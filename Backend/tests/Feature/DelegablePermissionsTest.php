<?php

namespace Tests\Feature;

use App\Http\Middleware\PermissionMiddleware;
use App\Models\User;
use App\Services\TenantInitializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DelegablePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Siembra el catálogo de 12 capacidades para el tenant 1 (en tests los tenants se
        // insertan por DB, sin disparar el hook del modelo que lo haría en producción).
        app(TenantInitializationService::class)->seedPermissionCatalog(1);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    private function makeJobRole(string $name = 'Gerente de Tienda'): int
    {
        return DB::table('job_roles')->insertGetId([
            'tenant_id' => 1,
            'name' => $name,
            'area' => 'Operaciones',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignEmployee(User $user, int $jobRoleId): void
    {
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name ?? 'Empleado',
            'job_role_id' => $jobRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grant(int $jobRoleId, string $capability): void
    {
        $permId = DB::table('permissions')->where('tenant_id', 1)->where('name', $capability)->value('id');
        DB::table('role_permissions')->insert([
            'tenant_id' => 1,
            'job_role_id' => $jobRoleId,
            'permission_id' => $permId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runMiddleware(User $user, string $capability): int
    {
        $mw = new PermissionMiddleware();
        $request = Request::create('/api/v1/admin/payroll', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $mw->handle($request, fn ($r) => response('OK', 200), $capability);

        return $response->getStatusCode();
    }

    // ---------- Middleware ----------

    public function test_admin_bypasses_permission_middleware(): void
    {
        $admin = $this->makeUser('admin');
        $this->assertEquals(200, $this->runMiddleware($admin, 'manage_payroll'));
    }

    public function test_supervisor_without_capability_is_denied(): void
    {
        $supervisor = $this->makeUser('supervisor');
        $this->assignEmployee($supervisor, $this->makeJobRole());

        $this->assertEquals(403, $this->runMiddleware($supervisor, 'manage_payroll'));
    }

    public function test_supervisor_with_capability_passes(): void
    {
        $supervisor = $this->makeUser('supervisor');
        $jobRoleId = $this->makeJobRole();
        $this->assignEmployee($supervisor, $jobRoleId);
        $this->grant($jobRoleId, 'manage_payroll');

        $this->assertEquals(200, $this->runMiddleware($supervisor, 'manage_payroll'));
    }

    // ---------- Endpoints de matriz ----------

    public function test_matrix_get_returns_catalog_and_roles(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeJobRole('Cajero');

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/permissions/matrix');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success', 'capabilities', 'indelegable', 'supervisor_defaults', 'job_roles', 'matrix',
        ]);
        // 12 capacidades delegables en el catálogo.
        $this->assertCount(12, $response->json('capabilities'));
    }

    public function test_admin_can_grant_capability_and_it_takes_effect(): void
    {
        $admin = $this->makeUser('admin');
        $supervisor = $this->makeUser('supervisor');
        $jobRoleId = $this->makeJobRole();
        $this->assignEmployee($supervisor, $jobRoleId);

        // Antes: sin capacidad → 403.
        $this->assertEquals(403, $this->runMiddleware($supervisor, 'manage_payroll'));

        // El admin otorga manage_payroll a ese puesto (e intenta colar una indelegable).
        $put = $this->actingAs($admin)->putJson('/api/v1/admin/permissions/matrix', [
            'matrix' => [
                (string) $jobRoleId => ['manage_payroll', 'manage_billing'],
            ],
        ]);
        $put->assertStatus(200);

        // La indelegable fue ignorada; solo se guardó la delegable.
        $this->assertDatabaseHas('role_permissions', [
            'tenant_id' => 1,
            'job_role_id' => $jobRoleId,
            'permission_id' => DB::table('permissions')->where('tenant_id', 1)->where('name', 'manage_payroll')->value('id'),
        ]);
        $this->assertDatabaseMissing('permissions', ['tenant_id' => 1, 'name' => 'manage_billing']);

        // Después: el puesto ya tiene la capacidad → pasa.
        $this->assertEquals(200, $this->runMiddleware($supervisor, 'manage_payroll'));
    }

    public function test_matrix_endpoints_are_admin_only(): void
    {
        $supervisor = $this->makeUser('supervisor');

        $this->actingAs($supervisor)->getJson('/api/v1/admin/permissions/matrix')->assertStatus(403);
        $this->actingAs($supervisor)->putJson('/api/v1/admin/permissions/matrix', ['matrix' => []])->assertStatus(403);
    }
}
