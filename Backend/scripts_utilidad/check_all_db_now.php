<?php
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "--- TENANTS ---\n";
$tenants = DB::table('tenants')->get();
foreach ($tenants as $tenant) {
    echo "ID: {$tenant->id} | Name: {$tenant->name} | Subdomain: {$tenant->subdomain} | Plan: {$tenant->plan}\n";
}

echo "\n--- COMPANIES ---\n";
$companies = DB::table('companies')->get();
foreach ($companies as $company) {
    echo "ID: {$company->id} | Name: {$company->name} | Domain: {$company->domain}\n";
}

echo "\n--- USERS ---\n";
$users = DB::table('users')->get();
foreach ($users as $user) {
    echo "ID: {$user->id} | Name: {$user->name} | Email: {$user->email} | Role: {$user->role} | Tenant ID: {$user->tenant_id}\n";
}
