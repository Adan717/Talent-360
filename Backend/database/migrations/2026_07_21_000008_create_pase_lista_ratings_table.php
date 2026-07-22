<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * employee_id / rated_by_employee_id apuntan a users.id (no employees.id) — mismo
     * identificador que ya usa /clock/punch y todo el módulo de reloj checador para
     * referirse a una persona. Ver docs/BACKEND_INTERFACES.md §22 para la justificación.
     */
    public function up(): void
    {
        Schema::create('pase_lista_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->integer('store_id')->default(1);
            $table->foreignId('employee_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('rated_by_employee_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->unsignedTinyInteger('presentacion');
            $table->unsignedTinyInteger('imagen');
            $table->unsignedTinyInteger('energia');
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pase_lista_ratings');
    }
};
