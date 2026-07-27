<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingPermissionsTest extends TestCase
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
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    /**
     * §64: un supervisor NO puede tocar el CSD del SAT ni el timbrado de nómina.
     */
    public function test_supervisor_is_denied_billing_routes(): void
    {
        $supervisor = $this->makeUser('supervisor');

        $this->actingAs($supervisor)->postJson('/api/v1/billing/csd', [])
            ->assertStatus(403);
        $this->actingAs($supervisor)->postJson('/api/v1/billing/tax-data', [])
            ->assertStatus(403);
        $this->actingAs($supervisor)->postJson('/api/v1/billing/payroll/timbrar', [])
            ->assertStatus(403);
        $this->actingAs($supervisor)->getJson('/api/v1/billing/invoices')
            ->assertStatus(403);
    }

    /**
     * §64: un admin SÍ pasa el filtro de rol (no recibe 403). Que luego falle por
     * validación/configuración de facturación es otra capa; aquí solo se comprueba
     * que la barrera de permisos lo deja entrar.
     */
    public function test_admin_passes_the_role_gate(): void
    {
        $admin = $this->makeUser('admin');

        $status = $this->actingAs($admin)->postJson('/api/v1/billing/csd', [])->status();

        $this->assertNotEquals(403, $status);
    }
}
