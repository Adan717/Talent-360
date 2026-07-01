<?php
 
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
 
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
 
use Illuminate\Support\Facades\DB;
 
try {
    DB::table('billing_plans')->truncate();
 
    DB::table('billing_plans')->insert([
        [
            'name' => 'Plan Freemium (Gratuito)',
            'code' => 'freemium',
            'price' => 0.00,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'stripe_price_id' => null,
            'features_json' => json_encode([
                'modules' => ['reloj', 'rrhh', 'operativo'],
                'features' => ['checklists_validation']
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ],
        [
            'name' => 'Plan Profesional (Pro)',
            'code' => 'pro',
            'price' => 299.00,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'stripe_price_id' => 'price_pro_test_123',
            'features_json' => json_encode([
                'modules' => ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'portal', 'documentos'],
                'features' => ['keys_control', 'meal_timers', 'checklists_validation', 'voice_commands']
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ],
        [
            'name' => 'Plan Empresarial (Enterprise)',
            'code' => 'enterprise',
            'price' => 999.00,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'stripe_price_id' => 'price_enterprise_test_123',
            'features_json' => json_encode([
                'modules' => ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'portal', 'documentos'],
                'features' => ['keys_control', 'meal_timers', 'checklists_validation', 'voice_commands']
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]
    ]);
 
    echo "¡Planes comerciales insertados con éxito en la base de datos pgsql!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
