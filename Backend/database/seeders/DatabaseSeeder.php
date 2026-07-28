<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // §47: cuentas de plataforma (platform_users). Antes traían contraseñas de
        // diccionario hardcodeadas en el repo ('Master'/'Support123'), lo que daba
        // credenciales de super-admin válidas a cualquiera con acceso de lectura al
        // código. Ahora la contraseña viene de variables de entorno (nunca del repo);
        // si no está definida, en local/testing se genera una aleatoria y se registra
        // en el log para que quien seedea la vea una vez, y en producción NO se crea la
        // cuenta con una contraseña por defecto (se crea a mano vía runbook).
        //
        // La cuenta genérica 'master@talent360.com' se eliminó por completo (§47.2):
        // era una cuenta sin dueño claro y menos cuentas de plataforma = menos superficie.
        if (DB::getSchemaBuilder()->hasTable('platform_users')) {
            $this->seedPlatformUser('pcmasterirapuato@gmail.com', 'Francisco Vega', 'platform_admin', 'SEED_SUPERADMIN_PASSWORD');
            $this->seedPlatformUser('soporte@talent360.mx', 'Agente Soporte', 'support_agent', 'SEED_SUPPORT_PASSWORD');
        }

        // Eliminar pcmasterirapuato@gmail.com de la tabla users para que no colisione con platform_users al iniciar sesión con Google
        if (DB::getSchemaBuilder()->hasTable('users')) {
            DB::table('users')->where('email', 'pcmasterirapuato@gmail.com')->delete();
        }

        // Sincronizar secuencias de PostgreSQL para evitar colisiones de llaves primarias autoincrementales
        if (DB::getDriverName() === 'pgsql') {
            $seqTables = ['job_roles', 'users', 'permissions', 'companies', 'tenants', 'platform_users'];
            foreach ($seqTables as $t) {
                if (DB::getSchemaBuilder()->hasTable($t)) {
                    DB::statement("SELECT setval('{$t}_id_seq', COALESCE((SELECT MAX(id) FROM {$t}), 1))");
                }
            }
        }
    }

    /**
     * Crea (si no existe) una cuenta de plataforma resolviendo la contraseña de forma
     * segura: env var → aleatoria+log en dev → omitir en producción.
     */
    private function seedPlatformUser(string $email, string $name, string $role, string $envKey): void
    {
        if (DB::table('platform_users')->where('email', $email)->exists()) {
            DB::table('platform_users')->where('email', $email)->update([
                'role' => $role,
                'is_active' => true,
                'updated_at' => now(),
            ]);
            return;
        }

        $password = env($envKey);

        if (!$password) {
            if (app()->environment('production')) {
                Log::warning("Seeder: no se creó la cuenta de plataforma {$email} porque {$envKey} no está definida en producción. Créala manualmente vía runbook.");
                return;
            }
            $password = Str::random(24);
            Log::info("Seeder: contraseña generada para {$email} (env {$envKey} no definida): {$password}");
        }

        DB::table('platform_users')->insert([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
