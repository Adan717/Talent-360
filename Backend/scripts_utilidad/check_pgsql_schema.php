<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "TABLES IN PGSQL:\n";
    $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema='public'");
    foreach ($tables as $table) {
        echo " - {$table->table_name}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
