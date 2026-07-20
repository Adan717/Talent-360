<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('description')->nullable(); // Mapea el Objetivo del manual
            $table->json('validation_criteria')->nullable(); // Mapea el checklist de supervisión
            $table->string('frequency')->default('Diaria'); // Mapea la frecuencia
            $table->string('evidence_type')->default('Supervisión directa'); // Mapea el tipo de evidencia
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['description', 'validation_criteria', 'frequency', 'evidence_type']);
        });
    }
};
