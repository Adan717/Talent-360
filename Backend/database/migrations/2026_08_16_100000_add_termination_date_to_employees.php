<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de baja (2026-08-16). Al construir el reporte de rotación salió que el sistema NO
 * sabe CUÁNDO se fue nadie: "Enviar a inactivo" sólo pone `is_active_employee = false`, sin
 * fecha, y `deleted_at` (cuando existe) es el día en que alguien pulsó "eliminar", que puede
 * ser meses después de la salida real.
 *
 * Sin esta columna no hay índice de rotación honesto: se puede decir cuánta gente está
 * inactiva, pero no cuándo se fue ni cuánto duró. Se agrega para que de AQUÍ EN ADELANTE sí
 * se pueda medir; el pasado no se inventa — el reporte declara qué bajas no tienen fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('termination_date')->nullable()->after('hire_date');
            $table->string('termination_reason')->nullable()->after('termination_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['termination_date', 'termination_reason']);
        });
    }
};
