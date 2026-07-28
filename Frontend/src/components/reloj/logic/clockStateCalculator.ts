/**
 * Máquina de Estados Finita Pura (FSM) del Reloj Checador (Dialer Engine).
 *
 * Esta función evalúa de manera determinista y sin dependencias de React ni del DOM
 * cuál de los 23 estados posibles debe activarse en el Dialer, basándose en la matriz de
 * reglas de `docs/funcionamiento_del_dial.md`.
 */

export interface ClockUser {
  id: number | string;
  name?: string;
  role: string;
  portadorLlaves?: boolean;
}

export interface ClockEvaluationContext {
  clockState: 'inactive' | 'waiting_room' | 'active' | 'meal' | 'short_break' | 'temp_exit' | 'contingency_offline' | 'finished' | string;
  currentUser: ClockUser;
  isPro?: boolean;
  features?: {
    enable_meal_slots?: boolean;
    enable_ley_silla?: boolean;
    [key: string]: any;
  };
  isFeatureUnlocked?: (featureKey: string) => boolean;
  storeStatus?: 'open' | 'closed' | string;
  openingStatus?: { status?: string } | null;
  responsibleId?: number | string | null;
  activeEncargadoId?: number | string | null;
  isWithinPerimeter: boolean;
  isGpsValidationBypassed?: boolean;
  isOpeningPremium?: boolean;
  checkOutTimes?: Record<string | number, any>;
  checkInTimes?: Record<string | number, number>;
  mealStartTimes?: Record<string | number, any>;
  mealEndTimes?: Record<string | number, any>;
  breakStartTimes?: Record<string | number, any>;
  userReservedMealSlots?: Record<string | number, string[]>;
  shiftConfigs?: Record<string | number, { start?: string; end?: string }>;
  currentSimTime: number; // en minutos desde medianoche (0..1439)
  isHandoverCompleted?: boolean;
  isPanicActive?: boolean;
  punctualityStatus?: { acumulatedLates?: number; isBlocked?: boolean } | null;
  isHoliday?: boolean;
  isRestDay?: boolean;
  isSimulatedOffline?: boolean;
  hasActiveKeyAssignment?: boolean;
}

export interface ClockStateResult {
  /** Clave interna que mapea directamente a uno de los 23 estados de la matriz */
  stateNumber: number;
  stateCode: string;
  text: string;
  bg: string;
  icon: string;
  iconKey: string;
  subtext?: string;
  disabled?: boolean;
  isEmergencyOpen?: boolean;
  isOpeningActive?: boolean;
  isIncidenceReport?: boolean;
  isResponsibleOutside?: boolean;
  isMealReservationAlert?: boolean;
  allowedActions: string[];
}

function parseTimeToMins(timeStr: string | undefined): number {
  if (!timeStr) return 1020; // 17:00 default
  const parts = timeStr.split(':');
  if (parts.length < 2) return 1020;
  return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
}

function formatTimeMins(mins: number): string {
  const h = Math.floor(mins / 60) % 24;
  const m = mins % 60;
  const period = h >= 12 ? 'PM' : 'AM';
  const displayH = h % 12 === 0 ? 12 : h % 12;
  const displayM = m < 10 ? `0${m}` : `${m}`;
  return `${displayH}:${displayM} ${period}`;
}

export function calculateClockState(ctx: ClockEvaluationContext): ClockStateResult {
  const {
    clockState,
    currentUser,
    isPro = false,
    features = {},
    isFeatureUnlocked = () => true,
    storeStatus = 'closed',
    openingStatus,
    responsibleId,
    activeEncargadoId,
    isWithinPerimeter,
    isGpsValidationBypassed = false,
    isOpeningPremium = false,
    checkOutTimes = {},
    checkInTimes = {},
    mealStartTimes = {},
    mealEndTimes = {},
    breakStartTimes = {},
    userReservedMealSlots = {},
    shiftConfigs = {},
    currentSimTime,
    isHandoverCompleted = false,
    isPanicActive = false,
    punctualityStatus,
    isHoliday = false,
    isRestDay = false,
    isSimulatedOffline = false,
    hasActiveKeyAssignment = false,
  } = ctx;

  const userId = currentUser?.id;
  const isManager = ['Encargado Titular', 'Segundo Encargado', 'Supervisor', 'Gerente'].includes(currentUser?.role || '');

  // ----------------------------------------------------
  // ESTADO 1: Bloqueo por Retardos (Academia)
  // ----------------------------------------------------
  if (punctualityStatus?.isBlocked || (punctualityStatus?.acumulatedLates && punctualityStatus.acumulatedLates >= 3)) {
    return {
      stateNumber: 1,
      stateCode: 'blocked_lates',
      text: '🔒 Fichaje Bloqueado',
      bg: 'bg-rose-950 text-rose-300 border border-rose-800 cursor-not-allowed',
      icon: '🔒',
      iconKey: 'blocked',
      disabled: true,
      subtext: 'Acumulaste 3 retardos. Completa curso en la Academia.',
      allowedActions: ['go_to_academy']
    };
  }

  // ----------------------------------------------------
  // ESTADO 2: Día Feriado Oficial LFT
  // ----------------------------------------------------
  if (isHoliday) {
    return {
      stateNumber: 2,
      stateCode: 'holiday_lft',
      text: 'DÍA FERIADO (LFT)',
      bg: 'bg-sky-900 text-sky-200 border border-sky-700',
      icon: '📅',
      iconKey: 'holiday',
      disabled: true,
      subtext: 'Descanso oficial de ley',
      allowedActions: ['request_overtime']
    };
  }

  // ----------------------------------------------------
  // ESTADO 3: Día de Descanso Programado
  // ----------------------------------------------------
  if (isRestDay) {
    return {
      stateNumber: 3,
      stateCode: 'rest_day',
      text: 'DÍA DE DESCANSO',
      bg: 'bg-emerald-950 text-emerald-300 border border-emerald-800',
      icon: '🌴',
      iconKey: 'rest_day',
      disabled: true,
      subtext: 'Día libre programado',
      allowedActions: ['request_overtime']
    };
  }

  // ----------------------------------------------------
  // ESTADO 10: Contingencia Offline (Sin Luz / Sin Internet)
  // ----------------------------------------------------
  if (isSimulatedOffline || clockState === 'contingency_offline') {
    return {
      stateNumber: 10,
      stateCode: 'contingency_offline',
      text: 'Declarar Eventualidad',
      bg: 'bg-amber-600 hover:bg-amber-700 text-white font-black shadow-[0_0_20px_rgba(217,119,6,0.35)] animate-pulse',
      icon: '⚡',
      iconKey: 'contingency',
      subtext: 'Falla eléctrica / Sin red en sucursal',
      allowedActions: ['declare_contingency']
    };
  }

  // ----------------------------------------------------
  // APERTURA DE TIENDA Y ESTADOS PRE-TURNO (Tienda Cerrada)
  // ----------------------------------------------------
  if (isOpeningPremium && storeStatus === 'closed' && openingStatus?.status === 'failed') {
    const isKeyholderPresent = hasActiveKeyAssignment && (isWithinPerimeter || isGpsValidationBypassed);
    if (isKeyholderPresent) {
      return {
        stateNumber: 9,
        stateCode: 'emergency_open',
        text: 'Apertura Emergencia',
        bg: 'bg-rose-600 hover:bg-rose-700 text-white font-black shadow-[0_0_25px_rgba(225,29,72,0.35)] animate-pulse',
        icon: '⚠️',
        iconKey: 'emergency_open',
        isEmergencyOpen: true,
        subtext: 'Requiere co-validación de 2 testigos presenciales',
        allowedActions: ['emergency_open']
      };
    }
  }

  if (isOpeningPremium && storeStatus === 'closed') {
    if (Number(userId) === Number(responsibleId)) {
      return {
        stateNumber: 8,
        stateCode: 'open_store',
        text: 'Abrir Tienda',
        bg: 'bg-violet-600 hover:bg-violet-700 text-white font-black shadow-[0_0_25px_rgba(139,92,246,0.35)] animate-pulse',
        icon: '🗝️',
        iconKey: 'open_store',
        isOpeningActive: true,
        subtext: 'Horario oficial de apertura. Suma bono.',
        allowedActions: ['open_store']
      };
    }
  }

  if (Number(userId) === Number(activeEncargadoId) && storeStatus === 'closed') {
    return {
      stateNumber: 8,
      stateCode: 'open_store',
      text: 'Abrir Tienda',
      bg: 'bg-indigo-600 hover:bg-indigo-700 text-white font-bold',
      icon: '🗝️',
      iconKey: 'open_store',
      subtext: 'Horario oficial de apertura.',
      allowedActions: ['open_store']
    };
  }

  // Si está fuera de la tienda antes de la apertura o fichaje
  if (!isWithinPerimeter && (clockState === 'inactive' || clockState === 'waiting_room')) {
    const isResponsibleForOpening = isOpeningPremium && storeStatus === 'closed' && Number(userId) === Number(responsibleId);

    if (isResponsibleForOpening) {
      return {
        stateNumber: 6,
        stateCode: 'incidence_report_responsible',
        text: 'Reportar Incidencia',
        bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)]',
        icon: '⚠️',
        iconKey: 'incidence_report',
        isIncidenceReport: true,
        isResponsibleOutside: true,
        subtext: '🗝️ Eres el responsable de apertura de hoy. Dirígete a la sucursal para activar el botón.',
        allowedActions: ['report_incidence']
      };
    }

    return {
      stateNumber: 6,
      stateCode: 'incidence_report_employee',
      text: 'Reportar Incidencia',
      bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)]',
      icon: '⚠️',
      iconKey: 'incidence_report',
      isIncidenceReport: true,
      allowedActions: ['report_incidence']
    };
  }

  // ----------------------------------------------------
  // ESTADO 23: Jornada Finalizada (Post check_out)
  // ----------------------------------------------------
  if (clockState === 'inactive' && userId && checkOutTimes[userId] !== undefined) {
    return {
      stateNumber: 23,
      stateCode: 'finished',
      text: 'Fin Jornada',
      bg: 'bg-slate-200 text-slate-400 cursor-not-allowed',
      icon: '🏁',
      iconKey: 'finished',
      disabled: true,
      subtext: 'Turno concluido hoy.',
      allowedActions: []
    };
  }

  // ----------------------------------------------------
  // ESTADO 12: Fichar Entrada (Ordinaria / Amnistía)
  // ----------------------------------------------------
  if (clockState === 'inactive' || clockState === 'waiting_room') {
    const isAmnesty = clockState === 'waiting_room';
    return {
      stateNumber: isAmnesty ? 7 : 12,
      stateCode: isAmnesty ? 'arrived_in_door' : 'normal_check_in',
      text: isAmnesty ? '📍 Ya llegué' : 'Fichar Entrada',
      bg: isAmnesty ? 'bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold animate-pulse' : 'bg-slate-800 hover:bg-slate-900 text-white font-bold',
      icon: isAmnesty ? '📍' : '🟢',
      iconKey: 'entrada',
      subtext: isAmnesty ? 'Registrar llegada para asegurar amnistía' : 'Fichaje ordinario de entrada',
      allowedActions: ['check_in']
    };
  }

  // ----------------------------------------------------
  // ESTADO ACTIVO (Jornada en Curso: Comida, Ley Silla, Handover, Salida)
  // ----------------------------------------------------
  if (clockState === 'active') {
    const hasTakenMeal = userId && mealStartTimes[userId] !== undefined;

    // Sub-flujos de Comida (Estados 16 y 17)
    if (!hasTakenMeal) {
      const mySlots = userId ? userReservedMealSlots[userId] || [] : [];
      const mealReservationUnlocked = isFeatureUnlocked('meal_reservation');

      if (isPro && features.enable_meal_slots !== false && mealReservationUnlocked) {
        if (mySlots.length > 0) {
          const [sh, sm] = mySlots[0].split(' ')[0].split(':');
          const isPm = mySlots[0].includes('PM');
          let hour = parseInt(sh, 10);
          if (isPm && hour !== 12) hour += 12;
          if (!isPm && hour === 12) hour = 0;
          const firstSlotMins = hour * 60 + parseInt(sm, 10);

          if (currentSimTime < firstSlotMins - 5) {
            return {
              stateNumber: 16,
              stateCode: 'meal_window_locked',
              text: 'Tomar Comida',
              bg: 'bg-slate-200 text-slate-400 cursor-not-allowed opacity-60',
              icon: '🍔',
              iconKey: 'meal_start',
              disabled: true,
              subtext: `Reserva programada: ${mySlots[0]}`,
              allowedActions: ['swap_meal_slot']
            };
          }
        } else {
          return {
            stateNumber: 16,
            stateCode: 'meal_slot_unreserved',
            text: 'Apartar Turno',
            bg: 'bg-amber-600/20 text-amber-500 border border-amber-500/30 hover:bg-amber-600/30 font-bold shadow-md cursor-pointer animate-pulse',
            icon: '🍔',
            iconKey: 'meal_prompt',
            isMealReservationAlert: true,
            subtext: 'Haz clic para seleccionar tu slot en el comedor.',
            allowedActions: ['reserve_meal_slot']
          };
        }
      }

      const userCheckInTimeMins = userId ? checkInTimes[userId] : undefined;
      const shiftStartStr = userId && shiftConfigs[userId]?.start ? shiftConfigs[userId].start : '09:00';
      const shiftStartMins = parseTimeToMins(shiftStartStr);
      const minMealTimeMins = userCheckInTimeMins ? userCheckInTimeMins + 90 : shiftStartMins + 120;

      if (currentSimTime < minMealTimeMins && (!mySlots || mySlots.length === 0)) {
        return {
          stateNumber: 16,
          stateCode: 'meal_time_locked',
          text: 'Tomar Comida',
          bg: 'bg-slate-200 text-slate-400 cursor-not-allowed opacity-60',
          icon: '🍔',
          iconKey: 'meal_start',
          disabled: true,
          subtext: `Disponible a partir de las ${formatTimeMins(minMealTimeMins)}`,
          allowedActions: []
        };
      }

      return {
        stateNumber: 17,
        stateCode: 'meal_ready',
        text: 'Tomar Comida',
        bg: 'bg-amber-500 hover:bg-amber-600 text-amber-950 font-bold shadow-[0_0_20px_rgba(245,158,11,0.25)]',
        icon: '🍔',
        iconKey: 'meal_start',
        subtext: 'Haz clic para iniciar tu comida',
        allowedActions: ['start_meal']
      };
    }

    // Sub-flujo Ley Silla (Estados 19 y 20)
    const hasReturnedFromMeal = userId && mealEndTimes[userId] !== undefined;
    const hasTakenBreak = userId && breakStartTimes[userId] !== undefined;
    if (isPro && hasReturnedFromMeal && !hasTakenBreak && features.enable_ley_silla !== false) {
      return {
        stateNumber: 19,
        stateCode: 'ley_silla_ready',
        text: 'Tomar Silla',
        bg: 'bg-violet-600 hover:bg-violet-700 text-white font-extrabold shadow-[0_0_20px_rgba(147,51,234,0.3)] animate-pulse',
        icon: '🧘',
        iconKey: 'break_start',
        subtext: 'Descanso Ley Silla (15 min)',
        allowedActions: ['start_break']
      };
    }

    // Sub-flujo Entrega de Turno / Keyholder Handover (Estado 21)
    const currentShiftEndStrHO = (userId && shiftConfigs[userId]?.end) || '17:00';
    const currentShiftEndMinsHO = parseTimeToMins(currentShiftEndStrHO);
    const isHandoverWindow = currentSimTime >= currentShiftEndMinsHO - 15;
    const isKeysControlUnlockedHO = isFeatureUnlocked('keys_control');

    if (isPro && isKeysControlUnlockedHO && isManager && !isHandoverCompleted && isHandoverWindow) {
      return {
        stateNumber: 21,
        stateCode: 'handover_ready',
        text: 'Entregar Turno',
        bg: 'bg-cyan-600 hover:bg-cyan-700 text-white font-bold shadow-[0_0_20px_rgba(8,145,178,0.3)] animate-pulse',
        icon: '🗝️',
        iconKey: 'handover',
        subtext: 'Realizar arqueo y entrega de llaves',
        allowedActions: ['complete_handover']
      };
    }

    // Sub-flujo Salida Normal (Estado 22)
    if (!isPro) {
      const currentShiftEndStr = (userId && shiftConfigs[userId]?.end) || '17:00';
      const currentShiftEndMins = parseTimeToMins(currentShiftEndStr);
      const isWithinExitWindow = currentSimTime >= currentShiftEndMins - 10;
      if (!isWithinExitWindow) {
        return {
          stateNumber: 22,
          stateCode: 'shift_in_progress',
          text: 'Jornada en Curso',
          bg: 'bg-slate-200 text-slate-400 cursor-not-allowed opacity-60',
          icon: '⏳',
          iconKey: 'waiting_opening',
          disabled: true,
          subtext: `Salida disponible a las ${formatTimeMins(currentShiftEndMins - 10)}`,
          allowedActions: []
        };
      }
    }

    return {
      stateNumber: 22,
      stateCode: 'exit_ready',
      text: 'Fichar Salida',
      bg: 'bg-rose-600 hover:bg-rose-700 text-white font-black shadow-[0_0_22px_rgba(225,29,72,0.35)]',
      icon: '🚪',
      iconKey: 'exit',
      subtext: 'Checklist cierre seguro (luces/caja)',
      allowedActions: ['check_out']
    };
  }

  // ----------------------------------------------------
  // ESTADO 18: Comida en Curso
  // ----------------------------------------------------
  if (clockState === 'meal') {
    return {
      stateNumber: 18,
      stateCode: 'meal_in_progress',
      text: 'Terminar Comida',
      bg: 'bg-emerald-500 hover:bg-emerald-600 text-white font-bold shadow-[0_0_20px_rgba(16,185,129,0.35)]',
      icon: '🏃',
      iconKey: 'meal_end',
      subtext: 'Haz clic al regresar a la sucursal',
      allowedActions: ['end_meal']
    };
  }

  // ----------------------------------------------------
  // ESTADO 20: Descanso Ley Silla en Curso
  // ----------------------------------------------------
  if (clockState === 'short_break') {
    return {
      stateNumber: 20,
      stateCode: 'short_break_in_progress',
      text: 'Terminar Descanso',
      bg: 'bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-[0_0_20px_rgba(79,70,229,0.35)]',
      icon: '🏃',
      iconKey: 'break_end',
      subtext: 'Haz clic al reincorporarte',
      allowedActions: ['end_break']
    };
  }

  // Fallback seguro
  return {
    stateNumber: 12,
    stateCode: 'fallback',
    text: 'Fichar Entrada',
    bg: 'bg-slate-800 text-white',
    icon: '🟢',
    iconKey: 'entrada',
    subtext: 'Fichaje de entrada',
    allowedActions: ['check_in']
  };
}
