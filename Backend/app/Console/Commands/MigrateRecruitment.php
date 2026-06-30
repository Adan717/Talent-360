<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class MigrateRecruitment extends Command
{
    protected $signature = 'app:migrate-recruitment';
    protected $description = 'Migrate vacancies and academy courses from legacy SQLite to PostgreSQL, mapping job role IDs by name without altering existing PGSQL roles.';

    public function handle()
    {
        // 1. Configure the legacy SQLite connection dynamically
        $sqlitePath = database_path('database.sqlite');
        if (!file_exists($sqlitePath)) {
            $this->error("Legacy SQLite file not found at: {$sqlitePath}");
            return 1;
        }

        Config::set('database.connections.sqlite_legacy', [
            'driver' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $this->info("Starting migration of vacancies and courses...");

        // 2. Fetch Job Roles from both SQLite and PostgreSQL to map them by name
        $sqliteRoles = DB::connection('sqlite_legacy')->table('job_roles')->select('id', 'name')->get();
        $pgsqlRoles = DB::table('job_roles')->select('id', 'name')->get();

        $nameMapping = [
            'Administrador / Gerente' => 'Administrador / Gerente',
            'Sup. Tienda y Compras' => 'Supervisor Compras',
            'Sup. Cajas' => 'Sup. Cajas',
            'Sup. Producción' => 'Sup. Producción',
            'Cajeros' => 'Cajeros',
            'Ayudante Integral' => 'Ayudante Integral',
            'Apoyo Eventual (Ex-Colaborador)' => 'Apoyo Eventual (Ex-Colaborador)',
        ];

        $roleIdMapping = [];
        $this->info("Mapping Job Roles:");
        foreach ($sqliteRoles as $sqRole) {
            $mappedName = $nameMapping[$sqRole->name] ?? $sqRole->name;
            $pgsqlRole = collect($pgsqlRoles)->first(function ($r) use ($mappedName) {
                return strtolower(trim($r->name)) === strtolower(trim($mappedName));
            });

            if ($pgsqlRole) {
                $roleIdMapping[$sqRole->id] = $pgsqlRole->id;
                $this->line(" - SQLite Role '{$sqRole->name}' (ID: {$sqRole->id}) -> PostgreSQL Role '{$pgsqlRole->name}' (ID: {$pgsqlRole->id})");
            } else {
                $roleIdMapping[$sqRole->id] = null;
                $this->warn(" - SQLite Role '{$sqRole->name}' (ID: {$sqRole->id}) has NO MATCH in PostgreSQL. Will be set to NULL.");
            }
        }

        // 3. Temporarily disable foreign key constraints in PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SET session_replication_role = replica;');
        }

        try {
            // --- MIGRATE VACANCIES ---
            $this->info("\nMigrating Vacancies...");
            
            // Delete existing vacancies in PGSQL to avoid key collisions on manual re-runs
            DB::table('vacancies')->delete();

            $sqliteVacancies = DB::connection('sqlite_legacy')->table('vacancies')->get();
            $vacanciesMigrated = 0;

            foreach ($sqliteVacancies as $v) {
                $vData = (array) $v;
                
                // Map the job_role_id
                $legacyRoleId = $vData['job_role_id'] ?? null;
                $vData['job_role_id'] = $legacyRoleId ? ($roleIdMapping[$legacyRoleId] ?? null) : null;

                // Force multi-tenant fields
                $vData['tenant_id'] = 1;
                $vData['company_id'] = 1;

                DB::table('vacancies')->insert($vData);
                $vacanciesMigrated++;
                $this->line("   -> Vacancy '{$vData['title']}' migrated (Job Role ID: " . ($vData['job_role_id'] ?? 'NULL') . ")");
            }
            $this->info("Migrated {$vacanciesMigrated} vacancies.");

            // --- MIGRATE ACADEMY COURSES ---
            $this->info("\nMigrating Academy Courses...");
            
            // Delete existing academy courses in PGSQL
            DB::table('academy_courses')->delete();

            $sqliteCourses = DB::connection('sqlite_legacy')->table('academy_courses')->get();
            $coursesMigrated = 0;

            foreach ($sqliteCourses as $c) {
                $cData = (array) $c;

                // Map target_job_role_id
                $legacyRoleId = $cData['target_job_role_id'] ?? null;
                $cData['target_job_role_id'] = $legacyRoleId ? ($roleIdMapping[$legacyRoleId] ?? null) : null;

                // Force multi-tenant fields
                $cData['tenant_id'] = 1;
                $cData['company_id'] = 1;

                DB::table('academy_courses')->insert($cData);
                $coursesMigrated++;
                $this->line("   -> Course '{$cData['title']}' migrated (Target Job Role ID: " . ($cData['target_job_role_id'] ?? 'NULL') . ")");
            }
            $this->info("Migrated {$coursesMigrated} academy courses.");

            // 4. Reset sequences in PostgreSQL
            if (DB::getDriverName() === 'pgsql') {
                foreach (['vacancies', 'academy_courses'] as $table) {
                    $maxId = DB::table($table)->max('id') ?? 0;
                    $nextId = $maxId + 1;
                    $sequenceName = "{$table}_id_seq";
                    DB::statement("ALTER SEQUENCE {$sequenceName} RESTART WITH {$nextId}");
                }
            }

            $this->info("\nMigration completed successfully!");

        } catch (\Exception $e) {
            $this->error("Error during migration: " . $e->getMessage());
            return 1;
        } finally {
            // Re-enable foreign key constraints in PostgreSQL
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET session_replication_role = DEFAULT;');
            }
        }

        return 0;
    }
}
