<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exceso de comida (spec:51): "si toma 75 min de una comida de 60, los 15 excedentes se
 * SUMAN a su Hora de Salida Oficial". Dos arreglos, ambos SOLO métricas (la nómina es por
 * salario fijo, no cambia el pago):
 *  1) el exceso de comida se computa por pareo meal_start/meal_end (antes leía
 *     duration_minutes, que ningún cliente escribe → estaba muerto);
 *  2) days_details expone required_exit_time = shiftEnd + exceso del día (Opción A).
 */
class MealExcessAndExitTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(string $shiftEnd = '18:00:00'): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Comida', 'subdomain' => 'comida' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'c' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000.00, 'restDay' => 'Domingo', 'mealMinutes' => 60,
            'shiftEnd' => $shiftEnd, 'is_active_employee' => true,
        ]);
        LftSetting::create([
            'tenant_id' => $tenant->id, 'meal_tolerance_minutes' => 15, 'rest_tolerance_minutes' => 10,
        ]);
        return [$tenant, $user, $employee];
    }

    private function entry(int $tenantId, int $userId, string $type, string $time): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'date' => '2026-06-01', // Lunes
            'type' => $type, 'time' => $time, 'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function day(array $payroll, string $date): array
    {
        return collect($payroll['days_details'])->firstWhere('date', $date) ?? [];
    }

    private function payroll(Employee $e): array
    {
        return app(ClockService::class)->calculatePayrollForEmployee($e, '2026-06-01', '2026-06-01');
    }

    public function test_meal_excess_counted_by_pairing_and_extends_exit(): void
    {
        [$t, $u, $e] = $this->makeSetup();
        $this->entry($t->id, $u->id, 'check_in', '09:00:00');
        $this->entry($t->id, $u->id, 'meal_start', '12:00:00');
        $this->entry($t->id, $u->id, 'meal_end', '13:30:00'); // 90 min > 75 → exceso 90-60=30

        $p = $this->payroll($e);
        $this->assertSame(30, $p['incidents']['meal_excess_minutes']);

        $day = $this->day($p, '2026-06-01');
        $this->assertSame('18:00:00', $day['official_exit_time']);
        $this->assertSame(30, $day['meal_makeup_minutes']);
        $this->assertSame('18:30:00', $day['required_exit_time']); // 18:00 + 30

        // No cambia el pago: el exceso no genera deducción (net == gross).
        $this->assertSame(0.0, $p['deductions_breakdown']['total']);
        $this->assertSame($p['salary']['gross'], $p['salary']['net']);
    }

    public function test_required_exit_capped_at_end_of_day(): void
    {
        // Turno nocturno: salida oficial 23:50 + 30 min de exceso cruzaría la medianoche;
        // se topa a 23:59:59 en vez de mostrar una hora anterior (00:20).
        [$t, $u, $e] = $this->makeSetup('23:50:00');
        $this->entry($t->id, $u->id, 'check_in', '15:00:00');
        $this->entry($t->id, $u->id, 'meal_start', '19:00:00');
        $this->entry($t->id, $u->id, 'meal_end', '20:30:00'); // 90 min > 75 → exceso 30

        $day = $this->day($this->payroll($e), '2026-06-01');
        $this->assertSame(30, $day['meal_makeup_minutes']);
        $this->assertSame('23:59:59', $day['required_exit_time']);
    }

    public function test_meal_within_allowance_no_excess_no_extension(): void
    {
        [$t, $u, $e] = $this->makeSetup();
        $this->entry($t->id, $u->id, 'check_in', '09:00:00');
        $this->entry($t->id, $u->id, 'meal_start', '12:00:00');
        $this->entry($t->id, $u->id, 'meal_end', '13:10:00'); // 70 min ≤ 75 → 0

        $p = $this->payroll($e);
        $this->assertSame(0, $p['incidents']['meal_excess_minutes']);

        $day = $this->day($p, '2026-06-01');
        $this->assertSame(0, $day['meal_makeup_minutes']);
        $this->assertSame('18:00:00', $day['required_exit_time']);
    }

    public function test_orphan_meal_start_no_excess(): void
    {
        [$t, $u, $e] = $this->makeSetup();
        $this->entry($t->id, $u->id, 'check_in', '09:00:00');
        $this->entry($t->id, $u->id, 'meal_start', '12:00:00'); // sin meal_end

        $p = $this->payroll($e);
        $this->assertSame(0, $p['incidents']['meal_excess_minutes']);
    }
}
