<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\SecurityLogger;
use App\Models\Tenant;
use App\Services\Billing\BillingProviderInterface;
use App\Support\EstadoDeCobranza;
use App\Support\Tarifario;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    // Nacer una empresa que ya pagó es el mismo código para toda pasarela (lo comparte
    // SubscriptionController, que atiende Mercado Pago y el simulador local).
    use AprovisionaEmpresas;

    /**
     * Los eventos que este controlador ATIENDE de verdad (el `switch` de `handleWebhook`), en
     * una lista que se puede leer desde fuera: `stripe:preparar` da de alta exactamente éstos
     * en el panel de Stripe.
     *
     * Si se agrega un `case` allá abajo, se agrega aquí. Si no, el panel nunca mandará el
     * evento nuevo y el circuito fallará EN SILENCIO, que es lo peor que le puede pasar a un
     * cobro: nadie se entera hasta que un cliente reclama. Hay una prueba que compara esta
     * lista con los `case` del archivo.
     */
    public const EVENTOS_QUE_ATIENDE = [
        'checkout.session.completed',
        'invoice.payment_succeeded',
        'charge.succeeded',
        'invoice.payment_failed',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    protected BillingProviderInterface $billingProvider;

    public function __construct(BillingProviderInterface $billingProvider)
    {
        $this->billingProvider = $billingProvider;
    }

    /**
     * Recibe los avisos de pago de Stripe. La ruta es PÚBLICA (`routes/api.php`): sin auth, sin
     * sesión y sin throttle, porque quien llama es Stripe.
     *
     * Por eso la firma es la única puerta. Antes (verificado el 2026-09-08) se saltaba la
     * verificación "si no hay secreto o no hay cabecera", y ese caso era el normal en la V2: el
     * secreto se leía con `env('STRIPE_WEBHOOK_SECRET')` FUERA de config/, donde `env()` devuelve
     * null en cuanto alguien cachea la config. Resultado: cualquiera que conociera la URL podía
     * mandar un `invoice.payment_succeeded` inventado y regalarse el servicio —
     * `handlePaymentSucceeded()` escribe `subscription_status = active` y
     * `current_period_end = +1 mes`— además de disparar un timbrado CFDI real contra el PAC.
     *
     * Ahora falla CERRADO:
     *   - Si hay secreto configurado, la firma es obligatoria SIEMPRE (también fuera de producción).
     *   - En producción, si falta el secreto, el webhook se rechaza: un servidor mal configurado
     *     no puede degradar solo a "modo abierto".
     *   - Sólo fuera de producción y sin secreto se acepta sin firma, para el simulador local.
     *
     * El secreto se lee de `config('cashier.webhook.secret')` (laravel/cashier, ya instalado),
     * que es un archivo de configuración y por tanto sobrevive a `config:cache`. Misma razón que
     * `config/services.php` documenta para la llave del PAC.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        // Un secreto de verdad empieza por `whsec_`. El relleno del `.env.example`
        // (`YOUR_STRIPE_WEBHOOK_SECRET`) NO cuenta como configurado: si contara, en producción
        // toda firma legítima se rechazaría por venir firmada con otro secreto y el diagnóstico
        // sería un misterio. Se trata como ausente, y en producción eso ya cierra la puerta.
        $endpointSecret = \App\Services\Billing\CobroConStripe::esLlave(config('cashier.webhook.secret'), ['whsec_'])
            ? trim((string) config('cashier.webhook.secret'))
            : '';

        Log::info("Stripe Webhook received.");

        $event = null;

        if ($endpointSecret !== '') {
            if ($sigHeader === null) {
                Log::error("Stripe Webhook rechazado: la peticion no trae cabecera Stripe-Signature.");
                return response()->json(['error' => 'Invalid signature'], 400);
            }

            if (!class_exists('\Stripe\Webhook')) {
                // Antes esto decodificaba el payload a pelo: un fallo de instalación abría la
                // puerta. Sin SDK no se puede verificar la firma, así que no se procesa.
                Log::error("Stripe Webhook rechazado: el SDK de Stripe no esta cargado y la firma no se puede verificar.");
                return response()->json(['error' => 'Invalid signature'], 400);
            }

            try {
                $event = \Stripe\Webhook::constructEvent(
                    $payload,
                    $sigHeader,
                    $endpointSecret,
                    (int) config('cashier.webhook.tolerance', 300)
                );
            } catch (\Exception $e) {
                Log::error("Stripe Webhook signature verification failed: " . $e->getMessage());
                return response()->json(['error' => 'Invalid signature'], 400);
            }
        } elseif (app()->environment('production')) {
            Log::error("Stripe Webhook rechazado: falta STRIPE_WEBHOOK_SECRET en el servidor de produccion.");
            return response()->json(['error' => 'Invalid signature'], 400);
        } else {
            // Sin secreto y fuera de producción: simulador y pruebas locales.
            Log::info("Skipping Stripe signature verification (sandbox/local mode).");
            $event = json_decode($payload, true);
        }

        if (!$event) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $eventType = is_array($event) ? ($event['type'] ?? '') : $event->type;
        $eventData = is_array($event) ? ($event['data']['object'] ?? []) : $event->data->object;

        Log::info("Processing Stripe event type: {$eventType}");

        switch ($eventType) {
            // La compra que acaba de pagarse: aquí nace (o mejora) la empresa.
            case 'checkout.session.completed':
                $this->handleCheckoutCompletado($eventData);
                break;

            // Las renovaciones que Stripe cobra solo, ciclo tras ciclo.
            case 'invoice.payment_succeeded':
            case 'charge.succeeded':
                $this->handlePaymentSucceeded($eventData, $eventType);
                break;

            // La otra mitad del trato: cuando el cobro falla, la empresa entra en mora. NO se
            // apaga aquí — la gracia y el apagón los decide `EstadoDeCobranza` en el barrido
            // diario, que es el único que sabe cuántos días lleva sin pagar.
            case 'invoice.payment_failed':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $this->handleCobroFallido($eventData, $eventType);
                break;

            default:
                Log::info("Unhandled Stripe event type: {$eventType}");
                break;
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * `checkout.session.completed` — alguien acaba de pagar: la liga manual del periodo o la
     * contratación de la suscripción recurrente.
     *
     * Es el ÚNICO punto en el que un pago con tarjeta puede dar de alta o mejorar una empresa. El
     * `success_url` al que vuelve el navegador no sirve para esto: una URL de vuelta la puede
     * teclear cualquiera, y aprovisionar desde ahí es exactamente el agujero por el que el
     * simulador de cobro regalaba empresas (por eso hoy es 404 en producción).
     */
    protected function handleCheckoutCompletado($data)
    {
        // Una sesión puede completarse sin que el dinero haya entrado (métodos diferidos). Sólo
        // se aprovisiona la que Stripe da por pagada.
        $estadoDePago = strtolower((string) ($data['payment_status'] ?? 'paid'));
        if (!in_array($estadoDePago, ['paid', 'no_payment_required'], true)) {
            Log::info("Stripe: checkout completado con payment_status={$estadoDePago}: todavía no se aprovisiona.");

            return;
        }

        $referencia = $data['client_reference_id'] ?? ($data['metadata']['referencia'] ?? null);
        $referencias = array_filter([
            'stripe_customer_id' => $data['customer'] ?? null,
            'stripe_subscription_id' => $data['subscription'] ?? null,
        ]);

        try {
            $aprovisionada = $this->aprovisionarRegistroPagado($referencia === null ? null : (string) $referencia);
        } catch (\Throwable $e) {
            // El alta puede fallar legítimamente DESPUÉS de cobrar (p. ej. 409: ese correo ya
            // pertenece a otra empresa). Se responde 200 a propósito: si se devolviera un error,
            // Stripe reintentaría el mismo aviso durante días y el resultado sería idéntico. El
            // registro pendiente NO se borra —el pago está hecho y el alta la termina una persona—
            // y el caso queda en la bitácora, no sólo en el log.
            SecurityLogger::log(
                'cobranza_alta_fallida_tras_pago',
                'Se cobró en Stripe pero el alta de la empresa falló: ' . $e->getMessage()
                . " (referencia {$referencia}). El pago está hecho; hay que completar el alta a mano.",
                null
            );

            return;
        }

        $tenant = $aprovisionada['tenant'] ?? $this->empresaDelEvento($data);

        if (!$tenant) {
            Log::error('Stripe: checkout pagado sin empresa a la que aplicarlo (referencia: '
                . var_export($referencia, true) . ').');

            return;
        }

        // Si el alta acaba de correr, ya estampó el ciclo QUE SE COBRÓ y recalcularlo aquí lo
        // duplicaría. Si no (una renovación pagada con liga manual), se extiende el periodo: desde
        // el corte vigente si todavía no vence —lo ya pagado no se le quita a nadie— o desde hoy.
        $corte = $aprovisionada
            ? $tenant->current_period_end
            : Tarifario::finDelPeriodo($data['metadata']['ciclo'] ?? null, $this->desdeDondeCuenta($tenant));

        $this->reactivarSiEstabaApagadaPorMora($tenant);
        $tenant->subscription_status = EstadoDeCobranza::ACTIVA;
        $tenant->estampaCicloDeCobro($corte, $referencias);

        Log::info("Stripe: checkout aplicado a la empresa {$tenant->id}; cubierta hasta {$tenant->current_period_end}.");
    }

    /**
     * El cobro falló, o la suscripción dejó de estar viva.
     *
     * AQUÍ NO SE APAGA A NADIE. Lo único que se hace es marcar la mora, que es lo que arranca el
     * reloj: quien cuenta los días de gracia y decide el apagón es el barrido diario
     * (`suscripciones:revisar-vencidas` + `EstadoDeCobranza`), que además protege al inquilino
     * principal, a las empresas exentas y a las que no tienen fecha de corte. Dos códigos
     * decidiendo lo mismo sería la forma más cara de equivocarse.
     */
    protected function handleCobroFallido($data, string $eventType)
    {
        $estado = strtolower((string) ($data['status'] ?? ''));

        // `customer.subscription.updated` llega por CUALQUIER cambio (también los inofensivos:
        // cambiar de tarjeta, renovar). Sólo cuentan los estados en los que Stripe dice que el
        // dinero no entró.
        if ($eventType === 'customer.subscription.updated'
            && !in_array($estado, ['past_due', 'unpaid', 'canceled', 'incomplete_expired'], true)) {
            Log::info("Stripe: suscripción en estado '{$estado}': no es mora, no se toca nada.");

            return;
        }

        $tenant = $this->empresaDelEvento($data);
        if (!$tenant) {
            Log::error("Stripe: evento '{$eventType}' sin empresa a la que aplicarlo.");

            return;
        }

        if (EstadoDeCobranza::esInquilinoPrincipal($tenant)) {
            Log::warning('Stripe: evento de cobro fallido sobre el inquilino principal de la plataforma: se ignora.');

            return;
        }

        $muerta = $eventType === 'customer.subscription.deleted' || in_array($estado, ['canceled', 'incomplete_expired'], true);
        $destino = $muerta ? EstadoDeCobranza::CANCELADA : EstadoDeCobranza::MORA;
        $previo = (string) $tenant->subscription_status;

        if ($previo === $destino) {
            return;
        }

        $tenant->subscription_status = $destino;
        $tenant->save();

        SecurityLogger::log(
            EstadoDeCobranza::EVENTO_MORA,
            "Stripe avisó '{$eventType}'" . ($estado === '' ? '' : " (status {$estado})")
            . ": estado {$previo} → {$destino}. El acceso sigue abierto; la gracia y el apagón "
            . 'los decide el barrido diario desde la fecha de corte.',
            $tenant->id
        );
    }

    /**
     * Vuelve a encender el reloj de una empresa que el barrido apagó por falta de pago, ahora que
     * pagó. Es la otra mitad del trato: si apagar es automático, encender al cobrar también.
     *
     * SÓLO reactiva a quien se apagó por mora. El interruptor manual del panel de plataforma deja
     * `subscription_status = cancelled` (PlatformAdminController::toggleTenantStatus), mientras que
     * el apagón por deuda deja `past_due`; una empresa suspendida a mano por cualquier otra razón
     * no puede volver sola por haber pagado.
     */
    private function reactivarSiEstabaApagadaPorMora(Tenant $tenant): void
    {
        if ((bool) $tenant->is_active) {
            return;
        }

        if ((string) $tenant->subscription_status !== EstadoDeCobranza::MORA) {
            Log::info("Stripe: la empresa {$tenant->id} está suspendida por algo que no es la deuda; "
                . 'el pago no la reactiva.');

            return;
        }

        $tenant->is_active = true;
        $tenant->suspension_reason = null;
        $tenant->suspended_at = null;

        SecurityLogger::log(
            EstadoDeCobranza::EVENTO_REACTIVACION,
            'Reactivación automática: entró el pago de una empresa que estaba suspendida por falta '
            . 'de pago. El registro de asistencia vuelve a funcionar.',
            $tenant->id
        );
    }

    /**
     * A qué empresa se refiere un evento de Stripe, en orden de confianza: los metadatos que
     * pusimos nosotros al crear el cobro (lo más fiable, no depende de que la empresa ya tuviera
     * guardado el cliente), el cliente de Stripe y la suscripción.
     */
    protected function empresaDelEvento($data): ?Tenant
    {
        $idEmpresa = $data['metadata']['tenant_id'] ?? null;
        if (!empty($idEmpresa)) {
            $tenant = Tenant::find((int) $idEmpresa);
            if ($tenant) {
                return $tenant;
            }
        }

        $cliente = $data['customer'] ?? null;
        if (!empty($cliente)) {
            $tenant = Tenant::where('stripe_customer_id', $cliente)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        // En los eventos de factura la suscripción viene en `subscription`; en los de la propia
        // suscripción, el objeto ES la suscripción y su id está en `id`.
        $suscripciones = [$data['subscription'] ?? null];
        if (($data['object'] ?? null) === 'subscription') {
            $suscripciones[] = $data['id'] ?? null;
        }

        foreach (array_filter($suscripciones) as $suscripcion) {
            $tenant = Tenant::where('stripe_subscription_id', $suscripcion)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return null;
    }

    /**
     * Desde cuándo se cuenta el periodo que se acaba de pagar: si a la empresa todavía le quedan
     * días pagados, desde su corte vigente (nadie pierde lo que ya pagó); si ya venció, desde hoy.
     */
    private function desdeDondeCuenta(Tenant $tenant): CarbonInterface
    {
        try {
            $corte = $tenant->current_period_end ? Carbon::parse($tenant->current_period_end) : null;
        } catch (\Throwable $e) {
            $corte = null;
        }

        return ($corte && $corte->isFuture()) ? $corte : Carbon::now();
    }

    /**
     * Handle payment succeeded event and trigger CFDI 4.0 invoice creation.
     */
    protected function handlePaymentSucceeded($data, string $eventType)
    {
        // Extract Stripe customer ID and subscription ID
        $customerId = $data['customer'] ?? null;
        $subscriptionId = $data['subscription'] ?? null;
        
        // Amount paid is usually in cents
        $amountPaidCents = $data['amount_paid'] ?? ($data['amount'] ?? 0);
        $amountPaid = $amountPaidCents / 100;
        
        $currency = strtoupper($data['currency'] ?? 'mxn');
        
        Log::info("Payment succeeded event: Customer={$customerId}, Subscription={$subscriptionId}, Amount={$amountPaid} {$currency}");

        if (!$customerId) {
            Log::error("Stripe event data does not contain a customer ID. Cannot proceed.");
            return;
        }

        $tenant = $this->empresaDelEvento($data);

        // Fallback: search by email inside metadata or customer details
        if (!$tenant) {
            $customerEmail = $data['customer_email'] ?? ($data['billing_details']['email'] ?? null);
            if ($customerEmail) {
                // Find admin user with that email
                $adminUser = DB::table('users')
                    ->where('email', strtolower($customerEmail))
                    ->where('role', 'admin')
                    ->first();

                if ($adminUser) {
                    $tenant = Tenant::find($adminUser->tenant_id);
                }
            }
        }

        if (!$tenant) {
            // Stripe puede entregar `charge.succeeded` antes que `checkout.session.completed`.
            // Ese cargo todavía no tiene empresa a la cual aplicar; el checkout posterior (o la
            // factura) es la fuente que aprovisiona y deja la relación. No es un error operativo.
            if ($eventType === 'charge.succeeded') {
                Log::info('Stripe: charge.succeeded llegó antes de que existiera la empresa; se espera checkout/invoice.');
                return;
            }
            Log::error("No Tenant found matching Stripe Customer ID: {$customerId} or Subscription ID: {$subscriptionId}");
            return;
        }

        // La nueva fecha de corte la manda Stripe en la factura (`period_end`, unix). Sólo si no
        // viene —p. ej. un `charge.succeeded` suelto— se cae al mes calendario.
        $corte = $this->fechaDeCorteDe($data) ?? now()->addMonth();

        // La fecha de corte se estampa con `Tenant::estampaCicloDeCobro()` y no con `update([...])`:
        // `current_period_end` no es $fillable y la asignación masiva la tiraba en silencio (el
        // porqué completo está en el docblock de ese método).
        DB::transaction(function() use ($tenant, $subscriptionId, $corte) {
            $this->reactivarSiEstabaApagadaPorMora($tenant);
            $tenant->subscription_status = EstadoDeCobranza::ACTIVA;
            $tenant->estampaCicloDeCobro($corte, ['stripe_subscription_id' => $subscriptionId]);
        });

        // Un evento de prueba sólo valida el circuito de cobro. Nunca debe intentar emitir un
        // CFDI contra el PAC: además de generar ruido en el log, sería un efecto fiscal real de
        // una tarjeta sandbox. Stripe marca estos objetos con `livemode=false`; los payloads
        // antiguos/locales que no traen la marca conservan el comportamiento de las pruebas de
        // aplicación y pasan por el proveedor falso de la suite.
        // Con firma válida el SDK entrega un StripeObject; el simulador y algunas pruebas entregan
        // un array. Se leen ambos sin `array_key_exists()`, que lanzaría TypeError con StripeObject.
        $livemode = is_array($data) ? ($data['livemode'] ?? null) : ($data->livemode ?? null);
        if ($livemode === false) {
            Log::info("Stripe: pago sandbox aplicado a la empresa {$tenant->id}; se omite timbrado CFDI.");

            return;
        }

        // Trigger CFDI 4.0 timbrado for this SaaS subscription
        $adminEmail = DB::table('users')
            ->where('tenant_id', $tenant->id)
            ->where('role', 'admin')
            ->value('email') ?? 'billing@' . $tenant->subdomain . '.com';

        // Construct CFDI 4.0 structure
        $invoiceData = [
            'customer' => [
                'legal_name' => $tenant->tax_name ?? $tenant->name,
                'rfc' => $tenant->rfc ?? 'XAXX010101000', // General test RFC if none set
                'tax_system' => $tenant->tax_regimen ?? '601',
                'email' => $adminEmail,
                'address' => [
                    'zip' => $tenant->postal_code ?? '01000'
                ]
            ],
            'items' => [
                [
                    'quantity' => 1,
                    'product' => [
                        'description' => "Suscripción mensual Plataforma Talent360 - Plan " . strtoupper($tenant->plan ?? 'PRO'),
                        'product_key' => '84111506', // Servicios de facturación de software SAT key
                        'price' => $amountPaid > 0 ? $amountPaid : 199.00,
                        'tax_included' => true,
                        'taxes' => [
                            [
                                'municipality' => 'IVA',
                                'rate' => 0.16,
                                'type' => 'transfer'
                            ]
                        ]
                    ]
                ]
            ],
            'payment_form' => '03', // Transferencia electrónica de fondos
            'payment_method' => 'PUE',
            'use' => 'G03' // Gastos en general
        ];

        Log::info("Triggering SAT CFDI 4.0 invoice creation for Tenant ID: {$tenant->id} via Facturapi.");

        // Call the abstract Billing Provider (does not set tenant context, so it bills from platform account to customer/tenant)
        $result = $this->billingProvider->createInvoice($invoiceData);

        if ($result['success']) {
            Log::info("SAT CFDI 4.0 Timbrado Successful! Invoice ID: {$result['id']}, UUID: {$result['uuid']}");

            // Log event in saas_audit_logs
            DB::table('saas_audit_logs')->insert([
                'tenant_id' => $tenant->id,
                'event_type' => 'stripe.payment_succeeded_facturapi_timbrado',
                'description' => "Pago Stripe de $" . $amountPaid . " " . $currency . " procesado con éxito. Factura CFDI 4.0 timbrada automáticamente con UUID: " . $result['uuid'] . ". PDF: " . $result['pdf_url'],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } else {
            Log::error("SAT CFDI 4.0 Timbrado Failed: " . ($result['error'] ?? 'Unknown error'));

            // Log failure event in saas_audit_logs
            DB::table('saas_audit_logs')->insert([
                'tenant_id' => $tenant->id,
                'event_type' => 'stripe.payment_succeeded_facturapi_failed',
                'description' => "Pago Stripe de $" . $amountPaid . " " . $currency . " procesado con éxito, pero falló el timbrado automático CFDI: " . ($result['error'] ?? 'Error desconocido de Facturapi.'),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    /**
     * Fin del periodo cobrado, tal como lo manda Stripe: en una factura viene en `period_end`
     * (unix) y, si trae renglones, en el periodo del renglón —que es el de la suscripción—.
     * Devuelve null si el evento no trae ninguno (p. ej. un `charge.succeeded` suelto), para que
     * quien llama decida el respaldo. `$data` puede ser un arreglo o un objeto del SDK de Stripe;
     * ambos se leen igual, y cualquier forma inesperada cae a null en vez de tirar un 500 que
     * dejaría el pago sin aplicar.
     */
    private function fechaDeCorteDe($data): ?Carbon
    {
        try {
            $candidatos = [
                $data['lines']['data'][0]['period']['end'] ?? null,
                $data['period_end'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($candidatos as $unix) {
            if (is_numeric($unix) && (int) $unix > 0) {
                return Carbon::createFromTimestamp((int) $unix);
            }
        }

        return null;
    }
}
