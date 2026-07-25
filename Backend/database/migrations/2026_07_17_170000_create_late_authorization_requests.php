<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 56 (Reloj): solicitudes de autorización por retardo (tolerancia con autorización).
 *
 * Cuando el Retardo Extremo (R14) bloquea la entrada, el empleado puede pedir autorización a un
 * admin. Una fila `approved` para (tenant, user, día) levanta el bloqueo server-side en ClockService.
 *
 * Unique (tenant_id, user_id, date): una solicitud por empleado por día (idempotente; re-solicitar
 * reabre la misma fila). Misma clase de invariante que R8/R51.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_authorization_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->unsignedBigInteger('user_id'); // el empleado que solicita (users.id)
            $table->date('date');
            $table->integer('requested_late_minutes')->nullable(); // contexto calculado server-side
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('resolved_by')->nullable(); // admin que resolvió (users.id)
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'date'], 'late_auth_tenant_user_date_unique');
            $table->index(['tenant_id', 'status']); // el panel del admin lista pendientes por tenant
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_authorization_requests');
    }
};
