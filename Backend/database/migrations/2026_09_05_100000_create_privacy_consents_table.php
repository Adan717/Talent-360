<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de consentimientos del aviso de privacidad (2026-09-05).
 *
 * Antes de esta tabla NO existía ninguna: la casilla "Acepto el Aviso de Privacidad" del alta de
 * empresa sólo habilitaba un botón en el navegador y su valor se perdía al enviar el formulario, y
 * el portal público de empleo recibía nombre, correo y teléfono de candidatos sin casilla ninguna.
 * Ante la LFPDPPP el consentimiento hay que poder PROBARLO: quién, cuándo, qué versión y desde dónde.
 *
 * `created_at` ES la fecha de aceptación (no se añade una columna aparte para no tener dos verdades);
 * las filas no se editan ni se borran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_consents', function (Blueprint $table) {
            $table->id();

            // Nullable: en el alta de empresa la empresa todavía no existe cuando la persona acepta.
            // Se rellena al aprovisionar (ver SubscriptionController::provisionTenant).
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // El titular. Uno de los dos, según el punto: la cuenta (alta / primer ingreso) o el
            // candidato del portal público (que no tiene cuenta).
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('candidate_id')->nullable()->index();

            // Cuál de los tres momentos: alta_empresa | primer_ingreso | postulacion.
            $table->string('punto', 32);

            // La versión del texto que se aceptó (App\Support\AvisoDePrivacidad::VERSION). Sin esto
            // el registro no sirve: "aceptó el aviso" no dice nada si el aviso cambió después.
            $table->string('version', 32);

            // Copia del nombre y correo AL MOMENTO de aceptar. Redundante a propósito: si mañana el
            // expediente se depura o el candidato se descarta, la constancia sigue diciendo quién fue.
            $table->string('nombre')->nullable();
            $table->string('email')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            // Una persona acepta UNA vez cada versión. Los NULL no chocan entre sí ni en sqlite ni en
            // Postgres, así que las filas de candidatos no estorban a las de usuarios y viceversa.
            $table->unique(['user_id', 'version'], 'privacy_consents_user_version_unique');
            $table->unique(['candidate_id', 'version'], 'privacy_consents_candidate_version_unique');
        });

        // ------------------------------------------------------------------------------------
        // La MARCA en la cuenta, calcada de `must_change_password` (bloque 1, 2026-08-13): es lo
        // que lee el gate en cada petición, sin consultar la tabla de arriba. La tabla es la
        // constancia; esta marca es el candado.
        // ------------------------------------------------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('privacidad_pendiente')->default(false);
            $table->string('privacidad_aceptada_version', 32)->nullable();
        });

        // Todas las cuentas que YA existen quedan marcadas: ninguna vio nunca el aviso —ése es
        // justamente el agujero que esto cierra— y a quien entra por el kiosco con su PIN no había
        // ni una sola pantalla que se lo mostrara. Se excluye al personal de la plataforma
        // (platform_admin / support_agent) y a las cuentas sin empresa: no son titulares de datos
        // laborales de un cliente y no tienen pantalla donde presentárselo.
        //
        // En una base recién migrada (la suite) esto no toca ninguna fila, así que las cuentas de
        // prueba nacen sin marca, igual que con `must_change_password`.
        DB::table('users')
            ->whereNotNull('tenant_id')
            ->whereNotIn('role', ['platform_admin', 'support_agent'])
            ->update(['privacidad_pendiente' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacidad_pendiente', 'privacidad_aceptada_version']);
        });

        Schema::dropIfExists('privacy_consents');
    }
};
