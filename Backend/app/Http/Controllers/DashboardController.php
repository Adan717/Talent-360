<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Helpers\TenantTimezone;

class DashboardController extends Controller
{
    public function getStats(Request $request)
    {
        try {
            $user = $request->user();
            $tenantId = $user ? $user->tenant_id : null;

            if (!$tenantId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tenant no identificado para el usuario autenticado.'
                ], 400);
            }

            $activeUsers = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('is_active_employee', true)
                ->count();
            
            $vacancies = DB::table('vacancies')
                ->where('tenant_id', $tenantId)
                ->count();

            $tasks = DB::table('tasks')
                ->where('tenant_id', $tenantId)
                ->count();

            $courses = DB::table('academy_courses')
                ->where('tenant_id', $tenantId)
                ->count();

            $today = \Carbon\Carbon::now(TenantTimezone::for($tenantId))->toDateString();

            // Retardos del Día (Check-ins de hoy donde is_late es verdadero)
            // whereNull('simulation_session_id'): nunca mezclar datos del Simulador Matrix
            // en las estadísticas reales del dashboard (ver sección 13 del contrato).
            $retardosHoy = DB::table('time_entries')
                ->where('tenant_id', $tenantId)
                ->where('date', $today)
                ->where('type', 'check_in')
                ->where('is_late', true)
                ->whereNull('simulation_session_id')
                ->count();

            // Cumplimiento de Asistencia (colaboradores que hicieron Check-in hoy)
            $presentCount = DB::table('time_entries')
                ->where('tenant_id', $tenantId)
                ->where('date', $today)
                ->where('type', 'check_in')
                ->whereNull('simulation_session_id')
                ->distinct('user_id')
                ->count('user_id');

            $cumplimiento = 100;
            if ($activeUsers > 0) {
                $cumplimiento = round(($presentCount / $activeUsers) * 100);
                if ($cumplimiento > 100) {
                    $cumplimiento = 100;
                }
            }

            $candidates = DB::table('candidates')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['prospect', 'reviewing', 'interviewing'])
                ->count();

            $candidatesRecentActivity = DB::table('candidates')
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '>=', \Carbon\Carbon::now()->subHours(24))
                ->exists();

            // Calcular salud de Empleados
            $clockedInUsers = [];
            $activeEmployeeIds = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('is_active_employee', true)
                ->pluck('user_id')
                ->filter()
                ->toArray();
                
            if (!empty($activeEmployeeIds)) {
                $todayEntries = DB::table('time_entries')
                    ->where('tenant_id', $tenantId)
                    ->where('date', $today)
                    ->whereIn('user_id', $activeEmployeeIds)
                    ->whereNull('simulation_session_id')
                    ->orderBy('id', 'asc')
                    ->get()
                    ->groupBy('user_id');
                    
                foreach ($todayEntries as $userId => $entries) {
                    $latest = $entries->last();
                    if ($latest->type === 'check_in' || $latest->type === 'meal_end') {
                        $clockedInUsers[] = $userId;
                    }
                }
            }
            
            $clockedInCount = count($clockedInUsers);
            $idleCount = 0;
            
            if ($clockedInCount > 0) {
                $activeUserIdsWithTasks = DB::table('task_assignments')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('user_id', $clockedInUsers)
                    ->where('status', 'in_progress')
                    ->where('date', $today)
                    ->pluck('user_id')
                    ->toArray();
                    
                $idleCount = count(array_diff($clockedInUsers, $activeUserIdsWithTasks));
            }
            
            if ($clockedInCount === 0) {
                $employeesHealth = 'gray';
            } elseif ($idleCount > 0) {
                $employeesHealth = 'red';
            } else {
                $employeesHealth = 'green';
            }

            // Calcular salud de Tareas
            $todayAssignments = DB::table('task_assignments')
                ->where('tenant_id', $tenantId)
                ->where('date', $today)
                ->select('status')
                ->get();
                
            $totalTodayAssignments = $todayAssignments->count();
            $completedTodayAssignments = $todayAssignments->where('status', 'completed')->count();
            $tasksPendingCount = $todayAssignments->whereIn('status', ['pending', 'in_progress', 'paused', 'awaiting_validation'])->count();
            
            if ($totalTodayAssignments === 0) {
                $tasksHealth = 'gray';
            } else {
                $completionRate = round(($completedTodayAssignments / $totalTodayAssignments) * 100);
                if ($completionRate >= 80) {
                    $tasksHealth = 'green';
                } elseif ($completionRate >= 40) {
                    $tasksHealth = 'amber';
                } else {
                    $tasksHealth = 'red';
                }
            }

            // Calcular salud de Prospectos
            if ($candidates === 0) {
                $prospectsHealth = 'gray';
            } else {
                $prospectsHealth = $candidatesRecentActivity ? 'green' : 'amber';
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'active_users' => $activeUsers,
                    'vacancies' => $vacancies,
                    'tasks' => $tasks,
                    'courses' => $courses,
                    'retardos_hoy' => $retardosHoy, 
                    'cumplimiento' => $cumplimiento,
                    'candidates_count' => $candidates,
                    'candidates_recent_activity' => $candidatesRecentActivity,
                    'employees_health' => $employeesHealth,
                    'employees_clocked_in_count' => $clockedInCount,
                    'employees_idle_count' => $idleCount,
                    'tasks_health' => $tasksHealth,
                    'tasks_pending_count' => $tasksPendingCount,
                    'tasks_completed_count' => $completedTodayAssignments,
                    'tasks_total_today' => $totalTodayAssignments,
                    'prospects_health' => $prospectsHealth,
                    'prospects_count' => $candidates
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
