<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academia (auditoría 2026-08-04, familia H23): el certificado era decorativo.
 *
 * "Mis Certificados" imprimía un `div` con `window.print()`, con las fechas escritas a mano en el
 * código ("del 01 al 15 de Agosto, 2026"), sin folio, sin registro en la base y sin forma de
 * comprobar que existiera — cualquiera podía imprimir uno editando el HTML, y la empresa no tenía
 * cómo distinguirlo de uno real. Además sólo se ofrecía si el curso tenía
 * `certificate_template_id`, que ningún flujo asigna: en la práctica nadie tenía certificados.
 *
 * Esta tabla es el registro real. Guarda una FOTO de los datos al momento de emitir (nombre del
 * colaborador, título del curso, nombre de la empresa) a propósito: si mañana el curso se
 * renombra o el colaborador se va, el certificado ya emitido debe seguir diciendo lo que decía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('course_id')->constrained('academy_courses')->onDelete('cascade');

            // El folio es la credencial de verificación: se comparte y con él se consulta el
            // certificado sin sesión, así que lleva una parte aleatoria para que no se puedan
            // adivinar de corrido los de los demás.
            $table->string('folio')->unique();

            $table->timestamp('issued_at');
            $table->unsignedTinyInteger('score')->default(100);

            $table->string('participant_name');
            $table->string('course_title');
            $table->string('company_name')->nullable();

            $table->timestamps();

            // Un certificado por curso y colaborador: volver a aprobar no emite uno nuevo ni
            // cambia el folio que ya se entregó.
            $table->unique(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_certificates');
    }
};
