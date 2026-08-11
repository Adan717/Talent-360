<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserRoleRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'T', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();
        return $user;
    }

    public function test_creating_a_user_with_platform_admin_role_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $jobRole = JobRole::create(['name' => 'Cajero', 'tenant_id' => 1, 'area' => 'Ops']);

        $response = $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Intento Escalada',
            'email' => 'escalada@x.com',
            'role' => 'platform_admin', // §49: no permitido en la tabla users
            // El sueldo es obligatorio en el alta desde 2026-08-08.
            'salary' => 3000,
            'hire_date' => '2026-08-01',
            'job_role_id' => $jobRole->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'escalada@x.com']);
    }

    public function test_creating_a_user_with_a_normal_company_role_still_works(): void
    {
        $admin = $this->makeAdmin();
        $jobRole = JobRole::create(['name' => 'Cajero', 'tenant_id' => 1, 'area' => 'Ops']);

        $response = $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Empleado Normal',
            'email' => 'normal@x.com',
            'role' => 'empleado',
            // El sueldo es obligatorio en el alta desde 2026-08-08.
            'salary' => 3000,
            'hire_date' => '2026-08-01',
            'job_role_id' => $jobRole->id,
        ]);

        // No debe fallar por validación de rol (el rol de empresa es válido).
        $this->assertNotEquals(422, $response->getStatusCode());
    }
}
