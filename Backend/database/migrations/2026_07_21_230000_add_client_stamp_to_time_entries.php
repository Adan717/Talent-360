<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R84 (Fase 2 / T2.1): idempotencia del batch offline.
 *
 * `client_stamp` es la firma que el cliente genera al ENCOLAR un ponche offline (estable entre
 * reintentos). El batch endpoint lo usa como llave de idempotencia: re-enviar la cola no duplica.
 *
 * Unique (tenant_id, user_id, client_stamp): la firma es POR USUARIO (el review R84 lo cazó — con
 * (tenant, client_stamp) la firma de un empleado podía "sombrear"/suprimir la de otro, y R85 volverá
 * las firmas deterministas, agravándolo). Los ponches ONLINE (client_stamp NULL) NO chocan entre sí
 * porque en Postgres y SQLite los NULL son DISTINTOS en un índice único (mismo truco que R83).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('client_stamp', 100)->nullable()->after('details');
            $table->unique(['tenant_id', 'user_id', 'client_stamp'], 'time_entries_tenant_user_stamp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropUnique('time_entries_tenant_user_stamp_unique');
            $table->dropColumn('client_stamp');
        });
    }
};
