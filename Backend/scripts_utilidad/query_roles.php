<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $roles = DB::table('job_roles')->where('tenant_id', 1)->get();
    echo "DECORARTE 360 JOB ROLES:\n";
    foreach ($roles as $r) {
        echo "ID: {$r->id} | Name: {$r->name} | Open: " . ($r->esAperturador ? 'YES' : 'NO') . " | Priority: {$r->jerarquiaLlaves}\n";
    }
} catch (\Exception $ex) {
    echo "ERROR: " . $ex->getMessage() . "\n";
}
