<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = [
            'job_roles', 'time_entries', 'store_logs', 
            'contingencies', 'tasks', 'routines', 'vacancies', 
            'academy_courses', 'system_settings', 'role_clock_policies',
            'task_assignments', 'candidates', 'induction_courses',
            'performance_evaluations', 'user_course_progress',
            'permissions', 'role_permissions', 'internal_messages', 'ui_rbac_rules'
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('tenant_id')->nullable()->constrained('tenants')->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'job_roles', 'time_entries', 'store_logs', 
            'contingencies', 'tasks', 'routines', 'vacancies', 
            'academy_courses', 'system_settings', 'role_clock_policies',
            'task_assignments', 'candidates', 'induction_courses',
            'performance_evaluations', 'user_course_progress',
            'permissions', 'role_permissions', 'internal_messages', 'ui_rbac_rules'
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['tenant_id']);
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
