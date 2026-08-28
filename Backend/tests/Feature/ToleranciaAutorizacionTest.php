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
 * Ronda 56 (Reloj): tolerancia con SOLICITUD DE AUTORIZACIÓN.
 *
 * Decisión de producto (2026-07-15, #8): el Retardo Extremo BLOQUEA la entrada (ya existía, R14),
 * PERO el empleado puede **solicitar autorización** a un admin; si la aprueba, puede marcar. Antes el
 * único escape era que un supervisor fichara por él (supervisor_override server-side).
 *
 * Flujo: check_in bloqueado → el empleado pide autorización → el admin la aprueba/rechaza → con una
 * autorización APROBADA para HOY, el mismo check_in ya no se bloquea.
 *
 * Backend primero (la UI —botón del empleado + panel del admin— va en ronda aparte). Todo el
 * enforcement es server-side: el bypass del bloqueo lo decide una fila `approved` en BD, no el cliente.
 */
class ToleranciaAutorizacionTest extends TestCase
{
    use RefreshDatabase;

    /** Esperado 09:00, tolerancia 10 (default), umbral de bloqueo `maxLateBlock`. */
    private function makeSetup(int $maxLateBlock = 60, string $role = 'empleado', int $tenantId = null): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Tol',
            'subdomain' => 'tol' . uniqid(),
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador Tarde',
            'email' => 'tarde' . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Colaborador Tarde',
            'base_salary' => 3000.00,
            'shiftStart' => '09:00:00',
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
            );
        LftSetting::create(['tenant_id' => $tenant->id, 'max_late_block_minutes' => $maxLateBlock]);
        return [$tenant, $user];
    }

    private function makeAdmin(Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin' . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    // ---- La solicitud del empleado ---------------------------------------------------

    public function test_el_empleado_solicita_autorizacion_y_queda_pendiente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00')); // 90 min tarde
        try {
            [$tenant, $user] = $this->makeSetup(60);

            $res = $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);

            $res->assertStatus(201);
            $this->assertDatabaseHas('late_authorization_requests', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'date' => '2026-07-10', 'status' => 'pending',
            ]);
            // El retardo se calcula server-side (contexto para el admin), no lo manda el cliente.
            $req = DB::table('late_authorization_requests')->where('user_id', $user->id)->first();
            $this->assertGreaterThan(60, (int) $req->requested_late_minutes);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_solicitar_dos_veces_el_mismo_dia_no_duplica(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);

            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', [])->assertStatus(201);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', [])->assertStatus(201);

            $this->assertSame(1, DB::table('late_authorization_requests')
                ->where('user_id', $user->id)->where('date', '2026-07-10')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Re-solicitar tras un RECHAZO reabre la solicitud (vuelve a pending). */
    public function test_resolicitar_tras_rechazo_vuelve_a_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);
            $admin = $this->makeAdmin($tenant);

            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);
            $id = DB::table('late_authorization_requests')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/late-authorizations/{$id}/resolve", ['status' => 'rejected']);

            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', [])->assertStatus(201);

            $this->assertSame('pending', DB::table('late_authorization_requests')->where('id', $id)->value('status'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Cazado por el code-review: re-solicitar tras una APROBACIÓN no debe revertirla a pending (ni
     * perder resolved_by/at). El "reabrir" es un update condicional `status != approved`.
     */
    public function test_resolicitar_tras_aprobacion_no_la_revierte(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);
            $admin = $this->makeAdmin($tenant);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);
            $id = DB::table('late_authorization_requests')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/late-authorizations/{$id}/resolve", ['status' => 'approved']);

            // El empleado vuelve a pedir (p.ej. recarga la UI): NO debe reabrirse.
            $res = $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);

            $res->assertStatus(201);
            $row = DB::table('late_authorization_requests')->where('id', $id)->first();
            $this->assertSame('approved', $row->status, 'una aprobación no debe revertirse al re-solicitar');
            $this->assertSame($admin->id, (int) $row->resolved_by, 'el resolved_by debe conservarse');
            $this->assertNotNull($row->resolved_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- La resolución del admin -----------------------------------------------------

    public function test_el_admin_ve_las_pendientes_de_su_tenant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);
            $admin = $this->makeAdmin($tenant);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);

            $res = $this->actingAs($admin)->getJson('/api/v1/admin/late-authorizations');

            $res->assertStatus(200);
            $this->assertCount(1, $res->json());
            $this->assertSame($user->id, (int) $res->json('0.user_id'));
            $this->assertSame('Colaborador Tarde', $res->json('0.employee_name'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Una pendiente de AYER no se lista: el candado sólo mira la aprobación de hoy. */
    public function test_las_pendientes_de_ayer_no_se_listan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);
            $admin = $this->makeAdmin($tenant);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);
            DB::table('late_authorization_requests')->where('user_id', $user->id)->update(['date' => '2026-07-09']);

            $res = $this->actingAs($admin)->getJson('/api/v1/admin/late-authorizations');

            $res->assertStatus(200);
            $this->assertCount(0, $res->json(), 'una solicitud de la víspera no debe aparecer como pendiente de hoy');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_un_empleado_no_puede_resolver(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);
            $id = DB::table('late_authorization_requests')->where('user_id', $user->id)->value('id');

            // La ruta está en el grupo admin/supervisor → 403 para un empleado.
            $this->actingAs($user)->postJson("/api/v1/admin/late-authorizations/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(403);
            $this->assertSame('pending', DB::table('late_authorization_requests')->where('id', $id)->value('status'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * R75: NADIE resuelve su PROPIA solicitud. El control existe para que alguien MÁS decida sobre
     * una llegada extremadamente tarde; si el admin que llega tarde se autoriza a sí mismo, el
     * control no controla nada. (No deja varado a nadie: admin/supervisor ya traen
     * `supervisor_override` en su propio ponche desde la app, así que el bloqueo ni les aplica ahí.)
     */
    public function test_nadie_puede_aprobar_su_propia_solicitud(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            // El que llega tarde ES un admin (puede llamar a /resolve por su rol).
            [$tenant, $adminTarde] = $this->makeSetup(60, 'admin');

            $this->actingAs($adminTarde)->postJson('/api/v1/clock/request-late-authorization', [])
                ->assertStatus(201);
            $reqId = DB::table('late_authorization_requests')->where('user_id', $adminTarde->id)->value('id');

            // Intenta auto-aprobarse → 403 y la solicitud sigue pendiente.
            $this->actingAs($adminTarde)
                ->postJson("/api/v1/admin/late-authorizations/{$reqId}/resolve", ['status' => 'approved'])
                ->assertStatus(403);

            $this->assertDatabaseHas('late_authorization_requests', [
                'id' => $reqId, 'status' => 'pending', 'resolved_by' => null,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Pero OTRO admin sí puede resolverla (el control funciona, sólo prohíbe el auto-servicio). */
    public function test_otro_admin_si_puede_resolver_la_solicitud(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $adminTarde] = $this->makeSetup(60, 'admin');
            $otroAdmin = $this->makeAdmin($tenant);

            $this->actingAs($adminTarde)->postJson('/api/v1/clock/request-late-authorization', [])
                ->assertStatus(201);
            $reqId = DB::table('late_authorization_requests')->where('user_id', $adminTarde->id)->value('id');

            $this->actingAs($otroAdmin)
                ->postJson("/api/v1/admin/late-authorizations/{$reqId}/resolve", ['status' => 'approved'])
                ->assertStatus(200);

            $this->assertDatabaseHas('late_authorization_requests', [
                'id' => $reqId, 'status' => 'approved', 'resolved_by' => $otroAdmin->id,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_el_admin_no_resuelve_solicitudes_de_otro_tenant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenantA, $userA] = $this->makeSetup(60);
            [$tenantB, ] = $this->makeSetup(60);
            $adminB = $this->makeAdmin($tenantB);
            $this->actingAs($userA)->postJson('/api/v1/clock/request-late-authorization', []);
            $id = DB::table('late_authorization_requests')->where('user_id', $userA->id)->value('id');

            $this->actingAs($adminB)->postJson("/api/v1/admin/late-authorizations/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(404);
            $this->assertSame('pending', DB::table('late_authorization_requests')->where('id', $id)->value('status'));
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- La integración: la aprobación levanta el bloqueo ----------------------------

    public function test_con_autorizacion_aprobada_el_bloqueo_se_levanta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00')); // 90 min tarde, umbral 60
        try {
            [$tenant, $user] = $this->makeSetup(60);
            $admin = $this->makeAdmin($tenant);

            // 1. Bloqueado sin autorización.
            $blocked = false;
            try { app(ClockService::class)->processPunch($user, 'check_in'); }
            catch (\Exception $e) { $blocked = true; }
            $this->assertTrue($blocked, 'sin autorización debe seguir bloqueado');

            // 2. El empleado solicita, el admin aprueba.
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);
            $id = DB::table('late_authorization_requests')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/late-authorizations/{$id}/resolve", ['status' => 'approved'])
                ->assertStatus(200);

            // 3. Ahora el MISMO check_in pasa (retardo registrado, pero no bloqueado).
            app(ClockService::class)->processPunch($user, 'check_in');
            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_in', 'is_late' => true,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_una_autorizacion_rechazada_no_levanta_el_bloqueo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);
            $admin = $this->makeAdmin($tenant);
            $this->actingAs($user)->postJson('/api/v1/clock/request-late-authorization', []);
            $id = DB::table('late_authorization_requests')->where('user_id', $user->id)->value('id');
            $this->actingAs($admin)->postJson("/api/v1/admin/late-authorizations/{$id}/resolve", ['status' => 'rejected']);

            $blocked = false;
            try { app(ClockService::class)->processPunch($user, 'check_in'); }
            catch (\Exception $e) { $blocked = true; }
            $this->assertTrue($blocked, 'una autorización rechazada NO debe levantar el bloqueo');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** La autorización aprobada de OTRO día no sirve para hoy. */
    public function test_autorizacion_aprobada_de_otro_dia_no_sirve(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:30:00'));
        try {
            [$tenant, $user] = $this->makeSetup(60);
            // Aprobada, pero para AYER.
            DB::table('late_authorization_requests')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-09',
                'status' => 'approved', 'requested_late_minutes' => 90,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $blocked = false;
            try { app(ClockService::class)->processPunch($user, 'check_in'); }
            catch (\Exception $e) { $blocked = true; }
            $this->assertTrue($blocked, 'la autorización de otro día no debe levantar el bloqueo de hoy');
        } finally {
            Carbon::setTestNow();
        }
    }
}
