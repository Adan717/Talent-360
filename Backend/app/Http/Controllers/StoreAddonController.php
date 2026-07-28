<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ModuleAddon;
use App\Models\SeasonalPromotion;
use App\Models\TenantModuleSubscription;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StoreAddonController extends Controller
{
    /**
     * Catálogo de Add-ons y estado de suscripción del Tenant
     */
    public function index(Request $request)
    {
        $tenant = $request->user()?->tenant;
        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        $addons = ModuleAddon::where('is_active', true)->get();

        $subscriptions = TenantModuleSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['pending_approval', 'active'])
            ->get()
            ->keyBy('module_key');

        $activeModulesConfig = DB::table('system_settings')
            ->where('tenant_id', $tenant->id)
            ->where('key', 'active_modules')
            ->first();

        $adoptedKeys = $activeModulesConfig ? (json_decode($activeModulesConfig->value, true) ?: []) : [];

        $items = $addons->map(function ($addon) use ($subscriptions, $adoptedKeys) {
            $sub = $subscriptions->get($addon->module_key);
            $isAdopted = in_array($addon->module_key, $adoptedKeys);

            $status = 'available';
            $expiresAt = null;

            if ($sub) {
                if ($sub->status === 'active' && ($sub->expires_at === null || Carbon::parse($sub->expires_at)->isFuture())) {
                    $status = 'unlocked';
                    $expiresAt = $sub->expires_at;
                } elseif ($sub->status === 'pending_approval') {
                    $status = 'pending_approval';
                }
            } elseif ($isAdopted) {
                $status = 'unlocked';
            }

            return [
                'id' => $addon->id,
                'module_key' => $addon->module_key,
                'name' => $addon->name,
                'description' => $addon->description,
                'price_per_employee' => (float)$addon->price_per_employee,
                'min_monthly_price' => (float)$addon->min_monthly_price,
                'icon_name' => $addon->icon_name,
                'status' => $status,
                'expires_at' => $expiresAt,
            ];
        });

        return response()->json([
            'addons' => $items,
            'plan' => strtolower($tenant->plan ?? 'starter'),
        ]);
    }

    /**
     * Promoción de temporada activa
     */
    public function activePromotion(Request $request)
    {
        $promo = SeasonalPromotion::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderBy('id', 'desc')
            ->first();

        return response()->json([
            'promotion' => $promo
        ]);
    }

    /**
     * Solicitar tiempo de gracia por difusión en redes sociales
     */
    public function claimSocialGrace(Request $request)
    {
        $validated = $request->validate([
            'module_key' => 'required|string',
            'proof_url' => 'nullable|string',
            'proof_note' => 'nullable|string',
        ]);

        $tenant = $request->user()?->tenant;
        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        // Obtener la configuración global de días de gracia (default 30)
        $graceConfig = DB::table('system_settings')
            ->whereNull('tenant_id')
            ->where('key', 'social_grace_days')
            ->first();
        $graceDays = $graceConfig ? (int)$graceConfig->value : 30;

        return DB::transaction(function () use ($tenant, $validated, $graceDays) {
            $sub = TenantModuleSubscription::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'module_key' => $validated['module_key'],
                ],
                [
                    'access_type' => 'social_grace_period',
                    'grace_days_granted' => $graceDays,
                    'proof_url' => $validated['proof_url'] ?? null,
                    'proof_note' => $validated['proof_note'] ?? null,
                    'status' => 'pending_approval',
                    'expires_at' => null,
                ]
            );

            return response()->json([
                'message' => 'Solicitud de difusión enviada con éxito. En breve será aprobada por el equipo de soporte.',
                'subscription' => $sub
            ]);
        });
    }

    /**
     * Contratar un módulo a la carta (Add-on)
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'module_key' => 'required|string',
        ]);

        $tenant = $request->user()?->tenant;
        if (!$tenant) {
            return response()->json(['error' => 'Tenant no encontrado'], 404);
        }

        return DB::transaction(function () use ($tenant, $validated) {
            $sub = TenantModuleSubscription::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'module_key' => $validated['module_key'],
                ],
                [
                    'access_type' => 'addon_paid',
                    'status' => 'active',
                    'expires_at' => null, // Suscripción continua recurrente
                ]
            );

            // Sincronizar active_modules en system_settings del tenant
            $tenantConfig = DB::table('system_settings')
                ->where('tenant_id', $tenant->id)
                ->where('key', 'active_modules')
                ->first();

            $activeMods = $tenantConfig ? (json_decode($tenantConfig->value, true) ?: []) : [];
            if (!in_array($validated['module_key'], $activeMods)) {
                $activeMods[] = $validated['module_key'];
                DB::table('system_settings')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'key' => 'active_modules'],
                    ['value' => json_encode(array_values($activeMods)), 'updated_at' => now()]
                );
            }

            return response()->json([
                'message' => 'Módulo contratado e insertado correctamente.',
                'subscription' => $sub
            ]);
        });
    }
}
