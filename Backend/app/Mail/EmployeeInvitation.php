<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §52: correo de bienvenida/invitación a un colaborador nuevo. El "From" usa el
 * remitente real de la plataforma con el nombre para mostrar del tenant ("{Empresa}
 * vía Talent360"), y el Reply-To (si existe) es el correo real del tenant.
 */
class EmployeeInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $employeeName,
        public string $companyName,
        public string $inviteUrl,
        public string $pin,
        public Address $fromAddress,
        public ?Address $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromAddress,
            replyTo: $this->replyToAddress ? [$this->replyToAddress] : [],
            subject: "Te damos la bienvenida a {$this->companyName} · Talent360",
        );
    }

    public function content(): Content
    {
        $name = e($this->employeeName);
        $company = e($this->companyName);
        $url = e($this->inviteUrl);
        $pin = e($this->pin);

        $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 520px; margin: 0 auto;">
  <h2>¡Hola {$name}!</h2>
  <p><strong>{$company}</strong> te invitó a unirte a su equipo en <strong>Talent360</strong>.</p>
  <p>Para activar tu cuenta, entra al siguiente enlace y usa tu PIN:</p>
  <p style="text-align:center;margin:24px 0;">
    <a href="{$url}" style="background:#2563EB;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;">Activar mi cuenta</a>
  </p>
  <p>Tu PIN de activación es: <strong style="font-size:20px;letter-spacing:2px;">{$pin}</strong></p>
  <p style="color:#666;font-size:13px;">Si no esperabas este correo, puedes ignorarlo.</p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
