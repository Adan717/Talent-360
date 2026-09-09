<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\EstadoDeCobranza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Plan C3 — la firma del webhook de Stripe.
 *
 * `POST /api/webhooks/stripe` es una ruta PÚBLICA (routes/api.php): sin sesión, sin token y sin
 * throttle. Hasta el 2026-09-08 el controlador se saltaba la verificación de firma "si no hay
 * secreto o no hay cabecera", y en la V2 ése era el caso NORMAL: el secreto se leía con
 * `env('STRIPE_WEBHOOK_SECRET')` fuera de config/, y además nadie lo había puesto en el servidor.
 * Con eso, un POST de cualquiera con `{"type":"invoice.payment_succeeded"}` dejaba a una empresa
 * en `active` con un mes más de servicio y disparaba un timbrado CFDI real contra el PAC.
 *
 * Estas pruebas fijan que el fallo sea CERRADO. No comprueban Stripe: comprueban que sin firma
 * válida NADIE mueve el dinero.
 */
class WebhookDeStripeFirmadoTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1/webhooks/stripe';
    private const SECRETO = 'whsec_secreto_de_prueba';

    protected function setUp(): void
    {
        parent::setUp();
        // Ningún timbrado real: si el evento llega a procesarse, el PAC es un doble.
        Http::fake(['*' => Http::response(['id' => 'inv_prueba', 'uuid' => 'UUID-PRUEBA', 'status' => 'valid'], 200)]);
    }

    /**
     * El entorno se cambia DESPUÉS de que RefreshDatabase migró: con `production` puesto en
     * setUp(), las migraciones de la suite pedirían confirmación.
     */
    private function enProduccion(): void
    {
        $this->app['env'] = 'production';
    }

    private function empresa(array $atributos = []): Tenant
    {
        $sufijo = str_replace('.', '', uniqid('', true));

        return Tenant::create([
            'name' => 'Empresa ' . $sufijo,
            'subdomain' => 'e' . $sufijo,
            'plan' => 'enterprise',
            'subscription_status' => $atributos['subscription_status'] ?? EstadoDeCobranza::PRUEBA,
            'stripe_customer_id' => $atributos['stripe_customer_id'] ?? ('cus_' . $sufijo),
        ])->fresh();
    }

    private function eventoDePagoDe(Tenant $empresa, ?int $finDePeriodo = null): array
    {
        $factura = [
            'customer' => $empresa->stripe_customer_id,
            'subscription' => 'sub_prueba',
            'amount_paid' => 199900,
            'currency' => 'mxn',
        ];

        if ($finDePeriodo !== null) {
            $factura['period_end'] = $finDePeriodo;
        }

        return [
            'id' => 'evt_prueba',
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => $factura],
        ];
    }

    /**
     * @param  string|null  $secretoParaFirmar  null = petición SIN cabecera Stripe-Signature.
     */
    private function enviar(array $evento, ?string $secretoParaFirmar = null)
    {
        $payload = json_encode($evento);
        $cabeceras = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($secretoParaFirmar !== null) {
            $instante = time();
            $cabeceras['HTTP_STRIPE_SIGNATURE'] = "t={$instante},v1="
                . hash_hmac('sha256', "{$instante}.{$payload}", $secretoParaFirmar);
        }

        return $this->call('POST', self::RUTA, [], [], [], $cabeceras, $payload);
    }

    private function assertIntacta(Tenant $empresa): void
    {
        $empresa->refresh();
        $this->assertSame(EstadoDeCobranza::PRUEBA, $empresa->subscription_status,
            'Un webhook rechazado no puede cambiar el estado de cobranza.');
        $this->assertNull($empresa->current_period_end,
            'Un webhook rechazado no puede regalar un mes de servicio.');
    }

    public function test_en_produccion_un_pago_falso_sin_firma_no_activa_a_nadie(): void
    {
        config(['cashier.webhook.secret' => self::SECRETO]);
        $this->enProduccion();
        $empresa = $this->empresa();

        $respuesta = $this->enviar($this->eventoDePagoDe($empresa));

        $respuesta->assertStatus(400);
        $this->assertIntacta($empresa);
    }

    public function test_en_produccion_una_firma_de_otro_secreto_se_rechaza(): void
    {
        config(['cashier.webhook.secret' => self::SECRETO]);
        $this->enProduccion();
        $empresa = $this->empresa();

        $respuesta = $this->enviar($this->eventoDePagoDe($empresa), 'whsec_otro_secreto');

        $respuesta->assertStatus(400);
        $this->assertIntacta($empresa);
    }

    /**
     * El caso que de verdad pasaba en la V2: nadie había puesto STRIPE_WEBHOOK_SECRET. Antes eso
     * abría la puerta en silencio ("sandbox/local mode"); ahora en producción cierra.
     */
    public function test_en_produccion_sin_secreto_configurado_el_webhook_se_rechaza(): void
    {
        config(['cashier.webhook.secret' => null]);
        $this->enProduccion();
        $empresa = $this->empresa();

        $respuesta = $this->enviar($this->eventoDePagoDe($empresa));

        $respuesta->assertStatus(400);
        $this->assertIntacta($empresa);
    }

    /** Con secreto configurado la firma es obligatoria SIEMPRE, no sólo en producción. */
    public function test_con_secreto_configurado_tampoco_pasa_sin_firma_fuera_de_produccion(): void
    {
        config(['cashier.webhook.secret' => self::SECRETO]);
        $empresa = $this->empresa();

        $respuesta = $this->enviar($this->eventoDePagoDe($empresa));

        $respuesta->assertStatus(400);
        $this->assertIntacta($empresa);
    }

    /**
     * Además de pasar, el pago tiene que MOVER LA FECHA DE CORTE a la que manda Stripe. Hasta el
     * 2026-09-08 no lo hacía: el controlador la escribía con `update([...])` y
     * `current_period_end` no está en $fillable, así que se caía en silencio. Una empresa pagada
     * quedaba 'active' sin fecha de corte — y sin fecha de corte el barrido de mora no la mira.
     */
    public function test_una_firma_valida_procesa_el_pago_y_mueve_la_fecha_de_corte(): void
    {
        config(['cashier.webhook.secret' => self::SECRETO]);
        $this->enProduccion();
        $empresa = $this->empresa();
        $finDePeriodo = now()->addDays(30)->startOfHour();

        $respuesta = $this->enviar(
            $this->eventoDePagoDe($empresa, $finDePeriodo->timestamp),
            self::SECRETO
        );

        $respuesta->assertStatus(200);
        $empresa->refresh();
        $this->assertSame(EstadoDeCobranza::ACTIVA, $empresa->subscription_status);
        $this->assertSame('sub_prueba', $empresa->stripe_subscription_id);
        $this->assertNotNull($empresa->current_period_end, 'El pago debe dejar fecha de corte.');
        $this->assertSame(
            $finDePeriodo->timestamp,
            \Carbon\Carbon::parse($empresa->current_period_end)->timestamp,
            'La fecha de corte es la que manda Stripe, no una inventada.'
        );
    }

    /** Un cobro de Stripe en modo prueba mueve la cobranza, pero jamás toca el PAC fiscal real. */
    public function test_un_pago_sandbox_no_intenta_timbrar_en_el_pac(): void
    {
        config(['cashier.webhook.secret' => self::SECRETO]);
        $this->enProduccion();
        $empresa = $this->empresa();
        $evento = $this->eventoDePagoDe($empresa, now()->addMonth()->timestamp);
        $evento['data']['object']['livemode'] = false;

        $this->enviar($evento, self::SECRETO)->assertStatus(200);

        $this->assertSame(EstadoDeCobranza::ACTIVA, $empresa->refresh()->subscription_status);
        $this->assertNotNull($empresa->current_period_end);
        Http::assertNothingSent();
    }

    /** Un evento sin `period_end` (p. ej. un cargo suelto) cae al mes calendario, no a nada. */
    public function test_sin_period_end_la_fecha_de_corte_cae_al_mes(): void
    {
        config(['cashier.webhook.secret' => self::SECRETO]);
        $this->enProduccion();
        $empresa = $this->empresa();

        $this->enviar($this->eventoDePagoDe($empresa), self::SECRETO)->assertStatus(200);

        $corte = \Carbon\Carbon::parse($empresa->refresh()->current_period_end);
        $this->assertSame(now()->addMonth()->toDateString(), $corte->toDateString());
    }

    /**
     * El simulador de checkout local (SubscriptionController::simulatedConfirm y las pruebas
     * manuales) sigue funcionando: fuera de producción y sin secreto, se acepta sin firma.
     */
    public function test_fuera_de_produccion_y_sin_secreto_el_simulador_sigue_pasando(): void
    {
        config(['cashier.webhook.secret' => null]);
        $empresa = $this->empresa();

        $respuesta = $this->enviar($this->eventoDePagoDe($empresa));

        $respuesta->assertStatus(200);
        $this->assertSame(EstadoDeCobranza::ACTIVA, $empresa->refresh()->subscription_status);
    }
}
