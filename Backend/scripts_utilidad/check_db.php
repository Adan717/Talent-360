<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Table store_daily_opening_statuses exists? ";
var_dump(Schema::hasTable('store_daily_opening_statuses'));

echo "All tables in database:\n";
$tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema='public'");
foreach ($tables as $t) {
    echo "- " . $t->table_name . "\n";
}
