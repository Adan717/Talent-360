<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrphanEmailRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Empresa Principal',
            'subdomain' => 'talent360',
            'public_slug' => 'talent360',
            'plan' => 'enterprise',
            'max_users' => 9999,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_tenant_soft_deletes_its_users(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
            'email' => 'sysadmin@talent360.com'
        ]);

        $tenant = Tenant::create([
            'name' => 'Empresa Obsolescente',
            'subdomain' => 'obsolescente',
            'public_slug' => 'obsolescente',
            'plan' => 'freemium',
            'max_users' => 5,
            'subscription_status' => 'trial',
        ]);

        $user = User::factory()->create([
            'email' => 'huerfano@test.com',
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($platformAdmin)->deleteJson("/api/v1/platform/tenants/{$tenant->id}");
        $response->assertStatus(200);

        $this->assertSoftDeleted('tenants', ['id' => $tenant->id]);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_orphan_user_can_re_register_new_company(): void
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Eliminada',
            'subdomain' => 'eliminada',
            'public_slug' => 'eliminada',
            'plan' => 'freemium',
            'max_users' => 5,
        ]);

        $user = User::factory()->create([
            'name' => 'Usuario Huérfano',
            'email' => 'liberado@test.com',
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenant->id]);

        // Soft delete the tenant and user
        $tenant->delete();
        $user->delete();

        // Now attempt registration with the same email
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Usuario Reorganizado',
            'email' => 'liberado@test.com',
            'password' => 'newpassword123',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'liberado@test.com',
            'tenant_id' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_deleted_tenant_subdomain_is_freed_for_re_registration(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
            'email' => 'admin_subdomain@talent360.com'
        ]);

        $tenant = Tenant::create([
            'name' => 'Dashcomputer',
            'subdomain' => 'dashcomputer',
            'public_slug' => 'dashcomputer',
            'plan' => 'freemium',
            'max_users' => 5,
        ]);

        $response = $this->actingAs($platformAdmin)->deleteJson("/api/v1/platform/tenants/{$tenant->id}");
        $response->assertStatus(200);

        $softDeletedTenant = Tenant::withTrashed()->find($tenant->id);
        $this->assertStringContainsString('dashcomputer_deleted_', $softDeletedTenant->subdomain);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Gael',
            'email' => 'gael_new@dashcomputer.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role' => 'admin',
            'tenant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::find($userId);

        $registerResponse = $this->actingAs($user, 'sanctum')->postJson('/api/v1/subscriptions/create-preference', [
            'subdomain' => 'dashcomputer',
            'plan' => 'freemium',
            'company_name' => 'Dashcomputer Nueva',
        ]);

        $registerResponse->assertStatus(200);
        $registerResponse->assertJsonPath('provisioned', true);

        $this->assertDatabaseHas('tenants', [
            'subdomain' => 'dashcomputer',
            'name' => 'Dashcomputer Nueva',
            'deleted_at' => null,
        ]);
    }
}
