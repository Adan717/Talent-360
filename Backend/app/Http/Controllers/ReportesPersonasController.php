<?php

namespace App\Http\Controllers;

use App\Support\PlazoInduccion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reportes de personas (2026-08-13): inducción y capacitación, expediente documental,
 * embudo de reclutamiento, y monedero/reconocimientos.
 *
 * Las definiciones se copian de donde ya viven (no se reinventan): el plazo de inducción es
 * `PlazoInduccion::DIAS`, la checklist del expediente es `DocumentosController::CHECKLIST`, y
 * el saldo autoritativo del monedero es `user_wallets`, no la suma de transacciones.
 */
class ReportesPersonasController extends Controller
{
    use ArmaReportesCsv;

    /**
     * INDUCCIÓN Y CAPACITACIÓN: quién trae la inducción pendiente, quién se atoró reprobando
     * y qué certificados se emitieron. Es la evidencia de capacitación (NOM-035, LFT art. 153).
     */
    public function academia(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'academia');
        $plazo = PlazoInduccion::DIAS;
        $hoy = Carbon::today();

        // Población: colaboradores activos con cuenta (los admins no reciben aviso de inducción,
        // igual que en el tablero del encargado — las dos puntas de la regla dicen lo mismo).
        $gente = DB::table('employees')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('employees.tenant_id', $tenantId)
            ->where('employees.is_active_employee', '!=', false)
            ->whereNull('employees.deleted_at')
            ->whereNotIn('users.role', ['admin', 'platform_admin'])
            ->get(['users.id as user_id', 'employees.name', 'employees.hire_date',
                   'employees.job_role_id', 'job_roles.name as puesto', 'users.has_completed_induction']);

        $progreso = DB::table('user_course_progress')
            ->join('academy_courses', 'academy_courses.id', '=', 'user_course_progress.course_id')
            ->where('user_course_progress.tenant_id', $tenantId)
            ->get(['user_course_progress.user_id', 'user_course_progress.status', 'user_course_progress.score',
                   'user_course_progress.failed_attempts', 'user_course_progress.completed_at',
                   'user_course_progress.supervisor_atendido_at', 'academy_courses.title', 'academy_courses.course_type']);

        $certificados = DB::table('course_certificates')
            ->where('tenant_id', $tenantId)
            ->whereBetween('issued_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->get(['user_id', 'folio', 'course_title', 'issued_at', 'score']);

        $filas = [];
        foreach ($gente as $p) {
            $suyo = $progreso->where('user_id', $p->user_id);
            $aprobados = $suyo->where('status', 'completed');
            $atorados = $suyo->filter(fn ($c) => (int) $c->failed_attempts >= 2 && $c->status !== 'completed');
            $misCerts = $certificados->where('user_id', $p->user_id);

            // Plazo de inducción: mismo criterio que el tablero del encargado.
            $estadoInduccion = 'Completada';
            $diasDesdeIngreso = '';
            if (!$p->has_completed_induction) {
                if ($p->hire_date) {
                    $dias = Carbon::parse($p->hire_date)->startOfDay()->diffInDays($hoy, false);
                    $diasDesdeIngreso = max(0, (int) $dias);
                    $estadoInduccion = $dias > $plazo
                        ? "VENCIDA (van {$diasDesdeIngreso} días)"
                        : "Pendiente (de {$plazo} días)";
                } else {
                    $estadoInduccion = 'Pendiente (sin fecha de ingreso capturada)';
                }
            }

            $filas[] = [
                $p->name,
                $p->puesto ?: 'Sin puesto',
                $p->hire_date ?: '',
                $diasDesdeIngreso,
                $estadoInduccion,
                $aprobados->count(),
                $atorados->count() ?: '',
                $atorados->map(fn ($c) => $c->title)->implode('; '),
                $misCerts->count() ?: '',
                $misCerts->map(fn ($c) => $c->folio)->implode('; '),
            ];
        }

        // Primero quien trae la inducción vencida.
        usort($filas, fn ($a, $b) => [str_starts_with($b[4], 'VENCIDA'), $b[6] ?: 0] <=> [str_starts_with($a[4], 'VENCIDA'), $a[6] ?: 0]);

        return $this->csv("induccion_y_capacitacion_{$desde}_a_{$hasta}.csv", [
            'Colaborador', 'Puesto', 'Fecha de ingreso', 'Días desde el ingreso', 'Inducción',
            'Cursos aprobados', 'Cursos atorados', 'Cuáles se le atoraron',
            'Certificados en el periodo', 'Folios',
        ], $filas, [
            "Periodo del {$desde} al {$hasta} (los certificados se cuentan por su fecha de emisión; la inducción es estado de HOY).",
            "El plazo de inducción es de {$plazo} días desde la fecha de ingreso — el mismo que avisa en la app del colaborador y en el tablero del encargado. Nada bloquea: avisa.",
            'Un curso "atorado" es el que la persona reprobó 2 o más veces sin aprobarlo.',
            'Los administradores no aparecen: tampoco reciben el aviso de inducción.',
            'Los folios se pueden verificar públicamente en la página de certificados.',
        ]);
    }

    /** EXPEDIENTE DOCUMENTAL: qué documentos tiene y le faltan a cada quien. */
    public function expedientes(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $checklist = DocumentosController::CHECKLIST;

        $gente = DB::table('employees')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('employees.tenant_id', $tenantId)
            ->where('employees.is_active_employee', '!=', false)
            ->whereNull('employees.deleted_at')
            ->orderBy('employees.name')
            ->get(['employees.id', 'employees.name', 'job_roles.name as puesto']);

        $docs = DB::table('employee_documents')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get(['employee_id', 'doc_type', 'status', 'rejection_reason', 'validated_at']);

        $filas = [];
        foreach ($gente as $p) {
            $suyos = $docs->where('employee_id', $p->id);
            $porTipo = [];
            $faltantes = [];

            foreach ($checklist as $clave => $etiqueta) {
                $doc = $suyos->firstWhere('doc_type', $clave);
                $porTipo[$clave] = match ($doc->status ?? null) {
                    'validado' => 'Validado',
                    'pendiente' => 'Por validar',
                    'rechazado' => 'Rechazado' . ($doc->rejection_reason ? " ({$doc->rejection_reason})" : ''),
                    default => 'FALTA',
                };
                if (!$doc || $doc->status === 'rechazado') {
                    $faltantes[] = $etiqueta;
                }
            }

            $validados = collect($porTipo)->filter(fn ($v) => $v === 'Validado')->count();

            $filas[] = array_merge(
                [$p->name, $p->puesto ?: 'Sin puesto', $validados . '/' . count($checklist)],
                array_values($porTipo),
                [count($faltantes) ? implode('; ', $faltantes) : 'Expediente completo',
                 $suyos->where('doc_type', 'otro')->count() ?: '']
            );
        }

        // Primero los expedientes más incompletos.
        usort($filas, fn ($a, $b) => (int) explode('/', $a[2])[0] <=> (int) explode('/', $b[2])[0]);

        return $this->csv('expedientes_' . now()->toDateString() . '.csv', array_merge(
            ['Colaborador', 'Puesto', 'Validados'],
            array_values($checklist),
            ['Qué le falta', 'Otros documentos']
        ), $filas, [
            'Estado de HOY: el expediente no es un periodo, es una foto del momento — este reporte ignora las fechas que le pidas.',
            'La lista de documentos requeridos es la misma para todos los puestos.',
            'Un documento rechazado cuenta como faltante: hay que volver a subirlo.',
            'Sólo aparecen colaboradores activos.',
        ]);
    }

    /** EMBUDO DE RECLUTAMIENTO: candidatos por etapa y qué pasó con ellos. */
    public function reclutamiento(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'reclutamiento');

        $candidatos = DB::table('candidates')
            ->leftJoin('vacancies', 'vacancies.id', '=', 'candidates.applied_vacancy_id')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'vacancies.job_role_id')
            ->where('candidates.tenant_id', $tenantId)
            ->whereNull('candidates.deleted_at')
            ->whereBetween('candidates.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->orderBy('candidates.created_at')
            ->get(['candidates.name', 'candidates.status', 'candidates.created_at', 'candidates.updated_at',
                   'candidates.is_ex_employee_fast_track', 'candidates.induction_score',
                   'vacancies.title as vacante', 'job_roles.name as puesto']);

        $filas = [];
        foreach ($candidatos as $c) {
            $filas[] = [
                Carbon::parse($c->created_at)->toDateString(),
                $c->name,
                $c->vacante ?: 'Sin vacante ligada',
                $c->puesto ?: '',
                $this->etapaEnEspanol($c->status),
                $c->is_ex_employee_fast_track ? 'Ya trabajó aquí' : '',
                $c->induction_score !== null ? $c->induction_score . '%' : '',
                Carbon::parse($c->updated_at)->toDateString(),
            ];
        }

        // Resumen por vacante al final del mismo archivo: es lo que se mira primero.
        $resumen = collect($candidatos)->groupBy(fn ($c) => $c->vacante ?: 'Sin vacante ligada');
        $filasResumen = [];
        foreach ($resumen as $vacante => $lista) {
            $filasResumen[] = [
                '', "RESUMEN · {$vacante}", '', '',
                'Total: ' . $lista->count()
                . ' · Contratados: ' . $lista->where('status', 'hired')->count()
                . ' · Rechazados: ' . $lista->where('status', 'rejected')->count()
                . ' · En proceso: ' . $lista->whereNotIn('status', ['hired', 'rejected'])->count(),
                '', '', '',
            ];
        }

        return $this->csv("reclutamiento_{$desde}_a_{$hasta}.csv", [
            'Se postuló', 'Candidato', 'Vacante', 'Puesto', 'Etapa actual',
            'Antecedente', 'Evaluación de postulación', 'Último movimiento',
        ], array_merge($filas, [[]], $filasResumen), [
            "Periodo del {$desde} al {$hasta} (por fecha de postulación).",
            'El sistema NO guarda cuándo cambió cada candidato de etapa, así que este reporte no puede decir cuánto tardó en cada una: sólo su etapa actual y la fecha de su último movimiento.',
            'La "evaluación de postulación" es el filtro previo a contratar; la inducción es de Academia y ocurre ya contratado.',
            '"Ya trabajó aquí" lo marca el sistema al reconocer el correo de un ex-colaborador.',
        ]);
    }

    /** MONEDERO Y RECONOCIMIENTOS: qué ganó cada quien y qué se validó o rechazó. */
    public function monedero(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'monedero');

        $nombres = $this->nombresPorUsuario($tenantId);

        // El saldo autoritativo es la tabla del monedero, no la suma de transacciones.
        $saldos = DB::table('user_wallets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get(['user_id', 'balance_coins', 'total_earned_coins', 'xp_points', 'level'])
            ->keyBy('user_id');

        // Lo GANADO en el periodo sale de las asignaciones (llevan el día operativo del tenant).
        $delPeriodo = DB::table('task_assignments')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereBetween('date', [$desde, $hasta])
            ->whereNotNull('user_id')
            ->get(['user_id', 'status', 'coins_awarded', 'points_awarded', 'validated_by', 'validation_feedback']);

        $filas = [];
        foreach ($delPeriodo->groupBy('user_id') as $userId => $suyas) {
            $saldo = $saldos[$userId] ?? null;
            $rechazadas = $suyas->filter(fn ($a) => $a->status === 'omitted' && $a->validation_feedback);

            $filas[] = [
                $nombres[$userId]['nombre'] ?? 'Sin expediente',
                $nombres[$userId]['puesto'] ?? 'Sin puesto',
                $suyas->whereIn('status', ['completed', 'awaiting_validation'])->count(),
                round($suyas->sum('coins_awarded'), 2),
                (int) $suyas->sum('points_awarded'),
                $suyas->whereNotNull('validated_by')->count(),
                $rechazadas->count() ?: '',
                $saldo ? round($saldo->balance_coins, 2) : 0,
                $saldo ? (int) $saldo->xp_points : 0,
                $saldo ? (int) $saldo->level : 1,
            ];
        }

        usort($filas, fn ($a, $b) => $b[3] <=> $a[3]);

        return $this->csv("monedero_{$desde}_a_{$hasta}.csv", [
            'Colaborador', 'Puesto', 'Tareas hechas en el periodo', 'Monedas ganadas en el periodo',
            'Puntos ganados en el periodo', 'Tareas validadas por un mando', 'Tareas rechazadas',
            'Saldo actual de monedas', 'Puntos totales', 'Nivel',
        ], $filas, [
            "Periodo del {$desde} al {$hasta}.",
            'Las monedas y puntos del periodo son los que se pagaron por tareas de esos días; el saldo y el nivel son de HOY (acumulados de siempre).',
            'Las monedas son reconocimiento interno: el sistema no las convierte en dinero ni afecta la nómina.',
            'Una tarea sólo paga una vez, aunque se reabra o se valide de nuevo.',
        ]);
    }

    private function etapaEnEspanol(?string $status): string
    {
        return match ($status) {
            'prospect' => '1. Prospecto',
            'induction' => '2. Evaluación de postulación',
            'interview' => '3. Por entrevistar',
            'training' => '4. Entrenamiento en piso',
            'hired' => '5. Contratado',
            'rejected' => 'Rechazado',
            default => (string) $status,
        };
    }
}
