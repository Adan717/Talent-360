<?php
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = ['tenants', 'users', 'employees', 'job_roles', 'companies', 'billing_plans'];
foreach ($tables as $t) {
    try {
        $count = DB::table($t)->count();
        echo "Table '$t' count: $count\n";
    } catch (\Exception $e) {
        echo "Table '$t' error: " . $e->getMessage() . "\n";
    }
}
