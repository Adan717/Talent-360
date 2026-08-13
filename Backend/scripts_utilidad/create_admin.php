<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
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

    // Bloque 1 (2026-08-13): este script sembraba password123/123456/Master FIJAS cada vez
    // que se corría — deshacía la rotación entera. Ahora genera aleatorias, las imprime UNA
    // vez, y marca las cuentas para cambio forzado (quien corre el script las conoce).
    $claves = [];
    $clave = function (string $email) use (&$claves): string {
        $claves[$email] = \Illuminate\Support\Str::random(16);
        return $claves[$email];
    };

    // 1. Company Admin (associated with seeded job_role_id = 1: Administrador / Gerente)
    User::updateOrCreate(
        ['email' => 'admin@empresa.com'],
        [
            'name' => 'Super Admin',
            'password' => Hash::make($clave('admin@empresa.com')),
            'role' => UserRole::ADMIN->value,
            'tenant_id' => $tenant->id,
            'job_role_id' => 1,
            'must_change_password' => true,
        ]
    );

    // 2. Master Admin (separate table)
    \App\Models\PlatformUser::updateOrCreate(
        ['email' => 'master@talent360.com'],
        [
            'name' => 'Master Admin',
            'password' => Hash::make($clave('master@talent360.com')),
            'role' => 'platform_admin',
            'is_active' => true,
            'must_change_password' => true,
        ]
    );
    // Delete from users table to completely isolate
    User::where('email', 'master@talent360.com')->delete();

    // 2b. Francisco Vega (Google Admin)
    \App\Models\PlatformUser::updateOrCreate(
        ['email' => 'pcmasterirapuato@gmail.com'],
        [
            'name' => 'Francisco Vega',
            'password' => Hash::make($clave('pcmasterirapuato@gmail.com')),
            'role' => 'platform_admin',
            'is_active' => true,
            'must_change_password' => true,
        ]
    );
    User::where('email', 'pcmasterirapuato@gmail.com')->delete();

    // 3. Employee Admin/Gerente (francisco@talent360.com)
    User::updateOrCreate(
        ['email' => 'francisco@talent360.com'],
        [
            'name' => 'Francisco',
            'password' => Hash::make($clave('francisco@talent360.com')),
            'role' => 'empleado',
            'tenant_id' => $tenant->id,
            'job_role_id' => 1, // Admin / Gerente
            'has_completed_induction' => true,
            'must_change_password' => true,
        ]
    );

    DB::commit();
    echo "SUCCESS\n";
    echo "Contraseñas generadas (se muestran SOLO esta vez; cada cuenta debe cambiarla al entrar):\n";
    foreach ($claves as $correo => $c) {
        echo "  {$correo}: {$c}\n";
    }
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
