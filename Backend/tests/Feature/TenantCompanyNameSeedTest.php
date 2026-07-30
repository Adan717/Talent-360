<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H12 (prueba en vivo 2026-07-29): al registrar "Panaderia La Espiga QA", la pantalla de
 * bienvenida saludaba con **"Bienvenido a DecorArte 360"** — el nombre de OTRA empresa.
 *
 * Causa: el alta no sembraba `company_name` en `system_settings` (quedaba NULL) y el
 * frontend caía a un default hardcodeado con el nombre de la empresa original del producto.
 * Un cliente nuevo veía la marca de otro en su primera pantalla.
 *
 * Regla: toda empresa nueva nace con su `company_name` sembrado a partir del nombre del
 * tenant, junto al resto de la configuración por defecto.
 */
class TenantCompanyNameSeedTest extends TestCase
{
    use RefreshDatabase;

    private function crearTenant(string $nombre, string $sub): Tenant
    {
        return Tenant::create([
            'name' => $nombre,
            'subdomain' => $sub,
            'public_slug' => $sub,
            'plan' => 'freemium',
            'max_users' => 10,
            'is_active' => true,
        ]);
    }

    private function companyName(int $tenantId)
    {
        $raw = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', 'company_name')
            ->value('value');

        return $raw === null ? null : json_decode($raw, true);
    }

    public function test_la_empresa_nueva_nace_con_su_propio_nombre(): void
    {
        $tenant = $this->crearTenant('Panaderia La Espiga QA', 'espiga');

        $this->assertSame('Panaderia La Espiga QA', $this->companyName($tenant->id));
    }

    public function test_el_estado_devuelve_el_nombre_propio_y_no_el_de_otra_empresa(): void
    {
        $otra = $this->crearTenant('DecorArte 360', 'decorarte');
        $mia = $this->crearTenant('Tortilleria El Comal', 'comal');

        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $mia->id]);

        $res = $this->actingAs($user->fresh())->getJson('/api/v1/sync/state');

        $res->assertStatus(200);
        $this->assertSame('Tortilleria El Comal', $res->json('system_settings.company_name'));
        $this->assertNotSame('DecorArte 360', $res->json('system_settings.company_name'));
    }

    public function test_no_pisa_el_nombre_que_la_empresa_ya_personalizo(): void
    {
        $tenant = $this->crearTenant('Nombre Inicial', 'inicial');

        // La empresa lo cambia desde Configuración.
        DB::table('system_settings')
            ->where('tenant_id', $tenant->id)
            ->where('key', 'company_name')
            ->update(['value' => json_encode('Mi Marca Comercial')]);

        // Una re-inicialización (p. ej. al reparar settings) no debe revertirlo.
        app(\App\Services\TenantInitializationService::class)->initializeSettingsForTenant($tenant->id);

        $this->assertSame('Mi Marca Comercial', $this->companyName($tenant->id));
    }
}
