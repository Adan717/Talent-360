<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$email = 'marisoldecorarte@gmail.com';
$password = 'marisol360';

$user = \App\Models\ObsidianUser::withoutGlobalScopes()->where('email', $email)->first();
if (!$user) {
    echo "User not found!\n";
    exit;
}

echo "User found: ID {$user->id}, Name: {$user->name}, Role: {$user->role}\n";

$passCheck = \Hash::check($password, $user->password);
echo "Password check: " . ($passCheck ? "PASSED" : "FAILED") . "\n";

if ($passCheck) {
    $token = $user->createToken('vault-user-token')->plainTextToken;
    echo "Token created successfully: {$token}\n";
}
