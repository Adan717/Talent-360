<?php

namespace App\Services;

use App\Models\ContingencyDeclaration;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserCourseProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClockService
{
    protected MockLocationDetector $mockLocationDetector;
    protected SimulatorSessionService $simulatorSessionService;

    public function __construct(SimulatorSessionService $simulatorSessionService)
    {
        $this->mockLocationDetector = new MockLocationDetector();
        $this->simulatorSessionService = $simulatorSessionService;
    }

    // Tipos de evento permitidos en el sistema de fichaje
    public const ALLOWED_TYPES = [
        'check_in', 'check_out', 'break_start', 'break_end',
        'meal_start', 'meal_end', 'waiting',
        'temp_exit_start', 'temp_exit_end',
        // §25: Ley Silla — tipo propio (no reusa break_*) para que nómina/reportes
        // puedan distinguir un descanso obligatorio por ley de uno ordinario.
        'silla_start', 'silla_end',
    ];

    public function processPunch(User $user, $type, $simTime = null, $details = [])
    {
        // Validar tipo de evento antes de cualquier operación
        if (!in_array($type, self::ALLOWED_TYPES)) {
            throw new \InvalidArgumentException("Tipo de fichaje inválido: '{$type}'. Valores permitidos: " . implode(', ', self::ALLOWED_TYPES));
        }

        $tenantId = $user->tenant_id ?? 1;
        $settings = \DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->pluck('value', 'key')
            ->toArray();
            
        $isSimulated = isset($settings['time_mode']) ? json_decode($settings['time_mode'], true) === 'simulated' : false;
        
        $timezone = isset($settings['timezone']) ? trim($settings['timezone'], '"') : 'America/Mexico_City';
        
        try {
            $now = Carbon::now($timezone);
        } catch (\Exception $e) {
            $now = Carbon::now('America/Mexico_City');
            $timezone = 'America/Mexico_City';
        }
        
        // Simulador Matrix: los fichajes marcados is_simulator se ligan a la sesión activa
        // del tenant y usan su simulated_date en vez de la fecha real — así corridas
        // sucesivas del simulador nunca chocan entre sí contra UNIQUE(user_id, date, type),
        // y sus filas quedan aisladas de los reportes reales (ver ExcludeSimulationScope).
        $isSimulatorPunch = isset($details['is_simulator']) && $details['is_simulator'] === true;
        $simulationSessionId = null;
        $simulatorSession = null;

        if ($isSimulatorPunch) {
            $simulatorSession = $this->simulatorSessionService->getActiveSession($tenantId, $user->id);
            $simulationSessionId = $simulatorSession->id;
        }

        $date = $simulatorSession
            ? Carbon::parse($simulatorSession->simulated_date)->format('Y-m-d')
            : $now->format('Y-m-d');

        // El simulador manda una hora explícita para pruebas; el sync offline por lotes
        // (punch-batch) también manda la hora real en que ocurrió el fichaje mientras el
        // dispositivo estaba sin conexión — sin esto, el registro quedaría con la hora en
        // que finalmente sincronizó en vez de la hora real del evento.
        $isOfflineSync = isset($details['offline_sync']) && $details['offline_sync'] === true;
        if (($isSimulated || $isOfflineSync || $isSimulatorPunch) && $simTime) {
            $time = $simTime;
            $now = Carbon::createFromFormat('Y-m-d H:i:s', "$date $time", $timezone);
        } else {
            $time = $now->format('H:i:s');
        }

        // Verificar si es día festivo no laborable y está configurado para bloquear la aplicación
        $holidayBlock = \DB::table('lft_holidays')
            ->where('tenant_id', $user->tenant_id ?? 1)
            ->where('date', $date)
            ->where('block_app', true)
            ->first();

        if ($holidayBlock) {
            throw new \Exception("Fichaje Denegado: Hoy es día festivo oficial obligatorio ({$holidayBlock->name}) y el sistema se encuentra bloqueado.");
        }

        // Validar inmutabilidad de la nómina consolidada (Fase 2 Solidez)
        $closedPayroll = DB::table('weekly_payrolls')
            ->where('tenant_id', $tenantId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->whereIn('status', ['approved', 'paid'])
            ->exists();

        if ($closedPayroll) {
            throw new \Exception("Fichaje Denegado: El período de nómina para la fecha {$date} ya ha sido aprobado o pagado y se encuentra bloqueado para modificaciones.");
        }

        // Anti-duplicados: cada tipo de fichaje solo puede registrarse una vez por día
        // (Registrar Entrada, Registrar Salida, Iniciar/Terminar Comida, Descansos, etc.).
        // Sin esto, un doble clic o un reintento de red crea dos filas para el mismo evento.
        $alreadyPunchedToday = TimeEntry::where('user_id', $user->id)
            ->where('date', $date)
            ->where('type', $type)
            ->exists();

        if ($alreadyPunchedToday) {
            throw new \Exception("Fichaje Denegado: ya existe un registro de tipo '{$type}' para hoy. No se puede duplicar.");
        }

        // Validación de secuencia (sección 15 del contrato): el índice único de arriba
        // solo evita duplicar el MISMO type — no impide que llegue, por ejemplo, un
        // meal_end sin meal_start previo (dial desincronizado, tab viejo, otra sesión,
        // cola offline). Cada type exige que su predecesor lógico ya exista hoy, y
        // algunos no pueden registrarse si el día ya se cerró con check_out.
        $sequenceRules = [
            'check_in'        => ['requires' => null,              'blocked_by' => 'check_out'],
            'meal_start'      => ['requires' => 'check_in',        'blocked_by' => 'check_out'],
            'meal_end'        => ['requires' => 'meal_start',      'blocked_by' => null],
            'break_start'     => ['requires' => 'check_in',        'blocked_by' => 'check_out'],
            'break_end'       => ['requires' => 'break_start',     'blocked_by' => null],
            'temp_exit_start' => ['requires' => 'check_in',        'blocked_by' => 'check_out'],
            'temp_exit_end'   => ['requires' => 'temp_exit_start', 'blocked_by' => null],
            'check_out'       => ['requires' => 'check_in',        'blocked_by' => null],
            'waiting'         => ['requires' => null,              'blocked_by' => null],
            'silla_start'     => ['requires' => 'check_in',        'blocked_by' => 'check_out'],
            'silla_end'       => ['requires' => 'silla_start',     'blocked_by' => null],
        ];

        $rule = $sequenceRules[$type] ?? null;
        if ($rule) {
            if ($rule['requires']) {
                $hasPrerequisite = TimeEntry::where('user_id', $user->id)
                    ->where('date', $date)
                    ->where('type', $rule['requires'])
                    ->exists();

                if (!$hasPrerequisite) {
                    throw new \Exception("Fichaje Denegado: no se puede registrar '{$type}' sin un '{$rule['requires']}' previo el mismo día.");
                }
            }

            if ($rule['blocked_by']) {
                $dayAlreadyClosed = TimeEntry::where('user_id', $user->id)
                    ->where('date', $date)
                    ->where('type', $rule['blocked_by'])
                    ->exists();

                if ($dayAlreadyClosed) {
                    throw new \Exception("Fichaje Denegado: ya se registró '{$rule['blocked_by']}' hoy, no se puede registrar '{$type}' después de cerrar el día.");
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

        // Obtener el plan del tenant
        $tenant = \App\Models\Tenant::find($user->tenant_id ?? 1);
        $isPro = $tenant && in_array(strtolower($tenant->plan ?? 'freemium'), ['pro', 'enterprise']);

        // 1. IP Lock (Disponible en Freemium y Pro si está configurado en clockOpConfig)
        if ($type === 'check_in' || $type === 'check_out') {
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

        // 2. Bloqueo de entrada si la tienda está cerrada (Plan Gratuito)
        if (!$isPro && $type === 'check_in') {
            $hasAssignments = \DB::table('store_opening_assignments')
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->exists();

            if ($hasAssignments) {
                $openingStatus = \DB::table('store_daily_opening_statuses')
                    ->where('tenant_id', $user->tenant_id)
                    ->where('date', $date)
                    ->first();
                
                $isOpened = false;
                $isOpeningManager = false;
                if ($openingStatus) {
                    $isOpened = $openingStatus->status === 'opened';
                    $isOpeningManager = intval($user->id) === intval($openingStatus->current_responsible_employee_id);
                } else {
                    $firstActive = \DB::table('store_opening_assignments')
                        ->where('tenant_id', $user->tenant_id)
                        ->where('is_active', true)
                        ->orderBy('priority_order', 'asc')
                        ->first();
                    if ($firstActive) {
                        $isOpeningManager = intval($user->id) === intval($firstActive->employee_id);
                    }
                }

                if (!$isOpened && !$isOpeningManager) {
                    throw new \Exception("Fichaje Denegado: La tienda física se encuentra cerrada. Debes esperar a que el encargado realice la apertura.");
                }
            }
        }

        // 2.b Bloqueo de salida si falta el checklist de cierre seguro (espejo del
        // checklist de apertura). Solo aplica a tenants con el módulo store_opening activo
        // y con la opción require_closing_checklist explícitamente activada en sus ajustes.
        if ($type === 'check_out' && \App\Services\FeatureAccessService::tenantHasFeature($tenantId, 'store_opening')) {
            $closingSettings = \DB::table('store_opening_settings')
                ->where('tenant_id', $tenantId)
                ->first();

            $requiresClosingChecklist = $closingSettings ? (bool)$closingSettings->require_closing_checklist : false;

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

        // 3. GPS VALIDATION — MockLocation Detection + Geofence (Plan Pro)
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
                        // Coordenada inyectada en el simulador para "Sucursal" (19.4326)
                        if (abs($gpsData['latitude'] - 19.4326) < 0.0005) {
                            $gpsData['latitude'] = (float)$storeLat;
                            $gpsData['longitude'] = (float)$storeLng;
                        } else {
                            // Coordenada lejana ("Casa")
                            $gpsData['latitude'] = (float)$storeLat + 0.02;
                            $gpsData['longitude'] = (float)$storeLng + 0.02;
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

        // Obtener la política de horario del empleado
        // shiftStart vive en `employees` desde la migración migrate_existing_users_to_employees_table;
        // `users` ya no tiene esa columna, así que hay que leerla vía la relación.
        $expectedTimeStr = $user->employee?->shiftStart ?? '09:00:00';
        // §61: expectedTime DEBE construirse en la MISMA fecha ($date) y zona horaria
        // ($timezone) que $now. Antes se usaba createFromFormat('H:i:s', ...) que fija la
        // hora en la fecha de hoy pero en la zona por defecto de la app (UTC). Con un
        // tenant en America/Mexico_City ese desfase de 6h volvía "tarde" a todo el mundo
        // y hacía que $now->diffInMinutes($expectedTime) diera negativo con decimales
        // (p.ej. -296.84), que al escribirse en la columna entera late_minutes reventaba
        // en Postgres (22P02) y generaba incidentes de descuento falsos.
        try {
            $expectedTime = Carbon::createFromFormat('Y-m-d H:i:s', "$date $expectedTimeStr", $timezone);
        } catch (\Exception $e) {
            $expectedTime = Carbon::createFromFormat('Y-m-d H:i:s', "$date 09:00:00", $timezone);
        }
        
        // Obtener las políticas LFT del Tenant
        $lft = \App\Models\LftSetting::where('tenant_id', $user->tenant_id)->first();
        $toleranceMinutes = $lft ? $lft->late_tolerance_minutes : 10;
        $lateActionMode = $lft ? $lft->late_action_mode : 'deduct';

        $isLate = false;
        $lateMinutes = 0;
        $hasAmnesty = isset($details['has_amnesty']) && $details['has_amnesty'] === true;

        // Declaración de Eventualidad (sin luz / sin internet): mientras esté activa para
        // el tenant y la fecha, la jornada completa queda protegida al 100% de salario
        // conforme LFT — se salta el cálculo de retardo igual que la amnistía por espera.
        $hasContingency = \App\Models\ContingencyDeclaration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('date', $date)
            ->whereNull('resolved_at')
            ->exists();

        if ($hasContingency) {
            $hasAmnesty = true;
        }

        if ($type === 'check_in' && !$hasAmnesty) {
            $proximityRecord = \DB::table('time_entries')
                ->where('user_id', $user->id)
                ->where('date', $date)
                ->where('type', 'waiting')
                ->first();
            if ($proximityRecord) {
                $hasAmnesty = true;
            }
        }

        if ($type === 'check_in' && !$hasAmnesty) {
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

        // Estructurar detalles del retardo o compensación
        $detailsMerge = $details;
        if ($hasContingency) {
            $detailsMerge['lft_incident'] = [
                'type' => 'contingency',
                'notes' => 'Jornada protegida al 100% por declaración de contingencia (sin luz / sin internet en sucursal).'
            ];
        } elseif ($hasAmnesty) {
            $detailsMerge['amnesty_applied'] = true;
            $detailsMerge['amnesty_note'] = 'Amnistía aplicada por apertura tardía de la sucursal.';
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

        // Obtener snapshot del empleado al momento del fichaje (datos inmutables para reportes históricos)
        $employee = DB::table('employees')->where('user_id', $user->id)->first();
        $jobRole = $employee ? DB::table('job_roles')->find($employee->job_role_id) : null;
        $snapshotName = $employee ? ($employee->name ?? $user->name) : $user->name;
        // job_roles no tiene columna `title`, el nombre del puesto vive en `name`.
        $snapshotRole = $jobRole?->name ?? null;
        $snapshotSalary = $employee?->base_salary ?? null;

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

        // Crear registro en la BD dentro de una transacción atómica.
        // Si falla la inserción del audit_log, el time_entry también se revierte.
        $entry = DB::transaction(function () use (
            $user, $date, $type, $time, $isLate, $lateMinutes, $detailsMerge,
            $snapshotName, $snapshotRole, $snapshotSalary, $simulationSessionId,
            $sillaRequestForThisPunch, $timezone,
            $photoUrl, $verificationMethod, $photoSkippedReason, $flaggedForReview,
            $tardinessAtTime, $toleranceAtTime, $toleranceVersion
        ) {
            try {
                $entry = TimeEntry::create([
                    'user_id'               => $user->id,
                    'tenant_id'             => $user->tenant_id ?? 1,
                    'date'                  => $date,
                    'type'                  => $type,
                    'time'                  => $time,
                    'is_late'               => $isLate,
                    'late_minutes'          => $lateMinutes,
                    'details'               => json_encode($detailsMerge),
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
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Backstop del índice único (time_entries_user_date_type_unique) para la
                // ventana de carrera que el exists() de más arriba no puede cerrar solo:
                // dos peticiones casi simultáneas pasando el chequeo antes de que la
                // primera confirme su insert.
                throw new \Exception("Fichaje Denegado: ya existe un registro de tipo '{$type}' para hoy. No se puede duplicar.");
            }

            // Registrar en audit log dentro de la misma transacción
            DB::table('audit_logs')->insert([
                'user_id'          => $user->id,
                'tenant_id'        => $user->tenant_id ?? 1,
                'date'             => $date,
                'type'             => $type,
                'timestamp_str'    => "$date $time",
                'reason'           => "Fichaje de tipo: $type",
                'punishment_amount'=> $isLate ? ($lateMinutes * 2) : 0,
                'details'          => json_encode($detailsMerge),
                'simulation_session_id' => $simulationSessionId,
                'created_at'       => Carbon::now(),
                'updated_at'       => Carbon::now(),
            ]);

            // §25: Ley Silla — transición de la solicitud en el mismo commit que el fichaje,
            // para que nunca queden desincronizados (ej. el fichaje se guarda pero la
            // solicitud se queda en 'approved' para siempre).
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

        // §20: notifica por WebSocket (mismo canal que StoreOpened) una vez que el
        // fichaje ya quedó confirmado en BD — nunca en rechazos/errores de validación,
        // que ya cortaron con throw antes de llegar aquí.
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
     * del propio supervisor que aprueba (mismo mecanismo que ya usan los testigos de
     * emergency-open) — 'qr'/'remote' se registran solo como bitácora de cumplimiento
     * (quién, cuándo, cómo), la sesión autenticada del supervisor ya es la autorización.
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

    private function resolveTenantTimezone(int $tenantId): string
    {
        $tz = DB::table('system_settings')->where('tenant_id', $tenantId)->where('key', 'timezone')->value('value');
        return $tz ? trim($tz, '"') : 'America/Mexico_City';
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

        $settings = \DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->pluck('value', 'key')
            ->toArray();
        $timezone = isset($settings['timezone']) ? trim($settings['timezone'], '"') : 'America/Mexico_City';

        try {
            $declaredAtCarbon = $declaredAt ? Carbon::parse($declaredAt, $timezone) : Carbon::now($timezone);
        } catch (\Exception $e) {
            $declaredAtCarbon = $declaredAt ? Carbon::parse($declaredAt) : Carbon::now('America/Mexico_City');
        }

        $date = $declaredAtCarbon->format('Y-m-d');

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
     * reinicia por periodo de nómina — es un tema de conducta/capacitación, no de
     * nómina, así que el bloqueo persistiría trivialmente si bastara con esperar a la
     * siguiente semana. Solo se reinicia cuando el empleado completa (o vuelve a
     * completar) el curso de puntualidad que el tenant haya configurado.
     */
    public function getPunctualityStatus($user): array
    {
        $tenantId = $user->tenant_id ?? 1;

        $settings = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->pluck('value', 'key')
            ->toArray();

        // El curso de puntualidad es una referencia de configuración del tenant
        // (system_settings), no una clasificación intrínseca del curso (course_type):
        // un tenant puede perfectamente reutilizar un curso de inducción/training ya
        // existente como su remediación de puntualidad, sin necesidad de alterar el
        // enum de academy_courses.course_type.
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
     * Calcular deducciones monetarias para la pre-nómina.
     */
    public function calculateLatePenalty(User $user, $startDate, $endDate)
    {
        $entries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('is_late', true)
            ->get();

        $totalLateMinutes = $entries->sum('late_minutes');
        
        // Simulación: $2 MXN de descuento por cada minuto de retardo
        $penaltyAmount = $totalLateMinutes * 2;

        return [
            'total_late_minutes' => $totalLateMinutes,
            'penalty_amount' => $penaltyAmount,
            'lates_count' => $entries->count()
        ];
    }

    /**
     * Calcula la nómina detallada y el desglose de LFT de un colaborador.
     */
    /**
     * @param int|null $simulationSessionId "Reportes de Prueba" (sección 13): si se
     * especifica, incluye EXCLUSIVAMENTE los fichajes de esa sesión del Simulador Matrix
     * (bypass explícito de ExcludeSimulationScope) en vez de los datos reales. Pensado
     * para que un admin verifique que el módulo de nómina calcula bien con datos de
     * prueba, sin que eso toque jamás un número real.
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
            ]);
        }
        
        $baseSalary = $employee->base_salary ?? $employee->salary ?? 2400.00;
        $dailySalary = $baseSalary / 6.0; // 6 días de trabajo devengan 1 de descanso
        
        // 2. Obtener registros de asistencia (reales por defecto; ver $simulationSessionId
        // arriba para el modo "Reportes de Prueba" del Simulador Matrix)
        $entriesQuery = TimeEntry::where('user_id', $employee->user_id)
            ->whereBetween('date', [$startDate, $endDate]);

        if ($simulationSessionId) {
            $entriesQuery->withoutGlobalScope(\App\Scopes\ExcludeSimulationScope::class)
                ->where('simulation_session_id', $simulationSessionId);
        }

        $entries = $entriesQuery->get();
            
        // 3. Agrupar por día
        $entriesByDate = $entries->groupBy('date');
        
        $latesCount = 0;
        $lateMinutes = 0;
        $mealExcessMinutes = 0;
        $restExcessMinutes = 0;
        
        // Días con declaración de contingencia activa (sin luz / sin internet): excluidos
        // de retardos y faltas, la jornada se paga al 100% conforme LFT.
        $contingencyDates = \App\Models\ContingencyDeclaration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNull('resolved_at')
            ->pluck('date')
            ->map(fn($d) => \Illuminate\Support\Carbon::parse($d)->toDateString())
            ->all();

        foreach ($entries as $entry) {
            if ($entry->is_late && !in_array($entry->date, $contingencyDates)) {
                $latesCount++;
                $lateMinutes += $entry->late_minutes;
            }
            // Analizar excesos de comida y descanso
            $details = json_decode($entry->details, true);
            if ($entry->type === 'meal_end' && isset($details['duration_minutes'])) {
                $mealMins = (int)$details['duration_minutes'];
                $allowedMeal = $employee->mealMinutes ?? 60;
                if ($mealMins > ($allowedMeal + $lft->meal_tolerance_minutes)) {
                    $mealExcessMinutes += max(0, $mealMins - $allowedMeal);
                }
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
        $restDayName = strtolower($employee->restDay ?? 'domingo');
        $restDayOfWeek = $dayMap[$restDayName] ?? 0;
        
        $current = $start->copy();
        $analysedDates = [];
        while ($current->lte($end)) {
            $dateStr = $current->toDateString();
            $analysedDates[] = $dateStr;
            
            $isHolidayDate = $holidays->has($dateStr);
            $isContingencyDate = in_array($dateStr, $contingencyDates);

            if ($current->dayOfWeek !== $restDayOfWeek) {
                if ($isContingencyDate) {
                    // Día protegido por contingencia: no cuenta como falta aunque no haya
                    // fichaje (ej. corte total de energía impidió cualquier registro).
                } elseif ($isHolidayDate) {
                    // Es día festivo
                    if (isset($entriesByDate[$dateStr])) {
                        $holidayWorkedDaysCount++;
                    }
                    // Si no laboró, NO cuenta como falta física (es descanso pagado obligatorio)
                } else {
                    $expectedDaysCount++;
                    if (!isset($entriesByDate[$dateStr])) {
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
        
        // 5. Calcular Proporcional del Séptimo Día (día de descanso)
        $restDayProportion = 1.0;
        if ($lft->proportional_rest_day && $totalAbsences > 0) {
            $workedDays = max(0, 6 - $totalAbsences);
            $restDayProportion = $workedDays / 6.0;
        }
        
        // 6. Calcular Deducciones
        $deductionAbsence = 0;
        if ($lft->deduct_absence_day) {
            $deductionAbsence = $totalAbsences * $dailySalary;
        }
        
        $deductionRestDay = 0;
        if ($lft->paid_rest_day) {
            $deductionRestDay = (1.0 - $restDayProportion) * $dailySalary;
        }
        
        // Penalización por minutos tarde si es deduct y no compensó
        $deductionLates = 0;
        if ($lft->late_action_mode === 'deduct') {
            $deductionLates = $lateMinutes * 2; // Simulación: $2 MXN de descuento por minuto
        }
        
        $totalDeductions = $deductionAbsence + $deductionRestDay + $deductionLates;
        
        // Calcular bono por festivo laborado (salario doble adicional por LFT)
        $holidayBonusPay = $holidayWorkedDaysCount * $dailySalary * 2.0;

        // Sueldo bruto para los 7 días (6 trabajados + 1 descanso) + bono festivo
        $grossPay = ($dailySalary * 7) + $holidayBonusPay;
        $netPay = max(0, $grossPay - $totalDeductions);
        
        // Obtener estatus de aprobaciones diarias
        $dailyApprovals = \App\Models\DailyApproval::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy('date');
            
        $datesDetails = [];
        foreach ($analysedDates as $dStr) {
            $cDate = Carbon::parse($dStr);
            $isRestDay = ($cDate->dayOfWeek === $restDayOfWeek);
            $hasEntries = isset($entriesByDate[$dStr]);
            
            $datesDetails[] = [
                'date' => $dStr,
                'day_name' => $cDate->translatedFormat('l'),
                'is_rest_day' => $isRestDay,
                'has_entries' => $hasEntries,
                'entries' => $hasEntries ? $entriesByDate[$dStr]->toArray() : [],
                'approval_status' => $dailyApprovals->has($dStr) 
                    ? $dailyApprovals[$dStr]->status 
                    : 'pending',
                'comments' => $dailyApprovals->has($dStr) 
                    ? $dailyApprovals[$dStr]->comments 
                    : null
            ];
        }

        // 6. Calcular Rendimiento de Tareas
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

        // 7. Calcular Score Global de Rendimiento (Evaluación Ley/Comportamiento)
        // Base 100, restamos 15 por falta, 5 por retardo, 1 por cada 5 min de exceso en comida/descanso
        $attendanceScore = 100 - ($totalAbsences * 15) - ($latesCount * 5) - (int)floor($mealExcessMinutes / 5) - (int)floor($restExcessMinutes / 5);
        $attendanceScore = max(0, $attendanceScore);
        
        // El score global es 60% asistencia/puntualidad y 40% desempeño de tareas
        $performanceScore = (int)round(($attendanceScore * 0.6) + ($taskPerformancePct * 0.4));
        $performanceScore = max(0, min(100, $performanceScore));
        
        // Buscar si ya se guardó y aprobó la nómina de esta semana
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
                'holiday_bonus' => (float)$holidayBonusPay
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
                'is_approved' => $weeklyPayrollRecord ? ($weeklyPayrollRecord->status === 'approved_by_employee' || $weeklyPayrollRecord->status === 'finalized') : false,
                'status' => $weeklyPayrollRecord ? $weeklyPayrollRecord->status : 'pending_employee',
                'approved_at' => $weeklyPayrollRecord ? $weeklyPayrollRecord->employee_approved_at : null
            ]
        ];
    }
}
