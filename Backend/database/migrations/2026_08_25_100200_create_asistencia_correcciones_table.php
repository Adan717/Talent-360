<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BITÁCORA INMUTABLE — capa 2: la INTENCIÓN (2026-08-25).
 *
 * El trigger de la capa 1 sabe *qué* cambió. Un juez pregunta el *porqué* justo después. Aquí vive
 * eso: quién autorizó la corrección, con qué motivo escrito, y —lo que más pesa— **si al
 * colaborador se le avisó**. Un ajuste de asistencia que la persona nunca supo que ocurrió es
 * exactamente lo que se ve mal en un juicio.
 *
 * PÓLIZA CONTABLE, NO GOMA DE BORRAR. Corregir un fichaje no lo sobrescribe: el original se marca
 * como anulado y se inserta uno nuevo que lo sustituye. Los dos se conservan, igual que una póliza
 * no se borra sino que se cancela con otra. El fichaje de las 8:03 sigue existiendo aunque hoy
 * cuente el de las 8:00.
 *
 * `motivo` es NOT NULL a propósito: una corrección sin razón escrita no se puede defender, y hacer
 * el campo obligatorio en la base es lo único que garantiza que ninguna vía lo omita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_correcciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // Qué se corrigió y con qué se sustituyó. Ambas nulables: dar de ALTA un fichaje
            // olvidado no anula nada, y anular un fichaje duplicado no lo sustituye por otro.
            $table->unsignedBigInteger('time_entry_id')->nullable()->index();
            $table->unsignedBigInteger('nueva_time_entry_id')->nullable()->index();

            // alta | anulacion | sustitucion
            $table->string('tipo', 20);

            $table->json('valor_anterior')->nullable();
            $table->json('valor_nuevo')->nullable();

            // Sin motivo no hay corrección. Obligatorio en la BASE, no sólo en la pantalla.
            $table->text('motivo');

            // Quién responde por ella y a quién le afecta.
            $table->unsignedBigInteger('autorizado_por');
            $table->unsignedBigInteger('empleado_user_id')->index();

            // Notificación al colaborador: decisión del dueño (2026-08-24), OBLIGATORIA.
            // Nulo = todavía no se le ha avisado; es una deuda visible, no un detalle perdido.
            $table->timestamp('notificado_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_correcciones');
    }
};
