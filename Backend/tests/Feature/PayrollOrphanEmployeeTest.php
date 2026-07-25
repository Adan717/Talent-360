<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * calculatePayrollForEmployee consulta las asistencias con `where('user_id', $employee->user_id)`.
 * employees.user_id es NULLABLE con FK onDelete('set null'): si el usuario enlazado se borra, el
 * empleado queda con user_id = NULL pero puede seguir `is_active_employee = true`. En SQL,
 * `where user_id = NULL` no matchea NADA → $entries vacío → CADA día laboral se cuenta como falta
 * física → faltas fantasma → suspensión disciplinaria FALSA (absences_for_suspension) y sueldo a
 * cero. Un empleado sin usuario NO puede fichar; no se le deben fabricar faltas.
 *
 * El fix (a nivel de servicio, protege a todos los callers) no incrementa faltas físicas cuando
 * el empleado no tiene user_id. El test de contraste confirma que un empleado CON usuario que no
 * fichó SÍ acumula faltas (la lógica normal de asistencia no se toca).
 */
class PayrollOrphanEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create(['name' => 'Empresa Huérfano', 'subdomain' => 'huerfano-' . uniqid(), 'is_active' => true]);
    }

    private function makeLft(int $tenantId): void
    {
        LftSetting::create([
            'tenant_id' => $tenantId, 'lates_per_absence' => 3, 'deduct_absence_day' => true,
            'absences_for_warning' => 3, 'absences_for_suspension' => 4, 'proportional_rest_day' => true,
            'late_tolerance_minutes' => 10, 'meal_tolerance_minutes' => 15, 'rest_tolerance_minutes' => 10,
            'late_action_mode' => 'deduct', 'paid_rest_day' => true,
        ]);
    }

    public function test_orphan_employee_without_user_is_not_all_absences(): void
    {
        $tenant = $this->makeTenant();
        $this->makeLft($tenant->id);

        // Empleado ACTIVO pero SIN usuario enlazado (user_id NULL) — no puede fichar.
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => null, 'name' => 'Huérfano',
            'base_salary' => 3000.00, 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);

        // Semana Lun 2026-06-01 .. Dom 2026-06-07, sin ninguna asistencia.
        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        // Con el bug: 6 faltas fantasma → suspensión + neto machacado. Correcto: 0 faltas.
        $this->assertSame(0, (int) $payroll['incidents']['physical_absences'], 'sin usuario no puede fichar → no hay faltas fabricadas');
        $this->assertSame(0, (int) $payroll['incidents']['total_absences']);
        $this->assertEquals(0.0, (float) $payroll['deductions_breakdown']['absences']);
        $this->assertEquals(3500.00, round($payroll['salary']['net'], 2), 'neto = sueldo completo (3000/6*7), sin deducciones fabricadas');
    }

    public function test_employee_with_user_but_no_punches_still_accrues_absences(): void
    {
        $tenant = $this->makeTenant();
        $this->makeLft($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Real', 'email' => 'r' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Real',
            'base_salary' => 3000.00, 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);

        // Empleado CON usuario que NO fichó ningún día → SÍ debe acumular faltas (lógica normal).
        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        $this->assertGreaterThan(0, (int) $payroll['incidents']['physical_absences'], 'un empleado real que no fichó sí falta');
    }
}
