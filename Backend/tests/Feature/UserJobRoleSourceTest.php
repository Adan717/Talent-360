<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ronda 41 + Ronda 73 (Reloj): el puesto que devuelve el contrato de auth (`/login`, `/me`) sale
 * SIEMPRE del EXPEDIENTE (`employees.job_role_id`).
 *
 * R41 lo resolvió cuando aún existía el duplicado legacy `users.job_role_id` (que derivaba a NULL y
 * dejaba al `currentUser` del frontend sin puesto → el Reloj no aplicaba la política de tolerancia).
 * **R73 ELIMINÓ la columna `users.job_role_id`**: ya no hay duplicado que sincronizar ni al cual
 * caer. El expediente es la ÚNICA fuente; sin expediente NO hay puesto (null), no se inventa.
 */
class UserJobRoleSourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Puesto',
            'subdomain' => 'empresa-puesto',
            'plan' => 'enterprise',
            'is_active' => true,
        ]);

        $role = JobRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Gerente de Sucursal',
            'area' => 'Gerencia',
            'portadorLlaves' => 'ambos',
        ]);

        return [$tenant, $role];
    }

    private function makeUser(Tenant $tenant, string $email, string $role): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Usuario ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
    }

    /** El expediente dice "Gerente" → /me devuelve ese puesto. */
    public function test_me_devuelve_el_puesto_del_expediente(): void
    {
        [$tenant, $role] = $this->makeSetup();
        $user = $this->makeUser($tenant, 'admin.puesto@test.com', 'admin');
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Admin con puesto',
            'job_role_id' => $role->id,
            'is_active_employee' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertSame($role->id, $response->json('user.job_role_id'), 'el puesto sale del expediente');
    }

    /** El mismo contrato en /login (la vía por la que el frontend arma su currentUser). */
    public function test_login_devuelve_el_puesto_del_expediente(): void
    {
        [$tenant, $role] = $this->makeSetup();
        $user = $this->makeUser($tenant, 'login.puesto@test.com', 'empleado');
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Colaborador',
            'job_role_id' => $role->id,
            'is_active_employee' => true,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'login.puesto@test.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $this->assertSame($role->id, $response->json('user.job_role_id'));
    }

    /**
     * R73: un usuario SIN expediente (cuenta puramente administrativa) NO tiene puesto → null. Antes
     * de R73 se caía al valor legacy de `users.job_role_id`; esa columna ya no existe.
     */
    public function test_usuario_sin_expediente_no_tiene_puesto(): void
    {
        [$tenant] = $this->makeSetup();
        $user = $this->makeUser($tenant, 'sin.exp@test.com', 'admin');

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertNull($response->json('user.job_role_id'), 'sin expediente no hay puesto');
    }

    /** Un expediente SIN puesto significa "sin puesto" → null. */
    public function test_expediente_sin_puesto_devuelve_null(): void
    {
        [$tenant] = $this->makeSetup();
        $user = $this->makeUser($tenant, 'exp.sinpuesto@test.com', 'empleado');
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Expediente sin puesto',
            'job_role_id' => null,
            'is_active_employee' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertNull($response->json('user.job_role_id'), 'el expediente manda: sin puesto es sin puesto');
    }
}
