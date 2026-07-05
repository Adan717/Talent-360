<?php
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DOCKER DB ENVIRONMENT ===\n";
echo "Active DB Connection: " . DB::getDefaultConnection() . "\n";
echo "Database Name: " . DB::connection()->getDatabaseName() . "\n";

echo "\n=== ALL USERS COUNT ===\n";
echo \App\Models\User::count() . " users in database.\n";

echo "\n=== PALOMA IN USERS ===\n";
$users = \App\Models\User::where('name', 'like', '%Paloma%')->get();
foreach ($users as $u) {
    echo "ID: {$u->id} | Name: {$u->name} | Job Role ID: {$u->job_role_id} | Tenant ID: {$u->tenant_id} | Email: {$u->email}\n";
    if ($u->jobRole) {
        echo "  Job Role Name: {$u->jobRole->name}\n";
    } else {
        echo "  No Job Role assigned!\n";
    }
}

echo "\n=== PALOMA IN EMPLOYEES ===\n";
$employees = \App\Models\Employee::where('name', 'like', '%Paloma%')->get();
foreach ($employees as $e) {
    echo "ID: {$e->id} | Name: {$e->name} | Job Role ID: {$e->job_role_id} | User ID: {$e->user_id}\n";
    if ($e->jobRole) {
        echo "  Job Role Name: {$e->jobRole->name}\n";
    } else {
        echo "  No Job Role assigned!\n";
    }
}

echo "\n=== ACADEMY COURSES ===\n";
$courses = \App\Models\AcademyCourse::all();
foreach ($courses as $c) {
    echo "ID: {$c->id} | Title: {$c->title} | Target Role ID: {$c->target_job_role_id} | Tenant ID: {$c->tenant_id} | Active: " . ($c->is_active ? 'Yes' : 'No') . "\n";
    if ($c->targetJobRole) {
        echo "  Target Job Role Name: {$c->targetJobRole->name}\n";
    } else {
        echo "  No target job role (All roles can see it?)\n";
    }
}
