<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user instanceof \App\Models\PlatformUser) {
            if (in_array('platform_admin', $roles) || in_array($user->role, $roles) || $user->role === 'platform_admin') {
                return $next($request);
            }
        }

        if (!in_array($user->role, $roles)) {
            return response()->json(['message' => 'Acceso denegado. Permisos insuficientes.'], 403);
        }

        return $next($request);
    }
}
