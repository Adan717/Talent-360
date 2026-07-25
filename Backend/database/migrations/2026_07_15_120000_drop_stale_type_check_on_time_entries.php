<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * time_entries.type nació como enum('check_in','meal_start','meal_end','check_out').
     * En Postgres, Laravel implementa el enum como VARCHAR + CHECK (time_entries_type_check).
     * La migración 2026_06_09_074700 lo cambió a string libre, pero `->change()` NO elimina
     * el CHECK heredado en Postgres → el constraint quedó huérfano bloqueando TODOS los
     * tipos añadidos después: break_start/break_end (Ley Silla), waiting (amnistía "Ya
     * llegué"), temp_exit_start/temp_exit_end, y las reservas de comida
     * (meal_reservation/meal_cancel/meal_swap). Cualquiera de ellos reventaba con
     * SQLSTATE 23514 y el fichaje devolvía 400 sin persistir.
     *
     * El tipo se valida en la capa de servicio (ClockService distingue auxiliares vs
     * asistencia por listas explícitas), así que aquí sólo se elimina el CHECK obsoleto.
     * SQLite no crea ese constraint, por lo que el guard por driver lo vuelve no-op en tests.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_type_check');
        }
    }

    public function down(): void
    {
        // No se recrea: reintroducir el enum volvería a bloquear el vocabulario real de
        // fichajes (justo el bug que esta migración corrige).
    }
};
