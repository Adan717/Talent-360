<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== SEEDING ACCOUNT ROLES ===\n";

// Seed/Update Francisco (Admin Owner)
$user1 = \App\Models\ObsidianUser::withoutGlobalScopes()->updateOrCreate(
    ['email' => 'francisco@decorarte360.com'],
    [
        'tenant_id' => 1,
        'name' => 'Francisco',
        'password' => \Hash::make('password123'),
        'job_role_id' => 11,
        'role' => 'admin'
    ]
);
echo "User francisco@decorarte360.com seeded as Admin.\n";

// Seed/Update Marisol (Supervisor)
$user2 = \App\Models\ObsidianUser::withoutGlobalScopes()->updateOrCreate(
    ['email' => 'marisoldecorarte@gmail.com'],
    [
        'tenant_id' => 1,
        'name' => 'Marisol',
        'password' => \Hash::make('marisol360'),
        'job_role_id' => 11,
        'role' => 'supervisor'
    ]
);
echo "User marisoldecorarte@gmail.com seeded as Supervisor.\n";

// Remove old francisco@talent360.com if exists to keep database clean
\App\Models\ObsidianUser::withoutGlobalScopes()->where('email', 'francisco@talent360.com')->delete();
echo "Removed legacy francisco@talent360.com user.\n";
