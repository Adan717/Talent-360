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

    /** Lo que se guarda en `max_users` cuando el plan no tiene tope. */
    public const SIN_TOPE = 9999;

    /**
     * Cupo de usuarios por defecto según el plan (§58).
     *
     * (2026-09-05) El cupo dejó de vivir aquí: ahora sale del mismo tabulador que los precios
     * (`billing_plans.max_users`, vía App\Support\Tarifario). Antes había TRES números para el
     * freemium sin que ninguno mandara sobre los otros: este método caía a **5** diciendo en su
     * comentario que era "lo que anuncia la landing", la landing anunciaba **10** y la pantalla
     * del cliente caía también a **10**. Y sobre todo: **nada aplica este tope** — `max_users` se
     * guarda y ningún código lo revisa. Es un número para AVISAR, no un candado (criterio del
     * dueño: "nada bloquea, todo avisa"), así que el que se conserva es el que se le prometió al
     * cliente en la landing.
     *
     * La llave vieja `system_settings.freemium_max_users` se trasladó a `billing_plans` en la
     * migración del tarifario (conservando el valor si alguna instalación lo tenía a mano) y se
     * retiró, para que no queden dos números que puedan divergir.
     */
    public static function maxUsersForPlan(?string $plan, ?int $employees = null): int
    {
        $plan = strtolower((string) $plan);

        $tope = \App\Support\Tarifario::topeDeColaboradores($plan);
        if ($tope !== null && $tope > 0) {
            return $tope;
        }

        // Plan sin tope: el cupo que se registra es el que se contrató (PRO se cobra por
        // colaborador, así que el número contratado ES la referencia).
        if ($employees && $employees > 0) {
            return $employees;
        }

        return self::SIN_TOPE;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function billingPlan()
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function subscriptionHistories()
    {
        return $this->hasMany(TenantSubscriptionHistory::class, 'tenant_id');
    }

    /**
     * Estampa hasta cuándo está pagada la empresa (y, si viene, la referencia del cobro en la
     * pasarela). La ÚNICA vía para escribir la fecha de corte: quien cobra la llama.
     *
     * EXISTE POR UN DEFECTO REAL (verificado el 2026-09-08): tanto el alta
     * (`SubscriptionController::provisionTenant`) como el webhook de Stripe escribían
     * `current_period_end` dentro de un `update([...])`, y esa columna —igual que
     * `mp_subscription_id`— NO está en $fillable a propósito, para que no se asigne en masa desde
     * una petición. La asignación masiva no falla: las TIRA EN SILENCIO. Resultado: NINGUNA
     * empresa recibió jamás fecha de corte, y sin fecha de corte `EstadoDeCobranza::decidir()`
     * responde SIN_FECHA_DE_CORTE y el barrido de mora no la mira. Es decir: el cobro existía,
     * pero la consecuencia de no pagar nunca podía dispararse.
     *
     * @param  array<string,mixed>  $referencias  columnas extra del cobro (stripe_customer_id,
     *                                            stripe_subscription_id, mp_subscription_id…).
     */
    public function estampaCicloDeCobro($finDePeriodo, array $referencias = []): void
    {
        $this->current_period_end = $finDePeriodo;

        foreach ($referencias as $columna => $valor) {
            if ($valor !== null && $valor !== '') {
                $this->{$columna} = $valor;
            }
        }

        $this->save();
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
        if ((int)$this->id === 1 || (int)$this->id === 33 || $this->subdomain === 'talent360') {
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
            $proAllowed = ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'portal', 'documentos', 'academia', 'facturacion', 'lft', 'organizacion', 'matrix'];
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
    /**
     * Las funciones de plan que existen. Lista ÚNICA, porque estaba en tres sitios distintos.
     *
     * `ClockController` preguntaba por sólo cuatro de ellas y mandaba el resultado al navegador
     * como `tenant_allowed_features`. El frontend, al recibir una lista NO vacía, la toma como
     * la verdad y ni siquiera mira el plan (`useAppStore::isFeatureUnlocked`), así que las tres
     * que faltaban quedaban apagadas para todo el mundo, enterprise incluido: `store_opening`,
     * `meal_reservation` y `enable_ley_silla`.
     *
     * Se notaba así: sin `store_opening` no aparece "Apertura de Sucursales" en Configuración,
     * no se pueden asignar portadores de llaves, y entonces NADIE puede abrir la tienda — el
     * dial se queda en "Reportar cerrado" para siempre, en una empresa nueva y sin explicación.
     *
     * Segunda vuelta (2026-08-21): la primera corrección dejó la lista en 7, pero el frontend
     * pregunta por 16 ids distintos. Cualquiera que falte aquí sale "función PRO" aunque la
     * empresa sea enterprise — así apareció "El Pase de Lista es una función PRO" (`roll_call`).
     * Hay una prueba que lee el código del frontend y exige que TODO id que pida esté aquí.
     */
    public const FUNCIONES_DEL_PLAN = [
        // Apertura y llaves
        'store_opening', 'keys_control', 'roll_call', 'emergency_open', 'door_amnesty',
        'store_closed_report',
        // Reloj
        'gps_validation', 'face_validation', 'lates_academy_block', 'enable_ley_silla',
        // Comedor
        'meal_timers', 'meal_reservation',
        // Tareas y asistentes
        'routines_management', 'checklists_validation', 'voice_commands', 'voice_assistant',
        // Plataforma
        'custom_logo', 'system_backups',
    ];

    public function isFeatureUnlocked($featureId)
    {
        if ((int)$this->id === 1 || (int)$this->id === 33 || $this->subdomain === 'talent360') {
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
