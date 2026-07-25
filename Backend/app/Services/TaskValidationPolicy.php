<?php

namespace App\Services;

use App\Models\JobRole;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Decide si la completación de una tarea debe pasar por validación de supervisor.
 *
 * Regla única compartida entre POST /sync/tasks (TaskSyncController) y
 * PUT /task-assignments/{id} (TaskAssignmentController), para que ninguna de las
 * dos puertas pueda saltarse la validación jerárquica que aplica la otra.
 */
class TaskValidationPolicy
{
    /**
     * @param  int        $tenantId
     * @param  int|null   $userId      Dueño de la assignment (null = pool).
     * @param  Task|null  $task        Tarea de la assignment.
     * @param  mixed $tasksConfig system_settings['tasksConfig'] del tenant; se
     *                            resuelve solo si se pasa null. Se normaliza a
     *                            array|null (un escalar JSON almacenado se trata como
     *                            "sin config") para no reventar con TypeError.
     */
    public static function requiresValidation(int $tenantId, $userId, ?Task $task, $tasksConfig = null): bool
    {
        if (!$task) {
            return false;
        }

        $user = $userId ? User::find($userId) : null;

        $reportsTo = false;
        if ($user && $user->employee && $user->employee->job_role_id) {
            $role = JobRole::find($user->employee->job_role_id);
            if ($role && $role->reports_to_role_id) {
                $reportsTo = true;
            }
        }

        $tenant = Tenant::find($tenantId);
        $supervisorUnlocked = $tenant ? $tenant->isFeatureUnlocked('supervisor_validation') : false;

        if (!($supervisorUnlocked && $reportsTo)) {
            return false;
        }

        if ($tasksConfig === null) {
            $raw = DB::table('system_settings')
                ->where('tenant_id', $tenantId)
                ->where('key', 'tasksConfig')
                ->value('value');
            $tasksConfig = $raw ? json_decode($raw, true) : null;
        }
        // Un escalar (bool/int/string) almacenado por error se trata como sin config.
        if (!is_array($tasksConfig)) {
            $tasksConfig = null;
        }

        $isBlocker = $task->priority === 'bloqueante';
        $mode = $task->validation_mode ?? 'forced';

        if ($mode === 'auto') {
            return false;
        }

        if ($mode === 'forced') {
            if ($tasksConfig && !empty($tasksConfig['requireSupervisorValidation'])) {
                $threshold = $tasksConfig['validationThreshold'] ?? 'all_tasks';
                if ($threshold === 'all_tasks') {
                    return true;
                }
                if ($threshold === 'blockers_only' && $isBlocker) {
                    return true;
                }
                return false;
            }
            return true;
        }

        if ($mode === 'dynamic') {
            $days = 0;
            if ($user && $user->employee && $user->employee->hire_date) {
                try {
                    $hireDate = \Carbon\Carbon::parse($user->employee->hire_date);
                    $days = abs(now()->diffInDays($hireDate));
                } catch (\Exception $ex) {
                    $days = 0;
                }
            }

            if ($days < 30) {
                return true;
            }
            if ($days < 90) {
                return mt_rand(1, 100) <= 50;
            }
            return mt_rand(1, 100) <= 15;
        }

        return false;
    }
}
