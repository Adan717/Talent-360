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
        Schema::table('vacancies', function (Blueprint $table) {
            $table->string('image_url')->nullable();
            $table->string('work_type')->nullable(); // Ej: Tiempo Completo, Medio Tiempo
            $table->string('schedule')->nullable();  // Ej: L-D 9:00 a 18:00
            $table->string('salary_range')->nullable(); // Ej: $1,500 - $2,000 MXN Semanales
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'work_type', 'schedule', 'salary_range']);
        });
    }
};
