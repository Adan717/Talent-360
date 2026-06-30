<?php
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$tenant = DB::table('tenants')->where('id', 1)->first();
if ($tenant) {
    echo "Tenant DecorArte 360 exists:\n";
    echo "Name: {$tenant->name} | Plan: {$tenant->plan} | Status: " . ($tenant->is_active ? 'Active' : 'Inactive') . "\n";
} else {
    echo "Tenant DecorArte 360 (ID 1) NOT found!\n";
}

$user = User::withoutGlobalScopes()->where('email', 'admin@decorarte360.com')->first();
if ($user) {
    echo "Admin user found:\n";
    echo "ID: {$user->id}\n";
    echo "Name: {$user->name}\n";
    echo "Email: {$user->email}\n";
    echo "Is active: " . ($user->is_active ? 'Yes' : 'No') . "\n";
    
    // Check common passwords
    $passwords = ['password', 'password123', 'admin', 'admin123', 'Master', 'Master123', 'secret'];
    foreach ($passwords as $p) {
        if (Hash::check($p, $user->password)) {
            echo "Password matches: '$p'\n";
        }
    }
} else {
    echo "Admin user admin@decorarte360.com NOT found!\n";
}
