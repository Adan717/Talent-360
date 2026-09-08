<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\PendingRegistration;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Aprovisionar una empresa que YA PAGO: crear (o mejorar) el inquilino, su admin y su ciclo de
 * cobro.
 *
 * Vive en un trait -igual que `ArmaReportesCsv` con los reportes- porque tiene que ser el MISMO
 * codigo para todas las pasarelas: lo usan `SubscriptionController` (Mercado Pago y el simulador
 * local) y `StripeWebhookController` (tarjeta, la pasarela elegida el 2026-09-06). Dos copias de
 * esto serian dos formas distintas de nacer una empresa, y este proyecto ya pago ese precio
 * varias veces (dos tabuladores de precios, dos bocas del embudo de reportes).
 */
trait AprovisionaEmpresas
{
    /**
     * Helper to provision a tenant
     *
     * OJO con `current_period_end` y `mp_subscription_id`: NO son $fillable (a propósito), así que
     * dentro de un `update([...])`/`create([...])` se caían EN SILENCIO — ninguna empresa recibía
     * fecha de corte y el barrido de mora no podía mirarla nunca. Se estampan aparte con
     * `Tenant::estampaCicloDeCobro()`, que es la única vía para esa columna.
     */
    protected function provisionTenant(array $payload, $prefId = null)
    {
        return DB::transaction(function() use ($payload, $prefId) {
            // El periodo concedido es el mismo que se cobró: anual cubre un año.
            $finDePeriodo = \App\Support\Tarifario::finDelPeriodo($payload['billing_cycle'] ?? null);

            if (isset($payload['action']) && $payload['action'] === 'upgrade') {
                // Upgrade flow for existing tenant
                $tenant = Tenant::findOrFail($payload['tenant_id']);
                $tenant->update([
                    'plan' => strtolower($payload['plan']),
                    'max_users' => Tenant::maxUsersForPlan($payload['plan'], isset($payload['employees']) ? intval($payload['employees']) : null),
                    'subscription_status' => 'active',
                ]);
                $tenant->estampaCicloDeCobro($finDePeriodo, ['mp_subscription_id' => $prefId]);

                $admin = User::where('tenant_id', $tenant->id)
                    ->where('role', UserRole::ADMIN->value)
                    ->first();

                return [
                    'tenant' => $tenant,
                    'admin' => $admin
                ];
            }

            // Standard creation flow
            // 1. Create or Reuse Tenant
            $tenant = Tenant::where('subdomain', $payload['subdomain'])->first();
            $baseSlug = Str::slug($payload['subdomain']);
            $publicSlug = $baseSlug;
            $slugIndex = 1;
            while (Tenant::withTrashed()->where('public_slug', $publicSlug)->where('id', '!=', $tenant->id ?? 0)->exists()) {
                $publicSlug = $baseSlug . '-' . $slugIndex++;
            }

            if ($tenant && $tenant->users()->count() === 0) {
                $tenant->update([
                    'name' => $payload['company_name'],
                    'plan' => strtolower($payload['plan']),
                    'max_users' => Tenant::maxUsersForPlan($payload['plan'], isset($payload['employees']) ? intval($payload['employees']) : null),
                    'public_slug' => $publicSlug,
                    'subscription_status' => 'active',
                    'trial_ends_at' => now()->addDays(14),
                ]);
            } else {
                $tenant = Tenant::create([
                    'name' => $payload['company_name'],
                    'subdomain' => $payload['subdomain'],
                    'plan' => strtolower($payload['plan']),
                    'max_users' => Tenant::maxUsersForPlan($payload['plan'], isset($payload['employees']) ? intval($payload['employees']) : null),
                    'public_slug' => $publicSlug,
                    'subscription_status' => 'active',
                    'trial_ends_at' => now()->addDays(14),
                ]);
            }

            $tenant->estampaCicloDeCobro($finDePeriodo, ['mp_subscription_id' => $prefId]);

            // Set context for traits
            session(['tenant_id' => $tenant->id]);

            // Explicitly set onboarding_completed to false for new tenant so OnboardingWizard is triggered
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'onboarding_completed', 'tenant_id' => $tenant->id],
                ['value' => json_encode(false), 'created_at' => now(), 'updated_at' => now()]
            );

            // 2. Associate or Create Admin User
            $currentUser = auth('sanctum')->user();
            if ($currentUser && $currentUser->tenant_id === null) {
                // Link the active Google authenticated user
                $currentUser->update([
                    'tenant_id' => $tenant->id,
                    'role' => UserRole::ADMIN->value,
                ]);
                $admin = $currentUser;
            } else {
                // Fallback: check if user already exists globally
                $admin = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->withTrashed()
                    ->where('email', $payload['admin_email'])
                    ->first();
                if ($admin) {
                    // §50: regla "1 cuenta = 1 empresa". Si esta cuenta YA pertenece a
                    // otra empresa ACTIVA, reasignarle el tenant_id la robaría.
                    // Pero si la empresa previa fue eliminada (o el usuario fue borrado lógicamente),
                    // el correo se considera huérfano y se reasigna a la nueva empresa.
                    if ($admin->tenant_id !== null) {
                        $existingTenant = Tenant::find($admin->tenant_id);
                        if ($existingTenant && !$existingTenant->trashed()) {
                            abort(409, 'Ya existe una cuenta registrada con este correo. Inicia sesión para gestionar tu empresa o usa un correo distinto.');
                        }
                    }
                    if ($admin->trashed()) {
                        $admin->restore();
                    }
                    $admin->update([
                        'tenant_id' => $tenant->id,
                        'role' => UserRole::ADMIN->value,
                        'is_active' => true
                    ]);
                } else {
                    $admin = User::create([
                        'name' => $payload['admin_name'],
                        'email' => $payload['admin_email'],
                        'password' => Hash::make(bin2hex(random_bytes(16))),
                        'role' => UserRole::ADMIN->value,
                        'tenant_id' => $tenant->id,
                    ]);
                }
            }

            // (2026-09-05) El consentimiento del alta se guardó ANTES de que existiera la empresa
            // (createPreference), con `tenant_id` en null. Ahora que existe, se le pone: sin esto la
            // constancia diría quién aceptó pero no de qué empresa es. Se busca por la cuenta y, si
            // el alta vino sin sesión, por el correo del admin.
            if ($admin) {
                \App\Models\PrivacyConsent::whereNull('tenant_id')
                    ->where(function ($q) use ($admin) {
                        $q->where('user_id', $admin->id);
                        if ($admin->email) {
                            $q->orWhere('email', strtolower($admin->email));
                        }
                    })
                    // Sólo se estampa la empresa. El titular NO se reescribe: si el alta vino sin
                    // sesión la fila ya lleva nombre y correo, y forzar aquí un `user_id` que esa
                    // cuenta ya tenga en otra fila de la misma versión chocaría con el índice único
                    // en mitad del aprovisionamiento.
                    ->update(['tenant_id' => $tenant->id]);
            }

            if (method_exists(\Auth::guard(), 'login')) {
                \Auth::login($admin);
            }

            // 3. Inject Clean Base Structure (Roles & Policies) for the new Tenant
            $seeder = new TenantSeeder();
            $seeder->run();

            if (method_exists(\Auth::guard(), 'logout')) {
                \Auth::logout();
            }

            // Mark Pending Registration as Completed
            if ($prefId) {
                PendingRegistration::where('id', $prefId)->update(['status' => 'completed']);
            } else {
                $adminEmail = strtolower($payload['admin_email'] ?? '');
                $subdomain = strtolower($payload['subdomain'] ?? '');
                if ($adminEmail || $subdomain) {
                    PendingRegistration::where('status', 'pending')
                        ->forEmailOrSubdomain($adminEmail, $subdomain)
                        ->update(['status' => 'completed']);
                }
            }

            return [
                'tenant' => $tenant,
                'admin' => $admin
            ];
        });
    }

    /**
     * Aprovisiona a partir del registro pendiente que dejo el checkout. Es el punto de entrada de
     * los webhooks: llega la confirmacion de la pasarela con la referencia (`external_reference`
     * en Mercado Pago, `client_reference_id` en Stripe) y aqui se convierte en empresa viva.
     *
     * Devuelve null si la referencia no existe o ya se consumio: un webhook repetido -Stripe
     * reintenta hasta que le respondan 2xx- no puede crear dos veces la misma empresa.
     *
     * @return array{tenant: \App\Models\Tenant, admin: ?\App\Models\User}|null
     */
    protected function aprovisionarRegistroPagado(?string $referencia): ?array
    {
        if (empty($referencia)) {
            return null;
        }

        $registro = PendingRegistration::find($referencia);
        if (!$registro) {
            Log::info("Aprovisionamiento: la referencia {$referencia} no corresponde a un registro pendiente (ya se consumio?).");

            return null;
        }

        $payload = json_decode($registro->payload, true);
        if (!is_array($payload)) {
            Log::error("Aprovisionamiento: el registro pendiente {$referencia} no trae un payload legible.");

            return null;
        }

        $resultado = $this->provisionTenant($payload, $referencia);
        $registro->delete();

        return $resultado;
    }
}
