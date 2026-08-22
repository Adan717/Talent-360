<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * §65: verificación de capacidades delegables por puesto.
 *
 * Va EN PARALELO a RoleMiddleware (`role:`), no lo reemplaza — la migración de rutas
 * de `role:` a `permission:` se hace por bloques sin romper lo actual de golpe.
 *
 * Reglas:
 *  - El `admin` del tenant pasa SIEMPRE. Toda la configuración nace en el administrador
 *    dueño (decisión de producto de Francisco), así que no necesita filas en
 *    role_permissions ni puede quedarse fuera de su propia empresa.
 *  - Cualquier otro rol pasa solo si el PUESTO del usuario (employees.job_role_id) tiene
 *    asignada al menos una de las capacidades pedidas en role_permissions (semántica OR,
 *    igual que `role:a,b`).
 *  - Las capacidades INDELEGABLES (identidad fiscal, plan/suscripción, borrar empresa,
 *    otorgar permisos) nunca se gatean con este middleware: se dejan en `role:admin`,
 *    de modo que ningún permiso pueda otorgarlas aunque el admin quisiera.
 */
class PermissionMiddleware
{
    /**
     * ¿Este usuario tiene alguna de estas capacidades? MISMA regla que `handle` (el admin
     * pasa siempre; los demás por el puesto). Se expone para que las pantallas no ofrezcan
     * botones que el servidor va a rechazar — sin escribir una segunda versión de la regla.
     */
    public static function usuarioTiene($user, string ...$capabilities): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->role === \App\Enums\UserRole::ADMIN->value) {
            return true;
        }

        $jobRoleId = DB::table('employees')->where('user_id', $user->id)->value('job_role_id');

        $puestoConfigurado = false;
        if ($jobRoleId) {
            $concedidas = DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.tenant_id', $user->tenant_id)
                ->where('role_permissions.job_role_id', $jobRoleId);

            if ((clone $concedidas)->whereIn('permissions.name', $capabilities)->exists()) {
                return true;
            }

            $puestoConfigurado = $concedidas->exists();
        }

        // Puesto de mando SIN CONFIGURAR: vale el set conservador de SUPERVISOR_DEFAULTS.
        //
        // (2026-08-22) La migración 2026_07_26_000001 repartió esos permisos a los puestos de las
        // empresas que YA existían ese día; a las nuevas no se los da nadie. La empresa de la
        // prueba tenía 11 puestos y CERO filas en role_permissions, y como la matriz
        // (GET/PUT /admin/permissions/matrix) no la consume ninguna pantalla, tampoco había forma
        // de otorgarlos: la supervisora recibía 403 al validar una tarea o al ver el monitor — su
        // rol era decorativo.
        //
        // Aplica cuando el puesto no tiene NINGUNA capacidad asignada (o no hay puesto todavía). En cuanto el admin le
        // concede aunque sea una, manda la matriz y nada más: así un puesto de mando al que se le
        // dio sólo `view_reports` (una capacidad de LECTURA) sigue sin poder cerrar turnos ni
        // escribirle al equipo, que fue un hallazgo de la ronda del Monitor.
        if ($user->role === \App\Enums\UserRole::SUPERVISOR->value
            && array_intersect($capabilities, \App\Support\PermissionCatalog::SUPERVISOR_DEFAULTS)
            && !$puestoConfigurado) {
            return true;
        }

        return false;
    }

    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Una sola versión de la regla: la de usuarioTiene() (antes estaba copiada aquí, y la
        // pantalla podía ofrecer un botón que el servidor rechazaba, o al revés).
        if (self::usuarioTiene($user, ...$capabilities)) {
            return $next($request);
        }

        $tenantId = $user->tenant_id;

        \App\Helpers\SecurityLogger::log(
            'permission_denied',
            'Acceso denegado a ' . $request->path() . ' — requiere una de: ' . implode(', ', $capabilities),
            $tenantId,
            $user->id
        );

        return response()->json([
            'message' => 'Acceso denegado. Tu puesto no tiene la capacidad requerida para esta acción.'
        ], 403);
    }
}
