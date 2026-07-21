<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NULL = dato real. Cualquier valor = dato de una sesión de simulación específica.
     * Se prefiere sobre un booleano is_simulator porque agrupa todos los registros de
     * una misma corrida (permite purgar una sesión completa) y liga la fecha simulada.
     */
    private array $tables = ['time_entries', 'store_logs', 'contingencies', 'internal_messages', 'audit_logs'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'simulation_session_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('simulation_session_id')->nullable()
                        ->after('id')
                        ->constrained('simulator_sessions')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'simulation_session_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('simulation_session_id');
                });
            }
        }
    }
};
