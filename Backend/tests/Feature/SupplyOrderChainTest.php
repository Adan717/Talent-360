<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\SupplyOrder;
use App\Models\SupplyOrderStageRole;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplyOrderChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'pro',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(?int $jobRoleId = null, string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'job_role_id' => $jobRoleId,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function role(string $name): JobRole
    {
        return JobRole::create(['name' => $name, 'tenant_id' => 1, 'area' => 'Ops']);
    }

    /** Crea un pedido directo con snapshot de responsables por etapa. */
    private function makeOrderAt(string $status, array $stageRoles): SupplyOrder
    {
        $order = SupplyOrder::create([
            'tenant_id' => 1,
            'supplier_name' => 'Proveedor Acme',
            'status' => $status,
        ]);

        foreach (SupplyOrder::STAGES as $stage) {
            SupplyOrderStageRole::create([
                'supply_order_id' => $order->id,
                'stage' => $stage,
                'job_role_id' => $stageRoles[$stage] ?? null,
            ]);
        }

        return $order;
    }

    public function test_update_and_read_back_chain_config(): void
    {
        $admin = $this->makeUser(null, 'admin');
        $compras = $this->role('Compras');
        $ventas = $this->role('Ventas');

        $update = $this->actingAs($admin)->putJson('/api/v1/supply-chain/config', [
            'config' => [
                ['stage' => 'generado', 'job_role_id' => $compras->id],
                ['stage' => 'listo_exhibir', 'job_role_id' => $ventas->id],
            ],
        ]);
        $update->assertStatus(200);

        $read = $this->actingAs($admin)->getJson('/api/v1/supply-chain/config');
        $read->assertStatus(200);

        $config = collect($read->json('config'))->keyBy('stage');
        $this->assertEquals($compras->id, $config['generado']['job_role_id']);
        $this->assertEquals($ventas->id, $config['listo_exhibir']['job_role_id']);
        $this->assertNull($config['recibido']['job_role_id']);
    }

    public function test_config_requires_admin_or_supervisor(): void
    {
        $employee = $this->makeUser(null, 'empleado');

        $response = $this->actingAs($employee)->getJson('/api/v1/supply-chain/config');
        $response->assertStatus(403);
    }

    public function test_creating_an_order_snapshots_the_tenant_config(): void
    {
        $admin = $this->makeUser(null, 'admin');
        $compras = $this->role('Compras');
        $produccion = $this->role('Producción');

        DB::table('supply_chain_stage_roles')->insert([
            ['tenant_id' => 1, 'stage' => 'generado', 'job_role_id' => $compras->id, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => 1, 'stage' => 'recibido', 'job_role_id' => $produccion->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/supply-orders', [
            'supplier_name' => 'Distribuidora Sur',
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $orderId = $response->json('order.id');

        // Las 5 etapas se snapshotearon; las 2 configuradas con su rol, las demás null.
        $this->assertDatabaseCount('supply_order_stage_roles', 5);
        $this->assertDatabaseHas('supply_order_stage_roles', [
            'supply_order_id' => $orderId, 'stage' => 'generado', 'job_role_id' => $compras->id,
        ]);
        $this->assertDatabaseHas('supply_order_stage_roles', [
            'supply_order_id' => $orderId, 'stage' => 'recibido', 'job_role_id' => $produccion->id,
        ]);
        $this->assertDatabaseHas('supply_order_stage_roles', [
            'supply_order_id' => $orderId, 'stage' => 'almacenado', 'job_role_id' => null,
        ]);
    }

    public function test_changing_tenant_config_does_not_affect_in_flight_orders(): void
    {
        $admin = $this->makeUser(null, 'admin');
        $compras = $this->role('Compras');
        $otro = $this->role('Otro puesto');

        DB::table('supply_chain_stage_roles')->insert([
            ['tenant_id' => 1, 'stage' => 'generado', 'job_role_id' => $compras->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $orderId = $this->actingAs($admin)->postJson('/api/v1/supply-orders', ['supplier_name' => 'X'])->json('order.id');

        // El admin cambia la config del tenant DESPUÉS de crear el pedido.
        $this->actingAs($admin)->putJson('/api/v1/supply-chain/config', [
            'config' => [['stage' => 'generado', 'job_role_id' => $otro->id]],
        ])->assertStatus(200);

        // El pedido en curso conserva su responsable original (snapshot).
        $this->assertDatabaseHas('supply_order_stage_roles', [
            'supply_order_id' => $orderId, 'stage' => 'generado', 'job_role_id' => $compras->id,
        ]);
    }

    public function test_advancing_stage_notifies_the_responsible_job_role(): void
    {
        $produccionRole = $this->role('Producción');
        $produccionUser = $this->makeUser($produccionRole->id);
        $mover = $this->makeUser(null, 'empleado');

        // Pedido en 'generado'; al avanzar pasa a 'por_llegar'. Configuro que
        // 'por_llegar' es responsabilidad de Producción para verificar el aviso.
        $order = $this->makeOrderAt('generado', ['por_llegar' => $produccionRole->id]);

        $this->mock(NotificationService::class, function ($mock) use ($produccionRole) {
            $mock->shouldReceive('sendToJobRole')
                ->once()
                ->with($produccionRole->id, 1, \Mockery::type('string'), \Mockery::type('string'));
        });

        $response = $this->actingAs($mover)->patchJson("/api/v1/supply-orders/{$order->id}/advance-stage");

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('supply_orders', ['id' => $order->id, 'status' => 'por_llegar']);
    }

    public function test_advancing_to_listo_exhibir_generates_an_exhibit_task_for_ventas(): void
    {
        $ventasRole = $this->role('Ventas');
        $mover = $this->makeUser(null, 'empleado');

        // Pedido en 'almacenado'; al avanzar llega a 'listo_exhibir'.
        $order = $this->makeOrderAt('almacenado', ['listo_exhibir' => $ventasRole->id]);

        $response = $this->actingAs($mover)->patchJson("/api/v1/supply-orders/{$order->id}/advance-stage");
        $response->assertStatus(200);

        $this->assertDatabaseHas('supply_orders', ['id' => $order->id, 'status' => 'listo_exhibir']);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Exhibir producto: Proveedor Acme',
            'target_type' => 'role',
            'target_id' => $ventasRole->id,
            'tenant_id' => 1,
        ]);
        $task = DB::table('tasks')->where('title', 'Exhibir producto: Proveedor Acme')->first();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id, 'status' => 'pending', 'user_id' => null, 'origin' => 'extra',
        ]);
    }

    public function test_cannot_advance_past_the_last_stage(): void
    {
        $mover = $this->makeUser(null, 'empleado');
        $order = $this->makeOrderAt('listo_exhibir', []);

        $response = $this->actingAs($mover)->patchJson("/api/v1/supply-orders/{$order->id}/advance-stage");

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_orders_are_scoped_to_the_tenant(): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'Otro', 'subdomain' => 'otro2', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherOrder = SupplyOrder::create(['tenant_id' => 2, 'supplier_name' => 'Ajeno', 'status' => 'generado']);

        $admin = $this->makeUser(null, 'admin');
        $mine = $this->makeOrderAt('generado', []);

        $response = $this->actingAs($admin)->getJson('/api/v1/supply-orders');
        $response->assertStatus(200);

        $ids = collect($response->json('orders'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($otherOrder->id, $ids);
    }
}
