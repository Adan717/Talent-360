<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ley-Silla (spec:76): el exceso de descansos cortos (break) debe contarse. Antes,
 * `$restExcessMinutes` se inicializaba en 0 y NUNCA se incrementaba (código muerto): el
 * score de desempeño ignoraba el abuso de descansos. Como `break_end` no trae
 * `duration_minutes`, la duración se computa emparejando break_start/break_end server-side.
 * Permitido = `leySillaConfig.breakMinutes` (15), tolerancia = `rest_tolerance_minutes` (10)
 * → excede si > 25 min; el exceso es (duración − 15). Solo afecta score/métricas, no el pago.
 */
class LeySillaBreakExcessTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa LeySilla', 'subdomain' => 'leysilla' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'c' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000.00, 'restDay' => 'Domingo', 'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        LftSetting::create([
            'tenant_id' => $tenant->id, 'rest_tolerance_minutes' => 10, 'meal_tolerance_minutes' => 15,
        ]);
        // El observer Tenant::created ya siembra leySillaConfig; se fija con updateOrInsert
        // (match por (tenant_id, key), unique compuesto de la Ronda 7) para el valor deseado.
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'key' => 'leySillaConfig'],
            [
                'value' => json_encode(['enabled' => true, 'consecutiveMinutes' => 120, 'breakMinutes' => 15]),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
        return [$tenant, $user, $employee];
    }

    private function entry(int $tenantId, int $userId, string $type, string $time): void
    {
        // Lunes 2026-06-01 (restDay = Domingo, así que es día laboral, sin falta).
        DB::table('time_entries')->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'date' => '2026-06-01',
            'type' => $type, 'time' => $time, 'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payroll(Employee $employee): array
    {
        return app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-01');
    }

    public function test_break_within_allowance_has_no_excess(): void
    {
        [$t, $u, $e] = $this->makeSetup();
        $this->entry($t->id, $u->id, 'check_in', '09:00:00');
        $this->entry($t->id, $u->id, 'break_start', '12:00:00');
        $this->entry($t->id, $u->id, 'break_end', '12:20:00'); // 20 min ≤ 25

        $p = $this->payroll($e);
        $this->assertSame(0, $p['incidents']['rest_excess_minutes']);
        $this->assertSame(0, $p['performance']['break_overtime_mins']);
    }

    public function test_break_exceeding_allowance_counts_excess(): void
    {
        [$t, $u, $e] = $this->makeSetup();
        $this->entry($t->id, $u->id, 'check_in', '09:00:00');
        $this->entry($t->id, $u->id, 'break_start', '12:00:00');
        $this->entry($t->id, $u->id, 'break_end', '12:40:00'); // 40 min > 25 → exceso 40-15=25

        $p = $this->payroll($e);
        $this->assertSame(25, $p['incidents']['rest_excess_minutes']);
        $this->assertSame(25, $p['performance']['break_overtime_mins']);
        // El exceso baja el score (floor(25/5)=5 puntos) pero NO cambia el pago.
        $this->assertSame(0.0, $p['deductions_breakdown']['total']);
    }

    public function test_orphan_break_start_has_no_excess(): void
    {
        [$t, $u, $e] = $this->makeSetup();
        $this->entry($t->id, $u->id, 'check_in', '09:00:00');
        $this->entry($t->id, $u->id, 'break_start', '12:00:00'); // sin break_end

        $p = $this->payroll($e);
        $this->assertSame(0, $p['incidents']['rest_excess_minutes']);
    }

    public function test_two_breaks_only_the_excessive_one_counts(): void
    {
        [$t, $u, $e] = $this->makeSetup();
        $this->entry($t->id, $u->id, 'check_in', '09:00:00');
        $this->entry($t->id, $u->id, 'break_start', '11:00:00');
        $this->entry($t->id, $u->id, 'break_end', '11:20:00'); // 20 min ≤ 25 → 0
        $this->entry($t->id, $u->id, 'break_start', '13:00:00');
        $this->entry($t->id, $u->id, 'break_end', '13:40:00'); // 40 min > 25 → 25

        $p = $this->payroll($e);
        $this->assertSame(25, $p['incidents']['rest_excess_minutes']);
    }
}
