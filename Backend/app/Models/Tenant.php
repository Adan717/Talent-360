<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $fillable = [
        'name', 'subdomain', 'plan', 'max_users',
        'public_slug', 'brand_color', 'logo_url', 'public_portal_enabled',
        'portal_custom_settings_json',
        'subscription_status', 'trial_ends_at',
        'stripe_customer_id', 'stripe_subscription_id', 'stripe_price_id',
        'billing_plan_id', 'allowed_modules_json',
        'rfc', 'tax_name', 'tax_regimen', 'postal_code',
        'csd_certificate', 'csd_private_key', 'csd_password',
        'facturapi_organization_id'
    ];

    // Secretos que NUNCA deben salir en un toArray()/JSON (merge F3): los passcodes hasheados de
    // la Wiki pública (R-passcodes) y el certificado/llave del CSD de facturación.
    protected $hidden = [
        'org_vault_admin_passcode_hash',
        'org_vault_viewer_passcode_hash',
        'csd_certificate', 'csd_private_key', 'csd_password',
    ];

    protected $casts = [
        'public_portal_enabled' => 'boolean',
        'allowed_modules_json' => 'array',
        'csd_certificate' => 'encrypted',
        'csd_private_key' => 'encrypted',
        'csd_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($tenant) {
            // Se envuelve en una transacción anidada (SAVEPOINT): si la inicialización
            // falla estando dentro de la transacción de registro de empresa, el rollback
            // llega solo hasta el savepoint y NO envenena la transacción padre (evita el
            // SQLSTATE[25P02] que antes bloqueaba todo el registro). El error se registra
            // pero no tumba la creación del tenant.
            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($tenant) {
                    app(\App\Services\TenantInitializationService::class)->initializeSettingsForTenant($tenant->id);
                });
            } catch (\Throwable $e) {
                \Log::error("Error al inicializar configuraciones para tenant {$tenant->id}: " . $e->getMessage());
            }
        });
    }

    /**
     * Cupo de usuarios por defecto según el plan (§58). Fuente única de verdad para
     * evitar que cada controlador invente su propio número (antes había 5, 10 y 9999
     * dispersos). El límite del freemium se lee de la configuración global de la
     * plataforma (system_settings.freemium_max_users, tenant_id NULL) igual que
     * freemium_allowed_modules/features; si no está definida, cae a 5 (lo que anuncia
     * la landing).
     */
    public static function maxUsersForPlan(?string $plan, ?int $employees = null): int
    {
        $plan = strtolower((string) $plan);

        if ($plan === 'freemium') {
            $config = \DB::table('system_settings')
                ->whereNull('tenant_id')
                ->where('key', 'freemium_max_users')
                ->first();
            $value = $config ? (int) $config->value : 0;
            return $value > 0 ? $value : 5;
        }

        if ($plan === 'pro') {
            return ($employees && $employees > 0) ? $employees : 50;
        }

        // enterprise / cualquier otro: sin límite práctico.
        return 9999;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function billingPlan()
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    /**
     * Check if the tenant's free trial is currently active.
     */
    public function isTrialActive()
    {
        if ($this->subscription_status === 'cancelled') {
            return false;
        }
        if ($this->subscription_status === 'active') {
            return false;
        }
        if (!$this->trial_ends_at) {
            return false;
        }
        try {
            return now()->lessThanOrEqualTo(\Carbon\Carbon::parse($this->trial_ends_at));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a specific module is unlocked for this tenant.
     */
    public function isModuleUnlocked($moduleId)
    {
        if ((int)$this->id === 1 || $this->subdomain === 'talent360') {
            return true;
        }

        // 1. Check if module was purchased individually (Marketplace)
        $allowedModules = $this->allowed_modules_json ?: [];
        if (in_array($moduleId, $allowedModules)) {
            return true;
        }

        // 2. Check if the tenant is linked to a Billing Plan
        if ($this->billingPlan) {
            $planCode = strtolower($this->billingPlan->code);
            if ($planCode === 'enterprise') {
                return true;
            }
            if ($this->isTrialActive()) {
                return true;
            }
            
            $planFeatures = $this->billingPlan->features_json ?: [];
            $planModules = $planFeatures['modules'] ?? [];
            if (in_array($moduleId, $planModules)) {
                return true;
            }
        }

        // Fallback to legacy string-based plan if no billingPlan relation is set
        if ($this->plan === 'enterprise') {
            return true;
        }
        if ($this->isTrialActive()) {
            return true;
        }
        if ($this->plan === 'pro') {
            $proAllowed = ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'portal', 'documentos', 'academia'];
            return in_array($moduleId, $proAllowed);
        }
        
        $config = \DB::table('system_settings')
            ->whereNull('tenant_id')
            ->where('key', 'freemium_allowed_modules')
            ->first();
        $allowed = $config ? (json_decode($config->value, true) ?: ['reloj', 'rrhh', 'operativo']) : ['reloj', 'rrhh', 'operativo'];
        return in_array($moduleId, $allowed);
    }

    /**
     * Check if a specific feature is unlocked for this tenant.
     */
    public function isFeatureUnlocked($featureId)
    {
        if ((int)$this->id === 1 || $this->subdomain === 'talent360') {
            return true;
        }

        if ($this->billingPlan) {
            $planCode = strtolower($this->billingPlan->code);
            if ($planCode === 'enterprise') {
                return true;
            }
            if ($this->isTrialActive()) {
                return true;
            }
            
            $planFeatures = $this->billingPlan->features_json ?: [];
            $planFeaturesList = $planFeatures['features'] ?? [];
            if (in_array($featureId, $planFeaturesList)) {
                return true;
            }
        }

        if ($this->plan === 'enterprise') {
            return true;
        }
        if ($this->isTrialActive()) {
            return true;
        }
        if ($this->plan === 'pro') {
            return true;
        }
        
        $config = \DB::table('system_settings')
            ->whereNull('tenant_id')
            ->where('key', 'freemium_allowed_features')
            ->first();
        $allowed = $config ? (json_decode($config->value, true) ?: []) : [];
        return in_array($featureId, $allowed);
    }
}
