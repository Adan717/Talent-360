<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== TENANTS ===\n";
$tenants = \App\Models\Tenant::withoutGlobalScopes()->get();
foreach ($tenants as $t) {
    echo "ID: {$t->id} | Name: {$t->name} | Subdomain: {$t->subdomain} | Public Slug: {$t->public_slug}\n";
}

echo "\n=== VAULTS ===\n";
$vaults = \App\Models\ObsidianVault::withoutGlobalScopes()->get();
foreach ($vaults as $v) {
    $docCount = \App\Models\ObsidianDocument::withoutGlobalScopes()->where('vault_id', $v->id)->count();
    echo "ID: {$v->id} | Tenant ID: {$v->tenant_id} | Name: {$v->name} | Documents: {$docCount}\n";
}

echo "\n=== OBSIDIAN USERS ===\n";
$users = \App\Models\ObsidianUser::withoutGlobalScopes()->get();
foreach ($users as $u) {
    echo "ID: {$u->id} | Tenant ID: {$u->tenant_id} | Name: {$u->name} | Email: {$u->email} | Role: {$u->role}\n";
}
