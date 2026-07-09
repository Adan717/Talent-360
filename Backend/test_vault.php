<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$tenant = \App\Models\Tenant::withoutGlobalScopes()->where('public_slug', 'decorarte360')->first();
$vault = \App\Models\ObsidianVault::withoutGlobalScopes()
    ->where('tenant_id', $tenant->id)
    ->get()
    ->sortByDesc(function ($v) {
        return \App\Models\ObsidianDocument::withoutGlobalScopes()->where('vault_id', $v->id)->count();
    })
    ->first();

echo "Selected Vault ID: {$vault->id}\n";
$user = \App\Models\ObsidianUser::withoutGlobalScopes()->where('email', 'marisoldecorarte@gmail.com')->first();
echo "User Role: {$user->role}\n";

$docsQuery = \App\Models\ObsidianDocument::withoutGlobalScopes()
    ->where('tenant_id', $tenant->id)
    ->where('vault_id', $vault->id);

$allDocs = $docsQuery->get();
echo "Total Docs in Vault: " . $allDocs->count() . "\n";
