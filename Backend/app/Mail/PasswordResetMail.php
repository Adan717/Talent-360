<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §52: correo del flujo estándar "olvidé mi contraseña". Usa el remitente
 * institucional de la plataforma (no el nombre del tenant — es un correo del sistema).
 */
class PasswordResetMail extends Mailable
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
