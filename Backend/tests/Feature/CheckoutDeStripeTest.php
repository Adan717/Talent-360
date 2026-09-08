<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EstadoDeCobranza;
use App\Support\Tarifario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Plan C1/C2 — cobrar con tarjeta (Stripe) y que el cobro tenga consecuencias.
 *
 * Lo que estas pruebas fijan, en orden de importancia:
 *
 *  1. **La empresa que paga recibe FECHA DE CORTE.** Hasta el 2026-09-08 no la recibía nadie:
 *     el alta la escribía dentro de un `update([...])` y `current_period_end` no es $fillable, así
 *     que la asignación masiva la tiraba en silencio. Sin fecha de corte, `EstadoDeCobranza`
 *     responde SIN_FECHA_DE_CORTE y el barrido de mora no mira nunca a esa empresa: se podía dejar
 *     de pagar para siempre. Es el defecto que hacía inútil toda la cobranza.
 *  2. **Un pago fallido deja mora, no apagón.** Apagar es del barrido, que es el único que cuenta
 *     los días de gracia.
 *  3. **Nadie entra gratis por un error de la pasarela**: si Stripe falla, el alta NO se
 *     aprovisiona (antes se caía al simulador, que da la empresa por pagada).
 *  4. **Una llave de relleno no es una llave.** `YOUR_STRIPE_SECRET_KEY` no configura nada.
 *
 * Se habla con Stripe por `Http`, así que aquí se intercepta con `Http::fake()` y se comprueba el
 * cuerpo EXACTO que se le manda: que el importe sea el del tabulador y que el modo sea el pedido.
 */
class CheckoutDeStripeTest extends TestCase
{
    use RefreshDatabase;

    private const LLAVE = 'sk_test_llave_de_prueba';
    private const SECRETO_WEBHOOK = 'whsec_secreto_de_prueba';
    private const RUTA_WEBHOOK = '/api/v1/webhooks/stripe';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cashier.secret' => self::LLAVE, 'cashier.webhook.secret' => self::SECRETO_WEBHOOK]);
    }

    /** Stripe responde con una sesión de checkout; el PAC (Facturapi) también se finge. */
    private function fingirStripe(array $sesion = []): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response(array_merge([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ], $sesion), 200),
            '*' => Http::response(['id' => 'inv_1', 'uuid' => 'UUID-1', 'status' => 'valid'], 200),
        ]);
    }

    private function altaDe(array $datos = [])
    {
        $usuario = User::factory()->create(['tenant_id' => null, 'role' => 'admin']);

        return $this->withHeader('Authorization', 'Bearer ' . $usuario->createToken('t')->plainTextToken)
            ->postJson('/api/v1/subscriptions/create-preference', array_merge([
                'company_name' => 'Empresa de Prueba',
                'subdomain' => 'empresadeprueba',
                'plan' => 'pro',
                'employees' => 12,
                'billing_cycle' => 'monthly',
                'acepta_aviso' => true,
            ], $datos));
    }

    /** El cuerpo que se le mandó a Stripe, ya decodificado del form-urlencoded. */
    private function cuerpoEnviadoAStripe(): array
    {
        $cuerpo = [];

        Http::assertSent(function ($peticion) use (&$cuerpo) {
            if (str_contains($peticion->url(), 'checkout/sessions')) {
                parse_str($peticion->body(), $cuerpo);

                return true;
            }

            return false;
        });

        return $cuerpo;
    }

    // --- El cobro -------------------------------------------------------------------------------

    public function test_el_alta_manda_a_stripe_el_importe_del_tabulador_como_suscripcion(): void
    {
        $this->fingirStripe();

        $respuesta = $this->altaDe(['plan' => 'pro', 'employees' => 12, 'billing_cycle' => 'monthly']);

        $respuesta->assertStatus(200)
            ->assertJsonPath('pasarela', 'stripe')
            ->assertJsonPath('simulated', false)
            ->assertJsonPath('init_point', 'https://checkout.stripe.com/c/pay/cs_test_123');

        $cuerpo = $this->cuerpoEnviadoAStripe();
        $esperado = (int) round(Tarifario::totalACobrar('pro', 12, 'monthly') * 100);

        $this->assertSame('subscription', $cuerpo['mode'], 'Por defecto se contrata la recurrente.');
        $this->assertSame($esperado, (int) $cuerpo['line_items'][0]['price_data']['unit_amount'],
            'El importe que se cobra es el del tabulador único, no uno inventado aquí.');
        $this->assertSame('mxn', $cuerpo['line_items'][0]['price_data']['currency']);
        $this->assertSame('month', $cuerpo['line_items'][0]['price_data']['recurring']['interval']);
        $this->assertNotEmpty($cuerpo['client_reference_id'], 'Sin referencia, el webhook no sabría a quién aplicar el pago.');
    }

    public function test_el_ciclo_anual_se_cobra_como_suscripcion_anual(): void
    {
        $this->fingirStripe();

        $this->altaDe(['billing_cycle' => Tarifario::CICLO_ANUAL])->assertStatus(200);

        $cuerpo = $this->cuerpoEnviadoAStripe();
        $this->assertSame('year', $cuerpo['line_items'][0]['price_data']['recurring']['interval']);
        $this->assertSame(
            (int) round(Tarifario::totalACobrar('pro', 12, Tarifario::CICLO_ANUAL) * 100),
            (int) $cuerpo['line_items'][0]['price_data']['unit_amount']
        );
    }

    /** La otra modalidad: una liga que cobra UNA vez el periodo, para mandarla a mano. */
    public function test_la_modalidad_liga_es_un_cobro_unico_sin_recurrencia(): void
    {
        $this->fingirStripe();

        $this->altaDe(['modalidad' => 'liga'])->assertStatus(200);

        $cuerpo = $this->cuerpoEnviadoAStripe();
        $this->assertSame('payment', $cuerpo['mode']);
        $this->assertArrayNotHasKey('recurring', $cuerpo['line_items'][0]['price_data']);
    }

    /**
     * Si Stripe falla, el alta NO puede caer al simulador: eso aprovisiona la empresa dando el
     * pago por bueno. Se falla de frente y no nace nadie.
     */
    public function test_si_stripe_falla_no_se_aprovisiona_ninguna_empresa(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'tarjeta rechazada']], 402)]);

        $this->altaDe()->assertStatus(502);

        $this->assertNull(Tenant::where('subdomain', 'empresadeprueba')->first(),
            'Un fallo de la pasarela no puede crear la empresa.');
    }

    /**
     * Sin ninguna pasarela y sin simulador (que es lo que debe quedar en la V2 en cuanto Stripe
     * cobre), el alta lo dice de frente. Antes devolvía `status: success` con una liga a un
     * endpoint que responde 404: el alta parecía ir bien y moría en el clic siguiente.
     */
    public function test_sin_pasarela_configurada_el_alta_dice_que_no_puede_cobrar(): void
    {
        config(['cashier.secret' => null]);
        \Illuminate\Support\Facades\App::detectEnvironment(fn () => 'production');

        $this->altaDe()->assertStatus(503);

        $this->assertNull(Tenant::where('subdomain', 'empresadeprueba')->first());
    }

    /** `YOUR_STRIPE_SECRET_KEY` (el relleno del .env.example) no es una llave. */
    public function test_la_llave_de_relleno_no_cuenta_como_configurada(): void
    {
        config(['cashier.secret' => 'YOUR_STRIPE_SECRET_KEY']);

        $this->assertFalse(app(\App\Services\Billing\CobroConStripe::class)->configurado());
    }

    // --- El webhook: lo que pasa cuando el dinero entra ------------------------------------------

    private function enviarWebhook(array $evento)
    {
        $payload = json_encode($evento);
        $instante = time();

        return $this->call('POST', self::RUTA_WEBHOOK, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$instante},v1="
                . hash_hmac('sha256', "{$instante}." . $payload, self::SECRETO_WEBHOOK),
        ], $payload);
    }

    private function checkoutCompletado(string $referencia, array $extra = []): array
    {
        return [
            'type' => 'checkout.session.completed',
            'data' => ['object' => array_merge([
                'object' => 'checkout_session',
                'payment_status' => 'paid',
                'client_reference_id' => $referencia,
                'customer' => 'cus_prueba',
                'subscription' => 'sub_prueba',
                'metadata' => ['referencia' => $referencia, 'ciclo' => 'monthly'],
            ], $extra)],
        ];
    }

    /**
     * EL DEFECTO QUE MÁS PESA: la empresa que paga tiene que quedar con fecha de corte. Sin ella,
     * `EstadoDeCobranza::decidir()` devuelve SIN_FECHA_DE_CORTE y no se le puede cobrar la mora.
     */
    public function test_el_checkout_pagado_crea_la_empresa_con_su_fecha_de_corte(): void
    {
        $this->fingirStripe();
        $this->altaDe(['subdomain' => 'ferreteriaelsol', 'company_name' => 'Ferretería El Sol'])->assertStatus(200);

        $referencia = PendingRegistration::first()->id;

        $this->enviarWebhook($this->checkoutCompletado($referencia))->assertStatus(200);

        $empresa = Tenant::where('subdomain', 'ferreteriaelsol')->first();
        $this->assertNotNull($empresa, 'El webhook pagado es lo que da de alta la empresa.');
        $this->assertSame(EstadoDeCobranza::ACTIVA, $empresa->subscription_status);
        $this->assertNotNull($empresa->current_period_end, 'Sin fecha de corte la cobranza no existe.');
        $this->assertSame(
            Carbon::now()->addMonth()->toDateString(),
            Carbon::parse($empresa->current_period_end)->toDateString()
        );
        $this->assertSame('cus_prueba', $empresa->stripe_customer_id);
        $this->assertSame('sub_prueba', $empresa->stripe_subscription_id);

        $decision = EstadoDeCobranza::decidir($empresa, Carbon::now());
        $this->assertSame(EstadoDeCobranza::AL_CORRIENTE, $decision['accion']);
    }

    public function test_un_plan_anual_queda_cubierto_un_ano_y_no_un_mes(): void
    {
        $this->fingirStripe();
        $this->altaDe(['subdomain' => 'panaderialuz', 'billing_cycle' => Tarifario::CICLO_ANUAL])->assertStatus(200);

        $this->enviarWebhook($this->checkoutCompletado(PendingRegistration::first()->id))->assertStatus(200);

        $empresa = Tenant::where('subdomain', 'panaderialuz')->first();
        $this->assertSame(
            Carbon::now()->addYear()->toDateString(),
            Carbon::parse($empresa->current_period_end)->toDateString(),
            'Cobrar doce mensualidades y conceder una sola suspendería al cliente once meses antes.'
        );
    }

    /** Stripe reintenta hasta que le respondan 2xx: el mismo aviso no puede crear dos empresas. */
    public function test_el_mismo_webhook_repetido_no_crea_dos_empresas(): void
    {
        $this->fingirStripe();
        $this->altaDe(['subdomain' => 'tallerdona'])->assertStatus(200);
        $referencia = PendingRegistration::first()->id;

        $this->enviarWebhook($this->checkoutCompletado($referencia))->assertStatus(200);
        $this->enviarWebhook($this->checkoutCompletado($referencia))->assertStatus(200);

        $this->assertSame(1, Tenant::where('subdomain', 'tallerdona')->count());
    }

    /**
     * El caso feo: se cobró y el alta falla (aquí, porque el subdominio se ocupó entre el checkout
     * y el pago). No puede perderse el rastro del dinero: se responde 200 para que Stripe deje de
     * reintentar algo que va a fallar igual, el registro pendiente NO se borra y queda en la
     * bitácora para terminarlo a mano.
     */
    public function test_si_el_alta_falla_despues_de_cobrar_queda_registro_y_no_se_reintenta_en_bucle(): void
    {
        $this->fingirStripe();
        $this->altaDe(['subdomain' => 'ocupado'])->assertStatus(200);
        $referencia = PendingRegistration::first()->id;

        // Alguien ocupa el subdominio (con gente dentro, así que no se puede reutilizar).
        $ocupante = Tenant::create(['name' => 'Ya existía', 'subdomain' => 'ocupado', 'plan' => 'pro']);
        User::factory()->create(['tenant_id' => $ocupante->id, 'role' => 'admin']);

        $this->enviarWebhook($this->checkoutCompletado($referencia))->assertStatus(200);

        $this->assertDatabaseHas('pending_registrations', ['id' => $referencia]);
        $this->assertDatabaseHas('saas_audit_logs', ['event_type' => 'cobranza_alta_fallida_tras_pago']);
    }

    /** Una sesión completada pero sin pagar (métodos diferidos) no aprovisiona nada. */
    public function test_una_sesion_sin_pago_confirmado_no_aprovisiona(): void
    {
        $this->fingirStripe();
        $this->altaDe(['subdomain' => 'vidriosdelnorte'])->assertStatus(200);
        $referencia = PendingRegistration::first()->id;

        $this->enviarWebhook($this->checkoutCompletado($referencia, ['payment_status' => 'unpaid']))
            ->assertStatus(200);

        $this->assertNull(Tenant::where('subdomain', 'vidriosdelnorte')->first());
    }

    // --- El webhook: lo que pasa cuando el dinero NO entra ---------------------------------------

    private function empresaAlCorriente(): Tenant
    {
        $empresa = Tenant::create([
            'name' => 'Cliente al corriente',
            'subdomain' => 'clientealcorriente',
            'plan' => 'pro',
            'subscription_status' => EstadoDeCobranza::ACTIVA,
            'stripe_customer_id' => 'cus_moroso',
            'stripe_subscription_id' => 'sub_moroso',
        ]);
        $empresa->estampaCicloDeCobro(Carbon::now()->addDays(3));

        return $empresa->fresh();
    }

    public function test_un_cobro_fallido_deja_mora_pero_no_apaga_a_nadie(): void
    {
        $empresa = $this->empresaAlCorriente();

        $this->enviarWebhook([
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_moroso', 'subscription' => 'sub_moroso']],
        ])->assertStatus(200);

        $empresa->refresh();
        $this->assertSame(EstadoDeCobranza::MORA, $empresa->subscription_status);
        $this->assertTrue((bool) $empresa->is_active,
            'El apagón lo decide el barrido cuando se agota la gracia, no el webhook.');
        $this->assertNotNull($empresa->current_period_end,
            'La fecha de corte no se toca: es desde donde se cuentan los días de gracia.');
    }

    /** `customer.subscription.updated` llega por cualquier cambio; sólo la mora cuenta. */
    public function test_una_suscripcion_que_sigue_activa_no_marca_mora(): void
    {
        $empresa = $this->empresaAlCorriente();

        $this->enviarWebhook([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'object' => 'subscription',
                'id' => 'sub_moroso',
                'customer' => 'cus_moroso',
                'status' => 'active',
            ]],
        ])->assertStatus(200);

        $this->assertSame(EstadoDeCobranza::ACTIVA, $empresa->refresh()->subscription_status);
    }

    public function test_la_suscripcion_cancelada_en_stripe_queda_como_baja(): void
    {
        $empresa = $this->empresaAlCorriente();

        $this->enviarWebhook([
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'object' => 'subscription',
                'id' => 'sub_moroso',
                'customer' => 'cus_moroso',
                'status' => 'canceled',
            ]],
        ])->assertStatus(200);

        $empresa->refresh();
        $this->assertSame(EstadoDeCobranza::CANCELADA, $empresa->subscription_status);
        $this->assertTrue((bool) $empresa->is_active, 'Cancelar en Stripe no apaga el reloj el mismo día.');
    }
}
