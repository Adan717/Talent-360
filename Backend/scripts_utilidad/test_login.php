<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Helpers\SecurityLogger;

try {
    $email = 'liz@decorarte360.com';
    $password = 'password123';

    echo "Attempting to find user with email: $email\n";
    $user = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
        ->where('email', strtolower(trim($email)))
        ->with('tenant')
        ->first();

    if (!$user) {
        echo "User not found!\n";
        exit;
    }

    echo "User found: " . $user->name . " (ID: " . $user->id . ", Role: " . $user->role . ")\n";
    echo "Tenant: " . ($user->tenant ? $user->tenant->name : 'None') . "\n";

    echo "Checking password...\n";
    $passwordMatch = Hash::check($password, $user->password);
    echo "Password match: " . ($passwordMatch ? 'YES' : 'NO') . "\n";

    if (!$passwordMatch) {
        echo "Password does not match!\n";
        exit;
    }

    echo "Checking if active...\n";
    if (!$user->is_active) {
        echo "User is inactive!\n";
        exit;
    }

    echo "Checking if tenant active...\n";
    $isPlatformUser = false;
    if (!$isPlatformUser && $user->role !== \App\Enums\UserRole::PLATFORM_ADMIN->value) {
        $tenant = $user->tenant;
        if ($tenant && !$tenant->is_active) {
            echo "Tenant is suspended!\n";
            exit;
        }
    }

    echo "Creating token...\n";
    $token = $user->createToken('auth_token')->plainTextToken;
    echo "Token created successfully: $token\n";

    echo "Checking 2FA...\n";
    $requires2fa = !$isPlatformUser && $user->two_factor_enabled;
    echo "2FA status check passed: " . ($requires2fa ? 'YES' : 'NO') . "\n";

    echo "Logging success using SecurityLogger...\n";
    SecurityLogger::log('auth_success', "Inicio de sesión exitoso de: {$user->email}", $isPlatformUser ? null : $user->tenant_id, $user->id);
    echo "SecurityLogger log passed.\n";

} catch (\Exception $e) {
    echo "EXCEPTION CAUGHT: " . $e->getMessage() . "\n";
    echo "STACK TRACE:\n" . $e->getTraceAsString() . "\n";
}
