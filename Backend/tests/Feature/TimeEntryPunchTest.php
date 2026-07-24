<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /clock/punch (TimeEntryController::punch) recibe un `user_id` destino y escribe en el
 * tenant de ESE usuario. Debe validar que el destino pertenece al tenant del emisor
 * autenticado — si no, es una inyección de ponche cross-tenant (mismo tipo de fuga que
 * cerró la Ronda 4 en /sync/clock, que aquí faltaba).
 */
class TimeEntryPunchTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $sub): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $sub,
            'subdomain' => $sub,
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
    }

    private function makeUser(int $tenantId, string $email, string $role = 'empleado'): User
    {
        return User::create([
            'tenant_id' => $tenantId,
            'name' => 'Usuario ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
    }

    private function enableSimulatedTime(int $tenantId): void
    {
        DB::table('system_settings')->insert([
            'tenant_id' => $tenantId,
            'key' => 'time_mode',
            'value' => '"simulated"',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_punch_rejects_user_from_other_tenant(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $actor = $this->makeUser($tenantA->id, 'actor@a.local', 'admin');

        $tenantB = $this->makeTenant('tenant-b');
        $foreign = $this->makeUser($tenantB->id, 'ajeno@b.local');

        // El emisor (tenant A) intenta fichar por un usuario del tenant B.
        $response = $this->actingAs($actor)->postJson('/api/v1/clock/punch', [
            'user_id' => $foreign->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $foreign->id]);
    }

    public function test_punch_allows_same_tenant_delegation(): void
    {
        // Caso legítimo: un kiosco/gerente ficha por un colaborador del MISMO tenant.
        $tenant = $this->makeTenant('tenant-c');
        $actor = $this->makeUser($tenant->id, 'gerente@c.local', 'admin');
        $target = $this->makeUser($tenant->id, 'colab@c.local');
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $target->id,
            'name' => 'Colaborador C',
            'base_salary' => 3000.00,
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        $this->enableSimulatedTime($tenant->id);

        $response = $this->actingAs($actor)->postJson('/api/v1/clock/punch', [
            'user_id' => $target->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $target->id,
            'type' => 'check_in',
        ]);
    }

    public function test_punch_rejects_null_tenant_actor(): void
    {
        // Un platform_admin (tenant_id null, además omite tenant.active) NO debe poder
        // fichar por nadie: sin la guarda, el fallback `?? 1` lo trataría como tenant 1 y
        // podría inyectar ponches a ese tenant real.
        $target = $this->makeUser(1, 'target-t1@x.local'); // tenant 1 (sembrado por migración)
        $platformAdmin = User::create([
            'tenant_id' => null,
            'name' => 'Plataforma',
            'email' => 'plat@x.local',
            'password' => bcrypt('password'),
            'role' => 'platform_admin',
        ]);

        $response = $this->actingAs($platformAdmin)->postJson('/api/v1/clock/punch', [
            'user_id' => $target->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $target->id]);
    }

    public function test_punch_allows_self_punch(): void
    {
        // Caso más común: el colaborador ficha por sí mismo (mismo tenant).
        $tenant = $this->makeTenant('tenant-self');
        $user = $this->makeUser($tenant->id, 'self@d.local');
        Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Auto D',
            'base_salary' => 3000.00,
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        $this->enableSimulatedTime($tenant->id);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $user->id,
            'type' => 'check_in',
        ]);
    }
}
