<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\UserRole;

class CheckTenantActive
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
        $user = $request->user();

        if ($user && $user->role !== UserRole::PLATFORM_ADMIN->value) {
            $tenant = $user->tenant;
            if ($tenant && !$tenant->is_active) {
                return response()->json([
                    'error' => 'Empresa suspendida',
                    'message' => 'El acceso a esta empresa ha sido suspendido temporalmente por el administrador de la plataforma.'
                ], 403);
            }
        }

        return $next($request);
    }
}
