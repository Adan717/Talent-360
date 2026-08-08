<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reportes básicos (CSV) — ronda 2026-08-08.
 *
 * La pestaña "Reportes Básicos (Gratis)" ofrecía dos descargas, "Asistencia del Día" y
 * "Tareas Completadas", con botones que NO TENÍAN handler: no bajaban nada y tampoco
 * avisaban. Eran decoración. Aquí están los dos reportes de verdad.
 *
 * Van en su propio grupo de rutas, SIN `permission:manage_payroll`: no traen ni un dato
 * salarial (asistencia y tareas), así que exigir la capacidad de nómina para bajarlos
 * sería pedir de más — el candado del dinero es para el dinero.
 *
 * CSV a mano con `fputcsv` en streaming: nada de cargar todo en memoria ni de sumar una
 * dependencia para separar por comas. Lleva BOM UTF-8 porque Excel en español abre el
 * archivo con los acentos rotos sin él (Nómina → NÃ³mina), y es el primer reporte que ve
 * el cliente.
 */
class ReportesBasicosController extends Controller
{
    /** Asistencia del día: entradas, salidas y retardos de HOY (día del tenant). */
    public function asistenciaDelDia(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $tz = TenantTimezone::for($tenantId);
        $fecha = $request->query('date', Carbon::now($tz)->toDateString());

        $entradas = DB::table('time_entries')
            ->leftJoin('users', 'users.id', '=', 'time_entries.user_id')
            ->where('time_entries.tenant_id', $tenantId)
            ->where('time_entries.date', $fecha)
            // Los fichajes del Simulador Matrix NUNCA se mezclan con un reporte real.
            ->whereNull('time_entries.simulation_session_id')
            ->orderBy('users.name')
            ->orderBy('time_entries.time')
            ->select(
                'time_entries.employee_name_at_time',
                'users.name as user_name',
                'time_entries.job_role_title_at_time',
                'time_entries.type',
                'time_entries.time',
                'time_entries.is_late',
                'time_entries.late_minutes'
            )
            ->get();

        $etiquetas = [
            'check_in' => 'Entrada',
            'check_out' => 'Salida',
            'meal_start' => 'Inicio de comida',
            'meal_end' => 'Fin de comida',
            'break_start' => 'Inicio de descanso',
            'break_end' => 'Fin de descanso',
        ];

        $filas = $entradas->map(fn ($e) => [
            $e->employee_name_at_time ?: ($e->user_name ?: 'Sin nombre'),
            $e->job_role_title_at_time ?: 'Sin puesto',
            $etiquetas[$e->type] ?? $e->type,
            substr((string) $e->time, 0, 5),
            $e->is_late ? 'Sí' : 'No',
            (int) $e->late_minutes,
        ])->all();

        return $this->csv(
            "asistencia_{$fecha}.csv",
            ['Colaborador', 'Puesto', 'Movimiento', 'Hora', '¿Retardo?', 'Minutos de retardo'],
            $filas
        );
    }

    /** Tareas completadas en un rango (por defecto, los últimos 30 días del tenant). */
    public function tareasCompletadas(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $tz = TenantTimezone::for($tenantId);

        $hasta = $request->query('to', Carbon::now($tz)->toDateString());
        $desde = $request->query('from', Carbon::parse($hasta, $tz)->subDays(29)->toDateString());

        $tareas = DB::table('task_assignments')
            ->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
            ->leftJoin('users', 'users.id', '=', 'task_assignments.user_id')
            ->where('tasks.tenant_id', $tenantId)
            ->where('task_assignments.status', 'completed')
            ->whereNull('task_assignments.deleted_at')
            ->whereBetween('task_assignments.date', [$desde, $hasta])
            ->orderBy('task_assignments.date')
            ->orderBy('users.name')
            ->select(
                'task_assignments.date',
                'users.name as user_name',
                'tasks.title',
                'tasks.priority',
                'tasks.estimated_mins',
                'task_assignments.accumulated_mins',
                'task_assignments.points_awarded'
            )
            ->get();

        $filas = $tareas->map(fn ($t) => [
            $t->date,
            $t->user_name ?: 'Sin colaborador',
            $t->title,
            $t->priority,
            (int) $t->estimated_mins,
            (int) $t->accumulated_mins,
            (int) $t->points_awarded,
        ])->all();

        return $this->csv(
            "tareas_completadas_{$desde}_a_{$hasta}.csv",
            ['Fecha', 'Colaborador', 'Tarea', 'Prioridad', 'Minutos estimados', 'Minutos reales', 'Puntos'],
            $filas
        );
    }

    /** CSV en streaming con BOM UTF-8 (si no, Excel en español rompe los acentos). */
    private function csv(string $nombre, array $encabezados, array $filas)
    {
        return response()->streamDownload(function () use ($encabezados, $filas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados);
            foreach ($filas as $fila) {
                fputcsv($salida, $fila);
            }
            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
