<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\JobRole;
use App\Models\Task;
use App\Models\Routine;

class DecorarteTasksSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = 1; // DecorArte 360

        // 1. Limpiar tareas y rutinas viejas para el tenant 1
        // Para evitar errores de integridad referencial, borramos en orden
        $routineIds = DB::table('routines')->where('tenant_id', $tenantId)->pluck('id');
        DB::table('routine_task')->whereIn('routine_id', $routineIds)->delete();
        DB::table('task_assignments')->where('tenant_id', $tenantId)->delete();
        DB::table('routines')->where('tenant_id', $tenantId)->delete();
        DB::table('tasks')->where('tenant_id', $tenantId)->delete();

        // 2. Buscar roles de DecorArte 360
        $roleAyudante = JobRole::where('tenant_id', $tenantId)->where('name', 'like', '%Ayudante%')->first();
        $roleAtencion = JobRole::where('tenant_id', $tenantId)->where('name', 'like', '%Atención%')->first();
        $roleSupervisorVentas = JobRole::where('tenant_id', $tenantId)->where('name', 'like', '%Ventas%')->first();
        $roleGerente = JobRole::where('tenant_id', $tenantId)->where('name', 'like', '%Gerente%')->first();

        // Fallbacks por si acaso
        $idAyudante = $roleAyudante ? $roleAyudante->id : 16;
        $idAtencion = $roleAtencion ? $roleAtencion->id : 15;
        $idSupervisor = $roleSupervisorVentas ? $roleSupervisorVentas->id : 13;
        $idGerente = $roleGerente ? $roleGerente->id : 11;

        // --- RUTINAS PARA AYUDANTE INTEGRAL ---

        // A. Rutina de Apertura (Ayudante)
        $routineAperturaAyudante = Routine::create([
            'title' => 'Rutina de Apertura (Ayudante)',
            'target_role_id' => $idAyudante,
            'trigger' => 'on_checkin',
            'assign_mode' => 'checklist',
            'tenant_id' => $tenantId
        ]);

        $tasksAperturaAyudante = [
            [
                'title' => 'Levantar las cortinas de la entrada y quitar candados',
                'mins' => 10,
                'priority' => 'normal',
                'time' => '09:00',
                'validation' => 'auto'
            ],
            [
                'title' => 'Abrir la puerta principal y colocar la rampa de acceso',
                'mins' => 5,
                'priority' => 'bloqueante',
                'time' => '09:00',
                'validation' => 'forced'
            ],
            [
                'title' => 'Sacar los tapetes de bienvenida y colocarlos en la entrada',
                'mins' => 5,
                'priority' => 'normal',
                'time' => '09:05',
                'validation' => 'auto'
            ],
            [
                'title' => 'Barrer la banqueta exterior y limpiar fachada frontal',
                'mins' => 15,
                'priority' => 'normal',
                'time' => '09:10',
                'validation' => 'auto'
            ]
        ];

        foreach ($tasksAperturaAyudante as $t) {
            $task = Task::create([
                'title' => $t['title'],
                'estimated_mins' => $t['mins'],
                'priority' => $t['priority'],
                'category' => 'operativo',
                'target_type' => 'role',
                'target_id' => $idAyudante,
                'assistant_type' => 'ninguno',
                'validation_mode' => $t['validation'],
                'scheduled_time' => $t['time'],
                'tenant_id' => $tenantId
            ]);
            $routineAperturaAyudante->tasks()->attach($task->id);
        }

        // B. Rutina de Operación Mañana (Ayudante)
        $routineMananaAyudante = Routine::create([
            'title' => 'Rutina de Operación Mañana (Ayudante)',
            'target_role_id' => $idAyudante,
            'trigger' => 'on_checkin',
            'assign_mode' => 'checklist',
            'tenant_id' => $tenantId
        ]);

        $tasksMananaAyudante = [
            [
                'title' => 'Limpieza de las vitrinas frontales de exhibición principal',
                'mins' => 20,
                'priority' => 'normal',
                'time' => '09:30',
                'validation' => 'auto'
            ],
            [
                'title' => 'Lavar los tapetes de bienvenida de la entrada con hidrolavadora',
                'mins' => 15,
                'priority' => 'normal',
                'time' => '10:30',
                'validation' => 'auto'
            ]
        ];

        foreach ($tasksMananaAyudante as $t) {
            $task = Task::create([
                'title' => $t['title'],
                'estimated_mins' => $t['mins'],
                'priority' => $t['priority'],
                'category' => 'operativo',
                'target_type' => 'role',
                'target_id' => $idAyudante,
                'assistant_type' => 'ninguno',
                'validation_mode' => $t['validation'],
                'scheduled_time' => $t['time'],
                'tenant_id' => $tenantId
            ]);
            $routineMananaAyudante->tasks()->attach($task->id);
        }

        // C. Rutina de Tarde (Ayudante)
        $routineTardeAyudante = Routine::create([
            'title' => 'Rutina de Tarde y Limpieza (Ayudante)',
            'target_role_id' => $idAyudante,
            'trigger' => 'on_checkin',
            'assign_mode' => 'checklist',
            'tenant_id' => $tenantId
        ]);

        $tasksTardeAyudante = [
            [
                'title' => 'Trapear los pasillos principales y áreas de exhibición',
                'mins' => 20,
                'priority' => 'normal',
                'time' => '13:00',
                'validation' => 'auto'
            ],
            [
                'title' => 'Lavar y desinfectar el baño de clientes y personal',
                'mins' => 25,
                'priority' => 'bloqueante',
                'time' => '14:00',
                'validation' => 'forced'
            ],
            [
                'title' => 'Limpiar las escaleras interiores y sacudir pasamanos',
                'mins' => 15,
                'priority' => 'normal',
                'time' => '16:00',
                'validation' => 'auto'
            ],
            [
                'title' => 'Limpiar el patio de servicio y ordenar contenedores de mermas',
                'mins' => 15,
                'priority' => 'normal',
                'time' => '17:00',
                'validation' => 'auto'
            ]
        ];

        foreach ($tasksTardeAyudante as $t) {
            $task = Task::create([
                'title' => $t['title'],
                'estimated_mins' => $t['mins'],
                'priority' => $t['priority'],
                'category' => 'operativo',
                'target_type' => 'role',
                'target_id' => $idAyudante,
                'assistant_type' => 'ninguno',
                'validation_mode' => $t['validation'],
                'scheduled_time' => $t['time'],
                'tenant_id' => $tenantId
            ]);
            $routineTardeAyudante->tasks()->attach($task->id);
        }

        // D. Rutina de Cierre (Ayudante)
        $routineCierreAyudante = Routine::create([
            'title' => 'Rutina de Cierre (Ayudante)',
            'target_role_id' => $idAyudante,
            'trigger' => 'on_checkin',
            'assign_mode' => 'checklist',
            'tenant_id' => $tenantId
        ]);

        $tasksCierreAyudante = [
            [
                'title' => 'Guardar tapetes de entrada, cerrar rampa y candados',
                'mins' => 10,
                'priority' => 'bloqueante',
                'time' => '19:50',
                'validation' => 'forced'
            ]
        ];

        foreach ($tasksCierreAyudante as $t) {
            $task = Task::create([
                'title' => $t['title'],
                'estimated_mins' => $t['mins'],
                'priority' => $t['priority'],
                'category' => 'operativo',
                'target_type' => 'role',
                'target_id' => $idAyudante,
                'assistant_type' => 'ninguno',
                'validation_mode' => $t['validation'],
                'scheduled_time' => $t['time'],
                'tenant_id' => $tenantId
            ]);
            $routineCierreAyudante->tasks()->attach($task->id);
        }


        // --- RUTINAS PARA ATENCIÓN AL CLIENTE ---

        $routineAtencion = Routine::create([
            'title' => 'Rutina de Exhibición y Mostrador',
            'target_role_id' => $idAtencion,
            'trigger' => 'on_checkin',
            'assign_mode' => 'checklist',
            'tenant_id' => $tenantId
        ]);

        $tasksAtencion = [
            [
                'title' => 'Limpieza fina de mostrador y desinfectar terminales punto de venta',
                'mins' => 10,
                'priority' => 'normal',
                'time' => '09:00',
                'validation' => 'auto'
            ],
            [
                'title' => 'Revisión general de productos exhibidos para verificar faltantes',
                'mins' => 20,
                'priority' => 'normal',
                'time' => '10:00',
                'validation' => 'auto'
            ],
            [
                'title' => 'Rellenar las góndolas y acomodar mercancía (Frenteo y orden)',
                'mins' => 30,
                'priority' => 'normal',
                'time' => '11:30',
                'validation' => 'auto'
            ],
            [
                'title' => 'Verificar pedidos especiales del día y validarlos con el sistema',
                'mins' => 15,
                'priority' => 'normal',
                'time' => '15:00',
                'validation' => 'auto'
            ],
            [
                'title' => 'Limpieza fina de estanterías y productos destacados',
                'mins' => 20,
                'priority' => 'normal',
                'time' => '16:30',
                'validation' => 'auto'
            ]
        ];

        foreach ($tasksAtencion as $t) {
            $task = Task::create([
                'title' => $t['title'],
                'estimated_mins' => $t['mins'],
                'priority' => $t['priority'],
                'category' => 'operativo',
                'target_type' => 'role',
                'target_id' => $idAtencion,
                'assistant_type' => 'ninguno',
                'validation_mode' => $t['validation'],
                'scheduled_time' => $t['time'],
                'tenant_id' => $tenantId
            ]);
            $routineAtencion->tasks()->attach($task->id);
        }


        // --- RUTINAS PARA SUPERVISOR DE VENTAS / GERENTE ---

        $routineSupervisor = Routine::create([
            'title' => 'Rutina de Supervisión y Control',
            'target_role_id' => $idSupervisor,
            'trigger' => 'on_checkin',
            'assign_mode' => 'checklist',
            'tenant_id' => $tenantId
        ]);

        $tasksSupervisor = [
            [
                'title' => 'Verificar el grupo de trabajo del día y confirmar asistencia',
                'mins' => 10,
                'priority' => 'normal',
                'time' => '09:15',
                'validation' => 'auto'
            ],
            [
                'title' => 'Revisar si hay pedidos de clientes pendientes de procesar',
                'mins' => 15,
                'priority' => 'normal',
                'time' => '10:00',
                'validation' => 'auto'
            ],
            [
                'title' => 'Realizar inventario rotativo (conteo de productos de alta rotación)',
                'mins' => 30,
                'priority' => 'normal',
                'time' => '13:30',
                'validation' => 'auto'
            ],
            [
                'title' => 'Realizar ajustes y transferencias en el sistema de ventas',
                'mins' => 20,
                'priority' => 'normal',
                'time' => '18:00',
                'validation' => 'auto'
            ],
            [
                'title' => 'Validar cierres parciales de ventas, arqueos y depósitos',
                'mins' => 15,
                'priority' => 'bloqueante',
                'time' => '19:30',
                'validation' => 'forced'
            ]
        ];

        foreach ($tasksSupervisor as $t) {
            $task = Task::create([
                'title' => $t['title'],
                'estimated_mins' => $t['mins'],
                'priority' => $t['priority'],
                'category' => 'supervision',
                'target_type' => 'role',
                'target_id' => $idSupervisor,
                'assistant_type' => 'ninguno',
                'validation_mode' => $t['validation'],
                'scheduled_time' => $t['time'],
                'tenant_id' => $tenantId
            ]);
            $routineSupervisor->tasks()->attach($task->id);
        }

        // --- TAREAS SUELTAS EN LA BOLSA DE TRABAJO (POOL) ---
        // Estas tareas no están en rutinas, están libres en la bolsa para cualquiera

        $tasksBolsa = [
            [
                'title' => 'Sacudir polvo acumulado en estanterías de bodega trasera',
                'mins' => 15,
                'priority' => 'normal',
                'role' => $idAyudante
            ],
            [
                'title' => 'Acomodar y clasificar cajas vacías en el área de reciclaje',
                'mins' => 20,
                'priority' => 'normal',
                'role' => $idAyudante
            ],
            [
                'title' => 'Apoyo en el etiquetado de precios de nueva colección',
                'mins' => 25,
                'priority' => 'normal',
                'role' => $idAtencion
            ]
        ];

        foreach ($tasksBolsa as $t) {
            Task::create([
                'title' => $t['title'],
                'estimated_mins' => $t['mins'],
                'priority' => $t['priority'],
                'category' => 'operativo',
                'target_type' => 'role',
                'target_id' => $t['role'],
                'assistant_type' => 'ninguno',
                'validation_mode' => 'auto',
                'tenant_id' => $tenantId
            ]);
        }
    }
}
