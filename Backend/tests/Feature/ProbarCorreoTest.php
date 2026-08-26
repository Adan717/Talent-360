<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `php artisan mail:test` — validar las credenciales de correo desde la consola (2026-08-26).
 *
 * Lo que estas pruebas fijan es lo que hace que el comando sirva de algo:
 *
 *  · **Envía EN LÍNEA, no por la cola.** Los correos del sistema sí se encolan; aquí encolar
 *    sería engañoso: el comando diría "listo" y el fallo real —credenciales malas, puerto
 *    cerrado— aparecería después en el worker, donde nadie lo mira.
 *  · **Avisa cuando `MAIL_MAILER` cae a `log`.** Sin ese aviso, el comando terminaría en verde
 *    mientras el correo se escribe en un archivo y no sale de la máquina.
 *  · **Nunca imprime la contraseña.**
 */
class ProbarCorreoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Con el transporte `array` se inspecciona el MENSAJE REAL que se construyó —destinatario,
     * remitente, asunto—, no un doble. `Mail::fake()` no sirve aquí: el comando usa `Mail::raw`,
     * que no manda un Mailable con clase propia y el fake no lo registra como tal.
     */
    public function test_manda_el_correo_de_prueba_al_destino(): void
    {
        config(['mail.default' => 'array']);
        Mail::mailer('array')->getSymfonyTransport()->flush();

        $this->artisan('mail:test', ['destino' => 'jefe@empresa.com'])->assertExitCode(0);

        $mensajes = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertCount(1, $mensajes, 'tenía que salir exactamente un correo');

        $mensaje = $mensajes[0]->getOriginalMessage();
        $this->assertSame('jefe@empresa.com', $mensaje->getTo()[0]->getAddress());
        $this->assertSame('Prueba de correo · Talent360', $mensaje->getSubject());
        $this->assertStringContainsString('Prueba de correo de Talent360', $mensaje->getTextBody());
        $this->assertNotEmpty($mensaje->getFrom(), 'sin remitente ningún servidor lo acepta');
    }

    /** La razón de ser del comando: el error se ve AHORA, no dentro de un worker. */
    public function test_el_correo_de_prueba_no_pasa_por_la_cola(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['destino' => 'jefe@empresa.com'])->assertExitCode(0);

        Mail::assertNothingQueued();
    }

    public function test_un_correo_invalido_se_rechaza_sin_intentar_nada(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['destino' => 'esto-no-es-un-correo'])
            ->expectsOutputToContain('no es un correo válido')
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }

    /** El aviso que evita la conclusión más cara: "ya funciona" cuando no salió nada. */
    public function test_avisa_cuando_el_env_no_tiene_smtp_configurado(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);

        $this->artisan('mail:test', ['destino' => 'jefe@empresa.com'])
            ->expectsOutputToContain('MAIL_MAILER no está configurado')
            ->expectsOutputToContain('NO va a salir de este servidor')
            ->assertExitCode(0);
    }

    public function test_con_smtp_no_avisa_de_lo_del_log(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $salida = $this->artisan('mail:test', ['destino' => 'jefe@empresa.com'])->assertExitCode(0);
        $salida->doesntExpectOutputToContain('NO va a salir de este servidor');
        $salida->run();
    }

    /** Aceptado por el servidor no es recibido en la bandeja: decirlo evita creer de más. */
    public function test_con_smtp_advierte_que_aceptado_no_es_recibido(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->artisan('mail:test', ['destino' => 'jefe@empresa.com'])
            ->expectsOutputToContain('"aceptado" no es "recibido"')
            ->assertExitCode(0);
    }

    public function test_nunca_imprime_la_contrasena_del_smtp(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => 'SuperSecreta123',
            'mail.mailers.smtp.host' => 'smtp.hostinger.com',
        ]);

        $salida = $this->artisan('mail:test', ['destino' => 'jefe@empresa.com'])->assertExitCode(0);
        $salida->doesntExpectOutputToContain('SuperSecreta123');
        // Pero sí dice que está puesta y cuánto mide: con eso se descarta "quedó vacía".
        $salida->expectsOutputToContain('puesta (15 caracteres)');
        $salida->run();
    }

    /** Una contraseña vacía es la causa más común, y el comando la señala. */
    public function test_señala_la_contrasena_vacia(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.password' => '']);

        $this->artisan('mail:test', ['destino' => 'jefe@empresa.com'])
            ->expectsOutputToContain('VACÍA')
            ->assertExitCode(0);
    }
}
