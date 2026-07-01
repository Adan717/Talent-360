<?php
 
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
 
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
 
try {
    DB::beginTransaction();
 
    // 1. Limpiar o actualizar Tenant 1
    DB::table('tenants')->updateOrInsert(
        ['id' => 1],
        [
            'name' => 'DecorArte 360',
            'subdomain' => 'decorarte',
            'plan' => 'pro',
            'brand_color' => '#8b5cf6', // Morado/Lila brillante
            'logo_url' => 'https://decorarte360.com/logo.png',
            'subscription_status' => 'active',
            'trial_ends_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now()
        ]
    );
 
    // Limpiar tablas para evitar duplicados en el seed de DecorArte
    DB::table('candidates')->where('tenant_id', 1)->delete();
    DB::table('vacancies')->where('tenant_id', 1)->delete();
    DB::table('academy_courses')->where('tenant_id', 1)->delete();
    DB::table('task_assignments')->where('tenant_id', 1)->delete();
    DB::table('routine_task')->delete();
    DB::table('routines')->where('tenant_id', 1)->delete();
    DB::table('tasks')->where('tenant_id', 1)->delete();
    DB::table('employees')->where('tenant_id', 1)->delete();
    DB::table('users')->where('tenant_id', 1)->delete();
    DB::table('job_roles')->where('tenant_id', 1)->delete();
 
    // 2. Insertar Puestos de DecorArte
    $rolesData = [
        ['id' => 11, 'name' => 'Administrador Gerente', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración'],
        ['id' => 12, 'name' => 'Supervisor de Compras', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Compras'],
        ['id' => 13, 'name' => 'Supervisor de Ventas', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Ventas'],
        ['id' => 14, 'name' => 'Supervisor de Producción', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Producción'],
        ['id' => 15, 'name' => 'Atención al Cliente', 'esAperturador' => false, 'jerarquiaLlaves' => 4, 'area' => 'Atención'],
        ['id' => 16, 'name' => 'Ayudante Integral', 'esAperturador' => false, 'jerarquiaLlaves' => 5, 'area' => 'Piso'],
        ['id' => 17, 'name' => 'Apoyo Eventual', 'esAperturador' => false, 'jerarquiaLlaves' => 6, 'area' => 'Piso']
    ];
 
    foreach ($rolesData as $r) {
        DB::table('job_roles')->insert([
            'id' => $r['id'],
            'tenant_id' => 1,
            'name' => $r['name'],
            'area' => $r['area'],
            'esAperturador' => $r['esAperturador'],
            'jerarquiaLlaves' => $r['jerarquiaLlaves'],
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
 
    // 3. Crear Usuarios y Empleados por defecto para DecorArte
    $employeesData = [
        [
            'name' => 'Francisco',
            'email' => 'francisco@decorarte360.com',
            'role' => 'admin',
            'job_role_id' => 11,
            'portador_llaves' => 'ambos',
            'shift_start' => '08:20',
            'shift_end' => '18:00',
            'meal_minutes' => 60,
            'rest_day' => 'Domingo'
        ],
        [
            'name' => 'Liz',
            'email' => 'liz@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 12,
            'portador_llaves' => 'apertura',
            'shift_start' => '08:20',
            'shift_end' => '18:00',
            'meal_minutes' => 60,
            'rest_day' => 'Lunes'
        ],
        [
            'name' => 'Joseline',
            'email' => 'joseline@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 13,
            'portador_llaves' => 'apertura',
            'shift_start' => '08:20',
            'shift_end' => '18:00',
            'meal_minutes' => 60,
            'rest_day' => 'Martes'
        ],
        [
            'name' => 'Hiraym',
            'email' => 'hiraym@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 14,
            'portador_llaves' => 'ninguno',
            'shift_start' => '09:00',
            'shift_end' => '18:00',
            'meal_minutes' => 60,
            'rest_day' => 'Miércoles'
        ],
        [
            'name' => 'Agnela',
            'email' => 'agnela@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 15,
            'portador_llaves' => 'ninguno',
            'shift_start' => '08:30',
            'shift_end' => '17:00',
            'meal_minutes' => 30,
            'rest_day' => 'Domingo'
        ],
        [
            'name' => 'Adriana',
            'email' => 'adriana@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 15,
            'portador_llaves' => 'ninguno',
            'shift_start' => '08:30',
            'shift_end' => '17:00',
            'meal_minutes' => 30,
            'rest_day' => 'Lunes'
        ],
        [
            'name' => 'Cristina',
            'email' => 'cristina@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 16,
            'portador_llaves' => 'ninguno',
            'shift_start' => '08:30',
            'shift_end' => '17:00',
            'meal_minutes' => 30,
            'rest_day' => 'Martes'
        ],
        [
            'name' => 'Valeria',
            'email' => 'valeria@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 16,
            'portador_llaves' => 'ninguno',
            'shift_start' => '09:00',
            'shift_end' => '17:30',
            'meal_minutes' => 30,
            'rest_day' => 'Miércoles'
        ]
    ];
 
    foreach ($employeesData as $emp) {
        // Crear usuario
        $userId = DB::table('users')->insertGetId([
            'tenant_id' => 1,
            'name' => $emp['name'],
            'email' => $emp['email'],
            'password' => Hash::make('password123'),
            'role' => $emp['role'],
            'job_role_id' => $emp['job_role_id'],
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
 
        // Crear empleado
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $userId,
            'name' => $emp['name'],
            'email' => $emp['email'],
            'job_role_id' => $emp['job_role_id'],
            'portadorLlaves' => $emp['portador_llaves'],
            'shiftStart' => $emp['shift_start'],
            'shiftEnd' => $emp['shift_end'],
            'mealMinutes' => $emp['meal_minutes'],
            'restDay' => $emp['rest_day'],
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
 
    // 4. Crear Tareas y Checklists diarios de DecorArte
    $tasksData = [
        // Administrador Gerente
        ['title' => 'Verificación de seguridad perimetral exterior', 'estimated_mins' => 10, 'priority' => 'alta', 'category' => 'operativo', 'target_id' => 11, 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Toma foto de la fachada exterior de la tienda.'],
        ['title' => 'Encendido de sistemas, luces y pantallas', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_id' => 11, 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
        ['title' => 'Corte de terminales bancarias y arqueo', 'estimated_mins' => 20, 'priority' => 'bloqueante', 'category' => 'administrativo', 'target_id' => 11, 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingresa el total acumulado en caja chica.'],
 
        // Supervisor de Compras
        ['title' => 'Recepción y verificación de suministros de piso', 'estimated_mins' => 30, 'priority' => 'alta', 'category' => 'operativo', 'target_id' => 12, 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Toma foto de la remisión firmada.'],
        ['title' => 'Inspección de limpieza de pasillos y exhibidores', 'estimated_mins' => 15, 'priority' => 'normal', 'category' => 'operativo', 'target_id' => 12, 'assistant_type' => 'ninguno', 'assistant_prompt' => ''],
 
        // Supervisor de Ventas
        ['title' => 'Corte de terminales de punto de venta', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'administrativo', 'target_id' => 13, 'assistant_type' => 'captura_numero', 'assistant_prompt' => 'Ingresa el monto del reporte de ventas diario.'],
        ['title' => 'Asegurar el cierre de la caja fuerte', 'estimated_mins' => 10, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_id' => 13, 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Toma foto del candado de la tómbola.'],
 
        // Supervisor de Producción
        ['title' => 'Apagado y bloqueo de maquinaria de corte', 'estimated_mins' => 15, 'priority' => 'bloqueante', 'category' => 'seguridad', 'target_id' => 14, 'assistant_type' => 'evidencia_foto', 'assistant_prompt' => 'Toma foto del interruptor principal apagado.'],
        ['title' => 'Limpieza y orden del taller de ensamble', 'estimated_mins' => 25, 'priority' => 'normal', 'category' => 'operativo', 'target_id' => 14, 'assistant_type' => 'ninguno', 'assistant_prompt' => '']
    ];
 
    $insertedTasks = [];
    foreach ($tasksData as $t) {
        $id = DB::table('tasks')->insertGetId([
            'tenant_id' => 1,
            'title' => $t['title'],
            'estimated_mins' => $t['estimated_mins'],
            'priority' => $t['priority'],
            'category' => $t['category'],
            'target_type' => 'role',
            'target_id' => $t['target_id'],
            'assistant_type' => $t['assistant_type'],
            'assistant_prompt' => $t['assistant_prompt'],
            'is_auto_capture' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $insertedTasks[$t['title']] = $id;
    }
 
    // 5. Crear las Rutinas
    $routinesData = [
        ['title' => 'Rutina de Apertura de Tienda', 'target_role_id' => 11, 'trigger' => 'apertura', 'assign_mode' => 'equitativo'],
        ['title' => 'Rutina de Cierre de Tienda', 'target_role_id' => 11, 'trigger' => 'cierre', 'assign_mode' => 'fijo'],
        ['title' => 'Rutina de Apertura de Piso', 'target_role_id' => 12, 'trigger' => 'apertura', 'assign_mode' => 'equitativo'],
        ['title' => 'Rutina de Cierre de Ventas', 'target_role_id' => 13, 'trigger' => 'cierre', 'assign_mode' => 'fijo'],
        ['title' => 'Rutina de Cierre de Producción', 'target_role_id' => 14, 'trigger' => 'cierre', 'assign_mode' => 'fijo']
    ];
 
    $insertedRoutines = [];
    foreach ($routinesData as $r) {
        $id = DB::table('routines')->insertGetId([
            'tenant_id' => 1,
            'title' => $r['title'],
            'target_role_id' => $r['target_role_id'],
            'trigger' => $r['trigger'],
            'assign_mode' => $r['assign_mode'],
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $insertedRoutines[$r['title']] = $id;
    }
 
    // 6. Ligar Rutinas con Tareas (routine_task)
    $routineTasks = [
        // Rutina de Apertura de Tienda -> Seguridad perimetral, Encendido luces
        ['routine' => 'Rutina de Apertura de Tienda', 'task' => 'Verificación de seguridad perimetral exterior'],
        ['routine' => 'Rutina de Apertura de Tienda', 'task' => 'Encendido de sistemas, luces y pantallas'],
 
        // Rutina de Cierre de Tienda -> Corte de terminales
        ['routine' => 'Rutina de Cierre de Tienda', 'task' => 'Corte de terminales bancarias y arqueo'],
 
        // Rutina de Apertura de Piso -> Recepción de suministros, Inspección de pasillos
        ['routine' => 'Rutina de Apertura de Piso', 'task' => 'Recepción y verificación de suministros de piso'],
        ['routine' => 'Rutina de Apertura de Piso', 'task' => 'Inspección de limpieza de pasillos y exhibidores'],
 
        // Rutina de Cierre de Ventas -> Corte terminales cajas, Cierre de caja fuerte
        ['routine' => 'Rutina de Cierre de Ventas', 'task' => 'Corte de terminales de punto de venta'],
        ['routine' => 'Rutina de Cierre de Ventas', 'task' => 'Asegurar el cierre de la caja fuerte'],
 
        // Rutina de Cierre de Producción -> Apagado de maquinas, Limpieza taller
        ['routine' => 'Rutina de Cierre de Producción', 'task' => 'Apagado y bloqueo de maquinaria de corte'],
        ['routine' => 'Rutina de Cierre de Producción', 'task' => 'Limpieza y orden del taller de ensamble']
    ];
 
    foreach ($routineTasks as $rt) {
        DB::table('routine_task')->insert([
            'routine_id' => $insertedRoutines[$rt['routine']],
            'task_id' => $insertedTasks[$rt['task']],
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    // 7. Crear Vacantes para DecorArte (Tenant 1)
    $vacanciesData = [
        [
            'job_role_id' => 11,
            'title' => 'Gerente de Sucursal DecorArte',
            'description' => 'Buscamos un gerente de sucursal entusiasta para liderar las operaciones y ventas en DecorArte.',
            'requirements' => "Licenciatura trunca o terminada.\nExperiencia mínima de 2 años en gerencia.\nManejo de personal y KPIs.",
            'is_active' => true,
            'is_hidden' => false,
            'image_url' => 'https://images.unsplash.com/photo-1556761175-4b46a572b786?auto=format&fit=crop&q=80&w=1000',
            'work_type' => 'Tiempo Completo',
            'schedule' => 'Lunes a Sábado 9:00 AM a 7:00 PM',
            'salary_range' => '$12,000 - $15,000 Mensuales',
        ],
        [
            'job_role_id' => 12,
            'title' => 'Supervisor de Tienda y Compras',
            'description' => 'Responsable de la supervisión de piso de ventas, compras y negociación con proveedores.',
            'requirements' => "Experiencia en piso de ventas y almacén.\nManejo de inventarios y proveedores.",
            'is_active' => true,
            'is_hidden' => false,
            'image_url' => 'https://images.unsplash.com/photo-1604719312566-8912e9227c6a?auto=format&fit=crop&q=80&w=1000',
            'work_type' => 'Tiempo Completo',
            'schedule' => 'Lunes a Sábado 9:00 AM a 7:00 PM',
            'salary_range' => '$8,000 - $10,000 Mensuales',
        ],
        [
            'job_role_id' => 15,
            'title' => 'Cajero(a) / Atención al Cliente',
            'description' => 'Atención al cliente y cobro en cajas de DecorArte.',
            'requirements' => "Experiencia en cobro y actitud de servicio.\nSecundaria terminada.",
            'is_active' => true,
            'is_hidden' => false,
            'image_url' => 'https://images.unsplash.com/photo-1600880292203-757bb62b4baf?auto=format&fit=crop&q=80&w=1000',
            'work_type' => 'Tiempo Completo',
            'schedule' => 'L-D rotativo con descanso entre semana',
            'salary_range' => '$1,500 - $1,800 Semanales',
        ]
    ];

    $insertedVacancies = [];
    foreach ($vacanciesData as $v) {
        $vId = DB::table('vacancies')->insertGetId(array_merge($v, [
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]));
        $insertedVacancies[$v['title']] = $vId;
    }

    // 8. Crear Candidatos para DecorArte (Tenant 1)
    $candidatesData = [
        [
            'applied_vacancy_id' => $insertedVacancies['Cajero(a) / Atención al Cliente'],
            'email' => 'juan.perez@example.com',
            'name' => 'Juan Pérez López',
            'phone' => '4621234567',
            'status' => 'prospect',
            'hr_notes' => 'Buen perfil, se programará llamada inicial.'
        ],
        [
            'applied_vacancy_id' => $insertedVacancies['Supervisor de Tienda y Compras'],
            'email' => 'maria.gomez@example.com',
            'name' => 'María Gómez Ramos',
            'phone' => '4629876543',
            'status' => 'interview',
            'hr_notes' => 'Entrevista agendada para el lunes a las 10:00 AM.'
        ]
    ];

    foreach ($candidatesData as $c) {
        DB::table('candidates')->insert(array_merge($c, [
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]));
    }

    // 9. Crear Cursos de la Academia para DecorArte (Tenant 1)
    $coursesData = [
        [
            'title' => 'Inducción DecorArte 360',
            'description' => 'Conoce nuestra historia, misión, visión y los valores fundamentales de DecorArte.',
            'course_type' => 'induction',
            'target_job_role_id' => null,
            'incentive_bonus_cents' => 0,
            'video_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'quiz_data' => json_encode([
                ['question' => '¿Cuál es el valor principal de DecorArte?', 'options' => ['Puntualidad', 'Creatividad', 'Honestidad'], 'answer' => 'Creatividad']
            ]),
            'is_active' => true
        ],
        [
            'title' => 'Entrenamiento: Recepción y Registro en SICAR',
            'description' => 'Aprende los protocolos de recepción de mercancía y cómo ingresarlos de manera correcta en el sistema SICAR.',
            'course_type' => 'training',
            'target_job_role_id' => 12, // Supervisor de Compras
            'incentive_bonus_cents' => 30000, // $300.00
            'video_url' => 'https://www.youtube.com/embed/tgbNymZ7vqY',
            'quiz_data' => json_encode([
                ['question' => '¿Qué sistema se utiliza para capturar compras?', 'options' => ['Excel', 'SICAR', 'SAP'], 'answer' => 'SICAR']
            ]),
            'is_active' => true
        ],
        [
            'title' => 'Entrenamiento: Manejo de Caja y Terminales',
            'description' => 'Aprende a usar la terminal punto de venta, realizar cobros y hacer cortes de caja.',
            'course_type' => 'training',
            'target_job_role_id' => 15, // Atención al Cliente
            'incentive_bonus_cents' => 20000, // $200.00
            'video_url' => 'https://www.youtube.com/embed/1k8craCGv14',
            'quiz_data' => json_encode([
                ['question' => '¿Qué se debe realizar al finalizar el turno de caja?', 'options' => ['Irse de inmediato', 'Limpieza general', 'Corte y arqueo de caja'], 'answer' => 'Corte y arqueo de caja']
            ]),
            'is_active' => true
        ]
    ];

    foreach ($coursesData as $course) {
        DB::table('academy_courses')->insert(array_merge($course, [
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]));
    }

    DB::commit();
    echo "¡Estructura de DecorArte 360 poblada con éxito en PostgreSQL real!\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error poblando DecorArte: " . $e->getMessage() . "\n";
}
