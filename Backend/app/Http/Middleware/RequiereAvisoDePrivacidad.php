<?php

namespace App\Http\Middleware;

use App\Support\AvisoDePrivacidad;
use Closure;
use Illuminate\Http\Request;

/**
 * Gate de consentimiento del aviso de privacidad (2026-09-05).
 *
 * Mismo molde que ForcePasswordChange, y por la misma razón: es la ÚNICA forma de garantizar que
 * nadie trabaje meses dentro del sistema sin haber visto el aviso. Un banner que se cierra no deja
 * constancia; una pantalla de un toque, sí.
 *
 * ORDEN CON ForcePasswordChange: éste se registra DESPUÉS, así que una cuenta con las dos marcas
 * primero cambia su contraseña y luego acepta. Por eso `api/v1/me/change-password` está en las
 * permitidas de AQUÍ: sin ella las dos marcas juntas se bloquean mutuamente (no podría cambiar la
 * contraseña por el aviso ni aceptar el aviso por la contraseña).
 */
class RequiereAvisoDePrivacidad
{
    /** Lo único que una cuenta sin consentimiento sí puede hacer. */
    private const PERMITIDAS = [
        // Aceptar (y consultar el estado) — la salida del candado.
        'api/v1/me/consentimiento',
        // Leerse a sí misma: es lo que le permite al frontend saber que hay que pintar la pantalla.
        'api/v1/me',
        // Ver arriba: el candado de contraseña corre antes que éste.
        'api/v1/me/change-password',
        'api/v1/logout',
        'api/v1/clock/kiosk-logout',
        // El kiosco identifica al empleado por SU PIN; la sesión sólo ancla el tenant. Bloquearlo
        // por el consentimiento pendiente del ancla dejaría sin fichar a toda la tienda por una
        // pantalla que esa persona ni siquiera vio (mismo criterio que ForcePasswordChange).
        'api/v1/kiosk/punch',
        'api/v1/kiosk/consentimiento',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('sanctum') ?? $request->user();

        if ($user
            && AvisoDePrivacidad::usuarioDebeAceptar($user)
            && !in_array($request->path(), self::PERMITIDAS, true)) {
            return response()->json([
                'error' => 'Debes aceptar el Aviso de Privacidad antes de continuar.',
                'code' => 'privacidad_pendiente',
                'version' => AvisoDePrivacidad::VERSION,
            ], 403);
        }

        return $next($request);
    }
}
