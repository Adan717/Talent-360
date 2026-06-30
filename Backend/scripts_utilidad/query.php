<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $users = DB::table('users')->where('tenant_id', 1)->get();
    echo "DECORARTE 360 USERS WITH ROLES:\n";
    foreach ($users as $u) {
        $roleName = DB::table('job_roles')->where('id', $u->job_role_id)->value('name') ?: 'Ninguno';
        $restDay = $u->rest_day ?? 'Ninguno';
        $shiftStart = $u->shift_start ?? 'Ninguno';
        echo "ID: {$u->id} | Name: {$u->name} | Email: {$u->email} | JobRoleID: {$u->job_role_id} | RoleName: {$roleName} | restDay: {$restDay} | shiftStart: {$shiftStart}\n";
    }

    echo "\nSTORE OPENING ASSIGNMENTS:\n";
    $assignments = DB::table('store_opening_assignments')->get();
    foreach ($assignments as $a) {
        $empName = DB::table('users')->where('id', $a->employee_id)->value('name') ?: 'Desconocido';
        echo "ID: {$a->id} | EmployeeID: {$a->employee_id} ({$empName}) | Order: {$a->priority_order} | CanOpen: {$a->can_open_store} | HasKeys: {$a->has_keys} | Active: {$a->is_active}\n";
    }
} catch (\Exception $ex) {
    echo "ERROR: " . $ex->getMessage() . "\n";
}