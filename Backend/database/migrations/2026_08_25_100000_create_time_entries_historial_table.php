<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BITÁCORA INMUTABLE DE ASISTENCIA — capa 1: la tabla espejo (2026-08-25).
 *
 * En México la carga de la prueba es del patrón (LFT 784 y 804): si la empresa no puede exhibir
 * sus controles de asistencia, **se presumen ciertos los hechos que alega el trabajador**. Un
 * registro de fichajes que un administrador puede cambiar sin dejar rastro no es evidencia — es un
 * indicio en contra.
 *
 * Aquí cae TODO cambio sobre `time_entries`, y lo escribe un trigger de la propia base de datos
 * (migración siguiente), no la aplicación. Esa es la diferencia entre una bitácora y una
 * costumbre: da igual si el cambio vino de Eloquent, de un comando, de una migración descuidada o
 * de alguien con `psql` abierto en el servidor.
 *
 * DECISIONES DE DISEÑO, y por qué:
 *
 * · **Sin llave foránea a `time_entries`.** Es deliberado: el historial tiene que sobrevivir al
 *   borrado de la fila que describe. Una FK con `cascade` borraría justo la evidencia de que algo
 *   se borró, que es el caso que más importa.
 *
 * · **`tenant_id` copiado, no unido.** Para poder purgar y aislar por empresa sin depender de una
 *   fila que quizá ya no existe.
 *
 * · **La fila entera, antes y después.** No una lista de campos cambiados: el documento que sirve
 *   en un juicio es *"decía esto y quedó así"*, completo.
 *
 * · **No se revoca todavía `UPDATE`/`DELETE` sobre esta tabla.** Va en su propio paso, después de
 *   una semana observando (paso 3 del RFC). Cerrar la puerta antes de comprobar que la bitácora ve
 *   lo que decimos que ve sería fe, no ingeniería.
 *
 * Retención aprobada por el dueño: **5 años**, y la purga nunca alcanza a una persona con un juicio
 * abierto. Ver `docs/RFC_BITACORA_INMUTABLE.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries_historial', function (Blueprint $table) {
            $table->id();

            // Sin FK a propósito (ver cabecera): el historial sobrevive a la fila que describe.
            $table->unsignedBigInteger('time_entry_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->string('operacion', 10); // INSERT | UPDATE | DELETE

            $table->json('fila_antes')->nullable();
            $table->json('fila_despues')->nullable();

            // Quién y por qué, según lo que la aplicación haya declarado en la transacción
            // (`App\Support\BitacoraDeAsistencia`). Nulo = lo escribió un proceso automático o
            // alguien que no pasó por la aplicación — y que quede nulo TAMBIÉN es información.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('correccion_id')->nullable()->index();
            $table->string('origen', 80)->nullable();

            // La hora la pone el servidor de base de datos, no la aplicación.
            $table->timestamp('registrado_en')->useCurrent();

            $table->index(['tenant_id', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries_historial');
    }
};
