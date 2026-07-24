<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R82 (Fase 1 / T1.3): Justificantes CON EFECTO en la nómina.
 *
 * Una fila `approved` para (tenant, user, día) hace que la nómina NO deduzca el retardo de ese día ni
 * lo cuente para faltas fantasma. La emite el empleado (motivo/evidencia) y la aprueba un admin —
 * mismo flujo que R56 (late_authorization_requests), distinto efecto: R56 desbloquea la ENTRADA,
 * aquí se perdona la DEDUCCIÓN de un retardo ya ocurrido.
 *
 * Unique (tenant_id, user_id, date): un justificante por empleado por día (idempotente; re-solicitar
 * reabre la misma fila). Misma clase de invariante que R8/R51/R56/R81.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_justifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->unsignedBigInteger('user_id'); // el empleado que justifica (users.id)
            $table->date('date');
            $table->text('reason');
            $table->integer('requested_late_minutes')->nullable(); // contexto calculado server-side
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('resolved_by')->nullable(); // admin que resolvió (users.id)
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'date'], 'late_just_tenant_user_date_unique');
            $table->index(['tenant_id', 'status']); // el panel del admin lista pendientes por tenant
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_justifications');
    }
};
