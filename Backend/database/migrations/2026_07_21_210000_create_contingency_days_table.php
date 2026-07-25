<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R83 (Fase 1 / T1.4): Contingencia (fuerza mayor) → pago 100%.
 *
 * Una fila `approved` para (tenant, user, día) hace que la nómina trate ese día como PRESENTE
 * (jornada causal 100% pagada, LFT Art. 42/427-431), sin contarlo como falta ni reducir el séptimo
 * día, y congela el retardo de ese día. La declara el empleado (fuerza mayor: sin luz / sin red) y la
 * aprueba un admin.
 *
 * Tabla DEDICADA (no la legacy `contingencies`, que carece de `date`/unique y sirve para
 * late/absent/rest_day_change sin efecto en nómina — mismo criterio que R82 con late_justifications).
 * Unique (tenant_id, user_id, date): una por empleado por día (idempotente; R8/R51/R56/R82).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingency_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->unsignedBigInteger('user_id'); // el empleado afectado (users.id)
            $table->date('date');
            $table->text('reason');
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('resolved_by')->nullable(); // admin que resolvió (users.id)
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'date'], 'contingency_tenant_user_date_unique');
            $table->index(['tenant_id', 'status']); // el panel del admin lista pendientes por tenant
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contingency_days');
    }
};
