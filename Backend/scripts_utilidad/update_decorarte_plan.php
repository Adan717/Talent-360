<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;

try {
    // Forzar la conexión a postgres en el puerto 5432 para probar
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.port' => 5432
    ]);
    
    $tenant = Tenant::find(1);
    if ($tenant) {
        $tenant->plan = 'enterprise';
        $tenant->save();
        echo "Tenant DecorArte (ID 1) plan updated to 'enterprise' successfully.\n";
    } else {
        echo "Tenant DecorArte (ID 1) not found.\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
