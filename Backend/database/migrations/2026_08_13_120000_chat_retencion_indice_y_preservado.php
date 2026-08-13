<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 2 / D3 (2026-08-13): retención de chat configurable.
 *
 * `preserved_at` va sobre `internal_messages` — la tabla VIVA del chat (el Monitor y el dial
 * escriben y leen ahí). `team_chat_messages`, que era la única que se purgaba, está muerta:
 * nada en el código la lee ni la escribe. Un mensaje conservado ("citado en un incidente") no
 * se purga jamás. El índice (tenant_id, created_at) que pedía el plan YA EXISTE desde
 * 2026_07_24_000001 (lo cazó la ronda adversarial: no se duplica).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->timestamp('preserved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->dropColumn('preserved_at');
        });
    }
};
