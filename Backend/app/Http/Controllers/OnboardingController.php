<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class OnboardingController extends Controller
{
    /**
     * Get onboarding welcome settings (Private)
     */
    public function getSettings(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $company = Company::find($tenantId);
        if (!$company) {
            $company = new Company();
            $company->id = $tenantId;
            $company->name = auth()->user()->tenant ? auth()->user()->tenant->name : 'Mi Empresa';
            $company->is_active = true;
            $company->save();
        }
        return response()->json([
            'welcomeTitle' => $company->welcome_title ?? '',
            'welcomeMessage' => $company->welcome_message ?? '',
            'welcomeImageUrl' => $company->welcome_image_url ?? '',
            'welcomeVideoUrl' => $company->welcome_video_url ?? '',
        ]);
    }
    /**
     * Save onboarding welcome settings (Private)
     */
    public function saveSettings(Request $request)
    {
        $request->validate([
            'welcomeTitle' => 'required|string|max:255',
            'welcomeMessage' => 'required|string',
            'welcomeImageUrl' => 'nullable|string',
            'welcomeVideoUrl' => 'nullable|string',
        ]);

        $tenantId = auth()->user()->tenant_id ?? 1;

        $company = Company::find($tenantId);
        if (!$company) {
            $company = new Company();
            $company->id = $tenantId;
            $company->name = auth()->user()->tenant ? auth()->user()->tenant->name : 'Mi Empresa';
            $company->is_active = true;
        }

        $company->welcome_title = $request->welcomeTitle;
        $company->welcome_message = $request->welcomeMessage;
        $company->welcome_image_url = $request->welcomeImageUrl;
        $company->welcome_video_url = $request->welcomeVideoUrl;
        $company->save();

        return response()->json(['status' => 'success', 'company' => $company]);
    }

    /**
     * Generate PIN code for an employee (Private)
     */
    public function generateInvitePin(Request $request, $employeeId)
    {
        $employee = Employee::findOrFail($employeeId);
        
        // Generar un PIN aleatorio de 6 dígitos
        $pin = sprintf("%06d", mt_rand(1, 999999));
        $inviteToken = Str::random(32);

        $employee->update([
            'pin_code' => $pin,
            'invite_token' => $inviteToken
        ]);

        return response()->json([
            'status' => 'success',
            'pin' => $pin,
            'invite_token' => $inviteToken
        ]);
    }

    /**
     * Verify PIN code and fetch company welcome screen config (Public)
     */
    public function verifyPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|size:6'
        ]);

        $employee = Employee::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('pin_code', $request->pin)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'El PIN ingresado es incorrecto o no existe.'], 404);
        }

        $company = Company::find($employee->tenant_id ?? 1);

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $employee->user_id ?? $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
            ],
            'company' => [
                'welcome_title' => $company->welcome_title ?? '¡Bienvenido al Equipo!',
                'welcome_message' => $company->welcome_message ?? 'Estamos muy emocionados de que te unas a nosotros.',
                'welcome_image_url' => $company->welcome_image_url ?? '',
                'welcome_video_url' => $company->welcome_video_url ?? '',
            ]
        ]);
    }

    /**
     * Complete activation and configure profile (Public)
     */
    public function completeActivation(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'pin' => 'required|string|size:6',
            'name' => 'required|string|max:255',
            'avatar' => 'nullable|string' // Puede ser base64 o URL
        ]);

        $employee = Employee::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where(function ($query) use ($request) {
                $query->where('id', $request->user_id)
                      ->orWhere('user_id', $request->user_id);
            })
            ->where('pin_code', $request->pin)
            ->first();

        if (!$employee) {
            return response()->json(['message' => 'El código PIN proporcionado es incorrecto o ya ha expirado.'], 403);
        }

        $employee->update([
            'name' => $request->name,
            'pin_code' => null, // Consumir el PIN
            'avatar' => $request->avatar ?? $employee->avatar
        ]);

        if ($employee->user) {
            $employee->user->update([
                'name' => $request->name,
                'avatar' => $request->avatar ?? $employee->user->avatar,
                'is_active' => true,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'user' => $employee->user ?? $employee
        ]);
    }

    /**
     * Send automated WhatsApp notifications via Talent 360 API (Simulated)
     */
    public function sendWhatsAppNotifications(Request $request)
    {
        $request->validate([
            'admin_phone' => 'nullable|string',
            'admin_message' => 'nullable|string',
            'employee_phone' => 'nullable|string',
            'employee_message' => 'nullable|string',
        ]);

        $adminSent = false;
        $employeeSent = false;

        if ($request->filled('admin_phone') && $request->filled('admin_message')) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $request->admin_phone);
            if (!empty($cleanPhone)) {
                \Illuminate\Support\Facades\Log::info("WhatsApp API (Talent 360) -> Enviando mensaje de bienvenida a Admin al número +{$cleanPhone}: {$request->admin_message}");
                $adminSent = true;
            }
        }

        if ($request->filled('employee_phone') && $request->filled('employee_message')) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $request->employee_phone);
            if (!empty($cleanPhone)) {
                \Illuminate\Support\Facades\Log::info("WhatsApp API (Talent 360) -> Enviando invitación a Colaborador al número +{$cleanPhone}: {$request->employee_message}");
                $employeeSent = true;
            }
        }

        return response()->json([
            'status' => 'success',
            'admin_sent' => $adminSent,
            'employee_sent' => $employeeSent,
            'message' => 'Notificaciones enviadas de manera exitosa desde el canal oficial de Talent 360.'
        ]);
    }

    /**
     * Inject selected demo data (Private)
     */
    public function injectDemoData(Request $request)
    {
        $request->validate([
            'inject_roles' => 'required|boolean',
            'inject_employees' => 'required|boolean'
        ]);

        $tenantId = auth()->user()->tenant_id ?? 1;
        $tenant = Tenant::findOrFail($tenantId);

        $insertedRoles = [];
        $insertedEmployees = [];

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            // 1. Inject Roles if requested
            if ($request->inject_roles) {
                $roles = [
                    ['name' => 'Gerente de Sucursal', 'description' => 'Responsable general de operaciones y ventas.', 'area' => 'Gerencia', 'esAperturador' => true, 'portadorLlaves' => 'ambos', 'nivel_mando' => 1],
                    ['name' => 'Cajero(a)', 'description' => 'Atención al cliente y cobro en caja.', 'area' => 'Cajas', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'nivel_mando' => 4],
                    ['name' => 'Asesor de Ventas', 'description' => 'Atención en piso y venta directa.', 'area' => 'Piso', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'nivel_mando' => 4],
                    ['name' => 'Almacenista', 'description' => 'Control de inventarios y bodega.', 'area' => 'Almacén', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'nivel_mando' => 4],
                ];

                foreach ($roles as $r) {
                    $existing = \App\Models\JobRole::where('tenant_id', $tenantId)
                        ->where('name', $r['name'])
                        ->first();
                    
                    if (!$existing) {
                        $roleModel = \App\Models\JobRole::create([
                            'name' => $r['name'],
                            'description' => $r['description'],
                            'area' => $r['area'],
                            'esAperturador' => $r['esAperturador'],
                            'portadorLlaves' => $r['portadorLlaves'],
                            'nivel_mando' => $r['nivel_mando'],
                            'tenant_id' => $tenantId
                        ]);
                        $insertedRoles[$r['name']] = $roleModel;
                    } else {
                        $insertedRoles[$r['name']] = $existing;
                    }
                }

                // Establecer Jerarquías y Organigrama
                $puestoGerente = $insertedRoles['Gerente de Sucursal'] ?? null;
                if ($puestoGerente) {
                    foreach (['Asesor de Ventas', 'Cajero(a)', 'Almacenista'] as $roleName) {
                        $puesto = $insertedRoles[$roleName] ?? null;
                        if ($puesto) {
                            $puesto->update([
                                'reports_to_role_id' => $puestoGerente->id,
                                'reports_to_role_ids' => [$puestoGerente->id],
                                'org_parent_role_id' => $puestoGerente->id,
                                'nivel_mando' => 4
                            ]);
                        }
                    }
                }

                // Inyectar rutinas de ejemplo asociadas a Gerente de Sucursal
                if ($puestoGerente) {
                    $existingRoutine = \App\Models\Routine::where('tenant_id', $tenantId)
                        ->where('title', 'Checklist Diario de Apertura')
                        ->first();

                    if (!$existingRoutine) {
                        // Checklist de Apertura
                        $routineApertura = \App\Models\Routine::create([
                            'title' => 'Checklist Diario de Apertura',
                            'target_role_id' => $puestoGerente->id,
                            'trigger' => 'apertura',
                            'assign_mode' => 'fijo',
                            'tenant_id' => $tenantId
                        ]);

                        $tasksApertura = [
                            'Desactivar alarma perimetral y encender switch principal',
                            'Verificar funcionamiento de las luces del piso de ventas',
                            'Realizar conteo del fondo de caja',
                            'Encender equipos de refrigeración/clima',
                            'Tomar foto de la fachada frontal limpia y despejada'
                        ];

                        foreach ($tasksApertura as $t) {
                            $task = \App\Models\Task::create([
                                'title' => $t,
                                'priority' => 'bloqueante',
                                'target_type' => 'role',
                                'target_id' => $puestoGerente->id,
                                'assistant_type' => str_contains($t, 'foto') ? 'evidencia_foto' : 'ninguno',
                                'tenant_id' => $tenantId
                            ]);
                            $routineApertura->tasks()->attach($task->id);
                        }

                        // Checklist de Operación
                        $routineOperacion = \App\Models\Routine::create([
                            'title' => 'Checklist Diario de Operación',
                            'target_role_id' => $puestoGerente->id,
                            'trigger' => 'hora_fija',
                            'assign_mode' => 'fijo',
                            'tenant_id' => $tenantId
                        ]);

                        $tasksOperacion = [
                            'Recorrer pasillos asegurando que el piso esté libre de cajas',
                            'Alinear los precios en las etiquetas de los domos principales',
                            'Validar que el personal esté portando el gafete y uniforme limpios',
                            'Revisar stock de bolsas de empaque en las cajas'
                        ];

                        foreach ($tasksOperacion as $t) {
                            $task = \App\Models\Task::create([
                                'title' => $t,
                                'priority' => 'normal',
                                'target_type' => 'role',
                                'target_id' => $puestoGerente->id,
                                'assistant_type' => 'ninguno',
                                'tenant_id' => $tenantId
                            ]);
                            $routineOperacion->tasks()->attach($task->id);
                        }

                        // Checklist de Cierre
                        $routineCierre = \App\Models\Routine::create([
                            'title' => 'Checklist Diario de Cierre',
                            'target_role_id' => $puestoGerente->id,
                            'trigger' => 'cierre',
                            'assign_mode' => 'fijo',
                            'tenant_id' => $tenantId
                        ]);

                        $tasksCierre = [
                            'Ejecutar corte X y validar los retiros parciales (Arqueos)',
                            'Hacer el cierre de terminales bancarias y adjuntar voucher',
                            'Guardar el efectivo en la tómbola',
                            'Apagar aires acondicionados y luces',
                            'Activar alarma perimetral y asegurar puertas'
                        ];

                        foreach ($tasksCierre as $t) {
                            $task = \App\Models\Task::create([
                                'title' => $t,
                                'priority' => 'bloqueante',
                                'target_type' => 'role',
                                'target_id' => $puestoGerente->id,
                                'assistant_type' => 'ninguno',
                                'tenant_id' => $tenantId
                            ]);
                            $routineCierre->tasks()->attach($task->id);
                        }
                    }
                }
            }

            // 2. Inject Employees if requested
            if ($request->inject_employees) {
                $puestoGerente = $insertedRoles['Gerente de Sucursal'] ?? \App\Models\JobRole::where('tenant_id', $tenantId)->where('name', 'Gerente de Sucursal')->first();
                $puestoVentas = $insertedRoles['Asesor de Ventas'] ?? \App\Models\JobRole::where('tenant_id', $tenantId)->where('name', 'Asesor de Ventas')->first();
                $puestoCaja = $insertedRoles['Cajero(a)'] ?? \App\Models\JobRole::where('tenant_id', $tenantId)->where('name', 'Cajero(a)')->first();
                $puestoAlmacen = $insertedRoles['Almacenista'] ?? \App\Models\JobRole::where('tenant_id', $tenantId)->where('name', 'Almacenista')->first();

                if (!$puestoGerente) {
                    $puestoGerente = \App\Models\JobRole::create([
                        'name' => 'Gerente de Sucursal',
                        'area' => 'Gerencia',
                        'nivel_mando' => 1,
                        'tenant_id' => $tenantId
                    ]);
                }
                if (!$puestoVentas) {
                    $puestoVentas = \App\Models\JobRole::create([
                        'name' => 'Asesor de Ventas',
                        'area' => 'Piso',
                        'nivel_mando' => 4,
                        'tenant_id' => $tenantId
                    ]);
                }
                if (!$puestoCaja) {
                    $puestoCaja = \App\Models\JobRole::create([
                        'name' => 'Cajero(a)',
                        'area' => 'Cajas',
                        'nivel_mando' => 4,
                        'tenant_id' => $tenantId
                    ]);
                }
                if (!$puestoAlmacen) {
                    $puestoAlmacen = \App\Models\JobRole::create([
                        'name' => 'Almacenista',
                        'area' => 'Almacén',
                        'nivel_mando' => 4,
                        'tenant_id' => $tenantId
                    ]);
                }

                $domain = $tenant->subdomain . '.com';

                $employees = [
                    [
                        'name' => 'Roberto Sánchez',
                        'email' => 'roberto.sanchez@' . $domain,
                        'password' => Hash::make('password123'),
                        'role' => 'empleado',
                        'job_role_id' => $puestoGerente->id,
                        'tenant_id' => $tenantId,
                    ],
                    [
                        'name' => 'María García',
                        'email' => 'maria.garcia@' . $domain,
                        'password' => Hash::make('password123'),
                        'role' => 'empleado',
                        'job_role_id' => $puestoVentas->id,
                        'tenant_id' => $tenantId,
                    ],
                    [
                        'name' => 'Carlos López',
                        'email' => 'carlos.lopez@' . $domain,
                        'password' => Hash::make('password123'),
                        'role' => 'empleado',
                        'job_role_id' => $puestoCaja->id,
                        'tenant_id' => $tenantId,
                    ],
                    [
                        'name' => 'Ana Martínez',
                        'email' => 'ana.martinez@' . $domain,
                        'password' => Hash::make('password123'),
                        'role' => 'empleado',
                        'job_role_id' => $puestoVentas->id,
                        'tenant_id' => $tenantId,
                    ],
                    [
                        'name' => 'Luis Fernández',
                        'email' => 'luis.fernandez@' . $domain,
                        'password' => Hash::make('password123'),
                        'role' => 'empleado',
                        'job_role_id' => $puestoAlmacen->id,
                        'tenant_id' => $tenantId,
                    ]
                ];

                foreach ($employees as $emp) {
                    $existingEmp = \App\Models\Employee::withoutGlobalScope(\App\Scopes\TenantScope::class)
                        ->where('email', $emp['email'])
                        ->first();
                    
                    if (!$existingEmp) {
                        $user = \App\Models\User::create([
                            'name' => $emp['name'],
                            'email' => $emp['email'],
                            'password' => $emp['password'],
                            'role' => $emp['role'],
                            'tenant_id' => $tenantId,
                            'is_active' => true
                        ]);

                        $newEmp = \App\Models\Employee::create([
                            'tenant_id' => $tenantId,
                            'user_id' => $user->id,
                            'name' => $emp['name'],
                            'email' => $emp['email'],
                            'job_role_id' => $emp['job_role_id'],
                            'is_active_employee' => true,
                            'shiftStart' => '08:00:00',
                            'shiftEnd' => '17:00:00',
                            'mealMinutes' => 60,
                            'restDay' => 'Domingo'
                        ]);
                        $insertedEmployees[] = $newEmp->name;
                    }
                }
            }

            \DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Datos demo procesados con éxito.',
                'roles_loaded' => count($insertedRoles) > 0,
                'employees_loaded' => count($insertedEmployees)
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al inyectar datos demo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Configura el nicho de negocio, inyectando puestos, tareas, vacantes y cursos seleccionados en el wizard.
     */
    public function configureNicho(Request $request)
    {
        $request->validate([
            'nicho' => 'required|string',
            'sub_nicho' => 'nullable|string',
            'custom_nicho_description' => 'nullable|string',
            'selected_puestos' => 'nullable|array',
            'selected_tareas' => 'nullable|array'
        ]);

        $tenantId = auth()->user()->tenant_id ?? 1;
        $nicho = strtolower($request->nicho);
        $subNicho = $request->sub_nicho;
        $customDescription = $request->custom_nicho_description;

        try {
            \DB::beginTransaction();

            $puestos = $request->input('selected_puestos');
            $tareas = $request->input('selected_tareas');

            // Si el frontend no envió selección previa en arreglo, determinar defaults
            if (empty($puestos)) {
                // El catálogo ya no vive aquí: está en `resources/catalogos/onboarding/<giro>.json`,
                // para que lo edite quien conoce el giro sin tocar PHP. Ver `CatalogoOnboarding`.
                $catalogo = \App\Support\CatalogoOnboarding::para($nicho);

                if ($catalogo !== null) {
                    $puestos = $catalogo['puestos'];
                    $tareas = $catalogo['tareas'];
                }
            }

            // Limpiar los puestos por defecto de la plantilla inicial que NO tengan empleados ni usuarios asignados,
            // para sustituirlos limpiamente por la estructura de puestos elegida por el usuario en el wizard.
            $existingRoles = \App\Models\JobRole::where('tenant_id', $tenantId)->get();
            foreach ($existingRoles as $oldRole) {
                $hasUsers = \DB::table('users')->where('job_role_id', $oldRole->id)->exists();
                $hasEmployees = \DB::table('employees')->where('job_role_id', $oldRole->id)->exists();
                if (!$hasUsers && !$hasEmployees) {
                    \DB::table('role_clock_policies')->where('job_role_id', $oldRole->id)->delete();
                    \DB::table('vacancies')->where('job_role_id', $oldRole->id)->delete();
                    $oldRole->delete();
                }
            }

            // Limpiar tareas y vacantes de la plantilla previa del tenant para cargar las del giro seleccionado
            \DB::table('tasks')->where('tenant_id', $tenantId)->delete();
            \DB::table('vacancies')->where('tenant_id', $tenantId)->delete();

            // 1. Inyectar Puestos en base de datos (`job_roles`)
            $roleIdsMap = [];
            $firstGerenteRole = null;

            foreach ($puestos as $p) {
                $roleId = \DB::table('job_roles')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => $p['name'],
                    'area' => $p['area'] ?? 'General',
                    'esAperturador' => $p['esAperturador'] ?? false,
                    'jerarquiaLlaves' => $p['jerarquiaLlaves'] ?? 0,
                    'portadorLlaves' => ($p['esAperturador'] ?? false) ? 'apertura' : 'ninguno',
                    'tiempoTolerancia' => 10,
                    'requiereJustificante' => true,
                    'puedeEmitirAvisos' => false,
                    'aplicaLeySilla' => in_array($nicho, ['retail', 'restaurante', 'taller']),
                    'evaluacion360Activa' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $roleIdsMap[$p['name']] = $roleId;

                if (!$firstGerenteRole || ($p['esAperturador'] ?? false)) {
                    $firstGerenteRole = $roleId;
                }
            }

            // 1.b H27: construir el ORGANIGRAMA con la jerarquía que el propio catálogo ya trae.
            //
            // Antes los puestos del giro nacían con `reports_to_role_id` NULL —todos huérfanos—, y
            // eso dejaba muerta la validación jerárquica: `TaskValidationPolicy` concluye que un
            // colaborador sin supervisor no necesita firma, así que NINGUNA tarea la exigía.
            //
            // `jerarquiaLlaves` ya expresa el nivel (1 mando, 2 supervisión, 3 piso, 4 eventual):
            // cada puesto reporta al PRIMERO del nivel inmediatamente superior que exista. Cuando
            // un nivel tiene varios candidatos —tres supervisores, por ejemplo— la elección es
            // convencional a propósito: deja una jerarquía coherente de arranque, y el admin la
            // ajusta desde el módulo de Organigrama, que para eso está.
            $this->construirOrganigrama($tenantId, $puestos, $roleIdsMap);

            // 2. Inyectar Tareas en base de datos (`tasks`)
            $taskIdsPorTitulo = [];
            if (!empty($tareas)) {
                foreach ($tareas as $t) {
                    $targetRoleId = isset($roleIdsMap[$t['target_role_name']]) ? $roleIdsMap[$t['target_role_name']] : $firstGerenteRole;
                    if ($targetRoleId) {
                        // H27: se conserva el id para poder colgar la tarea de una rutina.
                        $taskIdsPorTitulo[$t['title']] = \DB::table('tasks')->insertGetId([
                            'tenant_id' => $tenantId,
                            'title' => $t['title'],
                            'estimated_mins' => $t['estimated_mins'] ?? 15,
                            'priority' => $t['priority'] ?? 'normal',
                            'category' => $t['category'] ?? 'operativo',
                            'target_type' => 'role',
                            'target_id' => $targetRoleId,
                            'assistant_type' => $t['assistant_type'] ?? 'ninguno',
                            'assistant_prompt' => $t['assistant_prompt'] ?? '',
                            'is_auto_capture' => true,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                }
            }

            // 2.b H27: dejar creadas las RUTINAS del día, que son el motor de la asignación
            // automática. Sin una rutina con `trigger='apertura'`,
            // `StoreOpeningService::triggerOpeningChecklist` no reparte nada al abrir la tienda y
            // toda tarea hay que darla de alta a mano — en un módulo que se anuncia como
            // "Automatiza Rutinas".
            $this->crearRutinasDelGiro($tenantId, $tareas, $taskIdsPorTitulo, $roleIdsMap, $puestos, $firstGerenteRole);

            // 3. Inyectar Vacantes Iniciales en Bolsa de Trabajo (`vacancies`)
            $vacanciesData = [];
            if ($nicho === 'retail') {
                $vacanciesData = [
                    ['title' => 'Asesor de Ventas y Piso', 'department' => 'Piso de Ventas', 'salary_min' => 8000, 'salary_max' => 9500, 'employment_type' => 'Tiempo Completo', 'description' => 'Buscamos asesor de ventas proactivo para atención al cliente y acomodo de mercancía.'],
                    ['title' => 'Cajero(a) de Tienda', 'department' => 'Cajas', 'salary_min' => 7800, 'salary_max' => 8800, 'employment_type' => 'Tiempo Completo', 'description' => 'Atención en cajas, cobro de mercancía y arqueos diarios.']
                ];
            } elseif ($nicho === 'restaurante') {
                $vacanciesData = [
                    ['title' => 'Mesero(a) con Experiencia', 'department' => 'Servicio', 'salary_min' => 7500, 'salary_max' => 9000, 'employment_type' => 'Tiempo Completo', 'description' => 'Atención a comensales, toma de comandas y limpieza de área.'],
                    ['title' => 'Ayudante de Cocina', 'department' => 'Cocina', 'salary_min' => 8000, 'salary_max' => 8800, 'employment_type' => 'Tiempo Completo', 'description' => 'Apoyo en preparación de insumos, picado y limpieza de cocina.']
                ];
            } else {
                $vacanciesData = [
                    ['title' => 'Ejecutivo de Atención y Ventas', 'department' => 'Operaciones', 'salary_min' => 9000, 'salary_max' => 11000, 'employment_type' => 'Tiempo Completo', 'description' => 'Atención a clientes y coordinación operativa.']
                ];
            }

            foreach ($vacanciesData as $vac) {
                $roleId = isset($roleIdsMap[$vac['title']]) ? $roleIdsMap[$vac['title']] : $firstGerenteRole;
                $salaryRange = '$' . number_format($vac['salary_min']) . ' - $' . number_format($vac['salary_max']) . ' MXN';
                \DB::table('vacancies')->insert([
                    'tenant_id' => $tenantId,
                    'job_role_id' => $roleId,
                    'title' => $vac['title'],
                    'description' => $vac['description'],
                    'requirements' => 'Secundaria o Bachillerato concluido. Proactividad y compromiso.',
                    'work_type' => $vac['employment_type'] ?? 'Tiempo Completo',
                    'salary_range' => $salaryRange,
                    'is_active' => true,
                    'is_hidden' => false,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 4. Inyectar Cursos Iniciales en Academia LMS (`academy_courses`)
            $coursesData = [];
            if ($nicho === 'retail') {
                $coursesData = [
                    ['title' => 'Protocolo de Apertura y Operación Comercial', 'description' => 'Aprende los pasos clave para abrir la tienda, verificar cajas y dar la bienvenida al primer cliente.', 'course_type' => 'induction'],
                    ['title' => 'Excelencia en Servicio al Cliente y Venta Cruzada', 'description' => 'Técnicas de venta en piso, abordaje al cliente y sugerencias de productos complementarios.', 'course_type' => 'training']
                ];
            } elseif ($nicho === 'restaurante') {
                $coursesData = [
                    ['title' => 'Manejo Higiénico de Alimentos (NOM-251)', 'description' => 'Reglas sanitarias para la preparación de insumos y prevención de contaminación cruzada.', 'course_type' => 'induction'],
                    ['title' => 'Seguridad e Inspección de Válvulas de Gas', 'description' => 'Protocolos de encendido y cierre seguro de estufas y cilindros de gas.', 'course_type' => 'training']
                ];
            } else {
                $coursesData = [
                    ['title' => 'Inducción al Software Corporativo y Gestión del Tiempo', 'description' => 'Uso de herramientas internas, registro de asistencia y coordinación de tareas.', 'course_type' => 'induction']
                ];
            }

            foreach ($coursesData as $course) {
                \DB::table('academy_courses')->insert([
                    'tenant_id' => $tenantId,
                    'title' => $course['title'],
                    'description' => $course['description'],
                    'course_type' => $course['course_type'],
                    'target_job_role_id' => $firstGerenteRole,
                    'video_url' => '',
                    'quiz_data' => json_encode([
                        [
                            'question' => '¿Cuál es el objetivo principal de este protocolo?',
                            'options' => ['Garantizar la seguridad y calidad', 'Aumentar tiempos de espera', 'Omitir registros', 'Ninguna'],
                            'correctAnswer' => 0
                        ]
                    ]),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 5. Configurar Horarios y Valores de Sistema (`system_settings`)
            $openTime = $nicho === 'restaurante' ? '10:00' : '08:00';
            $closeTime = $nicho === 'restaurante' ? '22:00' : ($nicho === 'oficina' ? '18:00' : '20:00');

            \DB::table('system_settings')->updateOrInsert(
                ['key' => 'storeSchedule', 'tenant_id' => $tenantId],
                ['value' => json_encode(['openTime' => $openTime, 'closeTime' => $closeTime]), 'updated_at' => now()]
            );

            \DB::table('system_settings')->updateOrInsert(
                ['key' => 'nicho_configurado', 'tenant_id' => $tenantId],
                ['value' => json_encode(['nicho' => $nicho, 'subNicho' => $subNicho]), 'updated_at' => now()]
            );

            \DB::table('system_settings')->updateOrInsert(
                ['key' => 'onboarding_completed', 'tenant_id' => $tenantId],
                ['value' => json_encode(true), 'updated_at' => now()]
            );

            \DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Nicho configurado correctamente con puestos, tareas, vacantes y cursos precargados.',
                'puestos_creados' => count($puestos)
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al configurar el nicho: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * H27: enlaza cada puesto con el de nivel inmediatamente superior (`jerarquiaLlaves`).
     *
     * El puesto de mando queda sin jefe; el resto cuelga del primer puesto que exista en un nivel
     * por encima. No inventa niveles: si el giro sólo trae dos, la cadena tiene dos eslabones.
     */
    private function construirOrganigrama(int $tenantId, array $puestos, array $roleIdsMap): void
    {
        // Nivel → ids de los puestos que lo ocupan, en el orden del catálogo.
        $porNivel = [];
        foreach ($puestos as $p) {
            $id = $roleIdsMap[$p['name']] ?? null;
            if (!$id) {
                continue;
            }
            $nivel = (int) ($p['jerarquiaLlaves'] ?? 0);
            $porNivel[$nivel][] = $id;
        }

        if (empty($porNivel)) {
            return;
        }

        $niveles = array_keys($porNivel);
        sort($niveles);

        foreach ($niveles as $nivel) {
            // El jefe es el primer puesto del nivel superior MÁS CERCANO que exista.
            $jefeId = null;
            foreach (array_reverse($niveles) as $candidato) {
                if ($candidato < $nivel) {
                    $jefeId = $porNivel[$candidato][0];
                    break;
                }
            }

            if ($jefeId === null) {
                continue; // nivel más alto: no reporta a nadie
            }

            foreach ($porNivel[$nivel] as $puestoId) {
                \DB::table('job_roles')->where('id', $puestoId)->update([
                    'reports_to_role_id' => $jefeId,
                    'org_parent_role_id' => $jefeId,
                    'nivel_mando' => $nivel,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * H27: crea las rutinas del día y les cuelga sus tareas.
     *
     * QUÉ TAREA VA EN QUÉ RUTINA. El catálogo del giro no declara el momento del día —sólo
     * `category` y `priority`, y "seguridad" cubre tanto *desactivar* la alarma al abrir como
     * *activarla* al cerrar—, así que el reparto se hace con el campo `momento` que las entradas
     * de apertura/cierre llevan ahora explícito. Las tareas sin `momento` NO entran en ninguna
     * rutina: se quedan en el catálogo para que el admin las organice, que es preferible a
     * repartirlas adivinando.
     *
     * La rutina de apertura va a cargo del puesto APERTURADOR, que es quien tiene llaves.
     */
    private function crearRutinasDelGiro(
        int $tenantId,
        $tareas,
        array $taskIdsPorTitulo,
        array $roleIdsMap,
        array $puestos,
        $firstGerenteRole
    ): void {
        if (empty($tareas) || empty($taskIdsPorTitulo)) {
            return;
        }

        // El puesto con llaves; si el giro no marca ninguno, el de mando.
        $puestoAperturador = $firstGerenteRole;
        foreach ($puestos as $p) {
            if (($p['esAperturador'] ?? false) && isset($roleIdsMap[$p['name']])) {
                $puestoAperturador = $roleIdsMap[$p['name']];
                break;
            }
        }

        if (!$puestoAperturador) {
            return;
        }

        // Agrupar los títulos por momento declarado.
        $porMomento = [];
        foreach ($tareas as $t) {
            $momento = $t['momento'] ?? null;
            if (!$momento || !isset($taskIdsPorTitulo[$t['title']])) {
                continue;
            }
            $porMomento[$momento][] = $taskIdsPorTitulo[$t['title']];
        }

        // SÓLO se crean rutinas cuyo disparador alguien CONSUME de verdad.
        //
        // Aquí llegó a haber también una de `cierre`. Se retiró porque ningún punto del backend
        // busca ese disparador —`StoreOpeningService::triggerOpeningChecklist` sólo consulta
        // `trigger='apertura'`—, así que la rutina se creaba, aparecía en el panel del gerente y
        // no repartía ni una tarea: una promesa vacía en pantalla, que es justo la clase de
        // defecto que esta ronda de auditoría vino a eliminar (H19 el módulo de apertura, H23 la
        // aprobación de nómina que decía "listo" sin guardar nada).
        //
        // Las tareas de cierre CONSERVAN su `momento` en el catálogo: son metadatos correctos y
        // dejan el trabajo hecho para el día que se cablee el disparador —el enganche natural es
        // `StoreOpeningController::closingChecklist`—. Cuando eso exista, basta con volver a
        // añadir la línea de 'cierre' aquí.
        $definiciones = [
            'apertura' => 'Checklist Diario de Apertura',
        ];

        foreach ($definiciones as $trigger => $titulo) {
            $taskIds = $porMomento[$trigger] ?? [];
            if (empty($taskIds)) {
                continue;
            }

            // Reaplicar el giro no debe duplicar rutinas; y como las tareas se borran y recrean,
            // los vínculos se rehacen para no apuntar a filas que ya no existen.
            $rutina = \App\Models\Routine::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger)
                ->first();

            if ($rutina) {
                $rutina->update([
                    'title' => $titulo,
                    'target_role_id' => $puestoAperturador,
                    'assign_mode' => 'fijo',
                ]);
            } else {
                $rutina = \App\Models\Routine::create([
                    'tenant_id' => $tenantId,
                    'title' => $titulo,
                    'trigger' => $trigger,
                    'assign_mode' => 'fijo',
                    'target_role_id' => $puestoAperturador,
                ]);
            }

            \DB::table('routine_task')->where('routine_id', $rutina->id)->delete();
            foreach ($taskIds as $taskId) {
                \DB::table('routine_task')->insert([
                    'routine_id' => $rutina->id,
                    'task_id' => $taskId,
                ]);
            }
        }
    }
}
