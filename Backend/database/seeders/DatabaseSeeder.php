<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear el Tenant de Prueba (Talent360)
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Talent 360',
            'domain' => 'talent360.local',
            'is_active' => true,
            'subscription_tier' => 'enterprise',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('companies')->insert([
            'name' => 'Empresa Ficticia B',
            'domain' => 'empresab.local',
            'is_active' => true,
            'subscription_tier' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Crear el tenant de prueba en tenants table
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Talent 360',
            'subdomain' => 'talent360',
            'plan' => 'enterprise',
            'max_users' => 9999,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'name' => 'Empresa Ficticia B',
            'subdomain' => 'empresab',
            'plan' => 'freemium',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Ejecutar los seeders originales
        $this->call([
            RoleNormalizationSeeder::class,
            RoleClockPolicySeeder::class,
            SupervisorRoutinesSeeder::class,
            VacancySeeder::class,
            AcademySeeder::class,
            AcademyCoursesSeeder::class,
        ]);

        // 3. Vincular todos los registros creados a esta empresa (Multi-Tenant Retroactivo)
        $tables = [
            'users', 'job_roles', 'time_entries', 'store_logs', 
            'contingencies', 'tasks', 'routines', 'vacancies', 
            'academy_courses', 'system_settings', 'role_clock_policies',
            'task_assignments', 'candidates', 'induction_courses',
            'performance_evaluations', 'user_course_progress',
            'permissions', 'role_permissions', 'internal_messages', 'ui_rbac_rules'
        ];

        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $updateData = [];
                if (DB::getSchemaBuilder()->hasColumn($table, 'company_id')) {
                    $updateData['company_id'] = $companyId;
                }
                if (DB::getSchemaBuilder()->hasColumn($table, 'tenant_id')) {
                    $updateData['tenant_id'] = $tenantId;
                }
                if (!empty($updateData)) {
                    if ($table === 'users') {
                        DB::table($table)->where('role', '!=', 'platform_admin')->update($updateData);
                    } else {
                        DB::table($table)->update($updateData);
                    }
                }
            }
        }

        // Asegurar que el usuario administrador de la plataforma (Super Admin) exista de forma desacoplada
        if (DB::getSchemaBuilder()->hasTable('platform_users')) {
            // Eliminar de users para evitar colisiones/duplicados
            DB::table('users')->where('email', 'master@talent360.com')->delete();

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

        // Semilla para Tickets de Soporte Técnico
        if (DB::table('support_tickets')->count() === 0) {
            $masterUser = DB::table('platform_users')->where('email', 'master@talent360.com')->first();
            $supportUser = DB::table('platform_users')->where('email', 'support@talent360.com')->first();

            $ticketId1 = DB::table('support_tickets')->insertGetId([
                'tenant_id' => $tenantId,
                'title' => 'Error de timbrado CFDI 4.0',
                'description' => 'El sistema arroja error al intentar timbrar la factura de la suscripción mensual. Dice que el RFC no coincide.',
                'status' => 'open',
                'priority' => 'high',
                'assigned_to' => null,
                'created_by' => null,
                'contact_name' => 'Juan Pérez',
                'contact_email' => 'juan@talent360.com',
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2)
            ]);

            if ($masterUser) {
                DB::table('support_ticket_notes')->insert([
                    'ticket_id' => $ticketId1,
                    'user_id' => $masterUser->id,
                    'user_name' => $masterUser->name,
                    'note' => 'Nota interna: Revisando la API de Facturapi para ver si el RFC en sandbox está bien configurado.',
                    'created_at' => now()->subDays(1),
                    'updated_at' => now()->subDays(1)
                ]);
            }

            DB::table('support_tickets')->insert([
                'tenant_id' => 2, // Empresa Ficticia B
                'title' => 'Duda con turnos del Reloj Checador',
                'description' => '¿Cómo puedo configurar turnos nocturnos que crucen la medianoche en la sucursal Norte?',
                'status' => 'in_progress',
                'priority' => 'medium',
                'assigned_to' => $supportUser ? $supportUser->id : null,
                'created_by' => null,
                'contact_name' => 'Sofía Gómez',
                'contact_email' => 'sofia@empresab.com',
                'created_at' => now()->subHours(12),
                'updated_at' => now()->subHours(12)
            ]);
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
