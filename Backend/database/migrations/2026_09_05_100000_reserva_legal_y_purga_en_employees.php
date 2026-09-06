<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RETENCIÓN A CINCO AÑOS Y RESERVA POR JUICIO ABIERTO — el techo que faltaba (2026-09-05).
 *
 * El sistema ya tenía el PISO legal: "Eliminar definitivamente" se niega a borrar a quien tiene
 * fichajes, recibos o expediente, y archiva citando el art. 804 de la LFT (cinco años de guarda).
 * Lo que no tenía era TECHO: pasados esos cinco años nada caducaba nunca, y conservar datos
 * personales sin plazo tiene su propio problema legal —la LFPDPPP obliga a suprimirlos cuando
 * dejan de ser necesarios para la finalidad que los justificó— además del riesgo obvio: lo que no
 * existe no se puede filtrar.
 *
 * La regla que el dueño aprobó ("retención 5 años, y la purga nunca alcanza a quien tiene un juicio
 * abierto") vivía SÓLO como comentario en la cabecera de la bitácora inmutable y en el RFC. Aquí
 * deja de ser una intención y pasa a ser una columna.
 *
 * LAS COLUMNAS, y por qué cada una:
 *
 * · `legal_hold_at` — cuándo se puso la reserva. Mientras no sea nula, esta persona es INTOCABLE
 *   para la purga: en un juicio laboral la evidencia que se destruye se presume en contra del
 *   patrón (LFT 784/804), así que el plazo de retención no puede correr por encima de un litigio.
 *
 * · `legal_hold_reason` — el motivo. Obligatorio al marcar Y al levantar: una reserva sin razón
 *   escrita es una casilla que alguien palomeó, no una decisión que se pueda defender.
 *
 * · `legal_hold_by` / `legal_hold_by_name` — quién la puso. **Sin llave foránea a propósito**: el
 *   administrador que marca la reserva puede irse de la empresa y acabar él mismo anonimizado por
 *   esta misma purga; una FK con `cascade` borraría el dato de quién la puso, y una con `restrict`
 *   impediría purgarlo a él. Por eso además se guarda el NOMBRE como foto: un id que apunta a una
 *   ficha ya anonimizada no le dice nada a nadie dentro de tres años. Es el mismo patrón que
 *   `employee_name_at_time` en los fichajes y `created_by_name_snapshot` en los planes del día.
 *
 * · `legal_hold_reference` — expediente o juzgado, opcional. Es lo que permite reconciliar la
 *   reserva con el caso real cuando el abogado pregunta.
 *
 * · `purged_at` — la marca de "ya se purgó". Sirve para dos cosas: que el comando sea idempotente
 *   (no vuelve a recorrer a quien ya no tiene nada) y que la ficha anonimizada DIGA que está
 *   anonimizada, en vez de parecer un expediente capturado a medias.
 *
 * NINGUNA de estas columnas va en `$fillable` del modelo. Se escriben con `forceFill()` desde los
 * dos únicos sitios autorizados (el endpoint `role:admin` de reserva legal y el comando de purga),
 * porque la pantalla de RRHH manda el expediente ENTERO en cada guardado: si fueran asignables en
 * masa, corregirle el teléfono a alguien podría levantarle la reserva sin que nadie lo pidiera.
 * Hay prueba de ello (`ReservaLegalTest::test_un_put_normal_al_expediente_no_toca_la_reserva`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('legal_hold_at')->nullable()->after('termination_reason');
            $table->string('legal_hold_reason')->nullable()->after('legal_hold_at');
            // Sin foreignId(): ver la cabecera. Es un id suelto, y a su lado su foto de nombre.
            $table->unsignedBigInteger('legal_hold_by')->nullable()->after('legal_hold_reason');
            $table->string('legal_hold_by_name')->nullable()->after('legal_hold_by');
            $table->string('legal_hold_reference')->nullable()->after('legal_hold_by_name');

            $table->timestamp('purged_at')->nullable()->after('legal_hold_reference');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'legal_hold_at',
                'legal_hold_reason',
                'legal_hold_by',
                'legal_hold_by_name',
                'legal_hold_reference',
                'purged_at',
            ]);
        });
    }
};
