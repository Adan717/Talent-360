<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retardo Extremo (spec modulo_reloj_checador.md:46): umbral máximo de retardo por tenant.
 * Si un check_in supera este umbral, ClockService::processPunch BLOQUEA el fichaje salvo
 * override de un supervisor. Default 0 = deshabilitado (comportamiento previo intacto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->integer('max_late_block_minutes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('lft_settings', function (Blueprint $table) {
            $table->dropColumn('max_late_block_minutes');
        });
    }
};
