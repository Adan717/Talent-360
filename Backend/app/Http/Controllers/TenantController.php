<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Database\Seeders\TenantSeeder;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Auth;

class TenantController extends Controller
{
    public function store(Request $request)
    {
        if ($request->has('subdomain') && !$request->has('admin_email')) {
            $request->merge([
                'admin_email' => 'admin@' . $request->subdomain . '.com'
            ]);
        }

        $request->validate([
            'subdomain' => [
                'required', 'string',
                \Illuminate\Validation\Rule::unique('tenants', 'subdomain')->withoutTrashed(),
                \Illuminate\Validation\Rule::unique('tenants', 'public_slug')->withoutTrashed()
            ],
            'plan' => 'required|string',
            'company_name' => 'required|string',
            'admin_name' => 'required|string',
            'admin_email' => 'required|email',
            'admin_password' => 'required|min:6'
        ]);

        $plan = strtolower($request->plan);
        $ip = $request->ip();

        // Prevent abuse: Max 2 free registrations per IP in the last 30 days
        if ($plan === 'freemium') {
            $freeCount = DB::table('device_registrations')
                ->join('tenants', 'device_registrations.tenant_id', '=', 'tenants.id')
                ->where('device_registrations.ip_address', $ip)
                ->where('tenants.plan', 'freemium')
                ->where('device_registrations.created_at', '>=', now()->subDays(30))
                ->count();

            if ($freeCount >= 2) {
                return response()->json([
                    'error' => 'Registration Limit Exceeded',
                    'message' => 'Has superado el límite de registros de cuentas gratuitas desde esta red en los últimos 30 días. Por favor, ponte en contacto con nuestro equipo de ventas para asistencia.'
                ], 403);
            }
        }
        try {
            DB::beginTransaction();

            $trialConfig = DB::table('system_settings')
                ->whereNull('tenant_id')
                ->where('key', 'global_trial_days')
                ->first();
            $trialDays = $trialConfig ? (int)$trialConfig->value : 30;

            $baseSlug = \Illuminate\Support\Str::slug($request->subdomain);
            $publicSlug = $baseSlug;
            $slugIndex = 1;
            while (Tenant::withTrashed()->where('public_slug', $publicSlug)->exists()) {
                $publicSlug = $baseSlug . '-' . $slugIndex++;
            }

            // 1. Create Tenant with Dynamic Free Trial
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'subdomain' => $request->subdomain,
                'plan' => $plan,
                'max_users' => Tenant::maxUsersForPlan($plan),
                'public_slug' => $publicSlug,
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays)
            ]);

            // Set context for traits
            session(['tenant_id' => $tenant->id]);

            // Create corresponding Company record for welcome settings and legacy compatibility
            DB::table('companies')->insert([
                'id' => $tenant->id,
                'name' => $request->company_name,
                'domain' => $request->subdomain,
                'is_active' => true,
                'subscription_tier' => $plan === 'freemium' ? 'free' : $plan,
                'trial_ends_at' => now()->addDays($trialDays),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::table('system_settings')->updateOrInsert(
                ['key' => 'onboarding_completed', 'tenant_id' => $tenant->id],
                ['value' => json_encode(false), 'created_at' => now(), 'updated_at' => now()]
            );

            // 2. Associate or Create Admin User
            $adminEmail = strtolower(trim($request->admin_email));
            $existingAdmin = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->withTrashed()
                ->where('email', $adminEmail)
                ->first();

            if ($existingAdmin) {
                if ($existingAdmin->tenant_id !== null) {
                    $existingTenant = Tenant::find($existingAdmin->tenant_id);
                    if ($existingTenant && !$existingTenant->trashed()) {
                        return response()->json([
                            'error' => "El correo {$adminEmail} ya está registrado y pertenece a la empresa '{$existingTenant->name}'."
                        ], 409);
                    }
                }
                if ($existingAdmin->trashed()) {
                    $existingAdmin->restore();
                }
                $existingAdmin->update([
                    'name' => $request->admin_name,
                    'password' => Hash::make($request->admin_password),
                    'role' => UserRole::ADMIN->value,
                    'tenant_id' => $tenant->id,
                    'is_active' => true
                ]);
                $admin = $existingAdmin;
            } else {
                $admin = User::create([
                    'name' => $request->admin_name,
                    'email' => $adminEmail,
                    'password' => Hash::make($request->admin_password),
                    'role' => UserRole::ADMIN->value,
                    'tenant_id' => $tenant->id,
                    'is_active' => true
                ]);
            }

            // 3. Register Device
            DB::table('device_registrations')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $admin->id,
                'device_fingerprint' => $request->header('X-Device-Fingerprint', 'unknown_fingerprint'),
                'ip_address' => $ip,
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();

            // Enviar Correo de Bienvenida de Forma Segura
            try {
                \Illuminate\Support\Facades\Mail::to($admin->email)->send(
                    new \App\Mail\WelcomeMail($admin->name, $tenant->name, $tenant->subdomain)
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Falla al enviar correo de bienvenida a {$admin->email}: " . $e->getMessage());
            }

            // Login Admin automatically
            $token = $admin->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Empresa registrada con éxito',
                'tenant' => $tenant,
                'user' => $admin,
                'token' => $token
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al crear la cuenta: ' . $e->getMessage()], 500);
        }
    }
}
