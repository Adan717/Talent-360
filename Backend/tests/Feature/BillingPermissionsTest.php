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
     * El ambiente fiscal se DEDUCE de la llave del servidor; no se elige por empresa.
     *
     * La pantalla de Configuración traía un selector "Pruebas SAT / Producción Fiscal" que se
     * guardaba por empresa y no gobernaba nada. Que la pantalla dijera "Producción" mientras el
     * servidor timbra contra el sandbox es la peor forma de equivocarse en algo fiscal, así que
     * ahora la pantalla lee este endpoint. Lo que se prueba aquí es la regla, y que la llave
     * NUNCA salga en la respuesta.
     */
    public function test_el_estado_del_timbrado_sale_de_la_llave_del_servidor(): void
    {
        $admin = $this->makeUser('admin');
        $relleno = \App\Services\Billing\FacturapiBillingProvider::LLAVE_DE_RELLENO;

        $casos = [
            // llave del servidor        => [configurado, ambiente]
            ''                           => [false, null],
            $relleno                     => [false, null],
            'sk_test_abc123'             => [true, 'pruebas'],
            'sk_live_abc123'             => [true, 'produccion'],
        ];

        foreach ($casos as $llave => [$configurado, $ambiente]) {
            config(['services.facturapi.key' => $llave]);
            // El proveedor lee la llave en su constructor: hay que rearmarlo en cada caso.
            $this->app->forgetInstance(\App\Services\Billing\BillingProviderInterface::class);
            $this->app->forgetInstance(\App\Services\Billing\FacturapiBillingProvider::class);

            $res = $this->actingAs($admin)->getJson('/api/v1/billing/estado-timbrado')->assertOk();

            $res->assertJson(['configurado' => $configurado, 'ambiente' => $ambiente]);
            // Nada de timbrado automático: no existe: `timbrarNomina` sólo se invoca a mano.
            $res->assertJson(['timbrado_automatico' => false]);
            $this->assertStringNotContainsString(
                $llave !== '' ? $llave : 'IMPOSIBLE',
                $res->getContent(),
                'la llave del PAC no puede viajar al navegador'
            );
        }
    }

    /** Es dato fiscal: el mismo candado que el resto de billing. */
    public function test_el_estado_del_timbrado_es_solo_de_admin(): void
    {
        $this->actingAs($this->makeUser('supervisor'))
            ->getJson('/api/v1/billing/estado-timbrado')
            ->assertStatus(403);
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
