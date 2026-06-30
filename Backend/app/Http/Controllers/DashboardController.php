<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

            $today = \Carbon\Carbon::today()->toDateString();

            // Retardos del Día (Check-ins de hoy donde is_late es verdadero)
            $retardosHoy = DB::table('time_entries')
                ->where('tenant_id', $tenantId)
                ->where('date', $today)
                ->where('type', 'check_in')
                ->where('is_late', true)
                ->count();

            // Cumplimiento de Asistencia (colaboradores que hicieron Check-in hoy)
            $presentCount = DB::table('time_entries')
                ->where('tenant_id', $tenantId)
                ->where('date', $today)
                ->where('type', 'check_in')
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
                    'candidates_recent_activity' => $candidatesRecentActivity
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
