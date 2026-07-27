<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * §51: cuentas de prueba de DecorArte 360 (tenant de prueba). NO se corre por defecto
 * ni en producción — es dev-only y hay que invocarlo explícitamente:
 *
 *     php artisan db:seed --class=Database\\Seeders\\DevTestAccountsSeeder
 *
 * Las contraseñas vienen de variables de entorno (.env, no versionado) para no meter
 * secretos al repo (regla de §47). Si no están definidas, en dev se generan aleatorias
 * y se registran en el log. El tenant DecorArte se busca por subdominio (env
 * DECORARTE_SUBDOMAIN, default 'default') para no depender de un id numérico fijo.
 *
 * Variables de entorno que consume (opcionales; ver contrato §51 para las sugeridas):
 *   DECORARTE_SUBDOMAIN, DECORARTE_ADMIN_PASSWORD, DECORARTE_SUPERVISOR_PASSWORD,
 *   DECORARTE_EMPLEADO_PASSWORD, DECORARTE_SUPERVISOR_PIN, DECORARTE_EMPLEADO_PIN
 */
class DevTestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            Log::warning('DevTestAccountsSeeder: omitido en producción. Estas cuentas son solo para desarrollo/QA.');
            return;
        }

        $subdomain = env('DECORARTE_SUBDOMAIN', 'default');
        $tenant = DB::table('tenants')->where('subdomain', $subdomain)->first();

        if (!$tenant) {
            Log::warning("DevTestAccountsSeeder: no se encontró el tenant DecorArte por subdominio '{$subdomain}'. "
                . 'Ajusta DECORARTE_SUBDOMAIN en tu .env y vuelve a correr.');
            return;
        }

        $this->upsertUser($tenant->id, 'admin@decorarte360.com', 'Admin DecorArte', 'admin', 'DECORARTE_ADMIN_PASSWORD');
        $this->upsertUser($tenant->id, 'supervisor@decorarte360.com', 'Supervisor DecorArte', 'supervisor', 'DECORARTE_SUPERVISOR_PASSWORD', 'DECORARTE_SUPERVISOR_PIN', '1234');
        $this->upsertUser($tenant->id, 'empleado@decorarte360.com', 'Empleado DecorArte', 'empleado', 'DECORARTE_EMPLEADO_PASSWORD', 'DECORARTE_EMPLEADO_PIN', '5678');
    }

    private function upsertUser(int $tenantId, string $email, string $name, string $role, string $passwordEnvKey, ?string $pinEnvKey = null, ?string $pinDefault = null): void
    {
        $password = env($passwordEnvKey);
        if (!$password) {
            $password = Str::random(20);
            Log::info("DevTestAccountsSeeder: contraseña generada para {$email} ({$passwordEnvKey} no definida): {$password}");
        }

        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update([
                'password' => Hash::make($password),
                'role' => $role,
                'tenant_id' => $tenantId,
                'updated_at' => now(),
            ]);
            $userId = $existing->id;
        } else {
            $userId = DB::table('users')->insertGetId([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
                'tenant_id' => $tenantId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // PIN del reloj (kiosco/Ley Silla/testigos) para supervisor y empleado de prueba.
        if ($pinEnvKey !== null) {
            $pin = env($pinEnvKey, $pinDefault);
            $employee = DB::table('employees')->where('user_id', $userId)->first();
            $employeeData = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'name' => $name,
                'email' => $email,
                'security_pin' => $pin ? Hash::make($pin) : null,
                'updated_at' => now(),
            ];
            if ($employee) {
                DB::table('employees')->where('id', $employee->id)->update($employeeData);
            } else {
                $employeeData['created_at'] = now();
                DB::table('employees')->insert($employeeData);
            }
        }
    }
}
