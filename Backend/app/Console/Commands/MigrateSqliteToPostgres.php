<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class MigrateSqliteToPostgres extends Command
{
    protected $signature = 'data:migrate-legacy';
    protected $description = 'Migrates data from legacy SQLite database to the current PostgreSQL database';

    public function handle()
    {
        // Set up the legacy sqlite connection dynamically so it doesn't read DB_DATABASE from env
        Config::set('database.connections.sqlite_legacy', [
            'driver' => 'sqlite',
            'database' => database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $tables = [
            'system_settings',
            'job_roles',
            'users',
            'vacancies',
            'tasks',
            'academy_courses',
            'role_policies'
        ];

        $this->info('Starting migration from SQLite to PostgreSQL...');

        // Disable FK checks in PostgreSQL temporarily
        DB::statement('SET session_replication_role = replica;');

        foreach ($tables as $table) {
            $this->info("Migrating table: $table");
            
            DB::table($table)->truncate();

            // Fetch from SQLite
            $records = DB::connection('sqlite_legacy')->table($table)->get();
            $count = $records->count();

            if ($count === 0) {
                $this->line(" - No records found in $table");
                continue;
            }

            // Convert objects to arrays
            $insertData = [];
            foreach ($records as $record) {
                $insertData[] = (array) $record;
            }

            // Insert into PostgreSQL in chunks
            $chunks = array_chunk($insertData, 100);
            foreach ($chunks as $chunk) {
                DB::table($table)->insert($chunk);
            }

            $this->line(" - Migrated $count records into $table");

            // Reset Postgres sequences
            if (Schema::hasColumn($table, 'id')) {
                $maxId = DB::table($table)->max('id') ?? 0;
                $nextId = $maxId + 1;
                $sequenceName = "{$table}_id_seq";
                DB::statement("ALTER SEQUENCE {$sequenceName} RESTART WITH {$nextId}");
            }
        }

        // Re-enable FK checks
        DB::statement('SET session_replication_role = DEFAULT;');

        $this->info('Migration completed successfully!');
    }
}
