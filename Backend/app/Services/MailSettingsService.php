<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Mail\Mailables\Address;

/**
 * §52: resuelve el remitente de los correos salientes respetando SPF/DKIM/DMARC.
 * Patrón correcto (Slack/Notion/etc.): UN solo remitente real autenticado para todo el
 * sistema; lo que cambia por contexto es el NOMBRE para mostrar y el Reply-To.
 *
 * Config a nivel plataforma (system_settings, tenant_id NULL):
 *   platform_sender_email, platform_welcome_email_subject, platform_welcome_email_body
 * Config a nivel tenant (system_settings, tenant_id del tenant):
 *   sender_display_name, sender_reply_to
 */
class MailSettingsService
{
    private function platformSetting(string $key, ?string $default = null): ?string
    {
        $row = DB::table('system_settings')->whereNull('tenant_id')->where('key', $key)->value('value');
        if ($row === null) {
            return $default;
        }
        // Los valores pueden estar guardados como JSON (json_encode) o texto plano.
        $decoded = json_decode($row, true);
        return is_string($decoded) ? $decoded : $row;
    }

    private function tenantSetting(int $tenantId, string $key, ?string $default = null): ?string
    {
        $row = DB::table('system_settings')->where('tenant_id', $tenantId)->where('key', $key)->value('value');
        if ($row === null) {
            return $default;
        }
        $decoded = json_decode($row, true);
        return is_string($decoded) ? $decoded : $row;
    }

    /** El remitente real y autenticado del sistema (único). */
    public function platformSenderEmail(): string
    {
        return $this->platformSetting('platform_sender_email')
            ?: (config('mail.from.address') ?: 'no-reply@talent360.com.mx');
    }

    public function platformWelcomeSubject(): string
    {
        return $this->platformSetting('platform_welcome_email_subject')
            ?: '¡Bienvenido a Talent360!';
    }

    public function platformWelcomeBody(): string
    {
        return $this->platformSetting('platform_welcome_email_body')
            ?: "<p>Gracias por registrar <strong>{company_name}</strong> en Talent360.</p>"
             . "<p>Tu plan actual es <strong>{plan}</strong>.</p>";
    }

    /**
     * Dirección "From" para un correo dirigido a colaboradores de un tenant:
     * remitente real del sistema + nombre para mostrar "{Empresa} vía Talent360".
     */
    public function tenantFromAddress(int $tenantId): Address
    {
        $displayName = $this->tenantSetting($tenantId, 'sender_display_name')
            ?: (DB::table('tenants')->where('id', $tenantId)->value('name') ?: 'Talent360');

        return new Address($this->platformSenderEmail(), $displayName . ' vía Talent360');
    }

    /** Dirección "From" institucional de la plataforma (bienvenida a empresas nuevas). */
    public function platformFromAddress(): Address
    {
        return new Address($this->platformSenderEmail(), 'Talent360');
    }

    /** Reply-To real del tenant (para que si el empleado responde le llegue a su empresa). */
    public function tenantReplyTo(int $tenantId): ?Address
    {
        $replyTo = $this->tenantSetting($tenantId, 'sender_reply_to');
        return $replyTo ? new Address($replyTo) : null;
    }
}
