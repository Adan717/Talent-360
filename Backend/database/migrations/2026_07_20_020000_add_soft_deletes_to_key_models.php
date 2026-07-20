<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar columna deleted_at para borrado lógico en tablas de negocio clave
        
        if (Schema::hasTable('employees') && !Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('job_roles') && !Schema::hasColumn('job_roles', 'deleted_at')) {
            Schema::table('job_roles', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('vacancies') && !Schema::hasColumn('vacancies', 'deleted_at')) {
            Schema::table('vacancies', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('academy_courses') && !Schema::hasColumn('academy_courses', 'deleted_at')) {
            Schema::table('academy_courses', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('tenants') && !Schema::hasColumn('tenants', 'deleted_at')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('job_roles') && Schema::hasColumn('job_roles', 'deleted_at')) {
            Schema::table('job_roles', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('vacancies') && Schema::hasColumn('vacancies', 'deleted_at')) {
            Schema::table('vacancies', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('academy_courses') && Schema::hasColumn('academy_courses', 'deleted_at')) {
            Schema::table('academy_courses', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'deleted_at')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
