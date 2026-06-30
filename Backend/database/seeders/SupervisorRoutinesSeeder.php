<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupervisorRoutinesSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Checklist de Apertura
        $routineId1 = DB::table('routines')->insertGetId([
            'title' => 'Documento 17: Checklist Diario de Apertura',
            'target_role_id' => 2,
            'trigger' => 'apertura',
            'assign_mode' => 'fijo',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $tasksApertura = [
            'Desactivar alarma perimetral y encender switch principal',
            'Verificar funcionamiento de las luces del piso de ventas',
            'Realizar conteo del fondo de caja',
            'Encender equipos de refrigeración/clima',
            'Tomar foto de la fachada frontal limpia y despejada'
        ];

        foreach ($tasksApertura as $t) {
            $taskId = DB::table('tasks')->insertGetId([
                'title' => $t,
                'priority' => 'bloqueante',
                'target_type' => 'role',
                'target_id' => 2,
                'assistant_type' => str_contains($t, 'foto') ? 'evidencia_foto' : 'ninguno',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            DB::table('routine_task')->insert(['routine_id' => $routineId1, 'task_id' => $taskId]);
        }

        // 2. Checklist de Operación
        $routineId2 = DB::table('routines')->insertGetId([
            'title' => 'Documento 18: Checklist Diario de Operación',
            'target_role_id' => 2,
            'trigger' => 'hora_fija', // Se podría disparar a medio día
            'assign_mode' => 'fijo',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $tasksOperacion = [
            'Recorrer pasillos asegurando que el piso esté libre de cajas',
            'Alinear los precios en las etiquetas de los domos principales',
            'Validar que el personal esté portando el gafete y uniforme limpios',
            'Revisar stock de bolsas de empaque en las cajas'
        ];

        foreach ($tasksOperacion as $t) {
            $taskId = DB::table('tasks')->insertGetId([
                'title' => $t,
                'priority' => 'normal',
                'target_type' => 'role',
                'target_id' => 2,
                'assistant_type' => 'ninguno',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            DB::table('routine_task')->insert(['routine_id' => $routineId2, 'task_id' => $taskId]);
        }

        // 3. Checklist de Cierre
        $routineId3 = DB::table('routines')->insertGetId([
            'title' => 'Documento 19: Checklist Diario de Cierre',
            'target_role_id' => 2,
            'trigger' => 'cierre',
            'assign_mode' => 'fijo',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $tasksCierre = [
            'Ejecutar corte X y validar los retiros parciales (Arqueos)',
            'Hacer el cierre de terminales bancarias y adjuntar voucher',
            'Guardar el efectivo en la tómbola',
            'Apagar aires acondicionados y luces',
            'Activar alarma perimetral y asegurar puertas'
        ];

        foreach ($tasksCierre as $t) {
            $taskId = DB::table('tasks')->insertGetId([
                'title' => $t,
                'priority' => 'bloqueante',
                'target_type' => 'role',
                'target_id' => 2,
                'assistant_type' => 'ninguno',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            DB::table('routine_task')->insert(['routine_id' => $routineId3, 'task_id' => $taskId]);
        }
    }
}
