<?php

namespace App\Console\Commands;

use App\Services\MailSettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Valida las credenciales de correo del `.env` mandando un correo de verdad (2026-08-26).
 *
 * Sirve para comprobar el SMTP desde la consola ANTES de tocar la interfaz, que es donde un fallo
 * de correo se confunde con un fallo del sistema.
 *
 * TRES DECISIONES QUE HACEN QUE ESTO SIRVA DE ALGO:
 *
 *  1. **Envía EN LÍNEA, sin pasar por la cola.** Los correos del sistema sí se encolan (un SMTP
 *     lento no puede tumbar un alta), pero aquí encolar sería inútil y engañoso: el comando diría
 *     "enviado" y el fallo real —credenciales malas, puerto cerrado, TLS— aparecería después en el
 *     worker, donde nadie lo está mirando. Aquí se quiere el error en la cara, ahora.
 *
 *  2. **Avisa cuando el `.env` NO tiene SMTP.** `MAIL_MAILER` cae a `log` por defecto: sin este
 *     aviso, el comando terminaría con un "listo" verde mientras el correo se escribe en un archivo
 *     y no sale de la máquina. Ese "éxito" es peor que un error.
 *
 *  3. **Enseña la configuración que está usando**, con la contraseña tapada. Validar credenciales
 *     a ciegas no valida nada: la mitad de los fallos de SMTP en hosting compartido son un puerto
 *     o un host equivocado, y se ven de un vistazo.
 */
#[Signature('mail:test {destino : A dónde mandar el correo de prueba} {--desde= : Forzar el remitente (por omisión, el del sistema)}')]
#[Description('Manda un correo de prueba con la configuración actual del .env, para validar credenciales SMTP desde la consola.')]
class ProbarCorreo extends Command
{
    public function handle(MailSettingsService $ajustes): int
    {
        $destino = trim((string) $this->argument('destino'));

        if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$destino}' no es un correo válido.");
            $this->line('Uso: php artisan mail:test tucorreo@dominio.com');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        // El remitente del sistema vive en la base (`system_settings`), pero este comando NO puede
        // depender de ella: sirve para diagnosticar el CORREO, y fallar aquí por un problema de
        // base de datos mandaría a buscar el error al lugar equivocado. Si no se puede leer, se
        // usa el del .env y se sigue.
        $remitente = (string) $this->option('desde');
        if ($remitente === '') {
            try {
                $remitente = $ajustes->platformSenderEmail();
            } catch (\Throwable) {
                $remitente = (string) (config('mail.from.address') ?: 'no-reply@talent360.com.mx');
            }
        }

        $this->mostrarConfiguracion($mailer, $remitente, $destino);

        if ($mailer === 'log') {
            $this->newLine();
            $this->warn('⚠  MAIL_MAILER no está configurado (cae a "log").');
            $this->warn('   El correo NO va a salir de este servidor: se escribe en');
            $this->warn('   storage/logs/laravel.log. Pon MAIL_MAILER=smtp y tus credenciales');
            $this->warn('   en el .env para probar de verdad.');
            $this->newLine();
        }

        $inicio = microtime(true);

        try {
            Mail::raw($this->cuerpo($mailer, $remitente, $destino), function ($mensaje) use ($destino, $remitente) {
                $mensaje->to($destino)
                    ->from($remitente, config('mail.from.name') ?: 'Talent360')
                    ->subject('Prueba de correo · Talent360');
            });
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('✖ NO se pudo enviar.');
            $this->line('   ' . $e->getMessage());
            $this->pistas($e->getMessage());

            return self::FAILURE;
        }

        $segundos = round(microtime(true) - $inicio, 2);
        $this->newLine();

        if ($mailer === 'log') {
            $this->info("✔ Escrito en el log en {$segundos}s. NO se envió a nadie (MAIL_MAILER=log).");

            return self::SUCCESS;
        }

        $this->info("✔ El servidor de correo lo aceptó en {$segundos}s.");
        $this->newLine();
        // Que lo aceptó el servidor NO es que llegó a la bandeja: eso lo decide el destinatario.
        // Decirlo evita la conclusión más cara — "el correo funciona" — cuando falta SPF/DKIM.
        $this->line('   Ojo: "aceptado" no es "recibido". Revisa la bandeja de ' . $destino . ',');
        $this->line('   y también la carpeta de SPAM. Si llegó a spam, faltan los registros');
        $this->line('   SPF y DKIM del dominio desde el que estás enviando.');

        return self::SUCCESS;
    }

    private function mostrarConfiguracion(string $mailer, string $remitente, string $destino): void
    {
        $filas = [
            ['MAIL_MAILER', $mailer],
            ['Remitente (from)', $remitente],
            ['Nombre visible', (string) (config('mail.from.name') ?: '(sin nombre)')],
            ['Destino', $destino],
        ];

        if ($mailer === 'smtp') {
            $filas = array_merge($filas, [
                ['Host', (string) config('mail.mailers.smtp.host')],
                ['Puerto', (string) config('mail.mailers.smtp.port')],
                ['Usuario', (string) (config('mail.mailers.smtp.username') ?: '(sin usuario)')],
                // La contraseña NUNCA se imprime: sólo si está puesta y cuánto mide, que es lo
                // único que hace falta para descartar "se me olvidó ponerla" o "quedó vacía".
                ['Contraseña', $this->contrasenaTapada()],
                ['Cifrado (scheme)', (string) (config('mail.mailers.smtp.scheme') ?: '(automático)')],
            ]);
        }

        $this->newLine();
        $this->line('Configuración que se va a usar:');
        $this->table(['Ajuste', 'Valor'], $filas);
    }

    private function contrasenaTapada(): string
    {
        $clave = (string) config('mail.mailers.smtp.password');

        if ($clave === '') {
            return '(VACÍA — casi seguro es esto)';
        }

        return 'puesta (' . strlen($clave) . ' caracteres)';
    }

    private function cuerpo(string $mailer, string $remitente, string $destino): string
    {
        return implode("\n", [
            'Prueba de correo de Talent360.',
            '',
            'Si estás leyendo esto, la configuración de correo del servidor funciona:',
            'las credenciales son válidas y el servidor aceptó y entregó el mensaje.',
            '',
            'Enviado con:',
            '  · Transporte: ' . $mailer,
            '  · Remitente:  ' . $remitente,
            '  · Destino:    ' . $destino,
            '  · Fecha:      ' . now()->toDateTimeString(),
            '',
            'Este correo se generó con `php artisan mail:test` y no significa que algún',
            'colaborador haya recibido nada: es sólo una prueba de la configuración.',
        ]);
    }

    /** Los tres fallos que se repiten con SMTP de hosting compartido, dichos en cristiano. */
    private function pistas(string $error): void
    {
        $e = strtolower($error);
        $this->newLine();

        if (str_contains($e, 'authenticat') || str_contains($e, '535') || str_contains($e, 'credential')) {
            $this->line('   → El servidor rechazó el usuario o la contraseña. En hosting suele ser');
            $this->line('     el correo COMPLETO como usuario (no sólo la parte antes de la @).');

            return;
        }

        if (str_contains($e, 'connection') || str_contains($e, 'timed out') || str_contains($e, 'refused')) {
            $this->line('   → No se pudo abrir la conexión. Revisa MAIL_HOST y MAIL_PORT (465 con');
            $this->line('     SSL, 587 con TLS), y que el servidor deje salir por ese puerto.');

            return;
        }

        if (str_contains($e, 'sender') || str_contains($e, 'from') || str_contains($e, '553') || str_contains($e, '550')) {
            $this->line('   → El servidor no acepta ese remitente. Casi todos los hosting exigen');
            $this->line('     que el "from" sea EXACTAMENTE el buzón con el que te autenticaste.');
            $this->line('     Prueba: php artisan mail:test ' . $this->argument('destino') . ' --desde=EL_MISMO_DE_MAIL_USERNAME');

            return;
        }

        $this->line('   → Revisa el bloque de configuración de arriba contra lo que te dio tu hosting.');
    }
}
