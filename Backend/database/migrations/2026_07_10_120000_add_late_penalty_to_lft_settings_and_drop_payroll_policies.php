<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penalización por minuto de retardo configurable por tenant (antes hardcodeada
        // como `* 2` en ClockService). Default 2.00 = comportamiento previo.
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->decimal('late_penalty_per_minute', 8, 2)->default(2.00);
        });

        // Tabla huérfana: creada por una migración previa pero nunca leída/escrita por
        // ningún controlador/servicio, y con esquema (penalizaciones fijas) incompatible
        // con el cálculo real de nómina (por-minuto / por-día). Se elimina.
        Schema::dropIfExists('payroll_policies');
    }

    public function down(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->dropColumn('late_penalty_per_minute');
        });

        // Recrear la estructura original de payroll_policies (reversibilidad).
        Schema::create('payroll_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->decimal('late_penalty', 10, 2)->default(250);
            $table->decimal('absence_penalty', 10, 2)->default(1000);
            $table->string('period_type')->default('biweekly');
            $table->string('currency', 10)->default('MXN');
            $table->timestamps();
        });
    }
};
