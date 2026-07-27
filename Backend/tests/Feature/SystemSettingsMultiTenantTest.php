<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemSettingsMultiTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * BUGFIX: antes system_settings.key era PK global, así que dos empresas no podían
     * tener la misma llave de settings — al crear la segunda, la inicialización chocaba
     * y (por el error tragado en el hook) abortaba la transacción de registro (25P02).
     */
    public function test_two_tenants_each_get_their_own_default_settings(): void
    {
        $a = Tenant::create(['name' => 'Empresa A', 'subdomain' => 'empresa-a', 'plan' => 'freemium', 'public_slug' => 'empresa-a']);
        $b = Tenant::create(['name' => 'Empresa B', 'subdomain' => 'empresa-b', 'plan' => 'freemium', 'public_slug' => 'empresa-b']);

        // Ambas empresas tienen su propia fila de 'storeSchedule' (misma llave, distinto tenant).
        $this->assertDatabaseHas('system_settings', ['tenant_id' => $a->id, 'key' => 'storeSchedule']);
        $this->assertDatabaseHas('system_settings', ['tenant_id' => $b->id, 'key' => 'storeSchedule']);
        $this->assertDatabaseHas('system_settings', ['tenant_id' => $a->id, 'key' => 'leySillaConfig']);
        $this->assertDatabaseHas('system_settings', ['tenant_id' => $b->id, 'key' => 'leySillaConfig']);
    }

    public function test_registering_a_new_company_succeeds_even_when_another_tenant_has_settings(): void
    {
        // Empresa existente CON settings inicializados (dispara el hook).
        Tenant::create(['name' => 'Existente', 'subdomain' => 'existente', 'plan' => 'freemium', 'public_slug' => 'existente']);

        // Registro de una empresa NUEVA por el flujo público sin sesión (el que fallaba).
        $response = $this->postJson('/api/v1/subscriptions/create-preference', [
            'subdomain' => 'nueva-empresa',
            'plan' => 'freemium',
            'company_name' => 'Nueva Empresa',
            'admin_name' => 'Dueño Nuevo',
            'admin_email' => 'dueno-nuevo@x.com',
            'admin_password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'dueno-nuevo@x.com']);
        $newTenantId = DB::table('users')->where('email', 'dueno-nuevo@x.com')->value('tenant_id');
        $this->assertNotNull($newTenantId);
        // La empresa nueva quedó con sus settings propios (la inicialización funcionó).
        $this->assertDatabaseHas('system_settings', ['tenant_id' => $newTenantId, 'key' => 'storeSchedule']);
    }
}
