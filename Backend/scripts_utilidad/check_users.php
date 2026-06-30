<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $users = DB::table('users')->get();
    echo "USERS IN DB: " . $users->count() . "\n";
    foreach ($users as $u) {
        echo "ID: {$u->id} | Name: {$u->name} | Email: {$u->email} | Role: {$u->role} | Tenant ID: {$u->tenant_id}\n";
    }

    $employees = DB::table('employees')->get();
    echo "\nEMPLOYEES IN DB: " . $employees->count() . "\n";
    foreach ($employees as $e) {
        echo "ID: {$e->id} | Name: {$e->name} | Email: {$e->email} | User ID: {$e->user_id} | Phone: {$e->phone} | Tenant ID: {$e->tenant_id} | ActiveEmp: " . ($e->is_active_employee ? 'YES' : 'NO') . "\n";
    }

    $platformUsers = DB::table('platform_users')->get();
    echo "\nPLATFORM USERS:\n";
    foreach ($platformUsers as $pu) {
        echo "ID: {$pu->id} | Name: {$pu->name} | Email: {$pu->email} | Role: {$pu->role}\n";
    }

    $tenants = DB::table('tenants')->get();
    echo "\nTENANTS:\n";
    foreach ($tenants as $t) {
        echo "ID: {$t->id} | Name: {$t->name} | Subdomain: {$t->subdomain}\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
