<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R90 (Fase 4 / T4.2): "Reportar Tienda Cerrada" concede amnistía REAL.
 *
 * `late_amnesty_granted` = ¿se reportó hoy la tienda cerrada (vía el reporte gateado)? Es la señal
 * DURABLE que `processPunch` lee para amnistiar el retardo del equipo: sobrevive la transición del
 * `status` a `opened` (que borra el `closed_reported_by_employees`). Vive en la fila per-día
 * `store_daily_opening_statuses` que processPunch ya carga para el gate de tienda-cerrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_daily_opening_statuses', function (Blueprint $table) {
            $table->boolean('late_amnesty_granted')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('store_daily_opening_statuses', function (Blueprint $table) {
            $table->dropColumn('late_amnesty_granted');
        });
    }
};
