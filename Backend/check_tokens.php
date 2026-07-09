<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== PERSONAL ACCESS TOKENS ===\n";
$tokens = \Laravel\Sanctum\PersonalAccessToken::all();
foreach ($tokens as $t) {
    echo "ID: {$t->id} | Tokenable Type: {$t->tokenable_type} | Tokenable ID: {$t->tokenable_id} | Name: {$t->name} | Last Used: {$t->last_used_at}\n";
}
