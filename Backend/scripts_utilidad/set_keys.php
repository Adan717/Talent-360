<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    // 1. Update Super Admin (User ID 20, Employee ID 5)
    DB::table('employees')
        ->where('tenant_id', 1)
        ->where('name', 'Super Admin')
        ->update(['portadorLlaves' => 'titular']);
    
    // 2. Update Master Admin (Employee ID 16)
    DB::table('employees')
        ->where('tenant_id', 1)
        ->where('name', 'Master Admin')
        ->update(['portadorLlaves' => 'titular']);

    // 3. Update Francisco (User ID 11, Employee ID 14)
    DB::table('employees')
        ->where('tenant_id', 1)
        ->where('name', 'Francisco')
        ->update(['portadorLlaves' => 'suplente']);

    echo "UPDATED KEYS IN DATABASE SUCCESSFULLY!\n";

    // Verify
    $employees = DB::table('employees')
        ->where('tenant_id', 1)
        ->whereIn('name', ['Super Admin', 'Master Admin', 'Francisco'])
        ->get();
    foreach ($employees as $e) {
        echo "Name: {$e->name} | Keys: {$e->portadorLlaves} | RestDay: {$e->restDay} | Shift: {$e->shiftStart} - {$e->shiftEnd}\n";
    }
} catch (\Exception $ex) {
    echo "ERROR: " . $ex->getMessage() . "\n";
}
