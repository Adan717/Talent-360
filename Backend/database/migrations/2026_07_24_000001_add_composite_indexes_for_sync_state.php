<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §45: ClockController::getState() (/sync/state, el endpoint más llamado) filtra
     * siempre por (tenant_id + fecha) juntos, pero estas tablas solo tenían el índice
     * simple de tenant_id que trae foreignId(). Se agrega el índice compuesto para que
     * el filtro de fecha no obligue a un scan secuencial en las tablas que más crecen.
     *
     * Guards con hasColumn/hasIndex por si alguna columna difiere o la migración se
     * corre sobre una BD parcial; los nombres de índice son explícitos para poder
     * revertirlos con certeza.
     */
    private array $targets = [
        'time_entries' => ['tenant_id', 'date'],
        'store_logs' => ['tenant_id', 'date'],
        'contingencies' => ['tenant_id', 'created_at'],
        'internal_messages' => ['tenant_id', 'created_at'],
        'audit_logs' => ['tenant_id', 'date'],
    ];

    public function up(): void
    {
        foreach ($this->targets as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!Schema::hasColumn($table, $columns[0]) || !Schema::hasColumn($table, $columns[1])) {
                continue;
            }

            $indexName = $table . '_' . implode('_', $columns) . '_idx';
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->index($columns, $indexName);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $indexName = $table . '_' . implode('_', $columns) . '_idx';
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropIndex($indexName);
            });
        }
    }
};
