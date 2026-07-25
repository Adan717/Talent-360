<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * role_clock_policies: una política por (job_role_id, tenant_id). updateRolePolicy hacía un
 * check-then-insert no atómico (carrera → duplicados). Ahora hay un unique + un `upsert`
 * que en conflicto actualiza SOLO `config` (preservando el `policy_name` personalizado por
 * seeders/plantillas — que la vieja rama UPDATE también preservaba, y un updateOrInsert naíf
 * habría pisado).
 */
class RolePolicyUniqueTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $sub): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $sub, 'subdomain' => $sub,
            'plan' => 'enterprise', 'is_active' => true,
        ]);
    }

    private function makeAdmin(int $tenantId): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'Admin', 'email' => 'admin' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);
    }

    private function makeJobRole(int $tenantId, string $name): JobRole
    {
        return JobRole::create(['tenant_id' => $tenantId, 'name' => $name, 'area' => 'General']);
    }

    private function insertPolicy(int $jobRoleId, int $tenantId, string $name, array $config): void
    {
        DB::table('role_clock_policies')->insert([
            'job_role_id' => $jobRoleId, 'tenant_id' => $tenantId,
            'policy_name' => $name, 'config' => json_encode($config),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_duplicate_policy_is_rejected(): void
    {
        $tenant = $this->makeTenant('rp-dup');
        $role = $this->makeJobRole($tenant->id, 'Cajero');
        $this->insertPolicy($role->id, $tenant->id, 'A', ['x' => 1]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->insertPolicy($role->id, $tenant->id, 'B', ['y' => 2]);
    }

    public function test_update_preserves_custom_policy_name(): void
    {
        $tenant = $this->makeTenant('rp-preserve');
        $admin = $this->makeAdmin($tenant->id);
        $role = $this->makeJobRole($tenant->id, 'Cajero');
        $this->insertPolicy($role->id, $tenant->id, 'Perfil Cajero', ['old' => true]);

        $response = $this->actingAs($admin)->putJson("/api/v1/sync/role-policies/{$role->id}", [
            'new' => true, 'foo' => 'bar',
        ]);

        $response->assertStatus(200);
        // El nombre personalizado se PRESERVA; el config se reemplaza.
        $this->assertDatabaseHas('role_clock_policies', [
            'job_role_id' => $role->id, 'tenant_id' => $tenant->id, 'policy_name' => 'Perfil Cajero',
        ]);
        $config = json_decode(DB::table('role_clock_policies')
            ->where('job_role_id', $role->id)->where('tenant_id', $tenant->id)->value('config'), true);
        $this->assertTrue($config['new']);
        $this->assertArrayNotHasKey('old', $config);
        // Sigue habiendo una sola fila.
        $this->assertSame(1, DB::table('role_clock_policies')->where('job_role_id', $role->id)->count());
    }

    public function test_repeated_updates_stay_idempotent(): void
    {
        $tenant = $this->makeTenant('rp-idem');
        $admin = $this->makeAdmin($tenant->id);
        $role = $this->makeJobRole($tenant->id, 'Cajero');
        $this->insertPolicy($role->id, $tenant->id, 'Perfil Cajero', ['v' => 0]);

        // Dos updates consecutivos: el nombre personalizado sobrevive a ambos y gana el último config.
        $this->actingAs($admin)->putJson("/api/v1/sync/role-policies/{$role->id}", ['v' => 1])->assertStatus(200);
        $this->actingAs($admin)->putJson("/api/v1/sync/role-policies/{$role->id}", ['v' => 2])->assertStatus(200);

        $this->assertSame(1, DB::table('role_clock_policies')->where('job_role_id', $role->id)->count());
        $this->assertDatabaseHas('role_clock_policies', [
            'job_role_id' => $role->id, 'tenant_id' => $tenant->id, 'policy_name' => 'Perfil Cajero',
        ]);
        $config = json_decode(DB::table('role_clock_policies')
            ->where('job_role_id', $role->id)->where('tenant_id', $tenant->id)->value('config'), true);
        $this->assertSame(2, $config['v']);
    }

    public function test_update_creates_policy_when_absent(): void
    {
        $tenant = $this->makeTenant('rp-create');
        $admin = $this->makeAdmin($tenant->id);
        $role = $this->makeJobRole($tenant->id, 'Almacén');

        $response = $this->actingAs($admin)->putJson("/api/v1/sync/role-policies/{$role->id}", ['a' => 1]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('role_clock_policies', [
            'job_role_id' => $role->id, 'tenant_id' => $tenant->id, 'policy_name' => 'Perfil Personalizado',
        ]);
    }

    public function test_different_roles_allowed(): void
    {
        $tenant = $this->makeTenant('rp-diff');
        $r1 = $this->makeJobRole($tenant->id, 'Rol 1');
        $r2 = $this->makeJobRole($tenant->id, 'Rol 2');

        $this->insertPolicy($r1->id, $tenant->id, 'A', ['x' => 1]);
        $this->insertPolicy($r2->id, $tenant->id, 'B', ['y' => 2]);

        $this->assertSame(2, DB::table('role_clock_policies')->count());
    }
}
