<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R81 (Fase 1 / T1.2): Laborar Horas Extras / Feriado REAL.
 *
 * Una fila = un supervisor autorizó a (tenant, user) a laborar el día `date` (feriado bloqueante o
 * día de descanso). El bloqueo de feriado de ClockService::processPunch se levanta si existe la
 * fila para HOY. La credencial del supervisor se valida server-side (QR dinámico o PIN de kiosko);
 * antes el "desbloqueo" vivía sólo en un useState del cliente y el backend rechazaba igual.
 *
 * Unique (tenant_id, user_id, date): una autorización por empleado por día (idempotente; misma
 * clase de invariante que R8/R51/late_authorization_requests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->unsignedBigInteger('user_id'); // el empleado autorizado a laborar (users.id)
            $table->date('date');
            $table->string('kind'); // holiday | rest_day (derivado server-side al autorizar)
            $table->unsignedBigInteger('authorized_by'); // el supervisor que autorizó (users.id)
            $table->string('method'); // qr | pin
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'date'], 'overtime_auth_tenant_user_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_authorizations');
    }
};
