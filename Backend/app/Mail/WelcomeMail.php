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
class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $userName;
    public $companyName;
    public $subdomain;

    /**
     * Create a new message instance.
     */
    public function __construct($userName, $companyName, $subdomain)
    {
        $this->userName = $userName;
        $this->companyName = $companyName;
        $this->subdomain = $subdomain;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('¡Bienvenido a Talent 360! 🚀')
                    ->html($this->getEmailHtml());
    }

    /**
     * Generar HTML espectacular y responsivo para el correo de bienvenida.
     */
    private function getEmailHtml()
    {
        $loginUrl = "http://" . $this->subdomain . ".talent360.com";
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Bienvenido a Talent 360</title>
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
                    padding: 40px 0;
                }
                .container {
                    max-width: 600px;
                    background-color: #ffffff;
                    margin: 0 auto;
                    border-radius: 24px;
                    overflow: hidden;
                    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02);
                    border: 1px border #f1f5f9;
                }
                .header {
                    background: linear-gradient(135deg, #3b82f6 0%, #4f46e5 100%);
                    padding: 40px 30px;
                    text-align: center;
                    color: #ffffff;
                }
                .header h1 {
                    margin: 10px 0 0 0;
                    font-size: 26px;
                    font-weight: 800;
                    letter-spacing: -0.5px;
                }
                .content {
                    padding: 40px 30px;
                    color: #334155;
                    line-height: 1.6;
                }
                .content h2 {
                    font-size: 20px;
                    font-weight: 700;
                    margin-top: 0;
                    color: #0f172a;
                }
                .card {
                    background-color: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 16px;
                    padding: 20px;
                    margin: 25px 0;
                }
                .card-title {
                    font-weight: 800;
                    text-transform: uppercase;
                    font-size: 11px;
                    color: #64748b;
                    letter-spacing: 1px;
                    margin-bottom: 8px;
                }
                .card-value {
                    font-size: 15px;
                    font-weight: 700;
                    color: #1e293b;
                }
                .btn {
                    display: inline-block;
                    background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
                    color: #ffffff !important;
                    text-decoration: none;
                    font-weight: 700;
                    padding: 14px 30px;
                    border-radius: 12px;
                    font-size: 14px;
                    text-align: center;
                    box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
                    margin-top: 15px;
                }
                .footer {
                    background-color: #f1f5f9;
                    padding: 20px;
                    text-align: center;
                    font-size: 12px;
                    color: #64748b;
                    border-top: 1px solid #e2e8f0;
                }
            </style>
        </head>
        <body>
            <div class="wrapper">
                <div class="container">
                    <div class="header">
                        <h1>Talent 360</h1>
                    </div>
                    <div class="content">
                        <h2>¡Hola, ' . htmlspecialchars($this->userName) . '!</h2>
                        <p>Nos emociona mucho darte la bienvenida a <strong>Talent 360</strong>, la plataforma todo en uno para optimizar tu capital humano y control de asistencia.</p>
                        <p>Hemos creado tu cuenta de administrador de forma exitosa. Tu período de prueba de <strong>14 días gratuitos</strong> ha comenzado.</p>
                        
                        <div class="card">
                            <div class="card-title">Empresa Registrada</div>
                            <div class="card-value">' . htmlspecialchars($this->companyName) . '</div>
                            
                            <div class="card-title" style="margin-top:15px;">Tu Subdominio Exclusivo</div>
                            <div class="card-value">' . htmlspecialchars($this->subdomain) . '.talent360.com</div>
                        </div>

                        <p>Para ingresar al panel de control y que tus empleados puedan comenzar a registrar su asistencia, utiliza el siguiente enlace:</p>
                        
                        <div style="text-align: center;">
                            <a href="' . $loginUrl . '" class="btn">Ingresar a mi Plataforma</a>
                        </div>
                        
                        <p style="margin-top: 25px; font-size: 13px; color: #64748b;">Si tienes alguna duda o necesitas ayuda para iniciar tu configuración, responde a este correo o comunícate con nuestro equipo de soporte.</p>
                    </div>
                    <div class="container-footer">
                        <div class="footer">
                            &copy; ' . date('Y') . ' Talent 360. Todos los derechos reservados.
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ';
    }
}
