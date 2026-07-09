<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$request = \Illuminate\Http\Request::create('/api/v1/public/org-vault/decorarte360', 'GET');

// Simulate authentication for marisoldecorarte@gmail.com
$user = \App\Models\ObsidianUser::withoutGlobalScopes()->where('email', 'marisoldecorarte@gmail.com')->first();
$token = $user->createToken('vault-user-token')->plainTextToken;
$request->headers->set('Authorization', 'Bearer ' . $token);

$controller = new \App\Http\Controllers\ObsidianController();
$response = $controller->getPublicDocument($request, 'decorarte360');

echo "HTTP Status Code: " . $response->getStatusCode() . "\n";
$data = json_decode($response->getContent(), true);

echo "Tenant Name: " . ($data['tenant']['name'] ?? 'N/A') . "\n";
echo "Vault Name: " . ($data['vault_name'] ?? 'N/A') . "\n";
echo "Index Categories Count: " . (is_array($data['index']) ? count($data['index']) : 'Not an array') . "\n";
if (is_array($data['index'])) {
    foreach ($data['index'] as $cat => $docs) {
        echo "Category: '$cat' | Documents count: " . count($docs) . "\n";
        if (count($docs) > 0) {
            echo "   First document: Title: '{$docs[0]['title']}', Slug: '{$docs[0]['slug']}', Type: '{$docs[0]['type']}'\n";
        }
    }
}
