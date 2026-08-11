<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the fallback tenant needed due to BelongsToTenant trait on User
        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'public_slug' => 'default',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Register temporary test routes protected by our middleware
        Route::middleware(['web', 'role:admin'])->get('/_test/admin-only', function () {
            return response()->json(['message' => 'welcome admin']);
        });

        Route::middleware(['web', 'role:admin,manager'])->get('/_test/admin-or-manager', function () {
            return response()->json(['message' => 'welcome privileged user']);
        });
    }

    public function test_unauthenticated_user_gets_401(): void
    {
        $response = $this->getJson('/_test/admin-only');

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.'
        ]);
    }

    public function test_unauthorized_role_gets_403(): void
    {
        $user = User::factory()->create([
            'role' => 'employee',
        ]);

        $response = $this->actingAs($user)->getJson('/_test/admin-only');

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Acceso denegado. Permisos insuficientes.'
        ]);
    }

    public function test_authorized_role_gets_access(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($user)->getJson('/_test/admin-only');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'welcome admin'
        ]);
    }

    public function test_multiple_roles_allowed(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $manager = User::factory()->create([
            'role' => 'manager',
        ]);

        $employee = User::factory()->create([
            'role' => 'employee',
        ]);

        // Admin should have access
        $this->actingAs($admin)->getJson('/_test/admin-or-manager')
            ->assertStatus(200)
            ->assertJson(['message' => 'welcome privileged user']);

        // Manager should have access
        $this->actingAs($manager)->getJson('/_test/admin-or-manager')
            ->assertStatus(200)
            ->assertJson(['message' => 'welcome privileged user']);

        // Employee should NOT have access
        $this->actingAs($employee)->getJson('/_test/admin-or-manager')
            ->assertStatus(403)
            ->assertJson(['message' => 'Acceso denegado. Permisos insuficientes.']);
    }

    public function test_platform_admin_routes_are_correctly_protected(): void
    {
        // Unauthenticated user gets 401
        $this->getJson('/api/v1/platform/stats')->assertStatus(401);
        $this->getJson('/api/v1/platform/tenants')->assertStatus(401);

        // Unauthorized user (e.g., role = employee) gets 403
        $employee = User::factory()->create([
            'role' => 'employee',
        ]);
        $this->actingAs($employee)->getJson('/api/v1/platform/stats')->assertStatus(403);
        $this->actingAs($employee)->getJson('/api/v1/platform/tenants')->assertStatus(403);

        // Authorized user (role = platform_admin) gets 200
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);
        
        $this->actingAs($platformAdmin)->getJson('/api/v1/platform/stats')->assertStatus(200);
        
        // Let's also assert structure if needed, but status 200 is key.
        $this->actingAs($platformAdmin)->getJson('/api/v1/platform/tenants')->assertStatus(200);
    }

    public function test_company_admin_routes_are_correctly_protected(): void
    {
        // 1. Unauthenticated gets 401
        $this->getJson('/api/v1/employees')->assertStatus(401);
        $this->getJson('/api/v1/job-roles')->assertStatus(401);

        // 2. Unauthorized role (e.g. employee) gets 403
        $employee = User::factory()->create([
            'role' => 'employee',
        ]);
        $this->actingAs($employee)->getJson('/api/v1/employees')->assertStatus(403);
        $this->actingAs($employee)->getJson('/api/v1/job-roles')->assertStatus(403);

        // 3. Authorized role (e.g. supervisor) gets 200
        $supervisor = User::factory()->create([
            'role' => 'supervisor',
        ]);
        $this->actingAs($supervisor)->getJson('/api/v1/employees')->assertStatus(200);
        $this->actingAs($supervisor)->getJson('/api/v1/job-roles')->assertStatus(200);

        // 4. Authorized role (e.g. admin) gets 200
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);
        $this->actingAs($admin)->getJson('/api/v1/employees')->assertStatus(200);
        $this->actingAs($admin)->getJson('/api/v1/job-roles')->assertStatus(200);
    }

    public function test_sync_init_is_platform_admin_only(): void
    {
        // 1. Unauthenticated gets 401
        $this->postJson('/api/v1/sync/init', ['users' => [], 'configs' => []])->assertStatus(401);

        // 2. Unauthorized role (e.g. admin or supervisor) gets 403 — initDb hace TRUNCATE
        // de employees/job_roles/permissions sin filtrar por tenant_id.
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($supervisor)->postJson('/api/v1/sync/init', ['users' => [], 'configs' => []])->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/v1/sync/init', ['users' => [], 'configs' => []])->assertStatus(403);

        // 3. Authorized role (platform_admin) gets 200
        $platformAdmin = User::factory()->create(['role' => 'platform_admin']);
        $this->actingAs($platformAdmin)->postJson('/api/v1/sync/init', ['users' => [], 'configs' => []])->assertStatus(200);
    }

    public function test_sync_reset_is_open_to_admin_and_supervisor(): void
    {
        // /sync/reset ahora purga solo datos del Simulador Matrix (simulation_session_id
        // no nulo), no un TRUNCATE global — por eso admin/supervisor de la propia empresa
        // sí pueden usarlo, a diferencia de /sync/init.
        $this->postJson('/api/v1/sync/reset')->assertStatus(401);

        $empleado = User::factory()->create(['role' => 'empleado']);
        $this->actingAs($empleado)->postJson('/api/v1/sync/reset')->assertStatus(403);

        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $this->actingAs($supervisor)->postJson('/api/v1/sync/reset')->assertStatus(200);

        // ENMIENDA merge F3 (fixture): la resolución de tenant es ahora ESTRICTA (sin fallback
        // `?? 1`) — un admin sin tenant debe indicarlo. El contrato del test no cambia: un admin
        // DE SU EMPRESA puede purgar. Se le fija el tenant explícito que en la app real tiene.
        $admin = User::factory()->create(['role' => 'admin']);
        \DB::table('users')->where('id', $admin->id)->update(['tenant_id' => 1]);
        $this->actingAs($admin->fresh())->postJson('/api/v1/sync/reset')->assertStatus(200);
    }

    public function test_empleado_routes_are_correctly_protected(): void
    {
        // 1. Unauthenticated gets 401
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->postJson('/api/v1/clock/punch')->assertStatus(401);

        // 2. Authenticated user with any valid role (e.g. empleado, supervisor, platform_admin) gets 200 (or non-401/403)
        $employee = User::factory()->create([
            'role' => 'empleado',
        ]);
        $this->actingAs($employee)->getJson('/api/v1/me')->assertStatus(200);
        
        $supervisor = User::factory()->create([
            'role' => 'supervisor',
        ]);
        $this->actingAs($supervisor)->getJson('/api/v1/me')->assertStatus(200);

        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);
        $this->actingAs($platformAdmin)->getJson('/api/v1/me')->assertStatus(200);
    }

    public function test_public_vacancies_endpoint_works_for_unauthenticated_users(): void
    {
        // 1. Create a job role first
        DB::table('job_roles')->insert([
            'id' => 10,
            'name' => 'Cajero',
            'area' => 'Cajas',
            'esAperturador' => false,
            'portadorLlaves' => 'ninguno',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create a vacancy belonging to tenant 1
        DB::table('vacancies')->insert([
            'id' => 101,
            'tenant_id' => 1,
            'job_role_id' => 10,
            'title' => 'Cajero de Prueba',
            'description' => 'Prueba de vacante pública',
            'requirements' => json_encode(['Actitud de servicio']),
            'is_active' => true,
            'is_hidden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Access public vacancies as an unauthenticated user
        $response = $this->getJson('/api/v1/public/vacancies/default');

        // 4. Assert status is 200
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'id' => 101,
            'title' => 'Cajero de Prueba',
        ]);
    }

    public function test_sync_init_and_reset_day_fail_in_production_environment(): void
    {
        // 1. Set environment to production
        $this->app['env'] = 'production';

        // 2. Create platform admin user
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);

        // 3. Request init endpoint — sigue deshabilitado en producción (TRUNCATE global
        // de employees/job_roles/permissions sin filtrar por tenant).
        $responseInit = $this->actingAs($platformAdmin)->postJson('/api/v1/sync/init', ['users' => [], 'configs' => []]);
        $responseInit->assertStatus(403);
        $responseInit->assertJson([
            'error' => 'Este endpoint está deshabilitado en producción.'
        ]);

        // 4. Request reset_day endpoint — igual, sigue deshabilitado.
        $responseResetDay = $this->actingAs($platformAdmin)->postJson('/api/v1/sync/reset_day');
        $responseResetDay->assertStatus(403);
        $responseResetDay->assertJson([
            'error' => 'Este endpoint está deshabilitado en producción.'
        ]);
    }

    public function test_sync_reset_works_in_production_now_that_it_only_purges_simulator_data(): void
    {
        // /sync/reset ya no es un TRUNCATE global — ahora solo purga filas con
        // simulation_session_id no nulo, así que no hay razón para deshabilitarlo en
        // producción (Francisco lo necesita corriendo en su propio tenant real).
        $this->app['env'] = 'production';

        // ENMIENDA merge F3 (fixture): tenant explícito — la resolución estricta ya no cae a 1.
        $admin = User::factory()->create(['role' => 'admin']);
        \DB::table('users')->where('id', $admin->id)->update(['tenant_id' => 1]);

        $response = $this->actingAs($admin->fresh())->postJson('/api/v1/sync/reset');

        $response->assertStatus(200);
    }

    /**
     * CRITERIO NUEVO (2026-08-11): la alerta se archiva en la empresa del PORTAL que se visita, no
     * en la que diga el cuerpo de la petición.
     *
     * Esta prueba afirmaba lo contrario —que se podía crear mandando `tenant_id` a pelo—, y eso
     * era justamente el agujero: en una ruta pública el `tenant_id` lo pone el visitante, así que
     * cualquiera escribía correos en la lista de la empresa que eligiera. Además el portal mandaba
     * siempre `tenant_id: 1` (sin sesión no hay de dónde sacarlo), y por eso el correo de quien
     * pedía aviso en la bolsa de CUALQUIER cliente acababa archivado en la empresa 1.
     */
    public function test_public_vacancy_alerts_requires_the_portal_slug(): void
    {
        $response = $this->postJson('/api/v1/public/vacancy-alerts', [
            'tenant_id' => 1,
            'email' => 'alerta@test.com',
            'job_role_name' => 'Supervisor de Tienda'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('slug');

        $this->assertDatabaseMissing('vacancy_alerts', ['email' => 'alerta@test.com']);
    }

    public function test_public_vacancy_alerts_can_be_created_using_slug(): void
    {
        $response = $this->postJson('/api/v1/public/vacancy-alerts', [
            'slug' => 'default',
            'email' => 'alerta-slug@test.com',
            'job_role_name' => 'Cajero'
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'tenant_id' => 1,
            'email' => 'alerta-slug@test.com',
            'job_role_name' => 'Cajero'
        ]);
    }

    public function test_public_vacancy_alerts_validation(): void
    {
        $response = $this->postJson('/api/v1/public/vacancy-alerts', [
            'tenant_id' => 9999, // non-existent
            'email' => 'not-an-email',
            'job_role_name' => ''
        ]);

        $response->assertStatus(422);
    }

    public function test_platform_admin_can_delete_tenant(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);

        $tenantToDelete = new \App\Models\Tenant();
        $tenantToDelete->id = 2;
        $tenantToDelete->name = 'Eliminar S.A.';
        $tenantToDelete->subdomain = 'eliminar';
        $tenantToDelete->plan = 'free';
        $tenantToDelete->max_users = 10;
        $tenantToDelete->is_active = true;
        $tenantToDelete->save();

        $response = $this->actingAs($platformAdmin)
            ->deleteJson("/api/v1/platform/tenants/{$tenantToDelete->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Empresa eliminada con éxito'
        ]);

        // Tenant borrado físicamente en cascada: la fila se elimina por completo de la BD.
        $this->assertDatabaseMissing('tenants', [
            'id' => $tenantToDelete->id
        ]);
    }

    public function test_cannot_delete_default_tenant(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);

        $response = $this->actingAs($platformAdmin)
            ->deleteJson("/api/v1/platform/tenants/1");

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'No se puede eliminar el inquilino principal por defecto.'
        ]);
    }

    public function test_non_platform_admin_cannot_delete_tenant(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)
            ->deleteJson("/api/v1/platform/tenants/1");

        $response->assertStatus(403);
    }
}
