<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SillaMealQueueDoorNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'pro',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1, 'key' => 'time_mode', 'value' => json_encode('simulated'),
        ]);
    }

    private function makeUser(string $role = 'empleado', ?string $securityPin = null): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'security_pin' => $securityPin ? Hash::make($securityPin) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function checkIn(User $user, string $time = '09:00:00'): void
    {
        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in', 'time' => $time,
        ])->assertStatus(200);
    }

    // processPunch resuelve "hoy" con el timezone del tenant (system_settings.timezone,
    // default America/Mexico_City), que puede diferir del timezone de la app en la
    // máquina de pruebas — se lee la fecha real que quedó guardada en vez de asumir
    // que now()->format('Y-m-d') coincide.
    private function storedDateFor(User $user): string
    {
        return DB::table('time_entries')->where('user_id', $user->id)->value('date');
    }

    // §26 -----------------------------------------------------------------------

    public function test_door_notice_resolves_responsible_and_sends(): void
    {
        $responsible = $this->makeUser('empleado');
        $waiting = $this->makeUser('empleado');

        DB::table('store_daily_opening_statuses')->insert([
            'tenant_id' => 1, 'store_id' => 1, 'date' => now()->format('Y-m-d'),
            'scheduled_opening_time' => '08:30:00', 'pre_opening_window_start' => '08:15:00',
            'report_deadline' => '08:35:00', 'current_responsible_employee_id' => $responsible->id,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($waiting)->postJson('/api/v1/clock/door-notice', [
            'date' => now()->format('Y-m-d'),
            'message' => $waiting->name . ' está esperando en puerta',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('door_notices', [
            'from_employee_id' => $waiting->id,
            'to_employee_id' => $responsible->id,
        ]);
    }

    public function test_door_notice_fails_without_resolvable_responsible(): void
    {
        $waiting = $this->makeUser('empleado');

        $response = $this->actingAs($waiting)->postJson('/api/v1/clock/door-notice', [
            'date' => now()->format('Y-m-d'),
            'message' => 'Hola',
        ]);

        $response->assertStatus(422);
    }

    // §25 -----------------------------------------------------------------------

    public function test_silla_request_lifecycle_approve_start_end(): void
    {
        $employee = $this->makeUser('empleado');
        $supervisor = $this->makeUser('supervisor', '4821');

        $this->checkIn($employee);

        $req = $this->actingAs($employee)->postJson('/api/v1/clock/silla/request');
        $req->assertStatus(200);
        $req->assertJson(['success' => true, 'status' => 'pending']);
        $requestId = $req->json('request_id');

        // Duplicar la solicitud el mismo día reutiliza, no crea otra.
        $again = $this->actingAs($employee)->postJson('/api/v1/clock/silla/request');
        $this->assertEquals($requestId, $again->json('request_id'));
        $this->assertDatabaseCount('silla_requests', 1);

        // Un empleado normal no puede aprobar.
        $this->actingAs($employee)->postJson("/api/v1/clock/silla/{$requestId}/approve", [
            'method' => 'remote',
        ])->assertStatus(400);

        // PIN incorrecto se rechaza.
        $this->actingAs($supervisor)->postJson("/api/v1/clock/silla/{$requestId}/approve", [
            'method' => 'pin', 'supervisor_pin' => '0000',
        ])->assertStatus(400);

        // Aprobación correcta con PIN.
        $approve = $this->actingAs($supervisor)->postJson("/api/v1/clock/silla/{$requestId}/approve", [
            'method' => 'pin', 'supervisor_pin' => '4821',
        ]);
        $approve->assertStatus(200);
        $this->assertDatabaseHas('silla_requests', ['id' => $requestId, 'status' => 'approved']);

        // silla_start ahora sí procede.
        $start = $this->actingAs($employee)->postJson('/api/v1/clock/punch', [
            'user_id' => $employee->id, 'type' => 'silla_start', 'time' => '11:00:00',
        ]);
        $start->assertStatus(200);
        $this->assertDatabaseHas('silla_requests', ['id' => $requestId, 'status' => 'active']);

        $end = $this->actingAs($employee)->postJson('/api/v1/clock/punch', [
            'user_id' => $employee->id, 'type' => 'silla_end', 'time' => '11:15:00',
        ]);
        $end->assertStatus(200);
        $this->assertDatabaseHas('silla_requests', ['id' => $requestId, 'status' => 'finished']);
    }

    public function test_silla_start_without_approval_is_rejected(): void
    {
        $employee = $this->makeUser('empleado');
        $this->checkIn($employee);

        $response = $this->actingAs($employee)->postJson('/api/v1/clock/punch', [
            'user_id' => $employee->id, 'type' => 'silla_start', 'time' => '11:00:00',
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('no tienes una solicitud', $response->json('message'));
    }

    public function test_silla_capacity_blocks_when_full(): void
    {
        $supervisor = $this->makeUser('supervisor', '1111');

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'clockOpConfig',
            'value' => json_encode(['sillas_maximas_simultaneas' => 1]),
        ]);

        $emp1 = $this->makeUser('empleado');
        $this->checkIn($emp1, '09:00:00');
        $r1 = $this->actingAs($emp1)->postJson('/api/v1/clock/silla/request')->json('request_id');
        $this->actingAs($supervisor)->postJson("/api/v1/clock/silla/{$r1}/approve", ['method' => 'remote'])->assertStatus(200);
        $this->actingAs($emp1)->postJson('/api/v1/clock/punch', [
            'user_id' => $emp1->id, 'type' => 'silla_start', 'time' => '10:00:00',
        ])->assertStatus(200);

        $emp2 = $this->makeUser('empleado');
        $this->checkIn($emp2, '09:05:00');
        $r2 = $this->actingAs($emp2)->postJson('/api/v1/clock/silla/request')->json('request_id');
        $this->actingAs($supervisor)->postJson("/api/v1/clock/silla/{$r2}/approve", ['method' => 'remote'])->assertStatus(200);

        $blocked = $this->actingAs($emp2)->postJson('/api/v1/clock/punch', [
            'user_id' => $emp2->id, 'type' => 'silla_start', 'time' => '10:05:00',
        ]);
        $blocked->assertStatus(400);
        $this->assertStringContainsString('aforo', $blocked->json('message'));

        $status = $this->actingAs($supervisor)->getJson('/api/v1/clock/silla/status?date=' . $this->storedDateFor($emp1));
        $status->assertStatus(200);
        $status->assertJson(['max_simultaneous' => 1, 'active_count' => 1, 'available' => 0]);
        $this->assertCount(1, $status->json('queue'));
    }

    // §24 -----------------------------------------------------------------------

    public function test_meal_queue_creates_round_ordered_by_arrival_and_advances_turn(): void
    {
        $first = $this->makeUser('empleado');
        $second = $this->makeUser('empleado');

        $this->checkIn($first, '08:00:00');
        $this->checkIn($second, '08:05:00');
        $date = $this->storedDateFor($first);

        $queue = $this->actingAs($first)->getJson('/api/v1/meal-reservations/queue?date=' . $date);
        $queue->assertStatus(200);
        $queue->assertJson(['mode' => 'queue', 'order_by' => 'arrival', 'current_turn_employee_id' => $first->id]);

        // El segundo no puede elegir todavía.
        $this->actingAs($second)->postJson('/api/v1/meal-reservations/queue/pick', [
            'date' => $date, 'slot_start' => '12:00',
        ])->assertStatus(409);

        // El primero sí puede.
        $pick = $this->actingAs($first)->postJson('/api/v1/meal-reservations/queue/pick', [
            'date' => $date, 'slot_start' => '12:00',
        ]);
        $pick->assertStatus(200);
        $this->assertDatabaseHas('meal_reservations', ['user_id' => $first->id, 'slot_start' => '12:00']);

        // Ahora le toca al segundo.
        $queueAfter = $this->actingAs($second)->getJson('/api/v1/meal-reservations/queue?date=' . $date);
        $queueAfter->assertJson(['current_turn_employee_id' => $second->id]);

        $pick2 = $this->actingAs($second)->postJson('/api/v1/meal-reservations/queue/pick', [
            'date' => $date, 'slot_start' => '13:00',
        ]);
        $pick2->assertStatus(200);
    }
}
