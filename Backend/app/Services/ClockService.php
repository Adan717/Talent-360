<?php

namespace App\Services;

use App\Models\ContingencyDeclaration;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Helpers\TenantTimezone;
use App\Support\JornadaLaboral;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RECONCILIACIÓN merge/reloj-v2 (F3): este archivo une la línea del Reloj (dial v2, R1–R104,
 * autoridad en seguridad/amnistía/offline/nómina LFT) con las features anchas de la línea del
 * contrato §1–§42 (Simulador Matrix, Ley Silla, snapshot inmutable, candado de nómina cerrada,
 * anti-GPS-falso, evento TimeEntryRecorded). Cada bloque conserva el comentario de su línea de
 * origen; los puntos de arbitraje están marcados con "ARBITRAJE F3".
 */
class ClockService
{
    protected MockLocationDetector $mockLocationDetector;
    protected SimulatorSessionService $simulatorSessionService;

    public function __construct(SimulatorSessionService $simulatorSessionService)
    {
        $this->mockLocationDetector = new MockLocationDetector();
        $this->simulatorSessionService = $simulatorSessionService;
    }

    /**
     * Tipos de registro AUXILIARES (no son ponches de asistencia): se insertan tal
     * cual y no cuentan como asistencia ni pasan por las reglas de fichaje. Fuente
     * única de verdad usada por ClockController::sync y por el cálculo de nómina.
     */
    public const AUXILIARY_ENTRY_TYPES = ['meal_reservation', 'meal_cancel', 'meal_swap'];

    /**
     * Vocabulario COMPLETO de tipos de ponche aceptados. Whitelist única (los controllers validan
     * contra ella). Unión de ambas líneas: asistencia, pausas, salidas temporales, amnistía,
     * auxiliares, contingencia y Ley Silla (§25: tipo propio para que nómina/reportes distingan
     * un descanso obligatorio por ley de uno ordinario).
     */
    public const PUNCH_TYPES = [
        'check_in', 'check_out', 'waiting',
        'meal_start', 'meal_end', 'break_start', 'break_end',
        'temp_exit_start', 'temp_exit_end', 'contingency',
        'meal_reservation', 'meal_cancel', 'meal_swap',
        'silla_start', 'silla_end',
    ];

    /** Alias de compatibilidad: los controllers de la línea §1–§42 validan contra ALLOWED_TYPES. */
    public const ALLOWED_TYPES = self::PUNCH_TYPES;

    /** Defaults de mealSettings (espejo del FE: RelojVisual usa `|| 3`, `?? 13`, etc.). */
    private const MEAL_DEFAULTS = ['maxChairs' => 3, 'stepMins' => 15, 'startHour' => 13, 'endHour' => 17];
    private const MEAL_MINUTES_DEFAULT = 60;

    /**
     * Enforcement SERVER-SIDE del aforo de comida (R65, follow-up (b) de la escala de R63). El FE
     * (RelojVisual) ya valida el aforo `maxChairs`, un choque de puesto (`preventRoleOverlap`) y una
     * reserva por persona, pero SÓLO en el cliente — un cliente que salte el FE podía sobre-reservar
     * (el server insertaba cualquier `meal_reservation` sin mirar). Este guard replica la regla en el
     * servidor y es la única garantía real.
     *
     * Devuelve un MOTIVO (string) si la reserva debe rechazarse, o null si se permite. Modelo: cada
     * `meal_reservation` ocupa [inicio, inicio + mealMinutes_del_usuario) redondeado a bloques de
     * `stepMins`; `meal_cancel` anula la reserva del usuario; `meal_swap` se ignora para el conteo
     * (un intercambio preserva el conteo por bloque: uno sale y otro entra simétricamente).
     */
    public function mealReservationConflict(User $user, string $date, ?string $time): ?string
    {
        $tenantId = (int) ($user->tenant_id ?? 1);
        $settings = $this->mealSettings($tenantId);
        $maxChairs = max(1, (int) ($settings['maxChairs'] ?? self::MEAL_DEFAULTS['maxChairs']));
        $stepMins = max(1, (int) ($settings['stepMins'] ?? self::MEAL_DEFAULTS['stepMins']));
        $preventRoleOverlap = (bool) ($settings['preventRoleOverlap'] ?? false);

        $startMin = $this->minutesOfDay($time);
        // Una reserva sin hora interpretable no ocupa ningún bloque real → se rechaza (antes se
        // permitía y dejaba una fila fantasma que ninguna regla contaba). El FE siempre manda "HH:MM".
        if ($startMin === null) {
            return 'Horario de reserva inválido.';
        }

        // Datos del reservante desde el EXPEDIENTE (misma fuente que los demás, para que el choque de
        // puesto sea simétrico): mealMinutes (duración) y job_role_id (puesto).
        [$myMealMinutes, $myRole] = $this->employeeMealInfo($tenantId, (int) $user->id);
        $myDur = $this->mealDurationMinutes($myMealMinutes, $stepMins);
        $myEnd = $startMin + $myDur;

        // Regla A: una reserva ACTIVA por persona y día (espejo de `hasReservedMeal` del FE). Corta el
        // abuso más directo — un cliente pidiendo N reservas para sí mismo.
        if ($this->hasActiveMealReservation($tenantId, (int) $user->id, $date)) {
            return 'Ya tienes una reserva de comida para hoy.';
        }

        $others = $this->activeMealReservations($tenantId, $date, (int) $user->id, $stepMins);

        // Aforo = "a lo más maxChairs personas en el mismo instante". Se evalúa por BARRIDO DE
        // INSTANTES (no por muestreo de una malla fija): la concurrencia de los intervalos existentes
        // dentro de mi rango sólo crece en mi inicio o en el inicio de otra reserva, así que basta
        // revisar esos puntos. Esto detecta solapamientos de cola de cualquier tamaño (un intervalo no
        // alineado a la malla que el muestreo por bloques se saltaba).
        $instants = [$startMin];
        foreach ($others as $o) {
            if ($o['start'] >= $startMin && $o['start'] < $myEnd) {
                $instants[] = $o['start'];
            }
        }
        foreach ($instants as $t) {
            $covering = array_filter($others, fn($o) => $o['start'] <= $t && $t < $o['start'] + $o['dur']);
            if (count($covering) >= $maxChairs) {
                return 'Aforo lleno para ese horario de comida.';
            }
            if ($preventRoleOverlap && $myRole !== null) {
                foreach ($covering as $o) {
                    if ($o['jobRoleId'] !== null && (int) $o['jobRoleId'] === $myRole) {
                        return 'Ya hay alguien de tu mismo puesto comiendo en ese horario.';
                    }
                }
            }
        }
        return null;
    }

    /**
     * ¿El switch del dial (`clockOpConfig.enabledDialerFeatures.<key>`) está activo para el tenant?
     * Convención compartida con el FE: clave AUSENTE = ON (`!== false`). R93 (D2): los switches que
     * gatean acciones con efecto server-side real se enforcean también en el servidor — hasta ahora
     * apagarlos sólo escondía el botón y un cliente crudo disparaba la acción igual. Si `$settings`
     * (las filas de system_settings ya cargadas) se pasa, no re-consulta la BD.
     */
    public static function dialerFeatureEnabled(int $tenantId, string $key, ?array $settings = null): bool
    {
        $cfgRaw = $settings !== null
            ? ($settings['clockOpConfig'] ?? null)
            : \DB::table('system_settings')->where('tenant_id', $tenantId)->where('key', 'clockOpConfig')->value('value');
        $cfg = is_string($cfgRaw) ? (json_decode($cfgRaw, true) ?: []) : [];

        return ($cfg['enabledDialerFeatures'][$key] ?? null) !== false;
    }

    /** mealSettings del tenant (system_settings key 'mealSettings'), decodificado; [] si no hay. */
    private function mealSettings(int $tenantId): array
    {
        $row = \DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', 'mealSettings')
            ->value('value');
        if (!$row) {
            return [];
        }
        $decoded = json_decode($row, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Datos de comida del expediente del usuario: [mealMinutes (default 60 si nulo/0), job_role_id
     * (o null)]. Se lee del expediente para que el choque de puesto use la MISMA fuente que los otros
     * reservantes (evita el falso negativo de comparar users.job_role_id contra employees.job_role_id).
     */
    private function employeeMealInfo(int $tenantId, int $userId): array
    {
        $emp = \DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first(['mealMinutes', 'job_role_id']);
        $mealMinutes = $emp && $emp->mealMinutes ? (int) $emp->mealMinutes : self::MEAL_MINUTES_DEFAULT;
        $jobRoleId = $emp && $emp->job_role_id !== null ? (int) $emp->job_role_id : null;
        return [$mealMinutes, $jobRoleId];
    }

    /** Duración de la comida redondeada a bloques de stepMins (neededBlocks * stepMins), como el FE. */
    private function mealDurationMinutes(int $mealMinutes, int $stepMins): int
    {
        $neededBlocks = (int) ceil(max(1, $mealMinutes) / $stepMins);
        return $neededBlocks * $stepMins;
    }

    /** ¿Este usuario tiene una reserva de comida ACTIVA hoy? (última reservation sin cancel posterior). */
    private function hasActiveMealReservation(int $tenantId, int $userId, string $date): bool
    {
        $lastReservation = \DB::table('time_entries')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('date', $date)->where('type', 'meal_reservation')
            ->max('id');
        if (!$lastReservation) {
            return false;
        }
        $lastCancel = \DB::table('time_entries')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('date', $date)->where('type', 'meal_cancel')
            ->max('id');
        return !$lastCancel || $lastReservation > $lastCancel;
    }

    /**
     * Reservas de comida ACTIVAS de OTROS usuarios ese día. Para cada usuario, su reserva vigente es
     * la última `meal_reservation` sin una `meal_cancel` posterior. Devuelve
     * [ ['userId','jobRoleId','start'(min),'dur'(min)], ... ].
     */
    private function activeMealReservations(int $tenantId, string $date, int $excludeUserId, int $stepMins): array
    {
        $rows = \DB::table('time_entries')
            ->where('tenant_id', $tenantId)->where('date', $date)
            ->whereIn('type', ['meal_reservation', 'meal_cancel'])
            ->where('user_id', '!=', $excludeUserId)
            ->orderBy('id')
            ->get(['user_id', 'type', 'time']);

        // Replay por usuario: la última reserva gana; una cancel posterior la anula.
        $state = []; // userId => ['time'=>..., 'active'=>bool]
        foreach ($rows as $r) {
            if ($r->type === 'meal_reservation') {
                $state[$r->user_id] = ['time' => $r->time, 'active' => true];
            } elseif ($r->type === 'meal_cancel' && isset($state[$r->user_id])) {
                $state[$r->user_id]['active'] = false;
            }
        }

        $activeUserIds = [];
        foreach ($state as $uid => $s) {
            if ($s['active']) {
                $activeUserIds[] = $uid;
            }
        }
        if (empty($activeUserIds)) {
            return [];
        }

        // mealMinutes + job_role_id de los reservantes activos en una sola query.
        $emps = \DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $activeUserIds)
            ->get(['user_id', 'mealMinutes', 'job_role_id'])
            ->keyBy('user_id');

        $result = [];
        foreach ($activeUserIds as $uid) {
            $start = $this->minutesOfDay($state[$uid]['time']);
            if ($start === null) {
                continue;
            }
            $emp = $emps->get($uid);
            $mealMinutes = $emp && $emp->mealMinutes ? (int) $emp->mealMinutes : self::MEAL_MINUTES_DEFAULT;
            $result[] = [
                'userId' => (int) $uid,
                'jobRoleId' => $emp && $emp->job_role_id !== null ? (int) $emp->job_role_id : null,
                'start' => $start,
                'dur' => $this->mealDurationMinutes($mealMinutes, $stepMins),
            ];
        }
        return $result;
    }

    /** Minutos desde medianoche de una hora ("14:00", "14:00:00", "2:00 PM"); null si no se interpreta. */
    private function minutesOfDay(?string $time): ?int
    {
        if (!is_string($time) || trim($time) === '') {
            return null;
        }
        try {
            $c = Carbon::parse(trim($time));
            return $c->hour * 60 + $c->minute;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Minutos de exceso de una serie de pares start/end (comida o descanso) en un día.
     * Los ponches no traen duración, así que se emparejan por orden cronológico con una
     * pila LIFO (soporta múltiples pausas e ignora start/end huérfanos). Un par excede si
     * su duración > (permitido + tolerancia); el exceso es (duración − permitido). Se
     * compara con Carbon sobre "$dateStr $time" (misma fecha → nunca negativo).
     */
    private function pairedExcessMinutes($dayEntries, string $dateStr, string $startType, string $endType, int $allowed, int $tolerance): int
    {
        $ordered = $dayEntries
            ->whereIn('type', [$startType, $endType])
            ->sortBy('time')
            ->values();

        $openStarts = [];
        $excess = 0;
        foreach ($ordered as $e) {
            if ($e->type === $startType) {
                $openStarts[] = $e->time;
            } elseif ($e->type === $endType && !empty($openStarts)) {
                $startTime = array_pop($openStarts);
                $mins = (int) abs(
                    Carbon::parse("$dateStr {$e->time}")->diffInMinutes(Carbon::parse("$dateStr $startTime"))
                );
                if ($mins > ($allowed + $tolerance)) {
                    $excess += max(0, $mins - $allowed);
                }
            }
        }

        return $excess;
    }

    /**
     * Normaliza una hora de salida a H:i:s de forma tolerante: acepta null/''/'H:i'/'H:i:s'
     * y cualquier valor raro cae al default 18:00:00 (evita un 500 al parsear).
     */
    private function normalizeExitTime($shiftEnd): string
    {
        $val = is_string($shiftEnd) ? trim($shiftEnd) : '';
        if ($val === '') {
            return '18:00:00';
        }
        if (strlen($val) === 5) {
            $val .= ':00';
        }
        try {
            return Carbon::createFromFormat('H:i:s', $val)->format('H:i:s');
        } catch (\Exception $e) {
            return '18:00:00';
        }
    }

    public function processPunch(User $user, $type, $simTime = null, $details = [], $occurredAt = null, $clientStamp = null)
    {
        // Validar tipo de evento antes de cualquier operación (whitelist única de la unión).
        if (!in_array($type, self::PUNCH_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de fichaje inválido: '{$type}'. Valores permitidos: " . implode(', ', self::PUNCH_TYPES));
        }

        $tenantId = $user->tenant_id ?? 1;
        $settings = \DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->pluck('value', 'key')
            ->toArray();

        // El modo simulado deja que el CLIENTE fije la hora del ponche (herramienta de QA/demo). En
        // PRODUCCIÓN la asistencia es un registro legal (LFT) y anti-fraude: se ignora el modo
        // simulado siempre, aunque un admin haya activado el setting, y se usa la hora del servidor.
        $isSimulated = !app()->isProduction()
            && isset($settings['time_mode'])
            && json_decode($settings['time_mode'], true) === 'simulated';

        // Resolver de tz compartido: valida contra DateTimeZone y decodifica el valor
        // json-encoded. Devuelve siempre una zona válida.
        $timezone = TenantTimezone::fromSettings($settings);

        // Simulador Matrix (§13): los fichajes marcados is_simulator se ligan a la sesión activa
        // del tenant y usan su simulated_date en vez de la fecha real — así corridas
        // sucesivas del simulador nunca chocan entre sí, y sus filas quedan aisladas de los
        // reportes reales (ver ExcludeSimulationScope).
        $isSimulatorPunch = isset($details['is_simulator']) && $details['is_simulator'] === true;
        $simulationSessionId = null;
        $simulatorSession = null;
        if ($isSimulatorPunch) {
            $simulatorSession = $this->simulatorSessionService->getActiveSession($tenantId, $user->id);
            $simulationSessionId = $simulatorSession->id;
        }

        // R84 (offline-first): un ponche OFFLINE se registra en su MOMENTO original (inmutabilidad
        // histórica, LFT), no en la hora de sincronización. `$occurredAt` es un instante UTC (ISO)
        // que el batch endpoint pasa; se lleva a la tz del tenant. El flag `offline_sync` (protocolo
        // batch de la línea §16) es el mismo concepto con la hora en `simTime`.
        $isOfflineSyncFlag = isset($details['offline_sync']) && $details['offline_sync'] === true;
        $isOffline = $occurredAt !== null || $isOfflineSyncFlag;

        // H21: el turno del colaborador se lee AQUÍ porque decide a qué DÍA pertenece el fichaje.
        // Con un turno que cruza medianoche (22:00–02:00), la salida de las 02:00 es el cierre de
        // la jornada de AYER, no el comienzo de la de hoy. Ver `JornadaLaboral` para la regla y
        // para lo que costaba no tenerla (una noche se pagaba como dos días).
        $turnoDelColaborador = \DB::table('employees')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->first(['shiftStart', 'shiftEnd']);
        $turnoInicio = $turnoDelColaborador->shiftStart ?? null;
        $turnoFin = $turnoDelColaborador->shiftEnd ?? null;

        if ($occurredAt !== null) {
            $now = Carbon::parse($occurredAt)->setTimezone($timezone);
            $date = JornadaLaboral::fechaDeNegocio($now, $turnoInicio, $turnoFin);
            $time = $now->format('H:i:s');
        } else {
            $now = Carbon::now($timezone);
            $date = $simulatorSession
                ? Carbon::parse($simulatorSession->simulated_date)->format('Y-m-d')
                : JornadaLaboral::fechaDeNegocio($now, $turnoInicio, $turnoFin);

            if (($isSimulated || $isSimulatorPunch || $isOfflineSyncFlag) && $simTime) {
                // El simulador/batch envía algo como "09:30:00". Se normaliza a H:i:s: algunos
                // clientes mandan "H:i" (sin segundos), que rompería createFromFormat. Si el
                // formato no se reconoce, se cae a la hora del servidor, sin reventar.
                $simHis = strlen(trim((string) $simTime)) === 5 ? trim((string) $simTime) . ':00' : trim((string) $simTime);
                try {
                    // La hora simulada se ancla al día calendario que el cliente tenía delante;
                    // el corte de jornada se aplica DESPUÉS sobre ese instante ya construido.
                    $diaBase = $simulatorSession
                        ? $date
                        : $now->format('Y-m-d');
                    $now = Carbon::createFromFormat('Y-m-d H:i:s', "$diaBase $simHis", $timezone);
                    $time = $simHis;
                    // El simulador fija su propia fecha a propósito (§13: replay sobre fechas
                    // arbitrarias); fuera de él, la jornada manda.
                    if (!$simulatorSession) {
                        $date = JornadaLaboral::fechaDeNegocio($now, $turnoInicio, $turnoFin);
                    }
                } catch (\Exception $e) {
                    $time = $now->format('H:i:s');
                }
            } else {
                $time = $now->format('H:i:s');
            }
        }

        // Baja operativa (R89, T4.1): un colaborador con `is_active_employee = false` (archivado) NO
        // puede INICIAR turno EN VIVO por NINGÚN camino.
        //  - SÓLO check_in: no se estrangula a quien fue archivado a mitad de turno (puede cerrar/comer).
        //  - EXENTO OFFLINE: un ponche encolado ocurrió en su momento REAL, cuando la persona estaba
        //    activa; rechazarlo por el estado de HOY borraría un día trabajado legítimo (R84/R88).
        if ($type === 'check_in' && !$isOffline) {
            $expediente = method_exists($user, 'expediente') ? $user->expediente() : ($user->employee ?? null);
            if ($expediente && $expediente->is_active_employee === false) {
                throw new \Exception('Fichaje Denegado: este colaborador está dado de baja y no puede iniciar turno.');
            }
        }

        // Ventana de comida (R91, T4.3 / N7): la comida no puede EMPEZAR hasta que el colaborador lleva
        // `mealSettings.minWorkMinutes` de trabajo en su segmento ACTUAL. Opt-in por-tenant (0 = sin
        // ventana). Hard-reject SERVER-SIDE. Se ancla al ÚLTIMO check_in (el segmento ABIERTO).
        // EXENTO offline (criterio R88/R89).
        if ($type === 'meal_start' && !$isOffline) {
            $minWork = (int) ($this->mealSettings($tenantId)['minWorkMinutes'] ?? 0);
            if ($minWork > 0) {
                $lastAttendance = $this->lastEntryOfTypes($user->id, $date, ['check_in', 'check_out'], $isSimulatorPunch, $simulationSessionId);
                if (!$lastAttendance || $lastAttendance->type !== 'check_in') {
                    throw new \Exception('No puedes iniciar tu comida: no tienes un turno abierto.');
                }
                // H21: pegar "$date $time" a secas coloca los fichajes de madrugada 24 h antes de
                // cuando ocurrieron (se guardan con la fecha de la jornada, que empezó ayer), y el
                // "llevas X min de turno" salía disparado. `instanteDe` deshace ese desfase.
                $checkInAt = JornadaLaboral::instanteDe(
                    $date, $lastAttendance->time, $turnoInicio, $turnoFin, $timezone
                );
                $worked = (int) abs($checkInAt->diffInMinutes($now));
                if ($worked < $minWork) {
                    $faltan = $minWork - $worked;
                    throw new \Exception("Aún no puedes tomar tu comida: llevas {$worked} min de turno; debes cumplir {$minWork} min (faltan {$faltan}).");
                }
            }
        }

        // Switches del dial con efecto REAL (R93, D2).
        if ($type === 'waiting' && !self::dialerFeatureEnabled($tenantId, 'enable_proximity_check', $settings)) {
            throw new \Exception('El registro de cercanía ("Ya llegué") está deshabilitado para esta sucursal.');
        }
        if ($type === 'temp_exit_start' && !$isOffline && !self::dialerFeatureEnabled($tenantId, 'enable_temp_exit', $settings)) {
            throw new \Exception('Las salidas temporales están deshabilitadas para esta sucursal.');
        }

        // Inmutabilidad de la nómina consolidada (línea §1–§42, Fase 2 Solidez): un periodo aprobado
        // o pagado no admite ponches nuevos dentro de su rango.
        $closedPayroll = DB::table('weekly_payrolls')
            ->where('tenant_id', $tenantId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->whereIn('status', ['approved', 'paid'])
            ->exists();
        if ($closedPayroll) {
            throw new \Exception("Fichaje Denegado: El período de nómina para la fecha {$date} ya ha sido aprobado o pagado y se encuentra bloqueado para modificaciones.");
        }

        // ARBITRAJE F3 — Máquina de estados de secuencia (§15 reconciliada con turno partido R63/R68):
        // la línea §15 exigía "predecesor existe hoy" + UNIQUE(user,date,type), lo que prohibía el
        // turno PARTIDO (check_in→check_out→check_in) y las pausas múltiples, ambos flujos legítimos
        // de la línea del Reloj. La regla unificada mira el ESTADO (último marcador), no la mera
        // existencia: conserva los rechazos del contrato (meal_end sin meal_start, check_out sin
        // check_in, meal_start después de cerrar el día) y habilita los segmentos múltiples.
        // EXENTO OFFLINE (la cola sincroniza fragmentos cuyo ancla puede venir en otro lote, R84)
        // y exento para auxiliares/waiting/contingency (no son ponches de asistencia).
        // La regla se acota a la superficie CONTRACTUAL testeada de §15 (TaskAndSequencePendingItems):
        //  - check_out exige turno abierto ("sin un 'check_in' previo");
        //  - un *_start tras cerrar el día se rechaza ("después de cerrar el día");
        //  - meal_end/silla_end exigen su start ABIERTO (pareo por estado → pausas múltiples OK).
        // break_end/temp_exit_end NO se bloquean (línea Reloj: quien ya está fuera debe poder
        // registrar su regreso), y los *_start no exigen check_in previo (movimiento interno).
        $pairRules = [
            'meal_end' => 'meal_start',
            'silla_end' => 'silla_start',
        ];
        $startsBlockedAfterClose = ['meal_start', 'break_start', 'temp_exit_start', 'silla_start'];

        if ($type === 'check_in') {
            // R63: guard de ESTADO contra check_in duplicado (doble toque, retry de red, REINTENTO
            // DE LA COLA OFFLINE — por eso corre también con $isOffline). NO es "uno por día": un
            // check_in sólo se rechaza si hay un turno ABIERTO. Idempotente, devuelve la entrada
            // existente sin crear fila (la cola offline no debe recibir error).
            $lastAttendance = $this->lastEntryOfTypes($user->id, $date, ['check_in', 'check_out'], $isSimulatorPunch, $simulationSessionId);
            if ($lastAttendance && $lastAttendance->type === 'check_in') {
                return [
                    'success' => true,
                    'message' => 'Ya tienes una entrada registrada sin salida.',
                    'entry' => $lastAttendance,
                    'duplicate' => true,
                ];
            }
        }

        if (!$isOffline && !in_array($type, self::AUXILIARY_ENTRY_TYPES, true) && $type !== 'waiting' && $type !== 'contingency') {
            if ($type === 'check_out') {
                $lastAttendance = $this->lastEntryOfTypes($user->id, $date, ['check_in', 'check_out'], $isSimulatorPunch, $simulationSessionId);
                if (!$lastAttendance || $lastAttendance->type !== 'check_in') {
                    throw new \Exception("Fichaje Denegado: no se puede registrar '{$type}' sin un 'check_in' previo el mismo día.");
                }
            } elseif (in_array($type, $startsBlockedAfterClose, true)) {
                $lastAttendance = $this->lastEntryOfTypes($user->id, $date, ['check_in', 'check_out'], $isSimulatorPunch, $simulationSessionId);
                if ($lastAttendance && $lastAttendance->type === 'check_out') {
                    throw new \Exception("Fichaje Denegado: ya se registró 'check_out' hoy, no se puede registrar '{$type}' después de cerrar el día.");
                }
            }

            if (isset($pairRules[$type])) {
                $startType = $pairRules[$type];
                $lastPair = $this->lastEntryOfTypes($user->id, $date, [$startType, $type], $isSimulatorPunch, $simulationSessionId);
                if (!$lastPair || $lastPair->type !== $startType) {
                    throw new \Exception("Fichaje Denegado: no se puede registrar '{$type}' sin un '{$startType}' previo el mismo día.");
                }
            }
        }

        // §25: Ley Silla — silla_start exige una solicitud 'approved' (el supervisor ya
        // autorizó) y respeta el aforo máximo simultáneo antes de dejar iniciar.
        $sillaRequestForThisPunch = null;
        if ($type === 'silla_start') {
            $sillaRequestForThisPunch = \App\Models\SillaRequest::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $user->id)
                ->where('status', 'approved')
                ->whereDate('requested_at', $date)
                ->orderByDesc('requested_at')
                ->first();

            if (!$sillaRequestForThisPunch) {
                throw new \Exception('Fichaje Denegado: no tienes una solicitud de Ley Silla aprobada por tu supervisor.');
            }

            $clockOpConfig = isset($settings['clockOpConfig']) ? json_decode($settings['clockOpConfig'], true) : [];
            $maxSimultaneous = $clockOpConfig['sillas_maximas_simultaneas'] ?? 1;
            $activeCount = \App\Models\SillaRequest::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereDate('started_at', $date)
                ->count();

            if ($activeCount >= $maxSimultaneous) {
                throw new \Exception('Fichaje Denegado: el aforo máximo de sillas simultáneas está lleno. Espera tu turno.');
            }
        }

        // Día festivo no laborable configurado para bloquear la aplicación.
        $holidayBlock = \DB::table('lft_holidays')
            ->where('tenant_id', $user->tenant_id ?? 1)
            ->where('date', $date)
            ->where('block_app', true)
            ->first();

        if ($holidayBlock) {
            // R81 (Laborar Horas Extras real): una autorización de supervisor para HOY levanta el
            // bloqueo. La emite /clock/authorize-overtime tras validar la credencial server-side.
            $overtimeAuthorized = \DB::table('overtime_authorizations')
                ->where('tenant_id', $user->tenant_id ?? 1)
                ->where('user_id', $user->id)
                ->where('date', $date)
                ->exists();

            if (!$overtimeAuthorized) {
                throw new \Exception("Fichaje Denegado: Hoy es día festivo oficial obligatorio ({$holidayBlock->name}) y el sistema se encuentra bloqueado.");
            }
        }

        // Obtener el plan del tenant
        $tenant = \App\Models\Tenant::find($user->tenant_id ?? 1);
        $isPro = $tenant && in_array(strtolower($tenant->plan ?? 'freemium'), ['pro', 'enterprise']);

        // 1. IP Lock (Plan Gratuito)
        if (!$isPro && ($type === 'check_in' || $type === 'check_out')) {
            $clockOpConfigRaw = $settings['clockOpConfig'] ?? null;
            $clockOpConfig = $clockOpConfigRaw ? json_decode($clockOpConfigRaw, true) : [];
            $ipLockEnabled = $clockOpConfig['ip_lock_enabled'] ?? false;
            $storeIp = $clockOpConfig['store_ip_address'] ?? null;
            if ($ipLockEnabled && $storeIp) {
                $clientIp = request()->ip();
                if ($clientIp !== $storeIp && $clientIp !== '127.0.0.1' && $clientIp !== '::1' && !isset($details['sandbox_bypass'])) {
                    throw new \Exception("Fichaje Denegado: No estás conectado a la red Wi-Fi autorizada de la sucursal (IP incorrecta).");
                }
            }
        }

        // 2. Bloqueo de entrada si la tienda está cerrada — TODOS los planes (R76).
        // Se OMITE cuando el tenant no designó a nadie para abrir. `can_open_store` es el permiso
        // real (R46). EXENTO el ponche del simulador (§13: replay QA sobre fechas arbitrarias).
        if ($type === 'check_in' && !$isSimulatorPunch) {
            $encargados = \DB::table('store_opening_assignments')
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->where('can_open_store', true)
                ->orderBy('priority_order', 'asc')
                ->get(['employee_id']);

            if ($encargados->isNotEmpty()) {
                $openingStatus = \DB::table('store_daily_opening_statuses')
                    ->where('tenant_id', $user->tenant_id)
                    ->whereDate('date', $date)
                    ->first();

                $isOpened = $openingStatus && $openingStatus->status === 'opened';

                $responsableUserId = $openingStatus->current_responsible_employee_id ?? null;
                if (!$responsableUserId) {
                    $responsableUserId = \App\Models\Employee::withoutGlobalScopes()
                        ->where('id', $encargados->first()->employee_id)
                        ->value('user_id');
                }

                $isOpeningManager = $responsableUserId !== null
                    && intval($user->id) === intval($responsableUserId);

                if (!$isOpened && !$isOpeningManager) {
                    throw new \Exception("Fichaje Denegado: La tienda física se encuentra cerrada. Debes esperar a que el encargado realice la apertura.");
                }
            }
        }

        // 2.b Checklist de cierre de la línea §1–§42 (store_opening_events): bloqueo de salida si el
        // módulo store_opening está activo y `store_opening_settings.require_closing_checklist` lo
        // exige. Coexiste con el checklist por-punch de la línea del Reloj (más abajo): son dos
        // flags distintos, ambos default off.
        if ($type === 'check_out' && !$isOffline && class_exists(\App\Services\FeatureAccessService::class)
            && \App\Services\FeatureAccessService::tenantHasFeature($tenantId, 'store_opening')) {
            $closingSettings = \DB::table('store_opening_settings')
                ->where('tenant_id', $tenantId)
                ->first();

            $requiresClosingChecklist = $closingSettings ? (bool) $closingSettings->require_closing_checklist : false;

            if ($requiresClosingChecklist) {
                $hasCompletedChecklist = \DB::table('store_opening_events')
                    ->where('tenant_id', $tenantId)
                    ->where('event_type', 'closing_checklist')
                    ->where('event_status', 'success')
                    ->where('event_time', '>=', now()->subHours(24))
                    ->exists();

                if (!$hasCompletedChecklist) {
                    throw new \Exception("Completa el checklist de cierre antes de registrar salida.");
                }
            }
        }

        // 3. Geocerca validada en SERVIDOR (línea Reloj: clockOpConfig.storeLocation + gpsAlertRangeMeters).
        if ($type === 'check_in' || $type === 'check_out') {
            $geoConfigRaw = $settings['clockOpConfig'] ?? null;
            $geoConfig = is_string($geoConfigRaw) ? (json_decode($geoConfigRaw, true) ?: []) : [];
            $geoEnabled = ($geoConfig['gpsValidationEnabled'] ?? false) === true
                && !($geoConfig['allowManualCheckIn'] ?? false)
                && empty($details['supervisor_override']);
            $storeLoc = $geoConfig['storeLocation'] ?? null;

            if ($geoEnabled && is_array($storeLoc) && isset($storeLoc['lat'], $storeLoc['lng'])) {
                $gps = $details['gps'] ?? null;
                if (!is_array($gps) || !isset($gps['latitude'], $gps['longitude'])) {
                    throw new \Exception("Fichaje Denegado: Se requiere tu ubicación GPS para validar que estás en la sucursal.");
                }
                $radius = max(1.0, (float) ($geoConfig['gpsAlertRangeMeters'] ?? 100));
                $distance = $this->distanceMeters(
                    (float) $gps['latitude'], (float) $gps['longitude'],
                    (float) $storeLoc['lat'], (float) $storeLoc['lng']
                );
                if ($distance > $radius) {
                    throw new \Exception(
                        'Fichaje Denegado: Estás fuera del perímetro autorizado de la sucursal (' .
                        round($distance) . ' m; máximo ' . round($radius) . ' m).'
                    );
                }
            }
        }

        // 3.b GPS VALIDATION de la línea §1–§42 — MockLocation Detection + Geofence
        // (clockOpConfig.store_latitude/store_longitude). Detecta apps de GPS falso y valida el
        // perímetro con configuración propia; coexiste con la geocerca anterior (claves distintas).
        if ($isPro && $type === 'check_in' && isset($details['gps'])) {
            $gpsData = $details['gps'];
            $clockOpConfig = isset($settings['clockOpConfig'])
                ? json_decode($settings['clockOpConfig'], true)
                : [];

            $gpsEnabled = $clockOpConfig['gpsValidationEnabled'] ?? false;

            if ($gpsEnabled && is_array($gpsData)) {
                // a) Detectar GPS falso (Bypass si proviene del simulador Matrix QA)
                $isSimulator = isset($details['is_simulator']) || isset($details['sandbox_bypass']);

                // Mapear coordenadas del simulador de forma relativa a la tienda configurada
                if ($isSimulator && isset($gpsData['latitude'], $gpsData['longitude'])) {
                    $storeLat = $clockOpConfig['store_latitude']  ?? null;
                    $storeLng = $clockOpConfig['store_longitude'] ?? null;
                    if ($storeLat && $storeLng) {
                        if (abs($gpsData['latitude'] - 19.4326) < 0.0005) {
                            $gpsData['latitude'] = (float) $storeLat;
                            $gpsData['longitude'] = (float) $storeLng;
                        } else {
                            $gpsData['latitude'] = (float) $storeLat + 0.02;
                            $gpsData['longitude'] = (float) $storeLng + 0.02;
                        }
                    }
                }

                $mockResult = $this->mockLocationDetector->analyze($gpsData);

                if ($mockResult['is_mock'] && !$isSimulator) {
                    throw new \Exception(
                        "Fichaje Denegado: " . $mockResult['verdict'] .
                        " Desactiva las aplicaciones de GPS falso para poder fichar."
                    );
                }

                // b) Validar Geofence si la sucursal tiene coordenadas configuradas
                $storeLat = $clockOpConfig['store_latitude']  ?? null;
                $storeLng = $clockOpConfig['store_longitude'] ?? null;
                $geoRadius = $clockOpConfig['geo_radius_meters'] ?? 150;

                if ($storeLat && $storeLng && isset($gpsData['latitude'], $gpsData['longitude'])) {
                    $geoResult = $this->mockLocationDetector->validateGeofence(
                        (float) $gpsData['latitude'],
                        (float) $gpsData['longitude'],
                        (float) $storeLat,
                        (float) $storeLng,
                        (float) $geoRadius
                    );

                    if (!$geoResult['inside_geofence']) {
                        $strictGps = $clockOpConfig['strictGpsValidation'] ?? false;
                        if ($strictGps && !isset($details['sandbox_bypass'])) {
                            throw new \Exception("Fichaje Denegado: Te encuentras fuera del perímetro de la sucursal por " . round($geoResult['distance_meters'] ?? 0) . " metros.");
                        }
                        $details['gps_warning'] = $geoResult['message'];
                        $details['distance_meters'] = $geoResult['distance_meters'];
                    }
                }

                // Guardar metadata de GPS en los detalles del registro
                $details['gps_risk_level'] = $mockResult['risk_level'];
                $details['gps_flags']      = $mockResult['flags'];
            }
        }

        // Política de horario del empleado, anclada a la MISMA fecha y tz que $now (§61 —
        // ambas líneas arreglaron este mismo bug: con la tz por defecto UTC, un tenant en
        // America/Mexico_City volvía "tarde" a todo el mundo y late_minutes negativo con
        // decimales reventaba en Postgres 22P02). OJO: el turno vive en employees.shiftStart,
        // NO en users; lectura directa con filtro explícito de tenant (no depende del scope).
        // H21: se reutiliza el turno ya leído arriba (el mismo que decidió `$date`), en vez de
        // volver a consultarlo. Que la hora esperada y la fecha salgan de la MISMA lectura es lo
        // que hace que el retardo nocturno se corrija solo: `$expectedTime` se ancla a `$date`, y
        // con la fecha de negocio correcta un fichaje de las 00:30 se compara contra las 22:00 de
        // AYER —150 minutos— en vez de contra las 22:00 de hoy, que daba cero.
        $expectedTimeStr = $turnoInicio ?? '09:00:00';
        // Fallback del jefe: shiftStart malformado no debe tumbar el fichaje.
        try {
            $expectedTime = Carbon::createFromFormat('Y-m-d H:i:s', "$date $expectedTimeStr", $timezone);
        } catch (\Exception $e) {
            $expectedTime = Carbon::createFromFormat('Y-m-d H:i:s', "$date 09:00:00", $timezone);
        }

        // Políticas LFT del Tenant
        $lft = \App\Models\LftSetting::where('tenant_id', $user->tenant_id)->first();
        $toleranceMinutes = $lft ? $lft->late_tolerance_minutes : 10;
        $lateActionMode = $lft ? $lft->late_action_mode : 'deduct';

        $isLate = false;
        $lateMinutes = 0;
        // ARBITRAJE F3 — Amnistía SOLO server-side (spec §2, Amnistía Condicionada): aplica
        // EXCLUSIVAMENTE si el empleado marcó "Ya llegué" (`waiting`) DENTRO de su ventana de
        // tolerancia. El flag `has_amnesty` del cliente de la línea §1–§42 era falsificable
        // (bastaba mandarlo para anular el retardo) y se ELIMINA — regresión caracterizada por
        // AmnestyWindowTest::test_client_amnesty_flag_is_ignored.
        $hasAmnesty = false;
        // hora_llegada_virtual (spec:30,41): el inicio real de jornada es el "Ya llegué"
        // marcado dentro de ventana; se estampa en el check_in para nómina/métricas.
        $virtualArrival = null;

        // Declaración de Eventualidad de la línea §1–§42 (sin luz / sin internet): mientras esté
        // activa para el tenant y la fecha, la jornada queda protegida — se salta el cálculo de
        // retardo igual que la amnistía por espera. (El pago 100% de nómina de la línea del Reloj
        // sigue detrás del gate humano de contingency_days; ver calculatePayrollForEmployee.)
        $hasContingency = ContingencyDeclaration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('date', $date)
            ->whereNull('resolved_at')
            ->exists();

        if ($type === 'check_in') {
            if ($hasContingency) {
                $hasAmnesty = true;
            }

            if (!$hasAmnesty) {
                // Amnistía SOLO si existe un "Ya llegué" (waiting) DENTRO de la ventana
                // [entrada - tol, entrada + tol] (spec §2). Comparación por string H:i:s
                // (mismo día, ancho fijo: orden lexicográfico = cronológico).
                $lowerBound = $expectedTime->copy()->subMinutes($toleranceMinutes)->format('H:i:s');
                $upperBound = $expectedTime->copy()->addMinutes($toleranceMinutes)->format('H:i:s');
                $proximityRecord = \DB::table('time_entries')
                    ->where('user_id', $user->id)
                    ->where('date', $date)
                    ->where('type', 'waiting')
                    ->whereBetween('time', [$lowerBound, $upperBound])
                    ->first();
                if ($proximityRecord) {
                    $hasAmnesty = true;
                    $virtualArrival = $proximityRecord->time;
                }
            }
        }

        // R68: el retardo sólo se evalúa para el PRIMER check_in del día (el arranque real del turno).
        // Un 2º check_in es la reanudación de una jornada PARTIDA.
        $esPrimerCheckInDelDia = $type === 'check_in' && !TimeEntry::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->where('type', 'check_in')
            ->when($isSimulatorPunch,
                fn ($q) => $q->where('simulation_session_id', $simulationSessionId),
                fn ($q) => $q->whereNull('simulation_session_id'))
            ->exists();

        // Amnistía por REPORTE DE TIENDA CERRADA (R90, T4.2): amnistía de EQUIPO. Si HOY se reportó
        // la tienda cerrada (`late_amnesty_granted`) Y la tienda ABRIÓ TARDE, quien haga su PRIMER
        // check_in dentro de la tolerancia de la apertura REAL fue víctima del retraso → amnistía.
        if ($type === 'check_in' && !$hasAmnesty && $esPrimerCheckInDelDia) {
            $openingStatus = \DB::table('store_daily_opening_statuses')
                ->where('tenant_id', $user->tenant_id)
                ->whereDate('date', $date)
                ->first();
            if ($openingStatus
                && ($openingStatus->late_amnesty_granted ?? false)
                && $openingStatus->opened_at
                && $openingStatus->scheduled_opening_time) {
                $scheduledOpen = Carbon::parse("$date {$openingStatus->scheduled_opening_time}", $timezone);
                $openedAt = Carbon::parse($openingStatus->opened_at)->setTimezone($timezone);
                if ($openedAt->greaterThan($scheduledOpen)
                    && $now->lessThanOrEqualTo($openedAt->copy()->addMinutes($toleranceMinutes))) {
                    $hasAmnesty = true;
                    $virtualArrival = $scheduledOpen->format('H:i:s');
                }
            }
        }

        if ($type === 'check_in' && !$hasAmnesty && $esPrimerCheckInDelDia) {
            if ($now->greaterThan($expectedTime->copy()->addMinutes($toleranceMinutes))) {
                // §61: Carbon 3 devuelve un diff con signo y decimales. Se mide de
                // expectedTime -> now (positivo cuando el fichaje es posterior a la hora
                // esperada) y se castea a entero >= 0: así una llegada temprana nunca
                // marca retardo y nunca se escribe un decimal/negativo en la columna
                // entera late_minutes (causaba 22P02 en Postgres).
                $lateMinutes = (int) max(0, round($expectedTime->diffInMinutes($now)));
                if ($lateMinutes > 0) {
                    $isLate = true;
                }
            }
        }

        // Retardo Extremo (spec:46): si el retardo supera el umbral máximo configurado del
        // tenant, se BLOQUEA la jornada salvo override de un supervisor o solicitud aprobada (R56).
        $maxLateBlock = (int) ($lft?->max_late_block_minutes ?? 0);
        $supervisorOverride = isset($details['supervisor_override']) && $details['supervisor_override'] === true;
        if ($type === 'check_in' && $isLate && $maxLateBlock > 0 && $lateMinutes > $maxLateBlock && !$supervisorOverride) {
            $hasApprovedAuth = \DB::table('late_authorization_requests')
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('date', $date)
                ->where('status', 'approved')
                ->exists();

            if (!$hasApprovedAuth) {
                throw new \Exception("Fichaje Bloqueado: tu retardo de {$lateMinutes} min supera el máximo permitido ({$maxLateBlock} min). Un supervisor debe autorizar tu entrada.");
            }
        }

        // Estructurar detalles del retardo o compensación
        $detailsMerge = $details;
        // Señales transitorias del servidor: no se persisten en time_entries/audit_logs.
        unset($detailsMerge['supervisor_override']);
        unset($detailsMerge['via_kiosk']);
        unset($detailsMerge['early_departure']);
        // ARBITRAJE F3: `has_amnesty` y `offline_sync` son flags de transporte del cliente/batch,
        // no datos del ponche; el primero además era el vector de forja de amnistía.
        unset($detailsMerge['has_amnesty']);
        unset($detailsMerge['offline_sync']);

        if ($hasContingency && $type === 'check_in') {
            $detailsMerge['lft_incident'] = [
                'type' => 'contingency',
                'notes' => 'Jornada protegida al 100% por declaración de contingencia (sin luz / sin internet en sucursal).'
            ];
        }
        if ($hasAmnesty) {
            $detailsMerge['amnesty_applied'] = true;
            $detailsMerge['amnesty_note'] = 'Amnistía aplicada por apertura tardía de la sucursal.';
            if ($virtualArrival !== null) {
                // Inicio real de jornada (para que nómina/métricas paguen el tiempo esperando).
                $detailsMerge['hora_llegada_virtual'] = $virtualArrival;
            }
        }

        if ($isLate) {
            $detailsMerge['lft_incident'] = [
                'type' => 'late',
                'minutes' => $lateMinutes,
                'action_mode' => $lateActionMode,
                'notes' => $lateActionMode === 'extend_shift'
                    ? "Retardo de {$lateMinutes} min. Compensación requerida: salir {$lateMinutes} min tarde."
                    : "Retardo de {$lateMinutes} min. Descuento de salario o penalización acumulada."
            ];
        }

        // Salida Anticipada REAL (R92, Fase 4): si el check_out ocurre ANTES del shiftEnd del
        // expediente, se estampa un registro ESTRUCTURADO computado por el SERVIDOR. NO bloquea.
        if ($type === 'check_out') {
            $rawShiftEnd = \DB::table('employees')
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->value('shiftEnd');
            if (is_string($rawShiftEnd) && trim($rawShiftEnd) !== '') {
                $shiftEndAt = Carbon::createFromFormat(
                    'Y-m-d H:i:s', "$date " . $this->normalizeExitTime($rawShiftEnd), $timezone
                );
                if ($now->lessThan($shiftEndAt)) {
                    $authorized = \DB::table('early_departure_authorizations')
                        ->where('tenant_id', $user->tenant_id)
                        ->where('user_id', $user->id)
                        ->where('date', $date)
                        ->exists();
                    $detailsMerge['early_departure'] = [
                        'minutes_early' => (int) abs($now->diffInMinutes($shiftEndAt)),
                        'authorized' => $authorized,
                    ];
                }
            }
        }

        // Checklist Exprés de Cierre de la línea del Reloj (R88, T3.3): por-punch, con los 3 ticks
        // (luces/caja/alarma) en `details`. Opt-in vía lft_settings.require_closing_checklist.
        // EXENTOS: OFFLINE, KIOSKO (`via_kiosk`) y `supervisor_override` (ver R88).
        if ($type === 'check_out'
            && ($lft?->require_closing_checklist ?? false)
            && !$isOffline
            && empty($details['via_kiosk'])
            && empty($details['supervisor_override'])) {
            $cl = $details['closing_checklist'] ?? null;
            $checklistComplete = is_array($cl)
                && ($cl['luces'] ?? false) === true
                && ($cl['caja'] ?? false) === true
                && ($cl['alarma'] ?? false) === true;
            if (!$checklistComplete) {
                throw new \Exception('Cierre denegado: completa el checklist de cierre (luces, caja fuerte y alarma) antes de registrar tu salida.');
            }
        }

        // Salida Doble Llave (spec:53-55): si el tenant lo exige, la salida queda
        // 'pending_approval' hasta que un supervisor la autoriza; si no, 'final'.
        $checkOutStatus = null;
        if ($type === 'check_out') {
            $checkOutStatus = ($lft?->require_checkout_approval ?? false) ? 'pending_approval' : 'final';
        }

        // Snapshot del empleado al momento del fichaje (datos inmutables para reportes históricos).
        $employee = DB::table('employees')->where('user_id', $user->id)->first();
        $jobRole = $employee && $employee->job_role_id ? DB::table('job_roles')->find($employee->job_role_id) : null;
        $snapshotName = $employee ? ($employee->name ?? $user->name) : $user->name;
        $snapshotRole = $jobRole?->name ?? null;
        $snapshotSalary = $employee?->base_salary ?? null;

        // RESYNC 3 — UNIÓN de ambas líneas en el insert del ponche:
        //  · Línea del jefe (§67): foto de fichaje configurable + inmutabilidad del cálculo.
        //  · Línea del Reloj: check_out_status (Salida Doble Llave R75) y client_stamp
        //    (idempotencia del batch offline R84).
        //  · Se DESCARTA su try/catch del índice UNIQUE(user,date,type): ese índice se
        //    retiró en F3 (prohibía turno partido y pausas múltiples) y el catch genérico
        //    además rompería la detección de duplicados del batch (PunchBatchController
        //    depende de que la UniqueConstraintViolationException del client_stamp se
        //    propague tal cual).

        // §67: método de verificación realmente usado + evidencia. La foto es configurable
        // por tenant (require_photo_on_clockin, default true). El backend GUARDA lo que
        // ocurrió; el frontend decide si pide la foto y reporta qué pasó.
        $requirePhoto = isset($settings['require_photo_on_clockin'])
            ? (bool) json_decode($settings['require_photo_on_clockin'], true)
            : true;

        $photoUrl = $details['photo_url'] ?? null;
        $verificationMethod = $details['verification_method'] ?? null;
        $photoSkippedReason = $details['photo_skipped_reason'] ?? null;

        // Normalización defensiva por si el cliente no manda el método explícito.
        if ($photoUrl) {
            $photoSkippedReason = null;
            $verificationMethod = $verificationMethod ?: 'GEOFENCE_PIN_PHOTO';
        } else {
            if (!$photoSkippedReason) {
                $photoSkippedReason = $requirePhoto ? null : 'not_required';
            }
            $verificationMethod = $verificationMethod
                ?: ($photoSkippedReason === 'not_required' ? 'GEOFENCE_ONLY' : 'GEOFENCE_PIN');
        }

        // §67.C — la omisión por falla de cámara en un fichaje que SÍ requería foto queda
        // marcada para revisión del supervisor (visible, no solo registrada en bitácora).
        $cameraFailure = in_array($photoSkippedReason, ['camera_unavailable', 'permission_denied'], true);
        $flaggedForReview = $type === 'check_in' && $requirePhoto && $cameraFailure;

        // §67.E.1 — Fotografía del momento: se congela el cálculo de puntualidad tal como fue.
        // Cambiar la tolerancia a futuro NO recalcula la puntualidad pasada. tolerance_version
        // deriva de la última edición de la política LFT para poder distinguir "reglas" en el tiempo.
        $tardinessAtTime = $lateMinutes;
        $toleranceAtTime = $toleranceMinutes;
        $toleranceVersion = ($lft && $lft->updated_at) ? (string) $lft->updated_at->timestamp : 'default';

        // Crear registro + audit + transiciones Silla en una transacción atómica (si falla el
        // audit_log, el time_entry también se revierte).
        $entry = DB::transaction(function () use (
            $user, $date, $type, $time, $isLate, $lateMinutes, $detailsMerge,
            $snapshotName, $snapshotRole, $snapshotSalary, $simulationSessionId,
            $sillaRequestForThisPunch, $timezone, $checkOutStatus, $clientStamp, $lft,
            $photoUrl, $verificationMethod, $photoSkippedReason, $flaggedForReview,
            $tardinessAtTime, $toleranceAtTime, $toleranceVersion
        ) {
            $entry = TimeEntry::create([
                'user_id'               => $user->id,
                'tenant_id'             => $user->tenant_id ?? 1,
                'date'                  => $date,
                'type'                  => $type,
                'time'                  => $time,
                'is_late'               => $isLate,
                'late_minutes'          => $lateMinutes,
                'details'               => json_encode($detailsMerge),
                'check_out_status'      => $checkOutStatus,
                // R84: firma del ponche offline (idempotencia del batch). NULL en ponches online.
                'client_stamp'          => $clientStamp,
                'employee_name_at_time' => $snapshotName,
                'job_role_title_at_time'=> $snapshotRole,
                'base_salary_at_time'   => $snapshotSalary,
                'simulation_session_id' => $simulationSessionId,
                // §67
                'photo_url'                 => $photoUrl,
                'verification_method'       => $verificationMethod,
                'photo_skipped_reason'      => $photoSkippedReason,
                'flagged_for_review'        => $flaggedForReview,
                'tardiness_minutes_at_time' => $tardinessAtTime,
                'tolerance_mins_at_time'    => $toleranceAtTime,
                'tolerance_version'         => $toleranceVersion,
            ]);

            DB::table('audit_logs')->insert([
                'user_id'          => $user->id,
                'tenant_id'        => $user->tenant_id ?? 1,
                'date'             => $date,
                'type'             => $type,
                'timestamp_str'    => "$date $time",
                'reason'           => "Fichaje de tipo: $type",
                // Misma tarifa configurable que la nómina (antes hardcodeada `* 2`).
                'punishment_amount'=> $isLate ? ($lateMinutes * (float) ($lft?->late_penalty_per_minute ?? 2)) : 0,
                'details'          => json_encode($detailsMerge),
                'simulation_session_id' => $simulationSessionId,
                'created_at'       => Carbon::now(),
                'updated_at'       => Carbon::now(),
            ]);

            // §25: Ley Silla — transición de la solicitud en el mismo commit que el fichaje.
            if ($type === 'silla_start' && $sillaRequestForThisPunch) {
                $sillaRequestForThisPunch->status = 'active';
                $sillaRequestForThisPunch->started_at = Carbon::now($timezone);
                $sillaRequestForThisPunch->save();
            } elseif ($type === 'silla_end') {
                $activeSilla = \App\Models\SillaRequest::withoutGlobalScopes()
                    ->where('tenant_id', $user->tenant_id ?? 1)
                    ->where('employee_id', $user->id)
                    ->where('status', 'active')
                    ->orderByDesc('started_at')
                    ->first();
                if ($activeSilla) {
                    $activeSilla->status = 'finished';
                    $activeSilla->ended_at = Carbon::now($timezone);
                    $activeSilla->save();
                }
            }

            return $entry;
        });

        // §67.C.3 — si un mismo colaborador acumula 3 check_in seguidos con la foto omitida
        // por falla de cámara, se avisa al supervisor: es la señal de una cámara rota o de
        // alguien evadiendo la evidencia. El fichaje individual ya quedó flagged_for_review;
        // esto detecta el patrón.
        if ($flaggedForReview) {
            $recent = TimeEntry::where('user_id', $user->id)
                ->where('type', 'check_in')
                ->orderByDesc('date')
                ->limit(3)
                ->pluck('photo_skipped_reason')
                ->all();
            $streak = 0;
            foreach ($recent as $reason) {
                if (in_array($reason, ['camera_unavailable', 'permission_denied'], true)) {
                    $streak++;
                } else {
                    break;
                }
            }
            if ($streak >= 3) {
                \App\Helpers\SecurityLogger::log(
                    'clock_photo_skip_streak',
                    "El colaborador {$snapshotName} (user {$user->id}) acumula {$streak} fichajes seguidos sin foto por falla de cámara.",
                    $user->tenant_id ?? 1,
                    $user->id
                );
            }
        }

        // §20: notifica por WebSocket una vez que el fichaje ya quedó confirmado en BD — nunca en
        // rechazos/errores de validación, que ya cortaron con throw antes de llegar aquí.
        event(new \App\Events\TimeEntryRecorded($entry->tenant_id, $entry->user_id, $entry->type, $entry->time));

        return [
            'success' => true,
            'message' => $isLate
                ? "Registro guardado con retardo de {$lateMinutes} minutos."
                : "Registro exitoso.",
            'entry' => $entry
        ];
    }

    /**
     * Último ponche de los tipos dados para (user, date), con el aislamiento del Simulador Matrix:
     * un ponche REAL sólo mira filas reales (simulation_session_id NULL); un ponche del simulador
     * sólo mira las filas de SU sesión. Base de la máquina de estados de secuencia.
     */
    private function lastEntryOfTypes(int $userId, string $date, array $types, bool $isSimulatorPunch, ?int $simulationSessionId): ?TimeEntry
    {
        return TimeEntry::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->whereIn('type', $types)
            ->when($isSimulatorPunch,
                fn ($q) => $q->where('simulation_session_id', $simulationSessionId),
                fn ($q) => $q->whereNull('simulation_session_id'))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * §25 (Ley Silla): el empleado solicita el descanso; queda 'pending' hasta que un
     * supervisor la apruebe. Si ya tiene una solicitud viva hoy, la reutiliza en vez de
     * crear una duplicada.
     */
    public function createSillaRequest(User $user, int $storeId = 1): array
    {
        $tenantId = $user->tenant_id ?? 1;
        $timezone = $this->resolveTenantTimezone($tenantId);
        $today = Carbon::now($timezone)->format('Y-m-d');

        $existing = \App\Models\SillaRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $user->id)
            ->whereDate('requested_at', $today)
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->first();

        if ($existing) {
            return ['success' => true, 'request_id' => $existing->id, 'status' => $existing->status];
        }

        $request = \App\Models\SillaRequest::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'employee_id' => $user->id,
            'requested_at' => Carbon::now($timezone),
            'status' => 'pending',
        ]);

        return ['success' => true, 'request_id' => $request->id, 'status' => 'pending'];
    }

    /**
     * Aprueba una solicitud de Ley Silla. method='pin' valida contra employees.security_pin
     * del propio supervisor que aprueba — 'qr'/'remote' se registran solo como bitácora de
     * cumplimiento, la sesión autenticada del supervisor ya es la autorización.
     */
    public function approveSillaRequest(int $requestId, User $approver, string $method, ?string $supervisorPin = null): array
    {
        if (!in_array($approver->role, ['admin', 'supervisor', 'platform_admin'])) {
            throw new \Exception('Solo un supervisor o administrador puede aprobar una solicitud de Ley Silla.');
        }

        $silla = \App\Models\SillaRequest::withoutGlobalScopes()->findOrFail($requestId);
        if ((int) $silla->tenant_id !== (int) ($approver->tenant_id ?? 1)) {
            throw new \Exception('Esta solicitud no pertenece a tu empresa.');
        }
        if ($silla->status !== 'pending') {
            throw new \Exception('Esta solicitud ya fue procesada.');
        }

        if ($method === 'pin') {
            $employee = $approver->employee;
            if (!$employee || !$employee->security_pin || !$supervisorPin || !\Illuminate\Support\Facades\Hash::check($supervisorPin, $employee->security_pin)) {
                throw new \Exception('PIN de supervisor incorrecto.');
            }
        }

        $silla->status = 'approved';
        $silla->approved_by_employee_id = $approver->id;
        $silla->approval_method = $method;
        $silla->save();

        return ['success' => true, 'request_id' => $silla->id, 'status' => 'approved'];
    }

    public function rejectSillaRequest(int $requestId, User $approver): array
    {
        if (!in_array($approver->role, ['admin', 'supervisor', 'platform_admin'])) {
            throw new \Exception('Solo un supervisor o administrador puede rechazar una solicitud de Ley Silla.');
        }

        $silla = \App\Models\SillaRequest::withoutGlobalScopes()->findOrFail($requestId);
        if ((int) $silla->tenant_id !== (int) ($approver->tenant_id ?? 1)) {
            throw new \Exception('Esta solicitud no pertenece a tu empresa.');
        }
        if ($silla->status !== 'pending') {
            throw new \Exception('Esta solicitud ya fue procesada.');
        }

        $silla->status = 'rejected';
        $silla->approved_by_employee_id = $approver->id;
        $silla->save();

        return ['success' => true, 'request_id' => $silla->id, 'status' => 'rejected'];
    }

    /**
     * Aforo de sillas del día: cuántas activas, cuántas disponibles, y la cola de
     * solicitudes ya aprobadas esperando que se libere un lugar.
     */
    public function getSillaStatus(int $tenantId, string $date, int $storeId = 1): array
    {
        $settings = DB::table('system_settings')->where('tenant_id', $tenantId)->pluck('value', 'key')->toArray();
        $clockOpConfig = isset($settings['clockOpConfig']) ? json_decode($settings['clockOpConfig'], true) : [];
        $maxSimultaneous = $clockOpConfig['sillas_maximas_simultaneas'] ?? 1;

        $activeCount = \App\Models\SillaRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->whereDate('started_at', $date)
            ->count();

        $queue = \App\Models\SillaRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', 'approved')
            ->whereDate('requested_at', $date)
            ->orderBy('requested_at')
            ->get()
            ->values()
            ->map(fn($r, $i) => ['employee_id' => $r->employee_id, 'position' => $i + 1])
            ->all();

        return [
            'max_simultaneous' => $maxSimultaneous,
            'active_count' => $activeCount,
            'available' => max(0, $maxSimultaneous - $activeCount),
            'queue' => $queue,
        ];
    }

    /**
     * §25b: panel de supervisor — listar solicitudes de Ley Silla por status (default
     * 'pending') sin depender de que la aprobación llegue solo por push con el id.
     */
    public function listSillaRequests(int $tenantId, string $status = 'pending', ?string $date = null, int $storeId = 1): array
    {
        $query = \App\Models\SillaRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', $status);

        if ($date) {
            $query->whereDate('requested_at', $date);
        }

        return $query->orderBy('requested_at')
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'employee_id' => $r->employee_id,
                'requested_at' => $r->requested_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * BUG CAZADO EN EL SMOKE DEL MERGE: el valor de `system_settings.timezone` se guarda
     * json-encoded, así que la barra viene ESCAPADA (`"America\/Mexico_City"`). El `trim($tz,'"')`
     * de antes sólo quitaba las comillas y dejaba `America\/Mexico_City`, una zona INVÁLIDA →
     * "Unknown or bad timezone" (500) en declareContingency/createSillaRequest. Se delega en el
     * resolver compartido, que hace json_decode y valida contra DateTimeZone.
     */
    private function resolveTenantTimezone(int $tenantId): string
    {
        return TenantTimezone::for($tenantId);
    }

    /**
     * Declara una contingencia (sin luz / sin internet / ambas) para el tenant+sucursal+fecha
     * del usuario. Si ya existe una abierta (sin resolver) ese día, la reutiliza en vez de
     * duplicarla. Mientras esté activa, processPunch() salta el cálculo de retardo para
     * todos los usuarios del tenant ese día (ver bloque $hasContingency arriba).
     */
    public function declareContingency(int $userId, string $reason, ?string $declaredAt = null, int $storeId = 1): array
    {
        $allowedReasons = ['no_power', 'no_internet', 'no_power_and_internet'];
        if (!in_array($reason, $allowedReasons)) {
            throw new \InvalidArgumentException("Motivo de contingencia inválido: '{$reason}'. Valores permitidos: " . implode(', ', $allowedReasons));
        }

        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;
        // Merge F3 (fix de frontera de tz): la FECHA de la contingencia debe salir de la MISMA zona
        // que usa processPunch para $date (la del tenant) — con la fecha del servidor (UTC), entre
        // las 00:00 y las 06:00 UTC la declaración caía en "mañana" y la protección no aplicaba.
        // (Resync: se conserva el resolver compartido TenantTimezone, que hace json_decode y valida
        // la zona — el trim(...,'"') de la otra línea dejaba la barra escapada y daba zona inválida.)
        $timezone = $this->resolveTenantTimezone($tenantId);
        $declaredAtCarbon = $declaredAt ? Carbon::parse($declaredAt) : Carbon::now();
        $date = $declaredAtCarbon->copy()->setTimezone($timezone)->format('Y-m-d');

        $existing = ContingencyDeclaration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereDate('date', $date)
            ->whereNull('resolved_at')
            ->first();

        $declaration = $existing ?: ContingencyDeclaration::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'declared_by_user_id' => $userId,
            'date' => $date,
            'reason' => $reason,
            'declared_at' => $declaredAtCarbon,
        ]);

        return [
            'success' => true,
            'message' => 'Contingencia declarada. Jornada protegida al 100% conforme LFT.',
            'contingency_id' => $declaration->id,
        ];
    }

    /**
     * Estado #1 de la matriz (Fichaje Bloqueado por 3 retardos). El contador NO se
     * reinicia por periodo de nómina — solo cuando el empleado completa (o vuelve a
     * completar) el curso de puntualidad que el tenant haya configurado.
     */
    public function getPunctualityStatus($user): array
    {
        $tenantId = $user->tenant_id ?? 1;

        $settings = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->pluck('value', 'key')
            ->toArray();

        $requiredCourseId = isset($settings['punctuality_course_id'])
            ? (json_decode($settings['punctuality_course_id'], true) ?: null)
            : null;

        $courseCompleted = false;
        $lastCompletionDate = null;

        if ($requiredCourseId) {
            $progress = UserCourseProgress::where('user_id', $user->id)
                ->where('course_id', $requiredCourseId)
                ->where('status', 'completed')
                ->first();

            if ($progress) {
                $courseCompleted = true;
                $lastCompletionDate = $progress->completed_at;
            }
        }

        $periodStart = $lastCompletionDate ? $lastCompletionDate->format('Y-m-d') : null;

        $latesQuery = TimeEntry::where('user_id', $user->id)->where('is_late', true);
        if ($periodStart) {
            $latesQuery->where('date', '>', $periodStart);
        }
        $lateEntries = $latesQuery->get();

        // Mismo criterio que calculatePayrollForEmployee: los retardos en fechas con
        // contingencia activa (sin luz/sin internet) no cuentan para el bloqueo.
        $contingencyDatesQuery = ContingencyDeclaration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('resolved_at');
        if ($periodStart) {
            $contingencyDatesQuery->where('date', '>', $periodStart);
        }
        $contingencyDates = $contingencyDatesQuery->pluck('date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->all();

        $latesCount = $lateEntries->filter(fn($e) => !in_array($e->date, $contingencyDates))->count();

        return [
            'success' => true,
            'blocked' => $latesCount >= 3,
            'lates_count' => $latesCount,
            'period_start' => $periodStart,
            'period_end' => Carbon::now()->format('Y-m-d'),
            'required_course_id' => $requiredCourseId,
            'course_completed' => $courseCompleted,
        ];
    }

    /**
     * §63: racha de puntualidad del colaborador. Cuenta los check_in PUNTUALES
     * consecutivos desde el más reciente hacia atrás. Solo se consideran días en los
     * que el empleado realmente fichó entrada, así que los días sin check_in (descansos
     * y festivos) no rompen la racha por sí mismos — no penalizamos por no trabajar.
     * Un retardo en una fecha con contingencia activa (sin luz/sin internet) tampoco
     * rompe la racha, mismo criterio que getPunctualityStatus/nómina.
     *
     * Depende de que §61 esté corregido: antes `is_late` se marcaba en true aunque el
     * empleado llegara temprano (desfase de zona horaria), lo que dejaba a todos con
     * racha 0.
     */
    public function getPunctualityStreak($user): array
    {
        $tenantId = $user->tenant_id ?? 1;

        // Fechas con contingencia activa: un retardo en esos días no cuenta.
        $contingencyDates = ContingencyDeclaration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('resolved_at')
            ->pluck('date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->all();

        // Un check_in por día (el índice único garantiza unicidad), del más reciente al
        // más antiguo.
        $checkIns = TimeEntry::where('user_id', $user->id)
            ->where('type', 'check_in')
            ->orderBy('date', 'desc')
            ->get(['date', 'is_late']);

        $streak = 0;
        $lastLateDate = null;

        foreach ($checkIns as $entry) {
            $date = Carbon::parse($entry->date)->toDateString();
            $isLate = (bool) $entry->is_late && !in_array($date, $contingencyDates);

            if ($isLate) {
                // Primer retardo (el más reciente) corta la racha y marca la última fecha.
                if ($lastLateDate === null) {
                    $lastLateDate = $date;
                }
                break;
            }

            $streak++;
        }

        // Si la racha no se cortó por un retardo reciente, aún puede haber un retardo más
        // antiguo que quiera reportarse como last_late_date.
        if ($lastLateDate === null) {
            $lastLate = TimeEntry::where('user_id', $user->id)
                ->where('type', 'check_in')
                ->where('is_late', true)
                ->whereNotIn('date', $contingencyDates ?: ['1970-01-01'])
                ->orderBy('date', 'desc')
                ->value('date');
            $lastLateDate = $lastLate ? Carbon::parse($lastLate)->toDateString() : null;
        }

        return [
            'success' => true,
            'streak_days' => $streak,
            'last_late_date' => $lastLateDate,
        ];
    }

    /**
     * Calcula la nómina detallada y el desglose de LFT de un colaborador.
     *
     * @param int|null $simulationSessionId "Reportes de Prueba" (§13): si se especifica, incluye
     * EXCLUSIVAMENTE los fichajes de esa sesión del Simulador Matrix (bypass explícito de
     * ExcludeSimulationScope) en vez de los datos reales.
     */
    public function calculatePayrollForEmployee($employee, $startDate, $endDate, ?int $simulationSessionId = null)
    {
        $tenantId = $employee->tenant_id;

        // 1. Obtener la política LFT
        $lft = \App\Models\LftSetting::where('tenant_id', $tenantId)->first();
        if (!$lft) {
            $lft = \App\Models\LftSetting::create([
                'tenant_id' => $tenantId,
                'lates_per_absence' => 3,
                'deduct_absence_day' => true,
                'absences_for_warning' => 3,
                'absences_for_suspension' => 4,
                'proportional_rest_day' => true,
                'late_tolerance_minutes' => 10,
                'meal_tolerance_minutes' => 15,
                'rest_tolerance_minutes' => 10,
                'late_action_mode' => 'deduct',
                'paid_rest_day' => true,
                // N5/opción A: $0 de fábrica — el art. 107 LFT prohíbe multar el salario.
                // Activarlo es decisión explícita y documentada de cada empresa.
                'late_penalty_per_minute' => 0.00,
            ]);
        }

        // Periodicidad configurable (2026-08-03): si el expediente ya declara su SALARIO DIARIO
        // (capturado con periodicidad explícita y convertido por `App\Support\SalarioDiario`),
        // ésa es la verdad y se usa tal cual.
        //
        // Si no, fórmula HISTÓRICA intacta: base_salary/6. Ese /6 asume que base_salary es
        // semanal e INFLA el diario (convierte 6 días de trabajo en 7 pagados) — pero cambiarlo
        // a los expedientes legados sin el informe de impacto revisado alteraría pagos ya
        // acordados en silencio. La migración es por recaptura explícita, no por fórmula.
        // $baseSalary se define SIEMPRE: antes sólo existía en la rama legada, y para un
        // expediente con salario_diario `salary.base` salía indefinido (0 en pantalla).
        $baseSalary = $employee->base_salary ?? $employee->salary ?? 2400.00;
        if ($employee->salario_diario !== null && (float) $employee->salario_diario > 0) {
            $dailySalary = (float) $employee->salario_diario;
        } else {
            $dailySalary = $baseSalary / 6.0; // 6 días de trabajo devengan 1 de descanso
        }

        // 2. Registros de asistencia (excluyendo auxiliares como reservas de comida). Reales por
        // defecto (ExcludeSimulationScope global); modo "Reportes de Prueba" con $simulationSessionId.
        $entriesQuery = TimeEntry::where('user_id', $employee->user_id)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotIn('type', self::AUXILIARY_ENTRY_TYPES);

        if ($simulationSessionId) {
            $entriesQuery->withoutGlobalScope(\App\Scopes\ExcludeSimulationScope::class)
                ->where('simulation_session_id', $simulationSessionId);
        }

        $entries = $entriesQuery->get();

        // 3. Agrupar por día
        $entriesByDate = $entries->groupBy('date');

        // Fechas con ASISTENCIA real (R59): la presencia se prueba SÓLO con check_in/check_out.
        $attendedDates = [];
        foreach ($entriesByDate as $dateStr => $dayEntries) {
            if ($dayEntries->whereIn('type', ['check_in', 'check_out'])->isNotEmpty()) {
                $attendedDates[$dateStr] = true;
            }
        }

        $latesCount = 0;
        $lateMinutes = 0;
        $mealExcessMinutes = 0;
        $restExcessMinutes = 0;

        // R82: fechas con JUSTIFICANTE APROBADO — el retardo no se deduce ni cuenta para faltas.
        $justifiedDates = \DB::table('late_justifications')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $employee->user_id)
            ->where('status', 'approved')
            ->whereBetween('date', [$startDate, $endDate])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->flip();

        // R83: fechas con CONTINGENCIA APROBADA (gate humano de la línea del Reloj) — jornada causal
        // 100% pagada. ARBITRAJE F3: se UNEN las declaraciones ACTIVAS de la línea §1–§42
        // (contingency_declarations sin resolver: protección inmediata de retardo mientras dura la
        // eventualidad), manteniendo el gate humano para el resto de efectos.
        $contingencyDates = \DB::table('contingency_days')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $employee->user_id)
            ->where('status', 'approved')
            ->whereBetween('date', [$startDate, $endDate])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->flip();

        $activeDeclarationDates = ContingencyDeclaration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNull('resolved_at')
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        // N4/opción A: minutos de cada retardo en orden cronológico, para poder excluir del
        // cobro por minuto los retardos que la acumulación convierte en falta (cobro único).
        $minutosPorRetardo = [];

        foreach ($entries as $entry) {
            $entryDate = Carbon::parse($entry->date)->format('Y-m-d');
            // El retardo se congela por justificante aprobado (R82), contingencia aprobada (R83) o
            // declaración de eventualidad activa (línea §1–§42).
            if ($entry->is_late
                && !$justifiedDates->has($entryDate)
                && !$contingencyDates->has($entryDate)
                && !in_array($entryDate, $activeDeclarationDates, true)) {
                $latesCount++;
                $lateMinutes += $entry->late_minutes;
                $minutosPorRetardo[] = ['date' => $entryDate, 'mins' => (int) $entry->late_minutes];
            }
        }

        // Exceso de comida (spec:51) y de descanso Ley-Silla (spec:76) por PAREO de ponches.
        $allowedMeal = (int) ($employee->mealMinutes ?? 60);
        $mealTolerance = (int) ($lft?->meal_tolerance_minutes ?? 15);

        $leySillaRaw = \DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', 'leySillaConfig')
            ->value('value');
        $leySillaConfig = $leySillaRaw ? (json_decode($leySillaRaw, true) ?: []) : [];
        $leySillaEnabled = $leySillaConfig['enabled'] ?? true;
        $allowedBreak = (int) ($leySillaConfig['breakMinutes'] ?? 15);
        $restTolerance = (int) ($lft?->rest_tolerance_minutes ?? 10);

        $mealExcessByDate = [];
        foreach ($entriesByDate as $dateStr => $dayEntries) {
            $dayMealExcess = $this->pairedExcessMinutes($dayEntries, $dateStr, 'meal_start', 'meal_end', $allowedMeal, $mealTolerance);
            $mealExcessByDate[$dateStr] = $dayMealExcess;
            $mealExcessMinutes += $dayMealExcess;

            if ($leySillaEnabled) {
                $restExcessMinutes += $this->pairedExcessMinutes($dayEntries, $dateStr, 'break_start', 'break_end', $allowedBreak, $restTolerance);
            }
        }

        // Obtener días festivos registrados para el periodo
        $holidays = \DB::table('lft_holidays')
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy('date');

        $holidayWorkedDaysCount = 0;

        // 4. Calcular Faltas
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        $expectedDaysCount = 0;
        $physicalAbsences = 0;

        $dayMap = [
            'domingo' => 0, 'sunday' => 0,
            'lunes' => 1, 'monday' => 1,
            'martes' => 2, 'tuesday' => 2,
            'miercoles' => 3, 'wednesday' => 3,
            'jueves' => 4, 'thursday' => 4,
            'viernes' => 5, 'friday' => 5,
            'sabado' => 6, 'saturday' => 6,
        ];
        // OJO (bug de dinero): el picker de RRHH guarda el día de descanso ACENTUADO. Se normaliza
        // (mb_strtolower + quitar acentos) antes del lookup.
        $restDayName = strtr(
            mb_strtolower($employee->restDay ?? 'domingo', 'UTF-8'),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']
        );
        $restDayOfWeek = $dayMap[$restDayName] ?? 0;

        // Un empleado sin usuario enlazado (user_id NULL) NO puede fichar: no se fabrican faltas
        // para quien no tiene capacidad de fichar.
        $canTrackAttendance = !is_null($employee->user_id);

        // N3: una falta sólo puede existir en un día que YA TERMINÓ (en la zona horaria del
        // tenant). El día en curso y los futuros no son faltas — todavía no ocurren. Sin este
        // guard, calcular la semana corriente un jueves fabricaba 6 faltas y netos de $0.
        $todayStr = Carbon::now(TenantTimezone::for($tenantId))->toDateString();

        $current = $start->copy();
        $analysedDates = [];
        while ($current->lte($end)) {
            $dateStr = $current->toDateString();
            $analysedDates[] = $dateStr;

            $isHolidayDate = $holidays->has($dateStr);

            if ($current->dayOfWeek !== $restDayOfWeek) {
                if ($isHolidayDate) {
                    if (isset($attendedDates[$dateStr])) {
                        $holidayWorkedDaysCount++;
                    }
                } else {
                    $expectedDaysCount++;
                    // R83: contingencia aprobada (o eventualidad activa §1–§42) NO es falta.
                    if (!isset($attendedDates[$dateStr])
                        && $dateStr < $todayStr // N3: sólo días ya transcurridos
                        && !$contingencyDates->has($dateStr)
                        && !in_array($dateStr, $activeDeclarationDates, true)
                        && $canTrackAttendance) {
                        $physicalAbsences++;
                    }
                }
            }
            $current->addDay();
        }

        // Faltas equivalentes por retardos
        $absencesFromLates = $lft->lates_per_absence > 0
            ? (int)floor($latesCount / $lft->lates_per_absence)
            : 0;

        $totalAbsences = $physicalAbsences + $absencesFromLates;

        // 5. Proporcional del Séptimo Día (día de descanso)
        $restDayProportion = 1.0;
        if ($lft->proportional_rest_day && $totalAbsences > 0) {
            $workedDays = max(0, 6 - $totalAbsences);
            $restDayProportion = $workedDays / 6.0;
        }

        // 6. Deducciones
        $deductionAbsence = 0;
        if ($lft->deduct_absence_day) {
            $deductionAbsence = $totalAbsences * $dailySalary;
        }

        $deductionRestDay = 0;
        if ($lft->paid_rest_day) {
            $deductionRestDay = (1.0 - $restDayProportion) * $dailySalary;
        }

        // Penalización por minutos tarde: tarifa configurable (LftSetting) × factor del PUESTO
        // (R79/D1: job_roles.late_penalty_multiplier, default 1.0; nulo/0 cae a 1.0).
        $lateMultiplier = 1.0;
        if ($employee->job_role_id) {
            $jrMultiplier = \App\Models\JobRole::withoutGlobalScopes()
                ->where('id', $employee->job_role_id)
                ->value('late_penalty_multiplier');
            if ($jrMultiplier) {
                $lateMultiplier = (float) $jrMultiplier;
            }
        }
        $deductionLates = 0;
        if ($lft->late_action_mode === 'deduct') {
            // N4 — decisión del jefe (opción A): un retardo se cobra UNA sola vez. Los retardos
            // que la acumulación ya convirtió en falta (3→1, reglamento interior) NO cobran
            // además por minuto; sólo el residuo que no alcanzó a formar falta. Se consumen en
            // orden cronológico. (Con el default $0 del art. 107 esto vale $0 de todas formas;
            // aplica cuando una empresa activa el por-minuto bajo su responsabilidad.)
            $retardosConsumidos = $absencesFromLates * max(1, (int) $lft->lates_per_absence);
            usort($minutosPorRetardo, fn ($a, $b) => strcmp($a['date'], $b['date']));
            $minutosCobrables = array_sum(array_column(array_slice($minutosPorRetardo, $retardosConsumidos), 'mins'));

            $deductionLates = $minutosCobrables * (float) ($lft->late_penalty_per_minute ?? 0) * $lateMultiplier;
        }

        $totalDeductions = $deductionAbsence + $deductionRestDay + $deductionLates;

        // Bono por festivo laborado (salario doble adicional por LFT)
        $holidayBonusPay = $holidayWorkedDaysCount * $dailySalary * 2.0;

        // Bono de Cumplimiento (R94, Fase 5 / T5.1): DOS componentes INDEPENDIENTES, opt-in por monto.
        $punctualityBonus = 0.0;
        $punctualityEarned = false;
        $punctualityAmount = (float) ($lft->punctuality_bonus_amount ?? 0);
        if ($punctualityAmount > 0) {
            $maxLates = (int) ($lft->punctuality_bonus_max_lates ?? 0);
            $punctualityEarned = $latesCount <= $maxLates && $physicalAbsences === 0;
            if ($punctualityEarned) {
                $punctualityBonus = $punctualityAmount;
            }
        }

        $openingBonus = 0.0;
        $onTimeOpens = 0;
        $openingAmount = (float) ($lft->opening_bonus_per_open ?? 0);
        if ($openingAmount > 0) {
            $tz = TenantTimezone::for($tenantId);
            $tolerance = (int) ($lft->late_tolerance_minutes ?? 10);
            $opens = \DB::table('store_daily_opening_statuses')
                ->where('tenant_id', $tenantId)
                ->where('opened_by_employee_id', $employee->user_id)
                ->whereNotNull('opened_at')
                ->whereDate('date', '>=', $startDate)
                ->whereDate('date', '<=', $endDate)
                ->get(['date', 'scheduled_opening_time', 'opened_at']);
            foreach ($opens as $o) {
                if (!$o->scheduled_opening_time) {
                    continue;
                }
                $bizDate = Carbon::parse($o->date)->format('Y-m-d');
                $openedLocal = Carbon::parse($o->opened_at)->setTimezone($tz);
                $scheduled = Carbon::parse($bizDate . ' ' . $o->scheduled_opening_time, $tz);
                if ($openedLocal->lessThanOrEqualTo($scheduled->copy()->addMinutes($tolerance))) {
                    $onTimeOpens++;
                }
            }
            $openingBonus = $onTimeOpens * $openingAmount;
        }

        $complianceBonus = $punctualityBonus + $openingBonus;

        // Sueldo bruto para los 7 días (6 trabajados + 1 descanso) + bono festivo
        $grossPay = ($dailySalary * 7) + $holidayBonusPay;
        $netPay = max(0, $grossPay - $totalDeductions) + $complianceBonus;

        // Estatus de aprobaciones diarias
        $dailyApprovals = \App\Models\DailyApproval::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy('date');

        // Salida oficial normalizada a H:i:s.
        $officialExit = $this->normalizeExitTime($employee->shiftEnd);
        $datesDetails = [];
        foreach ($analysedDates as $dStr) {
            $cDate = Carbon::parse($dStr);
            $isRestDay = ($cDate->dayOfWeek === $restDayOfWeek);
            // `has_entries` = ¿hay ponches que MOSTRAR? `attended` = ¿ASISTIÓ de verdad? (R59).
            $hasEntries = isset($entriesByDate[$dStr]);
            $attended = isset($attendedDates[$dStr]);

            // Hora de Salida requerida = salida oficial + exceso de comida del día (spec:51).
            $dayMealMakeup = (int) ($mealExcessByDate[$dStr] ?? 0);
            $requiredExit = $officialExit;
            if ($dayMealMakeup > 0) {
                $extended = Carbon::parse("$dStr $officialExit")->addMinutes($dayMealMakeup);
                $requiredExit = $extended->format('Y-m-d') === $dStr ? $extended->format('H:i:s') : '23:59:59';
            }

            $datesDetails[] = [
                'date' => $dStr,
                'day_name' => $cDate->translatedFormat('l'),
                'is_rest_day' => $isRestDay,
                'has_entries' => $hasEntries,
                'attended' => $attended,
                // N3: sin asistencia + day_over=false NO es falta — el día no ha terminado.
                'day_over' => $dStr < $todayStr,
                'late_justified' => $justifiedDates->has(Carbon::parse($dStr)->format('Y-m-d')),
                'contingency' => $contingencyDates->has(Carbon::parse($dStr)->format('Y-m-d'))
                    || in_array($dStr, $activeDeclarationDates, true),
                'official_exit_time' => $officialExit,
                'meal_makeup_minutes' => $dayMealMakeup,
                'required_exit_time' => $requiredExit,
                'entries' => $hasEntries ? $entriesByDate[$dStr]->toArray() : [],
                'approval_status' => $dailyApprovals->has($dStr)
                    ? $dailyApprovals[$dStr]->status
                    : 'pending',
                'comments' => $dailyApprovals->has($dStr)
                    ? $dailyApprovals[$dStr]->comments
                    : null
            ];
        }

        // 6. Rendimiento de Tareas
        $assignments = \DB::table('task_assignments')
            ->where('user_id', $employee->user_id)
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->get();

        $totalTasks = $assignments->count();
        $completedTasksOnTime = 0;
        foreach ($assignments as $asn) {
            if ($asn->status === 'completed') {
                $completedAt = $asn->completed_at_mins ?? 0;
                $expectedEnd = $asn->expected_end_time_mins ?? 0;
                if ($expectedEnd === 0 || $completedAt <= $expectedEnd) {
                    $completedTasksOnTime++;
                }
            }
        }
        $taskPerformancePct = $totalTasks > 0 ? (int)round(($completedTasksOnTime / $totalTasks) * 100) : 100;

        // 7. Score Global de Rendimiento
        $attendanceScore = 100 - ($totalAbsences * 15) - ($latesCount * 5) - (int)floor($mealExcessMinutes / 5) - (int)floor($restExcessMinutes / 5);
        $attendanceScore = max(0, $attendanceScore);

        $performanceScore = (int)round(($attendanceScore * 0.6) + ($taskPerformancePct * 0.4));
        $performanceScore = max(0, min(100, $performanceScore));

        // Nómina semanal guardada/aprobada
        $weeklyPayrollRecord = \App\Models\WeeklyPayroll::where('employee_id', $employee->id)
            ->where('start_date', $startDate)
            ->where('end_date', $endDate)
            ->first();

        return [
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'salary' => [
                'base' => (float)$baseSalary,
                'daily' => (float)$dailySalary,
                'gross' => (float)$grossPay,
                'net' => (float)$netPay,
                'holiday_bonus' => (float)$holidayBonusPay,
                'compliance_bonus' => (float)$complianceBonus
            ],
            // Desglose estructurado del Bono de Cumplimiento (R94/R95) para el widget "Mis Bonos".
            'bonus' => [
                'punctuality_amount' => (float)$punctualityBonus,
                'punctuality_earned' => $punctualityEarned,
                'punctuality_configured' => $punctualityAmount > 0,
                'opening_amount' => (float)$openingBonus,
                'on_time_opens' => $onTimeOpens,
                'opening_configured' => $openingAmount > 0,
                'total' => (float)$complianceBonus
            ],
            'incidents' => [
                'lates' => $latesCount,
                'late_minutes' => $lateMinutes,
                'physical_absences' => $physicalAbsences,
                'absences_from_lates' => $absencesFromLates,
                'total_absences' => $totalAbsences,
                'rest_day_proportion' => (float)$restDayProportion,
                'meal_excess_minutes' => $mealExcessMinutes,
                'rest_excess_minutes' => $restExcessMinutes,
                'holidays_worked' => $holidayWorkedDaysCount
            ],
            'performance' => [
                'meal_overtime_mins' => $mealExcessMinutes,
                'break_overtime_mins' => $restExcessMinutes,
                'task_performance_pct' => $taskPerformancePct,
                'performance_score' => $performanceScore
            ],
            'deductions_breakdown' => [
                'absences' => (float)$deductionAbsence,
                'rest_day' => (float)$deductionRestDay,
                'lates' => (float)$deductionLates,
                'total' => (float)$totalDeductions
            ],
            'days_details' => $datesDetails,
            'approval' => [
                'is_approved' => $weeklyPayrollRecord
                    ? in_array($weeklyPayrollRecord->status, ['approved_by_employee', 'approved_by_admin', 'finalized'], true)
                    : false,
                'status' => $weeklyPayrollRecord ? $weeklyPayrollRecord->status : 'pending_employee',
                'approved_at' => $weeklyPayrollRecord ? $weeklyPayrollRecord->employee_approved_at : null,
                'cfdi_uuid' => $weeklyPayrollRecord->cfdi_uuid ?? null,
                'timbrada_at' => $weeklyPayrollRecord->timbrada_at ?? null,
                // Neto GUARDADO de la fila (el que se firmó/autorizó y el que se timbra);
                // puede diferir del neto vivo si una incidencia cambió después de la firma.
                'net_registrado' => isset($weeklyPayrollRecord->net_pay) ? (float) $weeklyPayrollRecord->net_pay : null
            ]
        ];
    }

    /**
     * Distancia haversine en metros entre dos coordenadas (mismo cálculo que el frontend:
     * radio terrestre 6371 km). Usada por la geocerca server-side de processPunch.
     */
    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371e3;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
