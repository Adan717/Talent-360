<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §40: origen de la asignación para el reporte de cierre del plan de trabajo
     * diario. Valores: 'planned', 'carried_over', 'extra', 'routine'. El frontend
     * decide cuál aplica al crear la asignación; el backend solo la persiste.
     */
    public function up(): void
    {
        if (Schema::hasTable('task_assignments') && !Schema::hasColumn('task_assignments', 'origin')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->string('origin', 20)->nullable()->default('planned');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_assignments') && Schema::hasColumn('task_assignments', 'origin')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                $table->dropColumn('origin');
            });
        }
    }
};
