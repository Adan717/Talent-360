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

    public function test_holiday_blocking_and_payroll_bonus(): void
    {
        // 1. Crear estructuras
        $tenant = Tenant::create([
            'name' => 'Empresa Festivos LFT',
            'subdomain' => 'test-holidays',
            'is_active' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador Festivo',
            'email' => 'colab_festivo@test.com',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);

        $this->actingAs($user);

        $employee = Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Colaborador Festivo',
            'base_salary' => 3000.00, // $500 diario
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);

        // Crear días festivos oficiales
        // Festivo 1: Miércoles 2026-06-03 (Sin bloqueo, no trabajado). No debe contar como falta.
        // Festivo 2: Jueves 2026-06-04 (Sin bloqueo, trabajado). Debe pagar doble adicional ($500 * 2 = $1000 de bono).
        // Festivo 3: Viernes 2026-06-05 (Con bloqueo). Fichajes en este día deben arrojar excepción.
        \DB::table('lft_holidays')->insert([
            ['tenant_id' => $tenant->id, 'date' => '2026-06-03', 'name' => 'Festivo No Trabajado', 'block_app' => false, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'date' => '2026-06-04', 'name' => 'Festivo Trabajado', 'block_app' => false, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'date' => '2026-06-05', 'name' => 'Festivo Bloqueado', 'block_app' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Fichajes:
        // Lunes 2026-06-01: Trabajado normal
        TimeEntry::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'date' => '2026-06-01', 'type' => 'check_in', 'time' => '08:50:00', 'is_late' => false, 'late_minutes' => 0
        ]);
        // Martes 2026-06-02: Falta física normal (no es festivo) -> 1 falta física
        
        // Miércoles 2026-06-03: Festivo No Trabajado (no tiene checadas). No debe contar como falta.
        
        // Jueves 2026-06-04: Festivo Trabajado (tiene checada)
        TimeEntry::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'date' => '2026-06-04', 'type' => 'check_in', 'time' => '08:50:00', 'is_late' => false, 'late_minutes' => 0
        ]);

        // Sábado 2026-06-06: Trabajado normal
        TimeEntry::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'date' => '2026-06-06', 'type' => 'check_in', 'time' => '08:50:00', 'is_late' => false, 'late_minutes' => 0
        ]);

        // Calcular nómina
        $clockService = app(ClockService::class);
        $payroll = $clockService->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-07');

        // Validar:
        // 1. Faltas físicas: Martes 2026-06-02 es la única falta (1 falta).
        // Miércoles 2026-06-03 (festivo no trabajado) no debe contar como falta.
        $this->assertEquals(1, $payroll['incidents']['physical_absences']);
        $this->assertEquals(1, $payroll['incidents']['total_absences']);

        // 2. Festivos laborados: Jueves 2026-06-04 (1 trabajado).
        $this->assertEquals(1, $payroll['incidents']['holidays_worked']);
        
        // 3. Bono por festivo laborado: $500 * 2 = $1000.
        $this->assertEquals(1000.00, $payroll['salary']['holiday_bonus']);

        // 4. Bloqueo de App: Intentar fichar el Viernes 2026-06-05 (festivo bloqueado) debe fallar con excepción.
        Carbon::setTestNow(Carbon::parse('2026-06-05 09:00:00'));

        try {
            $clockService->processPunch($user, 'check_in', '09:00:00', ['sandbox_bypass' => true]);
            $this->fail("Debería haber lanzado una excepción por día festivo bloqueado.");
        } catch (\Exception $e) {
            $this->assertStringContainsString("Hoy es día festivo oficial obligatorio (Festivo Bloqueado) y el sistema se encuentra bloqueado.", $e->getMessage());
        } finally {
            Carbon::setTestNow(); // Resetear Carbon
        }
    }
}
