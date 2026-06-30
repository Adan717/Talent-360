<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $settings = DB::table('system_settings')->where('tenant_id', 1)->get();
    echo "DECORARTE 360 SYSTEM SETTINGS:\n";
    foreach ($settings as $s) {
        echo "Key: {$s->key} | Value: {$s->value}\n";
    }
} catch (\Exception $ex) {
    echo "ERROR: " . $ex->getMessage() . "\n";
}
