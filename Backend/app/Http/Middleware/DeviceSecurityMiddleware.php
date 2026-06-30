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

        // Allow bypassing if it's superadmin or health checks
        if ($request->is('api/health') || $request->is('api/v1/health')) {
            return $next($request);
        }

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
