<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TenantModuleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $module
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if ($user) {
            // Bypass platform admins
            if ($user->role === UserRole::PLATFORM_ADMIN->value) {
                return $next($request);
            }

            $tenant = $user->tenant;
            if (!$tenant) {
                return $next($request);
            }

            // 1. Check if trial is active
            $trialActive = false;
            if ($tenant->subscription_status === 'trial' || empty($tenant->subscription_status)) {
                if ($tenant->trial_ends_at) {
                    $endsAt = Carbon::parse($tenant->trial_ends_at);
                    if (now()->lt($endsAt)) {
                        $trialActive = true;
                    }
                }
            }

            if ($trialActive) {
                return $next($request);
            }

            // 2. Check by plan type
            $plan = strtolower($tenant->plan ?? 'freemium');

            if ($plan === 'freemium') {
                // Read global allowed modules for Free plan
                $globalConfig = DB::table('system_settings')
                    ->whereNull('tenant_id')
                    ->where('key', 'freemium_allowed_modules')
                    ->first();

                $allowedModules = $globalConfig 
                    ? (json_decode($globalConfig->value, true) ?: ['reloj', 'rrhh', 'operativo']) 
                    : ['reloj', 'rrhh', 'operativo'];

                if (!in_array($module, $allowedModules)) {
                    return response()->json([
                        'error' => 'Module Locked',
                        'message' => 'Este módulo no está incluido en tu paquete gratuito. Por favor, actualiza al plan Profesional.'
                    ], 403);
                }
            } elseif ($plan === 'pro') {
                // Read custom active modules for this Pro tenant
                $tenantConfig = DB::table('system_settings')
                    ->where('tenant_id', $tenant->id)
                    ->where('key', 'active_modules')
                    ->first();

                $allowedModules = $tenantConfig 
                    ? (json_decode($tenantConfig->value, true) ?: ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia']) 
                    : ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia'];

                if (!in_array($module, $allowedModules)) {
                    return response()->json([
                        'error' => 'Module Locked',
                        'message' => 'Este módulo no está activo en tu plan. Por favor, actívalo en el panel de Adopción de Módulos.'
                    ], 403);
                }
            }
            // Enterprise has access to all modules
        }

        return $next($request);
    }
}
