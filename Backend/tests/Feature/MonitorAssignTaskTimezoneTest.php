<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * assignTask guardaba started_at_mins/expected_end_time_mins con Carbon::now() en la tz del
 * servidor (UTC), así que el monitor mostraba la hora de inicio de tarea corrida varias horas.
 * Ahora usa la tz del tenant (resolver compartido).
 */
class MonitorAssignTaskTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_started_mins_use_tenant_timezone_not_server(): void
    {
        // Instante congelado en UTC 02:30. En Asia/Tokyo (UTC+9, sin DST) son las 11:30 →
        // 11*60+30 = 690 min. En UTC serían 02:30 → 150 min. La diferencia prueba la tz.
        Carbon::setTestNow(Carbon::parse('2026-07-11 02:30:00', 'UTC'));

        $tenant = Tenant::create([
            'name' => 'Tokyo Co', 'subdomain' => 'mon-tz', 'plan' => 'enterprise', 'is_active' => true,
        ]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);
        $assignee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Emp', 'email' => 'e' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);

        // tenant_id no está en $fillable de Task → se asigna por atributo.
        $task = new Task(['title' => 'Barrer', 'estimated_mins' => 30]);
        $task->tenant_id = $tenant->id;
        $task->save();

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'timezone', 'tenant_id' => $tenant->id],
            ['value' => 'Asia/Tokyo', 'created_at' => now(), 'updated_at' => now()]
        );

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/assign-task', [
            'user_id' => $assignee->id, 'task_id' => $task->id,
        ]);

        $response->assertStatus(200);

        $row = DB::table('task_assignments')->where('user_id', $assignee->id)->first();
        $this->assertSame(690, (int) $row->started_at_mins);            // Tokyo 11:30, no UTC 150
        $this->assertSame(690 + 30, (int) $row->expected_end_time_mins); // + estimated_mins
    }

    public function test_time_remaining_parses_shift_end_in_tenant_timezone(): void
    {
        // UTC 12:00 del 11-jul = 21:00 en Asia/Tokyo (UTC+9). Un shiftEnd local de 18:00 YA
        // pasó → "Jornada terminada". Con la tz del servidor (UTC), shiftEnd=18:00 aún no
        // llegaba a las 12:00 y el bug mostraba "6h 0m". Esto fija el parseo de shiftEnd en tz.
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00', 'UTC'));

        $tenant = Tenant::create([
            'name' => 'Tokyo Co', 'subdomain' => 'mon-rem', 'plan' => 'enterprise', 'is_active' => true,
        ]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);
        $worker = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Worker', 'email' => 'w' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'timezone', 'tenant_id' => $tenant->id],
            ['value' => 'Asia/Tokyo', 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('employees')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $worker->id, 'name' => 'Worker',
            'is_active_employee' => true, 'shiftEnd' => '18:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // check_in de hoy (fecha en tz Tokyo) para que el colaborador esté "en turno".
        $today = Carbon::now('Asia/Tokyo')->toDateString();
        DB::table('time_entries')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $worker->id, 'date' => $today, 'type' => 'check_in',
            'time' => '09:00:00', 'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard/monitor');
        $response->assertStatus(200);

        $me = collect($response->json('data.users'))->firstWhere('id', $worker->id);
        $this->assertNotNull($me, 'El colaborador en turno debe aparecer en el monitor.');
        $this->assertSame('Jornada terminada', $me['time_remaining']);
    }
}
