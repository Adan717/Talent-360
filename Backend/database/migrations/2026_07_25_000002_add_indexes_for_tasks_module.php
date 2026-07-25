<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sección 2 #4 (petición de Francisco): índices para que el módulo de Rutinas y
     * Tareas Diarias busque a máxima velocidad. Mismo criterio que §45: índices
     * compuestos sobre las columnas por las que de verdad se filtra.
     *
     * - task_assignments: se consulta por (tenant_id, date) en el monitor y en
     *   TaskAssignmentController::index, y por (tenant_id, user_id) para la vista de
     *   cada colaborador.
     * - routine_task: se busca por routine_id (pivote de rutinas → tareas).
     *
     * Nota: no se agrega índice simple de tasks.tenant_id porque ya lo trae el
     * foreignId('tenant_id')->constrained() de la migración 2026_06_19 — sería duplicado.
     */
    private array $indexes = [
        'task_assignments' => [
            ['tenant_id', 'date'],
            ['tenant_id', 'user_id'],
        ],
        'routine_task' => [
            ['routine_id'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $sets) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($sets as $columns) {
                foreach ($columns as $col) {
                    if (!Schema::hasColumn($table, $col)) {
                        continue 2;
                    }
                }
                $indexName = $table . '_' . implode('_', $columns) . '_idx';
                Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                    $t->index($columns, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $sets) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($sets as $columns) {
                $indexName = $table . '_' . implode('_', $columns) . '_idx';
                Schema::table($table, function (Blueprint $t) use ($indexName) {
                    $t->dropIndex($indexName);
                });
            }
        }
    }
};
