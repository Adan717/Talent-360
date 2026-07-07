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
            'subdomain' => 'decorarte360',
            'public_slug' => 'decorarte360',
            'plan' => 'enterprise',
            'brand_color' => '#8b5cf6', // Morado/Lila brillante
            'logo_url' => 'https://decorarte360.com/logo.png',
            'subscription_status' => 'active',
            'trial_ends_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now()
        ]
    );
 
    // Limpiar tablas para evitar duplicados en el seed de DecorArte
    $demoEmails = [
        'francisco@decorarte360.com',
        'liz@decorarte360.com',
        'joseline@decorarte360.com',
        'hiraym@decorarte360.com',
        'agnela@decorarte360.com',
        'adriana@decorarte360.com',
        'cristina@decorarte360.com',
        'valeria@decorarte360.com',
        'paloma@decorarte360.com',
        'adan@decorarte360.com',
        'cristian@decorarte360.com'
    ];
    
    // Delete any old references to avoid foreign key violations
    DB::table('time_entries')->whereIn('user_id', function($q) use ($demoEmails) {
        $q->select('id')->from('users')->where('tenant_id', 1)->whereIn('email', $demoEmails);
    })->delete();
    
    DB::table('candidates')->where('tenant_id', 1)->delete();
    DB::table('vacancies')->where('tenant_id', 1)->delete();
    DB::table('academy_courses')->where('tenant_id', 1)->delete();
    DB::table('task_assignments')->where('tenant_id', 1)->delete();
    DB::table('routine_task')->delete();
    DB::table('routines')->where('tenant_id', 1)->delete();
    DB::table('tasks')->where('tenant_id', 1)->delete();
    DB::table('employees')->where('tenant_id', 1)->delete(); // clear all
    DB::table('users')->where('tenant_id', 1)->delete(); // clear all
    DB::table('job_roles')->where('tenant_id', 1)->delete(); // clear all
 
    // 2. Insertar Puestos de DecorArte con la tolerancia, justificante y jerarquía de llaves correctos
    $rolesData = [
        [
            'id' => 11,
            'name' => 'Administrador Gerente',
            'area' => 'Administración',
            'esAperturador' => true,
            'jerarquiaLlaves' => 1,
            'portadorLlaves' => 'ambos',
            'tiempoTolerancia' => 15,
            'requiereJustificante' => true,
            'reports_to_role_id' => null,
            'org_parent_role_id' => null,
            'reports_to_role_ids' => null,
            'nivel_mando' => 1
        ],
        [
            'id' => 12,
            'name' => 'Supervisor de Compras',
            'area' => 'Compras',
            'esAperturador' => true,
            'jerarquiaLlaves' => 2,
            'portadorLlaves' => 'apertura',
            'tiempoTolerancia' => 15,
            'requiereJustificante' => true,
            'reports_to_role_id' => 11,
            'org_parent_role_id' => 11,
            'reports_to_role_ids' => json_encode([11]),
            'nivel_mando' => 2
        ],
        [
            'id' => 13,
            'name' => 'Supervisor de Ventas',
            'area' => 'Ventas',
            'esAperturador' => true,
            'jerarquiaLlaves' => 2,
            'portadorLlaves' => 'apertura',
            'tiempoTolerancia' => 15,
            'requiereJustificante' => true,
            'reports_to_role_id' => 11,
            'org_parent_role_id' => 11,
            'reports_to_role_ids' => json_encode([11]),
            'nivel_mando' => 2
        ],
        [
            'id' => 14,
            'name' => 'Supervisor de Producción',
            'area' => 'Producción',
            'esAperturador' => false,
            'jerarquiaLlaves' => 0,
            'portadorLlaves' => 'ninguno',
            'tiempoTolerancia' => 10,
            'requiereJustificante' => true,
            'reports_to_role_id' => 11,
            'org_parent_role_id' => 11,
            'reports_to_role_ids' => json_encode([11]),
            'nivel_mando' => 2
        ],
        [
            'id' => 15,
            'name' => 'Atención al Cliente',
            'area' => 'Atención',
            'esAperturador' => false,
            'jerarquiaLlaves' => 0,
            'portadorLlaves' => 'ninguno',
            'tiempoTolerancia' => 10,
            'requiereJustificante' => false,
            'reports_to_role_id' => 13,
            'org_parent_role_id' => 13,
            'reports_to_role_ids' => json_encode([13]),
            'nivel_mando' => 3
        ],
        [
            'id' => 16,
            'name' => 'Ayudante Integral',
            'area' => 'Piso',
            'esAperturador' => false,
            'jerarquiaLlaves' => 3,
            'portadorLlaves' => 'cierre',
            'tiempoTolerancia' => 10,
            'requiereJustificante' => false,
            'reports_to_role_id' => 12,
            'org_parent_role_id' => 12,
            'reports_to_role_ids' => json_encode([12]),
            'nivel_mando' => 3
        ],
        [
            'id' => 17,
            'name' => 'Apoyo Eventual',
            'area' => 'Piso',
            'esAperturador' => false,
            'jerarquiaLlaves' => 0,
            'portadorLlaves' => 'ninguno',
            'tiempoTolerancia' => 10,
            'requiereJustificante' => false,
            'reports_to_role_id' => 12,
            'org_parent_role_id' => 12,
            'reports_to_role_ids' => json_encode([12]),
            'nivel_mando' => 4
        ]
    ];
  
    foreach ($rolesData as $r) {
        DB::table('job_roles')->insert([
            'id' => $r['id'],
            'tenant_id' => 1,
            'name' => $r['name'],
            'area' => $r['area'],
            'esAperturador' => $r['esAperturador'],
            'jerarquiaLlaves' => $r['jerarquiaLlaves'],
            'portadorLlaves' => $r['portadorLlaves'],
            'tiempoTolerancia' => $r['tiempoTolerancia'],
            'requiereJustificante' => $r['requiereJustificante'],
            'reports_to_role_id' => $r['reports_to_role_id'],
            'org_parent_role_id' => $r['org_parent_role_id'],
            'reports_to_role_ids' => $r['reports_to_role_ids'],
            'nivel_mando' => $r['nivel_mando'],
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
  
    // 3. Crear Usuarios y Empleados por defecto para DecorArte
    $employeesData = [
        [
            'id' => 1,
            'name' => 'Francisco',
            'email' => 'francisco@decorarte360.com',
            'role' => 'admin',
            'job_role_id' => 11,
            'portadorLlaves' => 'ambos',
            'shiftStart' => '08:20',
            'shiftEnd' => '18:00',
            'mealMinutes' => 60,
            'restDay' => 'Domingo',
            'reliefBuddyId' => 2
        ],
        [
            'id' => 2,
            'name' => 'Liz',
            'email' => 'liz@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 12,
            'portadorLlaves' => 'apertura',
            'shiftStart' => '08:20',
            'shiftEnd' => '18:00',
            'mealMinutes' => 60,
            'restDay' => 'Lunes',
            'reliefBuddyId' => 3
        ],
        [
            'id' => 3,
            'name' => 'Joseline',
            'email' => 'joseline@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 13,
            'portadorLlaves' => 'apertura',
            'shiftStart' => '08:20',
            'shiftEnd' => '18:00',
            'mealMinutes' => 60,
            'restDay' => 'Martes',
            'reliefBuddyId' => 2
        ],
        [
            'id' => 4,
            'name' => 'Hiraym',
            'email' => 'hiraym@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 14,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '09:00',
            'shiftEnd' => '18:00',
            'mealMinutes' => 60,
            'restDay' => 'Miércoles',
            'reliefBuddyId' => 1
        ],
        [
            'id' => 5,
            'name' => 'Agnela',
            'email' => 'agnela@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 15,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '08:30',
            'shiftEnd' => '17:00',
            'mealMinutes' => 30,
            'restDay' => 'Domingo',
            'reliefBuddyId' => 6
        ],
        [
            'id' => 6,
            'name' => 'Adriana',
            'email' => 'adriana@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 15,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '08:30',
            'shiftEnd' => '17:00',
            'mealMinutes' => 30,
            'restDay' => 'Lunes',
            'reliefBuddyId' => 5
        ],
        [
            'id' => 7,
            'name' => 'Cristina',
            'email' => 'cristina@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 16,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '08:30',
            'shiftEnd' => '17:00',
            'mealMinutes' => 30,
            'restDay' => 'Martes',
            'reliefBuddyId' => 8
        ],
        [
            'id' => 8,
            'name' => 'Valeria',
            'email' => 'valeria@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 16,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '09:00',
            'shiftEnd' => '17:30',
            'mealMinutes' => 30,
            'restDay' => 'Miércoles',
            'reliefBuddyId' => 7
        ]
    ];
 
    foreach ($employeesData as $emp) {
        // Crear usuario
        $userId = DB::table('users')->insertGetId([
            'id' => $emp['id'],
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
            'id' => $emp['id'],
            'tenant_id' => 1,
            'user_id' => $userId,
            'name' => $emp['name'],
            'email' => $emp['email'],
            'job_role_id' => $emp['job_role_id'],
            'phone' => '555' . str_pad($emp['id'], 7, '0', STR_PAD_LEFT),
            'pin_code' => '100' . $emp['id'],
            'portadorLlaves' => $emp['portadorLlaves'],
            'shiftStart' => $emp['shiftStart'] . ':00',
            'shiftEnd' => $emp['shiftEnd'] . ':00',
            'mealMinutes' => $emp['mealMinutes'],
            'restDay' => $emp['restDay'],
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    // Actualizar reliefBuddyId en la tabla users
    foreach ($employeesData as $emp) {
        DB::table('users')->where('id', $emp['id'])->update([
            'reliefBuddyId' => $emp['reliefBuddyId']
        ]);
    }
 
    // 4. Sembrar Configuración de Apertura (Establecer por defecto)
    if (\Illuminate\Support\Facades\Schema::hasTable('store_daily_opening_statuses')) {
        DB::table('store_daily_opening_statuses')->truncate();
        DB::table('store_daily_opening_statuses')->insert([
            'store_id' => 101,
            'tenant_id' => 1,
            'date' => now()->format('Y-m-d'),
            'scheduled_opening_time' => '08:30:00',
            'pre_opening_window_start' => '08:15:00',
            'report_deadline' => '08:40:00',
            'current_responsible_employee_id' => 1, // Francisco empieza con las llaves
            'opened_by_employee_id' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
 
    // Run additional seeders for Tenant 1
    (new \Database\Seeders\VacancySeeder())->run();
    (new \Database\Seeders\AcademyCoursesSeeder())->run();
    (new \Database\Seeders\DecorarteTasksSeeder())->run();
    (new \Database\Seeders\SupervisorRoutinesSeeder())->run();
    (new \Database\Seeders\RoleClockPolicySeeder())->run();

    DB::commit();
    echo "DecorArte seeded successfully!\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
