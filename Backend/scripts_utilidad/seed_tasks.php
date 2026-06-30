<?php

use Illuminate\Support\Facades\DB;

// Tareas
$t1 = DB::table('tasks')->insertGetId([
    'title' => 'Limpieza de Estaciones de Trabajo',
    'estimated_mins' => 20,
    'priority' => 'normal',
    'category' => 'operativo',
    'target_type' => 'role',
    'target_id' => 1,
    'assistant_type' => 'evidencia_foto',
    'assistant_prompt' => 'Toma una foto de tu estación limpia y ordenada.',
    'is_auto_capture' => true,
    'created_at' => now(),
    'updated_at' => now()
]);

$t2 = DB::table('tasks')->insertGetId([
    'title' => 'Arqueo de Caja',
    'estimated_mins' => 15,
    'priority' => 'bloqueante',
    'category' => 'administrativo',
    'target_type' => 'role',
    'target_id' => 2,
    'assistant_type' => 'captura_numero',
    'assistant_prompt' => 'Ingresa el total en caja exacto.',
    'is_auto_capture' => false,
    'created_at' => now(),
    'updated_at' => now()
]);

$t3 = DB::table('tasks')->insertGetId([
    'title' => 'Revisión de Inventario General',
    'estimated_mins' => 45,
    'priority' => 'normal',
    'category' => 'operativo',
    'target_type' => 'role',
    'target_id' => 1,
    'assistant_type' => 'ninguno',
    'assistant_prompt' => '',
    'is_auto_capture' => true,
    'created_at' => now(),
    'updated_at' => now()
]);

// Rutinas
$r1 = DB::table('routines')->insertGetId([
    'title' => 'Rutina de Apertura de Tienda',
    'target_role_id' => 1,
    'trigger' => 'apertura',
    'assign_mode' => 'equitativo',
    'created_at' => now(),
    'updated_at' => now()
]);

$r2 = DB::table('routines')->insertGetId([
    'title' => 'Cierre de Caja y Tienda',
    'target_role_id' => 2,
    'trigger' => 'cierre',
    'assign_mode' => 'fijo',
    'created_at' => now(),
    'updated_at' => now()
]);

// Relación Rutina-Tarea
DB::table('routine_task')->insert([
    ['routine_id' => $r1, 'task_id' => $t1, 'created_at' => now(), 'updated_at' => now()],
    ['routine_id' => $r2, 'task_id' => $t2, 'created_at' => now(), 'updated_at' => now()],
    ['routine_id' => $r1, 'task_id' => $t3, 'created_at' => now(), 'updated_at' => now()]
]);

echo "Seeded successfully!";

