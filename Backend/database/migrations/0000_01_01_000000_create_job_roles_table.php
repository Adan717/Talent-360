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
        Schema::create('job_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('area');
            $table->boolean('esAperturador')->default(false);
            $table->enum('portadorLlaves', ['apertura', 'cierre', 'ambos', 'ninguno'])->default('ninguno');
            $table->integer('jerarquiaLlaves')->default(0);
            $table->integer('tiempoTolerancia')->default(10);
            $table->boolean('requiereJustificante')->default(true);
            $table->boolean('puedeEmitirAvisos')->default(false);
            $table->boolean('aplicaLeySilla')->default(false);
            $table->boolean('evaluacion360Activa')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_roles');
    }
};
