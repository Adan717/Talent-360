<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R103: Intercambiar Comida validado SERVER-SIDE (spec §5.3 — el único PARCIAL del
 * audit de cobertura post-100%).
 *
 * El teatro que se cierra: la regla "sólo compañeros del mismo `job_role_id`" vivía únicamente
 * en el filtro del modal FE; `POST /sync/clock` con type='meal_swap' insertaba el registro sin
 * validar rol, pareo ni actor — un cliente hostil podía "intercambiar" a cualquier par del tenant.
 *
 * Reglas nuevas (todas server-side):
 *  1. `swap_with` (contraparte) es OBLIGATORIO y estructurado (ya no texto libre en details).
 *  2. La contraparte existe y es del MISMO tenant.
 *  3. Ambos expedientes tienen el MISMO job_role_id (§5.3: "compañeros del mismo nivel").
 *  4. El ACTOR debe ser uno de los dos intercambiantes — o un mando (el simulador del tenant 1
 *     postea con token de admin, patrón R87/R102).
 */
class MealSwapServerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'Empresa Swap', 'subdomain' => 'swap' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
    }

    private function makeEmp(string $name, ?int $jobRoleId, string $role = 'empleado'): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $name,
            'email' => strtolower($name) . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => $role,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name,
            'base_salary' => 3000.00, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true, 'job_role_id' => $jobRoleId,
        ]);
        return $user;
    }

    private function makeJobRole(string $name): int
    {
        return (int) DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'area' => 'Ops',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postSwap(User $actor, int $userId, int $swapWith)
    {
        return $this->actingAs($actor)->postJson('/api/v1/sync/clock', [
            'user_id' => $userId,
            'date' => now()->format('Y-m-d'),
            'type' => 'meal_swap',
            'time' => '00:00',
            'swap_with' => $swapWith,
            'details' => "Swapped meal slots with user {$swapWith}",
        ]);
    }

    public function test_swap_entre_mismo_puesto_pasa(): void
    {
        $rol = $this->makeJobRole('Cajero');
        $ana = $this->makeEmp('Ana', $rol);
        $pedro = $this->makeEmp('Pedro', $rol);

        $this->postSwap($ana, $ana->id, $pedro->id)->assertStatus(200);

        $this->assertDatabaseHas('time_entries', [
            'tenant_id' => $this->tenant->id, 'user_id' => $ana->id, 'type' => 'meal_swap',
        ]);
    }

    public function test_swap_entre_puestos_distintos_se_rechaza(): void
    {
        $ana = $this->makeEmp('Ana', $this->makeJobRole('Cajero'));
        $chef = $this->makeEmp('Chef', $this->makeJobRole('Cocina'));

        $this->postSwap($ana, $ana->id, $chef->id)->assertStatus(422);

        $this->assertDatabaseMissing('time_entries', [
            'tenant_id' => $this->tenant->id, 'type' => 'meal_swap',
        ]);
    }

    public function test_swap_sin_swap_with_se_rechaza(): void
    {
        $rol = $this->makeJobRole('Cajero');
        $ana = $this->makeEmp('Ana', $rol);

        $this->actingAs($ana)->postJson('/api/v1/sync/clock', [
            'user_id' => $ana->id, 'date' => now()->format('Y-m-d'),
            'type' => 'meal_swap', 'time' => '00:00',
            'details' => 'Swapped meal slots with user 999',
        ])->assertStatus(422);
    }

    public function test_contraparte_de_otro_tenant_se_rechaza(): void
    {
        $rol = $this->makeJobRole('Cajero');
        $ana = $this->makeEmp('Ana', $rol);
        $otroTenant = Tenant::create([
            'name' => 'Otra', 'subdomain' => 'otra' . uniqid(), 'plan' => 'enterprise', 'is_active' => true,
        ]);
        $ajeno = User::create([
            'tenant_id' => $otroTenant->id, 'name' => 'Ajeno',
            'email' => 'ajeno' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);

        $this->postSwap($ana, $ana->id, $ajeno->id)->assertStatus(422);
    }

    public function test_actor_que_no_es_parte_del_swap_se_rechaza(): void
    {
        $rol = $this->makeJobRole('Cajero');
        $ana = $this->makeEmp('Ana', $rol);
        $pedro = $this->makeEmp('Pedro', $rol);
        $intruso = $this->makeEmp('Intruso', $rol);

        $this->postSwap($intruso, $ana->id, $pedro->id)->assertStatus(403);

        $this->assertDatabaseMissing('time_entries', [
            'tenant_id' => $this->tenant->id, 'type' => 'meal_swap',
        ]);
    }

    public function test_mando_si_puede_swapear_a_terceros(): void
    {
        // El simulador del tenant 1 postea con el token del admin (patrón R87/R102).
        $rol = $this->makeJobRole('Cajero');
        $ana = $this->makeEmp('Ana', $rol);
        $pedro = $this->makeEmp('Pedro', $rol);
        $admin = $this->makeEmp('Admin', $rol, 'admin');

        $this->postSwap($admin, $ana->id, $pedro->id)->assertStatus(200);
    }

    public function test_contraparte_sin_expediente_se_rechaza(): void
    {
        $rol = $this->makeJobRole('Cajero');
        $ana = $this->makeEmp('Ana', $rol);
        $sinExp = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sin Expediente',
            'email' => 'sinexp' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);

        $this->postSwap($ana, $ana->id, $sinExp->id)->assertStatus(422);
    }
}
