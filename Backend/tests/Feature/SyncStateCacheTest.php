<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\User;
use App\Support\TenantConfigCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncStateCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'T', 'subdomain' => 't1', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();
        DB::table('employees')->insert([
            'tenant_id' => 1, 'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    public function test_sync_state_caches_static_config_and_editing_a_job_role_invalidates_it(): void
    {
        $admin = $this->makeAdmin();

        // Primera llamada: cachea la config (sin puestos aún).
        $first = $this->actingAs($admin)->getJson('/api/v1/sync/state');
        $first->assertStatus(200);
        $this->assertNotContains('Puesto Nuevo', collect($first->json('job_roles'))->pluck('name')->all());

        // El caché quedó poblado.
        $this->assertTrue(Cache::has(TenantConfigCache::key(1)));

        // Crear un puesto vía Eloquent dispara el observer → invalida el caché del tenant.
        JobRole::create(['name' => 'Puesto Nuevo', 'tenant_id' => 1, 'area' => 'Ops']);
        $this->assertFalse(Cache::has(TenantConfigCache::key(1)), 'El observer §46 debió invalidar el caché al crear el puesto.');

        // Segunda llamada: el puesto nuevo aparece (no quedó servido del caché viejo).
        $second = $this->actingAs($admin)->getJson('/api/v1/sync/state');
        $second->assertStatus(200);
        $this->assertContains('Puesto Nuevo', collect($second->json('job_roles'))->pluck('name')->all());
    }
}
