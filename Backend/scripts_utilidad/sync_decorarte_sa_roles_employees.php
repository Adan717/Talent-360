<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$tenantId = 33; // DecorArte S.A. de C.V.

DB::transaction(function () use ($tenantId) {
    // 0. Garantizar la existencia del Tenant #33
    DB::table('tenants')->updateOrInsert(
        ['id' => $tenantId],
        [
            'name' => 'DecorArte S.A. de C.V.',
            'subdomain' => 'decorarte-sadcv',
            'public_slug' => 'decorarte-sadcv',
            'plan' => 'enterprise',
            'brand_color' => '#8b5cf6',
            'logo_url' => 'https://decorarte360.com/logo.png',
            'subscription_status' => 'active',
            'trial_ends_at' => now()->addDays(30),
            'updated_at' => now(),
        ]
    );

    echo "1. Limpiando puestos y colaboradores anteriores de Tenant #{$tenantId}...\n";
    
    // Hard delete or soft delete existing records for tenant 33
    DB::table('employees')->where('tenant_id', $tenantId)->delete();
    DB::table('job_roles')->where('tenant_id', $tenantId)->delete();
    DB::table('users')->where('tenant_id', $tenantId)->where('role', '!=', 'platform_admin')->where('role', '!=', 'admin')->delete();

    echo "2. Creando los 7 Puestos de Trabajo en DecorArte S.A. de C.V. ...\n";

    // 1. Administrador Gerente
    $adminRoleId = DB::table('job_roles')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Administrador Gerente',
        'area' => 'Gerencia',
        'description' => 'Responsable general de administración y dirección operativa.',
        'esAperturador' => true,
        'portadorLlaves' => 'ambos',
        'jerarquiaLlaves' => 1,
        'tiempoTolerancia' => 10,
        'requiereJustificante' => true,
        'puedeEmitirAvisos' => true,
        'aplicaLeySilla' => false,
        'evaluacion360Activa' => true,
        'is_active' => true,
        'nivel_mando' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Supervisor de Compras
    $comprasRoleId = DB::table('job_roles')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Supervisor de Compras',
        'area' => 'Compras',
        'description' => 'Gestión de insumos, proveedores e inventarios.',
        'reports_to_role_id' => $adminRoleId,
        'reports_to_role_ids' => json_encode([$adminRoleId]),
        'org_parent_role_id' => $adminRoleId,
        'esAperturador' => false,
        'portadorLlaves' => 'ninguno',
        'tiempoTolerancia' => 10,
        'requiereJustificante' => true,
        'puedeEmitirAvisos' => true,
        'aplicaLeySilla' => false,
        'evaluacion360Activa' => true,
        'is_active' => true,
        'nivel_mando' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Supervisor de Ventas
    $ventasRoleId = DB::table('job_roles')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Supervisor de Ventas',
        'area' => 'Ventas',
        'description' => 'Estrategia comercial y atención al cliente.',
        'reports_to_role_id' => $adminRoleId,
        'reports_to_role_ids' => json_encode([$adminRoleId]),
        'org_parent_role_id' => $adminRoleId,
        'esAperturador' => true,
        'portadorLlaves' => 'ambos',
        'tiempoTolerancia' => 10,
        'requiereJustificante' => true,
        'puedeEmitirAvisos' => true,
        'aplicaLeySilla' => false,
        'evaluacion360Activa' => true,
        'is_active' => true,
        'nivel_mando' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 4. Supervisor de Producción
    $produccionRoleId = DB::table('job_roles')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Supervisor de Producción',
        'area' => 'Producción',
        'description' => 'Supervisión de talleres y control de calidad.',
        'reports_to_role_id' => $adminRoleId,
        'reports_to_role_ids' => json_encode([$adminRoleId]),
        'org_parent_role_id' => $adminRoleId,
        'esAperturador' => false,
        'portadorLlaves' => 'ninguno',
        'tiempoTolerancia' => 10,
        'requiereJustificante' => true,
        'puedeEmitirAvisos' => true,
        'aplicaLeySilla' => false,
        'evaluacion360Activa' => true,
        'is_active' => true,
        'nivel_mando' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 5. Atención al Cliente
    $atencionRoleId = DB::table('job_roles')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Atención al Cliente',
        'area' => 'Ventas',
        'description' => 'Servicio al cliente en mostrador y piso de ventas.',
        'reports_to_role_id' => $ventasRoleId,
        'reports_to_role_ids' => json_encode([$ventasRoleId]),
        'org_parent_role_id' => $ventasRoleId,
        'esAperturador' => false,
        'portadorLlaves' => 'ninguno',
        'tiempoTolerancia' => 10,
        'requiereJustificante' => true,
        'puedeEmitirAvisos' => false,
        'aplicaLeySilla' => true,
        'evaluacion360Activa' => false,
        'is_active' => true,
        'nivel_mando' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 6. Ayudante Integral
    $ayudanteRoleId = DB::table('job_roles')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Ayudante Integral',
        'area' => 'Operativo',
        'description' => 'Apoyo general en producción, ensamble y almacén.',
        'reports_to_role_id' => $produccionRoleId,
        'reports_to_role_ids' => json_encode([$produccionRoleId]),
        'org_parent_role_id' => $produccionRoleId,
        'esAperturador' => false,
        'portadorLlaves' => 'ninguno',
        'tiempoTolerancia' => 10,
        'requiereJustificante' => true,
        'puedeEmitirAvisos' => false,
        'aplicaLeySilla' => true,
        'evaluacion360Activa' => false,
        'is_active' => true,
        'nivel_mando' => 4,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 7. Apoyo Eventual
    $apoyoRoleId = DB::table('job_roles')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Apoyo Eventual',
        'area' => 'Operativo',
        'description' => 'Personal de soporte para temporadas de alta demanda.',
        'reports_to_role_id' => $produccionRoleId,
        'reports_to_role_ids' => json_encode([$produccionRoleId]),
        'org_parent_role_id' => $produccionRoleId,
        'esAperturador' => false,
        'portadorLlaves' => 'ninguno',
        'tiempoTolerancia' => 10,
        'requiereJustificante' => true,
        'puedeEmitirAvisos' => false,
        'aplicaLeySilla' => true,
        'evaluacion360Activa' => false,
        'is_active' => true,
        'nivel_mando' => 4,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo "Puestos creados con éxito.\n";

    echo "3. Insertando los 12 Colaboradores en DecorArte S.A. de C.V. ...\n";

    $employeesData = [
        ['name' => 'Francisco', 'role_id' => $adminRoleId, 'email' => 'francisco@decorarte.com', 'phone' => '5550000001', 'pin' => '1001', 'shiftStart' => '08:20:00', 'shiftEnd' => '17:30:00', 'mealMinutes' => 60, 'restDay' => 'Domingo', 'portadorLlaves' => 'Titular'],
        ['name' => 'Monica', 'role_id' => $comprasRoleId, 'email' => 'monica@decorarte.com', 'phone' => '5550000002', 'pin' => '1002', 'shiftStart' => '08:20:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 60, 'restDay' => 'Lunes', 'portadorLlaves' => 'Ninguno'],
        ['name' => 'Joseline', 'role_id' => $ventasRoleId, 'email' => 'joseline@decorarte.com', 'phone' => '5550000003', 'pin' => '1003', 'shiftStart' => '08:20:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 60, 'restDay' => 'Martes', 'portadorLlaves' => 'Suplente'],
        ['name' => 'Hiraym', 'role_id' => $produccionRoleId, 'email' => 'hiraym@decorarte.com', 'phone' => '5550000004', 'pin' => '1004', 'shiftStart' => '09:00:00', 'shiftEnd' => '18:00:00', 'mealMinutes' => 60, 'restDay' => 'Miércoles', 'portadorLlaves' => 'ninguno'],
        ['name' => 'Manuel Figueroa', 'role_id' => $produccionRoleId, 'email' => 'manuelfigueroa@decorarte.com', 'phone' => '5550000005', 'pin' => '1005', 'shiftStart' => '09:00:00', 'shiftEnd' => '18:00:00', 'mealMinutes' => 60, 'restDay' => 'Miércoles', 'portadorLlaves' => 'ninguno'],
        ['name' => 'Agnela', 'role_id' => $atencionRoleId, 'email' => 'agnela@decorarte.com', 'phone' => '5550000006', 'pin' => '1006', 'shiftStart' => '08:30:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 30, 'restDay' => 'Domingo', 'portadorLlaves' => 'ninguno'],
        ['name' => 'Adriana', 'role_id' => $atencionRoleId, 'email' => 'adriana@decorarte.com', 'phone' => '5550000007', 'pin' => '1007', 'shiftStart' => '08:30:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 30, 'restDay' => 'Lunes', 'portadorLlaves' => 'ninguno'],
        ['name' => 'Cristian', 'role_id' => $ayudanteRoleId, 'email' => 'cristian@decorarte.com', 'phone' => '5550000008', 'pin' => '1008', 'shiftStart' => '08:30:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 30, 'restDay' => 'Martes', 'portadorLlaves' => 'ninguno'],
        ['name' => 'Adan', 'role_id' => $ayudanteRoleId, 'email' => 'adan@decorarte.com', 'phone' => '5550000009', 'pin' => '1009', 'shiftStart' => '08:30:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 30, 'restDay' => 'Miércoles', 'portadorLlaves' => 'ninguno'],
        ['name' => 'Israel', 'role_id' => $ayudanteRoleId, 'email' => 'israel@decorarte.com', 'phone' => '5550000010', 'pin' => '1010', 'shiftStart' => '08:30:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 60, 'restDay' => 'Jueves', 'portadorLlaves' => 'ninguno'],
        ['name' => 'Manuel', 'role_id' => $ayudanteRoleId, 'email' => 'manuel@decorarte.com', 'phone' => '5550000011', 'pin' => '1011', 'shiftStart' => '08:30:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 60, 'restDay' => 'Viernes', 'portadorLlaves' => 'ninguno'],
        ['name' => 'Cristhel', 'role_id' => $ayudanteRoleId, 'email' => 'cristhel@decorarte.com', 'phone' => '5550000012', 'pin' => '1012', 'shiftStart' => '08:30:00', 'shiftEnd' => '17:00:00', 'mealMinutes' => 60, 'restDay' => 'Sábado', 'portadorLlaves' => 'ninguno'],
    ];

    foreach ($employeesData as $emp) {
        // Eliminar usuario previo con el mismo email si existe para garantizar ID y limpio
        DB::table('users')->where('email', $emp['email'])->delete();

        $userId = DB::table('users')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $emp['name'],
            'email' => $emp['email'],
            'password' => Hash::make('password123'),
            'role' => ($emp['name'] === 'Francisco') ? 'admin' : 'employee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $emp['name'],
            'email' => $emp['email'],
            'phone' => $emp['phone'],
            'job_role_id' => $emp['role_id'],
            'is_active_employee' => true,
            'shiftStart' => $emp['shiftStart'],
            'shiftEnd' => $emp['shiftEnd'],
            'mealMinutes' => $emp['mealMinutes'],
            'restDay' => $emp['restDay'],
            'pin_code' => $emp['pin'],
            'portadorLlaves' => $emp['portadorLlaves'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        echo "  - Creado colaborador: {$emp['name']} (#{$emp['pin']})\n";
    }

    echo "4. Clonando tareas y rutinas desde DecorArte 360 (Tenant #1) hacia Tenant #{$tenantId}...\n";
    $cloner = app(\App\Services\TenantTaskClonerService::class);
    $res = $cloner->cloneTasksAndRoutines(1, $tenantId);
    echo "  - Tareas clonadas: {$res['tasks_cloned']}, Rutinas clonadas: {$res['routines_cloned']}\n";

    echo "Sincronización completada exitosamente para DecorArte S.A. de C.V.!\n";
});
