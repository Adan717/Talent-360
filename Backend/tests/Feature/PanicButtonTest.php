<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Reloj R80 (Fase 1 / T1.1 del plan v2): Botón de Pánico REAL.
 *
 * Antes `triggerPanic` sólo hacía `addMatrixEvent` en el FE (teatro: confirmaba sin registrar nada
 * ni avisar a nadie fuera de esa pantalla). Ahora un endpoint PERSISTE el incidente (categoría + geo)
 * y ALERTA a los admin/supervisor DEL TENANT (patrón `notifyTenantAdmins`, R69 — jamás cross-tenant).
 *
 * Todo el enforcement es server-side: la categoría se valida contra una whitelist, el tenant sale del
 * usuario autenticado (no del cliente) y la resolución la puede hacer el reportante o un mando, nunca
 * un tercero ajeno.
 */
class PanicButtonTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa Panic',
            'subdomain' => 'panic' . uniqid(),
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
    }

    private function makeUser(Tenant $tenant, string $role = 'empleado', ?string $fcm = null): User
    {
        $u = User::create([
            'tenant_id' => $tenant->id,
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => $role . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
        if ($fcm !== null) {
            // fcm_token no es fillable → set directo.
            DB::table('users')->where('id', $u->id)->update(['fcm_token' => $fcm]);
        }
        return $u;
    }

    // ---- El reporte del empleado -----------------------------------------------------

    public function test_el_empleado_reporta_un_incidente_de_panico(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);

        $res = $this->actingAs($user)->postJson('/api/v1/clock/panic', [
            'category' => 'robo_asalto',
            'description' => 'Sujetos armados en la entrada',
            'latitude' => 19.4326,
            'longitude' => -99.1332,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('panic_incidents', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'category' => 'robo_asalto',
            'status' => 'active',
        ]);
        // La geo se persiste (categoría "con geo" del spec).
        $row = DB::table('panic_incidents')->where('user_id', $user->id)->first();
        $this->assertNotNull($row->latitude);
        $this->assertNotNull($row->longitude);
    }

    public function test_una_categoria_invalida_es_rechazada(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);

        $this->actingAs($user)->postJson('/api/v1/clock/panic', [
            'category' => 'zombis',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('panic_incidents')->count());
    }

    public function test_el_incidente_puede_no_traer_geo(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);

        $this->actingAs($user)->postJson('/api/v1/clock/panic', [
            'category' => 'incendio',
        ])->assertStatus(201);

        $row = DB::table('panic_incidents')->where('user_id', $user->id)->first();
        $this->assertNull($row->latitude);
        $this->assertNull($row->longitude);
        $this->assertSame('incendio', $row->category);
    }

    // ---- La alerta a los mandos (patrón R69, tenant-scoped) ---------------------------

    public function test_el_reporte_alerta_a_admin_y_supervisor_del_tenant(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $this->makeUser($tenant, 'admin', 'tok-admin');
        $this->makeUser($tenant, 'supervisor', 'tok-sup');

        // FCM está simulado en este entorno (el paquete Firebase no está instalado) → cada
        // destinatario deja un log "Sending FCM Notification to token [X]".
        Log::spy();

        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', [
            'category' => 'emergencia_medica',
        ])->assertStatus(201);

        Log::shouldHaveReceived('info')
            ->withArgs(fn($m, $c = []) => is_string($m) && str_contains($m, '[tok-admin]'))
            ->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn($m, $c = []) => is_string($m) && str_contains($m, '[tok-sup]'))
            ->once();
    }

    public function test_no_alerta_a_mandos_de_otro_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $reporter = $this->makeUser($tenantA);
        $this->makeUser($tenantB, 'admin', 'tok-otro-tenant');

        Log::spy();

        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', [
            'category' => 'fallo_energia',
        ])->assertStatus(201);

        Log::shouldNotHaveReceived('info',
            [\Mockery::on(fn($m) => is_string($m) && str_contains($m, '[tok-otro-tenant]'))]);
    }

    // ---- El panel del admin ----------------------------------------------------------

    public function test_el_admin_ve_los_incidentes_activos_de_su_tenant(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/panic-incidents');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json());
        $this->assertSame('incendio', $res->json('0.category'));
        $this->assertSame($reporter->id, (int) $res->json('0.user_id'));
    }

    public function test_el_panel_no_ve_incidentes_de_otro_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $reporterA = $this->makeUser($tenantA);
        $adminB = $this->makeUser($tenantB, 'admin');
        $this->actingAs($reporterA)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);

        $res = $this->actingAs($adminB)->getJson('/api/v1/admin/panic-incidents');

        $res->assertStatus(200);
        $this->assertCount(0, $res->json());
    }

    // ---- La resolución ---------------------------------------------------------------

    public function test_el_admin_resuelve_un_incidente(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);
        $id = DB::table('panic_incidents')->where('user_id', $reporter->id)->value('id');

        $this->actingAs($admin)->postJson("/api/v1/clock/panic/{$id}/resolve")->assertStatus(200);

        $this->assertDatabaseHas('panic_incidents', [
            'id' => $id, 'status' => 'resolved', 'resolved_by' => $admin->id,
        ]);
    }

    public function test_el_reportante_puede_resolver_su_propio_incidente(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);
        $id = DB::table('panic_incidents')->where('user_id', $reporter->id)->value('id');

        // El overlay de pánico se desactiva desde el mismo dispositivo del reportante.
        $this->actingAs($reporter)->postJson("/api/v1/clock/panic/{$id}/resolve")->assertStatus(200);

        $this->assertSame('resolved', DB::table('panic_incidents')->where('id', $id)->value('status'));
    }

    public function test_un_empleado_ajeno_no_resuelve_el_incidente(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $ajeno = $this->makeUser($tenant); // otro empleado del mismo tenant, no es mando ni reportante
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);
        $id = DB::table('panic_incidents')->where('user_id', $reporter->id)->value('id');

        $this->actingAs($ajeno)->postJson("/api/v1/clock/panic/{$id}/resolve")->assertStatus(403);

        $this->assertSame('active', DB::table('panic_incidents')->where('id', $id)->value('status'));
    }

    public function test_no_se_resuelve_un_incidente_de_otro_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $reporterA = $this->makeUser($tenantA);
        $adminB = $this->makeUser($tenantB, 'admin');
        $this->actingAs($reporterA)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);
        $id = DB::table('panic_incidents')->where('user_id', $reporterA->id)->value('id');

        $this->actingAs($adminB)->postJson("/api/v1/clock/panic/{$id}/resolve")->assertStatus(404);

        $this->assertSame('active', DB::table('panic_incidents')->where('id', $id)->value('status'));
    }

    public function test_un_incidente_ya_resuelto_no_se_re_resuelve(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);
        $id = DB::table('panic_incidents')->where('user_id', $reporter->id)->value('id');
        $this->actingAs($admin)->postJson("/api/v1/clock/panic/{$id}/resolve")->assertStatus(200);

        // Segundo intento (p.ej. doble toque) → 422, sin pisar resolved_by.
        $this->actingAs($admin)->postJson("/api/v1/clock/panic/{$id}/resolve")->assertStatus(422);
    }

    // ---- resolve-mine: sin contabilidad de ids en el cliente (review R80) ---------------

    /**
     * El review cazó que el id del incidente vivía sólo en la memoria de React: un reload, una
     * carrera con el POST en vuelo o un doble-tap lo pierden y el incidente quedaba 'active' para
     * siempre. `resolve-mine` resuelve TODOS los activos del propio usuario sin id.
     */
    public function test_resolve_mine_resuelve_todos_los_activos_propios(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        // Doble-tap: dos incidentes activos del mismo usuario.
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);
        $this->assertSame(2, DB::table('panic_incidents')->where('user_id', $reporter->id)->where('status', 'active')->count());

        $res = $this->actingAs($reporter)->postJson('/api/v1/clock/panic/resolve-mine');

        $res->assertStatus(200);
        $this->assertSame(2, (int) $res->json('resolved_count'));
        $this->assertSame(0, DB::table('panic_incidents')->where('user_id', $reporter->id)->where('status', 'active')->count());
    }

    public function test_resolve_mine_no_toca_incidentes_ajenos(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $otro = $this->makeUser($tenant);
        $this->actingAs($otro)->postJson('/api/v1/clock/panic', ['category' => 'robo_asalto']);

        $res = $this->actingAs($reporter)->postJson('/api/v1/clock/panic/resolve-mine');

        $res->assertStatus(200);
        $this->assertSame(0, (int) $res->json('resolved_count'));
        $this->assertSame('active', DB::table('panic_incidents')->where('user_id', $otro->id)->value('status'));
    }

    // ---- mine: rehidratación del overlay tras un reload (review R80) --------------------

    public function test_mine_devuelve_el_incidente_activo_propio(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'emergencia_medica']);

        $res = $this->actingAs($reporter)->getJson('/api/v1/clock/panic/mine');

        $res->assertStatus(200);
        $this->assertSame('emergencia_medica', $res->json('incident.category'));
        $this->assertSame('active', $res->json('incident.status'));
    }

    public function test_mine_es_null_sin_incidente_activo_y_no_ve_ajenos(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $otro = $this->makeUser($tenant);
        $this->actingAs($otro)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);

        // Sin incidente propio → null (el ajeno no se filtra).
        $this->actingAs($reporter)->getJson('/api/v1/clock/panic/mine')
            ->assertStatus(200)->assertJson(['incident' => null]);

        // Tras resolver el propio → null otra vez.
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'incendio']);
        $this->actingAs($reporter)->postJson('/api/v1/clock/panic/resolve-mine');
        $this->actingAs($reporter)->getJson('/api/v1/clock/panic/mine')
            ->assertStatus(200)->assertJson(['incident' => null]);
    }

    // ---- Bordes del panel (review R80) --------------------------------------------------

    /**
     * Un mando (admin/supervisor) puede reportar sin tener fila en employees; el panel debe caer a
     * users.name — un incidente ANÓNIMO en pleno asalto es inservible para el respondedor.
     */
    public function test_el_panel_muestra_el_nombre_aunque_el_reportante_no_tenga_expediente(): void
    {
        $tenant = $this->makeTenant();
        $supervisor = $this->makeUser($tenant, 'supervisor'); // sin fila en employees
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($supervisor)->postJson('/api/v1/clock/panic', ['category' => 'robo_asalto']);

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/panic-incidents');

        $res->assertStatus(200);
        $this->assertSame($supervisor->name, $res->json('0.employee_name'));
    }

    /** Mismo criterio que report(): un admin sin tenant recibe 403, no una lista vacía "sana". */
    public function test_el_panel_con_admin_sin_tenant_da_403(): void
    {
        $tenant = $this->makeTenant();
        $adminSinTenant = $this->makeUser($tenant, 'admin');
        DB::table('users')->where('id', $adminSinTenant->id)->update(['tenant_id' => null]);
        $adminSinTenant = $adminSinTenant->fresh();

        $this->actingAs($adminSinTenant)->getJson('/api/v1/admin/panic-incidents')->assertStatus(403);
    }

    /** El label del push es el CANÓNICO del spec (el FE lo espeja): 'Fallo General de Energía'. */
    public function test_el_push_usa_el_label_canonico(): void
    {
        $tenant = $this->makeTenant();
        $reporter = $this->makeUser($tenant);
        $this->makeUser($tenant, 'admin', 'tok-label');

        Log::spy();

        $this->actingAs($reporter)->postJson('/api/v1/clock/panic', ['category' => 'fallo_energia'])
            ->assertStatus(201);

        Log::shouldHaveReceived('info')
            ->withArgs(fn($m, $c = []) => is_string($m) && str_contains($m, 'Fallo General de Energía'))
            ->once();
    }
}
