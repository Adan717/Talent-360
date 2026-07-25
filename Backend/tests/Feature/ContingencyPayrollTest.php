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
 * Reloj R83 (Fase 1 / T1.4 del plan v2, CIERRA la Fase 1): Contingencia (fuerza mayor) → pago 100%.
 *
 * El teatro que se cierra: declarar una eventualidad (sin luz / sin red / fuerza mayor) sólo se
 * registraba (tabla `contingencies`); la nómina NO aplicaba ningún efecto. Ahora una contingencia
 * APROBADA por un admin para (tenant, user, día) hace que la nómina trate ese día como PRESENTE
 * (jornada causal 100% pagada, LFT Art. 42/427-431), sin contarlo como falta ni reducir el séptimo
 * día, y CONGELA el retardo de ese día (spec §4: "nómina pre-clasifica Jornada Causal 100% Pagada
 * LFT, congela retardos").
 *
 * Flujo (espejo de R82): el empleado declara → el admin aprueba → una fila `approved` aplica el
 * efecto server-side. Todo el efecto lo decide BD, no el cliente.
 */
class ContingencyPayrollTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Cont', 'subdomain' => 'cont' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colaborador Cont',
            'email' => 'cont' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colaborador Cont',
            'base_salary' => 3000.00, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode('UTC'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        LftSetting::create([
            'tenant_id' => $tenant->id, 'late_tolerance_minutes' => 10, 'late_penalty_per_minute' => 2,
            'lates_per_absence' => 3, 'deduct_absence_day' => true, 'paid_rest_day' => true,
            'proportional_rest_day' => true,
        ]);
        return [$tenant, $user, $employee];
    }

    private function makeAdmin(Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin',
            'email' => 'admin' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'admin',
        ]);
    }

    private function contingencia(Tenant $tenant, User $user, string $date, string $status): void
    {
        DB::table('contingency_days')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => $date,
            'reason' => 'Corte de energía en toda la zona (CFE)', 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ficharTarde(User $user): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00'));
        try {
            app(ClockService::class)->processPunch($user, 'check_in');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- El efecto en la nómina --------------------------------------------------------

    public function test_dia_sin_asistencia_ni_contingencia_es_falta(): void
    {
        [, , $employee] = $this->makeSetup();
        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertGreaterThanOrEqual(1, $payroll['incidents']['physical_absences']);
        $this->assertGreaterThan(0, $payroll['deductions_breakdown']['absences']);
    }

    public function test_contingencia_aprobada_paga_el_dia_al_100(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->contingencia($tenant, $user, '2026-07-10', 'approved');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        // No es falta física, no se deduce, y el neto = bruto (jornada causal 100% pagada).
        $this->assertSame(0, $payroll['incidents']['physical_absences']);
        $this->assertSame(0.0, $payroll['deductions_breakdown']['absences']);
        $this->assertSame(0.0, $payroll['deductions_breakdown']['total']);
        $this->assertSame($payroll['salary']['gross'], $payroll['salary']['net']);
    }

    public function test_contingencia_congela_el_retardo_del_dia(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->ficharTarde($user); // llegó tarde a pesar de la eventualidad
        $this->contingencia($tenant, $user, '2026-07-10', 'approved');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertSame(0, $payroll['incidents']['lates'], 'la contingencia congela el retardo');
        $this->assertSame(0.0, $payroll['deductions_breakdown']['lates']);
    }

    public function test_contingencia_pendiente_no_tiene_efecto(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->contingencia($tenant, $user, '2026-07-10', 'pending');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertGreaterThanOrEqual(1, $payroll['incidents']['physical_absences']);
        $this->assertGreaterThan(0, $payroll['deductions_breakdown']['absences']);
    }

    public function test_contingencia_rechazada_no_tiene_efecto(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->contingencia($tenant, $user, '2026-07-10', 'rejected');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertGreaterThanOrEqual(1, $payroll['incidents']['physical_absences']);
    }

    public function test_contingencia_de_otro_dia_no_aplica(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->contingencia($tenant, $user, '2026-07-09', 'approved'); // AYER

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertGreaterThanOrEqual(1, $payroll['incidents']['physical_absences']);
    }

    public function test_el_grid_marca_el_dia_de_contingencia(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->contingencia($tenant, $user, '2026-07-10', 'approved');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');
        $dia = collect($payroll['days_details'])->firstWhere('date', '2026-07-10');
        $this->assertTrue($dia['contingency']);
    }

    // ---- La declaración y la resolución ------------------------------------------------

    public function test_el_empleado_declara_una_contingencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:35:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $res = $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', [
                'reason' => 'Sin energía eléctrica en toda la sucursal, corte de CFE',
            ]);
            $res->assertStatus(201);
            $this->assertDatabaseHas('contingency_days', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-10', 'status' => 'pending',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_declaracion_exige_motivo(): void
    {
        [, $user] = $this->makeSetup();
        $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', ['reason' => 'corto'])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', [])->assertStatus(422);
    }

    public function test_declarar_dos_veces_el_mismo_dia_no_duplica(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:35:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $p = ['reason' => 'Sin energía eléctrica, corte general en la zona'];
            $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', $p)->assertStatus(201);
            $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', $p)->assertStatus(201);
            $this->assertSame(1, DB::table('contingency_days')->where('user_id', $user->id)->where('date', '2026-07-10')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_el_admin_ve_resuelve_y_deja_auditoria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:35:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $admin = $this->makeAdmin($tenant);
            $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', ['reason' => 'Corte de energía general en la zona']);

            $lista = $this->actingAs($admin)->getJson('/api/v1/admin/contingencies');
            $lista->assertStatus(200);
            $this->assertCount(1, $lista->json());
            $this->assertSame('Colaborador Cont', $lista->json('0.employee_name'));

            $id = DB::table('contingency_days')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/contingencies/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(200);
            $this->assertDatabaseHas('contingency_days', ['id' => $id, 'status' => 'approved', 'resolved_by' => $admin->id]);
            $this->assertDatabaseHas('audit_logs', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => 'contingency_approved',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_un_empleado_no_puede_resolver(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:35:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', ['reason' => 'Corte de energía general en la zona']);
            $id = DB::table('contingency_days')->where('user_id', $user->id)->value('id');
            $this->actingAs($user)->postJson("/api/v1/admin/contingencies/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(403);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** R75: nadie resuelve su propia contingencia. */
    public function test_nadie_resuelve_la_suya(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:35:00'));
        try {
            [$tenant, $adminDeclara] = $this->makeSetup();
            DB::table('users')->where('id', $adminDeclara->id)->update(['role' => 'admin']);
            $adminDeclara = $adminDeclara->fresh();
            $this->actingAs($adminDeclara)->postJson('/api/v1/clock/declare-contingency', ['reason' => 'Corte de energía general en la zona']);
            $id = DB::table('contingency_days')->where('user_id', $adminDeclara->id)->value('id');
            $this->actingAs($adminDeclara)->postJson("/api/v1/admin/contingencies/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(403);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_el_admin_no_resuelve_de_otro_tenant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:35:00'));
        try {
            [$tenantA, $userA] = $this->makeSetup();
            [$tenantB, ] = $this->makeSetup();
            $adminB = $this->makeAdmin($tenantB);
            $this->actingAs($userA)->postJson('/api/v1/clock/declare-contingency', ['reason' => 'Corte de energía general en la zona']);
            $id = DB::table('contingency_days')->where('user_id', $userA->id)->value('id');
            $this->actingAs($adminB)->postJson("/api/v1/admin/contingencies/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(404);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Integración: declarar → aprobar → la nómina paga ese día al 100%. */
    public function test_flujo_completo_declarar_aprobar_paga_100(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $admin = $this->makeAdmin($tenant);

        Carbon::setTestNow(Carbon::parse('2026-07-10 08:35:00'));
        try {
            $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', ['reason' => 'Corte de energía CFE en toda la zona comercial']);
            $id = DB::table('contingency_days')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/contingencies/{$id}/resolve", ['status' => 'approved'])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');
        $this->assertSame(0, $payroll['incidents']['physical_absences']);
        $this->assertSame(0.0, $payroll['deductions_breakdown']['total']);
    }
}
