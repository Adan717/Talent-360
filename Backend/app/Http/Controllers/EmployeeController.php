<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Employee;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    public function index()
    {
        // Eloquent automáticamente filtra por tenant_id gracias a BelongsToTenant / Tenantable
        $employees = Employee::with('jobRole:id,name', 'user')->get();
        return response()->json($employees);
    }

    /**
     * H1 (prueba en vivo 2026-07-29) — sincroniza `salary` y `base_salary`.
     *
     * `base_salary` es la columna de verdad del dinero: el costo financiero de cada tarea
     * (`base_salary > 0 ? base_salary : 300.00` en TaskAssignmentController y
     * TaskSyncController) y el snapshot inmutable del ponche (`base_salary_at_time`) sólo
     * la leen a ella. Pero la pantalla de alta manda `salary`, así que `base_salary` quedaba
     * NULL y TODOS los colaboradores se calculaban con el default de $300/día.
     *
     * Se espeja en ambos sentidos: si llega una, se copia a la otra; si llegan las dos,
     * `base_salary` manda (es la autoritativa). Un `null` explícito se respeta en ambas.
     */
    private function espejarSueldo(array $data): array
    {
        $tieneSalary = array_key_exists('salary', $data);
        $tieneBase = array_key_exists('base_salary', $data);

        if ($tieneBase && $data['base_salary'] !== null) {
            $data['salary'] = $data['base_salary'];
        } elseif ($tieneSalary && $data['salary'] !== null) {
            $data['base_salary'] = $data['salary'];
        } elseif ($tieneBase && $data['base_salary'] === null && !$tieneSalary) {
            $data['salary'] = null;
        } elseif ($tieneSalary && $data['salary'] === null && !$tieneBase) {
            $data['base_salary'] = null;
        }

        return $this->derivarSalarioDiario($data);
    }

    /**
     * Periodicidad configurable (2026-08-03, aprobada por producto): si la captura declara con
     * qué periodicidad viene el monto, se convierte y almacena el SALARIO DIARIO — la unidad
     * única que la nómina consume (ver `App\Support\SalarioDiario`).
     *
     * Sin `salary_periodicity` no se toca nada: el expediente queda como legado (salario_diario
     * NULL) y la nómina sigue usando la fórmula histórica. La migración de lo ya capturado es
     * una decisión con informe de impacto de por medio, no un efecto colateral de esta función.
     */
    private function derivarSalarioDiario(array $data): array
    {
        $periodicidad = $data['salary_periodicity'] ?? null;
        unset($data['salary_periodicity']); // no es columna de `employees`

        if (!\App\Support\SalarioDiario::esValida($periodicidad)) {
            return $data;
        }

        $monto = $data['base_salary'] ?? null;

        if ($monto !== null) {
            $data['salario_diario'] = \App\Support\SalarioDiario::desde((float) $monto, $periodicidad);
            $data['periodicidad_captura'] = $periodicidad;
        }

        return $data;
    }

    /**
     * Primer correo libre a partir del que se generó del nombre (homónimos, 2026-08-08).
     *
     *   juanperez@empresa.com  ->  juanperez2@empresa.com  ->  juanperez3@empresa.com
     *
     * Se comprueba contra `employees` de la empresa y contra `users`, que es único global.
     */
    private function correoDisponible(string $email, ?int $tenantId): string
    {
        [$local, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        if ($dominio === '') {
            return $email;
        }

        // Tope defensivo: 99 homónimos es de sobra y evita un bucle infinito si algo falla.
        for ($n = 2; $n <= 99; $n++) {
            $candidato = "{$local}{$n}@{$dominio}";

            $tomadoEnEmpresa = Employee::withoutGlobalScopes()
                ->where('email', $candidato)
                ->where('tenant_id', $tenantId)
                ->exists();

            $tomadoComoCuenta = User::withoutGlobalScopes()->where('email', $candidato)->exists();

            if (!$tomadoEnEmpresa && !$tomadoComoCuenta) {
                return $candidato;
            }
        }

        // Sin hueco en los 99: se cae a algo único de todas formas, nunca a pisar a nadie.
        return $local . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)) . '@' . $dominio;
    }

    /**
     * Horario de la EMPRESA cuando el alta no trae uno (decisión del dueño, 2026-08-08).
     *
     * Antes `shiftStart`/`shiftEnd` quedaban NULL y el reloj asumía 09:00 para todos: a quien
     * entra a las 11:00 le contaba dos horas de retardo desde su primer día. El horario de la
     * empresa vive en `system_settings.storeSchedule` (openTime/closeTime), que es lo que el
     * asistente de alta configura.
     *
     * Vive en `App\Support\HorarioDeLaEmpresa` desde 2026-08-11: era privado de aquí y por eso el
     * alta del ATS (que escribe `Employee::create` por su cuenta) clavaba 09:00–18:00 en duro.
     */
    private function heredarHorarioDeLaEmpresa(array $data, ?int $tenantId): array
    {
        return \App\Support\HorarioDeLaEmpresa::completar($data, $tenantId);
    }

    public function store(Request $request)
    {
        $currentUser = auth()->user() ?? auth('sanctum')->user();
        $tenantId = $currentUser ? $currentUser->tenant_id : null;
        $tenant = $currentUser ? $currentUser->tenant : null;

        // 1. Validar límite de empleados activos para plan Freemium (Límite: 10 colaboradores)
        if ($tenant) {
            $trialActive = false;
            if ($tenant->subscription_status === 'trial' || empty($tenant->subscription_status)) {
                if ($tenant->trial_ends_at) {
                    $endsAt = \Carbon\Carbon::parse($tenant->trial_ends_at);
                    if (now()->lt($endsAt)) {
                        $trialActive = true;
                    }
                }
            }

            if (!$trialActive && strtolower($tenant->plan ?? 'freemium') === 'freemium') {
                $activeCount = Employee::where('tenant_id', $tenant->id)
                    ->where('is_active_employee', true)
                    ->count();

                if ($activeCount >= 10) {
                    return response()->json([
                        'error' => 'Plan Limit Exceeded',
                        'message' => 'Has alcanzado el límite máximo de 10 colaboradores activos en el plan gratuito. Actualiza al plan Profesional para contratar más empleados.'
                    ], 403);
                }
            }
        }

        // 2. Validar límite de cuentas administrativas (Seats) por plan
        $role = strtolower($request->input('role', 'empleado'));
        $request->merge(['role' => $role]);
        if ($tenant && in_array($role, ['admin', 'supervisor'])) {
            $adminCount = User::where('tenant_id', $tenant->id)
                ->whereIn('role', ['admin', 'supervisor'])
                ->count();

            $adminLimit = 9999;
            if (strtolower($tenant->plan ?? 'freemium') === 'freemium') {
                $adminLimit = 1;
            } elseif (strtolower($tenant->plan ?? 'pro') === 'pro') {
                $adminLimit = 3;
            }

            if ($adminCount >= $adminLimit) {
                return response()->json([
                    'error' => 'Admin Limit Exceeded',
                    'message' => 'Has alcanzado el límite máximo de ' . $adminLimit . ' cuentas administrativas en tu plan ' . ucfirst($tenant->plan) . '. Por favor actualiza tu suscripción para añadir más supervisores.'
                ], 403);
            }
        }

        // H3: normalizar ANTES de todo (búsqueda de duplicados, validación y guardado). El
        // correo autogenerado por el alta salía con acentos (`adáncuéllar@...`), que exige
        // SMTPUTF8 para viajar: la invitación de bienvenida y las notificaciones fallaban en
        // silencio. Se normaliza en el request para que todo el flujo vea el mismo valor.
        $email = \App\Support\EmailNormalizer::normalizar($request->input('email'));
        if ($email !== null) {
            $request->merge(['email' => $email]);
        }

        // CONTRASEÑA (2026-08-08). Antes el default era la cadena fija `password123`: TODA la
        // plantilla de TODAS las empresas compartía la misma contraseña, conocida y publicada
        // en el propio código. Ahora, si el alta no trae una, se genera una aleatoria que nadie
        // conoce —ni nosotros— y la persona fija la suya al activar su cuenta con el PIN de la
        // invitación (ver OnboardingController::completeActivation). El admin también puede
        // ponerle una a mano desde RRHH.
        //
        // Las cuentas YA existentes no se tocan: rotarlas aquí dejaría fuera a quien está
        // trabajando hoy. Se cambian al activarse o desde el panel.
        $password = $request->input('password') ?: \Illuminate\Support\Str::random(32);

        // HOMÓNIMOS (2026-08-08). El formulario de alta NO pide correo: lo genera del nombre
        // (`slugParaCorreo`). Con dos "Juan Pérez" salía el mismo `juanperez@empresa.com`, y
        // esta rama —pensada para actualizar a quien ya existe— PISABA el expediente del
        // primero en silencio: su puesto, su sueldo y su horario quedaban reemplazados por los
        // del segundo, y la empresa se quedaba con una sola ficha para dos personas.
        //
        // Ahora sólo se actualiza si quien llama lo pide EXPLÍCITAMENTE (`actualizar_existente`,
        // que RRHH manda cuando el admin confirma que es la misma persona). Si no, el homónimo
        // recibe su propio correo (juanperez2@…) y su propio expediente.
        $existingEmployee = Employee::withoutGlobalScopes()->where('email', $email)->where('tenant_id', $tenantId)->first();

        if ($existingEmployee && !$request->boolean('actualizar_existente')) {
            $email = $this->correoDisponible($email, $tenantId);
            $request->merge(['email' => $email]);
            $existingEmployee = null;
        }

        if ($existingEmployee) {
            $data = $request->validate([
                'name' => 'required|string',
                'email' => 'required|email',
                // §49: solo roles de empresa. platform_admin/support_agent son
                // exclusivos de la tabla platform_users, nunca de users.
                'role' => 'required|string|in:admin,supervisor,empleado',
                'job_role_id' => 'nullable|integer|exists:job_roles,id',
                'contract_type' => 'nullable|string',
                'salary' => 'nullable|numeric',
                'is_active' => 'nullable|boolean',
                'is_active_employee' => 'nullable|boolean',
                'shiftStart' => 'nullable|string',
                'shiftEnd' => 'nullable|string',
                'phone' => 'nullable|string',
                'portadorLlaves' => 'nullable|string',
                'employee_id' => 'nullable|string',
                'curp' => 'nullable|string',
                'rfc' => 'nullable|string',
                'nss' => 'nullable|string',
                'address' => 'nullable|string',
                'emergency_contact_name' => 'nullable|string',
                'emergency_contact_phone' => 'nullable|string',
                'hire_date' => 'nullable|date',
                'mealMinutes' => 'nullable|integer|min:0|max:480',
                'restDay' => 'nullable|string',
                'base_salary' => 'nullable|numeric',
                'salary_periodicity' => 'nullable|string|in:diario,semanal,quincenal,mensual',
                'avatar' => 'sometimes|nullable|string',
            ]);

            // Actualizar cuenta de usuario vinculada
            if ($existingEmployee->user_id) {
                $user = User::withoutGlobalScopes()->find($existingEmployee->user_id);
                if ($user) {
                    $user->update([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'role' => $data['role'],
                        'avatar' => $data['avatar'] ?? $user->avatar,
                    ]);
                    if ($request->filled('password')) {
                        // La puso un admin (la conoce alguien más): cambio forzado al entrar.
                        $user->update(['password' => Hash::make($request->password), 'must_change_password' => true]);
                    }
                }
            }

            $existingEmployee->update($data);
            return response()->json($existingEmployee->load('user'), 200);
        }

        // 4. Validar email único global en users si se va a crear el usuario de login
        $existingUserGlobal = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($existingUserGlobal) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['The email has already been taken.']
            ]);
        }

        // SUELDO OBLIGATORIO (decisión del dueño, 2026-08-08). Sin sueldo capturado, el cálculo
        // de nómina sustituye el hueco por un default escondido de $2,400 y la persona aparece
        // con un sueldo que nadie tecleó. Se pide al dar de alta, que es cuando se sabe.
        // Se acepta por cualquiera de las dos columnas (el formulario manda `salary`, la
        // autoritativa es `base_salary`; `espejarSueldo` las sincroniza después).
        if ($request->input('base_salary') === null && $request->input('salary') === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'salary' => ['Captura el sueldo del colaborador: sin él, la nómina no puede calcularse.'],
            ]);
        }

        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            // §49: solo roles de empresa (ver nota arriba).
            'role' => 'required|string|in:admin,supervisor,empleado',
            // El puesto se valida DENTRO de la empresa: con `exists:job_roles,id` a secas se
            // aceptaba el id de un puesto de OTRO tenant.
            'job_role_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('job_roles', 'id')->where('tenant_id', $tenantId)],
            'contract_type' => 'nullable|string',
            'salary' => 'nullable|numeric|min:0.01',
            'is_active' => 'nullable|boolean',
            'is_active_employee' => 'nullable|boolean',
            'shiftStart' => 'nullable|string',
            'shiftEnd' => 'nullable|string',
            'phone' => 'nullable|string',
            'portadorLlaves' => 'nullable|string',
            'employee_id' => 'nullable|string',
            'curp' => 'nullable|string',
            'rfc' => 'nullable|string',
            'nss' => 'nullable|string',
            'address' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string',
            'emergency_contact_phone' => 'nullable|string',
            // OBLIGATORIA desde 2026-08-05. Es un dato de NEGOCIO —cuándo empezó a trabajar la
            // persona—, del que cuelgan la antigüedad, el aguinaldo y el finiquito el día que
            // existan, y de la que ya depende el aviso de "lleva N días sin su inducción".
            // Estaba en `nullable` y ningún formulario la mandaba: el 100% de los colaboradores
            // vivos la tenía vacía. No se acepta `created_at` como sustituto silencioso: esa es
            // la fecha en que se creó el registro, y un alta el viernes para entrar el lunes son
            // días distintos.
            'hire_date' => 'required|date',
            'mealMinutes' => 'nullable|integer|min:0|max:480',
            'restDay' => 'nullable|string',
            'base_salary' => 'nullable|numeric|min:0.01',
                'salary_periodicity' => 'nullable|string|in:diario,semanal,quincenal,mensual',
            'avatar' => 'sometimes|nullable|string',
        ]);

        // H1: `base_salary` es la COLUMNA DE VERDAD del dinero (costo de tarea y snapshot
        // `base_salary_at_time` del ponche la leen; con NULL caen al default de $300). El FE
        // del alta manda `salary`, así que se espejan ambas venga la que venga.
        $data = $this->espejarSueldo($data);

        // TURNO POR DEFECTO DE LA EMPRESA (decisión del dueño, 2026-08-08). Si el alta no
        // declara horario, se hereda el de la empresa (`storeSchedule`) en vez de dejarlo en
        // NULL: con NULL el reloj asumía 09:00 para todo el mundo, así que a quien entra a las
        // 11:00 le contaba dos horas de retardo desde su primer día.
        $data = $this->heredarHorarioDeLaEmpresa($data, $tenantId);

        try {
            DB::beginTransaction();

                        // Crear el registro de acceso en la tabla users
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($password),
                'role' => $data['role'],
                'job_role_id' => $data['job_role_id'] ?? null,
                'tenant_id' => $tenantId,
                'is_active' => $request->input('is_active', true),
                'avatar' => $data['avatar'] ?? null,
                // Si la contraseña la tecleó el admin, la conoce alguien más: cambio forzado.
                // (La aleatoria no se marca: nadie la conoce y se fija al activar con el PIN.)
                'must_change_password' => $request->filled('password'),
            ]);

            // Crear el registro del colaborador en la tabla employees
            $data['tenant_id'] = $tenantId;
            $data['user_id'] = $user->id;
            $employee = Employee::create($data);

            DB::commit();

            // §52: si el colaborador tiene correo, mandarle la invitación de bienvenida
            // con su PIN de activación. Best-effort: si el correo no está configurado
            // todavía, no rompe la creación del empleado.
            if (!empty($data['email'])) {
                $this->sendEmployeeInvitation($employee->fresh(), $tenantId);
            }

            return response()->json($employee->load('user'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al registrar colaborador: ' . $e->getMessage()], 500);
        }
    }

    /**
     * §52: reenviar la invitación de bienvenida a un colaborador (botón "Reenviar
     * invitación" en RRHH). Regenera el PIN de activación y manda el correo.
     */
    public function resendInvitation(Request $request, $id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $employee = Employee::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($id);

        if (empty($employee->email)) {
            return response()->json(['success' => false, 'message' => 'Este colaborador no tiene un correo registrado.'], 422);
        }

        $sent = $this->sendEmployeeInvitation($employee, $tenantId);

        return response()->json([
            'success' => true,
            'message' => $sent
                ? 'Invitación reenviada.'
                : 'Invitación registrada, pero el correo no pudo enviarse (¿falta configurar el dominio/servicio de correo?).',
        ]);
    }

    /**
     * Genera el PIN de activación y envía el correo de invitación. Best-effort:
     * devuelve false (sin lanzar) si el correo no se pudo enviar.
     */
    private function sendEmployeeInvitation(Employee $employee, int $tenantId): bool
    {
        $pin = sprintf('%06d', mt_rand(1, 999999));
        $inviteToken = \Illuminate\Support\Str::random(32);
        $employee->update(['pin_code' => $pin, 'invite_token' => $inviteToken]);

        $companyName = \Illuminate\Support\Facades\DB::table('tenants')->where('id', $tenantId)->value('name') ?: 'tu empresa';
        $base = rtrim(config('app.frontend_url') ?? config('app.url') ?? '', '/');
        $inviteUrl = $base . '/invite?pin=' . $pin;

        $mailSettings = app(\App\Services\MailSettingsService::class);

        try {
            \Illuminate\Support\Facades\Mail::to($employee->email)->send(new \App\Mail\EmployeeInvitation(
                $employee->name ?? 'Colaborador',
                $companyName,
                $inviteUrl,
                $pin,
                $mailSettings->tenantFromAddress($tenantId),
                $mailSettings->tenantReplyTo($tenantId),
            ));
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo enviar la invitación a ' . $employee->email . ': ' . $e->getMessage());
            return false;
        }
    }

    public function update(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);
        $currentUser = auth()->user() ?? auth('sanctum')->user();
        $tenant = $currentUser ? $currentUser->tenant : null;
        $tenantId = $currentUser ? $currentUser->tenant_id : null;

        // H3: misma normalización que en el alta (ver EmailNormalizer).
        if ($request->has('email')) {
            $request->merge(['email' => \App\Support\EmailNormalizer::normalizar($request->input('email'))]);
        }

        // Validar límite de cuentas administrativas (Seats) por plan en actualización
        $role = $request->input('role');
        if ($role) {
            $role = strtolower($role);
            $request->merge(['role' => $role]);
        }
        if ($tenant && $role && in_array($role, ['admin', 'supervisor']) && $employee->role !== $role) {
            $adminCount = User::where('tenant_id', $tenant->id)
                ->whereIn('role', ['admin', 'supervisor'])
                ->count();

            $adminLimit = 9999;
            if (strtolower($tenant->plan ?? 'freemium') === 'freemium') {
                $adminLimit = 1;
            } elseif (strtolower($tenant->plan ?? 'pro') === 'pro') {
                $adminLimit = 3;
            }

            if ($adminCount >= $adminLimit) {
                return response()->json([
                    'error' => 'Admin Limit Exceeded',
                    'message' => 'Has alcanzado el límite máximo de ' . $adminLimit . ' cuentas administrativas en tu plan ' . ucfirst($tenant->plan) . '. Por favor actualiza tu suscripción para añadir más supervisores.'
                ], 403);
            }
        }

        $data = $request->validate([
            'name' => 'sometimes|string',
            // El correo es la llave de acceso: si ya lo usa OTRA cuenta, el índice único
            // reventaba con un 500 que le enseñaba al usuario el SQL crudo. Se valida y se
            // responde con el mensaje del formulario, ignorando la propia cuenta.
            'email' => [
                'sometimes',
                'email',
                \Illuminate\Validation\Rule::unique('users', 'email')->ignore($employee->user_id),
            ],
            // §49: solo roles de empresa (ver nota arriba).
            'role' => 'sometimes|string|in:admin,supervisor,empleado',
            // El puesto se valida DENTRO de la empresa: con `exists:job_roles,id` a secas se
            // aceptaba el id de un puesto de OTRO tenant.
            'job_role_id' => ['sometimes', 'nullable', 'integer', \Illuminate\Validation\Rule::exists('job_roles', 'id')->where('tenant_id', $tenantId)],
            'contract_type' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
            'is_active_employee' => 'sometimes|boolean',
            'salary' => 'sometimes|nullable|numeric',
            'google_id' => 'nullable|string',
            'apple_id' => 'nullable|string',
            'samsung_id' => 'nullable|string',
            'shiftStart' => 'sometimes|nullable|string',
            'shiftEnd' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string',
            'portadorLlaves' => 'sometimes|nullable|string',
            'employee_id' => 'sometimes|nullable|string',
            'curp' => 'sometimes|nullable|string',
            'rfc' => 'sometimes|nullable|string',
            'nss' => 'sometimes|nullable|string',
            'address' => 'sometimes|nullable|string',
            'emergency_contact_name' => 'sometimes|nullable|string',
            'emergency_contact_phone' => 'sometimes|nullable|string',
            'hire_date' => 'sometimes|nullable|date',
            'mealMinutes' => 'sometimes|nullable|integer|min:0|max:480',
            'restDay' => 'sometimes|nullable|string',
            'base_salary' => 'sometimes|nullable|numeric',
            'salary_periodicity' => 'sometimes|nullable|string|in:diario,semanal,quincenal,mensual',
            'avatar' => 'sometimes|nullable|string',
            'allowed_modules' => 'sometimes|nullable|array',
            'allowed_features' => 'sometimes|nullable|array',
        ]);

        // H1: misma sincronía de sueldo que en el alta (ver espejarSueldo()).
        $data = $this->espejarSueldo($data);

        try {
            DB::beginTransaction();

            $employee->update($data);

            // Si tiene usuario enlazado, actualizar. Si no tiene pero se requiere acceso web, crear usuario.
            if ($employee->user_id) {
                $user = User::withoutGlobalScopes()->find($employee->user_id);
                if ($user) {
                    $userUpdates = [];
                    if ($request->has('name')) $userUpdates['name'] = $request->name;
                    if ($request->has('email')) $userUpdates['email'] = $request->email;
                    // GUARDAR LA FICHA NO APAGA LA CUENTA (2026-08-08).
                    //
                    // Aquí había un `if ($request->role === 'empleado') $userUpdates['is_active'] = false;`
                    // y, debajo, una condición que impedía que el `is_active` del propio formulario
                    // lo volviera a encender. Como RRHH manda el expediente COMPLETO en cada guardado
                    // (incluido `role`), bastaba corregirle el teléfono a alguien —o arrastrar su
                    // tarjeta en el organigrama— para que dejara de poder entrar: el login le
                    // respondía 403 "Usuario inactivo / archivado". Comprobado con la prueba de
                    // EditarFichaNoApagaCuentaTest, que sin este cambio falla en el login.
                    //
                    // No era una decisión de producto: la línea entró en julio dentro de un commit
                    // sobre otro tema. El colaborador ENTRA a la aplicación — el reloj es suyo.
                    // Dar de baja de verdad sigue apagando la cuenta, en `destroy()`.
                    if ($request->has('role')) {
                        $userUpdates['role'] = $request->role;
                    }
                    if ($request->has('job_role_id')) $userUpdates['job_role_id'] = $request->job_role_id;
                    if ($request->has('is_active')) {
                        $userUpdates['is_active'] = $request->is_active;
                    }
                    if ($request->has('google_id')) $userUpdates['google_id'] = $request->google_id;
                    if ($request->has('apple_id')) $userUpdates['apple_id'] = $request->apple_id;
                    if ($request->has('samsung_id')) $userUpdates['samsung_id'] = $request->samsung_id;
                    if ($request->has('avatar')) $userUpdates['avatar'] = $request->avatar;

                    if ($request->filled('password')) {
                        // La puso un admin (la conoce alguien más): cambio forzado al entrar.
                        $userUpdates['password'] = Hash::make($request->password);
                        $userUpdates['must_change_password'] = true;
                    }

                    if (!empty($userUpdates)) {
                        $user->update($userUpdates);
                    }
                }
            } else {
                if ($request->has('role') && in_array($request->role, ['admin', 'supervisor'])) {
                    // Misma regla que el alta: si no viene contraseña, se genera una que NADIE
                    // conoce (la persona fija la suya al activar con su PIN). El `password123`
                    // que había aquí nacía con acceso de admin/supervisor.
                    $user = User::create([
                        'name' => $request->input('name', $employee->name),
                        'email' => $request->input('email', $employee->email),
                        'password' => Hash::make($request->input('password') ?: \Illuminate\Support\Str::random(32)),
                        'role' => $request->role,
                        'job_role_id' => $request->input('job_role_id', $employee->job_role_id),
                        'tenant_id' => $employee->tenant_id ?? $tenantId,
                        'is_active' => $request->input('is_active', true),
                        'avatar' => $request->input('avatar', $employee->avatar),
                        // Igual que en el alta: contraseña tecleada por el admin → cambio forzado.
                        'must_change_password' => $request->filled('password'),
                    ]);
                    $employee->user_id = $user->id;
                    $employee->save();
                }
            }

            DB::commit();
            return response()->json($employee->load('user'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al actualizar colaborador: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $employee = Employee::withTrashed()->findOrFail($id);

        try {
            DB::beginTransaction();

            // Desactivar el estado operativo del colaborador. 2026-08-16: se registra la
            // FECHA de la baja — sin ella el sistema sabía quién estaba inactivo pero no
            // desde cuándo, y no había forma de medir rotación ni permanencia.
            $employee->update([
                'is_active_employee' => false,
                'termination_date' => $employee->termination_date ?? now()->toDateString(),
                'termination_reason' => $request->input('motivo') ?: $employee->termination_reason,
            ]);

            // Si tiene usuario enlazado, desactivar su acceso web (pero no eliminarlo por completo para conservar integridad, y nunca tocar cuentas admin)
            if ($employee->user_id) {
                $user = User::withoutGlobalScopes()->withTrashed()->find($employee->user_id);
                if ($user && $user->role !== 'admin') {
                    $user->update(['is_active' => false]);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Employee deactivated']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al desactivar colaborador: ' . $e->getMessage()], 500);
        }
    }

    public function forceDestroy($id)
    {
        $employee = Employee::withTrashed()->findOrFail($id);
        $currentUser = auth()->user() ?? auth('sanctum')->user();

        // Evitar que el administrador principal se borre a sí mismo
        if ($employee->user_id && (int)$employee->user_id === (int)$currentUser->id) {
            return response()->json(['error' => 'No puedes eliminar tu propia cuenta de administrador.'], 403);
        }

        // LA HISTORIA LABORAL NO SE BORRA (2026-08-08).
        //
        // Este método confiaba en que, si el colaborador tenía históricos, las claves foráneas
        // lanzarían una excepción y el `catch` de abajo haría el archivado "para conservar la
        // integridad histórica". Pero TODAS esas claves son ON DELETE CASCADE, no RESTRICT: la
        // excepción nunca ocurre y el borrado se lleva por delante los fichajes, los recibos de
        // nómina YA FIRMADOS y el expediente completo del Archivo Digital (INE, acta, CURP,
        // RFC, comprobante), dejando además esos archivos huérfanos en el storage privado
        // porque el cascade es SQL puro y no dispara eventos de Eloquent.
        //
        // Agravante: el botón vive en la pestaña de INACTIVOS —justo la población cuyos
        // controles de asistencia y recibos obliga a conservar 5 años el art. 804 de la LFT— y
        // la ruta la puede llamar un SUPERVISOR, no sólo el dueño.
        //
        // Ahora se pregunta ANTES, explícitamente. Si hay historia, se archiva y se dice.
        $historia = [
            'fichajes' => $employee->user_id
                ? DB::table('time_entries')->where('user_id', $employee->user_id)->count()
                : 0,
            'recibos de nómina' => DB::table('weekly_payrolls')->where('employee_id', $employee->id)->count(),
            'documentos del expediente' => DB::table('employee_documents')->where('employee_id', $employee->id)->count(),
        ];
        $conHistoria = array_filter($historia);

        if (!empty($conHistoria)) {
            $detalle = [];
            foreach ($conHistoria as $que => $cuantos) {
                $detalle[] = "{$cuantos} {$que}";
            }

            $employee->update([
                'is_active_employee' => false,
                'termination_date' => $employee->termination_date ?? now()->toDateString(),
            ]);
            $employee->delete();
            if ($employee->user_id) {
                $u = User::withoutGlobalScopes()->find($employee->user_id);
                if ($u) {
                    $u->update(['is_active' => false]);
                    $u->delete();
                }
            }

            return response()->json([
                'message' => 'Se archivó a ' . $employee->name . ' en vez de borrarlo: tiene '
                    . implode(', ', $detalle) . '. Esos registros deben conservarse (art. 804 LFT) '
                    . 'y se habrían borrado con él. Ya no aparece en el directorio ni puede entrar.',
                'archivado' => true,
                'historia' => $historia,
            ]);
        }

        try {
            DB::beginTransaction();

            $userId = $employee->user_id;

            // 1. Si tiene usuario enlazado, verificar que no sea el único administrador del tenant
            if ($userId) {
                $user = User::withoutGlobalScopes()->withTrashed()->find($userId);
                if ($user) {
                    if ($user->role === 'admin') {
                        $adminCount = User::where('tenant_id', $user->tenant_id)
                            ->where('role', 'admin')
                            ->count();
                        if ($adminCount <= 1) {
                            DB::rollBack();
                            return response()->json(['error' => 'No se puede eliminar al último administrador del sistema.'], 403);
                        }
                    }
                    $user->forceDelete();
                }
            }

            // 2. Eliminar físicamente al empleado
            $employee->forceDelete();

            DB::commit();
            return response()->json(['message' => 'Colaborador eliminado definitivamente.']);
        } catch (\Exception $e) {
            DB::rollBack();

            // Si falla por restricción de claves foráneas en históricos (ej. nóminas, checadas o aprobaciones),
            // aplicar borrado lógico defensivo (soft delete) marcándolo inactivo para conservar integridad histórica.
            try {
                DB::beginTransaction();
                $employee->update([
                    'is_active_employee' => false,
                    'termination_date' => $employee->termination_date ?? now()->toDateString(),
                ]);
                $employee->delete();
                if ($employee->user_id) {
                    $u = User::withoutGlobalScopes()->find($employee->user_id);
                    if ($u) {
                        $u->update(['is_active' => false]);
                        $u->delete();
                    }
                }
                DB::commit();
                return response()->json(['message' => 'Colaborador archivado e inhabilitado de forma segura para proteger la integridad de los registros históricos.']);
            } catch (\Throwable $ex) {
                DB::rollBack();
                return response()->json(['error' => 'Error al procesar la baja del colaborador: ' . $e->getMessage()], 500);
            }
        }
    }

    /**
     * PATCH /api/v1/employees/{id}/report-to
     * Actualiza el reporte jerárquico de un empleado (Organigrama Drag & Drop).
     * Valida que no se creen ciclos en la jerarquía.
     */
    public function updateReportTo(Request $request, int $id)
    {
        $tenantId = (auth()->user() ?? auth('sanctum')->user())?->tenant_id;

        $request->validate([
            // Acotado a la EMPRESA: con `exists:employees,id` a secas se podía poner como jefe
            // a alguien de otra compañía (los ids del cliente son globales).
            'report_to' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
        ]);

        $employee = Employee::findOrFail($id);

        if ($request->report_to) {
            // Nadie es su propio jefe. El bucle de abajo no lo cubría: si el destino no tenía
            // jefe todavía, no llegaba a entrar y la auto-referencia pasaba.
            if ((int) $request->report_to === (int) $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Una persona no puede reportarse a sí misma.',
                ], 422);
            }

            // Prevenir ciclos: el nuevo jefe no puede colgar del propio empleado.
            $reportToId = $request->report_to;
            $current    = Employee::find($reportToId);
            $visited    = [];

            while ($current && $current->report_to) {
                if (in_array($current->id, $visited) || $current->report_to == $id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Reorganización inválida: se detectó un ciclo jerárquico. Un subordinado no puede ser el jefe de su propio jefe.',
                    ], 422);
                }
                $visited[]  = $current->id;
                $current    = Employee::find($current->report_to);
            }
        }

        $employee->update(['report_to' => $request->report_to]);

        return response()->json([
            'success'  => true,
            'message'  => 'Jerarquía actualizada correctamente.',
            'employee' => $employee->fresh(['jobRole']),
        ]);
    }
}
