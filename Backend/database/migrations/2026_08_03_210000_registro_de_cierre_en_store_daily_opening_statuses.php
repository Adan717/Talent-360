<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro formal del CIERRE de sucursal (decisión de producto P1-P3, 2026-08-03):
 * quién cerró y cuándo, espejo exacto de opened_by_employee_id/opened_at.
 *
 * A PROPÓSITO no se toca la columna `status`: el gate de tienda-cerrada de todos los planes
 * (R76) lee `status`, y si el cierre lo cambiara, los colaboradores que siguen dentro no
 * podrían fichar su salida — es decir, el cierre BLOQUEARÍA, que es exactamente lo que se
 * decidió que no haga ("candado a la salida = conflicto laboral"). La verdad del cierre vive
 * en estas dos columnas; la píldora del dial las lee para pintar "CERRADA".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_daily_opening_statuses', function (Blueprint $table) {
            $table->foreignId('closed_by_employee_id')->nullable()
                ->constrained('users')->onDelete('set null');
            $table->timestamp('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_daily_opening_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_employee_id');
            $table->dropColumn('closed_at');
        });
    }
};
