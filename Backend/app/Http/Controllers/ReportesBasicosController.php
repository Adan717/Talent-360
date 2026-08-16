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
 * La entrega (CSV con BOM, PDF con `?formato=pdf`, notas al pie y neutralización de fórmulas)
 * sale de `ArmaReportesCsv`, igual que los otros trece. Este controlador tenía SU PROPIA copia
 * de `csv()`, escrita antes del trait: se quedó atrás cuando el trait ganó las notas al pie, y
 * los dos únicos reportes de los quince que no las traían eran justo éstos.
 */
class ReportesBasicosController extends Controller
{
    use ArmaReportesCsv;

    /**
     * Máximo de días por descarga (ronda adversarial del bloque 6): el tope que anunciaba
     * el asistente era decorativo si esta puerta —la que entrega los datos— no lo conocía.
     * Un ?from=1990-01-01 materializaba TODO el historial en memoria.
     */
    public const DIAS_TOPE = 92;

    /** Valida y acota el rango pedido. Devuelve [desde, hasta] (Y-m-d). */
    private function rangoValidado(Request $request, string $desdePorDefecto, string $hastaPorDefecto): array
    {
        $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        $hasta = $request->query('to', $hastaPorDefecto);
        $desde = $request->query('from', $desdePorDefecto);
        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        if (Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) + 1 > self::DIAS_TOPE) {
            abort(response()->json([
                'message' => 'El periodo máximo por reporte es de ' . self::DIAS_TOPE . ' días. Acota las fechas.',
            ], 422));
        }

        return [$desde, $hasta];
    }

    /**
     * Asistencia: entradas, salidas y retardos. Por defecto HOY (día del tenant); acepta
     * `from`/`to` para un rango (bloque 6: es la MISMA puerta que llena el asistente —
     * extenderla aquí evita una segunda ruta). `date` se conserva por compatibilidad.
     */
    public function asistenciaDelDia(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $tz = TenantTimezone::for($tenantId);
        $fecha = $request->query('date', Carbon::now($tz)->toDateString());
        [$desde, $hasta] = $this->rangoValidado($request, $request->query('to', $fecha), $request->query('to', $fecha));

        $entradas = DB::table('time_entries')
            ->leftJoin('users', 'users.id', '=', 'time_entries.user_id')
            ->where('time_entries.tenant_id', $tenantId)
            ->whereBetween('time_entries.date', [$desde, $hasta])
            // Los fichajes del Simulador Matrix NUNCA se mezclan con un reporte real.
            ->whereNull('time_entries.simulation_session_id')
            ->orderBy('time_entries.date')
            ->orderBy('users.name')
            ->orderBy('time_entries.time')
            ->select(
                'time_entries.date',
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
            $e->date,
            $e->employee_name_at_time ?: ($e->user_name ?: 'Sin nombre'),
            $e->job_role_title_at_time ?: 'Sin puesto',
            $etiquetas[$e->type] ?? $e->type,
            substr((string) $e->time, 0, 5),
            $e->is_late ? 'Sí' : 'No',
            (int) $e->late_minutes,
        ])->all();

        $nombre = $desde === $hasta ? "asistencia_{$desde}.csv" : "asistencia_{$desde}_a_{$hasta}.csv";

        return $this->csv(
            $nombre,
            ['Fecha', 'Colaborador', 'Puesto', 'Movimiento', 'Hora', '¿Retardo?', 'Minutos de retardo'],
            $filas,
            [
                $desde === $hasta ? "Periodo del {$desde}." : "Periodo del {$desde} al {$hasta}.",
                'Es el detalle CRUDO de los fichajes: un retardo aparece aquí aunque después se haya justificado. Para lo que de verdad se cobró, el reporte es "Retardos y Faltas por Colaborador", que sale del mismo motor que la nómina.',
                'El nombre y el puesto son los que la persona tenía EL DÍA del fichaje, no los de hoy.',
            ]
        );
    }

    /** Tareas completadas en un rango (por defecto, los últimos 30 días del tenant). */
    public function tareasCompletadas(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $tz = TenantTimezone::for($tenantId);

        $hastaPorDefecto = $request->query('to', Carbon::now($tz)->toDateString());
        [$desde, $hasta] = $this->rangoValidado(
            $request,
            Carbon::createFromFormat('Y-m-d', $hastaPorDefecto, $tz)->subDays(29)->toDateString(),
            $hastaPorDefecto
        );

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
            $filas,
            [
                "Periodo del {$desde} al {$hasta}.",
                'Sólo tareas COMPLETADAS. Las omitidas, rechazadas o sin cerrar están en "Cumplimiento de Rutinas".',
                'Los "minutos reales" sólo se acumulan cuando la persona PAUSA la tarea: una tarea hecha de corrido marca 0, y eso no significa que no se trabajó.',
            ]
        );
    }

}
