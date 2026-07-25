<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retardo Extremo (spec:46): si un check_in supera el umbral máximo de retardo
 * (`max_late_block_minutes`), el sistema BLOQUEA la jornada server-side; solo un
 * supervisor puede autorizar (override). Antes el "bloqueo" vivía solo en el frontend y
 * era trivialmente evadible. El override NO se confía al cliente: lo determinan los
 * controllers según el rol del emisor autenticado.
 */
class ExtremeLateBlockTest extends TestCase
{
    use RefreshDatabase;

    /** expected 09:00, tolerancia 10 (default). */
    private function makeSetup(int $maxLateBlock, string $role = 'empleado'): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Retardo',
            'subdomain' => 'retardo' . uniqid(),
            'plan' => 'enterprise', // isPro => sin bloqueo de tienda cerrada
            'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador',
            'email' => 'colab' . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Colaborador',
            'base_salary' => 3000.00,
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => $tenant->id,
            'key' => 'timezone',
            'value' => json_encode('UTC'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        LftSetting::create([
            'tenant_id' => $tenant->id,
            'max_late_block_minutes' => $maxLateBlock,
        ]);
        return [$tenant, $user];
    }

    public function test_extreme_late_check_in_is_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00')); // 90 min tarde (esperado 09:00)
        try {
            [$tenant, $user] = $this->makeSetup(60);

            $blocked = false;
            try {
                app(ClockService::class)->processPunch($user, 'check_in');
            } catch (\Exception $e) {
                $blocked = true;
                $this->assertStringContainsString('Bloqueado', $e->getMessage());
            }

            $this->assertTrue($blocked, 'un retardo de 90 min con umbral 60 debe bloquearse');
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_supervisor_override_allows_extreme_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);

            app(ClockService::class)->processPunch($user, 'check_in', null, ['supervisor_override' => true]);

            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_in', 'is_late' => true,
            ]);
            // La señal transitoria de override NO debe quedar persistida en el ponche.
            $entry = \App\Models\TimeEntry::where('user_id', $user->id)->where('type', 'check_in')->first();
            $this->assertStringNotContainsString('supervisor_override', (string) $entry->details);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_amnesty_exempts_from_block(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);
            // "Ya llegué" 09:05, dentro de tolerancia → amnistía → is_late=0 → no se bloquea.
            DB::table('time_entries')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-10',
                'type' => 'waiting', 'time' => '09:05:00', 'is_late' => false, 'late_minutes' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            app(ClockService::class)->processPunch($user, 'check_in');

            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_in', 'is_late' => false,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_block_disabled_when_max_is_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(0); // deshabilitado

            app(ClockService::class)->processPunch($user, 'check_in');

            // 90 min tarde pero el bloqueo está apagado → retardo normal, no bloqueo.
            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_in', 'is_late' => true,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_under_threshold_is_not_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00')); // 30 min tarde
        try {
            [$tenant, $user] = $this->makeSetup(60); // umbral 60

            app(ClockService::class)->processPunch($user, 'check_in');

            // 30 < 60 → no se bloquea, pero es retardo (30 > tolerancia 10).
            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_in', 'is_late' => true,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_employee_cannot_self_override_via_client_flag(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60, 'empleado');

            // El empleado MANDA el flag de override; el controller debe sobrescribirlo
            // (su rol no es admin/supervisor) → bloqueado.
            $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id,
                'type' => 'check_in',
                'time' => '10:30:00',
                'details' => ['supervisor_override' => true],
            ]);

            $response->assertStatus(400);
            // No cualquier 400: específicamente el bloqueo de Retardo Extremo.
            $this->assertStringContainsString('Bloqueado', (string) $response->json('message'));
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_supervisor_delegation_overrides_block(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $target] = $this->makeSetup(60);
            $supervisor = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Supervisor',
                'email' => 'sup' . uniqid() . '@t.local',
                'password' => bcrypt('password'),
                'role' => 'supervisor',
            ]);

            // Un supervisor ficha por el empleado extremadamente tarde → override → permitido.
            $response = $this->actingAs($supervisor)->postJson('/api/v1/clock/punch', [
                'user_id' => $target->id,
                'type' => 'check_in',
                'time' => '10:30:00',
            ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('time_entries', ['user_id' => $target->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
