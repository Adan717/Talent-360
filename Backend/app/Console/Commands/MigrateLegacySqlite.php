<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateLegacySqlite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:migrate-legacy-sqlite';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate historical and legacy data from SQLite database to PostgreSQL (under Tenant Talent 360).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dbPath = database_path('database.sqlite');
        if (!file_exists($dbPath)) {
            $this->error("La base de datos SQLite no existe en: {$dbPath}");
            return 1;
        }

        $this->info("Iniciando conexión con SQLite: {$dbPath}");
        try {
            $sqlite = new \PDO("sqlite:{$dbPath}");
            $sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            $this->error("Error al conectar con SQLite: " . $e->getMessage());
            return 1;
        }

        // 1. Obtener el tenant Talent 360
        $tenant = DB::table('tenants')->where('subdomain', 'talent360')->first();
        if (!$tenant) {
            $this->error("No se encontró el tenant Talent 360 (subdominio 'talent360') en PostgreSQL.");
            return 1;
        }
        $tenantId = $tenant->id;
        $this->info("Tenant de destino encontrado: '{$tenant->name}' (ID: {$tenantId})");

        // 2. Limpiar registros anteriores del tenant en PostgreSQL para evitar duplicados
        $this->warn("Limpiando registros antiguos del Tenant {$tenantId} en PostgreSQL...");
        
        Schema::disableForeignKeyConstraints();

        DB::table('routine_task')->whereExists(function ($query) use ($tenantId) {
            $query->select(DB::raw(1))
                  ->from('routines')
                  ->whereColumn('routine_task.routine_id', 'routines.id')
                  ->where('routines.tenant_id', $tenantId);
        })->delete();

        DB::table('task_assignments')->where('tenant_id', $tenantId)->delete();
        DB::table('routines')->where('tenant_id', $tenantId)->delete();
        DB::table('tasks')->where('tenant_id', $tenantId)->delete();
        DB::table('time_entries')->where('tenant_id', $tenantId)->delete();
        DB::table('store_logs')->where('tenant_id', $tenantId)->delete();
        DB::table('contingencies')->where('tenant_id', $tenantId)->delete();
        DB::table('internal_messages')->where('tenant_id', $tenantId)->delete();
        DB::table('permissions')->where('tenant_id', $tenantId)->delete();
        DB::table('users')->where('tenant_id', $tenantId)->delete();

        Schema::enableForeignKeyConstraints();
        
        // Drop PostgreSQL check constraints for type enums if we are on PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_type_check;");
            DB::statement("ALTER TABLE store_logs DROP CONSTRAINT IF EXISTS store_logs_type_check;");
        }

        $this->info("Tablas transaccionales limpiadas exitosamente.");


        // 3. Mapear Puestos (Job Roles)
        $this->info("\n--- Migrando y Mapeando Puestos (Job Roles) ---");
        $oldRoles = $sqlite->query("SELECT * FROM job_roles")->fetchAll(\PDO::FETCH_ASSOC);
        $roleMap = [];
        foreach ($oldRoles as $oldRole) {
            $newRole = DB::table('job_roles')
                ->where('tenant_id', $tenantId)
                ->where('name', $oldRole['name'])
                ->first();

            if (!$newRole) {
                $newRoleId = DB::table('job_roles')->insertGetId([
                    'name' => $oldRole['name'],
                    'description' => $oldRole['description'] ?? null,
                    'area' => $oldRole['area'] ?? 'General',
                    'esAperturador' => filter_var($oldRole['esAperturador'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'portadorLlaves' => $oldRole['portadorLlaves'] ?? 'ninguno',
                    'jerarquiaLlaves' => $oldRole['jerarquiaLlaves'] ?? 9,
                    'tiempoTolerancia' => $oldRole['tiempoTolerancia'] ?? 5,
                    'requiereJustificante' => filter_var($oldRole['requiereJustificante'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'puedeEmitirAvisos' => filter_var($oldRole['puedeEmitirAvisos'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'tenant_id' => $tenantId,
                    'created_at' => $oldRole['created_at'] ?? now(),
                    'updated_at' => $oldRole['updated_at'] ?? now(),
                ]);
                $roleMap[$oldRole['id']] = $newRoleId;
                $this->line("Puesto creado: '{$oldRole['name']}' con nuevo ID {$newRoleId}");
            } else {
                $roleMap[$oldRole['id']] = $newRole->id;
                $this->line("Puesto mapeado: '{$oldRole['name']}' -> ID existente {$newRole->id}");
            }
        }

        // 4. Migrar Permisos (Permissions)
        $this->info("\n--- Migrando Permisos ---");
        $oldPerms = $sqlite->query("SELECT * FROM permissions")->fetchAll(\PDO::FETCH_ASSOC);
        $permissionMap = [];
        foreach ($oldPerms as $perm) {
            $newPermId = DB::table('permissions')->insertGetId([
                'name' => $perm['name'],
                'description' => $perm['description'],
                'tenant_id' => $tenantId,
                'created_at' => $perm['created_at'] ?? now(),
                'updated_at' => $perm['updated_at'] ?? now(),
            ]);
            $permissionMap[$perm['id']] = $newPermId;
            $this->line("Permiso creado: '{$perm['name']}' (ID: {$newPermId})");
        }

        // 5. Migrar Colaboradores (Users)
        $this->info("\n--- Migrando Colaboradores (Users) ---");
        $oldUsers = $sqlite->query("SELECT * FROM users")->fetchAll(\PDO::FETCH_ASSOC);
        $userMap = [];
        foreach ($oldUsers as $oldUser) {
            $newRoleId = $roleMap[$oldUser['job_role_id']] ?? null;
            if (!$newRoleId) {
                // Asignar primer puesto disponible si no hay mapeo
                $newRoleId = reset($roleMap);
            }

            // Sanitizar email reemplazando @talent360.com o @talent360.com por @talent360.com para mantener uniformidad
            $email = $oldUser['email'];
            $email = str_replace(['@talent360.com', '@talent360.com'], '@talent360.com', $email);

            $newUserId = DB::table('users')->insertGetId([
                'name' => $oldUser['name'],
                'email' => $email,
                'email_verified_at' => $oldUser['email_verified_at'] ?? null,
                'password' => $oldUser['password'], // Se mantiene el Hash de SQLite
                'job_role_id' => $newRoleId,
                'avatar' => $oldUser['avatar'] ?? null,
                'shiftStart' => $oldUser['shiftStart'] ?? '08:00:00',
                'shiftEnd' => $oldUser['shiftEnd'] ?? '18:00:00',
                'mealMinutes' => $oldUser['mealMinutes'] ?? 60,
                'restDay' => $oldUser['restDay'] ?? 'Domingo',
                'reliefBuddyId' => null, // Se mapeará después
                'remember_token' => $oldUser['remember_token'] ?? null,
                'tenant_id' => $tenantId,
                'created_at' => $oldUser['created_at'] ?? now(),
                'updated_at' => $oldUser['updated_at'] ?? now(),
            ]);

            $userMap[$oldUser['id']] = $newUserId;
            $this->line("Colaborador migrado: '{$oldUser['name']}' (Email: {$email}) -> Nuevo ID {$newUserId}");
        }

        // Mapeo retroactivo de reliefBuddyId
        foreach ($oldUsers as $oldUser) {
            if (!empty($oldUser['reliefBuddyId']) && isset($userMap[$oldUser['reliefBuddyId']])) {
                $newBuddyId = $userMap[$oldUser['reliefBuddyId']];
                $newUserId = $userMap[$oldUser['id']];
                DB::table('users')->where('id', $newUserId)->update(['reliefBuddyId' => $newBuddyId]);
            }
        }
        $this->info("Mapeo de suplentes/compañeros de relevo completado.");

        // 6. Migrar Historial de Tienda (Store Logs)
        $this->info("\n--- Migrando Bitácoras de Tienda (Store Logs) ---");
        $oldStoreLogs = $sqlite->query("SELECT * FROM store_logs")->fetchAll(\PDO::FETCH_ASSOC);
        $logsMigrated = 0;
        foreach ($oldStoreLogs as $log) {
            $newUserId = isset($log['user_id']) ? ($userMap[$log['user_id']] ?? null) : null;
            if (!$newUserId) continue;

            DB::table('store_logs')->insert([
                'user_id' => $newUserId,
                'date' => $log['date'],
                'type' => $log['type'],
                'time' => $log['time'],
                'notes' => $log['notes'] ?? null,
                'tenant_id' => $tenantId,
                'created_at' => $log['created_at'] ?? now(),
                'updated_at' => $log['updated_at'] ?? now(),
            ]);
            $logsMigrated++;
        }
        $this->info("Migrados {$logsMigrated} registros de bitácoras de tienda.");

        // 7. Migrar Contingencias (Contingencies)
        $this->info("\n--- Migrando Contingencias (Justificantes) ---");
        $oldContingencies = $sqlite->query("SELECT * FROM contingencies")->fetchAll(\PDO::FETCH_ASSOC);
        $contingenciesMigrated = 0;
        foreach ($oldContingencies as $contingency) {
            $newUserId = isset($contingency['user_id']) ? ($userMap[$contingency['user_id']] ?? null) : null;
            $newResolvedBy = isset($contingency['resolved_by']) ? ($userMap[$contingency['resolved_by']] ?? null) : null;

            if (!$newUserId) continue;

            DB::table('contingencies')->insert([
                'user_id' => $newUserId,
                'type' => $contingency['type'],
                'status' => $contingency['status'] ?? 'pending',
                'justification_text' => $contingency['justification_text'] ?? null,
                'resolved_by' => $newResolvedBy,
                'tenant_id' => $tenantId,
                'created_at' => $contingency['created_at'] ?? now(),
                'updated_at' => $contingency['updated_at'] ?? now(),
            ]);
            $contingenciesMigrated++;
        }
        $this->info("Migrados {$contingenciesMigrated} justificantes de faltas/retardos.");

        // 8. Migrar Mensajes Internos (Internal Messages)
        $this->info("\n--- Migrando Mensajes Internos ---");
        $oldMessages = $sqlite->query("SELECT * FROM internal_messages")->fetchAll(\PDO::FETCH_ASSOC);
        $messagesMigrated = 0;
        foreach ($oldMessages as $message) {
            $newSenderId = isset($message['sender_id']) ? ($userMap[$message['sender_id']] ?? null) : null;
            $newReceiverId = isset($message['receiver_id']) ? ($userMap[$message['receiver_id']] ?? null) : null;

            if (!$newSenderId) continue;

            DB::table('internal_messages')->insert([
                'sender_id' => $newSenderId,
                'receiver_id' => $newReceiverId,
                'type' => $message['type'],
                'content' => $message['content'],
                'tenant_id' => $tenantId,
                'created_at' => $message['created_at'] ?? now(),
                'updated_at' => $message['updated_at'] ?? now(),
            ]);
            $messagesMigrated++;
        }
        $this->info("Migrados {$messagesMigrated} mensajes internos.");

        // 9. Migrar Reloj Checador (Time Entries)
        $this->info("\n--- Migrando Entradas de Tiempo (Reloj Checador) ---");
        $oldEntries = $sqlite->query("SELECT * FROM time_entries")->fetchAll(\PDO::FETCH_ASSOC);
        $entriesMigrated = 0;
        foreach ($oldEntries as $entry) {
            $newUserId = isset($entry['user_id']) ? ($userMap[$entry['user_id']] ?? null) : null;
            if (!$newUserId) continue;

            DB::table('time_entries')->insert([
                'user_id' => $newUserId,
                'date' => $entry['date'],
                'type' => $entry['type'],
                'time' => $entry['time'],
                'is_late' => filter_var($entry['is_late'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'late_minutes' => $entry['late_minutes'] ?? 0,
                'details' => $entry['details'] ?? null,
                'tenant_id' => $tenantId,
                'created_at' => $entry['created_at'] ?? now(),
                'updated_at' => $entry['updated_at'] ?? now(),
            ]);
            $entriesMigrated++;
        }
        $this->info("Migrados {$entriesMigrated} registros de entradas del reloj.");

        // 10. Migrar Configuraciones de Sistema (System Settings)
        $this->info("\n--- Migrando Parámetros de Sistema ---");
        $oldSettings = $sqlite->query("SELECT * FROM system_settings")->fetchAll(\PDO::FETCH_ASSOC);
        $settingsMigrated = 0;
        foreach ($oldSettings as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $setting['key'], 'tenant_id' => $tenantId],
                [
                    'value' => $setting['value'],
                    'created_at' => $setting['created_at'] ?? now(),
                    'updated_at' => $setting['updated_at'] ?? now(),
                ]
            );
            $settingsMigrated++;
        }
        $this->info("Sincronizados {$settingsMigrated} parámetros de sistema.");

        // 11. Migrar Tareas y Rutinas
        $this->info("\n--- Migrando Tareas, Rutinas y Asignaciones ---");
        
        // Tareas
        $oldTasks = $sqlite->query("SELECT * FROM tasks")->fetchAll(\PDO::FETCH_ASSOC);
        $taskMap = [];
        foreach ($oldTasks as $task) {
            $newTargetId = $task['target_id'];
            if ($task['target_type'] === 'role' && isset($roleMap[$task['target_id']])) {
                $newTargetId = $roleMap[$task['target_id']];
            } elseif ($task['target_type'] === 'user' && isset($userMap[$task['target_id']])) {
                $newTargetId = $userMap[$task['target_id']];
            }

            $newTaskId = DB::table('tasks')->insertGetId([
                'title' => $task['title'],
                'estimated_mins' => $task['estimated_mins'] ?? 15,
                'priority' => $task['priority'] ?? 'normal',
                'category' => $task['category'] ?? 'operativo',
                'target_type' => $task['target_type'] ?? 'role',
                'target_id' => $newTargetId,
                'assistant_type' => $task['assistant_type'] ?? 'ninguno',
                'assistant_prompt' => $task['assistant_prompt'] ?? null,
                'is_auto_capture' => filter_var($task['is_auto_capture'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'tenant_id' => $tenantId,
                'created_at' => $task['created_at'] ?? now(),
                'updated_at' => $task['updated_at'] ?? now(),
            ]);
            $taskMap[$task['id']] = $newTaskId;
        }
        $this->line("Migradas " . count($taskMap) . " tareas operativas.");

        // Rutinas
        $oldRoutines = $sqlite->query("SELECT * FROM routines")->fetchAll(\PDO::FETCH_ASSOC);
        $routineMap = [];
        foreach ($oldRoutines as $routine) {
            $newTargetRoleId = isset($routine['target_role_id']) ? ($roleMap[$routine['target_role_id']] ?? null) : null;

            $newRoutineId = DB::table('routines')->insertGetId([
                'title' => $routine['title'],
                'target_role_id' => $newTargetRoleId,
                'trigger' => $routine['trigger'] ?? 'apertura',
                'assign_mode' => $routine['assign_mode'] ?? 'equitativo',
                'tenant_id' => $tenantId,
                'created_at' => $routine['created_at'] ?? now(),
                'updated_at' => $routine['updated_at'] ?? now(),
            ]);
            $routineMap[$routine['id']] = $newRoutineId;
        }
        $this->line("Migradas " . count($routineMap) . " rutinas de trabajo.");

        // Relaciones Routine Task
        $oldRoutineTasks = $sqlite->query("SELECT * FROM routine_task")->fetchAll(\PDO::FETCH_ASSOC);
        $rtCount = 0;
        foreach ($oldRoutineTasks as $rt) {
            $newRoutineId = $routineMap[$rt['routine_id']] ?? null;
            $newTaskId = $taskMap[$rt['task_id']] ?? null;

            if ($newRoutineId && $newTaskId) {
                DB::table('routine_task')->insert([
                    'routine_id' => $newRoutineId,
                    'task_id' => $newTaskId,
                    'created_at' => $rt['created_at'] ?? now(),
                    'updated_at' => $rt['updated_at'] ?? now(),
                ]);
                $rtCount++;
            }
        }
        $this->line("Establecidas {$rtCount} relaciones entre rutinas y tareas.");

        // Asignaciones de Tareas (Task Assignments)
        $oldAssignments = $sqlite->query("SELECT * FROM task_assignments")->fetchAll(\PDO::FETCH_ASSOC);
        $assignmentsCount = 0;
        foreach ($oldAssignments as $assign) {
            $newTaskId = $taskMap[$assign['task_id']] ?? null;
            $newUserId = isset($assign['user_id']) ? ($userMap[$assign['user_id']] ?? null) : null;
            $newAssignedRoutineId = isset($assign['assigned_from_routine_id']) ? ($routineMap[$assign['assigned_from_routine_id']] ?? null) : null;

            if ($newTaskId) {
                DB::table('task_assignments')->insert([
                    'task_id' => $newTaskId,
                    'user_id' => $newUserId,
                    'status' => $assign['status'] ?? 'pending',
                    'started_at_mins' => $assign['started_at_mins'] ?? null,
                    'expected_end_time_mins' => $assign['expected_end_time_mins'] ?? null,
                    'completed_at_mins' => $assign['completed_at_mins'] ?? null,
                    'assigned_from_routine_id' => $newAssignedRoutineId,
                    'assistant_data' => $assign['assistant_data'] ?? null,
                    'tenant_id' => $tenantId,
                    'created_at' => $assign['created_at'] ?? now(),
                    'updated_at' => $assign['updated_at'] ?? now(),
                ]);
                $assignmentsCount++;
            }
        }
        $this->line("Migradas {$assignmentsCount} asignaciones históricas de tareas.");

        $this->info("\n==========================================");
        $this->info("¡MIGRACIÓN COMPLETADA CON ÉXITO!");
        $this->info("==========================================");
        return 0;
    }
}

