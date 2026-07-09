<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== SEEDING MARISOL ===\n";
$user = \App\Models\ObsidianUser::withoutGlobalScopes()->updateOrCreate(
    ['email' => 'marisoldecorarte@gmail.com'],
    [
        'tenant_id' => 1,
        'name' => 'Marisol',
        'password' => \Hash::make('marisol360'),
        'job_role_id' => 11,
        'role' => 'admin'
    ]
);
echo "User marisoldecorarte@gmail.com created/updated successfully with password: marisol360\n";
// Done
