<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeTestTenantsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:purge-test-tenants {--force : Confirm purging all test tenants without prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Depura de forma física y en cascada todos los inquilinos de prueba (tenant_id > 1), preservando intacto a Decorarte 360 (tenant_id = 1) y ajustando la secuencia a id = 2';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('¿Estás SEGURO de eliminar físicamente todas las empresas de prueba (tenant_id > 1) y sus datos vinculados? Esta acción es irreversible.')) {
            $this->info('Operación cancelada por el usuario.');
            return 0;
        }

        $this->info('Iniciando depuración profunda de inquilinos de prueba (tenant_id > 1)...');

        try {
            DB::beginTransaction();

            // 1. Obtener los IDs de tenants a eliminar (excluyendo tenant_id = 1)
            $testTenantIds = DB::table('tenants')
                ->where('id', '>', 1)
                ->where('subdomain', '!=', 'talent360')
                ->pluck('id')
                ->toArray();

            if (empty($testTenantIds)) {
                $this->info('No se encontraron empresas de prueba (tenant_id > 1) para eliminar.');
            } else {
                $this->info('Empresas a eliminar: ' . implode(', ', $testTenantIds));

                // 2. Tablas asociadas a eliminar de forma física limpia
                $tenantTables = [
                    'time_entries',
                    'store_logs',
                    'contingencies',
                    'audit_logs',
                    'saas_audit_logs',
                    'store_opening_assignments',
                    'store_daily_opening_statuses',
                    'store_opening_events',
                    'key_transfers',
                    'door_notices',
                    'weekly_payrolls',
                    'daily_approvals',
                    'pase_lista_ratings',
                    'meal_photo_evidences',
                    'meal_reservations',
                    'meal_queue_entries',
                    'meal_queue_rounds',
                    'silla_requests',
                    'contingency_declarations',
                    'simulator_sessions',
                    'tenant_offline_secrets',
                    'task_assignments',
                    'tasks',
                    'routines',
                    'vacancy_alerts',
                    'interviews',
                    'candidates',
                    'vacancies',
                    'user_course_progress',
                    'academy_courses',
                    'obsidian_exam_attempts',
                    'obsidian_exam_questions',
                    'obsidian_exams',
                    'obsidian_suggestions',
                    'obsidian_read_progress',
                    'obsidian_links',
                    'obsidian_documents',
                    'obsidian_vaults',
                    'obsidian_users',
                    'role_clock_policies',
                    'ui_rbac_rules',
                    'role_permissions',
                    'job_roles',
                    'device_registrations',
                    'employee_reports',
                    'team_chat_messages',
                    'billing_cards',
                    'support_ticket_notes',
                    'support_tickets',
                    'lft_holidays',
                    'company_features',
                    'tenant_module_subscriptions',
                ];

                foreach ($tenantTables as $table) {
                    if (DB::getSchemaBuilder()->hasTable($table)) {
                        if (DB::getSchemaBuilder()->hasColumn($table, 'tenant_id')) {
                            $deletedCount = DB::table($table)->whereIn('tenant_id', $testTenantIds)->delete();
                            if ($deletedCount > 0) {
                                $this->line(" - Tabla {$table}: {$deletedCount} registros eliminados.");
                            }
                        }
                    }
                }

                // Limpiar system_settings de los tenants borrados
                DB::table('system_settings')->whereIn('tenant_id', $testTenantIds)->delete();

                // 3. Eliminar empleados vinculados
                $empCount = DB::table('employees')->whereIn('tenant_id', $testTenantIds)->delete();
                $this->line(" - Tabla employees: {$empCount} registros eliminados.");

                // 4. Eliminar usuarios vinculados (excluyendo cuentas de plataforma / platform_users)
                $userCount = DB::table('users')->whereIn('tenant_id', $testTenantIds)->delete();
                $this->line(" - Tabla users: {$userCount} registros eliminados.");

                // 5. Eliminar finalmente las filas de tenants
                $tenantCount = DB::table('tenants')->whereIn('id', $testTenantIds)->delete();
                $this->line(" - Tabla tenants: {$tenantCount} inquilinos eliminados definitivamente.");
            }

            // 6. Sincronizar secuencias de PostgreSQL para que el próximo registro obtenga tenant_id = 2
            if (DB::getDriverName() === 'pgsql') {
                $seqTables = [
                    'tenants' => 1,
                    'users' => 1,
                    'employees' => 1,
                    'job_roles' => 1,
                    'tasks' => 1,
                    'vacancies' => 1,
                ];

                foreach ($seqTables as $t => $minVal) {
                    if (DB::getSchemaBuilder()->hasTable($t)) {
                        DB::statement("SELECT setval('{$t}_id_seq', COALESCE((SELECT MAX(id) FROM {$t}), {$minVal}))");
                    }
                }
                $this->info('Secuencias de PostgreSQL sincronizadas correctamente.');
            }

            DB::commit();
            $this->info('✅ Depuración completada con éxito. Decorarte 360 (tenant_id = 1) permanece activo y el secuenciador está listo en tenant_id = 2.');
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error al depurar inquilinos: ' . $e->getMessage());
            Log::error('PurgeTestTenantsCommand Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return 1;
        }
    }
}
