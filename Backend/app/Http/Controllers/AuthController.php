<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\User;
use App\Helpers\SecurityLogger;

class AuthController extends Controller
{
    protected \App\Services\ClockService $clockService;

    public function __construct(\App\Services\ClockService $clockService)
    {
        $this->clockService = $clockService;
    }

    // Rate-limit del login anti-fuerza-bruta. Calca el patrón del Kiosko (R54): cuenta SÓLO los
    // intentos FALLIDOS (un login exitoso no consume presupuesto → el chorro de la mañana no lo
    // dispara). Dos llaves de defensa en profundidad:
    //  - POR CUENTA (email+IP): protege UNA cuenta real de la adivinación. Se LIMPIA al acertar (sólo
    //    el dueño de la contraseña puede acertar, así que limpiar es seguro y no castiga los typos).
    //    Lleva la IP para que saber el email de alguien NO permita bloquearlo desde cualquier lado
    //    (account-lockout DoS): sólo se bloquea la combinación email+IP del atacante.
    //  - BACKSTOP DE ENUMERACIÓN/STUFFING (por IP): frena el barrido de muchos correos desde una IP.
    //    CLAVE DE SEGURIDAD: sólo cuenta y sólo bloquea intentos a correos que **no existen**. Un
    //    usuario legítimo SIEMPRE usa su correo real → este backstop NUNCA lo cuenta ni lo bloquea.
    //    Por eso es seguro aunque detrás de un proxy `$request->ip()` colapse a una sola IP (hoy nginx
    //    no propaga la IP del cliente → el backstop es efectivamente global): en el peor caso frena
    //    logins a correos INEXISTENTES en toda la plataforma, jamás a una cuenta real. Los typos de
    //    una oficina compartida son correos reales, así que tampoco la estrangulan (el caso de R63).
    //    NO se limpia al acertar. Un WAF/edge con la IP real del cliente es el sitio idóneo para el
    //    rate-limit por IP verdadero; esto es el respaldo a nivel app.
    private const LOGIN_MAX_POR_CUENTA = 5;
    private const LOGIN_MAX_STUFFING = 50;
    private const LOGIN_DECAY_SEGUNDOS = 900; // 15 min

    // (2026-08-28, revisión externa r2-b) El kiosco tenía `throttle:5,1` de ruta — y el throttle
    // de ruta en un endpoint sin sesión cuenta POR IP, contando también los logins EXITOSOS. La
    // tablet de la sucursal es UNA sola IP: a las 8:00 llegan veinte personas y al sexto fichaje
    // el kiosco se cerraba. Mismo remedio que en /login: contar sólo intentos FALLIDOS, por
    // EMPLEADO (el chorro legítimo de la mañana jamás se cuenta entre sí), con un backstop por IP
    // que sólo cuenta empleados INEXISTENTES o sin PIN (enumeración) — un PIN mal tecleado de una
    // persona real nunca alimenta el backstop, así que una mañana torpe no lo dispara.
    private const KIOSK_MAX_POR_EMPLEADO = 5;
    private const KIOSK_MAX_ENUMERACION = 50;

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $email = strtolower(trim($request->email));
        $ip = $request->ip();
        $keyCuenta = 'login-fail:' . $email . '|' . $ip;
        $keyStuffing = 'login-unknown-ip:' . $ip;

        // Gate por cuenta ANTES del lookup: un atacante estrangulado no paga el bcrypt de abajo.
        if (RateLimiter::tooManyAttempts($keyCuenta, self::LOGIN_MAX_POR_CUENTA)) {
            \Illuminate\Support\Facades\Log::warning("Login throttled (cuenta)", ['email' => $email, 'ip' => $ip]);
            return response()->json([
                'error' => 'Demasiados intentos fallidos. Espera un momento e inténtalo de nuevo.',
            ], 429);
        }

        $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('email', $email)
            ->with('tenant')
            ->first();

        $isPlatformUser = false;
        if (!$user) {
            $user = \App\Models\PlatformUser::where('email', $email)->first();
            if ($user) {
                $isPlatformUser = true;
            }
        }

        // El correo NO existe en ningún padrón → sólo estos alimentan/consultan el backstop de
        // enumeración, que por diseño nunca puede bloquear a una cuenta real (ver comentario arriba).
        $emailExiste = (bool) $user;
        if (!$emailExiste && RateLimiter::tooManyAttempts($keyStuffing, self::LOGIN_MAX_STUFFING)) {
            \Illuminate\Support\Facades\Log::warning("Login throttled (enumeración)", ['ip' => $ip]);
            return response()->json([
                'error' => 'Demasiados intentos fallidos. Espera un momento e inténtalo de nuevo.',
            ], 429);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Sólo las credenciales MALAS cuentan para el rate-limit (no el usuario inactivo ni el
            // tenant suspendido de abajo: ésos ya tienen la contraseña correcta, no son fuerza bruta).
            RateLimiter::hit($keyCuenta, self::LOGIN_DECAY_SEGUNDOS);
            // El backstop de enumeración SÓLO se alimenta de correos inexistentes (nunca de un typo
            // en un correo real), así que jamás puede estrangular a la oficina ni a una cuenta viva.
            if (!$emailExiste) {
                RateLimiter::hit($keyStuffing, self::LOGIN_DECAY_SEGUNDOS);
            }
            SecurityLogger::log('auth_failure', "Intento de inicio de sesión fallido para el correo: {$request->email}");
            return response()->json(['error' => 'Credenciales incorrectas'], 401);
        }

        if (!$user->is_active) {
            SecurityLogger::log('auth_blocked', "Intento de acceso de usuario inactivo: {$user->email}", $isPlatformUser ? null : $user->tenant_id, $user->id);
            return response()->json(['error' => 'Usuario inactivo / archivado'], 403);
        }

        // Check if tenant is active
        if (!$isPlatformUser && $user->role !== \App\Enums\UserRole::PLATFORM_ADMIN->value) {
            $tenant = $user->tenant;
            if ($tenant && !$tenant->is_active) {
                SecurityLogger::log('auth_suspended', "Intento de acceso a empresa suspendida: {$tenant->name} por el usuario: {$user->email}", $tenant->id, $user->id);
                return response()->json([
                    'error' => 'Empresa suspendida',
                    'message' => 'El acceso a esta empresa ha sido suspendido temporalmente por el administrador de la plataforma.'
                ], 403);
            }
        }

        // Éxito: limpia SÓLO la llave por cuenta (los typos previos no cuentan tras acertar). El
        // backstop de enumeración no se toca aquí — un éxito no lo alimenta (sólo lo hacen los correos
        // inexistentes) ni lo resetea.
        RateLimiter::clear($keyCuenta);

        $token = $user->createToken('auth_token')->plainTextToken;
        $requires2fa = !$isPlatformUser && $user->two_factor_enabled;

        SecurityLogger::log('auth_success', "Inicio de sesión exitoso de: {$user->email}", $isPlatformUser ? null : $user->tenant_id, $user->id);

        // §43: además del JSON (para compatibilidad con el frontend actual mientras
        // migra), el token viaja en una cookie httpOnly que JavaScript no puede leer.
        return response()->json([
            'message' => 'Login exitoso',
            // toAuthPayload: el puesto sale del expediente, no del duplicado stale users.job_role_id.
            'user' => $user instanceof \App\Models\User ? $user->toAuthPayload() : $user,
            'tenant' => $isPlatformUser ? null : $user->tenant,
            'token' => $token,
            'requires_2fa' => $requires2fa
        ])->cookie($this->makeAuthCookie($token));
    }

    /**
     * §43: cookie httpOnly del token de auth. `$minutes = null` → un año (login normal);
     * para el kiosco se pasan 15 minutos, para que la cookie caduque junto con el token.
     */
    private function makeAuthCookie(string $token, ?int $minutes = null)
    {
        $minutes = $minutes ?? 60 * 24 * 365; // "indefinido" para login normal
        $secure = app()->isProduction();

        // Despliegue Railway+Vercel (2026-07-28): con el FE y el BE en DOMINIOS distintos
        // (vercel.app / railway.app), SameSite=Lax hace que el navegador NUNCA mande la
        // cookie en los XHR cross-site — el login "funciona" pero ninguna petición queda
        // autenticada. En esos entornos se fija AUTH_COOKIE_SAMESITE=None (exige Secure,
        // que en producción ya va en true). Default 'Lax' = mismo comportamiento de
        // siempre cuando FE y BE comparten dominio (Hetzner/local).
        $sameSite = env('AUTH_COOKIE_SAMESITE', 'Lax');
        if (strcasecmp($sameSite, 'None') === 0 && !$secure) {
            // SameSite=None sin Secure lo RECHAZAN los navegadores — mejor caer a Lax
            // que emitir una cookie muerta en entornos locales.
            $sameSite = 'Lax';
        }

        return cookie(
            \App\Http\Middleware\AuthTokenFromCookie::COOKIE_NAME,
            $token,
            $minutes,
            '/',
            null,
            $secure,   // secure: solo HTTPS en producción
            true,      // httpOnly: JS no puede leerla
            false,
            $sameSite
        );
    }

    private function forgetAuthCookie()
    {
        return \Illuminate\Support\Facades\Cookie::forget(\App\Http\Middleware\AuthTokenFromCookie::COOKIE_NAME);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $isPlatformUser = $user instanceof \App\Models\PlatformUser;
            SecurityLogger::log('auth_logout', "Sesión cerrada por: {$user->email}", $isPlatformUser ? null : $user->tenant_id, $user->id);
        }
        $request->user()->currentAccessToken()->delete();
        // §43: además de borrar el token en BD, expirar la cookie httpOnly.
        return response()->json(['message' => 'Sesión cerrada exitosamente'])
            ->withCookie($this->forgetAuthCookie());
    }

    /**
     * §37: Modo Kiosco — login por PIN en tablet compartida. Reutiliza
     * employees.security_pin (mismo mecanismo que testigos de Apertura de Emergencia
     * y aprobación de Ley Silla), no crea un secreto paralelo.
     *
     * No hace falta un mecanismo de "tenant del dispositivo": employee_id ya identifica
     * una fila única en employees, y esa fila ya trae su propio tenant_id — se resuelve
     * el tenant a partir del empleado, no al revés.
     */
    public function kioskLogin(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|integer',
            'pin' => 'required|string',
        ]);

        $genericError = ['success' => false, 'message' => 'PIN incorrecto o colaborador no válido.'];

        // r2-b: throttle por EMPLEADO (sólo fallidos) + backstop de enumeración por IP.
        // Ver las constantes KIOSK_* arriba para el porqué del diseño.
        $ip = $request->ip();
        $keyEmpleado = 'kiosk-fail:' . (int) $request->employee_id . '|' . $ip;
        $keyEnum = 'kiosk-enum-ip:' . $ip;

        if (RateLimiter::tooManyAttempts($keyEmpleado, self::KIOSK_MAX_POR_EMPLEADO)
            || RateLimiter::tooManyAttempts($keyEnum, self::KIOSK_MAX_ENUMERACION)) {
            \Illuminate\Support\Facades\Log::warning('Kiosk login throttled', ['employee_id' => $request->employee_id, 'ip' => $ip]);
            return response()->json([
                'success' => false,
                'message' => 'Demasiados intentos fallidos. Espera unos minutos e inténtalo de nuevo.',
            ], 429);
        }

        $employee = \App\Models\Employee::withoutGlobalScopes()->find($request->employee_id);
        if (!$employee || !$employee->security_pin) {
            // Empleado inexistente o sin PIN: huele a enumeración, alimenta ambos contadores.
            RateLimiter::hit($keyEmpleado, self::LOGIN_DECAY_SEGUNDOS);
            RateLimiter::hit($keyEnum, self::LOGIN_DECAY_SEGUNDOS);
            return response()->json($genericError, 422);
        }
        if (!Hash::check($request->pin, $employee->security_pin)) {
            // PIN equivocado de una persona REAL: cuenta sólo contra ese empleado, nunca
            // contra la tablet entera (el backstop por IP es sólo para enumeración).
            RateLimiter::hit($keyEmpleado, self::LOGIN_DECAY_SEGUNDOS);
            return response()->json($genericError, 422);
        }

        $user = $employee->user_id ? User::withoutGlobalScope(\App\Scopes\TenantScope::class)->find($employee->user_id) : null;
        if (!$user) {
            return response()->json($genericError, 422);
        }

        // Acertó: su contador se limpia para que el typo de ayer no le cobre el fichaje de hoy.
        RateLimiter::clear($keyEmpleado);

        $expiresAt = now()->addMinutes(15);
        $token = $user->createToken('kiosk_session', ['*'], $expiresAt)->plainTextToken;

        SecurityLogger::log('auth_success', "Inicio de sesión de kiosco: {$user->email}", $user->tenant_id, $user->id);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user,
            'tenant' => $user->tenant,
            'expires_at' => $expiresAt->toIso8601String(),
        ])->cookie($this->makeAuthCookie($token, 15)); // §43: cookie que caduca con el token (15 min)
    }

    public function kioskLogout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Sesión de kiosco cerrada.'])
            ->withCookie($this->forgetAuthCookie());
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if ($user instanceof \App\Models\User) {
            $user->load('tenant');
        } elseif ($user instanceof \App\Models\PlatformUser) {
            if ($user->role !== \App\Enums\UserRole::SUPPORT_AGENT->value) {
                $user->role = \App\Enums\UserRole::PLATFORM_ADMIN->value;
            }
        }
        return response()->json([
            // toAuthPayload: el puesto sale del expediente, no del duplicado stale users.job_role_id.
            'user' => $user instanceof \App\Models\User ? $user->toAuthPayload() : $user,
            'tenant' => $user instanceof \App\Models\User ? $user->tenant : null
        ]);
    }

    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string'
        ]);

        $user = $request->user();
        $table = $user instanceof \App\Models\PlatformUser ? 'platform_users' : 'users';
        \Illuminate\Support\Facades\DB::table($table)
            ->where('id', $user->id)
            ->update([
                'fcm_token' => $request->fcm_token,
                'updated_at' => now()
            ]);

        return response()->json(['message' => 'FCM token actualizado exitosamente.']);
    }

    /**
     * §52: flujo estándar "olvidé mi contraseña" (para empleados que ya activaron su
     * cuenta con su propia contraseña; NO es el PIN de invitación). Siempre responde
     * éxito para no revelar si el correo existe. El envío es best-effort: si el correo
     * falla (sin dominio/servicio configurado aún), no rompe la petición.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('email', $request->email)->first();

        if ($user) {
            $token = \Illuminate\Support\Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $base = rtrim(config('app.frontend_url') ?? config('app.url') ?? '', '/');
            $resetUrl = $base . '/reset-password?token=' . $token . '&email=' . urlencode($request->email);

            try {
                \Illuminate\Support\Facades\Mail::to($request->email)->send(new \App\Mail\PasswordResetMail($resetUrl));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('No se pudo enviar el correo de reset de contraseña: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        $row = \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$row || !Hash::check($request->token, $row->token)) {
            return response()->json(['success' => false, 'message' => 'El enlace de restablecimiento es inválido.'], 422);
        }

        if (\Carbon\Carbon::parse($row->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['success' => false, 'message' => 'El enlace de restablecimiento venció. Solicita uno nuevo.'], 422);
        }

        $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Cuenta no encontrada.'], 404);
        }

        // El enlace de reset no puede ser la puerta trasera del blocklist: sin este candado,
        // un usuario marcado pedía el enlace y volvía a ponerse password123.
        if (in_array($request->password, User::CONTRASENAS_CONOCIDAS, true)) {
            return response()->json(['success' => false, 'message' => 'Esa contraseña es de las que todo el mundo conoce. Elige una distinta.'], 422);
        }

        // La contraseña nueva la eligió su dueño (vía enlace de reset): sin cambio forzado.
        // Y el reset expulsa TODAS las sesiones: es el flujo de "alguien más tiene mi contraseña".
        $user->update(['password' => Hash::make($request->password), 'must_change_password' => false]);
        $user->tokens()->delete();
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['success' => true, 'message' => 'Contraseña restablecida. Ya puedes iniciar sesión.']);
    }

    public function loginSocial(Request $request)
    {
        $request->validate([
            'provider' => 'required|string|in:google,apple,samsung',
            'provider_id' => 'required_without:id_token|string',
            'id_token' => 'nullable|string',
            'email' => 'nullable|email'
        ]);

        $provider = $request->provider;
        $providerId = $request->provider_id;
        $email = $request->email;
        $name = $request->input('name');

        if ($provider === 'google' && $request->has('id_token') && !empty($request->id_token)) {
            try {
                $verifyUrl = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $request->id_token;
                $response = \Illuminate\Support\Facades\Http::get($verifyUrl);
                
                if ($response->failed()) {
                    return response()->json(['error' => 'Token de Google inválido o vencido.'], 401);
                }

                $googleData = $response->json();
                
                // Verificar que la audiencia coincida con el Client ID de Google si está configurado en services.php
                $configuredClientId = config('services.google.client_id');
                if ($configuredClientId && isset($googleData['aud']) && $googleData['aud'] !== $configuredClientId) {
                    return response()->json(['error' => 'Validación de cliente de Google fallida.'], 401);
                }

                $email = $googleData['email'] ?? null;
                $name = $googleData['name'] ?? null;
                $providerId = $googleData['sub'] ?? null;

                if (!$email || !$providerId) {
                    return response()->json(['error' => 'Datos de Google incompletos.'], 400);
                }
            } catch (\Exception $e) {
                return response()->json(['error' => 'Error al validar con Google: ' . $e->getMessage()], 500);
            }
        }

        // Determine column name
        $column = $provider . '_id';

        // 1. Try to find user by the social ID
        $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->withTrashed()
            ->where($column, $providerId)
            ->with('tenant')
            ->first();

        // 2. If not found by social ID, try to find by email and automatically link
        if (!$user && $email) {
            $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->withTrashed()
                ->where('email', $email)
                ->with('tenant')
                ->first();

            if ($user) {
                // Link the social ID
                $user->update([$column => $providerId]);
            }
        }

        // Si el usuario estaba en borrado lógico, restaurarlo
        if ($user && $user->trashed()) {
            $user->restore();
        }

        // 3. If still not found, check platform users
        $isPlatformUser = false;
        if (!$user) {
            if ($email) {
                $user = \App\Models\PlatformUser::where('email', $email)->first();
                if ($user) {
                    $isPlatformUser = true;
                }
            }
        }

        // 4. If still not found, register new global user (pre-registration state)
        if (!$user) {
            if ($email) {
                $user = User::create([
                    'name' => $request->input('name') ?? explode('@', $email)[0],
                    'email' => $email,
                    'role' => 'admin', // Will become admin once company is created
                    $column => $providerId,
                    'password' => Hash::make(bin2hex(random_bytes(16))),
                    'tenant_id' => null,
                    'is_active' => true
                ]);
            } else {
                return response()->json(['error' => 'No se encontró ninguna cuenta vinculada con estas credenciales.'], 404);
            }
        }

        // Si el usuario pertenece a una empresa que ya fue eliminada, liberar el tenant_id (correo huérfano)
        if (!$isPlatformUser && $user->tenant_id !== null) {
            $tenant = \App\Models\Tenant::find($user->tenant_id);
            if (!$tenant || $tenant->trashed()) {
                $user->update(['tenant_id' => null]);
                $user->refresh();
            }
        }

        if (!$user->is_active) {
            return response()->json(['error' => 'Usuario inactivo / archivado'], 403);
        }

        // Check if tenant is active
        if (!$isPlatformUser && $user->role !== \App\Enums\UserRole::PLATFORM_ADMIN->value) {
            $tenant = $user->tenant;
            if ($tenant && !$tenant->is_active) {
                return response()->json([
                    'error' => 'Empresa suspendida',
                    'message' => 'El acceso a esta empresa ha sido suspendido temporalmente por el administrador de la plataforma.'
                ], 403);
            }
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login exitoso',
            // toAuthPayload: el puesto sale del expediente, no del duplicado stale users.job_role_id.
            'user' => $user instanceof \App\Models\User ? $user->toAuthPayload() : $user,
            'tenant' => $isPlatformUser ? null : $user->tenant,
            'token' => $token
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:6'
        ]);

        $email = strtolower(trim($request->email));
        $existingUser = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->withTrashed()
            ->where('email', $email)
            ->first();

        if ($existingUser) {
            $tenant = $existingUser->tenant_id ? \App\Models\Tenant::find($existingUser->tenant_id) : null;

            if ($tenant && !$tenant->trashed()) {
                $companyName = $tenant->name;
                return response()->json([
                    'error' => "El correo {$email} ya está registrado en la plataforma y pertenece a la empresa '{$companyName}'. Inicia sesión o utiliza un correo distinto para tu nueva empresa.",
                    'is_duplicated' => true,
                    'company_name' => $companyName
                ], 409);
            }

            // Si la empresa fue eliminada (o tenant_id es NULL) o el usuario está borrado lógicamente:
            // Es un correo huérfano o cuenta libre. Restaurar y resetear para pre-registro.
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }
            $existingUser->update([
                'name' => $request->name,
                'password' => Hash::make($request->password),
                'role' => 'admin',
                'tenant_id' => null,
                'is_active' => true
            ]);
            $user = $existingUser;
        } else {
            $user = User::create([
                'name' => $request->name,
                'email' => $email,
                'password' => Hash::make($request->password),
                'role' => 'admin',
                'tenant_id' => null,
                'is_active' => true
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Pre-registro exitoso',
            'user' => $user,
            'token' => $token
        ], 201);
    }

    /**
     * Alarma de traslado (R87, Fase 3 / T3.2): el colaborador fija cuántos minutos ANTES de su turno
     * quiere el aviso local (0/15/30/45/60; 0 = desactivada). Se persiste en el EXPEDIENTE (la jornada
     * vive ahí y el reloj arma su estado desde `employees` — una columna en `users` se perdería, saga
     * R41/R45). El aviso en sí es una notificación LOCAL del dispositivo; el backend sólo guarda la
     * preferencia. Se devuelve el payload de auth para refrescar `currentUser` sin recargar (R53).
     */
    public function preShiftAlarm(Request $request)
    {
        $request->validate([
            'minutes' => ['required', 'integer', 'in:0,15,30,45,60'],
        ]);

        $user = $request->user();
        if ($user instanceof \App\Models\PlatformUser) {
            return response()->json(['success' => false, 'message' => 'No aplica a este usuario.'], 422);
        }

        $affected = \Illuminate\Support\Facades\DB::table('employees')
            ->where('user_id', $user->id)
            ->update(['pre_shift_alarm_minutes' => (int) $request->minutes, 'updated_at' => now()]);

        if ($affected === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes un expediente; no se puede configurar la alarma.',
            ], 422);
        }

        $updatedUser = \App\Models\User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->with('tenant')->find($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Alarma de traslado actualizada.',
            'user' => $updatedUser->toAuthPayload(),
        ]);
    }

    /**
     * R102 (espejo de preShiftAlarm): la Academia lo llama al APROBAR el curso de Puntualidad.
     * Re-estampa `employees.punctuality_reset_at` → el conteo del bloqueo del dial
     * (`punctuality_lockout_count`, ver toAuthPayload) vuelve a 0 y el checador se desbloquea.
     * Funciona también para el curso SINTÉTICO (id 999): no toca FKs de cursos — la trampa
     * documentada en Academia.tsx que dejaba el checador bloqueado PARA SIEMPRE.
     */
    public function punctualityCourseReset(Request $request)
    {
        $user = $request->user();
        if ($user instanceof \App\Models\PlatformUser) {
            return response()->json(['success' => false, 'message' => 'No aplica a este usuario.'], 422);
        }

        $affected = \Illuminate\Support\Facades\DB::table('employees')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->update(['punctuality_reset_at' => now(), 'updated_at' => now()]);

        if ($affected === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes un expediente; no hay bloqueo que resetear.',
            ], 422);
        }

        $updatedUser = \App\Models\User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->with('tenant')->find($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Checador desbloqueado tras completar el curso de Puntualidad.',
            'user' => $updatedUser->toAuthPayload(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        // `phone` se eliminó de `users` en la migración 2026_06_26_010708 (vive en `employees`).
        // La regla `unique:users,phone` y el write quedaron huérfanos: hoy no revientan sólo porque
        // ningún cliente manda `phone` (el `nullable` corta la regla antes de consultar), pero en
        // cuanto alguno lo mande es un 500 (SQLSTATE 42703, columna inexistente). Se retiran.
        $request->validate([
            'name' => 'required|string|max:255',
            'avatar' => 'nullable|string',
            'academy_assistant_enabled' => 'nullable|boolean',
        ]);

        $esPlatformUser = $user instanceof \App\Models\PlatformUser;

        $updates = [
            'name' => $request->name,
            'updated_at' => now()
        ];

        // `platform_users` NO tiene columna `avatar` (comprobado contra el esquema: sólo
        // id, name, email, password, role, is_active, remember_token, timestamps y 2FA).
        // Escribirla ahí es el MISMO 500 por columna inexistente que el comentario de
        // arriba dice haber quitado para `phone`, y aquí seguía vivo: cualquier
        // platform_admin que guardara su perfil recibía SQLSTATE 42703.
        if (!$esPlatformUser) {
            $updates['avatar'] = $request->avatar;
        }

        $table = $esPlatformUser ? 'platform_users' : 'users';
        \Illuminate\Support\Facades\DB::table($table)
            ->where('id', $user->id)
            ->update($updates);

        // Espejar al EXPEDIENTE: el Reloj arma su lista de colaboradores desde `employees`
        // (`ClockController::getState`), así que sin esto un colaborador que se renombraba o
        // cambiaba su foto seguía saliendo con los datos viejos en el checador y en el monitor,
        // indefinidamente. `uploadAvatar` ya espejaba; este camino no. Query builder crudo, igual
        // que arriba (no pasa por fillable) y filtrado por user_id (aislamiento conservado).
        if (!($user instanceof \App\Models\PlatformUser)) {
            \Illuminate\Support\Facades\DB::table('employees')
                ->where('user_id', $user->id)
                ->update([
                    'name' => $request->name,
                    'avatar' => $request->avatar,
                    'updated_at' => now()
                ]);
        }

        // §38 (línea §1–§42): academy_assistant_enabled vive dentro de employees.clock_preferences —
        // se mergea en vez de sobreescribir para no perder otras preferencias futuras.
        if (!($user instanceof \App\Models\PlatformUser) && $request->has('academy_assistant_enabled')) {
            $employeeRow = \Illuminate\Support\Facades\DB::table('employees')->where('user_id', $user->id)->first();
            if ($employeeRow) {
                $preferences = $employeeRow->clock_preferences ? json_decode($employeeRow->clock_preferences, true) : [];
                $preferences['academy_assistant_enabled'] = (bool) $request->boolean('academy_assistant_enabled');
                \Illuminate\Support\Facades\DB::table('employees')
                    ->where('user_id', $user->id)
                    ->update(['clock_preferences' => json_encode($preferences)]);
            }
        }

        if ($user instanceof \App\Models\PlatformUser) {
            $updatedUser = \App\Models\PlatformUser::find($user->id);
        } else {
            $updatedUser = \App\Models\User::withoutGlobalScope(\App\Scopes\TenantScope::class)->with('tenant')->find($user->id);
        }

        $academyAssistantEnabled = null;
        if (!($user instanceof \App\Models\PlatformUser)) {
            $rawPreferences = \Illuminate\Support\Facades\DB::table('employees')->where('user_id', $user->id)->value('clock_preferences');
            $academyAssistantEnabled = $rawPreferences
                ? (json_decode($rawPreferences, true)['academy_assistant_enabled'] ?? false)
                : false;
        }

        return response()->json([
            // toAuthPayload, no el modelo crudo: `MyAccountModal:133` y `OnboardingWizard:221,348`
            // hacen `setCurrentUser(res.data.user)` — REEMPLAZO TOTAL, no spread. Con el modelo crudo
            // la sesión perdía `can_clock_in` (R53), el `job_role_id` del expediente y la jornada.
            'message' => 'Perfil actualizado exitosamente',
            'user' => $updatedUser instanceof \App\Models\User ? $updatedUser->toAuthPayload() : $updatedUser,
            'academy_assistant_enabled' => $academyAssistantEnabled,
        ]);
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        $user = $request->user();

        // Las cuentas de PLATAFORMA no tienen dónde guardar una foto (`platform_users` no
        // tiene columna `avatar`). Antes esto reventaba con un 500 por columna inexistente;
        // se dice y no se sube el archivo, en vez de contestar "subido con éxito" y no
        // guardar nada.
        if ($user instanceof \App\Models\PlatformUser) {
            return response()->json([
                'message' => 'Las cuentas de plataforma no tienen foto de perfil.',
            ], 422);
        }

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');

            // Nombre uuid, no `avatar_{user_id}_{time()}` (2026-08-08).
            //
            // La foto de perfil se sirve como estático desde `public/`, y hasta hoy su nombre
            // era ENUMERABLE: el id de usuario es secuencial y el `time()` cae en una ventana
            // pequeña, así que un desconocido podía barrer las caras de la plantilla probando
            // combinaciones. Con un uuid el nombre deja de ser adivinable.
            //
            // ponytail: sigue siendo una URL de capacidad (quien tenga el enlace la ve). Un
            // <img src> no puede mandar el Bearer y la cookie de sesión es Secure —
            // inservible mientras el despliegue siga en HTTP plano. Cuando esté en HTTPS,
            // pasarlo a URL firmada temporal o a auth por cookie, como la evidencia de
            // comedor. Hoy no hay ni un avatar subido en ninguna instancia.
            $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $filename = \Illuminate\Support\Str::uuid() . '.' . $extension;

            $destinationPath = public_path('uploads/avatars');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $file->move($destinationPath, $filename);

            $avatarUrl = '/uploads/avatars/' . $filename;

            // `platform_users` no tiene columna `avatar`: escribirla es un 500 por columna
            // inexistente (mismo caso que en updateProfile).
            if (!($user instanceof \App\Models\PlatformUser)) {
                \Illuminate\Support\Facades\DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'avatar' => $avatarUrl,
                        'updated_at' => now()
                    ]);
            }

            if (!($user instanceof \App\Models\PlatformUser)) {
                \Illuminate\Support\Facades\DB::table('employees')
                    ->where('user_id', $user->id)
                    ->update([
                        'avatar' => $avatarUrl,
                        'updated_at' => now()
                    ]);
            }

            return response()->json([
                'message' => 'Avatar subido exitosamente',
                'avatar_url' => $avatarUrl
            ]);
        }

        return response()->json(['error' => 'No se cargó ningún archivo.'], 400);
    }


    public function changePassword(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed|different:current_password'
        ], [
            // Sin esto los mensajes salen del vendor en inglés, en una pantalla toda en español.
            'new_password.min' => 'La contraseña nueva debe tener al menos 6 caracteres.',
            'new_password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'new_password.different' => 'La contraseña nueva no puede ser igual a la actual.',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'error' => 'La contraseña actual es incorrecta.'
            ], 422);
        }

        // Bloque 1: cambiar password123 por 123456 dejaría la vuelta entera en nada.
        if (in_array($request->new_password, User::CONTRASENAS_CONOCIDAS, true)) {
            return response()->json([
                'error' => 'Esa contraseña es de las que todo el mundo conoce. Elige una distinta.'
            ], 422);
        }

        $table = $user instanceof \App\Models\PlatformUser ? 'platform_users' : 'users';
        \Illuminate\Support\Facades\DB::table($table)
            ->where('id', $user->id)
            ->update([
                'password' => Hash::make($request->new_password),
                // La puso su dueño: se levanta el cambio forzado (ambas tablas tienen la columna).
                'must_change_password' => false,
                'updated_at' => now()
            ]);

        // Cambiar la contraseña EXPULSA a cualquier otra sesión. Sanctum no caduca tokens
        // (expiration=null): sin esto, quien entró con la contraseña vieja conserva un token
        // eterno que revive en cuanto el dueño legítimo completa el cambio forzado.
        $actual = $user->currentAccessToken();
        $otros = $user->tokens();
        if ($actual instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $otros->where('id', '!=', $actual->id);
        }
        $otros->delete();

        return response()->json([
            'message' => 'Contraseña actualizada exitosamente'
        ]);
    }

    /**
     * Estado #1 del dialer (Fichaje Bloqueado por 3 retardos). Ver ClockService::getPunctualityStatus
     * para la regla de negocio completa (no se reinicia por periodo de nómina).
     */
    public function punctualityStatus(Request $request)
    {
        $status = $this->clockService->getPunctualityStatus($request->user());

        return response()->json($status);
    }

    /**
     * §63: racha de puntualidad del colaborador para la cinta de bienvenida del Reloj.
     * Devuelve { streak_days, last_late_date } calculado sobre time_entries reales.
     */
    public function punctualityStreak(Request $request)
    {
        $streak = $this->clockService->getPunctualityStreak($request->user());

        return response()->json($streak);
    }

    /**
     * Configura el PIN de seguridad del empleado (distinto del pin_code de invitación
     * de onboarding). Se usa para autorizar acciones sensibles como la co-validación
     * de testigos en "Apertura de Emergencia". Requiere la contraseña actual, igual
     * que changePassword, por tratarse de un secreto que habilita acciones con peso
     * legal/de nómina.
     */
    public function updateSecurityPin(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña actual es incorrecta.'
            ], 422);
        }

        $employee = $user->employee;
        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Tu cuenta no tiene un perfil de empleado asociado.'
            ], 422);
        }

        $employee->security_pin = Hash::make($request->pin);
        $employee->save();

        return response()->json([
            'success' => true,
            'message' => 'PIN de seguridad actualizado.'
        ]);
    }

    /**
     * Variante §1–§42 de la alarma de traslado (PUT /me/pre-shift-alarm): persiste en
     * users.pre_shift_alarm_minutes (columna legacy conservada — drop diferido de F2). La
     * variante canónica de la línea del Reloj (POST, preShiftAlarm) escribe en el EXPEDIENTE,
     * que es lo que lee toAuthPayload; conciliar el FE hacia una sola en F3-FE.
     */
    public function updatePreShiftAlarm(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'minutes' => ['nullable', 'integer', \Illuminate\Validation\Rule::in([15, 30, 45, 60])],
        ]);

        $minutes = $validated['minutes'] ?? null;

        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $user->id)
            ->update([
                'pre_shift_alarm_minutes' => $minutes,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'pre_shift_alarm_minutes' => $minutes,
        ]);
    }

    public function requestRestDay(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'requested_day' => 'required|string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
            'justification' => 'required|string|max:1000'
        ]);

        $contingencyId = \Illuminate\Support\Facades\DB::table('contingencies')->insertGetId([
            'user_id' => $user->id,
            'type' => 'rest_day_change',
            'status' => 'pending',
            'justification_text' => "Cambio de día de descanso a: {$request->requested_day}. Razón: {$request->justification}",
            'tenant_id' => $user->tenant_id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $contingency = \Illuminate\Support\Facades\DB::table('contingencies')->find($contingencyId);

        return response()->json([
            'message' => 'Solicitud de día de descanso registrada exitosamente',
            'request' => $contingency
        ]);
    }

    public function getRestDayRequests(Request $request)
    {
        $user = $request->user();
        $requests = \Illuminate\Support\Facades\DB::table('contingencies')
            ->where('user_id', $user->id)
            ->where('type', 'rest_day_change')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'requests' => $requests
        ]);
    }

    public function updateSecurity(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'two_factor_enabled' => 'nullable|boolean',
            'biometric_key' => 'nullable|string'
        ]);

        $updates = [];
        if ($request->has('two_factor_enabled')) {
            $updates['two_factor_enabled'] = $request->two_factor_enabled;
            if ($request->two_factor_enabled && !$user->two_factor_secret) {
                // Generate a mock secret
                $updates['two_factor_secret'] = 'secret_' . bin2hex(random_bytes(10));
            }
        }
        if ($request->has('biometric_key')) {
            $updates['biometric_key'] = $request->biometric_key;
        }

        $table = $user instanceof \App\Models\PlatformUser ? 'platform_users' : 'users';
        \Illuminate\Support\Facades\DB::table($table)
            ->where('id', $user->id)
            ->update($updates);

        $updatedUser = User::withoutGlobalScope(\App\Scopes\TenantScope::class)->find($user->id);

        return response()->json([
            'message' => 'Seguridad actualizada exitosamente',
            'user' => $updatedUser
        ]);
    }

}
