<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== SEEDING USERS ===\n";
// Seeding Marisol
$user1 = \App\Models\ObsidianUser::withoutGlobalScopes()->updateOrCreate(
    ['email' => 'marisoldecorarte@gmail.com'],
    [
        'tenant_id' => 1,
        'name' => 'Marisol',
        'password' => \Hash::make('marisol360'),
        'job_role_id' => 11,
        'role' => 'admin'
    ]
);
echo "User marisoldecorarte@gmail.com seeded.\n";

// Seeding Francisco
$user2 = \App\Models\ObsidianUser::withoutGlobalScopes()->updateOrCreate(
    ['email' => 'francisco@talent360.com'],
    [
        'tenant_id' => 1,
        'name' => 'Francisco Vega',
        'password' => \Hash::make('password123'),
        'job_role_id' => 11,
        'role' => 'admin'
    ]
);
echo "User francisco@talent360.com seeded.\n";
