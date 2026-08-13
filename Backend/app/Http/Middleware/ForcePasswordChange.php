<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Bloque 1 (2026-08-13): una cuenta marcada con `must_change_password` no puede usar
 * NINGUNA ruta salvo cambiar su contraseña (y cerrar sesión). Va en el grupo `api`
 * completo —no grupo por grupo— para que un grupo de rutas nuevo no lo olvide.
 */
class ForcePasswordChange
{
    /** Lo único que una cuenta marcada sí puede hacer. */
    private const PERMITIDAS = [
        'api/v1/me/change-password',
        'api/v1/logout',
        'api/v1/clock/kiosk-logout',
        // El ponche de kiosco identifica al empleado por SU PIN; la sesión sólo ancla el
        // tenant. Bloquearlo por la marca del ancla dejaría sin fichar a toda la tienda
        // por la contraseña vieja de UNA persona (y no expone ningún dato del ancla).
        'api/v1/kiosk/punch',
    ];

    public function handle(Request $request, Closure $next)
    {
        // Sin middleware de auth todavía: se resuelve el guard de Sanctum directo (el guard
        // memoiza, así que auth:sanctum no repite la consulta). En pruebas actingAs llena
        // el guard por defecto — de ahí el segundo intento. Aplica a users Y platform_users
        // (ambos tienen la columna; el `?? false` cubre cualquier otro Authenticatable).
        $user = $request->user('sanctum') ?? $request->user();

        if ($user
            && ($user->must_change_password ?? false)
            && !in_array($request->path(), self::PERMITIDAS, true)) {
            return response()->json([
                'error' => 'Debes cambiar tu contraseña antes de continuar.',
                'code' => 'must_change_password',
            ], 403);
        }

        return $next($request);
    }
}
