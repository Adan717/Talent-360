<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reportes de incidencias y operación (2026-08-13): justificantes y autorizaciones,
 * aperturas y cierres de sucursal, comedor y Ley Silla.
 *
 * Misma regla de la casa que ReportesOperativosController: ninguna cifra se recalcula si ya
 * existe quien la calcule. En particular "apertura a tiempo" sale de
 * `ClockService::aperturasATiempo`, el mismo método que paga el bono.
 *
 * Nota sobre justificantes: son SEIS tablas separadas (el propio código reconoce la
 * consolidación como pendiente). El reporte las unifica en una sola vista sin fusionarlas en
 * la base: lo que aquí se junta es la LECTURA, no los datos.
 */
class ReportesIncidenciasController extends Controller
{
    use ArmaReportesCsv;

    /**
     * JUSTIFICANTES Y AUTORIZACIONES: qué se pidió, qué se aprobó y quién lo resolvió.
     * Es la evidencia de por qué un retardo NO se cobró — hoy sólo se ve en el momento, en
     * el Monitor, y después nadie puede reconstruirla.
     */
    public function justificantes(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'justificantes');

        $nombres = $this->nombresPorUsuario($tenantId);
        $filas = [];

        // Las cuatro con ciclo pedir → resolver.
        $conCiclo = [
            'late_justifications' => ['Justificante de retardo', true],
            'contingency_days' => ['Contingencia (fuerza mayor)', true],
            'late_authorization_requests' => ['Autorización de entrada tardía', false],
        ];
        foreach ($conCiclo as $tabla => [$etiqueta, $tieneMotivo]) {
            $columnas = ['user_id', 'date', 'status', 'resolved_by', 'resolved_at', 'created_at'];
            if ($tieneMotivo) {
                $columnas[] = 'reason';
            }
            if ($tabla === 'late_authorization_requests' || $tabla === 'late_justifications') {
                $columnas[] = 'requested_late_minutes';
            }

            foreach (DB::table($tabla)->where('tenant_id', $tenantId)
                ->whereBetween('date', [$desde, $hasta])->get($columnas) as $r) {
                $filas[] = [
                    $r->date, $etiqueta,
                    $nombres[$r->user_id]['nombre'] ?? 'Sin expediente',
                    $nombres[$r->user_id]['puesto'] ?? 'Sin puesto',
                    $this->estadoEnEspanol($r->status),
                    $tieneMotivo ? ($r->reason ?? '') : '',
                    isset($r->requested_late_minutes) ? (int) $r->requested_late_minutes : '',
                    $r->resolved_by ? ($nombres[$r->resolved_by]['nombre'] ?? 'Un mando') : '',
                    $r->resolved_at ? Carbon::parse($r->resolved_at)->format('Y-m-d H:i') : '',
                ];
            }
        }

        // Las dos que NO tienen ciclo: la fila ES la autorización (se otorga en el acto).
        $sinCiclo = [
            'overtime_authorizations' => 'Autorización de horas extra',
            'early_departure_authorizations' => 'Autorización de salida anticipada',
        ];
        foreach ($sinCiclo as $tabla => $etiqueta) {
            $columnas = ['user_id', 'date', 'authorized_by', 'method', 'created_at'];
            if ($tabla === 'overtime_authorizations') {
                $columnas[] = 'kind';
            }
            foreach (DB::table($tabla)->where('tenant_id', $tenantId)
                ->whereBetween('date', [$desde, $hasta])->get($columnas) as $r) {
                $detalle = isset($r->kind)
                    ? ($r->kind === 'holiday' ? 'Día festivo' : 'Día de descanso')
                    : '';
                $filas[] = [
                    $r->date, $etiqueta,
                    $nombres[$r->user_id]['nombre'] ?? 'Sin expediente',
                    $nombres[$r->user_id]['puesto'] ?? 'Sin puesto',
                    'Autorizada en el momento',
                    $detalle . ($r->method ? " (validó con {$r->method})" : ''),
                    '',
                    $nombres[$r->authorized_by]['nombre'] ?? 'Un mando',
                    Carbon::parse($r->created_at)->format('Y-m-d H:i'),
                ];
            }
        }

        // Eventualidades de sucursal: aplican a TODA la empresa ese día, no a una persona.
        foreach (DB::table('contingency_declarations')->where('tenant_id', $tenantId)
            ->whereBetween('date', [$desde, $hasta])
            ->get(['date', 'declared_by_user_id', 'reason', 'declared_at', 'resolved_at']) as $r) {
            $filas[] = [
                $r->date, 'Eventualidad de sucursal (toda la empresa)',
                'Toda la plantilla', '',
                $r->resolved_at ? 'Cerrada' : 'Abierta',
                match ($r->reason) {
                    'no_power' => 'Sin energía eléctrica',
                    'no_internet' => 'Sin internet',
                    'no_power_and_internet' => 'Sin luz ni internet',
                    default => (string) $r->reason,
                },
                '',
                $nombres[$r->declared_by_user_id]['nombre'] ?? 'Un mando',
                Carbon::parse($r->declared_at)->format('Y-m-d H:i'),
            ];
        }

        usort($filas, fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return $this->csv("justificantes_{$desde}_a_{$hasta}.csv", [
            'Fecha', 'Tipo', 'Colaborador', 'Puesto', 'Estado', 'Motivo / Detalle',
            'Minutos solicitados', 'Resolvió', 'Cuándo se resolvió',
        ], $filas, [
            "Periodo del {$desde} al {$hasta}.",
            'Un justificante o una contingencia APROBADOS son la razón por la que un retardo o una falta no se cobraron en la nómina de ese periodo.',
            'Las autorizaciones de horas extra y de salida anticipada no tienen ciclo de aprobación: se otorgan en el momento con el QR o el PIN de un mando, y la fila es la autorización.',
            'Nadie puede resolver su propia solicitud.',
        ]);
    }

    /** APERTURAS Y CIERRES: quién abrió, a qué hora y si fue a tiempo. */
    public function aperturas(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'aperturas');
        $tz = TenantTimezone::for($tenantId);

        $nombres = $this->nombresPorUsuario($tenantId);
        $lft = \App\Models\LftSetting::where('tenant_id', $tenantId)->first();
        $tolerancia = (int) ($lft->late_tolerance_minutes ?? 10);

        $dias = DB::table('store_daily_opening_statuses')
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$desde, $hasta])
            ->orderBy('date')
            ->get(['date', 'store_id', 'status', 'scheduled_opening_time', 'opened_by_employee_id',
                   'opened_at', 'closed_by_employee_id', 'closed_at', 'late_amnesty_granted']);

        // Los eventos dicen QUÉ pasó ese día (emergencia, ausencia reportada, traspaso).
        $eventos = DB::table('store_opening_events')
            ->where('tenant_id', $tenantId)
            ->whereBetween('event_time', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->get(['store_id', 'event_type', 'event_time', 'employee_id', 'notes']);

        $filas = [];
        foreach ($dias as $d) {
            $abrio = $d->opened_by_employee_id;
            $aTiempo = '';
            if ($d->opened_at && $d->scheduled_opening_time) {
                $abierta = Carbon::parse($d->opened_at)->setTimezone($tz);
                $programada = Carbon::parse($d->date . ' ' . $d->scheduled_opening_time, $tz);
                // MISMA regla que paga el bono de apertura (ClockService::aperturasATiempo).
                $aTiempo = $abierta->lessThanOrEqualTo($programada->copy()->addMinutes($tolerancia))
                    ? 'A tiempo'
                    : 'Tarde por ' . $programada->diffInMinutes($abierta) . ' min';
            }

            $delDia = $eventos->filter(fn ($e) => Carbon::parse($e->event_time)->setTimezone($tz)->toDateString() === $d->date
                && (int) $e->store_id === (int) $d->store_id);
            $incidencias = $delDia->map(fn ($e) => $this->eventoEnEspanol($e->event_type))
                ->filter()->unique()->implode('; ');

            $filas[] = [
                $d->date,
                $d->scheduled_opening_time ? substr($d->scheduled_opening_time, 0, 5) : '',
                $d->opened_at ? Carbon::parse($d->opened_at)->setTimezone($tz)->format('H:i') : '',
                $aTiempo,
                $abrio ? ($nombres[$abrio]['nombre'] ?? 'Sin expediente') : 'NADIE ABRIÓ',
                $d->closed_at ? Carbon::parse($d->closed_at)->setTimezone($tz)->format('H:i') : '',
                $d->closed_by_employee_id ? ($nombres[$d->closed_by_employee_id]['nombre'] ?? 'Sin expediente') : '',
                $this->estadoAperturaEnEspanol($d->status),
                $d->late_amnesty_granted ? 'Sí' : '',
                $incidencias,
            ];
        }

        return $this->csv("aperturas_{$desde}_a_{$hasta}.csv", [
            'Fecha', 'Apertura programada', 'Abrió a las', '¿A tiempo?', 'Quién abrió',
            'Cerró a las', 'Quién cerró', 'Estado del día', 'Amnistía por tienda cerrada', 'Incidencias',
        ], $filas, [
            "Periodo del {$desde} al {$hasta}.",
            "\"A tiempo\" usa la MISMA regla que paga el bono de apertura: abrir dentro de los {$tolerancia} minutos de tolerancia de la empresa.",
            'La "amnistía por tienda cerrada" significa que ese día los retardos de la plantilla no se cobraron porque la sucursal abrió tarde.',
            'Un día sin apertura registrada aparece como "NADIE ABRIÓ": revisa las incidencias de ese renglón.',
        ]);
    }

    /** COMEDOR Y LEY SILLA: comidas, excesos y descansos. */
    public function comedor(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'comedor');
        $tz = TenantTimezone::for($tenantId);

        $nombres = $this->nombresPorUsuario($tenantId);

        $marcas = DB::table('time_entries')
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$desde, $hasta])
            ->whereNull('simulation_session_id')
            ->whereIn('type', ['meal_start', 'meal_end', 'break_start', 'break_end'])
            ->orderBy('user_id')->orderBy('date')->orderBy('time')
            ->get(['user_id', 'date', 'type', 'time']);

        // OJO: en esta tabla la columna se llama `employee_id` pero apunta a `users` (familia
        // §29/§30 de nombres engañosos). Y "otorgada" son tres estados, no sólo `approved`:
        // una solicitud en curso (`active`) o ya terminada (`finished`) también se concedió.
        $sillas = DB::table('silla_requests')
            ->where('tenant_id', $tenantId)
            ->whereBetween('requested_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
            ->get(['employee_id as user_id', 'status', 'requested_at']);

        $porPersonaDia = [];
        foreach ($marcas as $m) {
            $porPersonaDia[$m->user_id][$m->date][] = $m;
        }

        // La MISMA tolerancia que aplica la nómina. El reporte la ignoraba por completo, así que
        // con permitido 60 y tolerancia 15 una comida de 70 min salía con 10 de exceso aquí y 0
        // en la nómina: dos cifras del mismo día (2026-08-22).
        $lftComedor = \App\Models\LftSetting::where('tenant_id', $tenantId)->first();
        $toleranciaComida = (int) ($lftComedor->meal_tolerance_minutes ?? 15);
        $toleranciaDescanso = (int) ($lftComedor->rest_tolerance_minutes ?? 10);

        $filas = [];
        foreach ($porPersonaDia as $userId => $dias) {
            $emp = $nombres[$userId] ?? null;
            $permitidos = (int) ($emp['mealMinutes'] ?? 60);

            foreach ($dias as $fecha => $delDia) {
                // Fórmula ÚNICA, compartida con el motor de nómina (App\Support\ExcesoDeDescanso).
                $comida = \App\Support\ExcesoDeDescanso::calcular(
                    $delDia, $fecha, 'meal_start', 'meal_end', $permitidos, $toleranciaComida,
                    $emp['shiftStart'] ?? null, $emp['shiftEnd'] ?? null, $tz
                );
                $descanso = \App\Support\ExcesoDeDescanso::calcular(
                    $delDia, $fecha, 'break_start', 'break_end', 15, $toleranciaDescanso,
                    $emp['shiftStart'] ?? null, $emp['shiftEnd'] ?? null, $tz
                );
                $exceso = $comida['exceso'];

                $sillasDia = $sillas->filter(fn ($s) => (int) $s->user_id === (int) $userId
                    && Carbon::parse($s->requested_at)->setTimezone($tz)->toDateString() === $fecha);

                $filas[] = [
                    $fecha,
                    $emp['nombre'] ?? 'Sin expediente',
                    $emp['puesto'] ?? 'Sin puesto',
                    $comida['inicio'] ?? '',
                    $comida['fin'] ?? '',
                    $comida['minutos'],
                    $permitidos,
                    // Un 0 explícito: en las demás columnas numéricas se imprime, y una celda
                    // vacía en Excel no es cero, es "no se midió".
                    $exceso,
                    $descanso['minutos'],
                    $sillasDia->count() ?: '',
                    $sillasDia->whereIn('status', ['approved', 'active', 'finished'])->count() ?: '',
                    $comida['abierta'] ? 'Comida sin cerrar' : '',
                ];
            }
        }

        usort($filas, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $this->csv("comedor_{$desde}_a_{$hasta}.csv", [
            'Fecha', 'Colaborador', 'Puesto', 'Inició comida', 'Terminó comida', 'Minutos de comida',
            'Minutos permitidos', 'Exceso', 'Minutos de descanso', 'Solicitudes Ley Silla',
            'Ley Silla aprobadas', 'Observación',
        ], $filas, [
            "Periodo del {$desde} al {$hasta}.",
            'Los minutos permitidos salen del expediente de cada persona (Directorio Digital → Laboral).',
            // (2026-08-22) Antes decía "es el mismo que la nómina DESCUENTA del cierre de
            // jornada". La primera mitad ya es cierta —desde hoy las dos pantallas usan
            // App\Support\ExcesoDeDescanso—, pero la segunda nunca lo fue: el motor paga por DÍA
            // y el exceso no toca el dinero. Prometer un descuento que no ocurre es peor que no
            // prometer nada, sobre todo en el papel que se le enseña a un colaborador.
            'El exceso se calcula igual que en la nómina (misma fórmula y misma tolerancia).',
            'Hoy el exceso NO descuenta dinero: es un indicador y sirve de evidencia. La jornada se paga por día.',
            'La Ley Silla es obligatoria en México desde 2025: este reporte sirve de evidencia de que los descansos se otorgan.',
        ]);
    }

    // ── Soporte ───────────────────────────────────────────────────────────────────────

    private function estadoEnEspanol(?string $status): string
    {
        return match ($status) {
            'approved' => 'Aprobado',
            'rejected' => 'Rechazado',
            'pending' => 'Pendiente de resolver',
            default => (string) $status,
        };
    }

    private function estadoAperturaEnEspanol(?string $status): string
    {
        return match ($status) {
            'opened' => 'Abierta',
            'closed_reported_by_employees' => 'Reportada como cerrada por el equipo',
            'failed' => 'No se abrió',
            'transferred' => 'Traspasada a otro portador',
            'active_window' => 'En ventana de apertura',
            'pending' => 'Pendiente',
            default => (string) $status,
        };
    }

    private function eventoEnEspanol(string $tipo): string
    {
        return match ($tipo) {
            'emergency_open' => 'Apertura de emergencia con testigos',
            'report_absence', 'handoff_report_absence' => 'Portador de llaves reportó ausencia',
            'report_late', 'handoff_report_late' => 'Portador de llaves reportó retraso',
            'handoff_no_response' => 'El suplente no respondió',
            'failed_no_responsibles' => 'Nadie con llaves disponible',
            'closed_reported' => 'El equipo reportó la tienda cerrada',
            default => '',
        };
    }

    /** Empareja inicio/fin de un tipo de pausa y devuelve minutos + horas legibles. */
    private function minutosEmparejados(array $marcas, string $inicio, string $fin, string $fecha, string $tz): array
    {
        $total = 0; $abierta = null; $primerInicio = null; $ultimoFin = null;
        foreach ($marcas as $m) {
            if ($m->type === $inicio) {
                $abierta = Carbon::parse($fecha . ' ' . substr((string) $m->time, 0, 8), $tz);
                $primerInicio = $primerInicio ?? substr((string) $m->time, 0, 5);
            } elseif ($m->type === $fin && $abierta) {
                $cierre = Carbon::parse($fecha . ' ' . substr((string) $m->time, 0, 8), $tz);
                $total += max(0, $abierta->diffInMinutes($cierre));
                $ultimoFin = substr((string) $m->time, 0, 5);
                $abierta = null;
            }
        }

        return ['minutos' => $total, 'inicio' => $primerInicio, 'fin' => $ultimoFin, 'abierta' => $abierta !== null];
    }
}
