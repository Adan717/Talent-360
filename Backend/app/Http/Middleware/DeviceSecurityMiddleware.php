<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class DeviceSecurityMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $fingerprint = $request->header('X-Device-Fingerprint');

        // (2026-09-05) Aquí había una exención para 'api/health' y 'api/v1/health' que NO
        // eximía de nada: este middleware sólo se aplica al grupo `v1` (routes/api.php), donde
        // /health nunca ha vivido —vive fuera del prefijo— y 'api/v1/health' no existe. Dos
        // condiciones imposibles que además hacían creer que el health check estaba protegido
        // de algo. Se borra: /api/health no pasa por este middleware, punto.

        $query = DB::table('device_registrations')
            ->where('is_banned', true)
            ->where(function($q) use ($ip, $fingerprint) {
                $q->where('ip_address', $ip);
                if (!empty($fingerprint)) {
                    $q->orWhere('device_fingerprint', $fingerprint);
                }
            });

        $banned = $query->first();

        if ($banned) {
            return response()->json([
                'error' => 'Device Banned',
                'message' => 'Este dispositivo o red ha sido suspendido temporalmente por violar las políticas de seguridad y abuso de la plataforma. Razón: ' . ($banned->ban_reason ?? 'Políticas de uso.')
            ], 403);
        }

        return $next($request);
    }
}
