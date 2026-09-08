<?php

namespace Tests\Feature;

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `stripe:preparar` — los tres pasos manuales del Plan C, hechos por el comando (2026-09-08).
 *
 * Lo que se protege aquí no es la salida bonita del comando, sino las tres formas conocidas de
 * dejar el cobro roto en silencio:
 *
 *  1. **El webhook dado de alta con menos eventos de los que el código atiende.** Marcar seis
 *     casillas a mano en un panel es exactamente donde se pierde una: el pago entra, el evento
 *     no llega y la empresa nunca nace. Por eso los eventos salen de una constante y no de la
 *     memoria de quien configura.
 *  2. **La URL sin `/v1`.** Stripe acepta el alta y manda los avisos a una ruta que no existe.
 *  3. **Darlo por listo sin la firma.** Sin `STRIPE_WEBHOOK_SECRET` el webhook responde 400 a
 *     todo (falla cerrado, a propósito), así que "configurado" sin firma es no configurado.
 */
class PrepararStripeTest extends TestCase
{
    private const LLAVE = 'sk_test_llave_de_prueba';
    private const URL = 'https://talent360.com.mx/api/v1/webhooks/stripe';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cashier.secret' => self::LLAVE,
            'cashier.webhook.secret' => 'whsec_secreto_de_prueba',
            'app.url' => 'https://talent360.com.mx',
        ]);
    }

    /**
     * Stripe, fingido por closure y no por patrones de URL: `webhook_endpoints` se pide con
     * `?limit=100` y con el id pegado detrás, y un patrón sin comodín no casa con ninguno de los
     * dos — la petición se escaparía a la API de verdad. `preventStrayRequests` hace que eso
     * truene aquí en vez de salir a internet desde una prueba.
     */
    private function fingirStripe(array $webhooks = []): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($webhooks) {
            $url = $request->url();

            if (str_contains($url, '/v1/account')) {
                return Http::response([
                    'id' => 'acct_123',
                    'settings' => ['dashboard' => ['display_name' => 'Talent 360 SA']],
                ], 200);
            }

            if (str_contains($url, '/v1/checkout/sessions')) {
                return Http::response([
                    'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
                ], 200);
            }

            if (str_contains($url, '/v1/webhook_endpoints')) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => $webhooks], 200)
                    : Http::response(['id' => 'we_1', 'secret' => 'whsec_recien_creado'], 200);
            }

            return Http::response([], 200);
        });
    }

    /** Sin llave no se finge nada: se dice qué falta y dónde ponerlo. */
    public function test_sin_llave_explica_que_poner_en_el_env(): void
    {
        config(['cashier.secret' => 'YOUR_STRIPE_SECRET_KEY']);

        $this->artisan('stripe:preparar')
            ->expectsOutputToContain('No hay llave de Stripe')
            ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET')
            ->assertExitCode(1);
    }

    /**
     * EL CANDADO PRINCIPAL: el endpoint se da de alta con EXACTAMENTE los eventos que el
     * webhook atiende. Si alguien agrega un `case` y olvida la constante, esta prueba y la de
     * abajo lo cazan antes de que un cliente pague y su empresa no nazca.
     */
    public function test_da_de_alta_el_webhook_con_todos_los_eventos_que_el_codigo_atiende(): void
    {
        $this->fingirStripe();

        $this->artisan('stripe:preparar --force')
            ->expectsOutputToContain('Webhook dado de alta')
            ->expectsOutputToContain('whsec_recien_creado')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/webhook_endpoints') || $request->method() !== 'POST') {
                return false;
            }

            return $request['url'] === self::URL
                && array_diff(StripeWebhookController::EVENTOS_QUE_ATIENDE, (array) $request['enabled_events']) === [];
        });
    }

    /** Si el endpoint ya existe pero le faltan eventos, se los agrega en vez de duplicarlo. */
    public function test_completa_los_eventos_de_un_webhook_que_ya_existia(): void
    {
        $this->fingirStripe([
            ['id' => 'we_viejo', 'url' => self::URL, 'enabled_events' => ['checkout.session.completed'], 'status' => 'enabled'],
        ]);

        $this->artisan('stripe:preparar --force')
            ->expectsOutputToContain('le faltan eventos')
            ->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/webhook_endpoints/we_viejo') && $r->method() === 'POST');
    }

    /** Y si ya está completo, no toca nada: configurar dos veces no puede romper lo que ya sirve. */
    public function test_no_toca_un_webhook_que_ya_esta_completo(): void
    {
        $this->fingirStripe([
            ['id' => 'we_ok', 'url' => self::URL, 'enabled_events' => StripeWebhookController::EVENTOS_QUE_ATIENDE, 'status' => 'enabled'],
        ]);

        $this->artisan('stripe:preparar --force')
            ->expectsOutputToContain('ya estaba dado de alta')
            ->assertExitCode(0);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/webhook_endpoints') && $r->method() === 'POST');
    }

    /** La compra de prueba que el plan pedía hacer a mano, con la tarjeta que aprueba a la vista. */
    public function test_crea_el_cobro_de_prueba_y_dice_con_que_tarjeta_pagarlo(): void
    {
        $this->fingirStripe([
            ['id' => 'we_ok', 'url' => self::URL, 'enabled_events' => StripeWebhookController::EVENTOS_QUE_ATIENDE, 'status' => 'enabled'],
        ]);

        $this->artisan('stripe:preparar --force')
            ->expectsOutputToContain('https://checkout.stripe.com/c/pay/cs_test_1')
            ->expectsOutputToContain('4242 4242 4242 4242')
            ->assertExitCode(0);
    }

    /** Sin la firma del webhook NO se declara listo: sin ella el webhook rechaza todo pago. */
    public function test_sin_la_firma_del_webhook_no_se_da_por_listo(): void
    {
        config(['cashier.webhook.secret' => '']);
        $this->fingirStripe([
            ['id' => 'we_ok', 'url' => self::URL, 'enabled_events' => StripeWebhookController::EVENTOS_QUE_ATIENDE, 'status' => 'enabled'],
        ]);

        $this->artisan('stripe:preparar --force --sin-prueba')
            ->expectsOutputToContain('FALTA (STRIPE_WEBHOOK_SECRET)')
            ->assertExitCode(1);
    }

    /** Un webhook en http nunca recibiría nada: se para antes de tocar la cuenta. */
    public function test_rechaza_una_url_que_no_sea_https(): void
    {
        $this->fingirStripe();

        $this->artisan('stripe:preparar --force --url=http://talent360.com.mx/api/v1/webhooks/stripe')
            ->expectsOutputToContain('tiene que ser https')
            ->assertExitCode(1);
    }

    /** Y avisa del error clásico: la ruta lleva `/v1` en medio. */
    public function test_avisa_si_la_url_no_lleva_la_ruta_del_webhook(): void
    {
        $this->fingirStripe();

        $this->artisan('stripe:preparar --force --url=https://talent360.com.mx/api/webhooks/stripe')
            ->expectsOutputToContain('Ojo con el /v1');
    }

    /**
     * La constante y el `switch` no pueden divergir: si se atiende un evento que no se da de
     * alta, Stripe no lo manda; si se da de alta uno que no se atiende, se registra ruido.
     */
    public function test_la_lista_de_eventos_coincide_con_los_case_del_controlador(): void
    {
        $codigo = file_get_contents(app_path('Http/Controllers/StripeWebhookController.php'));
        preg_match_all("/case '([a-z_.]+)':/", $codigo, $coincidencias);

        $this->assertEqualsCanonicalizing(
            StripeWebhookController::EVENTOS_QUE_ATIENDE,
            array_values(array_unique($coincidencias[1])),
            'EVENTOS_QUE_ATIENDE y el switch de handleWebhook dejaron de coincidir: el panel de Stripe se quedaria sin mandar un evento (o mandando uno que nadie atiende)'
        );
    }
}
