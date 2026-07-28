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

            // 1. Check if trial is active or plan is Enterprise
            $plan = strtolower($tenant->plan ?? 'starter');
            if ($plan === 'freemium') {
                $plan = 'starter';
            }

            if ($plan === 'enterprise') {
                return $next($request);
            }

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

            // 2. Base plan allowed modules
            if ($plan === 'starter') {
                $globalConfig = DB::table('system_settings')
                    ->whereNull('tenant_id')
                    ->where('key', 'freemium_allowed_modules')
                    ->first();

                $allowedModules = $globalConfig 
                    ? (json_decode($globalConfig->value, true) ?: ['reloj', 'rrhh', 'operativo', 'lft', 'organizacion']) 
                    : ['reloj', 'rrhh', 'operativo', 'lft', 'organizacion'];
            } else { // pro
                $allowedModules = ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'lft', 'organizacion'];
            }

            if (in_array($module, $allowedModules)) {
                return $next($request);
            }

            // 3. Check active addon / social grace subscriptions for this tenant
            $activeSub = DB::table('tenant_module_subscriptions')
                ->where('tenant_id', $tenant->id)
                ->where('module_key', $module)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                })
                ->exists();

            if ($activeSub) {
                return $next($request);
            }

            // 4. Fallback check active_modules in tenant settings
            $tenantConfig = DB::table('system_settings')
                ->where('tenant_id', $tenant->id)
                ->where('key', 'active_modules')
                ->first();

            $adoptedModules = $tenantConfig ? (json_decode($tenantConfig->value, true) ?: []) : [];
            if (in_array($module, $adoptedModules)) {
                return $next($request);
            }

            return response()->json([
                'error' => 'Module Locked',
                'module_key' => $module,
                'message' => 'Este módulo no está incluido en tu plan actual. Puedes solicitar un tiempo de gracia o contratarlo a la carta.'
            ], 403);
        }

        return $next($request);
    }
}

