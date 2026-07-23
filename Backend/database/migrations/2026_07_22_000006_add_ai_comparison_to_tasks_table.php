<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §35: modo de validación "Comparación (IA)" — el admin sube 3-5 imágenes de
     * referencia + tolerancia por tarea; la IA compara la evidencia del empleado.
     */
    public function up(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                if (!Schema::hasColumn('tasks', 'ai_comparison_enabled')) {
                    $table->boolean('ai_comparison_enabled')->default(false);
                }
                if (!Schema::hasColumn('tasks', 'ai_reference_images')) {
                    $table->json('ai_reference_images')->nullable();
                }
                if (!Schema::hasColumn('tasks', 'ai_tolerance_description')) {
                    $table->text('ai_tolerance_description')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                foreach (['ai_comparison_enabled', 'ai_reference_images', 'ai_tolerance_description'] as $column) {
                    if (Schema::hasColumn('tasks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
