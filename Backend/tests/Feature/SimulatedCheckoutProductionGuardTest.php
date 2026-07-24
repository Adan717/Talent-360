<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Seguridad (production-readiness): el CHECKOUT SIMULADO no puede correr en producción.
 *
 * Bug: `createPreference` cae a un checkout SIMULADO cuando el proveedor de pago real (MercadoPago)
 * no está configurado, y `simulated-confirm` (ruta PÚBLICA, api.php:48) llama a `provisionTenant` y
 * devuelve un token de admin SIN verificar pago y SIN gate de entorno. En producción eso es un
 * aprovisionamiento GRATIS de un tenant Pro/Enterprise: cualquiera crea preferencia → confirma
 * simulado → empresa de pago gratis + sesión de admin.
 *
 * Fix: el simulador SÓLO existe en `local`/`testing`. En cualquier otro entorno (producción,
 * staging, o `APP_ENV` sin definir → `config/app.php` defaultea 'production') las rutas del simulador
 * responden 404 y `createPreference` NO ofrece la URL simulada para un plan de pago. Los caminos de
 * pago REAL (MercadoPago SDK, webhook de Stripe) no se tocan.
 */
class SimulatedCheckoutProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function pending(string $plan): PendingRegistration
    {
        // La PK es `id` (UUID string); el `pref_id` de la URL es ese `id`. No hay columna `pref_id`.
        return PendingRegistration::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => json_encode([
                'plan' => $plan,
                'company_name' => 'Empresa X',
                'admin_email' => 'admin' . uniqid() . '@x.local',
                'admin_password' => 'secret123',
                'employees' => 10,
            ]),
        ]);
    }

    public function test_simulated_confirm_es_404_en_produccion(): void
    {
        App::detectEnvironment(fn () => 'production');
        $reg = $this->pending('pro');

        $res = $this->get('/api/v1/subscriptions/simulated-confirm?pref_id=' . $reg->id);

        $res->assertStatus(404);
        // Lo esencial: NO se aprovisionó ningún tenant de pago gratis.
        $this->assertDatabaseHas('pending_registrations', ['id' => $reg->id]); // sigue pendiente, no consumido
    }

    public function test_simulated_checkout_es_404_en_produccion(): void
    {
        App::detectEnvironment(fn () => 'production');
        $reg = $this->pending('pro');

        $this->get('/api/v1/subscriptions/simulated-checkout?pref_id=' . $reg->id)
            ->assertStatus(404);
    }

    /** También en staging o cualquier entorno que no sea local/testing. */
    public function test_simulated_confirm_es_404_en_staging(): void
    {
        App::detectEnvironment(fn () => 'staging');
        $reg = $this->pending('enterprise');

        $this->get('/api/v1/subscriptions/simulated-confirm?pref_id=' . $reg->id)
            ->assertStatus(404);
    }

    /**
     * En producción, `createPreference` de un plan de PAGO sin proveedor real configurado NO debe
     * devolver una URL de checkout simulada (antes: `simulated=true`).
     */
    public function test_create_preference_no_ofrece_simulador_en_produccion(): void
    {
        App::detectEnvironment(fn () => 'production');

        $res = $this->postJson('/api/v1/subscriptions/create-preference', [
            'plan' => 'pro',
            'company_name' => 'Empresa Y',
            'admin_email' => 'y' . uniqid() . '@y.local',
            'admin_password' => 'secret123',
            'employees' => 10,
        ]);

        // No debe venir un checkout simulado; el plan de pago sin proveedor real no se puede cobrar.
        $this->assertNotTrue($res->json('simulated'), 'producción no debe ofrecer el checkout simulado');
    }

    /** En testing/local el simulador SÍ responde (no 404): el flujo de dev/QA se conserva. */
    public function test_el_simulador_sigue_disponible_en_testing(): void
    {
        // El entorno de tests es 'testing' por defecto.
        $reg = $this->pending('pro');

        $res = $this->get('/api/v1/subscriptions/simulated-checkout?pref_id=' . $reg->id);

        $res->assertStatus(200); // la página del simulador se sirve en dev/testing
    }
}
