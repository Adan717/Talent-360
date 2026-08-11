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
    protected $signature = 'tenant:purge-test-tenants
        {--tenants= : IDs de las empresas a borrar, separados por coma. OBLIGATORIO.}
        {--force : Saltar la confirmación (sólo para scripts, nunca en un despliegue)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Borra FÍSICAMENTE las empresas que se le indiquen por id (--tenants=2,3) y todos sus datos. Irreversible.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // GATE DE ENTORNO (2026-08-11). Este comando no tenía ninguno: borraba empresas en
        // producción sin preguntar. Mismo patrón que ya usa ClockController::resetDay.
        if (app()->isProduction() && !env('ALLOW_QA_RESET', false)) {
            $this->error('Comando deshabilitado en producción. Fija ALLOW_QA_RESET=true a propósito si de verdad quieres borrar empresas.');
            return 1;
        }

        // LOS IDS SE DICEN A MANO (2026-08-11). Antes el criterio era `id > 1`: TODA empresa que
        // no fuera la primera se consideraba "de prueba" y se borraba físicamente, con sus
        // fichajes y sus recibos de nómina. No existe ninguna bandera `is_demo` en la tabla, así
        // que la heurística no tenía cómo distinguir una empresa real de una de pruebas — y este
        // comando se ejecutaba con --force en CADA despliegue desde `deploy_to_hetzner.py`.
        $ids = collect(explode(',', (string) $this->option('tenants')))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '' && ctype_digit($v))
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('Indica qué empresas borrar: --tenants=2,3');
            $this->line('Empresas existentes:');
            foreach (DB::table('tenants')->get(['id', 'name', 'subdomain']) as $t) {
                $this->line("  {$t->id}  {$t->name}  ({$t->subdomain})");
            }
            return 1;
        }

        if ($ids->contains(1)) {
            $this->error('La empresa 1 no se puede borrar con este comando.');
            return 1;
        }

        $nombres = DB::table('tenants')->whereIn('id', $ids)->pluck('name', 'id');

        if ($nombres->isEmpty()) {
            $this->error('Ninguno de esos ids existe.');
            return 1;
        }

        if (!$this->option('force') && !$this->confirm('Vas a BORRAR FÍSICAMENTE, y sin vuelta atrás, estas empresas con todos sus datos (fichajes, nómina, expedientes): ' . $nombres->map(fn ($n, $id) => "#{$id} {$n}")->implode(', ') . '. ¿Seguro?')) {
            $this->info('Operación cancelada por el usuario.');
            return 0;
        }

        $this->info('Borrando: ' . $nombres->map(fn ($n, $id) => "#{$id} {$n}")->implode(', '));

        try {
            DB::beginTransaction();

            $testTenantIds = $nombres->keys()->all();

            if (empty($testTenantIds)) {
                $this->info('Nada que borrar.');
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
                if (DB::getSchemaBuilder()->hasTable('system_settings')) {
                    DB::table('system_settings')->whereIn('tenant_id', $testTenantIds)->delete();
                }

                // 3. Eliminar empleados vinculados
                if (DB::getSchemaBuilder()->hasTable('employees')) {
                    $empCount = DB::table('employees')->whereIn('tenant_id', $testTenantIds)->delete();
                    $this->line(" - Tabla employees: {$empCount} registros eliminados.");
                }

                // 4. Eliminar usuarios vinculados (excluyendo cuentas de plataforma / platform_users)
                if (DB::getSchemaBuilder()->hasTable('users')) {
                    $userCount = DB::table('users')->whereIn('tenant_id', $testTenantIds)->delete();
                    $this->line(" - Tabla users: {$userCount} registros eliminados.");
                }

                // 5. Eliminar finalmente las filas de tenants
                if (DB::getSchemaBuilder()->hasTable('tenants')) {
                    $tenantCount = DB::table('tenants')->whereIn('id', $testTenantIds)->delete();
                    $this->line(" - Tabla tenants: {$tenantCount} inquilinos eliminados definitivamente.");
                }
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
