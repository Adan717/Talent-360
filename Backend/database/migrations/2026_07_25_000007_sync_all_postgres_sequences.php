<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $tables = [
                'tenants',
                'users',
                'employees',
                'job_roles',
                'vacancies',
                'candidates',
                'time_entries',
                'weekly_payrolls',
                'academy_courses',
                'academy_lessons',
                'system_settings'
            ];

            foreach ($tables as $table) {
                try {
                    DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1))");
                } catch (\Throwable $e) {
                    // Ignore tables without integer auto-increment 'id' sequences
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
