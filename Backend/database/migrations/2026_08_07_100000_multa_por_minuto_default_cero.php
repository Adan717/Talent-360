<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N5 — decisión del jefe (2026-08-07, opción A): el art. 107 de la LFT prohíbe las multas al
 * salario y el descuento por minuto de retardo es exactamente eso. Deja de venir activado de
 * fábrica: default $0. El campo sigue existiendo; si una empresa lo activa, es su decisión
 * explícita y queda DOCUMENTADA (quién y cuándo: late_penalty_set_by / late_penalty_set_at).
 *
 * Datos viejos (el patrón que nos mordió tres veces): cambiar el default no arregla lo ya
 * sembrado. Se pone en $0 SOLO a quien tiene exactamente 2.00 —el default que recibió en
 * silencio—; cualquier otro valor (1.50, 3.00…) fue capturado a propósito y se respeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->decimal('late_penalty_per_minute', 8, 2)->default(0.00)->change();
            if (!Schema::hasColumn('lft_settings', 'late_penalty_set_by')) {
                $table->foreignId('late_penalty_set_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('lft_settings', 'late_penalty_set_at')) {
                $table->timestamp('late_penalty_set_at')->nullable();
            }
        });

        DB::table('lft_settings')
            ->where('late_penalty_per_minute', 2.00)
            ->update(['late_penalty_per_minute' => 0]);
    }

    public function down(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->decimal('late_penalty_per_minute', 8, 2)->default(2.00)->change();
            $table->dropConstrainedForeignId('late_penalty_set_by');
            $table->dropColumn('late_penalty_set_at');
        });
    }
};
