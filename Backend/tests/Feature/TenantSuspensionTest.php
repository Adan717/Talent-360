<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantSuspensionTest extends TestCase
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
    }

    public function test_suspended_tenant_blocks_routes(): void
    {
        // 1. Create a suspended tenant with explicit ID = 2 (bypassing fillable)
        $tenant = new Tenant();
        $tenant->id = 2;
        $tenant->name = 'Empresa Suspendida';
        $tenant->subdomain = 'susp';
        $tenant->plan = 'pro';
        $tenant->max_users = 10;
        $tenant->is_active = false;
        $tenant->save();

        // 2. Create a user under that suspended tenant
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
        ]);

        // 3. Try to access an admin endpoint
        $response = $this->actingAs($user)->getJson('/api/v1/employees');

        // 4. Assert response is 403 (suspended)
        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'Empresa suspendida'
        ]);
    }

    public function test_login_fails_for_suspended_tenant(): void
    {
        // 1. Create suspended tenant with explicit ID = 3
        $tenant = new Tenant();
        $tenant->id = 3;
        $tenant->name = 'Suspended Shop';
        $tenant->subdomain = 'suspshop';
        $tenant->plan = 'pro';
        $tenant->is_active = false;
        $tenant->save();

        // 2. Create user with known password
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'admin@suspshop.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin',
        ]);

        // 3. Attempt login
        $response = $this->postJson('/api/v1/login', [
            'email' => 'admin@suspshop.com',
            'password' => 'secret123'
        ]);

        // 4. Assert login is blocked with 403
        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'Empresa suspendida'
        ]);
    }

    public function test_platform_admin_can_toggle_tenant_status(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);

        // Create tenant with explicit ID = 4
        $tenant = new Tenant();
        $tenant->id = 4;
        $tenant->name = 'Test Corp';
        $tenant->subdomain = 'testcorp';
        $tenant->plan = 'pro';
        $tenant->is_active = true;
        $tenant->save();

        // 1. Suspend tenant
        $response = $this->actingAs($platformAdmin)->postJson("/api/v1/platform/tenants/{$tenant->id}/toggle-status", [
            'is_active' => false,
            'suspension_reason' => 'Falta de pago'
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'is_active' => false,
            'suspension_reason' => 'Falta de pago',
        ]);

        // 2. Activate tenant
        $response = $this->actingAs($platformAdmin)->postJson("/api/v1/platform/tenants/{$tenant->id}/toggle-status", [
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'is_active' => true,
            'suspension_reason' => null,
        ]);
    }

    public function test_platform_admin_can_reset_tenant_admin_password(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);

        // Create tenant with explicit ID = 5
        $tenant = new Tenant();
        $tenant->id = 5;
        $tenant->name = 'Client Corp';
        $tenant->subdomain = 'client';
        $tenant->plan = 'pro';
        $tenant->is_active = true;
        $tenant->save();

        $adminUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'email' => 'ceo@client.com',
            'password' => Hash::make('oldpassword'),
        ]);

        $response = $this->actingAs($platformAdmin)->postJson("/api/v1/platform/tenants/{$tenant->id}/reset-password", [
            'password' => 'newpassword123'
        ]);

        $response->assertStatus(200);
        
        // Assert password has been updated
        $adminUser->refresh();
        $this->assertTrue(Hash::check('newpassword123', $adminUser->password));
    }

    public function test_platform_admin_can_impersonate_tenant(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);

        // Create tenant with explicit ID = 6
        $tenant = new Tenant();
        $tenant->id = 6;
        $tenant->name = 'Impersonate Corp';
        $tenant->subdomain = 'imp';
        $tenant->plan = 'pro';
        $tenant->is_active = true;
        $tenant->save();

        $adminUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'email' => 'admin@imp.com',
        ]);

        $response = $this->actingAs($platformAdmin)->postJson("/api/v1/platform/tenants/{$tenant->id}/impersonate");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token', 'user', 'tenant'
        ]);
    }

    public function test_platform_admin_can_fetch_module_audits(): void
    {
        $platformAdmin = User::factory()->create([
            'role' => 'platform_admin',
        ]);

        $response = $this->actingAs($platformAdmin)->getJson("/api/v1/platform/audits");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'id', 'name', 'score', 'description', 'details' => [
                    'coverage', 'performance', 'security', 'status', 'meta'
                ]
            ]
        ]);
    }
}
