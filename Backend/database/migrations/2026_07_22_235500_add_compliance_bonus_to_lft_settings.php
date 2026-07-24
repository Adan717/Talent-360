<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R94 (Fase 5 / T5.1 · N6): Bono de Cumplimiento server-side.
 *
 * El bono tiene DOS componentes INDEPENDIENTES, cada uno opt-in por su monto (0 = apagado; decisión
 * del usuario 2026-07-22: "debe poder ser configurable"). Con ambos en 0 (default) ningún tenant se
 * afecta — el neto de nómina no cambia hasta que el admin configure un monto.
 *  - `punctuality_bonus_amount`: bono FIJO del periodo si el empleado tuvo <= `punctuality_bonus_max_lates`
 *    retardos Y cero faltas físicas.
 *  - `opening_bonus_per_open`: bono POR CADA apertura de tienda a tiempo del portador de llaves.
 * Viven en `lft_settings` (per-tenant, ya cargado en calculatePayrollForEmployee) — mismo patrón que
 * `late_penalty_per_minute`/`max_late_block_minutes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->decimal('punctuality_bonus_amount', 10, 2)->default(0);
            $table->unsignedSmallInteger('punctuality_bonus_max_lates')->default(0);
            $table->decimal('opening_bonus_per_open', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->dropColumn(['punctuality_bonus_amount', 'punctuality_bonus_max_lates', 'opening_bonus_per_open']);
        });
    }
};
