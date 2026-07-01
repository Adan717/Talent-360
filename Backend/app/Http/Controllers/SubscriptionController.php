<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\User;
use App\Models\PendingRegistration;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Database\Seeders\TenantSeeder;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    /**
     * Create MercadoPago Preference or Fallback to Simulator
     */
    public function createPreference(Request $request)
    {
        $user = auth('sanctum')->user();
        if (!$user && $request->bearerToken()) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());
            if ($tokenModel) {
                $user = \App\Models\User::withoutGlobalScope(\App\Scopes\TenantScope::class)->find($tokenModel->tokenable_id);
                if ($user) {
                    auth('sanctum')->setUser($user);
                }
            }
        }
        $isInitialRegistration = ($user && $user->tenant_id === null);
        $isUpgrade = ($user && $user->tenant_id !== null);

        if ($isUpgrade) {
            $request->validate([
                'plan' => 'required|string',
            ]);
            $tenant = Tenant::findOrFail($user->tenant_id);

            $payload = [
                'action' => 'upgrade',
                'tenant_id' => $tenant->id,
                'plan' => $request->plan,
                'company_name' => $tenant->name,
                'admin_email' => $user->email,
                'admin_name' => $user->name,
                'subdomain' => $tenant->subdomain,
            ];
        } elseif ($isInitialRegistration) {
            // Google authenticated registration flow: only company data is required
            $request->validate([
                'subdomain' => 'required|string',
                'plan' => 'required|string',
                'company_name' => 'required|string',
            ]);
            $payload = [
                'action' => 'register_initial',
                'subdomain' => $request->subdomain,
                'plan' => $request->plan,
                'company_name' => $request->company_name,
                'admin_name' => $user->name,
                'admin_email' => $user->email,
            ];
        } else {
            // Fallback for standard registration
            if ($request->has('subdomain') && !$request->has('admin_email')) {
                $request->merge([
                    'admin_email' => 'admin@' . $request->subdomain . '.com'
                ]);
            }
            $request->validate([
                'subdomain' => 'required|string',
                'plan' => 'required|string',
                'company_name' => 'required|string',
                'admin_name' => 'required|string',
                'admin_email' => 'required|email',
                'admin_password' => 'required|min:6'
            ]);
            $payload = $request->all();
        }

        $regId = (string) Str::uuid();

        // Save pending registration payload
        PendingRegistration::create([
            'id' => $regId,
            'payload' => json_encode($payload)
        ]);

        $price = $payload['plan'] === 'pro' ? 199 : ($payload['plan'] === 'enterprise' ? 499 : 0);

        // If plan is freemium and it's not upgrade, register immediately (no payment needed)
        if (!$isUpgrade && ($payload['plan'] === 'freemium' || $price <= 0)) {
            $tenant = $this->provisionTenant($payload);
            $token = $tenant['admin']->createToken('auth_token')->plainTextToken;
            return response()->json([
                'status' => 'success',
                'provisioned' => true,
                'tenant' => $tenant['tenant'],
                'user' => $tenant['admin'],
                'token' => $token
            ]);
        }

        // Try using MercadoPago SDK if configured
        $mpToken = config('mercadopago.access_token');
        if ($mpToken && !str_starts_with($mpToken, 'TEST-xxxx') && class_exists('MercadoPago\SDK')) {
            try {
                \MercadoPago\SDK::setAccessToken($mpToken);

                $preference = new \MercadoPago\Preference();
                
                $item = new \MercadoPago\Item();
                $item->title = $isUpgrade ? 'Mejora de Plan Suscripción Talent360 - Plan ' . ucfirst($payload['plan']) : 'Suscripción Talent360 - Plan ' . ucfirst($payload['plan']);
                $item->quantity = 1;
                $item->unit_price = (float)$price;
                $item->currency_id = 'MXN';

                $preference->items = array($item);
                $preference->external_reference = $regId;
                
                // Back URLs
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
                $preference->back_urls = array(
                    "success" => $request->input('success_url', $isUpgrade ? "$frontendUrl/app?payment=success&action=upgrade" : "$frontendUrl/login?payment=success"),
                    "failure" => $request->input('failure_url', $isUpgrade ? "$frontendUrl/app?payment=failed" : "$frontendUrl/register?payment=failed"),
                    "pending" => $request->input('pending_url', $isUpgrade ? "$frontendUrl/app?payment=pending" : "$frontendUrl/register?payment=pending")
                );
                $preference->auto_return = "approved";
                $preference->save();

                return response()->json([
                    'status' => 'success',
                    'init_point' => $preference->init_point,
                    'simulated' => false
                ]);
            } catch (\Exception $e) {
                // Fallback to simulator below
            }
        }

        // Fallback to simulated checkout URL
        $simulatedUrl = url('/api/subscriptions/simulated-checkout?pref_id=' . $regId);
        return response()->json([
            'status' => 'success',
            'init_point' => $simulatedUrl,
            'simulated' => true
        ]);
    }

    /**
     * Simulated Checkout HTML Page
     */
    public function simulatedCheckout(Request $request)
    {
        $prefId = $request->query('pref_id');
        $reg = PendingRegistration::findOrFail($prefId);
        $payload = json_decode($reg->payload, true);
        $plan = $payload['plan'] ?? 'pro';
        $price = $plan === 'pro' ? 199 : 499;

        $confirmUrl = url('/api/subscriptions/simulated-confirm?pref_id=' . $prefId);

        return response()->stream(function() use ($payload, $plan, $price, $confirmUrl) {
            echo "
            <!DOCTYPE html>
            <html lang='es'>
            <head>
                <meta charset='UTF-8'>
                <title>MercadoPago Sandbox - Talent360</title>
                <script src='https://cdn.tailwindcss.com'></script>
            </head>
            <body class='bg-slate-900 text-slate-100 flex items-center justify-center min-h-screen font-sans'>
                <div class='bg-slate-800 border border-slate-700 rounded-3xl p-8 max-w-md w-full shadow-2xl text-center space-y-6'>
                    <div class='w-16 h-16 bg-blue-600 rounded-2xl mx-auto flex items-center justify-center font-black text-3xl text-white shadow-lg shadow-blue-500/20'>
                        MP
                    </div>
                    <div class='space-y-2'>
                        <h2 class='text-2xl font-black tracking-tight'>Pasarela MercadoPago (Pruebas)</h2>
                        <p class='text-sm text-slate-400'>Estás pagando la suscripción de Talent360</p>
                    </div>
                    <div class='bg-slate-900/50 border border-slate-700/50 rounded-2xl p-5 text-left space-y-3 text-sm'>
                        <div class='flex justify-between'><span class='text-slate-400 font-bold'>Concepto:</span><span>Plan " . ucfirst($plan) . "</span></div>
                        <div class='flex justify-between'><span class='text-slate-400 font-bold'>Importe:</span><span class='text-emerald-400 font-black'>$" . $price . " MXN/mes</span></div>
                        <div class='flex justify-between'><span class='text-slate-400 font-bold'>Empresa:</span><span>" . htmlspecialchars($payload['company_name']) . "</span></div>
                        <div class='flex justify-between'><span class='text-slate-400 font-bold'>Admin Email:</span><span>" . htmlspecialchars($payload['admin_email']) . "</span></div>
                    </div>
                    <div class='space-y-3'>
                        <a href='{$confirmUrl}' class='block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black py-4 rounded-2xl shadow-lg shadow-emerald-500/20 active:scale-98 transition-all uppercase tracking-wider text-sm'>
                            💳 Pagar Simulación
                        </a>
                        <a href='" . env('FRONTEND_URL', 'http://localhost:5173') . "/register' class='block w-full bg-slate-700 hover:bg-slate-600 text-slate-300 font-bold py-3 rounded-2xl transition-colors text-sm'>
                            Cancelar pago
                        </a>
                    </div>
                </div>
            </body>
            </html>
            ";
        }, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * Simulated Confirm Endpoint (Triggers provision and redirects)
     */
    public function simulatedConfirm(Request $request)
    {
        $prefId = $request->query('pref_id');
        $reg = PendingRegistration::findOrFail($prefId);
        $payload = json_decode($reg->payload, true);

        try {
            $isUpgrade = (isset($payload['action']) && $payload['action'] === 'upgrade');
            $tenantData = $this->provisionTenant($payload, $prefId);
            
            // Mark registration as processed
            $reg->delete();

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            if ($isUpgrade) {
                return redirect("$frontendUrl/app?payment=success&action=upgrade");
            }

            // Redirect back to frontend login with success message
            return redirect("$frontendUrl/login?payment=success&email=" . urlencode($payload['admin_email']));
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error de aprovisionamiento: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Webhook Endpoint for MercadoPago Notifications
     */
    public function webhook(Request $request)
    {
        // Check standard notifications
        $type = $request->input('type');
        $dataId = $request->input('data.id');

        if ($type === 'payment' && $dataId) {
            $mpToken = config('mercadopago.access_token');
            if ($mpToken && !str_starts_with($mpToken, 'TEST-xxxx') && class_exists('MercadoPago\SDK')) {
                try {
                    \MercadoPago\SDK::setAccessToken($mpToken);
                    $payment = \MercadoPago\Payment::find_by_id($dataId);
                    $prefId = $payment->external_reference;

                    if ($prefId) {
                        $reg = PendingRegistration::find($prefId);
                        if ($reg) {
                            $payload = json_decode($reg->payload, true);
                            $this->provisionTenant($payload, $prefId);
                            $reg->delete();
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('MP Webhook Provision Error: ' . $e->getMessage());
                }
            }
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * Helper to provision a tenant
     */
    private function provisionTenant(array $payload, $prefId = null)
    {
        return DB::transaction(function() use ($payload, $prefId) {
            if (isset($payload['action']) && $payload['action'] === 'upgrade') {
                // Upgrade flow for existing tenant
                $tenant = Tenant::findOrFail($payload['tenant_id']);
                $tenant->update([
                    'plan' => strtolower($payload['plan']),
                    'max_users' => strtolower($payload['plan']) === 'pro' ? 50 : 9999,
                    'mp_subscription_id' => $prefId,
                    'subscription_status' => 'active',
                    'current_period_end' => now()->addMonth(),
                ]);

                $admin = User::where('tenant_id', $tenant->id)
                    ->where('role', UserRole::ADMIN->value)
                    ->first();

                return [
                    'tenant' => $tenant,
                    'admin' => $admin
                ];
            }

            // Standard creation flow
            // 1. Create Tenant
            $tenant = Tenant::create([
                'name' => $payload['company_name'],
                'subdomain' => $payload['subdomain'],
                'plan' => strtolower($payload['plan']),
                'max_users' => strtolower($payload['plan']) === 'freemium' ? 5 : (strtolower($payload['plan']) === 'pro' ? 50 : 9999),
                'public_slug' => Str::slug($payload['subdomain']),
                'mp_subscription_id' => $prefId,
                'subscription_status' => 'active',
                'trial_ends_at' => now()->addDays(14),
                'current_period_end' => now()->addMonth(),
            ]);

            // Set context for traits
            session(['tenant_id' => $tenant->id]);

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
                    ->where('email', $payload['admin_email'])
                    ->first();
                if ($admin) {
                    $admin->update([
                        'tenant_id' => $tenant->id,
                        'role' => UserRole::ADMIN->value,
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

            Auth::login($admin);

            // 3. Inject Clean Base Structure (Roles & Policies) for the new Tenant
            $seeder = new TenantSeeder();
            $seeder->run();

            Auth::logout();

            return [
                'tenant' => $tenant,
                'admin' => $admin
            ];
        });
    }
}
