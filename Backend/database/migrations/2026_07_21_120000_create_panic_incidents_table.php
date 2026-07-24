<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj R80 (Fase 1 / T1.1): Botón de Pánico REAL.
 *
 * El botón de pánico dejaba de ser teatro: ahora cada activación PERSISTE aquí (categoría + geo +
 * quién) y dispara una alerta a los mandos del tenant. La tabla es el registro histórico del
 * incidente y el respaldo del panel del admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panic_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->unsignedBigInteger('user_id'); // quien reporta (users.id)
            $table->string('category'); // robo_asalto | incendio | emergencia_medica | fallo_energia
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();  // geo del incidente (spec: "con geo")
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status')->default('active'); // active | resolved
            $table->unsignedBigInteger('resolved_by')->nullable(); // mando o el propio reportante (users.id)
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // El panel del admin lista los ACTIVOS por tenant.
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panic_incidents');
    }
};
