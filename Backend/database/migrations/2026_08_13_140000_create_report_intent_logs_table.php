<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 6 (2026-08-13): bitácora del asistente de reportes — frase + intención + quién.
 * Contiene datos personales (las frases pueden llevar nombres de trabajadores): hereda la
 * retención del bloque 2 (la purga vive en chat:clean-old-messages) y alimenta la alerta
 * por tasa de fallo (reportes:alerta-fallos-asistente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_intent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('frase');
            $table->text('intent')->nullable();
            $table->boolean('exito');
            $table->string('nota')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_intent_logs');
    }
};
