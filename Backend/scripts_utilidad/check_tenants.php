<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenants = DB::table('tenants')->get();
if ($tenants->isEmpty()) {
    echo "NO TENANTS FOUND!" . PHP_EOL;
} else {
    foreach ($tenants as $t) {
        echo "ID: {$t->id} | Name: {$t->name} | Plan: {$t->plan} | Color: {$t->brand_color}" . PHP_EOL;
    }
}
