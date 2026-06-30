<?php

use Illuminate\Support\Facades\DB;

// Buscar usuarios para asignarles
$encargado = DB::table('users')->where('job_role_id', 1)->first(); // Encargado Titular
$cajero = DB::table('users')->where('job_role_id', 2)->first();    // Segundo Encargado

// Limpiar asignaciones previas si existen
DB::table('task_assignments')->truncate();

if ($encargado) {
    // Asignarle tareas de la Rutina 1
    DB::table('task_assignments')->insert([
        [
            'task_id' => 1, // Limpieza de Estaciones
            'user_id' => $encargado->id,
            'status' => 'pending',
            'started_at_mins' => null,
            'expected_end_time_mins' => null,
            'completed_at_mins' => null,
            'assigned_from_routine_id' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ],
        [
            'task_id' => 3, // Revisión de Inventario
            'user_id' => $encargado->id,
            'status' => 'in_progress',
            'started_at_mins' => 500, // 8:20 AM
            'expected_end_time_mins' => 545, // 9:05 AM
            'completed_at_mins' => null,
            'assigned_from_routine_id' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]
    ]);
}

if ($cajero) {
    // Asignarle tareas de la Rutina 2
    DB::table('task_assignments')->insert([
        [
            'task_id' => 2, // Arqueo de caja
            'user_id' => $cajero->id,
            'status' => 'pending',
            'started_at_mins' => null,
            'expected_end_time_mins' => null,
            'completed_at_mins' => null,
            'assigned_from_routine_id' => 2,
            'created_at' => now(),
            'updated_at' => now()
        ]
    ]);
}

echo "Asignaciones creadas con éxito!";
