<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * calculatePayrollForEmployee mapeaba el día de descanso con un `$dayMap` de llaves SIN
 * acento y `strtolower()` (byte-only, NO quita acentos). El picker de RRHH y los seeds de
 * prod guardan nombres ACENTUADOS ('Miércoles', 'Sábado'), así que `strtolower('Miércoles')`
 * = 'miércoles' (con é) no matcheaba 'miercoles' → `?? 0` = Domingo. Resultado: el día real
 * de descanso se contaba como día laboral → FALTA FANTASMA cada semana → subpago recurrente
 * (~$583/semana en un sueldo de $3,500) y suspensión disciplinaria falsa a las ~3 semanas.
 * Invisible en la suite porque TODOS los fixtures usaban 'Domingo' (que baja a una llave
 * válida). Bug de PHP puro (no de driver) + hueco de cobertura de datos de prueba.
 */
class PayrollRestDayAccentTest extends TestCase
{
    use RefreshDatabase;

    /** Semana 2026-06-01 (Lun) .. 2026-06-07 (Dom). */
    private function setupEmployee(string $restDay): array
    {
        $tenant = Tenant::create(['name' => 'Empresa Acento', 'subdomain' => 'acento-' . uniqid(), 'is_active' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'c' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000.00, 'restDay' => $restDay, 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        LftSetting::create([
            'tenant_id' => $tenant->id, 'lates_per_absence' => 3, 'deduct_absence_day' => true,
            'absences_for_warning' => 3, 'absences_for_suspension' => 4, 'proportional_rest_day' => true,
            'late_tolerance_minutes' => 10, 'meal_tolerance_minutes' => 15, 'rest_tolerance_minutes' => 10,
            'late_action_mode' => 'deduct', 'paid_rest_day' => true,
        ]);
        return [$tenant, $user, $employee];
    }

    private function punchOnTime(int $userId, int $tenantId, string $date): void
    {
        TimeEntry::create([
            'user_id' => $userId, 'tenant_id' => $tenantId, 'date' => $date,
            'type' => 'check_in', 'time' => '08:55:00', 'is_late' => false, 'late_minutes' => 0,
        ]);
    }

    public function test_wednesday_rest_day_accented_is_not_a_phantom_absence(): void
    {
        [$tenant, $user, $employee] = $this->setupEmployee('Miércoles');
        // Ficha TODOS los días laborales excepto el miércoles (2026-06-03), su descanso real.
        foreach (['2026-06-01', '2026-06-02', '2026-06-04', '2026-06-05', '2026-06-06', '2026-06-07'] as $d) {
            $this->punchOnTime($user->id, $tenant->id, $d);
        }

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        // El miércoles es descanso → 0 faltas. Con el bug caía a Domingo → miércoles = falta fantasma.
        $this->assertSame(0, (int) $payroll['incidents']['physical_absences'], 'el miércoles (descanso) no debe ser falta');
        $this->assertSame(0, (int) $payroll['incidents']['total_absences']);
        $this->assertEquals(0.0, (float) $payroll['deductions_breakdown']['absences']);
        // Sueldo completo: 3000/6*7 = 3500, sin deducciones.
        $this->assertEquals(3500.00, round($payroll['salary']['net'], 2));
    }

    public function test_saturday_rest_day_accented_is_not_a_phantom_absence(): void
    {
        [$tenant, $user, $employee] = $this->setupEmployee('Sábado');
        // Ficha todos menos el sábado (2026-06-06), su descanso real.
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-07'] as $d) {
            $this->punchOnTime($user->id, $tenant->id, $d);
        }

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        $this->assertSame(0, (int) $payroll['incidents']['physical_absences'], 'el sábado (descanso) no debe ser falta');
        $this->assertSame(0, (int) $payroll['incidents']['total_absences']);
    }
}
