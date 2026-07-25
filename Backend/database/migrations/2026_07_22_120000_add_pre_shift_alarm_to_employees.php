<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R87 (Fase 3 / T3.2): Alarma de traslado.
 *
 * `pre_shift_alarm_minutes` = cuántos minutos ANTES del turno el colaborador quiere el aviso local
 * ("Es hora de salir…"). 0/null = desactivada. Vive en `employees` (no en `users`): la jornada
 * (shiftStart, contra la que se calcula la alarma) vive ahí y el reloj arma su estado desde el
 * expediente (getState/toAuthPayload) — una columna en `users` se perdería (saga R41/R44/R45).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedSmallInteger('pre_shift_alarm_minutes')->nullable()->after('shiftEnd');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('pre_shift_alarm_minutes');
        });
    }
};
