<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sección 2 #2: marca las asignaciones que un proceso nocturno movió a
     * "Pendiente de Validación Gerencial" por quedar inconclusas (posible apagón /
     * jornada no cerrada). El frontend usa esta bandera para mostrar los 3 botones
     * (aprobar / reprogramar / rechazar) y el contexto "por apagón".
     */
    public function up(): void
    {
        if (Schema::hasTable('task_assignments') && !Schema::hasColumn('task_assignments', 'flagged_incomplete')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->boolean('flagged_incomplete')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_assignments') && Schema::hasColumn('task_assignments', 'flagged_incomplete')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->dropColumn('flagged_incomplete');
            });
        }
    }
};
