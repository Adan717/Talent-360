<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar columnas de snapshot a time_entries
        //    Estas columnas guardan el estado del empleado en el MOMENTO exacto del fichaje,
        //    para que los reportes históricos sean inmutables aunque el empleado cambie de puesto.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('employee_name_at_time')->nullable()->after('details')
                ->comment('Nombre completo del empleado al momento del fichaje (snapshot inmutable)');
            $table->string('job_role_title_at_time')->nullable()->after('employee_name_at_time')
                ->comment('Puesto/título del empleado al momento del fichaje (snapshot inmutable)');
            $table->decimal('base_salary_at_time', 10, 2)->nullable()->after('job_role_title_at_time')
                ->comment('Salario base del empleado al momento del fichaje (snapshot inmutable)');
        });

        // 2. Crear tabla de archivado para resets de QA
        //    Los registros borrados por resetDay/resetDb se mueven aquí en lugar de eliminarse.
        //    Solo un platform_admin puede purgar permanentemente esta tabla.
        Schema::create('archived_time_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id')->comment('ID original en time_entries');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->date('date')->nullable();
            $table->string('type')->nullable();
            $table->string('time')->nullable();
            $table->boolean('is_late')->default(false);
            $table->integer('late_minutes')->default(0);
            $table->text('details')->nullable();
            $table->string('employee_name_at_time')->nullable();
            $table->string('job_role_title_at_time')->nullable();
            $table->decimal('base_salary_at_time', 10, 2)->nullable();
            $table->string('archived_reason')->nullable()
                ->comment('Motivo del archivado: reset_day, reset_db, manual');
            $table->unsignedBigInteger('archived_by_user_id')->nullable()
                ->comment('ID del usuario que ejecutó el reset');
            $table->timestamp('original_created_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'date']);
            $table->index('original_id');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn(['employee_name_at_time', 'job_role_title_at_time', 'base_salary_at_time']);
        });

        Schema::dropIfExists('archived_time_entries');
    }
};
