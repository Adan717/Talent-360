<?php

namespace App\Http\Controllers;

use App\Support\FichajesVigentes;
use App\Helpers\TenantTimezone;
use App\Models\Employee;
use App\Services\ClockService;
use App\Support\JornadaLaboral;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reportes operativos nuevos (2026-08-13): retardos y faltas, horas trabajadas y
 * cumplimiento de rutinas.
 *
 * REGLA QUE MANDA EN ESTE ARCHIVO: ninguna cifra se vuelve a calcular aquí si ya existe
 * quien la calcule. El defecto histórico de este proyecto es "dos cifras para el mismo
 * dato" (la tarjeta que decía $4,875 cuando la nómina eran $13,125), así que:
 *
 *  - Retardos y faltas salen de `ClockService::calculatePayrollForEmployee`, el MISMO
 *    método que alimenta la nómina, el recibo firmado y el ticket. Contar `is_late` a mano
 *    daría MÁS retardos que la nómina, porque las exenciones (justificante aprobado,
 *    contingencia, amnistía) no se escriben en la fila del fichaje: se aplican al calcular.
 *  - Horas trabajadas NO existen en la nómina (se paga por día, no por horas): es un dato
 *    operativo nuevo, y como tal se calcula aquí con `JornadaLaboral` — el mismo soporte que
 *    usa el reloj para los turnos que cruzan medianoche.
 *  - Cumplimiento de rutinas declara su definición DENTRO del CSV, porque hoy conviven
 *    cuatro conteos distintos en el producto (ver el comentario del método).
 *
 * Reusa el cinturón de ReportesBasicosController: tope de días, CSV con BOM, celdas
 * neutralizadas contra fórmulas de Excel. Mismo grupo de rutas (admin/supervisor, módulo
 * reportes, throttle) — sin dato salarial, así que sin candado de nómina.
 */
class ReportesOperativosController extends Controller
{
    use ArmaReportesCsv;

    public function __construct(private ClockService $clock)
    {
    }

    /**
     * RETARDOS Y FALTAS por colaborador. El resumen que hoy se arma a mano contando
     * renglones del CSV de asistencia — y mal, porque ese CSV no sabe de exenciones.
     */
    public function retardosYFaltas(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'retardos');

        $lft = \App\Models\LftSetting::where('tenant_id', $tenantId)->first();
        $retardosPorFalta = (int) ($lft->lates_per_absence ?? 3);

        $filas = [];
        foreach ($this->colaboradores($tenantId) as $emp) {
            // La MISMA llamada que hace la nómina. Si esto y el recibo difieren, es un bug
            // del motor, no de dos cuentas paralelas.
            $r = $this->clock->calculatePayrollForEmployee($emp, $desde, $hasta);
            $inc = $r['incidents'] ?? [];

            $filas[] = [
                $emp->name,
                $emp->jobRole->name ?? 'Sin puesto',
                (int) ($inc['lates'] ?? 0),
                (int) ($inc['late_minutes'] ?? 0),
                (int) ($inc['physical_absences'] ?? 0),
                (int) ($inc['absences_from_lates'] ?? 0),
                (int) ($inc['total_absences'] ?? 0),
                (int) ($inc['meal_excess_minutes'] ?? 0),
                (int) ($inc['holidays_worked'] ?? 0),
            ];
        }

        // De más incidencias a menos: el reporte se pide para ver quién reincide.
        usort($filas, fn ($a, $b) => [$b[6], $b[2]] <=> [$a[6], $a[2]]);

        return $this->csv("retardos_y_faltas_{$desde}_a_{$hasta}.csv", [
            'Colaborador', 'Puesto', 'Retardos', 'Minutos de retardo', 'Faltas físicas',
            "Faltas por acumular {$retardosPorFalta} retardos", 'Faltas totales',
            'Exceso de comida (min)', 'Días festivos trabajados',
        ], $filas, [
            "Periodo del {$desde} al {$hasta}.",
            'Las cifras son las mismas que usa la nómina: los retardos con justificante aprobado y los días con contingencia aprobada NO cuentan.',
            "Cada {$retardosPorFalta} retardos se convierten en 1 falta (configurable en Ley Federal del Trabajo).",
            'Los días de descanso y los festivos no cuentan como falta; un día que aún no termina tampoco.',
        ]);
    }

    /**
     * HORAS TRABAJADAS Y EXTRA. Dato operativo: la nómina paga por día, no por horas, así
     * que esto NO es una segunda versión del salario — es cuánto tiempo estuvo la gente.
     */
    public function horasTrabajadas(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'horas');
        $tz = TenantTimezone::for($tenantId);

        $colaboradores = $this->colaboradores($tenantId)->keyBy('user_id');

        $fichajes = FichajesVigentes::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$desde, $hasta])
            ->whereNull('simulation_session_id')
            ->whereIn('type', ['check_in', 'check_out', 'meal_start', 'meal_end', 'break_start', 'break_end'])
            ->orderBy('user_id')->orderBy('date')->orderBy('time')
            ->get(['user_id', 'date', 'type', 'time', 'details']);

        $extras = DB::table('overtime_authorizations')
            ->where('tenant_id', $tenantId)->whereBetween('date', [$desde, $hasta])
            ->get(['user_id', 'date', 'kind']);

        $porDia = [];
        foreach ($fichajes as $f) {
            $porDia[$f->user_id][$f->date][] = $f;
        }

        $filas = [];
        foreach ($porDia as $userId => $dias) {
            $emp = $colaboradores[$userId] ?? null;
            foreach ($dias as $fecha => $marcas) {
                $jornada = $this->minutosDeJornada($marcas, $fecha, $emp, $tz);
                $extra = $extras->first(fn ($e) => (int) $e->user_id === (int) $userId && $e->date === $fecha);

                $filas[] = [
                    $fecha,
                    $emp->name ?? 'Sin expediente',
                    $emp->jobRole->name ?? 'Sin puesto',
                    $jornada['entrada'] ?? '',
                    $jornada['salida'] ?? '',
                    $this->hhmm($jornada['brutos']),
                    $this->hhmm($jornada['comida']),
                    $this->hhmm($jornada['descanso']),
                    $this->hhmm($jornada['efectivos']),
                    $extra ? ($extra->kind === 'holiday' ? 'Festivo autorizado' : 'Descanso autorizado') : '',
                    $jornada['incompleta'],
                ];
            }
        }

        usort($filas, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $this->csv("horas_trabajadas_{$desde}_a_{$hasta}.csv", [
            'Fecha', 'Colaborador', 'Puesto', 'Entrada', 'Salida', 'Horas en sucursal',
            'Comida', 'Descansos', 'Horas efectivas', 'Trabajo en día no laborable', 'Observación',
        ], $filas, [
            "Periodo del {$desde} al {$hasta}.",
            'Horas efectivas = horas en sucursal − comida − descansos. Los turnos que cruzan medianoche se cuentan como UNA jornada.',
            'La nómina de este sistema se paga por día, no por horas: este reporte es operativo y no altera ningún recibo.',
            '"Cerrada por el sistema" = la persona olvidó checar salida y el cierre automático puso la hora de cierre de la sucursal.',
        ]);
    }

    /**
     * CUMPLIMIENTO DE RUTINAS Y TAREAS.
     *
     * Hoy conviven CUATRO conteos distintos del mismo dato (el CSV de tareas cuenta sólo
     * `completed`; el Monitor cuenta por `updated_at` en UTC; el tablero por `date`; y el
     * "reporte de cierre" del navegador cuenta `completed` + `awaiting_validation`). En vez
     * de agregar un quinto silencioso, este reporte declara su definición DENTRO del
     * archivo y usa la del reporte de cierre, que es la única con semántica de cumplimiento.
     *
     * Lo que NO trae: "minutos reales". `accumulated_mins` sólo se incrementa al PAUSAR, así
     * que en la mayoría de las filas es 0 — una columna de ceros presentada como tiempo real
     * sería exactamente la clase de cifra que este proyecto lleva ocho rondas quitando.
     */
    public function cumplimientoRutinas(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'rutinas');

        $asignaciones = DB::table('task_assignments')
            ->leftJoin('tasks', 'tasks.id', '=', 'task_assignments.task_id')
            ->leftJoin('users', 'users.id', '=', 'task_assignments.user_id')
            ->leftJoin('employees', function ($j) {
                $j->on('employees.user_id', '=', 'task_assignments.user_id')
                    ->on('employees.tenant_id', '=', 'task_assignments.tenant_id');
            })
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('task_assignments.tenant_id', $tenantId)
            ->whereNull('task_assignments.deleted_at')
            ->whereBetween('task_assignments.date', [$desde, $hasta])
            ->get([
                'task_assignments.status', 'task_assignments.user_id',
                'users.name as colaborador', 'job_roles.name as puesto',
                'tasks.title as tarea', 'tasks.estimated_mins',
            ]);

        // Por persona
        $porPersona = [];
        // Por tarea (para ver cuáles se omiten seguido)
        $porTarea = [];

        $vacio = fn (string $puesto) => ['hechas' => 0, 'omitidas' => 0, 'abiertas' => 0,
                                        'total' => 0, 'puesto' => $puesto, 'mins' => 0];

        foreach ($asignaciones as $a) {
            $quien = $a->colaborador ?: 'Sin asignar (bolsa de trabajo)';
            $tarea = $a->tarea ?: '(tarea eliminada)';
            $bucket = $this->bucket($a->status);
            $mins = (int) ($a->estimated_mins ?? 0);

            $porPersona[$quien] ??= $vacio($a->puesto ?: 'Sin puesto');
            $porPersona[$quien][$bucket]++;
            $porPersona[$quien]['total']++;
            $porPersona[$quien]['mins'] += $mins;

            $porTarea[$tarea] ??= $vacio('');
            $porTarea[$tarea][$bucket]++;
            $porTarea[$tarea]['total']++;
            $porTarea[$tarea]['mins'] += $mins;
        }

        $filas = [];
        foreach ($porPersona as $quien => $d) {
            $filas[] = ['Colaborador', $quien, $d['puesto'], $d['total'], $d['hechas'], $d['omitidas'],
                        $d['abiertas'], $this->pct($d['hechas'], $d['total']), $this->hhmm($d['mins'])];
        }
        foreach ($porTarea as $tarea => $d) {
            $filas[] = ['Tarea', $tarea, '', $d['total'], $d['hechas'], $d['omitidas'],
                        $d['abiertas'], $this->pct($d['hechas'], $d['total']), $this->hhmm($d['mins'])];
        }

        // Primero las personas, y dentro de cada bloque el peor cumplimiento arriba.
        usort($filas, fn ($a, $b) => [$a[0], (float) rtrim($a[7], '%')] <=> [$b[0], (float) rtrim($b[7], '%')]);

        return $this->csv("cumplimiento_rutinas_{$desde}_a_{$hasta}.csv", [
            'Tipo', 'Nombre', 'Puesto', 'Asignadas', 'Hechas', 'Omitidas', 'Sin cerrar',
            '% cumplimiento', 'Tiempo estimado',
        ], $filas, [
            "Periodo del {$desde} al {$hasta}.",
            'Hechas = completadas y las que esperan firma del supervisor. Omitidas = rechazadas o saltadas. Sin cerrar = quedaron pendientes, en curso o pausadas.',
            'El tiempo es el ESTIMADO del catálogo: el sistema sólo mide el tiempo real cuando la persona pausa la tarea, así que un "tiempo real" sería engañoso y no se incluye.',
            'Las tareas de la bolsa que nadie tomó aparecen como "Sin asignar".',
        ]);
    }

    // ── Soporte ───────────────────────────────────────────────────────────────────────

    private function bucket(?string $status): string
    {
        return match ($status) {
            'completed', 'awaiting_validation' => 'hechas',
            'omitted' => 'omitidas',
            default => 'abiertas',
        };
    }



    /** Colaboradores activos del tenant, con su puesto. */
    private function colaboradores(int $tenantId)
    {
        return Employee::where('tenant_id', $tenantId)
            ->where('is_active_employee', '!=', false)
            ->with('jobRole')
            ->orderBy('name')
            ->get();
    }

    /**
     * Minutos de una jornada a partir de sus marcas. Empareja check_in/check_out en orden y
     * resta comida y descansos; con `JornadaLaboral` para que el turno nocturno (22:00-02:00)
     * cuente como una sola jornada y no como dos ni como negativo.
     */
    private function minutosDeJornada(array $marcas, string $fecha, $emp, string $tz): array
    {
        $inicio = $emp->shiftStart ?? null;
        $fin = $emp->shiftEnd ?? null;

        $instante = function ($m) use ($fecha, $inicio, $fin, $tz) {
            return JornadaLaboral::instanteDe($fecha, substr((string) $m->time, 0, 8), $inicio, $fin, $tz);
        };

        // Se ordena por el INSTANTE, no por la hora del reloj: en un turno 22:00–02:00 la
        // salida de las 02:00 es POSTERIOR a la entrada de las 22:00, aunque "02" < "22".
        // Ordenar por la hora cruda emparejaba la salida con nada y daba 0 horas.
        usort($marcas, fn ($a, $b) => $instante($a) <=> $instante($b));

        $brutos = 0; $comida = 0; $descanso = 0;
        $abierto = null; $comidaAbierta = null; $descansoAbierto = null;
        $entrada = null; $salida = null; $autoCerrada = false;

        foreach ($marcas as $m) {
            $t = $instante($m);
            switch ($m->type) {
                case 'check_in':
                    $abierto = $t;
                    $entrada = $entrada ?? substr((string) $m->time, 0, 5);
                    break;
                case 'check_out':
                    if ($abierto) {
                        $brutos += max(0, $abierto->diffInMinutes($t));
                        $abierto = null;
                    }
                    $salida = substr((string) $m->time, 0, 5);
                    $detalles = json_decode((string) $m->details, true);
                    if (!empty($detalles['auto_closed'])) {
                        $autoCerrada = true;
                    }
                    break;
                case 'meal_start':   $comidaAbierta = $t; break;
                case 'meal_end':
                    if ($comidaAbierta) { $comida += max(0, $comidaAbierta->diffInMinutes($t)); $comidaAbierta = null; }
                    break;
                case 'break_start':  $descansoAbierto = $t; break;
                case 'break_end':
                    if ($descansoAbierto) { $descanso += max(0, $descansoAbierto->diffInMinutes($t)); $descansoAbierto = null; }
                    break;
            }
        }

        $observacion = '';
        if ($autoCerrada) {
            $observacion = 'Cerrada por el sistema (olvidó checar salida)';
        } elseif ($abierto && $brutos > 0) {
            // (2026-08-22) Quien salió y VOLVIÓ a entrar el mismo día deja un turno abierto sobre
            // horas que sí se contaron (las del turno que cerró). La fila decía a la vez "salida
            // 09:09 · 6:22 horas" y "sin salida registrada: no se cuentan horas" — dos afirmaciones
            // contrarias en el mismo renglón. Se cuentan las cerradas y se dice cuál queda abierta.
            $observacion = 'Volvió a entrar y no cerró ese turno: sólo se cuentan las horas ya cerradas';
        } elseif ($abierto) {
            $observacion = 'Sin salida registrada: no se cuentan horas';
        } elseif ($comidaAbierta) {
            $observacion = 'Comida sin cerrar';
        }

        return [
            'entrada' => $entrada, 'salida' => $salida,
            'brutos' => $brutos, 'comida' => $comida, 'descanso' => $descanso,
            'efectivos' => max(0, $brutos - $comida - $descanso),
            'incompleta' => $observacion,
        ];
    }

    /**
     * REGISTRO ELECTRÓNICO DE JORNADA — Art. 132 fr. XXXIV LFT (2026-08-28).
     *
     * La obligación patronal es conservar el registro de las horas de ENTRADA y SALIDA de cada
     * trabajador. Este reporte lo entrega en crudo, una fila por persona y día, y —esto es lo que
     * lo vuelve defendible ante una inspección— **con las correcciones a la vista**: si una hora
     * se movió, aquí se lee cuál era, cuál quedó, quién la cambió y por qué.
     *
     * POR QUÉ NO PASA POR `FichajesVigentes::query()` COMO LOS DEMÁS: los otros reportes miden lo
     * que se paga, y para eso un fichaje anulado no existe. Éste no mide, TESTIFICA. Un registro
     * del que se pueden borrar renglones sin dejar marca no prueba nada, así que va por
     * `FichajesVigentes::todos()` (la salida documentada para auditoría) y presenta lo retirado
     * como retirado, con su motivo. Se le añade a mano el filtro del Simulador, que `todos()` no
     * trae consigo.
     */
    public function registroDeJornada(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'registro_jornada');

        $colaboradores = $this->colaboradores($tenantId)->keyBy('user_id');

        // TODOS los movimientos del periodo, anulados incluidos.
        $fichajes = FichajesVigentes::todos()
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$desde, $hasta])
            ->whereNull('simulation_session_id')
            ->whereIn('type', ['check_in', 'check_out', 'meal_start', 'meal_end'])
            ->orderBy('user_id')->orderBy('date')->orderBy('time')
            ->get([
                'id', 'user_id', 'date', 'type', 'time', 'details',
                'employee_name_at_time', 'job_role_title_at_time',
                'anulado_at', 'anulado_por_correccion_id', 'creado_por_correccion_id',
            ]);

        // El motivo y la firma de cada corrección que tocó este periodo.
        $idsCorreccion = $fichajes
            ->flatMap(fn ($f) => [$f->anulado_por_correccion_id, $f->creado_por_correccion_id])
            ->filter()->unique()->values()->all();

        $correcciones = collect();
        if (!empty($idsCorreccion)) {
            $correcciones = DB::table('asistencia_correcciones as c')
                ->leftJoin('users as autor', 'autor.id', '=', 'c.autorizado_por')
                ->where('c.tenant_id', $tenantId)
                ->whereIn('c.id', $idsCorreccion)
                ->get(['c.id', 'c.tipo', 'c.motivo', 'c.created_at', 'autor.name as autor'])
                ->keyBy('id');
        }

        $porDia = [];
        foreach ($fichajes as $f) {
            $porDia[$f->user_id][$f->date][] = $f;
        }

        $filas = [];
        foreach ($porDia as $userId => $dias) {
            $emp = $colaboradores[$userId] ?? null;

            foreach ($dias as $fecha => $marcas) {
                $vigentes = collect($marcas)->filter(fn ($m) => $m->anulado_at === null);
                $anuladas = collect($marcas)->filter(fn ($m) => $m->anulado_at !== null);

                $entrada = $vigentes->firstWhere('type', 'check_in');
                $salida = $vigentes->where('type', 'check_out')->last();
                $comidaIni = $vigentes->firstWhere('type', 'meal_start');
                $comidaFin = $vigentes->where('type', 'meal_end')->last();

                // El nombre y el puesto son la FOTO del momento (lo que exige un registro
                // histórico); si esa vía no los estampó, se cae al expediente de hoy.
                $conFoto = collect($marcas)->first(fn ($m) => $m->employee_name_at_time !== null);

                $notas = [];
                foreach ($marcas as $m) {
                    $idCorr = $m->anulado_at !== null ? $m->anulado_por_correccion_id : $m->creado_por_correccion_id;
                    if (!$idCorr || !isset($correcciones[$idCorr])) {
                        continue;
                    }
                    $c = $correcciones[$idCorr];
                    $que = $m->anulado_at !== null
                        ? 'se retiró ' . $this->nombreDelMovimiento($m->type) . ' de las ' . substr((string) $m->time, 0, 5)
                        : 'quedó ' . $this->nombreDelMovimiento($m->type) . ' a las ' . substr((string) $m->time, 0, 5);
                    $notas[] = $que . ' — ' . trim((string) $c->motivo)
                        . ' (' . ($c->autor ?? 'usuario retirado') . ', ' . substr((string) $c->created_at, 0, 10) . ')';
                }
                $notas = array_values(array_unique($notas));

                $auto = $vigentes->first(
                    fn ($m) => $m->type === 'check_out' && str_contains((string) $m->details, '"auto_closed":true')
                );

                if (!empty($notas)) {
                    $estado = 'Corregido';
                } elseif ($auto) {
                    $estado = 'Salida puesta por el sistema';
                } elseif (!$entrada || !$salida) {
                    $estado = 'Incompleto';
                } else {
                    $estado = 'Original';
                }

                $filas[] = [
                    $fecha,
                    $conFoto->employee_name_at_time ?? $emp->name ?? 'Sin expediente',
                    $conFoto->job_role_title_at_time ?? $emp->jobRole->name ?? 'Sin puesto',
                    $entrada ? substr((string) $entrada->time, 0, 8) : '',
                    $salida ? substr((string) $salida->time, 0, 8) : '',
                    $comidaIni ? substr((string) $comidaIni->time, 0, 5) : '',
                    $comidaFin ? substr((string) $comidaFin->time, 0, 5) : '',
                    $estado,
                    $anuladas->count(),
                    implode(' | ', $notas),
                ];
            }
        }

        usort($filas, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $conCorreccion = count(array_filter($filas, fn ($f) => $f[7] === 'Corregido'));

        return $this->csv("registro_de_jornada_{$desde}_a_{$hasta}.csv", [
            'Fecha', 'Colaborador', 'Puesto', 'Entrada', 'Salida',
            'Inicio de comida', 'Fin de comida', 'Estado del registro',
            'Movimientos retirados', 'Qué se corrigió y por qué',
        ], $filas, [
            "Periodo del {$desde} al {$hasta}.",
            'Registro electrónico de jornada — artículo 132 fracción XXXIV de la Ley Federal del Trabajo: '
                . 'la hora de entrada y de salida de cada trabajador, por día.',
            'La hora la sella el SERVIDOR al registrarse el fichaje; la persona no puede escribirla. '
                . 'Un fichaje sincronizado sin red conserva su momento real y queda marcado para revision.',
            'Ninguna hora se sobrescribe: una correccion RETIRA el movimiento anterior (sigue en el sistema, '
                . 'contado en "Movimientos retirados") y registra el nuevo. Por eso este reporte puede mostrar '
                . 'que decia antes y que dice ahora.',
            '"Salida puesta por el sistema" = la persona no marco su salida y el cierre automatico la registro; '
                . 'no es una hora observada.',
            'El nombre y el puesto son los que tenia la persona EL DIA del fichaje, no los de hoy.',
        ], [
            'titulo' => 'Resumen del periodo',
            'encabezados' => ['Concepto', 'Cantidad'],
            'filas' => [
                ['Dias con registro', count($filas)],
                ['Dias con alguna correccion', $conCorreccion],
                ['Colaboradores con registro', count($porDia)],
            ],
        ]);
    }

    /** Nombre humano de un movimiento, para las notas de correccion del registro de jornada. */
    private function nombreDelMovimiento(string $tipo): string
    {
        return [
            'check_in' => 'la entrada',
            'check_out' => 'la salida',
            'meal_start' => 'el inicio de comida',
            'meal_end' => 'el fin de comida',
        ][$tipo] ?? 'un movimiento';
    }
}
