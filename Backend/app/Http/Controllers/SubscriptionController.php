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
    // Nacer una empresa que ya pagó es el MISMO código para toda pasarela: vive en el trait y lo
    // comparte StripeWebhookController.
    use AprovisionaEmpresas;

    /**
     * Merge F3: el simulador de cobro SOLO existe en local/testing — en producción estos
     * endpoints deben ser 404 (antes provisionaban tenants con un pago fingido).
     *
     * Y desde el 2026-09-08 se apaga SOLO en cuanto hay una pasarela de verdad. Antes, el día
     * que Stripe empezara a cobrar había que acordarse de quitar la variable a mano; esa clase
     * de pendiente no se cumple, y lo que queda vivo mientras tanto es un alta gratuita de
     * empresas —con su admin y su token de sesión— en una URL pública. Ahora el interruptor lo
     * mueve el hecho de que exista quien cobre, no la memoria de nadie.
     */
    private function simulatorAllowed(): bool
    {
        // En local y en las pruebas el simulador es la única forma de recorrer el alta.
        if (app()->environment('local', 'testing')) {
            return true;
        }

        if ($this->hayPasarelaDeVerdad()) {
            return false;
        }

        // Opt-in explícito para staging con APP_ENV=production (instancia V2), y sólo mientras
        // no haya pasarela. Sale de `config/` y no de `env()` suelto: fuera de config, `env()`
        // devuelve null en cuanto alguien cachea la configuración.
        return (bool) config('services.checkout_simulado');
    }

    /** ¿Hay alguien que pueda cobrar de verdad en este servidor? */
    private function hayPasarelaDeVerdad(): bool
    {
        return app(\App\Services\Billing\CobroConStripe::class)->configurado()
            || $this->mercadoPagoConfigurado();
    }

    /**
     * Mercado Pago (camino heredado). La comprobación vive aquí y no repetida en el embudo:
     * si el simulador y el cobro no coincidieran en qué cuenta como "configurado", habría un
     * hueco por el que el simulador seguiría vivo con una pasarela funcionando.
     */
    private function mercadoPagoConfigurado(): bool
    {
        $token = config('mercadopago.access_token');

        return $token && !str_starts_with($token, 'TEST-xxxx') && class_exists('MercadoPago\SDK');
    }

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
                'subdomain' => [
                    'required', 'string', 'alpha_dash', 'max:50',
                    \Illuminate\Validation\Rule::unique('tenants', 'subdomain')->withoutTrashed(),
                    \Illuminate\Validation\Rule::unique('tenants', 'public_slug')->withoutTrashed()
                ],
                'plan' => 'required|string',
                'company_name' => 'required|string',
                'employees' => 'nullable|integer',
                'billing_cycle' => 'nullable|string',
            ], [
                'subdomain.unique' => 'El subdominio ya está registrado por otra empresa. Por favor elige uno diferente.',
                'subdomain.alpha_dash' => 'El subdominio solo puede contener letras, números y guiones sin espacios.',
                'subdomain.max' => 'El subdominio no puede tener más de 50 caracteres.',
            ]);
            $payload = [
                'action' => 'register_initial',
                'subdomain' => strtolower($request->subdomain),
                'plan' => $request->plan,
                'company_name' => $request->company_name,
                'admin_name' => $user->name ?? $request->input('admin_name', 'Admin'),
                'admin_email' => $user->email ?? $request->input('admin_email'),
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
                'subdomain' => [
                    'required', 'string', 'alpha_dash', 'max:50',
                    \Illuminate\Validation\Rule::unique('tenants', 'subdomain')->withoutTrashed(),
                    \Illuminate\Validation\Rule::unique('tenants', 'public_slug')->withoutTrashed()
                ],
                'plan' => 'required|string',
                'company_name' => 'required|string',
                'admin_name' => 'required|string',
                'admin_email' => 'required|email',
                'admin_password' => 'required|min:6',
                'employees' => 'nullable|integer',
                'billing_cycle' => 'nullable|string',
            ], [
                'subdomain.unique' => 'El subdominio ya está registrado por otra empresa. Por favor elige uno diferente.',
                'subdomain.alpha_dash' => 'El subdominio solo puede contener letras, números y guiones sin espacios.',
                'subdomain.max' => 'El subdominio no puede tener más de 50 caracteres.',
            ]);
            $payload = $request->all();
            $payload['subdomain'] = strtolower($request->subdomain);
            $payload['employees'] = $request->input('employees');
            $payload['billing_cycle'] = $request->input('billing_cycle', 'monthly');
        }

        $payload['subdomain'] = strtolower($payload['subdomain'] ?? '');
        $adminEmail = strtolower($payload['admin_email'] ?? '');
        $subdomain = $payload['subdomain'];

        // (2026-09-05) ALTA DE EMPRESA — punto 1 de los tres del consentimiento.
        //
        // Hasta hoy la casilla "Acepto los Términos y el Aviso de Privacidad" era TEATRO: vivía sólo
        // en el navegador (habilitaba el botón) y su valor no se enviaba ni se guardaba en ninguna
        // parte. Ahora el servidor la exige y deja constancia con la VERSIÓN del aviso, el momento,
        // la IP y el navegador. Se registra ANTES de cobrar y de aprovisionar: el consentimiento es
        // de la persona que lo dio, exista o no la empresa después.
        //
        // No aplica a una mejora de plan (`upgrade`): esa empresa ya existe y su admin ya aceptó.
        if (!$isUpgrade) {
            $request->validate(
                ['acepta_aviso' => ['required', 'accepted']],
                [
                    'acepta_aviso.required' => 'Debes aceptar el Aviso de Privacidad y los Términos del Servicio para dar de alta tu empresa.',
                    'acepta_aviso.accepted' => 'Debes aceptar el Aviso de Privacidad y los Términos del Servicio para dar de alta tu empresa.',
                ]
            );

            if ($user instanceof \App\Models\User) {
                // La cuenta ya existe (paso 1 del alta): queda registrada Y al día, para que no se
                // le vuelva a pedir el aviso al entrar a la empresa que acaba de crear.
                \App\Support\AvisoDePrivacidad::aceptarUsuario(
                    $user,
                    \App\Support\AvisoDePrivacidad::PUNTO_ALTA_EMPRESA,
                    $request
                );
            } else {
                // Alta sin sesión: la constancia queda a nombre del correo del administrador y
                // provisionTenant le estampa la empresa cuando exista.
                \App\Support\AvisoDePrivacidad::registrar([
                    'tenant_id' => null,
                    'punto' => \App\Support\AvisoDePrivacidad::PUNTO_ALTA_EMPRESA,
                    'nombre' => $payload['admin_name'] ?? null,
                    'email' => $adminEmail ?: null,
                ], $request);
            }
        }

        // Re-use or Create Pending Registration
        $existingPending = null;
        if ($adminEmail || $subdomain) {
            $existingPending = PendingRegistration::where('status', 'pending')
                ->forEmailOrSubdomain($adminEmail, $subdomain)
                ->first();
        }

        if ($existingPending) {
            $regId = $existingPending->id;
            $existingPending->update([
                'admin_email' => $adminEmail,
                'subdomain' => $subdomain,
                'payload' => json_encode($payload),
                'status' => 'pending',
                'updated_at' => now(),
            ]);
            $pendingRecord = $existingPending;
        } else {
            $regId = (string) Str::uuid();
            $pendingRecord = PendingRegistration::create([
                'id' => $regId,
                'admin_email' => $adminEmail,
                'subdomain' => $subdomain,
                'status' => 'pending',
                'payload' => json_encode($payload)
            ]);
        }

        // (2026-09-05) Los precios ya no viven aquí. Este bloque estaba DUPLICADO letra por letra
        // en `simulatedCheckout`, y ninguna pantalla sabía qué cobraba: la landing pintaba el
        // anual con un "20%" escrito a mano (29×12×0.8 = $278.40 por colaborador) mientras la
        // caja cobraba 24×12 = $288. Ahora la caja y la pantalla leen el MISMO tabulador
        // (`billing_plans`, vía App\Support\Tarifario).
        $billingCycle = $payload['billing_cycle'] ?? 'monthly';
        $price = \App\Support\Tarifario::totalACobrar(
            $payload['plan'] ?? null,
            isset($payload['employees']) ? intval($payload['employees']) : null,
            $billingCycle
        );

        // If plan is freemium and it's not upgrade, register immediately (no payment needed)
        if (!$isUpgrade && (strtolower($payload['plan']) === 'freemium' || $price <= 0)) {
            try {
                $tenant = $this->provisionTenant($payload, $regId);
                $token = $tenant['admin']->createToken('auth_token')->plainTextToken;
                return response()->json([
                    'status' => 'success',
                    'provisioned' => true,
                    'tenant' => $tenant['tenant'],
                    'user' => $tenant['admin'],
                    'token' => $token
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw $e;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                throw $e;
            } catch (\Exception $e) {
                \Log::error("Error al aprovisionar empresa: " . $e->getMessage(), [
                    'payload' => $payload,
                    'exception' => $e
                ]);
                return response()->json([
                    'error' => $e->getMessage() ?: 'Error al crear la empresa. Por favor intenta con otro subdominio.'
                ], 400);
            }
        }

        // PASARELA ELEGIDA (decisión del 2026-09-06): tarjeta con Stripe. Va ANTES que Mercado
        // Pago porque es la que cobra hoy; MP se queda debajo como camino heredado mientras su
        // token exista. Dos modalidades, como se pidió: `modalidad=liga` cobra una vez el periodo
        // (la liga que se manda a mano) y cualquier otra cosa crea la SUSCRIPCIÓN, que Stripe
        // cobra sola cada ciclo y cuyos reintentos y mora maneja Stripe Billing.
        $stripe = app(\App\Services\Billing\CobroConStripe::class);
        if ($stripe->configurado()) {
            $frontendUrl = $this->getFrontendUrl($request);
            $modalidad = strtolower((string) $request->input('modalidad', 'suscripcion')) === 'liga'
                ? \App\Services\Billing\CobroConStripe::MODO_LIGA
                : \App\Services\Billing\CobroConStripe::MODO_SUSCRIPCION;

            try {
                $sesion = $stripe->crearSesion([
                    'modo' => $modalidad,
                    'importe' => $price,
                    'concepto' => ($isUpgrade ? 'Mejora de plan Talent360 - Plan ' : 'Suscripción Talent360 - Plan ')
                        . ucfirst((string) $payload['plan']),
                    'referencia' => $regId,
                    'correo' => $adminEmail ?: null,
                    'ciclo' => $billingCycle,
                    'url_exito' => $request->input('success_url', $isUpgrade
                        ? "$frontendUrl/app?payment=success&action=upgrade"
                        : "$frontendUrl/login?payment=success"),
                    'url_cancelacion' => $request->input('failure_url', $isUpgrade
                        ? "$frontendUrl/app?payment=failed"
                        : "$frontendUrl/register?payment=failed"),
                    'metadatos' => array_filter(['tenant_id' => $payload['tenant_id'] ?? null]),
                ]);

                $pendingRecord->update(['checkout_url' => $sesion['url']]);

                return response()->json([
                    'status' => 'success',
                    'init_point' => $sesion['url'],
                    'simulated' => false,
                    'pasarela' => 'stripe',
                ]);
            } catch (\Throwable $e) {
                // Con Stripe configurado NO se cae al simulador: eso daría por buena una compra
                // que nadie cobró. Se falla de frente y el cliente reintenta.
                \Log::error('Stripe checkout falló: ' . $e->getMessage());

                return response()->json([
                    'error' => 'No se pudo abrir el cobro con tarjeta. Vuelve a intentarlo en unos minutos.',
                ], 502);
            }
        }

        // Try using MercadoPago SDK if configured
        $mpToken = config('mercadopago.access_token');
        if ($this->mercadoPagoConfigurado()) {
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

                $pendingRecord->update(['checkout_url' => $preference->init_point]);

                return response()->json([
                    'status' => 'success',
                    'init_point' => $preference->init_point,
                    'simulated' => false
                ]);
            } catch (\Exception $e) {
                // Fallback to simulator below
            }
        }

        // Sin ninguna pasarela configurada Y sin simulador permitido, lo que se devolvía era una
        // liga a un endpoint que responde 404, envuelta en `status: success`. El alta parecía ir
        // bien y moría en el clic siguiente. Se dice de frente.
        if (!$this->simulatorAllowed()) {
            \Log::error('Alta sin pasarela de cobro: no hay llave de Stripe ni token de Mercado Pago en este servidor.');

            return response()->json([
                'error' => 'El cobro con tarjeta no está disponible en este momento. Escríbenos y completamos tu alta.',
            ], 503);
        }

        // Fallback to simulated checkout URL
        $simulatedUrl = $this->getBaseUrl($request) . '/api/v1/subscriptions/simulated-checkout?pref_id=' . $regId;
        $pendingRecord->update(['checkout_url' => $simulatedUrl]);

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
        abort_unless($this->simulatorAllowed(), 404);

        $prefId = $request->query('pref_id');
        $reg = PendingRegistration::findOrFail($prefId);
        $payload = json_decode($reg->payload, true);
        $plan = $payload['plan'] ?? 'pro';
        $billingCycle = $payload['billing_cycle'] ?? 'monthly';
        // Era una COPIA idéntica del cálculo de `createPreference`: dos bocas para el mismo
        // cobro. Ahora las dos leen el tabulador único.
        $price = \App\Support\Tarifario::totalACobrar(
            $plan,
            isset($payload['employees']) ? intval($payload['employees']) : null,
            $billingCycle
        );
        $priceUnit = $billingCycle === 'yearly' ? 'MXN/año (Pago Anual)' : 'MXN/mes';

        $confirmUrl = $this->getBaseUrl($request) . '/api/v1/subscriptions/simulated-confirm?pref_id=' . $prefId;

        // Fetch platform bank config
        $bankConfigRow = \DB::table('system_settings')
            ->whereNull('tenant_id')
            ->where('key', 'platform_bank_config')
            ->first();
        $bank = $bankConfigRow ? json_decode($bankConfigRow->value, true) : null;
        $bankActive = $bank && ($bank['is_active'] ?? false);

        $bankJson = json_encode($bank);
        $hasBankStr = $bankActive ? 'true' : 'false';
        $html = "
            <!DOCTYPE html>
            <html lang='es'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'>
                <title>MercadoPago Sandbox - Talent360</title>
                <script src='https://cdn.tailwindcss.com'></script>
                <style>
                    .tab-active { border-color: #2563eb; color: #3b82f6; font-weight: 800; }
                </style>
            </head>
            <body class='bg-slate-950 text-slate-100 flex items-center justify-center min-h-screen font-sans p-3 sm:p-6'>
                <div class='bg-slate-900 border border-slate-800 rounded-2xl sm:rounded-3xl p-5 sm:p-8 max-w-md w-full shadow-2xl space-y-5 sm:space-y-6 my-auto border-t-4 border-t-blue-600'>
                    <div class='flex items-center justify-between gap-2'>
                        <div class='flex items-center gap-2.5 min-w-0'>
                            <div class='w-9 h-9 sm:w-10 sm:h-10 bg-blue-600 rounded-xl flex items-center justify-center font-black text-lg sm:text-xl text-white shadow-lg shadow-blue-500/20 shrink-0'>
                                MP
                            </div>
                            <span class='font-extrabold text-xs sm:text-sm text-slate-200 truncate'>MercadoPago Sandbox</span>
                        </div>
                        <span class='bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px] sm:text-xs font-bold px-2.5 py-1 rounded-full shrink-0 whitespace-nowrap'>
                            Modo Sandbox
                        </span>
                    </div>

                    <div class='space-y-1.5 text-center'>
                        <h2 class='text-xl sm:text-2xl font-black tracking-tight text-white'>Procesar Suscripción</h2>
                        <p class='text-xs sm:text-sm text-slate-400'>Estás pagando la suscripción de Talent360</p>
                    </div>

                    <div class='bg-slate-950/60 border border-slate-800 rounded-2xl p-4 sm:p-5 text-left space-y-2.5 text-xs sm:text-sm'>
                        <div class='flex justify-between items-center gap-2'><span class='text-slate-400 font-bold shrink-0'>Concepto:</span><span class='font-semibold text-slate-200 text-right truncate'>Plan " . ucfirst($plan) . "</span></div>
                        <div class='flex justify-between items-center gap-2'><span class='text-slate-400 font-bold shrink-0'>Importe:</span><span class='text-emerald-400 font-black text-sm sm:text-base text-right'>$" . $price . " " . $priceUnit . "</span></div>
                        <div class='flex justify-between items-start gap-2'><span class='text-slate-400 font-bold shrink-0'>Empresa:</span><span class='font-semibold text-slate-200 text-right break-words max-w-[65%]'>" . htmlspecialchars($payload['company_name']) . "</span></div>
                        <div class='flex justify-between items-start gap-2'><span class='text-slate-400 font-bold shrink-0'>Admin Email:</span><span class='font-semibold text-slate-200 text-right break-all max-w-[65%]'>" . htmlspecialchars($payload['admin_email']) . "</span></div>
                    </div>

                    <!-- Pestañas de método de pago -->
                    <div class='flex border-b border-slate-800 text-xs sm:text-sm font-semibold'>
                        <button id='tab-card' onclick='switchTab(\"card\")' class='flex-1 py-3 text-center border-b-2 tab-active transition-all focus:outline-none'>
                            💳 Tarjeta
                        </button>
                        <button id='tab-spei' onclick='switchTab(\"spei\")' class='flex-1 py-3 text-center border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition-all focus:outline-none " . ($bankActive ? '' : 'hidden') . "'>
                            🏦 SPEI / Transferencia
                        </button>
                    </div>

                    <!-- Contenido Pestaña Tarjeta -->
                    <div id='content-card' class='space-y-4 animate-in fade-in duration-200'>
                        <p class='text-xs text-slate-400 text-left leading-relaxed'>
                            Simula un pago inmediato y exitoso utilizando una tarjeta bancaria en modo sandbox. Tu cuenta será activada de inmediato.
                        </p>
                        <a href='{$confirmUrl}' class='block w-full bg-emerald-600 hover:bg-emerald-500 active:scale-[0.98] text-white font-black py-3.5 sm:py-4 rounded-xl sm:rounded-2xl shadow-lg shadow-emerald-500/20 transition-all uppercase tracking-wider text-xs sm:text-sm text-center cursor-pointer'>
                            Confirmar Pago con Tarjeta
                        </a>
                    </div>

                    <!-- Contenido Pestaña SPEI -->
                    <div id='content-spei' class='hidden space-y-4 animate-in fade-in duration-200 text-left'>
                        <div class='bg-slate-950/70 border border-slate-800 rounded-2xl p-4 space-y-3 text-xs'>
                            <p class='text-[10px] font-bold uppercase tracking-wider text-blue-400'>Datos de Transferencia Bancaria</p>
                            <div>
                                <span class='text-slate-400 block'>Banco Receptor:</span>
                                <span class='font-bold text-white text-xs sm:text-sm' id='bank-name'></span>
                            </div>
                            <div>
                                <span class='text-slate-400 block'>Beneficiario:</span>
                                <span class='font-bold text-white text-xs sm:text-sm' id='bank-holder'></span>
                            </div>
                            <div class='flex justify-between items-end gap-2'>
                                <div class='min-w-0 flex-1'>
                                    <span class='text-slate-400 block'>CLABE Interbancaria:</span>
                                    <span class='font-black text-emerald-400 text-sm sm:text-base tracking-wider break-all' id='bank-clabe'></span>
                                </div>
                                <button onclick='copyText(\"bank-clabe\")' class='text-[10px] sm:text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold px-2.5 py-1.5 rounded-lg transition-colors shrink-0'>
                                    Copiar
                                </button>
                            </div>
                            <div class='flex justify-between items-end gap-2' id='card-container'>
                                <div class='min-w-0 flex-1'>
                                    <span class='text-slate-400 block'>Número de Tarjeta:</span>
                                    <span class='font-bold text-white text-xs sm:text-sm break-all' id='bank-card'></span>
                                </div>
                                <button onclick='copyText(\"bank-card\")' class='text-[10px] sm:text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold px-2.5 py-1.5 rounded-lg transition-colors shrink-0'>
                                    Copiar
                                </button>
                            </div>
                            <div class='border-t border-slate-800/80 pt-2'>
                                <span class='text-slate-400 block mb-1 text-[11px] font-semibold'>Instrucciones:</span>
                                <p class='text-[11px] text-slate-300 leading-relaxed' id='bank-instructions'></p>
                            </div>
                        </div>

                        <div class='space-y-2'>
                            <a href='{$confirmUrl}&method=spei' class='block w-full bg-blue-600 hover:bg-blue-500 active:scale-[0.98] text-white font-black py-3.5 sm:py-4 rounded-xl sm:rounded-2xl shadow-lg shadow-blue-500/20 transition-all uppercase tracking-wider text-xs sm:text-sm text-center cursor-pointer'>
                                ⏱ Registrar Transferencia Simulada
                            </a>
                            <p class='text-[10px] sm:text-xs text-slate-400 text-center leading-relaxed'>
                                Al hacer clic, registrarás la transferencia simulada. El sistema simulará la confirmación del SPEI y provisionará tu base de datos de inmediato.
                            </p>
                        </div>
                    </div>

                    <div>
                        <a href='" . $this->getFrontendUrl($request) . "/register' class='block w-full bg-slate-800 hover:bg-slate-700 active:scale-[0.98] text-slate-300 font-bold py-3 rounded-xl sm:rounded-2xl transition-colors text-xs sm:text-sm text-center'>
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

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * Simulated Confirm Endpoint (Triggers provision and redirects)
     */
    public function simulatedConfirm(Request $request)
    {
        abort_unless($this->simulatorAllowed(), 404);

        $prefId = $request->query('pref_id');
        $reg = PendingRegistration::findOrFail($prefId);
        $payload = json_decode($reg->payload, true);

        try {
            $isUpgrade = (isset($payload['action']) && $payload['action'] === 'upgrade');
            $this->provisionTenant($payload, $prefId);
            
            // Mark registration as processed
            $reg->delete();

            $frontendUrl = $this->getFrontendUrl($request);
            if ($isUpgrade) {
                return redirect("$frontendUrl/app?payment=success&action=upgrade");
            }

            // Nunca poner credenciales en la URL: se filtran al historial, logs y Referer.
            // La contraseña ya elegida durante el registro es la que confirma la identidad.
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
                    $this->aprovisionarRegistroPagado($payment->external_reference);
                } catch (\Exception $e) {
                    \Log::error('MP Webhook Provision Error: ' . $e->getMessage());
                }
            }
        }

        return response()->json(['status' => 'received']);
    }

    private function getBaseUrl(Request $request): string
    {
        $host = $request->header('X-Forwarded-Host') ?: ($request->header('Host') ?: $request->getHttpHost());
        $scheme = $request->header('X-Forwarded-Proto') ?: $request->getScheme();
        return rtrim("$scheme://$host", '/');
    }

    private function getFrontendUrl(Request $request): string
    {
        if (env('FRONTEND_URL')) {
            return env('FRONTEND_URL');
        }
        $origin = $request->header('Origin') ?: $request->header('Referer');
        if ($origin) {
            $scheme = parse_url($origin, PHP_URL_SCHEME);
            $host = parse_url($origin, PHP_URL_HOST);
            $port = parse_url($origin, PHP_URL_PORT);
            if ($scheme && $host) {
                return rtrim($scheme . '://' . $host . ($port ? ':' . $port : ''), '/');
            }
        }
        return $this->getBaseUrl($request);
    }
}
