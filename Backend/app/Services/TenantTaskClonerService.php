<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use App\Models\JobRole;
use App\Models\Task;
use App\Models\Routine;
use Illuminate\Support\Str;

class TenantTaskClonerService
{
    /**
     * Clona puestos, tareas, rutinas y relaciones de un tenant origen a un tenant destino.
     * Si el tenant destino no existe, lo crea automáticamente.
     */
    public function cloneTasksAndRoutines($sourceTenantIdentifier, $targetTenantIdentifier): array
    {
        return DB::transaction(function () use ($sourceTenantIdentifier, $targetTenantIdentifier) {
            // 1. Resolver Tenant Origen
            $sourceTenant = is_numeric($sourceTenantIdentifier)
                ? Tenant::find($sourceTenantIdentifier)
                : Tenant::where('name', 'like', "%{$sourceTenantIdentifier}%")->orWhere('subdomain', $sourceTenantIdentifier)->first();

            if (!$sourceTenant) {
                throw new \InvalidArgumentException("No se encontró el tenant origen: {$sourceTenantIdentifier}");
            }

            // 2. Resolver o Crear Tenant Destino
            $targetTenant = null;
            if (is_numeric($targetTenantIdentifier)) {
                $targetTenant = Tenant::find($targetTenantIdentifier);
            } else {
                $cleanTarget = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $targetTenantIdentifier));
                $allTenants = Tenant::all();
                $targetTenant = $allTenants->first(function ($t) use ($targetTenantIdentifier, $cleanTarget) {
                    $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $t->name));
                    return $t->id == $targetTenantIdentifier
                        || $cleanName === $cleanTarget
                        || (str_contains($cleanName, 'decorarte') && (str_contains($cleanName, 'sa') || str_contains($cleanName, 'cv')))
                        || $t->subdomain === Str::slug($targetTenantIdentifier)
                        || $t->public_slug === Str::slug($targetTenantIdentifier);
                });
            }

            if (!$targetTenant) {
                $slug = Str::slug(is_string($targetTenantIdentifier) ? $targetTenantIdentifier : "tenant-{$targetTenantIdentifier}");
                if (empty($slug)) {
                    $slug = 'decorarte-sadcv';
                }

                $targetTenant = Tenant::create([
                    'name' => is_string($targetTenantIdentifier) ? $targetTenantIdentifier : 'DecorArte SA de CV',
                    'subdomain' => $slug,
                    'public_slug' => $slug,
                    'plan' => 'enterprise',
                    'brand_color' => '#8b5cf6',
                    'logo_url' => 'https://decorarte360.com/logo.png',
                    'subscription_status' => 'active',
                    'trial_ends_at' => now()->addDays(30),
                    'is_active' => true,
                ]);
            }

            $sourceId = $sourceTenant->id;
            $targetId = $targetTenant->id;

            if ($sourceId === $targetId) {
                throw new \InvalidArgumentException("El tenant origen y destino no pueden ser el mismo (ID: {$sourceId}).");
            }

            // 3. Mapear y Clonar JobRoles (Puestos de Trabajo)
            $sourceRoles = JobRole::withoutGlobalScopes()->where('tenant_id', $sourceId)->get();
            $targetRoles = JobRole::withoutGlobalScopes()->where('tenant_id', $targetId)->get();
            $roleMap = []; // [source_role_id => target_role_id]

            $normalizeName = function ($name) {
                return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name ?? ''));
            };

            foreach ($sourceRoles as $sRole) {
                $sNorm = $normalizeName($sRole->name);
                $existingTargetRole = $targetRoles->first(function ($tR) use ($sNorm, $normalizeName) {
                    return $normalizeName($tR->name) === $sNorm;
                });

                if ($existingTargetRole) {
                    $roleMap[$sRole->id] = $existingTargetRole->id;
                } else {
                    $roleData = $sRole->toArray();
                    unset($roleData['id'], $roleData['created_at'], $roleData['updated_at'], $roleData['deleted_at']);
                    $roleData['tenant_id'] = $targetId;
                    $roleData['reports_to_role_id'] = null;
                    $roleData['org_parent_role_id'] = null;
                    $roleData['reports_to_role_ids'] = null;

                    $newRole = JobRole::withoutGlobalScopes()->create($roleData);
                    $roleMap[$sRole->id] = $newRole->id;
                }
            }

            // Mapear jerarquías en puestos creados
            foreach ($sourceRoles as $sRole) {
                if (isset($roleMap[$sRole->id]) && ($sRole->reports_to_role_id || $sRole->org_parent_role_id)) {
                    $targetRole = JobRole::withoutGlobalScopes()->find($roleMap[$sRole->id]);
                    if ($targetRole) {
                        $targetRole->reports_to_role_id = $sRole->reports_to_role_id ? ($roleMap[$sRole->reports_to_role_id] ?? null) : null;
                        $targetRole->org_parent_role_id = $sRole->org_parent_role_id ? ($roleMap[$sRole->org_parent_role_id] ?? null) : null;
                        
                        if ($sRole->reports_to_role_ids && is_array($sRole->reports_to_role_ids)) {
                            $mappedIds = array_filter(array_map(fn($id) => $roleMap[$id] ?? null, $sRole->reports_to_role_ids));
                            $targetRole->reports_to_role_ids = array_values($mappedIds);
                        }

                        $targetRole->save();
                    }
                }
            }

            // 4. Limpiar tareas y rutinas anteriores en el tenant destino para evitar duplicados en ejecuciones repetidas
            $existingTargetRoutineIds = Routine::withoutGlobalScopes()->where('tenant_id', $targetId)->pluck('id');
            if ($existingTargetRoutineIds->count() > 0) {
                DB::table('routine_task')->whereIn('routine_id', $existingTargetRoutineIds)->delete();
            }
            Routine::withoutGlobalScopes()->where('tenant_id', $targetId)->forceDelete();
            Task::withoutGlobalScopes()->where('tenant_id', $targetId)->forceDelete();

            // 5. Clonar Tareas (asegurando títulos únicos)
            $sourceTasks = DB::table('tasks')
                ->where('tenant_id', $sourceId)
                ->whereNull('deleted_at')
                ->get()
                ->unique(fn($t) => trim(mb_strtolower($t->title)));

            $taskMap = []; // [source_task_id => target_task_id]

            foreach ($sourceTasks as $sTask) {
                $taskData = (array) $sTask;
                unset($taskData['id']);
                
                $taskData['tenant_id'] = $targetId;
                $taskData['created_at'] = now();
                $taskData['updated_at'] = now();

                if (($taskData['target_type'] ?? '') === 'role' && !empty($taskData['target_id'])) {
                    $taskData['target_id'] = $roleMap[$taskData['target_id']] ?? $taskData['target_id'];
                }

                $newTaskId = DB::table('tasks')->insertGetId($taskData);
                $taskMap[$sTask->id] = $newTaskId;
            }

            // 6. Clonar Rutinas (asegurando títulos y roles únicos)
            $sourceRoutines = DB::table('routines')
                ->where('tenant_id', $sourceId)
                ->whereNull('deleted_at')
                ->get()
                ->unique(fn($r) => trim(mb_strtolower($r->title)) . '_' . $r->target_role_id);

            $routineMap = []; // [source_routine_id => target_routine_id]

            foreach ($sourceRoutines as $sRoutine) {
                $routineData = (array) $sRoutine;
                unset($routineData['id']);
                
                $routineData['tenant_id'] = $targetId;
                $routineData['created_at'] = now();
                $routineData['updated_at'] = now();

                if (!empty($routineData['target_role_id'])) {
                    $routineData['target_role_id'] = $roleMap[$routineData['target_role_id']] ?? $routineData['target_role_id'];
                }

                $newRoutineId = DB::table('routines')->insertGetId($routineData);
                $routineMap[$sRoutine->id] = $newRoutineId;
            }

            // 7. Re-asociar Pivote routine_task
            $sourcePivotRecords = DB::table('routine_task')
                ->whereIn('routine_id', array_keys($routineMap))
                ->get();

            $pivotInsertCount = 0;
            foreach ($sourcePivotRecords as $pivot) {
                $mappedRoutineId = $routineMap[$pivot->routine_id] ?? null;
                $mappedTaskId = $taskMap[$pivot->task_id] ?? null;

                if ($mappedRoutineId && $mappedTaskId) {
                    DB::table('routine_task')->insert([
                        'routine_id' => $mappedRoutineId,
                        'task_id' => $mappedTaskId,
                    ]);
                    $pivotInsertCount++;
                }
            }

            // Invalida el caché de configuración del tenant para que /sync/state refresque las tareas y rutinas inmediatamente
            \App\Support\TenantConfigCache::forget($targetId);
            \App\Support\TenantConfigCache::forget($sourceId);

            return [
                'source_tenant_id' => $sourceId,
                'source_tenant_name' => $sourceTenant->name,
                'target_tenant_id' => $targetId,
                'target_tenant_name' => $targetTenant->name,
                'roles_mapped' => count($roleMap),
                'tasks_cloned' => count($taskMap),
                'routines_cloned' => count($routineMap),
                'routine_task_relations' => $pivotInsertCount,
            ];
        });
    }
}
