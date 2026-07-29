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
                if ($nicho === 'materias_primas' || $nicho === 'reposteria') {
                    $puestos = [
                        ['name' => 'Administrador Gerente', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Gerencia General'],
                        ['name' => 'Supervisor de Compras', 'esAperturador' => false, 'jerarquiaLlaves' => 2, 'area' => 'Compras & Almacén'],
                        ['name' => 'Supervisor de Ventas', 'esAperturador' => false, 'jerarquiaLlaves' => 2, 'area' => 'Ventas & Mostrador'],
                        ['name' => 'Supervisor de Producción', 'esAperturador' => false, 'jerarquiaLlaves' => 2, 'area' => 'Producción & Envasado'],
                        ['name' => 'Asesor de Ventas', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Piso de Ventas'],
                        ['name' => 'Ayudante Integral', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Operaciones'],
                        ['name' => 'Apoyo Eventual', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Operaciones']
                    ];
                    $tareas = [
                        // Administrador Gerente (22 Tareas)
                        ['title' => 'Desactivar alarma perimetral y encender switch principal de energía', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de la pantalla de alarma desactivada.'],
                        ['title' => 'Verificar funcionamiento de las luces del piso de ventas y clima', 'estimated_mins' => 5, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Realizar conteo del fondo de caja inicial y apertura en punto de venta', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'operativo', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingrese el monto del fondo inicial.'],
                        ['title' => 'Inspeccionar estado exterior de la sucursal y tomar foto de la fachada frontal', 'estimated_mins' => 10, 'priority' => 'alta', 'category' => 'seguridad', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de la fachada frontal despejada.'],
                        ['title' => 'Apertura de caja fuerte/tómbola y asignación de fondos a cajas registradoras', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'operativo', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Monto asignado a cajas.'],
                        ['title' => 'Verificar el grupo de trabajo del día y confirmar asistencia del personal (Pase de lista)', 'estimated_mins' => 10, 'priority' => 'alta', 'category' => 'supervision', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Recorrer pasillos asegurando que el piso esté libre de cajas u obstáculos', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Validar que el personal esté portando el gafete y uniforme limpios', 'estimated_mins' => 10, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Auditoría de puntualidad, retardos y justificantes en el reloj checador', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Revisión de bitácora de novedades de la jornada anterior', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Supervisión de clima laboral y atención a incidencias de personal', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Revisión de flujo de caja diario, retiros parciales y depósitos', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingrese el total de retiros parciales.'],
                        ['title' => 'Ejecutar corte X/Y y validar retiros de efectivo con cajeros (Arqueo gerencial)', 'estimated_mins' => 25, 'priority' => 'bloqueante', 'category' => 'operativo', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Total arqueado en cajas.'],
                        ['title' => 'Realizar conciliación bancaria diaria y voucher de terminales', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de vouchers de terminales.'],
                        ['title' => 'Resguardo de efectivo sobrante en la tómbola de seguridad', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Monto resguardado en tómbola.'],
                        ['title' => 'Apagar equipos de cómputo, clima y luces de piso de venta', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Verificación de cierre seguro de puertas de emergencia y accesos', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Cierre de cortina metálica principal y colocación de candados reforzados', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto del candado metálico cerrado.'],
                        ['title' => 'Activar alarma perimetral y verificar reporte de armado en sistema', 'estimated_mins' => 5, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de pantalla de alarma armada.'],
                        ['title' => 'Envío de reporte diario de ventas y asistencia a dirección general', 'estimated_mins' => 15, 'priority' => 'alta', 'category' => 'supervision', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Supervisión de cumplimiento de metas de venta semanales', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Autorización de compras de insumos operativos extraordinarios', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Administrador Gerente', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],

                        // Supervisor de Compras (12 Tareas)
                        ['title' => 'Auditoría de niveles de inventario crítico en bodega y góndolas', 'estimated_mins' => 30, 'priority' => 'alta', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Conteo diario de productos A (alta rotación y mayor valor)', 'estimated_mins' => 25, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingrese piezas contadas.'],
                        ['title' => 'Identificación de faltantes y alertas de stock mínimo', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Revisión y recepción de fletes de materias primas (harinas, azúcar, mantecas, chocolates)', 'estimated_mins' => 40, 'priority' => 'bloqueante', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de la mercancía descargada.'],
                        ['title' => 'Inspección física de empaques y caducidades en sacos y cajas recibidas', 'estimated_mins' => 25, 'priority' => 'alta', 'category' => 'calidad', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Cotejo de remisiones/facturas físicas contra orden de compra', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Ingreso de entradas de mercancía al sistema ERP/Inventarios', 'estimated_mins' => 30, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Generación de órdenes de compra con proveedores de materias primas', 'estimated_mins' => 35, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Seguimiento a entregas pendientes y reclamos por mermas/defectos', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Control e inventario de material de empaque (bolsas, cajas, domos)', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Unidades en inventario de empaque.'],
                        ['title' => 'Reporte de variación de costos e insumos de temporada', 'estimated_mins' => 25, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Supervisión de stock de insumos para cajas y mostrador', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Compras', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],

                        // Supervisor de Ventas (14 Tareas)
                        ['title' => 'Supervisar la atención al cliente en mostrador y agilidad en cajas', 'estimated_mins' => 30, 'priority' => 'alta', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Monitoreo de tiempo de espera en fila de clientes', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Atención y resolución de quejas o devoluciones de clientes', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Verificación de precios visibles y promociones vigentes', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Revisar pedidos de clientes especiales, escuelas o de mayoreo pendientes de procesar', 'estimated_mins' => 25, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Autorización de descuentos especiales o facturación a clientes corporativos', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Realizar arqueos sorpresivos de caja a cajeros y vendedores', 'estimated_mins' => 20, 'priority' => 'bloqueante', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Monto encontrado en arqueo.'],
                        ['title' => 'Validación de cierres parciales de ventas, arqueos y depósitos', 'estimated_mins' => 25, 'priority' => 'bloqueante', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Monto validado.'],
                        ['title' => 'Realizar inventario rotativo (conteo de productos de alta rotación)', 'estimated_mins' => 30, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Piezas contadas.'],
                        ['title' => 'Realizar ajustes de inventario por mermas o roturas en piso', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Alinear los precios en las etiquetas de los domos principales', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de exhibidor con precios.'],
                        ['title' => 'Coordinación de frenteo y exhibición en cabeceras de pasillo', 'estimated_mins' => 25, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de cabecera alineada.'],
                        ['title' => 'Supervisión de arqueos de cambio en cajas registradoras', 'estimated_mins' => 15, 'priority' => 'alta', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Cambio en cajas.'],
                        ['title' => 'Reporte diario de conversión de ventas y tickets promedio', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'supervision', 'target_role_name' => 'Supervisor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],

                        // Supervisor de Producción (12 Tareas)
                        ['title' => 'Inspección de lotes de fraccionado, empacado y etiquetado de insumos a granel', 'estimated_mins' => 35, 'priority' => 'alta', 'category' => 'produccion', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de empaques fraccionados.'],
                        ['title' => 'Supervisión de básculas de pesado para asegurar gramajes exactos', 'estimated_mins' => 20, 'priority' => 'bloqueante', 'category' => 'calidad', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de báscula calibrada.'],
                        ['title' => 'Control e impresión de etiquetas con fecha de caducidad y lote', 'estimated_mins' => 25, 'priority' => 'alta', 'category' => 'produccion', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de la etiqueta impresa.'],
                        ['title' => 'Verificación de la rotación PEPS (Primeras Entradas, Primeras Salidas) en almacén de granel', 'estimated_mins' => 30, 'priority' => 'bloqueante', 'category' => 'calidad', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Auditoría de higiene en la zona de envasado y herramientas de trabajo', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'seguridad', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de área sanitizada.'],
                        ['title' => 'Control de mermas y pesado de materias primas procesadas en taller de fraccionado', 'estimated_mins' => 25, 'priority' => 'alta', 'category' => 'produccion', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Kilogramos de merma.'],
                        ['title' => 'Monitoreo de condiciones de temperatura y humedad en almacén de insumos sensibles', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'calidad', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Temperatura en °C.'],
                        ['title' => 'Registro de bitácora de limpieza de maquinaria y moldes', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'seguridad', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Control de merma por humedad o empaque dañado', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'produccion', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Reporte semanal de rendimiento de insumos procesados', 'estimated_mins' => 30, 'priority' => 'normal', 'category' => 'produccion', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Inspección de calidad organoléptica en materia prima recibida', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'calidad', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Supervisión del correcto etiquetado de alérgenos en insumos empacados', 'estimated_mins' => 20, 'priority' => 'bloqueante', 'category' => 'calidad', 'target_role_name' => 'Supervisor de Producción', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],

                        // Asesor de Ventas (12 Tareas)
                        ['title' => 'Bienvenida y atención personalizada a clientes reposteros y panaderos', 'estimated_mins' => 45, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Asesoría técnica sobre rendimiento y uso de insumos, esencias y coberturas', 'estimated_mins' => 30, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Cobro rápido en caja y manejo de terminales de pago', 'estimated_mins' => 45, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Verificación de datos fiscales para facturación a clientes', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Limpieza fina de mostrador, vitrinas y desinfección de terminales punto de venta', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de mostrador limpio.'],
                        ['title' => 'Revisión general de productos exhibidos en góndola para verificar faltantes', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Rellenar góndolas y acomodar mercancía (Frenteo, orden y alineación de precios)', 'estimated_mins' => 30, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de góndola frenteada.'],
                        ['title' => 'Limpieza fina de estanterías y productos destacados', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Apoyo en el etiquetado de precios de nueva colección o promociones', 'estimated_mins' => 25, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Verificar pedidos especiales del día y validarlos con el sistema', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Acomodo de devoluciones de productos en anaqueles', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Conteo e inventario rápido de cierre en zona de mostrador', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Piezas contadas en mostrador.'],

                        // Ayudante Integral (14 Tareas)
                        ['title' => 'Levantar las cortinas de la entrada y quitar candados', 'estimated_mins' => 10, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Abrir la puerta principal y colocar la rampa de acceso', 'estimated_mins' => 5, 'priority' => 'bloqueante', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de rampa colocada.'],
                        ['title' => 'Sacar los tapetes de bienvenida y colocarlos en la entrada', 'estimated_mins' => 5, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Barrer la banqueta exterior y limpiar fachada frontal', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de banqueta limpia.'],
                        ['title' => 'Limpieza de las vitrinas frontales de exhibición principal', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Lavar los tapetes de bienvenida de la entrada con hidrolavadora', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Trapear los pasillos principales y áreas de exhibición', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Lavar y desinfectar el baño de clientes y personal', 'estimated_mins' => 25, 'priority' => 'bloqueante', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de baños sanitizados.'],
                        ['title' => 'Limpiar las escaleras interiores y sacudir pasamanos', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Limpiar el patio de servicio y ordenar contenedores de mermas', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Surtido de bodega a exhibidores y traslado de mercancía pesada', 'estimated_mins' => 30, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Sacudir polvo acumulado en estanterías de bodega trasera', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Guardar tapetes de entrada, cerrar rampa y candados al cierre', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de candado cerrado.'],
                        ['title' => 'Recolección y depósito de basura general de la sucursal', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'operativo', 'target_role_name' => 'Ayudante Integral', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],

                        // Apoyo Eventual (6 Tareas)
                        ['title' => 'Apoyo en la descarga de fletes pesados de sacos de harina, azúcar y mantecas', 'estimated_mins' => 45, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Apoyo Eventual', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Apoyo en el traslado de sacos de bodega a zona de fraccionado', 'estimated_mins' => 30, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Apoyo Eventual', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Acomodar y clasificar cajas vacías en el área de reciclaje', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Apoyo Eventual', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de cajas clasificadas.'],
                        ['title' => 'Apoyo en el pegado de etiquetas de promociones y reempaquetado especial', 'estimated_mins' => 35, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Apoyo Eventual', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Limpieza y despeje de pasillos auxiliares en temporadas de alta afluencia', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Apoyo Eventual', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Apoyo general en montaje de exhibiciones de temporada (Navidad, Rosca, Valentín)', 'estimated_mins' => 40, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Apoyo Eventual', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Foto de exhibición montada.']
                    ];
                } elseif ($nicho === 'retail') {
                    $puestos = [
                        ['name' => 'Gerente de Tienda', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Gerencia'],
                        ['name' => 'Supervisor de Cajas', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Cajas'],
                        ['name' => 'Asesor de Ventas y Piso', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Piso de Ventas'],
                        ['name' => 'Almacenista / Inventarios', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Almacén']
                    ];
                    $tareas = [
                        ['title' => 'Conteo y validación de fondo de caja', 'estimated_mins' => 15, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Cajas', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingrese el monto contado del fondo de caja.'],
                        ['title' => 'Desactivación de alarma y encendido de switch', 'estimated_mins' => 10, 'priority' => 'alta', 'category' => 'seguridad', 'target_role_name' => 'Gerente de Tienda', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de la pantalla de la alarma desactivada.'],
                        ['title' => 'Alineación de precios y frenteo de mercancía', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Asesor de Ventas y Piso', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto del exhibidor ordenado.'],
                        ['title' => 'Cierre de cortina metálica y candado', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Gerente de Tienda', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto del candado cerrado.']
                    ];
                } elseif ($nicho === 'restaurante') {
                    $puestos = [
                        ['name' => 'Gerente de Restaurante', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración'],
                        ['name' => 'Chef / Jefe de Cocina', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Cocina'],
                        ['name' => 'Mesero / Atención al Cliente', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Servicio'],
                        ['name' => 'Ayudante de Cocina', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Cocina']
                    ];
                    $tareas = [
                        ['title' => 'Verificación de temperatura de congeladores', 'estimated_mins' => 15, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Chef / Jefe de Cocina', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingrese la temperatura actual en °C.'],
                        ['title' => 'Montaje y sanitización de mesas de comedor', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Mesero / Atención al Cliente', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de la estación limpia.'],
                        ['title' => 'Inspección y cierre de llaves de gas principal', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Chef / Jefe de Cocina', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de la válvula de gas cerrada.']
                    ];
                } elseif ($nicho === 'oficina') {
                    $puestos = [
                        ['name' => 'Director General', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Dirección'],
                        ['name' => 'Coordinador de Operaciones', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Operaciones'],
                        ['name' => 'Consultor / Ejecutivo de Cuenta', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Operaciones'],
                        ['name' => 'Recepcionista / Asistente', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Administración']
                    ];
                    $tareas = [
                        ['title' => 'Encendido y verificación de servidores locales', 'estimated_mins' => 15, 'priority' => 'alta', 'category' => 'tecnología', 'target_role_name' => 'Coordinador de Operaciones', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                        ['title' => 'Revisión y distribución de correspondencia', 'estimated_mins' => 10, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Recepcionista / Asistente', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de la bitácora de firmas.']
                    ];
                } elseif ($nicho === 'taller') {
                    $puestos = [
                        ['name' => 'Jefe de Taller', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración'],
                        ['name' => 'Supervisor de Seguridad', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Calidad'],
                        ['name' => 'Técnico Operador / Mecánico', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Producción'],
                        ['name' => 'Ayudante de Almacén', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Logística']
                    ];
                    $tareas = [
                        ['title' => 'Inspección de equipo de protección personal (EPP)', 'estimated_mins' => 10, 'priority' => 'alta', 'category' => 'seguridad', 'target_role_name' => 'Supervisor de Seguridad', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto grupal del equipo portando su EPP.'],
                        ['title' => 'Apagado de disyuntores de alta tensión y soldadoras', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Técnico Operador / Mecánico', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto del disyuntor apagado.']
                    ];
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

            // 2. Inyectar Tareas en base de datos (`tasks`)
            if (!empty($tareas)) {
                foreach ($tareas as $t) {
                    $targetRoleId = isset($roleIdsMap[$t['target_role_name']]) ? $roleIdsMap[$t['target_role_name']] : $firstGerenteRole;
                    if ($targetRoleId) {
                        \DB::table('tasks')->insert([
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
}
