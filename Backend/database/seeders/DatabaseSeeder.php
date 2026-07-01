<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Catálogos Base esenciales (Si existieran catalogos 100% globales, se llamarian aqui)
        // Por ahora, todos los catalogos dependen del Tenant en el modelo SaaS.

        // Asegurar que el usuario administrador de la plataforma (Super Admin) exista
        if (DB::getSchemaBuilder()->hasTable('platform_users')) {
            if (!DB::table('platform_users')->where('email', 'master@talent360.com')->exists()) {
                DB::table('platform_users')->insert([
                    'name' => 'Master Admin',
                    'email' => 'master@talent360.com',
                    'password' => \Illuminate\Support\Facades\Hash::make('Master'),
                    'role' => 'platform_admin',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            if (!DB::table('platform_users')->where('email', 'pcmasterirapuato@gmail.com')->exists()) {
                DB::table('platform_users')->insert([
                    'name' => 'Francisco Vega',
                    'email' => 'pcmasterirapuato@gmail.com',
                    'password' => \Illuminate\Support\Facades\Hash::make('Master'),
                    'role' => 'platform_admin',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            if (!DB::table('platform_users')->where('email', 'support@talent360.com')->exists()) {
                DB::table('platform_users')->insert([
                    'name' => 'Agente Soporte',
                    'email' => 'support@talent360.com',
                    'password' => \Illuminate\Support\Facades\Hash::make('Support123'),
                    'role' => 'support_agent',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
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
}
