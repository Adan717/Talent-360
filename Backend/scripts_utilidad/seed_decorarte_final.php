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
        'paloma@decorarte360.com',
        'adan@decorarte360.com',
        'joseline@decorarte360.com',
        'hiraym@decorarte360.com',
        'agnela@decorarte360.com',
        'adriana@decorarte360.com',
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
        ['id' => 1, 'name' => 'Administrador / Gerente', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración', 'portadorLlaves' => 'ambos', 'tiempoTolerancia' => 15, 'requiereJustificante' => true],
        ['id' => 2, 'name' => 'Sup. Tienda y Compras', 'esAperturador' => true, 'jerarquiaLlaves' => 2, 'area' => 'Piso', 'portadorLlaves' => 'apertura', 'tiempoTolerancia' => 15, 'requiereJustificante' => true],
        ['id' => 3, 'name' => 'Sup. Cajas', 'esAperturador' => true, 'jerarquiaLlaves' => 0, 'area' => 'Cajas', 'portadorLlaves' => 'ninguno', 'tiempoTolerancia' => 15, 'requiereJustificante' => true],
        ['id' => 4, 'name' => 'Sup. Producción', 'esAperturador' => false, 'jerarquiaLlaves' => 0, 'area' => 'Producción', 'portadorLlaves' => 'ninguno', 'tiempoTolerancia' => 10, 'requiereJustificante' => true],
        ['id' => 5, 'name' => 'Cajeros', 'esAperturador' => false, 'jerarquiaLlaves' => 0, 'area' => 'Cajas', 'portadorLlaves' => 'ninguno', 'tiempoTolerancia' => 10, 'requiereJustificante' => false],
        ['id' => 6, 'name' => 'Ayudante Integral', 'esAperturador' => false, 'jerarquiaLlaves' => 3, 'area' => 'Piso', 'portadorLlaves' => 'cierre', 'tiempoTolerancia' => 10, 'requiereJustificante' => false]
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
            'job_role_id' => 1,
            'portadorLlaves' => 'ambos',
            'shiftStart' => '08:20',
            'shiftEnd' => '18:00',
            'mealMinutes' => 60,
            'restDay' => 'Domingo',
            'reliefBuddyId' => 9
        ],
        [
            'id' => 9,
            'name' => 'Paloma',
            'email' => 'paloma@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 2,
            'portadorLlaves' => 'apertura',
            'shiftStart' => '09:00',
            'shiftEnd' => '17:00',
            'mealMinutes' => 60,
            'restDay' => 'Domingo',
            'reliefBuddyId' => 8
        ],
        [
            'id' => 8,
            'name' => 'Adán',
            'email' => 'adan@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 6,
            'portadorLlaves' => 'cierre',
            'shiftStart' => '09:00',
            'shiftEnd' => '17:30',
            'mealMinutes' => 30,
            'restDay' => 'Miércoles',
            'reliefBuddyId' => 1
        ],
        [
            'id' => 3,
            'name' => 'Joseline',
            'email' => 'joseline@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 3,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '08:20',
            'shiftEnd' => '18:00',
            'mealMinutes' => 60,
            'restDay' => 'Martes',
            'reliefBuddyId' => 9
        ],
        [
            'id' => 4,
            'name' => 'Hiraym',
            'email' => 'hiraym@decorarte360.com',
            'role' => 'supervisor',
            'job_role_id' => 4,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '09:00',
            'shiftEnd' => '18:00',
            'mealMinutes' => 60,
            'restDay' => 'Miércoles',
            'reliefBuddyId' => 8
        ],
        [
            'id' => 5,
            'name' => 'Agnela',
            'email' => 'agnela@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 5,
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
            'job_role_id' => 5,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '08:30',
            'shiftEnd' => '17:00',
            'mealMinutes' => 30,
            'restDay' => 'Lunes',
            'reliefBuddyId' => 5
        ],
        [
            'id' => 7,
            'name' => 'Cristian',
            'email' => 'cristian@decorarte360.com',
            'role' => 'empleado',
            'job_role_id' => 6,
            'portadorLlaves' => 'ninguno',
            'shiftStart' => '08:30',
            'shiftEnd' => '17:00',
            'mealMinutes' => 30,
            'restDay' => 'Martes',
            'reliefBuddyId' => 8
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
    DB::table('store_opening_status')->truncate();
    DB::table('store_opening_status')->insert([
        'store_id' => 101,
        'tenant_id' => 1,
        'date' => now()->format('Y-m-d'),
        'current_responsible_employee_id' => 1, // Francisco empieza con las llaves
        'opened_by_employee_id' => null,
        'status' => 'closed',
        'created_at' => now(),
        'updated_at' => now()
    ]);
 
    DB::commit();
    echo "DecorArte seeded successfully!\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
