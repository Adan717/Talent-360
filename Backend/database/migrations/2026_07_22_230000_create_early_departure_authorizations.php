<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R92 (Fase 4): Salida Anticipada enforceada.
 *
 * Autorización server-side de una salida anticipada (spec: botón secundario #3 — "QR supervisor (Pro)
 * o causa (Free), sin penalización si autorizado"). Espejo de `overtime_authorizations` (R81): la
 * credencial del supervisor se valida en el servidor y la autorización queda en fila propia; el
 * check_out la consume para estampar `details.early_departure.authorized = true`.
 *
 * Tabla PROPIA y no `overtime_authorizations` con otro `kind`: aquella tiene unique(tenant,user,date)
 * SIN kind (una autorización de horas extras el mismo día colisionaría) y su consumer en processPunch
 * no filtra por kind (una fila 'early_departure' desbloquearía el feriado por accidente). La
 * consolidación de las N tablas de autorización es follow-up conocido (AbstractDayApprovalController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('early_departure_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->unsignedBigInteger('user_id'); // el empleado autorizado a salir temprano (users.id)
            $table->date('date');
            $table->unsignedBigInteger('authorized_by'); // el supervisor que autorizó (users.id)
            $table->string('method'); // qr | pin
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'date'], 'early_dep_auth_tenant_user_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('early_departure_authorizations');
    }
};
