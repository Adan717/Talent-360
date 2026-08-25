<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BITÁCORA INMUTABLE — un fichaje corregido se ANULA, no se borra (2026-08-25).
 *
 * Es la regla de póliza contable llevada al esquema. Hasta hoy la única forma de deshacer un
 * fichaje equivocado era borrarlo (`shifts:reparar-cierres-sinteticos` lo hace, y el reinicio de
 * jornada también): la fila desaparecía y con ella la posibilidad de explicar qué había pasado.
 *
 * Con esto, el fichaje original se queda donde está, marcado, y otro lo sustituye. Los dos se
 * conservan. Para todo lo que calcula —nómina, reportes, Monitor— el anulado deja de existir; para
 * quien tenga que probar qué ocurrió, sigue ahí junto al motivo por el que se retiró.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->timestamp('anulado_at')->nullable()->after('details');
            $table->unsignedBigInteger('anulado_por_correccion_id')->nullable()->after('anulado_at');

            // Todas las consultas de asistencia filtran por esto: conviene que sea barato.
            $table->index('anulado_at');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['anulado_at']);
            $table->dropColumn(['anulado_at', 'anulado_por_correccion_id']);
        });
    }
};
