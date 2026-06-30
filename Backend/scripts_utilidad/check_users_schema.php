<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $columns = DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'users'");
    echo "USERS COLUMNS:" . PHP_EOL;
    foreach ($columns as $c) {
        echo "Column: {$c->column_name} | Type: {$c->data_type}" . PHP_EOL;
    }
} catch (\Exception $ex) {
    echo "ERROR: " . $ex->getMessage() . PHP_EOL;
}
