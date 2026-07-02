<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\TimeEntry;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PayrollLftTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_lft_calculation_with_absences_and_seventh_day_proportionality(): void
    {
        // 1. Crear estructuras de prueba
        $tenant = Tenant::create([
            'name' => 'Empresa Test LFT',
            'subdomain' => 'test-lft',
            'is_active' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador Test',
            'email' => 'colab@test.com',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);

        $this->actingAs($user);

        $employee = Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Colaborador Test',
            'base_salary' => 3000.00, // $3,000 MXN semanales ($500 diarios)
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);

        // 2. Configuración LFT: 3 retardos = 1 falta, séptimo día proporcional activo
        $lft = LftSetting::create([
            'tenant_id' => $tenant->id,
            'lates_per_absence' => 3,
            'deduct_absence_day' => true,
            'absences_for_warning' => 3,
            'absences_for_suspension' => 4,
            'proportional_rest_day' => true,
            'late_tolerance_minutes' => 10,
            'meal_tolerance_minutes' => 15,
            'rest_tolerance_minutes' => 10,
            'late_action_mode' => 'deduct',
            'paid_rest_day' => true,
        ]);

        // 3. Simular registros: Lunes de check-in temprano, Martes falta (sin registros)
        // Simular 3 retardos en el periodo (Lunes, Miércoles, Jueves)
        $dates = ['2026-06-01', '2026-06-03', '2026-06-04'];
        foreach ($dates as $date) {
            TimeEntry::create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'date' => $date,
                'type' => 'check_in',
                'time' => '09:15:00',
                'is_late' => true,
                'late_minutes' => 15,
                'details' => json_encode(['lft_incident' => ['type' => 'late', 'minutes' => 15]])
            ]);
        }

        // Agregar check-in a tiempo para Viernes y Sábado
        foreach (['2026-06-05', '2026-06-06'] as $date) {
            TimeEntry::create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'date' => $date,
                'type' => 'check_in',
                'time' => '08:55:00',
                'is_late' => false,
                'late_minutes' => 0,
            ]);
        }

        // 4. Calcular nómina de la semana (2026-06-01 al 2026-06-07)
        $clockService = app(ClockService::class);
        $payroll = $clockService->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        // 5. Validar resultados
        // Faltas físicas: 1 (el Martes 2026-06-02 no tuvo registros)
        // Faltas por retardos: 3 retardos / 3 = 1 falta por retardos
        // Faltas totales: 2
        $this->assertEquals(2, $payroll['incidents']['total_absences']);
        
        // Días trabajados efectivamente: 6 - 2 = 4 días
        // Proporcional séptimo día: 4 / 6 = 0.6667
        $this->assertEquals(4/6, $payroll['incidents']['rest_day_proportion']);

        // Salario diario: $3000 / 6 = $500
        // Descuento por faltas: 2 * $500 = $1,000
        $this->assertEquals(1000.00, $payroll['deductions_breakdown']['absences']);

        // Descuento por descanso proporcional: (1 - 4/6) * $500 = $166.67
        $this->assertEquals(166.67, round($payroll['deductions_breakdown']['rest_day'], 2));

        // Neto: Sueldo bruto ($500 * 7 = $3500) - deducciones ($1000 + $166.67 + retardos)
        // Como modo es 'deduct', se suma penalización por minutos tarde: 45 minutos * 2 = $90 de penalización
        // Deducciones totales: $1000 + $166.67 + $90 = $1256.67
        // Neto esperado: $3500 - $1256.67 = $2243.33
        $this->assertEquals(2243.33, round($payroll['salary']['net'], 2));
    }
}
