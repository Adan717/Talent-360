<?php

namespace App\Console\Commands;

use App\Http\Controllers\StripeWebhookController;
use App\Services\Billing\CobroConStripe;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deja Stripe listo para cobrar, en un solo comando (2026-09-08).
 *
 * POR QUÉ EXISTE. El plan de ejecución dejaba cuatro pendientes al dueño: poner las llaves, dar
 * de alta el webhook en el panel, hacer una compra de prueba y apagar el checkout simulado. Tres
 * de esos cuatro son mecánicos, y uno de ellos —el webhook— es de los que se hacen mal en
 * silencio: la URL lleva `/v1` en medio, hay que marcar seis eventos a mano y, si falta uno, no
 * pasa nada visible hasta que un cliente paga y su empresa no nace. Eso no se le pide a nadie
 * por escrito: se automatiza.
 *
 * Así que aquí quedan los tres: verificar la llave contra la cuenta real, dar de alta (o poner al
 * día) el endpoint con EXACTAMENTE los eventos que el webhook atiende, y crear una sesión de
 * cobro de prueba. El cuarto —apagar el simulador— ya no es un paso: `SubscriptionController`
 * lo ignora solo en cuanto hay pasarela.
 *
 * LO QUE ESTE COMANDO NO HACE: escribir el `.env`. Las llaves las pone el dueño; un comando que
 * las escriba tendría que leerlas, y las credenciales de cobro no pasan por aquí.
 */
class PrepararStripeCommand extends Command
{
    protected $signature = 'stripe:preparar
        {--url= : URL pública del webhook. Por defecto APP_URL + /api/v1/webhooks/stripe}
        {--sin-prueba : No crear la sesión de cobro de prueba}
        {--force : No preguntar antes de tocar la cuenta de Stripe}';

    protected $description = 'Verifica la llave de Stripe, da de alta el webhook con sus eventos y hace un cobro de prueba.';

    private const RUTA_DEL_WEBHOOK = '/api/v1/webhooks/stripe';

    public function handle(CobroConStripe $stripe): int
    {
        if (!$stripe->configurado()) {
            $this->error('No hay llave de Stripe en este servidor.');
            $this->line('');
            $this->line('  Pon en el .env del servidor, con las llaves de tu panel de Stripe:');
            $this->line('    STRIPE_KEY=pk_...        (la publicable)');
            $this->line('    STRIPE_SECRET=sk_...     (la secreta; tambien vale una restringida rk_)');
            $this->line('    STRIPE_WEBHOOK_SECRET=whsec_...  (te la da el paso 2 de este comando)');
            $this->line('');
            $this->line('  Los rellenos tipo YOUR_STRIPE_SECRET_KEY NO cuentan: el codigo los ignora a proposito.');

            return self::FAILURE;
        }

        try {
            $cuenta = $stripe->cuenta();
        } catch (Throwable $e) {
            $this->error('Stripe rechazo la llave: ' . $e->getMessage());
            $this->line('Revisa que STRIPE_SECRET sea la de esta cuenta y no este revocada.');

            return self::FAILURE;
        }

        $this->info('1/3 · La llave sirve.');
        $this->line("      Cuenta: {$cuenta['nombre']} ({$cuenta['id']})");
        $this->line($cuenta['viva']
            ? '      MODO REAL: lo que se cobre aqui es dinero de verdad.'
            : '      Modo de PRUEBAS (sandbox): ningun cargo es real.');
        $this->line('');

        $url = $this->urlDelWebhook();
        if (!str_ends_with($url, self::RUTA_DEL_WEBHOOK)) {
            $this->warn("La URL no termina en " . self::RUTA_DEL_WEBHOOK . ': ' . $url);
            $this->warn('Ojo con el /v1: sin el, Stripe manda los avisos a una ruta que no existe.');
        }
        if (!str_starts_with($url, 'https://')) {
            $this->error('El webhook tiene que ser https: Stripe no manda avisos a http. URL: ' . $url);

            return self::FAILURE;
        }

        if (!$this->atenderElWebhook($stripe, $url)) {
            return self::FAILURE;
        }

        if ($this->option('sin-prueba')) {
            $this->line('3/3 · Cobro de prueba omitido (--sin-prueba).');

            return $this->cierre($stripe);
        }

        return $this->cobroDePrueba($stripe) ? $this->cierre($stripe) : self::FAILURE;
    }

    /** Da de alta el endpoint, o le pone al día los eventos si ya existía. */
    private function atenderElWebhook(CobroConStripe $stripe, string $url): bool
    {
        $eventos = StripeWebhookController::EVENTOS_QUE_ATIENDE;

        try {
            $existentes = collect($stripe->webhooksRegistrados())->firstWhere('url', $url);
        } catch (Throwable $e) {
            $this->error('No se pudieron leer los webhooks de la cuenta: ' . $e->getMessage());

            return false;
        }

        if ($existentes === null) {
            if (!$this->option('force') && !$this->confirm("2/3 · Voy a dar de alta el webhook {$url} en tu cuenta de Stripe. ¿Le sigo?", true)) {
                $this->line('      Cancelado. Sin webhook, un pago no da de alta la empresa.');

                return false;
            }

            try {
                $alta = $stripe->registrarWebhook($url, $eventos);
            } catch (Throwable $e) {
                $this->error('No se pudo dar de alta el webhook: ' . $e->getMessage());

                return false;
            }

            $this->info('2/3 · Webhook dado de alta con sus ' . count($eventos) . ' eventos.');
            $this->line('');
            $this->line('      ▼ GUARDA ESTO EN EL .env DEL SERVIDOR. Stripe sólo lo enseña una vez:');
            $this->line('');
            $this->line('        STRIPE_WEBHOOK_SECRET=' . $alta['secret']);
            $this->line('');
            $this->line('      Sin esa linea el webhook responde 400 a TODO —falla cerrado a proposito—');
            $this->line('      y ningun pago dara de alta una empresa. Despues, reinicia el backend.');
            $this->line('');

            return true;
        }

        $faltantes = array_values(array_diff($eventos, $existentes['eventos']));

        if ($faltantes === []) {
            $this->info('2/3 · El webhook ya estaba dado de alta y con todos sus eventos.');
            $this->line('');

            return true;
        }

        $this->warn('2/3 · El webhook existe pero le faltan eventos: ' . implode(', ', $faltantes));
        if (!$this->option('force') && !$this->confirm('      ¿Se los agrego?', true)) {
            return false;
        }

        try {
            $stripe->actualizarEventosDelWebhook($existentes['id'], $eventos);
        } catch (Throwable $e) {
            $this->error('No se pudieron actualizar los eventos: ' . $e->getMessage());

            return false;
        }

        $this->info('      Listo: el endpoint quedó con los ' . count($eventos) . ' eventos.');
        $this->line('');

        return true;
    }

    /** La compra de prueba que el plan pedía hacer a mano. */
    private function cobroDePrueba(CobroConStripe $stripe): bool
    {
        try {
            $sesion = $stripe->crearSesion([
                'modo' => CobroConStripe::MODO_LIGA,
                'importe' => 10.00,
                'concepto' => 'Prueba de configuracion (Talent 360)',
                'referencia' => 'prueba-' . now()->format('YmdHis'),
                'correo' => null,
                'ciclo' => null,
                'url_exito' => rtrim((string) config('app.url'), '/') . '/?prueba=ok',
                'url_cancelacion' => rtrim((string) config('app.url'), '/') . '/?prueba=cancelada',
            ]);
        } catch (Throwable $e) {
            $this->error('3/3 · El cobro de prueba fallo: ' . $e->getMessage());

            return false;
        }

        $this->info('3/3 · Cobro de prueba creado. Abrelo y paga para cerrar el circuito:');
        $this->line('');
        $this->line('      ' . $sesion['url']);
        $this->line('');
        $this->line('      Tarjeta que APRUEBA:  4242 4242 4242 4242 · cualquier fecha futura · cualquier CVC');
        $this->line('      Tarjeta que RECHAZA:  4000 0000 0000 0002 (para ver el camino del pago fallido)');
        $this->line('      Despues revisa en el panel de Stripe que el evento salio en 200.');
        $this->line('');

        return true;
    }

    /** El estado en el que queda el servidor, que es lo que interesa al final. */
    private function cierre(CobroConStripe $stripe): int
    {
        $secreto = (string) config('cashier.webhook.secret');
        $tieneSecreto = CobroConStripe::esLlave($secreto, ['whsec_']);

        $this->line('── COMO QUEDA ESTE SERVIDOR ──');
        $this->line('  Cobro con tarjeta ....... ' . ($stripe->configurado() ? 'ENCENDIDO' : 'apagado'));
        $this->line('  Firma del webhook ....... ' . ($tieneSecreto ? 'configurada' : 'FALTA (STRIPE_WEBHOOK_SECRET)'));
        $this->line('  Checkout simulado ....... ' . ($stripe->configurado()
            ? 'INERTE (hay pasarela; da igual que ALLOW_SIMULATED_CHECKOUT siga en true)'
            : 'VIVO si ALLOW_SIMULATED_CHECKOUT=true — da altas gratis en una URL publica'));
        $this->line('');

        if (!$tieneSecreto) {
            $this->warn('Falta la firma: mientras no este, el webhook responde 400 a todo y ningun pago');
            $this->warn('dara de alta una empresa. Pon STRIPE_WEBHOOK_SECRET y reinicia el backend.');

            return self::FAILURE;
        }

        $this->info('Todo listo. Ya no queda ningun paso manual de Stripe.');

        return self::SUCCESS;
    }

    private function urlDelWebhook(): string
    {
        $url = (string) $this->option('url');

        return $url !== ''
            ? rtrim($url, '/')
            : rtrim((string) config('app.url'), '/') . self::RUTA_DEL_WEBHOOK;
    }
}
