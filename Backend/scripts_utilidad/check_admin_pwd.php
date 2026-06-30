<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$user = User::withoutGlobalScopes()->where('email', 'admin@pcmaster67.com')->first();
if ($user) {
    echo "USER FOUND:\n";
    echo "ID: {$user->id}\n";
    echo "Email: {$user->email}\n";
    echo "Password Hash: {$user->password}\n";
    
    $pwdList = ['123456789', 'password123', 'admin', 'admin123', '123456'];
    foreach ($pwdList as $pwd) {
        $check = Hash::check($pwd, $user->password);
        echo "Check '$pwd': " . ($check ? 'YES' : 'NO') . "\n";
    }
} else {
    echo "USER NOT FOUND!\n";
}
