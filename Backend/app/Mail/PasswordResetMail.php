<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §52: correo del flujo estándar "olvidé mi contraseña". Usa el remitente
 * institucional de la plataforma (no el nombre del tenant — es un correo del sistema).
 */
/**
 * ENCOLADO (2026-08-26). Antes se enviaba EN LÍNEA: si el servidor de correo tardaba ocho
 * segundos, el alta del colaborador tardaba ocho segundos, y si el correo estaba caído la
 * petición fallaba entera. Un correo no puede tumbar un alta.
 *
 * `SerializesModels` va puesto por si algún día se le pasa un modelo: hoy recibe cadenas y
 * objetos `Address`, que serializan sin problema.
 */
class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: app(\App\Services\MailSettingsService::class)->platformFromAddress(),
            subject: 'Restablece tu contraseña · Talent360',
        );
    }

    public function content(): Content
    {
        $url = e($this->resetUrl);

        $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 520px; margin: 0 auto;">
  <h2>Restablece tu contraseña</h2>
  <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta de Talent360.</p>
  <p style="text-align:center;margin:24px 0;">
    <a href="{$url}" style="background:#2563EB;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;">Restablecer contraseña</a>
  </p>
  <p style="color:#666;font-size:13px;">Este enlace vence en 60 minutos. Si no solicitaste esto, puedes ignorar el correo.</p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
