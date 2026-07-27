<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Helpers\SecurityLogger;

class AuthController extends Controller
{
    protected $clockService;

    public function __construct(\App\Services\ClockService $clockService)
    {
        $this->clockService = $clockService;
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('email', strtolower(trim($request->email)))
            ->with('tenant')
            ->first();

        \Illuminate\Support\Facades\Log::info("Login attempt details", [
            'input_email' => $request->email,
            'input_password_len' => strlen($request->password),
            'user_found' => $user ? true : false,
            'user_id' => $user ? $user->id : null,
            'user_email' => $user ? $user->email : null,
            'password_match' => $user ? Hash::check($request->password, $user->password) : false,
        ]);

        $isPlatformUser = false;
        if (!$user) {
            $user = \App\Models\PlatformUser::where('email', strtolower(trim($request->email)))->first();
            if ($user) {
                $isPlatformUser = true;
            }
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
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

        $token = $user->createToken('auth_token')->plainTextToken;
        $requires2fa = !$isPlatformUser && $user->two_factor_enabled;

        SecurityLogger::log('auth_success', "Inicio de sesión exitoso de: {$user->email}", $isPlatformUser ? null : $user->tenant_id, $user->id);

        // §43: además del JSON (para compatibilidad con el frontend actual mientras
        // migra), el token viaja en una cookie httpOnly que JavaScript no puede leer.
        return response()->json([
            'message' => 'Login exitoso',
            'user' => $user,
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

        return cookie(
            \App\Http\Middleware\AuthTokenFromCookie::COOKIE_NAME,
            $token,
            $minutes,
            '/',
            null,
            $secure,   // secure: solo HTTPS en producción
            true,      // httpOnly: JS no puede leerla
            false,
            'Lax'
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
        }
        return response()->json([
            'user' => $user,
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

        $user->update(['password' => Hash::make($request->password)]);
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
            'user' => $user,
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

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'name' => 'required|string|max:255',
            'avatar' => 'nullable|string',
            'phone' => 'nullable|string|max:30|unique:users,phone,' . $user->id,
            'academy_assistant_enabled' => 'nullable|boolean',
        ], [
            'phone.unique' => 'Este número de WhatsApp ya se encuentra registrado con otra empresa.'
        ]);

        $updates = [
            'name' => $request->name,
            'avatar' => $request->avatar,
            'updated_at' => now()
        ];

        if ($request->has('phone')) {
            $updates['phone'] = $request->phone;
        }

        $table = $user instanceof \App\Models\PlatformUser ? 'platform_users' : 'users';
        \Illuminate\Support\Facades\DB::table($table)
            ->where('id', $user->id)
            ->update($updates);

        // §38: academy_assistant_enabled vive dentro de employees.clock_preferences
        // (columna json ya existente, sin conectar a ningún controlador todavía) — se
        // mergea en vez de sobreescribir para no perder otras preferencias futuras.
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
            'message' => 'Perfil actualizado exitosamente',
            'user' => $updatedUser,
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
     * Configura la alarma de traslado pre-turno del perfil del usuario
     * ("Configura tu alarma" — sección 3/5 del dialer). El backend solo persiste
     * la preferencia; la notificación en sí la dispara el frontend con la Notification API.
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
