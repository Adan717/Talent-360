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

        return response()->json([
            'message' => 'Login exitoso',
            // toAuthPayload: el puesto sale del expediente, no del duplicado stale users.job_role_id.
            'user' => $user instanceof \App\Models\User ? $user->toAuthPayload() : $user,
            'tenant' => $isPlatformUser ? null : $user->tenant,
            'token' => $token,
            'requires_2fa' => $requires2fa
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $isPlatformUser = $user instanceof \App\Models\PlatformUser;
            SecurityLogger::log('auth_logout', "Sesión cerrada por: {$user->email}", $isPlatformUser ? null : $user->tenant_id, $user->id);
        }
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada exitosamente']);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if ($user instanceof \App\Models\User) {
            $user->load('tenant');
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
            ->where($column, $providerId)
            ->with('tenant')
            ->first();

        // 2. If not found by social ID, try to find by email and automatically link
        if (!$user && $email) {
            $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('email', $email)
                ->with('tenant')
                ->first();

            if ($user) {
                // Link the social ID
                $user->update([$column => $providerId]);
            }
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
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'admin',
            'tenant_id' => null,
            'is_active' => true
        ]);

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

        $updates = [
            'name' => $request->name,
            'avatar' => $request->avatar,
            'updated_at' => now()
        ];

        $table = $user instanceof \App\Models\PlatformUser ? 'platform_users' : 'users';
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

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            
            $destinationPath = public_path('uploads/avatars');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $file->move($destinationPath, $filename);
            
            $avatarUrl = '/uploads/avatars/' . $filename;

            $table = $user instanceof \App\Models\PlatformUser ? 'platform_users' : 'users';
            \Illuminate\Support\Facades\DB::table($table)
                ->where('id', $user->id)
                ->update([
                    'avatar' => $avatarUrl,
                    'updated_at' => now()
                ]);

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
            'new_password' => 'required|string|min:6|confirmed'
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'error' => 'La contraseña actual es incorrecta.'
            ], 422);
        }

        $table = $user instanceof \App\Models\PlatformUser ? 'platform_users' : 'users';
        \Illuminate\Support\Facades\DB::table($table)
            ->where('id', $user->id)
            ->update([
                'password' => Hash::make($request->new_password),
                'updated_at' => now()
            ]);

        return response()->json([
            'message' => 'Contraseña actualizada exitosamente'
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

    /**
     * §37: Modo Kiosco — login por PIN en tablet compartida. Reutiliza
     * employees.security_pin (mismo mecanismo que testigos de Apertura de Emergencia
     * y aprobación de Ley Silla), no crea un secreto paralelo. El tenant se resuelve
     * a partir del empleado (employee_id → employees.tenant_id), no al revés.
     */
    public function kioskLogin(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|integer',
            'pin' => 'required|string',
        ]);

        $genericError = ['success' => false, 'message' => 'PIN incorrecto o colaborador no válido.'];

        $employee = \App\Models\Employee::withoutGlobalScopes()->find($request->employee_id);
        if (!$employee || !$employee->security_pin || !Hash::check($request->pin, $employee->security_pin)) {
            return response()->json($genericError, 422);
        }

        $user = $employee->user_id ? User::withoutGlobalScope(\App\Scopes\TenantScope::class)->find($employee->user_id) : null;
        if (!$user) {
            return response()->json($genericError, 422);
        }

        $expiresAt = now()->addMinutes(15);
        $token = $user->createToken('kiosk_session', ['*'], $expiresAt)->plainTextToken;

        SecurityLogger::log('auth_success', "Inicio de sesión de kiosco: {$user->email}", $user->tenant_id, $user->id);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $user,
            'tenant' => $user->tenant,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function kioskLogout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Sesión de kiosco cerrada.']);
    }

    /** Estado #1 de la matriz (bloqueo por 3 retardos) — §1–§42; ver ClockService::getPunctualityStatus. */
    public function punctualityStatus(Request $request)
    {
        $status = $this->clockService->getPunctualityStatus($request->user());

        return response()->json($status);
    }

    /**
     * Configura el PIN de seguridad del empleado (distinto del pin_code de invitación de
     * onboarding). Autoriza acciones sensibles (testigos de Apertura de Emergencia, Ley Silla).
     * Requiere la contraseña actual, por tratarse de un secreto con peso legal/de nómina.
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
}
