<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R102: estado #1 del dial con retardos REALES.
 *
 * Marcador del último curso de Puntualidad aprobado. El conteo del bloqueo
 * (`punctuality_lockout_count` en toAuthPayload) cuenta los audit_logs
 * `late_entry_unlocked` POSTERIORES a este marcador; aprobar el curso lo
 * re-estampa (POST /me/punctuality-course-reset) y desbloquea el dial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('punctuality_reset_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('punctuality_reset_at');
        });
    }
};
