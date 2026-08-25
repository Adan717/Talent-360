<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revocación de certificados (2026-08-24).
 *
 * El apagón de folios impide emitir constancias sobre exámenes que la empresa no configuró, pero
 * llegó tarde para las que ya salieron: existen certificados verificables en público respaldados
 * por un examen de una sola pregunta, y uno de ellos dice "Derechos Laborales y Ley Federal del
 * Trabajo". Un tercero lo lee como constancia de capacitación.
 *
 * Se REVOCAN, no se borran. Un certificado emitido es un hecho histórico: alguien lo tuvo en la
 * mano y pudo compartir el folio. Borrarlo dejaría a esa consulta indistinguible de un folio
 * inventado y borraría la evidencia de que se emitió y por qué se retiró. Revocar deja las dos
 * cosas por escrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('score');
            $table->string('revoked_reason')->nullable()->after('revoked_at');
            $table->unsignedBigInteger('revoked_by')->nullable()->after('revoked_reason');

            // La verificación pública busca por folio y ahora filtra por revocación.
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            $table->dropIndex(['revoked_at']);
            $table->dropColumn(['revoked_at', 'revoked_reason', 'revoked_by']);
        });
    }
};
