<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ENCOLADO (2026-08-26). Antes se enviaba EN LÍNEA: si el servidor de correo tardaba ocho
 * segundos, el alta del colaborador tardaba ocho segundos, y si el correo estaba caído la
 * petición fallaba entera. Un correo no puede tumbar un alta.
 *
 * `SerializesModels` va puesto por si algún día se le pasa un modelo: hoy recibe cadenas y
 * objetos `Address`, que serializan sin problema.
 */
class AbandonmentRecoveryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $adminName;
    public $companyName;
    public $planName;
    public $checkoutUrl;

    /**
     * Create a new message instance.
     */
    public function __construct($adminName, $companyName, $planName, $checkoutUrl)
    {
        $this->adminName = $adminName ?: 'Hola';
        $this->companyName = $companyName ?: 'tu empresa';
        $this->planName = ucfirst($planName ?: 'Pro');
        $this->checkoutUrl = $checkoutUrl ?: env('FRONTEND_URL', 'http://localhost:5173') . '/register';
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('¡Tu registro en Talent 360 casi está listo! 🚀')
                    ->html($this->getEmailHtml());
    }

    /**
     * Generar HTML responsivo y profesional para recuperación de suscripción.
     */
    private function getEmailHtml()
    {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Completa tu registro en Talent 360</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                    background-color: #f8fafc;
                    margin: 0;
                    padding: 0;
                    -webkit-font-smoothing: antialiased;
                }
                .wrapper {
                    width: 100%;
                    table-layout: fixed;
                    background-color: #f8fafc;
                    padding-bottom: 40px;
                }
                .main {
                    background-color: #ffffff;
                    margin: 0 auto;
                    width: 100%;
                    max-width: 600px;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
                    border: 1px solid #e2e8f0;
                }
                .header {
                    background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
                    padding: 32px 24px;
                    text-align: center;
                    color: #ffffff;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                    font-weight: 700;
                    letter-spacing: -0.5px;
                }
                .content {
                    padding: 32px 24px;
                    color: #334155;
                    font-size: 16px;
                    line-height: 1.6;
                }
                .badge {
                    display: inline-block;
                    background-color: #eff6ff;
                    color: #2563eb;
                    font-weight: 600;
                    padding: 6px 12px;
                    border-radius: 20px;
                    font-size: 14px;
                    margin-bottom: 16px;
                }
                .cta-button {
                    display: inline-block;
                    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
                    color: #ffffff !important;
                    text-decoration: none;
                    font-weight: 600;
                    font-size: 16px;
                    padding: 14px 28px;
                    border-radius: 8px;
                    margin: 24px 0;
                    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
                }
                .footer {
                    background-color: #f1f5f9;
                    padding: 24px;
                    text-align: center;
                    font-size: 13px;
                    color: #64748b;
                    border-top: 1px solid #e2e8f0;
                }
            </style>
        </head>
        <body>
            <div class="wrapper">
                <br>
                <div class="main">
                    <div class="header">
                        <h1>Talent 360</h1>
                        <p style="margin: 4px 0 0 0; opacity: 0.9; font-size: 14px;">Gestión Inteligente de Recursos Humanos</p>
                    </div>
                    <div class="content">
                        <div class="badge">Suscripción Pendiente</div>
                        <h2 style="margin-top: 0; color: #0f172a;">¡Hola, ' . htmlspecialchars($this->adminName) . '!</h2>
                        <p>Notamos que comenzaste el proceso para activar el plan <strong>' . htmlspecialchars($this->planName) . '</strong> para tu empresa <strong>' . htmlspecialchars($this->companyName) . '</strong>, pero tu registro no pudo ser completado.</p>
                        <p>¡No te preocupes! Tus datos siguen seguros y guardados para que no tengas que escribir todo otra vez.</p>
                        
                        <div style="text-align: center;">
                            <a href="' . htmlspecialchars($this->checkoutUrl) . '" class="cta-button">Completar mi Suscripción en 1 Clic &rarr;</a>
                        </div>

                        <p style="font-size: 14px; color: #64748b;">Si tuviste algún problema con tu método de pago o tienes alguna duda antes de comenzar, estamos listos para apoyarte.</p>
                    </div>
                    <div class="footer">
                        <p style="margin: 0;">© ' . date('Y') . ' Talent 360. Todos los derechos reservados.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
    }
}
