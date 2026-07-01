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
        $company = Company::findOrFail($tenantId);
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

        $company = Company::findOrFail($tenantId);
        $company->update([
            'welcome_title' => $request->welcomeTitle,
            'welcome_message' => $request->welcomeMessage,
            'welcome_image_url' => $request->welcomeImageUrl,
            'welcome_video_url' => $request->welcomeVideoUrl,
        ]);

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
     * Configura el nicho de negocio, inyectando puestos y tareas por defecto o generados por Gemini.
     */
    public function configureNicho(Request $request)
    {
        $request->validate([
            'nicho' => 'required|string',
            'custom_nicho_description' => 'nullable|string'
        ]);

        $tenantId = auth()->user()->tenant_id ?? 1;
        $nicho = strtolower($request->nicho);
        $customDescription = $request->custom_nicho_description;

        try {
            \DB::beginTransaction();

            $puestos = [];
            $tareas = [];

            if ($nicho === 'retail') {
                $puestos = [
                    ['name' => 'Gerente de Tienda', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración'],
                    ['name' => 'Supervisor de Cajas', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Ventas'],
                    ['name' => 'Cajero / Vendedor', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Ventas'],
                    ['name' => 'Ayudante General', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Piso']
                ];
                $tareas = [
                    ['title' => 'Verificación de terminales de pago', 'estimated_mins' => 15, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Supervisor de Cajas', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingrese el número de terminales encendidas y listas.'],
                    ['title' => 'Checklist de limpieza de vitrinas', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante General', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de los exhibidores limpios.'],
                    ['title' => 'Cierre de cortina metálica y candado', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Gerente de Tienda', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto del candado cerrado.']
                ];
            } elseif ($nicho === 'restaurante') {
                $puestos = [
                    ['name' => 'Gerente de Restaurante', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración'],
                    ['name' => 'Jefe de Cocina', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Cocina'],
                    ['name' => 'Mesero / Atención', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Servicio'],
                    ['name' => 'Repartidor', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Servicio']
                ];
                $tareas = [
                    ['title' => 'Checklist de encendido y limpieza de estufas', 'estimated_mins' => 20, 'priority' => 'alta', 'category' => 'operativo', 'target_role_name' => 'Jefe de Cocina', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de la parrilla limpia.'],
                    ['title' => 'Corte de caja de barra y bebidas', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'administrativo', 'target_role_name' => 'Gerente de Restaurante', 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingrese el total de comandas cobradas.'],
                    ['title' => 'Cierre e inspección de llaves de gas', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Jefe de Cocina', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de la válvula de gas cerrada.']
                ];
            } elseif ($nicho === 'oficina') {
                $puestos = [
                    ['name' => 'Director General', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración'],
                    ['name' => 'Coordinador de Operaciones', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Operaciones'],
                    ['name' => 'Consultor / Staff', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Operaciones'],
                    ['name' => 'Recepcionista', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Administración']
                ];
                $tareas = [
                    ['title' => 'Encendido y verificación de servidores locales', 'estimated_mins' => 15, 'priority' => 'alta', 'category' => 'tecnología', 'target_role_name' => 'Coordinador de Operaciones', 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
                    ['title' => 'Revisión y distribución de correspondencia', 'estimated_mins' => 10, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Recepcionista', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto de la bitácora de firmas.'],
                    ['title' => 'Checklist de apagado de luces y clima', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Director General', 'assistant_type' => 'ninguno', 'assistant_prompt' => '']
                ];
            } elseif ($nicho === 'taller') {
                $puestos = [
                    ['name' => 'Jefe de Taller', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración'],
                    ['name' => 'Supervisor de Seguridad', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Calidad'],
                    ['name' => 'Operador de Maquinaria', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Producción'],
                    ['name' => 'Ayudante de Almacén', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Logística']
                ];
                $tareas = [
                    ['title' => 'Inspección de equipo de protección personal (EPP)', 'estimated_mins' => 10, 'priority' => 'alta', 'category' => 'seguridad', 'target_role_name' => 'Supervisor de Seguridad', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto grupal del equipo portando su EPP.'],
                    ['title' => 'Apagado de prensas y soldadoras eléctricas', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_role_name' => 'Operador de Maquinaria', 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Tome foto del disyuntor apagado.'],
                    ['title' => 'Inventariar herramienta manual de taller', 'estimated_mins' => 20, 'priority' => 'normal', 'category' => 'operativo', 'target_role_name' => 'Ayudante de Almacén', 'assistant_type' => 'ninguno', 'assistant_prompt' => '']
                ];
            } else {
                // Nicho personalizado por IA (Gemini)
                $prompt = "Genera una lista de puestos y checklists de tareas operativas recomendadas para una empresa del nicho: '{$customDescription}'. Retorna el resultado estrictamente en formato JSON con la siguiente estructura y sin texto explicativo adicional:
                {
                    \"puestos\": [
                        {\"name\": \"Gerente\", \"esAperturador\": true, \"jerarquiaLlaves\": 1, \"area\": \"Administración\"},
                        ... (máximo 4 puestos)
                    ],
                    \"tareas\": [
                        {\"title\": \"Nombre de la tarea\", \"estimated_mins\": 15, \"priority\": \"alta|normal|bloqueante\", \"category\": \"operativo|administrativo|seguridad\", \"target_role_name\": \"Nombre del puesto asignado\", \"assistant_type\": \"evidencia_foto|captura_numero|ninguno\", \"assistant_prompt\": \"Instrucción de evidencia\"},
                        ... (máximo 4 tareas)
                    ]
                }";

                $geminiKey = env('GEMINI_API_KEY');
                if (!$geminiKey) {
                    throw new \Exception("GEMINI_API_KEY no configurado en el servidor.");
                }

                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $geminiKey, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                    ]
                ]);

                if ($response->failed()) {
                    throw new \Exception("Error al conectar con la API de Gemini: " . $response->body());
                }

                $resultText = $response->json('candidates.0.content.parts.0.text');
                
                // Limpiar posibles bloques markdown del JSON retornado por la IA
                $resultText = preg_replace('/^```json\s*/i', '', $resultText);
                $resultText = preg_replace('/```$/', '', $resultText);
                $resultText = trim($resultText);

                $result = json_decode($resultText, true);
                if (!$result || !isset($result['puestos']) || !isset($result['tareas'])) {
                    throw new \Exception("La respuesta de la IA no tiene el formato esperado.");
                }

                $puestos = $result['puestos'];
                $tareas = $result['tareas'];
            }

            // Inyectar Puestos en base de datos
            $roleIdsMap = [];
            foreach ($puestos as $p) {
                $roleId = \DB::table('job_roles')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => $p['name'],
                    'area' => $p['area'] ?? 'General',
                    'esAperturador' => $p['esAperturador'] ?? false,
                    'jerarquiaLlaves' => $p['jerarquiaLlaves'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $roleIdsMap[$p['name']] = $roleId;
            }

            // Inyectar Tareas en base de datos
            foreach ($tareas as $t) {
                $targetRoleId = isset($roleIdsMap[$t['target_role_name']]) ? $roleIdsMap[$t['target_role_name']] : null;
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

            \DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Nicho configurado correctamente con puestos y checklists recomendados.',
                'puestos_creados' => count($puestos)
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al configurar nicho: ' . $e->getMessage()
            ], 500);
        }
    }
}
