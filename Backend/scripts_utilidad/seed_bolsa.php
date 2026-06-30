<?php

use Illuminate\Support\Facades\DB;

// Crear un par de tareas nuevas diseñadas para la bolsa de trabajo
$t4 = DB::table('tasks')->insertGetId([
    'title' => 'Acomodar Bodega Principal',
    'estimated_mins' => 60,
    'priority' => 'normal',
    'category' => 'operativo',
    'target_type' => 'role',
    'target_id' => null, // Cualquiera puede tomarla
    'assistant_type' => 'evidencia_foto',
    'assistant_prompt' => 'Toma una foto de la bodega ya acomodada.',
    'is_auto_capture' => false,
    'created_at' => now(),
    'updated_at' => now()
]);

$t5 = DB::table('tasks')->insertGetId([
    'title' => 'Llamar a Proveedores Retrasados',
    'estimated_mins' => 30,
    'priority' => 'bloqueante',
    'category' => 'administrativo',
    'target_type' => 'role',
    'target_id' => null, // Cualquiera
    'assistant_type' => 'texto',
    'assistant_prompt' => 'Anota los acuerdos o fechas prometidas de entrega.',
    'is_auto_capture' => false,
    'created_at' => now(),
    'updated_at' => now()
]);

// Asignarlas a la Bolsa de Trabajo (user_id = null)
DB::table('task_assignments')->insert([
    [
        'task_id' => $t4,
        'user_id' => null, // Bolsa de trabajo
        'status' => 'pending',
        'started_at_mins' => null,
        'expected_end_time_mins' => null,
        'completed_at_mins' => null,
        'assigned_from_routine_id' => null, // Tareas libres (fuera de rutina)
        'created_at' => now(),
        'updated_at' => now()
    ],
    [
        'task_id' => $t5,
        'user_id' => null, // Bolsa de trabajo
        'status' => 'pending',
        'started_at_mins' => null,
        'expected_end_time_mins' => null,
        'completed_at_mins' => null,
        'assigned_from_routine_id' => null,
        'created_at' => now(),
        'updated_at' => now()
    ]
]);

echo "Bolsa de trabajo poblada con éxito!";
