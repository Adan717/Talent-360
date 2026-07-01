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
                'employees' => 'nullable|integer',
                'billing_cycle' => 'nullable|string',
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
                'employees' => $request->input('employees'),
                'billing_cycle' => $request->input('billing_cycle', 'monthly'),
            ];
        } elseif ($isInitialRegistration) {
            // Google authenticated registration flow: only company data is required
            $request->validate([
                'subdomain' => 'required|string',
                'plan' => 'required|string',
                'company_name' => 'required|string',
                'employees' => 'nullable|integer',
                'billing_cycle' => 'nullable|string',
            ]);
            $payload = [
                'action' => 'register_initial',
                'subdomain' => $request->subdomain,
                'plan' => $request->plan,
                'company_name' => $request->company_name,
                'admin_name' => $user->name,
                'admin_email' => $user->email,
                'employees' => $request->input('employees'),
                'billing_cycle' => $request->input('billing_cycle', 'monthly'),
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
                'admin_password' => 'required|min:6',
                'employees' => 'nullable|integer',
                'billing_cycle' => 'nullable|string',
            ]);
            $payload = $request->all();
            $payload['employees'] = $request->input('employees');
            $payload['billing_cycle'] = $request->input('billing_cycle', 'monthly');
        }

        $regId = (string) Str::uuid();

        // Save pending registration payload
        PendingRegistration::create([
            'id' => $regId,
            'payload' => json_encode($payload)
        ]);

        $price = 0;
        $billingCycle = $payload['billing_cycle'] ?? 'monthly';
        if (strtolower($payload['plan']) === 'pro') {
            $employees = isset($payload['employees']) ? intval($payload['employees']) : 10;
            $basePrice = $employees * 12;
            if ($billingCycle === 'yearly') {
                $price = (float) round($basePrice * 12 * 0.8); // 20% discount billed annually
            } else {
                $price = (float) $basePrice;
            }
        } elseif (strtolower($payload['plan']) === 'enterprise') {
            if ($billingCycle === 'yearly') {
                $price = (float) round(499 * 12 * 0.8); // 20% discount billed annually
            } else {
                $price = (float) 499;
            }
        }

        // If plan is freemium and it's not upgrade, register immediately (no payment needed)
        if (!$isUpgrade && (strtolower($payload['plan']) === 'freemium' || $price <= 0)) {
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
        $billingCycle = $payload['billing_cycle'] ?? 'monthly';
        $price = 0;
        if ($plan === 'pro') {
            $employees = isset($payload['employees']) ? intval($payload['employees']) : 10;
            $basePrice = $employees * 12;
            if ($billingCycle === 'yearly') {
                $price = (float) round($basePrice * 12 * 0.8);
            } else {
                $price = (float) $basePrice;
            }
        } elseif ($plan === 'enterprise') {
            if ($billingCycle === 'yearly') {
                $price = (float) round(499 * 12 * 0.8);
            } else {
                $price = (float) 499;
            }
        }
        $priceUnit = $billingCycle === 'yearly' ? 'MXN/año (Pago Anual)' : 'MXN/mes';

        $confirmUrl = url('/api/subscriptions/simulated-confirm?pref_id=' . $prefId);

        // Fetch platform bank config
        $bankConfigRow = \DB::table('system_settings')
            ->whereNull('tenant_id')
            ->where('key', 'platform_bank_config')
            ->first();
        $bank = $bankConfigRow ? json_decode($bankConfigRow->value, true) : null;
        $bankActive = $bank && ($bank['is_active'] ?? false);

        return response()->stream(function() use ($payload, $plan, $price, $priceUnit, $confirmUrl, $bank, $bankActive) {
            $bankJson = json_encode($bank);
            $hasBankStr = $bankActive ? 'true' : 'false';
            echo "
            <!DOCTYPE html>
            <html lang='es'>
            <head>
                <meta charset='UTF-8'>
                <title>MercadoPago Sandbox - Talent360</title>
                <script src='https://cdn.tailwindcss.com'></script>
                <style>
                    .tab-active { border-color: #2563eb; color: #2563eb; font-weight: 800; }
                </style>
            </head>
            <body class='bg-slate-900 text-slate-100 flex items-center justify-center min-h-screen font-sans p-4'>
                <div class='bg-slate-800 border border-slate-700 rounded-3xl p-8 max-w-md w-full shadow-2xl space-y-6'>
                    <div class='flex items-center justify-between'>
                        <div class='flex items-center gap-2'>
                            <div class='w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center font-black text-xl text-white shadow-lg shadow-blue-500/20'>
                                MP
                            </div>
                            <span class='font-extrabold text-sm text-slate-300'>MercadoPago Sandbox</span>
                        </div>
                        <span class='bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] font-bold px-2 py-0.5 rounded-full'>
                            Modo Sandbox
                        </span>
                    </div>

                    <div class='space-y-2 text-center'>
                        <h2 class='text-2xl font-black tracking-tight text-white'>Procesar Suscripción</h2>
                        <p class='text-xs text-slate-400'>Estás pagando la suscripción de Talent360</p>
                    </div>

                    <div class='bg-slate-900/50 border border-slate-700/50 rounded-2xl p-5 text-left space-y-3 text-sm'>
                        <div class='flex justify-between'><span class='text-slate-400 font-bold'>Concepto:</span><span>Plan " . ucfirst($plan) . "</span></div>
                        <div class='flex justify-between'><span class='text-slate-400 font-bold'>Importe:</span><span class='text-emerald-400 font-black'>$" . $price . " " . $priceUnit . "</span></div>
                        <div class='flex justify-between'><span class='text-slate-400 font-bold'>Empresa:</span><span>" . htmlspecialchars($payload['company_name']) . "</span></div>
                        <div class='flex justify-between'><span class='text-slate-400 font-bold'>Admin Email:</span><span>" . htmlspecialchars($payload['admin_email']) . "</span></div>
                    </div>

                    <!-- Pestañas de método de pago -->
                    <div class='flex border-b border-slate-700 text-xs font-semibold'>
                        <button id='tab-card' onclick='switchTab(\"card\")' class='flex-1 py-3 text-center border-b-2 tab-active transition-all focus:outline-none'>
                            💳 Tarjeta de Crédito/Débito
                        </button>
                        <button id='tab-spei' onclick='switchTab(\"spei\")' class='flex-1 py-3 text-center border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition-all focus:outline-none " . ($bankActive ? '' : 'hidden') . "'>
                            🏦 Transferencia SPEI
                        </button>
                    </div>

                    <!-- Contenido Pestaña Tarjeta -->
                    <div id='content-card' class='space-y-4 animate-in fade-in duration-200'>
                        <p class='text-xs text-slate-400 text-left leading-relaxed'>
                            Simula un pago inmediato y exitoso utilizando una tarjeta bancaria en modo sandbox. Tu cuenta será activada de inmediato.
                        </p>
                        <a href='{$confirmUrl}' class='block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black py-4 rounded-2xl shadow-lg shadow-emerald-500/20 active:scale-98 transition-all uppercase tracking-wider text-sm text-center'>
                            Confirmar Pago con Tarjeta
                        </a>
                    </div>

                    <!-- Contenido Pestaña SPEI -->
                    <div id='content-spei' class='hidden space-y-4 animate-in fade-in duration-200 text-left'>
                        <div class='bg-slate-900/60 border border-slate-700 rounded-2xl p-4 space-y-3 text-xs'>
                            <p class='text-[10px] font-bold uppercase tracking-wider text-blue-400'>Datos de Transferencia Bancaria</p>
                            <div>
                                <span class='text-slate-400 block'>Banco Receptor:</span>
                                <span class='font-bold text-white text-sm' id='bank-name'></span>
                            </div>
                            <div>
                                <span class='text-slate-400 block'>Beneficiario:</span>
                                <span class='font-bold text-white text-sm' id='bank-holder'></span>
                            </div>
                            <div class='flex justify-between items-end'>
                                <div>
                                    <span class='text-slate-400 block'>CLABE Interbancaria:</span>
                                    <span class='font-black text-emerald-400 text-base tracking-wider' id='bank-clabe'></span>
                                </div>
                                <button onclick='copyText(\"bank-clabe\")' class='text-[10px] bg-slate-700 hover:bg-slate-600 text-slate-300 font-bold px-2 py-1 rounded transition-colors'>
                                    Copiar
                                </button>
                            </div>
                            <div class='flex justify-between items-end' id='card-container'>
                                <div>
                                    <span class='text-slate-400 block'>Número de Tarjeta:</span>
                                    <span class='font-bold text-white' id='bank-card'></span>
                                </div>
                                <button onclick='copyText(\"bank-card\")' class='text-[10px] bg-slate-700 hover:bg-slate-600 text-slate-300 font-bold px-2 py-1 rounded transition-colors'>
                                    Copiar
                                </button>
                            </div>
                            <div class='border-t border-slate-800 pt-2'>
                                <span class='text-slate-400 block mb-1'>Instrucciones:</span>
                                <p class='text-[11px] text-slate-300 leading-relaxed' id='bank-instructions'></p>
                            </div>
                        </div>

                        <div class='space-y-2'>
                            <a href='{$confirmUrl}&method=spei' class='block w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-2xl shadow-lg shadow-blue-500/20 active:scale-98 transition-all uppercase tracking-wider text-sm text-center'>
                                ⏱ Registrar Transferencia Simulada
                            </a>
                            <p class='text-[10px] text-slate-400 text-center leading-relaxed'>
                                Al hacer clic, registrarás la transferencia simulada. El sistema simulará la confirmación del SPEI y provisionará tu base de datos de inmediato.
                            </p>
                        </div>
                    </div>

                    <div>
                        <a href='" . env('FRONTEND_URL', 'http://localhost:5173') . "/register' class='block w-full bg-slate-700 hover:bg-slate-600 text-slate-300 font-bold py-3 rounded-2xl transition-colors text-sm text-center'>
                            Cancelar pago y volver
                        </a>
                    </div>
                </div>

                <script>
                    const hasBank = {$hasBankStr};
                    const bankData = {$bankJson};

                    if (hasBank && bankData) {
                        document.getElementById('bank-name').innerText = bankData.bank_name || '';
                        document.getElementById('bank-holder').innerText = bankData.account_holder || '';
                        document.getElementById('bank-clabe').innerText = bankData.clabe || '';
                        
                        if (bankData.card_number) {
                            document.getElementById('bank-card').innerText = bankData.card_number;
                        } else {
                            document.getElementById('card-container').classList.add('hidden');
                        }

                        document.getElementById('bank-instructions').innerText = bankData.instructions || 'Realiza tu transferencia SPEI e introduce tu referencia de pago para activar tu servicio.';
                    }

                    function switchTab(tab) {
                        const tabCard = document.getElementById('tab-card');
                        const tabSpei = document.getElementById('tab-spei');
                        const contentCard = document.getElementById('content-card');
                        const contentSpei = document.getElementById('content-spei');

                        if (tab === 'card') {
                            tabCard.classList.add('tab-active');
                            tabCard.classList.remove('border-transparent', 'text-slate-400');
                            tabSpei.classList.remove('tab-active');
                            tabSpei.classList.add('border-transparent', 'text-slate-400');
                            
                            contentCard.classList.remove('hidden');
                            contentSpei.classList.add('hidden');
                        } else {
                            tabSpei.classList.add('tab-active');
                            tabSpei.classList.remove('border-transparent', 'text-slate-400');
                            tabCard.classList.remove('tab-active');
                            tabCard.classList.add('border-transparent', 'text-slate-400');
                            
                            contentSpei.classList.remove('hidden');
                            contentCard.classList.add('hidden');
                        }
                    }

                    function copyText(elementId) {
                        const text = document.getElementById(elementId).innerText;
                        navigator.clipboard.writeText(text).then(() => {
                            alert('Copiado al portapapeles: ' + text);
                        });
                    }
                </script>
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
            $token = $tenantData['admin']->createToken('auth_token')->plainTextToken;
            
            // Mark registration as processed
            $reg->delete();

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            if ($isUpgrade) {
                return redirect("$frontendUrl/app?payment=success&action=upgrade");
            }

            // Redirect back to frontend login with success message and autologin token
            return redirect("$frontendUrl/login?payment=success&email=" . urlencode($payload['admin_email']) . "&token=" . urlencode($token));
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
                    'max_users' => strtolower($payload['plan']) === 'pro' ? (isset($payload['employees']) ? intval($payload['employees']) : 50) : 9999,
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
                'max_users' => strtolower($payload['plan']) === 'freemium' ? 5 : (strtolower($payload['plan']) === 'pro' ? (isset($payload['employees']) ? intval($payload['employees']) : 50) : 9999),
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
