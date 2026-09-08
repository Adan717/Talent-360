<?php

namespace App\Services\Billing;

use App\Support\Tarifario;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * El cobro con tarjeta (Stripe), en un solo sitio. Decisión del 2026-09-06: los clientes pagan
 * con tarjeta, en dos modalidades — una LIGA MANUAL por periodo y una SUSCRIPCIÓN que Stripe
 * cobra sola cada ciclo (los reintentos y el `past_due` los hace Stripe Billing, no este código).
 *
 * Se habla con la API REST por `Http` y no con el SDK de Stripe a propósito, igual que
 * `FacturapiBillingProvider`: es lo que se puede probar de verdad (`Http::fake()` intercepta y
 * deja verificar el cuerpo exacto que se manda). El SDK se sigue usando donde no hay alternativa:
 * verificar la firma del webhook.
 *
 * NO INVENTA PRECIOS. El importe viene de `App\Support\Tarifario`, que es el único tabulador; lo
 * que cobra la caja y lo que pinta la pantalla no pueden separarse.
 *
 * Sin `STRIPE_SECRET` en el servidor esta clase no cobra nada y lo dice (`configurado()` en
 * false): nunca finge un cobro. Las llaves las pone el dueño en el `.env` del servidor.
 */
class CobroConStripe
{
    /** Un cobro único: la "liga manual" que se le manda al cliente por el periodo en curso. */
    public const MODO_LIGA = 'payment';

    /** Suscripción: Stripe cobra la tarjeta cada ciclo hasta que alguien la cancele. */
    public const MODO_SUSCRIPCION = 'subscription';

    private const API = 'https://api.stripe.com/v1';

    /** Los precios del producto son en pesos. Stripe cobra en centavos. */
    public const MONEDA = 'mxn';

    /**
     * ¿Hay llave de Stripe DE VERDAD en este servidor?
     *
     * No basta con que la variable exista: el `.env.example` —y por herencia varios `.env`— trae
     * `STRIPE_SECRET=YOUR_STRIPE_SECRET_KEY`, y darla por buena manda al cliente a un checkout que
     * Stripe rechaza. Las llaves reales empiezan por `sk_` (o `rk_` si es restringida); cualquier
     * otra cosa es relleno y aquí se trata como "no configurado". Mismo criterio con el que
     * `SubscriptionController` descarta el token de relleno de Mercado Pago.
     */
    public function configurado(): bool
    {
        return self::esLlave($this->secreto(), ['sk_', 'rk_']);
    }

    /** @param  string[]  $prefijos */
    public static function esLlave(?string $valor, array $prefijos): bool
    {
        $valor = trim((string) $valor);

        foreach ($prefijos as $prefijo) {
            if (str_starts_with($valor, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Crea la sesión de checkout y devuelve la liga a la que se manda al cliente.
     *
     * @param  array{modo:string, importe:float, concepto:string, referencia:string,
     *               correo:?string, url_exito:string, url_cancelacion:string, ciclo:?string,
     *               metadatos?:array<string,scalar>}  $datos
     * @return array{url:string, id:string}
     *
     * @throws RuntimeException si no hay llave o si Stripe rechaza la petición.
     */
    public function crearSesion(array $datos): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException('Stripe no está configurado en este servidor (falta STRIPE_SECRET).');
        }

        $modo = $datos['modo'] === self::MODO_SUSCRIPCION ? self::MODO_SUSCRIPCION : self::MODO_LIGA;
        $centavos = (int) round(((float) $datos['importe']) * 100);

        if ($centavos <= 0) {
            // Un cobro de cero no es un cobro: el alta gratuita ya se resuelve antes de llegar aquí.
            throw new RuntimeException('No se puede cobrar un importe de cero.');
        }

        $precio = [
            'currency' => self::MONEDA,
            'unit_amount' => $centavos,
            'product_data' => ['name' => $datos['concepto']],
        ];

        if ($modo === self::MODO_SUSCRIPCION) {
            // El importe ya es el total por TODOS los colaboradores (así cotiza el tabulador y así
            // cobraba Mercado Pago), por eso la cantidad es 1 y no el número de personas.
            $precio['recurring'] = [
                'interval' => Tarifario::esAnual($datos['ciclo'] ?? null) ? 'year' : 'month',
            ];
        }

        $cuerpo = [
            'mode' => $modo,
            'line_items' => [['quantity' => 1, 'price_data' => $precio]],
            'success_url' => $datos['url_exito'],
            'cancel_url' => $datos['url_cancelacion'],
            // La referencia es lo que ata el pago con el registro pendiente cuando vuelve el
            // webhook. Va por duplicado a propósito: `client_reference_id` es lo que Stripe
            // muestra en su panel, y los metadatos son los que sobreviven en la suscripción.
            'client_reference_id' => $datos['referencia'],
            'metadata' => ['referencia' => $datos['referencia']] + ($datos['metadatos'] ?? []),
        ];

        if ($modo === self::MODO_SUSCRIPCION) {
            // Sin esto, la factura de cada renovación no sabría de qué empresa es.
            $cuerpo['subscription_data'] = ['metadata' => $cuerpo['metadata']];
        }

        if (!empty($datos['correo'])) {
            $cuerpo['customer_email'] = $datos['correo'];
        }

        $respuesta = Http::withToken($this->secreto())
            ->asForm()
            ->post(self::API . '/checkout/sessions', $cuerpo);

        if (!$respuesta->successful()) {
            $motivo = $respuesta->json('error.message') ?? ('HTTP ' . $respuesta->status());
            Log::error('Stripe: no se pudo crear la sesión de checkout: ' . $motivo);

            throw new RuntimeException('Stripe rechazó el cobro: ' . $motivo);
        }

        $sesion = $respuesta->json();

        if (empty($sesion['url'])) {
            throw new RuntimeException('Stripe respondió sin liga de pago.');
        }

        return ['url' => (string) $sesion['url'], 'id' => (string) ($sesion['id'] ?? '')];
    }

    /**
     * La llave secreta sale de `config('cashier.*')` —un archivo de configuración— y no de
     * `env()` suelto: fuera de config/, `env()` devuelve null en cuanto alguien cachea la config
     * y el cobro se apagaría sin avisar. Es el mismo trago que ya documenta `config/services.php`
     * para la llave del PAC.
     */
    private function secreto(): string
    {
        return trim((string) config('cashier.secret'));
    }
}
