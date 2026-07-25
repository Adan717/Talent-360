<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R79 (Fase 0, decisión D1): `job_roles.late_penalty_multiplier` tenía input en RRHH,
 * validación y columna, pero la nómina NUNCA lo aplicaba (`deductionLates = lateMinutes *
 * late_penalty_per_minute`, sin factor por puesto). El admin creía que un puesto castigaba el
 * retardo más caro y no pasaba nada. Ahora la deducción se multiplica por el factor del puesto.
 */
class MultiplicadorRetardoPuestoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Employee,1:User} un empleado con retardo de 30 min ya registrado. */
    private function empConRetardo(float $multiplier): array
    {
        $tenant = Tenant::create(['name' => 'Empresa M', 'subdomain' => 'm' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $role = JobRole::create([
            'tenant_id' => $tenant->id, 'name' => 'Puesto', 'area' => 'Op',
            'late_penalty_multiplier' => $multiplier,
        ]);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'C', 'email' => 'c' . uniqid() . '@t.local', 'password' => bcrypt('x'), 'role' => 'empleado']);
        $emp = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'C',
            'job_role_id' => $role->id, 'base_salary' => 3000.00, 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        LftSetting::create([
            'tenant_id' => $tenant->id, 'late_action_mode' => 'deduct',
            'late_penalty_per_minute' => 2.00, 'late_tolerance_minutes' => 10,
            'lates_per_absence' => 99, // que 1 retardo no dispare falta (aislar la deducción por minutos)
        ]);
        // Un check_in tarde: 30 min de retardo el viernes.
        DB::table('time_entries')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-10',
            'type' => 'check_in', 'time' => '09:30:00', 'is_late' => true, 'late_minutes' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$emp, $user];
    }

    private function deduccionRetardo(Employee $emp): float
    {
        $p = app(ClockService::class)->calculatePayrollForEmployee($emp->fresh(), '2026-07-06', '2026-07-12');
        return (float) $p['deductions_breakdown']['lates']; // el monto de la deducción por retardo
    }

    public function test_multiplicador_1_es_la_deduccion_base(): void
    {
        [$emp] = $this->empConRetardo(1.0);
        // 30 min * $2/min * 1.0 = $60
        $this->assertSame(60.0, $this->deduccionRetardo($emp));
    }

    public function test_multiplicador_2_duplica_la_deduccion(): void
    {
        [$emp] = $this->empConRetardo(2.0);
        // 30 min * $2/min * 2.0 = $120
        $this->assertSame(120.0, $this->deduccionRetardo($emp));
    }
}
