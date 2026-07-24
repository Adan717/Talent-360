<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R91 (Fase 4 / T4.3 · N7): ventana de comida — la comida no puede EMPEZAR hasta cumplir
 * `mealSettings.minWorkMinutes` de trabajo en el segmento actual. Antes no se enforceaba en NINGÚN lado
 * (ni FE ni backend). Guard server-side en `processPunch` (hard-reject → 400), opt-in por-tenant
 * (0 = sin ventana), exento offline, anclado al ÚLTIMO check_in (segmento abierto).
 */
class MealWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function make(int $minWork): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Comida', 'subdomain' => 'com' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        DB::table('system_settings')->insert([
            ['tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode('UTC'),
                'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'key' => 'mealSettings', 'value' => json_encode(['minWorkMinutes' => $minWork]),
                'created_at' => now(), 'updated_at' => now()],
        ]);
        $jr = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Piso']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'c' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado', 'is_active' => true,
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true, 'job_role_id' => $jr->id,
        ]);
        return [$tenant, $user];
    }

    private function punchAt(User $user, string $type, string $hhmm): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 ' . $hhmm));
        try {
            app(ClockService::class)->processPunch($user, $type);
            return ['ok' => true, 'msg' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    public function test_bloquea_meal_start_antes_de_ventana(): void
    {
        [, $user] = $this->make(90);
        $this->punchAt($user, 'check_in', '09:00:00');

        $res = $this->punchAt($user, 'meal_start', '09:30:00'); // 30 min < 90

        $this->assertFalse($res['ok']);
        $this->assertStringContainsStringIgnoringCase('comida', $res['msg']);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'meal_start']);
    }

    public function test_permite_meal_start_cumplida_la_ventana(): void
    {
        [, $user] = $this->make(90);
        $this->punchAt($user, 'check_in', '09:00:00');

        $res = $this->punchAt($user, 'meal_start', '10:35:00'); // 95 min >= 90

        $this->assertTrue($res['ok'], $res['msg']);
        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'meal_start']);
    }

    public function test_sin_ventana_permite_siempre(): void
    {
        [, $user] = $this->make(0); // opt-out
        $this->punchAt($user, 'check_in', '09:00:00');

        $res = $this->punchAt($user, 'meal_start', '09:01:00');

        $this->assertTrue($res['ok'], $res['msg']);
        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'meal_start']);
    }

    public function test_sin_turno_abierto_rechaza(): void
    {
        [, $user] = $this->make(90);
        // Sin check_in previo → no hay turno abierto.
        $res = $this->punchAt($user, 'meal_start', '10:35:00');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsStringIgnoringCase('turno', $res['msg']);
    }

    public function test_ancla_al_ultimo_check_in_en_split_shift(): void
    {
        [, $user] = $this->make(90);
        // Turno partido: entra 09:00, sale 12:00, REENTRA 15:00.
        $this->punchAt($user, 'check_in', '09:00:00');
        $this->punchAt($user, 'check_out', '12:00:00');
        $this->punchAt($user, 'check_in', '15:00:00');

        // meal_start a las 15:30: 30 min desde la REENTRADA (no 6.5h desde la 1ª) → bloqueado.
        $res = $this->punchAt($user, 'meal_start', '15:30:00');
        $this->assertFalse($res['ok'], 'debe anclar al último check_in');

        // A las 16:35: 95 min desde las 15:00 → permitido.
        $res2 = $this->punchAt($user, 'meal_start', '16:35:00');
        $this->assertTrue($res2['ok'], $res2['msg']);
    }

    public function test_offline_exento(): void
    {
        [, $user] = $this->make(90);
        $this->punchAt($user, 'check_in', '09:00:00');

        // meal_start OFFLINE (occurredAt) a las 09:30: exento aunque no cumpla la ventana.
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        try {
            app(ClockService::class)->processPunch($user, 'meal_start', null, [], '2026-07-15T09:30:00Z');
            $ok = true;
        } catch (\Throwable $e) {
            $ok = false;
        }

        $this->assertTrue($ok, 'offline exento de la ventana de comida');
        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'meal_start']);
    }

    public function test_endpoint_real_rechaza_meal_start_temprano(): void
    {
        [, $user] = $this->make(90);
        $this->punchAt($user, 'check_in', '09:00:00');

        Carbon::setTestNow(Carbon::parse('2026-07-15 09:30:00'));
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'meal_start',
        ]);

        $res->assertStatus(400);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'meal_start']);
    }

    public function test_ya_cerrado_el_turno_rechaza_meal_start(): void
    {
        // Segmento CERRADO (review R91): trabajó 3h y CERRÓ; luego intenta comer → no hay turno abierto.
        [, $user] = $this->make(90);
        $this->punchAt($user, 'check_in', '09:00:00');
        $this->punchAt($user, 'check_out', '12:00:00');

        $res = $this->punchAt($user, 'meal_start', '12:05:00');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsStringIgnoringCase('turno', $res['msg']);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'meal_start']);
    }

    public function test_min_work_como_string_json(): void
    {
        // El panel de ajustes plausiblemente guarda "90" (string) en el JSON → el (int) debe manejarlo.
        $tenant = Tenant::create(['name' => 'S', 'subdomain' => 's' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        DB::table('system_settings')->insert([
            ['tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode('UTC'),
                'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'key' => 'mealSettings', 'value' => json_encode(['minWorkMinutes' => '90']),
                'created_at' => now(), 'updated_at' => now()],
        ]);
        $jr = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'C', 'area' => 'P']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'C', 'email' => 's' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true]);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'C',
            'base_salary' => 3000, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo', 'mealMinutes' => 60,
            'is_active_employee' => true, 'job_role_id' => $jr->id]);
        $this->punchAt($user, 'check_in', '09:00:00');

        $res = $this->punchAt($user, 'meal_start', '09:30:00'); // 30 < 90

        $this->assertFalse($res['ok'], 'el string "90" debe interpretarse como 90');
    }

    public function test_meal_end_no_gateado(): void
    {
        // La ventana SÓLO aplica a meal_start; terminar la comida nunca se bloquea por ella.
        [, $user] = $this->make(90);
        $this->punchAt($user, 'check_in', '09:00:00');

        // meal_end suelto (sin meal_start previo) a los 5 min: no lo bloquea la ventana de comida.
        $res = $this->punchAt($user, 'meal_end', '09:05:00');

        $this->assertTrue($res['ok'], $res['msg']);
    }
}
