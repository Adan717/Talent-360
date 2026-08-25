<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un fichaje sabe si NACIÓ de una corrección (2026-08-25).
 *
 * `anulado_por_correccion_id` dice qué corrección retiró un fichaje. Falta el otro lado: cuál lo
 * CREÓ. Sin eso, para pintar la etiqueta "⚠️ Corregido" habría que cruzar `asistencia_correcciones`
 * en cada pantalla que liste fichajes — el reloj del colaborador, el Monitor, los reportes— y la
 * que se olvidara mostraría un fichaje corregido como si fuera original.
 *
 * La transparencia con el colaborador es obligación aquí (decisión del dueño, 2026-08-24, y es lo
 * que la ley espera): la persona tiene que ver que le movieron un registro. Un dato que hay que
 * recordar cruzar es un dato que alguien va a olvidar; con la columna, la etiqueta sale sola donde
 * ya se lee el fichaje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('creado_por_correccion_id')->nullable()->after('anulado_por_correccion_id');
            $table->index('creado_por_correccion_id');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['creado_por_correccion_id']);
            $table->dropColumn('creado_por_correccion_id');
        });
    }
};
