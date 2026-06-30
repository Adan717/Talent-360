<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use App\Models\User;
use App\Models\JobRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Enums\UserRole;

try {
    Model::unguard();
    DB::beginTransaction();

    $tenant = Tenant::where('subdomain', 'decorarte')->first();
    if (!$tenant) {
        $tenant = Tenant::firstOrCreate(
            ['subdomain' => 'talent360'],
            [
                'name' => 'Talent 360',
                'plan' => 'enterprise',
                'max_users' => 9999
            ]
        );
    }

    // Ocupamos una compañía porque las migraciones antiguas lo piden
    $companyId = DB::table('companies')->where('domain', 'decorarte')->value('id');
    if (!$companyId) {
        $companyId = DB::table('companies')->where('domain', 'talent360')->value('id');
        if (!$companyId) {
            $companyId = DB::table('companies')->insertGetId([
                'name' => 'Talent 360',
                'domain' => 'talent360',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // 1. Company Admin (associated with seeded job_role_id = 1: Administrador / Gerente)
    User::updateOrCreate(
        ['email' => 'admin@empresa.com'],
        [
            'name' => 'Super Admin',
            'password' => Hash::make('password123'),
            'role' => UserRole::ADMIN->value,
            'tenant_id' => $tenant->id,
            'job_role_id' => 1,
        ]
    );

    // 2. Master Admin (separate table)
    \App\Models\PlatformUser::updateOrCreate(
        ['email' => 'master@talent360.com'],
        [
            'name' => 'Master Admin',
            'password' => Hash::make('123456'),
            'role' => 'platform_admin',
            'is_active' => true,
        ]
    );
    // Delete from users table to completely isolate
    User::where('email', 'master@talent360.com')->delete();

    // 3. Employee Admin/Gerente (francisco@talent360.com)
    User::updateOrCreate(
        ['email' => 'francisco@talent360.com'],
        [
            'name' => 'Francisco',
            'password' => Hash::make('password123'),
            'role' => 'empleado',
            'tenant_id' => $tenant->id,
            'job_role_id' => 1, // Admin / Gerente
            'has_completed_induction' => true,
        ]
    );

    DB::commit();
    echo "SUCCESS\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
