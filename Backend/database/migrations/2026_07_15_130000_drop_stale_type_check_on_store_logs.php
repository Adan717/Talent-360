<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hermano del bug de la Ronda 36 (time_entries_type_check), cazado por la auditoría de
     * paridad SQLite↔Postgres.
     *
     * store_logs.type nació como enum('open','close','forzosa','transfer'). En Postgres eso
     * es un VARCHAR + CHECK (store_logs_type_check) que NINGUNA migración de esquema elimina
     * (solo lo dropea el comando one-off MigrateLegacySqlite → señal de que la intención es
     * que la columna sea free-form). El endpoint `POST /sync/store_log`
     * (ClockController::syncStoreLog) reenvía `$request->input('type')` sin validar contra el
     * enum, así que cualquier tipo nuevo o de un cliente distinto revienta con SQLSTATE 23514
     * en prod — invisible para la suite SQLite (que nunca crea el CHECK). Hoy el FE solo manda
     * open/forzosa/close (todos válidos), así que es un landmine LATENTE, no un bug vivo; se
     * elimina el CHECK huérfano para cerrar la clase de bug de forma consistente con R36.
     *
     * SQLite no crea ese constraint → el guard por driver lo vuelve no-op en tests.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE store_logs DROP CONSTRAINT IF EXISTS store_logs_type_check');
        }
    }

    public function down(): void
    {
        // No se recrea: reintroducir el enum volvería a bloquear tipos legítimos de bitácora
        // de tienda que el endpoint acepta libremente (justo el landmine que esto cierra).
    }
};
