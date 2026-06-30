<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$users = DB::table('users')->where('role', 'admin')->get();
echo "ADMIN USERS IN DATABASE:\n";
foreach ($users as $u) {
    echo "ID: {$u->id} | Name: {$u->name} | Email: {$u->email} | Tenant ID: {$u->tenant_id}\n";
}

$tenants = DB::table('tenants')->get();
echo "\nTENANTS IN DATABASE:\n";
foreach ($tenants as $t) {
    echo "ID: {$t->id} | Name: {$t->name} | Subdomain: {$t->subdomain} | Plan: {$t->plan}\n";
}

$companies = DB::table('companies')->get();
echo "\nCOMPANIES IN DATABASE:\n";
foreach ($companies as $c) {
    echo "ID: {$c->id} | Name: {$c->welcome_title} | Message: {$c->welcome_message}\n";
}

$settings = DB::table('system_settings')->get();
echo "\nSYSTEM SETTINGS IN DATABASE:\n";
foreach ($settings as $s) {
    echo "ID: {$s->id} | Tenant ID: {$s->tenant_id} | Key: {$s->key} | Value: {$s->value}\n";
}
