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
 * Reloj R82 (Fase 1 / T1.3 del plan v2): Justificantes CON EFECTO en la nómina.
 *
 * El teatro que se cierra: el modal de justificante ("Firmar y Entrar") sólo actualizaba estado local
 * en el cliente — no persistía nada y la nómina lo ignoraba (sólo miraba la amnistía). Ahora un
 * justificante APROBADO por un admin para (tenant, user, día) hace que la nómina NO deduzca ese
 * retardo ni lo cuente para faltas fantasma (spec §5: "adjunta justificante para mitigar deducciones …
 * admin anula el descuento").
 *
 * Flujo (espejo de R56): el empleado solicita el justificante de HOY → el admin lo aprueba/rechaza →
 * con un justificante APROBADO para esa fecha, la nómina perdona el retardo de ese día. Todo el efecto
 * es server-side: lo decide una fila `approved` en BD, no el cliente.
 */
class LateJustificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Just', 'subdomain' => 'just' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colaborador Tarde',
            'email' => 'just' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colaborador Tarde',
            'base_salary' => 3000.00, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
            );
        LftSetting::create([
            'tenant_id' => $tenant->id, 'late_tolerance_minutes' => 10,
            'late_penalty_per_minute' => 2, 'lates_per_absence' => 3,
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

    /** Ficha tarde el 2026-07-10 (30 min, tolerancia 10) → un retardo real. */
    private function ficharTarde(User $user): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00'));
        try {
            app(ClockService::class)->processPunch($user, 'check_in');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function justificar(Tenant $tenant, User $user, string $date, string $status): void
    {
        DB::table('late_justifications')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => $date,
            'reason' => 'Tráfico por accidente en la vía', 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- El efecto en la nómina --------------------------------------------------------

    public function test_sin_justificante_el_retardo_se_deduce(): void
    {
        [, $user, $employee] = $this->makeSetup();
        $this->ficharTarde($user);

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertSame(1, $payroll['incidents']['lates']);
        $this->assertGreaterThan(0, $payroll['incidents']['late_minutes']);
        $this->assertGreaterThan(0, $payroll['deductions_breakdown']['lates']);
    }

    public function test_un_justificante_aprobado_anula_la_deduccion_del_retardo(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->ficharTarde($user);
        $this->justificar($tenant, $user, '2026-07-10', 'approved');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        // El retardo se PERDONA: 0 deducción, 0 conteo (no alimenta faltas fantasma).
        $this->assertSame(0, $payroll['incidents']['lates']);
        $this->assertSame(0, $payroll['incidents']['late_minutes']);
        $this->assertSame(0.0, $payroll['deductions_breakdown']['lates']);
    }

    /** Transparencia: el grid marca el día como "tarde pero justificado" (no $0 sin explicación). */
    public function test_el_grid_marca_el_dia_justificado(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->ficharTarde($user);
        $this->justificar($tenant, $user, '2026-07-10', 'approved');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $dia = collect($payroll['days_details'])->firstWhere('date', '2026-07-10');
        $this->assertNotNull($dia);
        $this->assertTrue($dia['late_justified'], 'el día justificado debe marcarse en el grid');
        // El ponche sigue mostrando is_late (verdad histórica); sólo el pago se perdona.
        $this->assertTrue((bool) collect($dia['entries'])->firstWhere('type', 'check_in')['is_late']);
    }

    public function test_un_justificante_pendiente_no_anula_la_deduccion(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->ficharTarde($user);
        $this->justificar($tenant, $user, '2026-07-10', 'pending');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertSame(1, $payroll['incidents']['lates']);
        $this->assertGreaterThan(0, $payroll['deductions_breakdown']['lates']);
    }

    public function test_un_justificante_rechazado_no_anula_la_deduccion(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->ficharTarde($user);
        $this->justificar($tenant, $user, '2026-07-10', 'rejected');

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertSame(1, $payroll['incidents']['lates']);
        $this->assertGreaterThan(0, $payroll['deductions_breakdown']['lates']);
    }

    public function test_un_justificante_de_otro_dia_no_anula_el_retardo_de_hoy(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $this->ficharTarde($user);
        $this->justificar($tenant, $user, '2026-07-09', 'approved'); // AYER

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertSame(1, $payroll['incidents']['lates']);
        $this->assertGreaterThan(0, $payroll['deductions_breakdown']['lates']);
    }

    /** El justificante aprobado de OTRO empleado no perdona el retardo del mío. */
    public function test_un_justificante_de_otro_empleado_no_cruza(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $otro = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Otro', 'email' => 'otro' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $this->ficharTarde($user);
        $this->justificar($tenant, $otro, '2026-07-10', 'approved'); // aprobado, pero de OTRO user

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');

        $this->assertSame(1, $payroll['incidents']['lates']);
        $this->assertGreaterThan(0, $payroll['deductions_breakdown']['lates']);
    }

    // ---- La solicitud del empleado -----------------------------------------------------

    public function test_el_empleado_solicita_un_justificante(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:40:00'));
        try {
            [$tenant, $user] = $this->makeSetup();

            $res = $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', [
                'reason' => 'Choque en el periférico, adjunto foto',
            ]);

            $res->assertStatus(201);
            $this->assertDatabaseHas('late_justifications', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'date' => '2026-07-10', 'status' => 'pending',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_solicitud_exige_un_motivo(): void
    {
        [, $user] = $this->makeSetup();
        $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', ['reason' => 'corto'])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', [])
            ->assertStatus(422);
    }

    public function test_solicitar_dos_veces_el_mismo_dia_no_duplica(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:40:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $payload = ['reason' => 'Motivo suficientemente largo del retardo'];
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', $payload)->assertStatus(201);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', $payload)->assertStatus(201);

            $this->assertSame(1, DB::table('late_justifications')
                ->where('user_id', $user->id)->where('date', '2026-07-10')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Re-solicitar tras un RECHAZO reabre a pending; tras una APROBACIÓN no la revierte (TOCTOU R56). */
    public function test_resolicitar_tras_aprobacion_no_la_revierte(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:40:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $admin = $this->makeAdmin($tenant);
            $payload = ['reason' => 'Motivo suficientemente largo del retardo'];
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', $payload);
            $id = DB::table('late_justifications')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/late-justifications/{$id}/resolve", ['status' => 'approved']);

            $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', $payload)->assertStatus(201);

            $this->assertSame('approved', DB::table('late_justifications')->where('id', $id)->value('status'));
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- La resolución del admin -------------------------------------------------------

    public function test_el_admin_ve_y_resuelve_las_pendientes_de_su_tenant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:40:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $admin = $this->makeAdmin($tenant);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', ['reason' => 'Motivo del retardo detallado']);

            $lista = $this->actingAs($admin)->getJson('/api/v1/admin/late-justifications');
            $lista->assertStatus(200);
            $this->assertCount(1, $lista->json());
            $this->assertSame('Colaborador Tarde', $lista->json('0.employee_name'));

            $id = DB::table('late_justifications')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/late-justifications/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(200);
            $this->assertDatabaseHas('late_justifications', ['id' => $id, 'status' => 'approved', 'resolved_by' => $admin->id]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Un acto que mueve dinero (perdonar la deducción) deja rastro append-only en audit_logs. */
    public function test_la_resolucion_escribe_auditoria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:40:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $admin = $this->makeAdmin($tenant);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', ['reason' => 'Motivo del retardo detallado']);
            $id = DB::table('late_justifications')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/late-justifications/{$id}/resolve", ['status' => 'approved']);

            $this->assertDatabaseHas('audit_logs', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'date' => '2026-07-10', 'type' => 'late_justification_approved',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** El panel muestra el retardo REAL del ponche, no el congelado (evita aprobar a ciegas). */
    public function test_el_panel_muestra_el_retardo_real_del_ponche(): void
    {
        [$tenant, $user] = $this->makeSetup();
        $admin = $this->makeAdmin($tenant);
        $this->ficharTarde($user); // ~30 min tarde el 2026-07-10

        // Solicitud "preventiva" sin minutos (simula solicitar antes de que se conociera el retardo).
        DB::table('late_justifications')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-10',
            'reason' => 'Motivo del retardo detallado', 'requested_late_minutes' => null,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $lista = $this->actingAs($admin)->getJson('/api/v1/admin/late-justifications');
        $lista->assertStatus(200);
        // Aunque se guardó null, el panel recalcula del ponche real (~30 min).
        $this->assertGreaterThan(20, (int) $lista->json('0.requested_late_minutes'));
    }

    public function test_un_empleado_no_puede_resolver(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:40:00'));
        try {
            [$tenant, $user] = $this->makeSetup();
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', ['reason' => 'Motivo del retardo detallado']);
            $id = DB::table('late_justifications')->where('user_id', $user->id)->value('id');

            $this->actingAs($user)->postJson("/api/v1/admin/late-justifications/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(403);
            $this->assertSame('pending', DB::table('late_justifications')->where('id', $id)->value('status'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** R75: nadie resuelve su PROPIO justificante. */
    public function test_nadie_aprueba_su_propio_justificante(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:40:00'));
        try {
            [$tenant, $adminTarde] = $this->makeSetup();
            DB::table('users')->where('id', $adminTarde->id)->update(['role' => 'admin']);
            $adminTarde = $adminTarde->fresh();

            $this->actingAs($adminTarde)->postJson('/api/v1/clock/request-late-justification', ['reason' => 'Motivo del retardo detallado']);
            $id = DB::table('late_justifications')->where('user_id', $adminTarde->id)->value('id');

            $this->actingAs($adminTarde)->postJson("/api/v1/admin/late-justifications/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(403);
            $this->assertSame('pending', DB::table('late_justifications')->where('id', $id)->value('status'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_el_admin_no_resuelve_de_otro_tenant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:40:00'));
        try {
            [$tenantA, $userA] = $this->makeSetup();
            [$tenantB, ] = $this->makeSetup();
            $adminB = $this->makeAdmin($tenantB);
            $this->actingAs($userA)->postJson('/api/v1/clock/request-late-justification', ['reason' => 'Motivo del retardo detallado']);
            $id = DB::table('late_justifications')->where('user_id', $userA->id)->value('id');

            $this->actingAs($adminB)->postJson("/api/v1/admin/late-justifications/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(404);
            $this->assertSame('pending', DB::table('late_justifications')->where('id', $id)->value('status'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Integración: solicitar → aprobar → la nómina perdona el retardo de ese día. */
    public function test_flujo_completo_solicitar_aprobar_perdona_en_nomina(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup();
        $admin = $this->makeAdmin($tenant);
        $this->ficharTarde($user);

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:45:00'));
        try {
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-justification', ['reason' => 'Accidente vial documentado con foto']);
            $id = DB::table('late_justifications')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/late-justifications/{$id}/resolve", ['status' => 'approved'])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');
        $this->assertSame(0.0, $payroll['deductions_breakdown']['lates']);
        $this->assertSame(0, $payroll['incidents']['lates']);
    }
}
