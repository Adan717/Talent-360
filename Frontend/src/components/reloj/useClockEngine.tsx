import React, { useState, useEffect, useRef } from 'react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';
import { useTaskStore } from '../../store/useTaskStore';
import { MOCK_STORE } from '../../mockData';
import { echoInstance } from '../../lib/echo';
import { offlineDb } from '../../lib/offlineDb';
import { computeOfflineStamp, warmOfflineSecret } from '../../lib/offlineSecret';
import { useGeoAndOfflineSync } from './hooks/useGeoAndOfflineSync';
import { useBreakAndMealTimers } from './hooks/useBreakAndMealTimers';
import { useStoreOpening } from './hooks/useStoreOpening';
import { useKeyholderDelegation } from './hooks/useKeyholderDelegation';
import { useClockUIState } from './hooks/useClockUIState';
import { calculateClockState } from './logic/clockStateCalculator';
import { evaluateCheckIn, resolveTolerance } from './logic/attendance';
import { shouldBlockForLateTolerance } from './logic/accessBlock';

export function useClockEngine(overrideUser?: any) {
  const assignments = useTaskStore(s => s.assignments);
  
  // --- FULLSTACK GLOBAL STATE ---
  
  const [globalPermissions, setGlobalPermissions] = useState<string[]>([]);
  
  useEffect(() => {
    fetchState();

    const handleSync = () => fetchState();
    window.addEventListener('db_sync_updated', handleSync);
    return () => window.removeEventListener('db_sync_updated', handleSync);
  }, []);


    const {
    isLoadingDB, setIsLoadingDB,
    globalUsers, setGlobalUsers,
    currentUser: globalUser, setCurrentUser: setGlobalUser,
    systemSettings, updateSetting,
    roleClockPolicies,
    fetchState,
    storeStatus, setStoreStatus,
    globalClockStates, setGlobalClockState,
    globalCheckInTimes, setGlobalCheckInTime,
    globalArrivalTimes, setGlobalArrivalTime,
    globalSimTime,
    globalRoles, setGlobalRoles,
    dbPermissions, setDbPermissions,
    dbRolePermissions, setDbRolePermissions,
    activeEncargadoId, setActiveEncargadoId,
    globalSimDay, setGlobalSimDay,
    isSandboxMode,
    // H6: ids con entrada tardía ya autorizada por un mando (viene de /sync/state).
    lateAuthorizedUserIds,
    currentTier,
    punctualityStatus,
    fetchPunctualityStatus
  } = useAppStore();
  
  const currentUser = overrideUser || globalUser;
  const setCurrentUser = overrideUser ? () => {} : setGlobalUser;
  const isSimulator = !!overrideUser;

  // NOTA (refactor Jul 2026 — división de useClockEngine.tsx en módulos más chicos): globalToast /
  // showCustomAlert y clockOpConfig se adelantan aquí (antes vivían más abajo en el archivo) porque
  // useGeoAndOfflineSync() los necesita como parámetros. El resto del comportamiento es idéntico.
  const [globalToast, setGlobalToast] = useState<string | null>(null);
  const showCustomAlert = (msg: string) => {
    setGlobalToast(msg);
    setTimeout(() => setGlobalToast(null), 4000);
  };

  // clockOpConfig también se adelanta aquí por la misma razón (lo consume useGeoAndOfflineSync).
  const clockOpConfig = systemSettings.clockOpConfig || {};

  const {
    syncQueue, setSyncQueue,
    gpsCoordinates, setGpsCoordinates,
    gpsStatus, setGpsStatus,
    requestGPS,
    isSimulatedOffline, setIsSimulatedOffline,
    STORE_LAT, STORE_LNG, ALLOWED_RADIUS_METERS,
    hasStoreLocation,
    isGpsValidationBypassed,
    gpsDistance,
    isWithinPerimeter,
    saveOfflineContingency,
    syncOfflineContingencies,
    syncOfflineQueue,
  } = useGeoAndOfflineSync({
    isSimulator,
    overrideUser,
    currentUserName: currentUser?.name,
    clockOpConfig,
    showCustomAlert,
  });

  // NUEVO: precarga el secreto de firma offline mientras hay conexión, para que ya esté cacheado
  // en memoria si el dispositivo pierde internet más tarde y necesita firmar un fichaje offline
  // (ver Frontend/src/lib/offlineSecret.ts). No aplica al simulador Matrix (isSandboxMode).
  useEffect(() => {
    if (!isSimulator && !isSandboxMode && currentUser?.id) {
      warmOfflineSecret();
    }
  }, [isSimulator, isSandboxMode, currentUser?.id]);

  // Auditoría reloj checador (2026-07-22), Hallazgo 1 / punto 1 del plan de acción: al montar,
  // trae el estatus real de puntualidad del backend (GET /me/punctuality-status) en vez de confiar
  // en el contador local de localStorage (evadible borrando datos del navegador). En sandbox/Matrix
  // sin backend real detrás no aplica; se sigue usando el mecanismo local solo ahí.
  useEffect(() => {
    if (!isSandboxMode && currentUser?.id) {
      fetchPunctualityStatus();
    }
  }, [isSandboxMode, currentUser?.id]);

  // NOTA (refactor Jul 2026): useStoreOpening() y useKeyholderDelegation() necesitan poder llamar a
  // syncToDB/processFinalClockOut, pero esas dos funciones son lógica central del motor y se definen
  // más abajo en este mismo archivo. No hace falta moverlas (sería mucho más riesgoso, tocan la máquina
  // de estados del dial): basta con pasar un wrapper que las referencie por nombre. Como el wrapper no
  // se EJECUTA hasta que el usuario interactúa (mucho después de que termine este render), para ese
  // entonces syncToDB/processFinalClockOut ya están asignadas — el mismo motivo por el que el código
  // original ya podía llamar a syncToDB desde una función definida antes que ella en el archivo.
  const syncToDBProxy = (type: string, isLate?: boolean, lateMinutes?: number, details?: string) =>
    syncToDB(type, isLate, lateMinutes, details);

  const processFinalClockOutProxy = (delegatedTo?: number | null, note?: string) =>
    processFinalClockOut(delegatedTo, note);

  const {
    openingSettings, setOpeningSettings,
    openingStatus, setOpeningStatus,
    openingChecklistCompleted, setOpeningChecklistCompleted,
    openingRollCallCompleted, setOpeningRollCallCompleted,
    closingChecklistCompleted, setClosingChecklistCompleted,
    showClosingChecklistModal, setShowClosingChecklistModal,
    closingChecklistSubmitting, setClosingChecklistSubmitting,
    getSimTimeStr,
    handleOpenStorePremium,
    showEmergencyOpenModal, setShowEmergencyOpenModal,
    emergencyOpenSubmitting,
    handleEmergencyStoreOpen,
    securityPinSubmitting,
    handleUpdateSecurityPin,
    showContingencyModal, setShowContingencyModal,
    contingencySubmitting,
    activeContingency, setActiveContingency,
    handleContingencyDeclaration,
    handleReportAbsencePremium,
    handleReportLatePremium,
    handleReportStoreStillClosedPremium,
  } = useStoreOpening({
    currentUser,
    showCustomAlert,
    isSimulatedOffline,
    saveOfflineContingency,
    syncToDB: syncToDBProxy,
    isSimulator,
  });

  const {
    isKeysControlUnlocked,
    keyholders, setKeyholders,
    showKeyDelegationModal, setShowKeyDelegationModal,
    nextDayEncargadoId, setNextDayEncargadoId,
    handleKeyDelegation,
    isUserActiveKeyholder,
    getNextSuplenteUser,
    handleCallSuplente,
    pendingKeyTransfers, setPendingKeyTransfers,
    initiateKeyTransfer,
    checkPendingKeyTransfers,
    respondToKeyTransfer,
    reportAbandonment,
  } = useKeyholderDelegation({
    globalUsers,
    currentUser,
    showCustomAlert,
    setDesignatedCloserId: (id: number) => setDesignatedCloserId(id),
    processFinalClockOut: processFinalClockOutProxy,
  });

  // NOTA (refactor Jul 2026): banderas booleanas puras de UI (modales, validaciones en curso) que
  // no tenían lógica propia — ver hooks/useClockUIState.ts.
  const {
    paseListaDone, setPaseListaDone,
    applyPunishments, setApplyPunishments,
    showMasterCloseModal, setShowMasterCloseModal,
    showTransferModal, setShowTransferModal,
    showCCTVModal, setShowCCTVModal,
    isDropdownOpen, setIsDropdownOpen,
    showAbsenceModal, setShowAbsenceModal,
    showAmnestyModal, setShowAmnestyModal,
    showGhostTheater, setShowGhostTheater,
    showJustificanteModal, setShowJustificanteModal,
    showReportModal, setShowReportModal,
    showEvalModal, setShowEvalModal,
    showForzosaModal, setShowForzosaModal,
    showPaseListaModal, setShowPaseListaModal,
    showBreakSeatModal, setShowBreakSeatModal,
    showTempExitModal, setShowTempExitModal,
    showPanicModal, setShowPanicModal,
    isPanicActive, setIsPanicActive,
    showMealSwapModal, setShowMealSwapModal,
    isHandoverCompleted, setIsHandoverCompleted,
    showEarlyDepartureModal, setShowEarlyDepartureModal,
    isEarlyDepartureValidation, setIsEarlyDepartureValidation,
    isOvertimeValidation, setIsOvertimeValidation,
    isLateEntryValidation, setIsLateEntryValidation,
    isSidebarOpen, setIsSidebarOpen,
    isModulesOpen, setIsModulesOpen,
    showMealReservationModal, setShowMealReservationModal,
    showAlarmSettingsModal, setShowAlarmSettingsModal,
    pendingTasksBlocker, setPendingTasksBlocker,
    preShiftAlarmPlayed, setPreShiftAlarmPlayed,
    mealReminderAlarmPlayed, setMealReminderAlarmPlayed,
    leySillaAlarmPlayed, setLeySillaAlarmPlayed,
  } = useClockUIState();


  let leySillaConfig = systemSettings.leySillaConfig || {};
  const setLeySillaConfig = (v: any) => updateSetting('leySillaConfig', typeof v === 'function' ? v(leySillaConfig) : v);
  
  let featureFlags = systemSettings.featureFlags || {};
  const setFeatureFlags = (v: any) => updateSetting('featureFlags', typeof v === 'function' ? v(featureFlags) : v);
  
  let mealSettings = systemSettings.mealSettings || {};
  const setMealSettings = (v: any) => updateSetting('mealSettings', typeof v === 'function' ? v(mealSettings) : v);
  
  let timeBankConfigs = systemSettings.timeBankConfigs || {};
  const setTimeBankConfigs = (v: any) => updateSetting('timeBankConfigs', typeof v === 'function' ? v(timeBankConfigs) : v);
  
  const adminConfigs = systemSettings.adminConfigs || {};
  const setAdminConfigs = (v: any) => updateSetting('adminConfigs', typeof v === 'function' ? v(adminConfigs) : v);

  const isOpeningPremium = useAppStore.getState().isFeatureUnlocked('store_opening');

  // --- Inyección de Perfiles por Puesto (RBAC+) ---
  if (currentUser && roleClockPolicies && roleClockPolicies.length > 0) {
      const policy = roleClockPolicies.find((p: any) => p.job_role_id === currentUser.job_role_id);
      if (policy && policy.config) {
          featureFlags = {
              ...featureFlags,
              evaluacion_salida: policy.config.requiere_evaluacion_salida ?? featureFlags.evaluacion_salida,
              paseDeLista: policy.config.paseDeLista ?? featureFlags.paseDeLista,
          };
          timeBankConfigs = {
              ...timeBankConfigs,
              maxLateMinsAllowed: policy.config.tolerancia_retardo_mins ?? timeBankConfigs.maxLateMinsAllowed,
          };
          mealSettings = {
              ...mealSettings,
              mealMinutes: policy.config.minutos_comida ?? mealSettings.mealMinutes,
          };
      }
  }
  
  const globalStoreShiftStart = systemSettings.globalStoreShiftStart;
  const setGlobalStoreShiftStart = (v: any) => updateSetting('globalStoreShiftStart', typeof v === 'function' ? v(globalStoreShiftStart) : v);
  
  const globalStoreShiftEnd = systemSettings.globalStoreShiftEnd;
  const setGlobalStoreShiftEnd = (v: any) => updateSetting('globalStoreShiftEnd', typeof v === 'function' ? v(globalStoreShiftEnd) : v);

  

  // NOTA (refactor Jul 2026 — migración de polling a WebSockets, tarea #41): antes este intervalo
  // corría cada 5s para todos los usuarios conectados, incluso sin ningún cambio real que sincronizar.
  // Ahora la actualización en tiempo real la maneja el listener de '.App\\Events\\TimeEntryRecorded'
  // (ver más abajo, mismo canal que ya usa StoreOpened) — cada fichaje de CUALQUIER empleado del
  // tenant dispara ese evento y refresca el estado al instante, sin esperar el poll. Este intervalo
  // se deja en 60s únicamente como red de seguridad por si el WebSocket se desconecta (reconexión de
  // red, Reverb caído, etc.) — no debería ser la vía principal de actualización en producción.
  // PENDIENTE DE BACKEND: el evento TimeEntryRecorded todavía no existe (ver docs/BACKEND_INTERFACES.md
  // §20) — hasta que Claude Code lo implemente, este poll de 60s es la única vía real de sincronización
  // entre dispositivos, con más retraso que antes. No revertir a 5s: una vez que exista el evento, el
  // WebSocket cubre la actualización inmediata y no hace falta un poll agresivo.
  useEffect(() => {
    fetchState();
    const interval = setInterval(fetchState, 60000);
    return () => {
      clearInterval(interval);
    };
  }, [currentUser.id]);

  const [storeOpenSimTime, setStoreOpenSimTime] = useState<number | null>(null);
  const [activePushNotification, setActivePushNotification] = useState<{type: string, text: string, action: () => void, dismiss?: () => void} | null>(null);
  const dismissedTaskNotificationsRef = useRef<Set<string>>(new Set());

  // Auto-cierre de notificaciones emergentes flotantes a los 5 segundos
  useEffect(() => {
    if (activePushNotification) {
      const timer = setTimeout(() => {
        setActivePushNotification(null);
      }, 5000);
      return () => clearTimeout(timer);
    }
  }, [activePushNotification]);

  // NOTA (refactor Jul 2026): toda la lógica de apertura de tienda premium (settings, status,
  // checklist de apertura/cierre, apertura de emergencia, PIN de seguridad, declaración de
  // contingencia y los reportes de ausencia/retardo/tienda-cerrada) ahora vive en
  // hooks/useStoreOpening.ts — ver la llamada a useStoreOpening() más arriba, que devuelve
  // exactamente estos mismos nombres para no romper nada río abajo en este archivo.

  // --- PWA Advanced & Geofencing States ---
  // NOTA (refactor Jul 2026): todo lo de GPS/geofencing y sincronización offline (syncQueue,
  // gpsCoordinates, gpsStatus, requestGPS, isSimulatedOffline, STORE_LAT/LNG, isWithinPerimeter,
  // syncOfflineQueue, syncOfflineContingencies, saveOfflineContingency) ahora vive en
  // hooks/useGeoAndOfflineSync.ts — ver la llamada a useGeoAndOfflineSync() más arriba, que
  // devuelve exactamente estos mismos nombres para no romper nada río abajo en este archivo.

  // NOTA (refactor Jul 2026): los horarios de descanso/comida/salida y la petición pendiente de
  // descanso ahora viven en hooks/useBreakAndMealTimers.ts — devuelve exactamente estos mismos
  // nombres (pendingBreakRequests, breakStartTimes, breakEndTimes, mealStartTimes, mealEndTimes,
  // checkOutTimes y sus setters) para no romper nada río abajo en este archivo.
  const {
    pendingBreakRequests, setPendingBreakRequests,
    breakStartTimes, setBreakStartTimes,
    breakEndTimes, setBreakEndTimes,
    mealStartTimes, setMealStartTimes,
    mealEndTimes, setMealEndTimes,
    checkOutTimes, setCheckOutTimes,
  } = useBreakAndMealTimers();

  useEffect(() => {
    if (!currentUser || !currentUser.tenant_id) return;
    const channelName = `tenant.${currentUser.tenant_id}.clock`;
    // §27 (docs/BACKEND_INTERFACES.md): backend ya migró StoreOpened/TimeEntryRecorded/
    // DoorNoticeCreated/MealQueueTurnChanged a PrivateChannel y agregó la autorización en
    // routes/channels.php — canal privado real, ya no cualquiera puede escuchar fichajes de otro tenant.
    const channel = echoInstance.private(channelName);
    
    channel.listen('.App\\Events\\StoreOpened', (e: any) => {
      console.log('StoreOpened event received via WebSockets:', e);
      fetchState();
      showCustomAlert('¡La tienda ha sido abierta oficialmente!');
    });

    // NUEVO (tarea #41 — migración de polling a WebSockets): reemplaza el sondeo de 5s por push real.
    // Reutiliza el mismo canal público 'tenant.{id}.clock' que ya usa StoreOpened, en vez de abrir una
    // conexión aparte. PENDIENTE DE BACKEND (docs/BACKEND_INTERFACES.md §20): este evento todavía no
    // existe del lado de Laravel — Claude Code debe emitirlo desde ClockService::processPunch() al
    // final de cada fichaje exitoso (online, batch offline, apertura normal/emergencia — todos pasan
    // por processPunch()). Mientras no exista, este listener simplemente nunca dispara y el fallback
    // de 60s (ver el useEffect de arriba) sigue siendo la única vía de sincronización entre dispositivos.
    channel.listen('.App\\Events\\TimeEntryRecorded', (e: any) => {
      console.log('TimeEntryRecorded event received via WebSockets:', e);
      fetchState();
    });

    return () => {
      channel.stopListening('.App\\Events\\StoreOpened');
      channel.stopListening('.App\\Events\\TimeEntryRecorded');
    };
  }, [currentUser?.tenant_id]);
  
  useEffect(() => {
    const roles = Array.isArray(globalRoles) ? globalRoles : [];
    const perms = Array.isArray(dbPermissions) ? dbPermissions : [];
    const rolePerms = Array.isArray(dbRolePermissions) ? dbRolePermissions : [];
    if (!currentUser || roles.length === 0 || perms.length === 0) return;
    const myRole = roles.find(r => r && r.id === currentUser.job_role_id);
    if (myRole) {
       const myPermIds = rolePerms.filter(rp => rp && rp.job_role_id === myRole.id).map(rp => rp.permission_id);
       const myPermNames = perms.filter(p => p && myPermIds.includes(p.id)).map(p => p.name);
       setGlobalPermissions(myPermNames);
    }
  }, [currentUser, globalRoles, dbPermissions, dbRolePermissions]);

  // using global storeStatus
  const [summaryView, setSummaryView] = useState('daily');
  const [designatedCloserId, setDesignatedCloserId] = useState(1);
  const [masterClosePhase, setMasterClosePhase] = useState('checklist');
  const [tasksChecked, setTasksChecked] = useState({ t1: false, t2: false, t3: false });


  

  const [amnestyActive, setAmnestyActive] = useState(MOCK_STORE.hasAmnesty);
  const [requireEvaluation, setRequireEvaluation] = useState(MOCK_STORE.requireEvaluation);
  
  
  // ESTADO GLOBAL SIMULADO
  const initialState = globalUsers.reduce((acc, user) => ({ ...acc, [user.id]: 'inactive' }), {});
  // const [globalClockStates, setGlobalClockStates] = useState(initialState);
  
  // Fase 3: Banco de Tiempo y Candados Biológicos
  const [globalTimeBank, setGlobalTimeBank] = useState<Record<number, number>>({});
  const [activeTimers, setActiveTimers] = useState<Record<number, { type: 'meal'|'short_break', startSimTime: number }>>({});
  const [breaksTaken, setBreaksTaken] = useState<Record<number, number>>({});
  

  // FASE 4: Sistema de Alertas Buddy
  const [buddyAlerts, setBuddyAlerts] = useState<Record<number, {id: number, msg: string, type: 'info' | 'warning'}[]>>({});
  const removeAlert = (userId: number, alertId: number) => {
    setBuddyAlerts(prev => {
      const arr = prev[userId] || [];
      return { ...prev, [userId]: arr.filter(a => a.id !== alertId) };
    });
  };

  // const [arrivalTimes, setArrivalTimes] = useState({});
  // const [checkInTimes, setCheckInTimes] = useState({});


  const [expandedCards, setExpandedCards] = useState<Record<string, any>>({});
  const [phoneTab, setPhoneTab] = useState('checador');
  const [innerTool, setInnerTool] = useState<any>(null);
  const [realSeconds, setRealSeconds] = useState(0);

  useEffect(() => {
    const interval = setInterval(() => {
      setRealSeconds(prev => (prev + 1) % 60);
    }, 1000);
    return () => clearInterval(interval);
  }, []);

    const parseTimeToMins = (timeStr: any) => {
    if (!timeStr || typeof timeStr !== 'string') return 0;
    const [h, m] = timeStr.split(':').map(Number);
    return h * 60 + m;
  };

  const initialShifts = globalUsers.reduce((acc, user) => {
    const startMins = parseTimeToMins(user.shiftStart);
    const endMins = parseTimeToMins(user.shiftEnd);
    const midPointMins = startMins + Math.floor((endMins - startMins) / 2);
    const mealStartH = Math.floor(midPointMins / 60);
    const mealStartM = midPointMins % 60;
    const mealStart = `${mealStartH.toString().padStart(2, '0')}:${mealStartM.toString().padStart(2, '0')}`;

    return {
    isGlobalLoading: false,
      ...acc,
      [user.id]: { 
        start: user.shiftStart, 
        end: user.shiftEnd,
        mealStart: activeTimers[user.id]?.type === 'meal' ? activeTimers[user.id].startSimTime : null,
        mealMinutes: user.mealMinutes,
        restDay: user.restDay,
        portadorLlaves: user.portadorLlaves
      }
    };
  }, {});
  const [shiftConfigs, setShiftConfigs] = useState<Record<string, any>>(initialShifts);

  // FIX (prueba en vivo 2026-07-29): `useState(initialShifts)` sólo lee su argumento en el
  // PRIMER render, y en ese momento `globalUsers` todavía está vacío (la carga es asíncrona),
  // así que `shiftConfigs` se quedaba en {} de por vida. Consecuencia: TODO el dial evaluaba
  // contra el horario por defecto (09:00, `|| 480` en los cálculos) sin importar el turno real
  // del colaborador → retardos falsos y "ACCESO BLOQUEADO / TOLERANCIA VENCIDA" permanente en
  // cualquier empresa cuyo turno no fuera 09:00-18:00. Reproducido con turno 15:49-23:30:
  // el backend fichaba bien (200) mientras el dial seguía bloqueado.
  //
  // Se hidrata cuando llegan/cambian los usuarios.
  //
  // H14 (jornada de regresión 2026-07-30): la primera versión sólo rellenaba los huecos
  // (`if (!merged[userId])`), así que si RRHH corregía el horario de alguien a media jornada el
  // dial seguía con el viejo hasta recargar: ofrecía "Salida Anticipada" con un turno que ya
  // había terminado y la ventana de comida equivocada. Ahora, cuando el valor que manda el
  // SERVIDOR cambia respecto a la última hidratación, se adopta el nuevo; lo que el usuario
  // haya editado en la sesión se conserva mientras el servidor no cambie.
  const ultimoShiftDelServidor = useRef<Record<string, string>>({});
  useEffect(() => {
    if (!globalUsers || globalUsers.length === 0) return;
    setShiftConfigs(prev => {
      const merged = { ...prev };
      let changed = false;
      for (const [userId, cfg] of Object.entries(initialShifts as Record<string, any>)) {
        if (userId === 'isGlobalLoading') continue;
        const huella = `${cfg?.start ?? ''}|${cfg?.end ?? ''}`;
        const servidorCambio = ultimoShiftDelServidor.current[userId] !== undefined
          && ultimoShiftDelServidor.current[userId] !== huella;
        if (!merged[userId] || servidorCambio) {
          merged[userId] = cfg;
          changed = true;
        }
        ultimoShiftDelServidor.current[userId] = huella;
      }
      return changed ? merged : prev;
    });
  }, [globalUsers]);

  const timeMode = systemSettings?.time_mode || 'simulated';
  const isRealTimeMode = timeMode === 'realtime';

  const syncToBackend = async (endpoint: string, payload: any) => {
      // Siempre mandamos al backend la solicitud, pero con o sin time en el payload
      // El backend decide si usar NTP o simulado
      try {
         await axiosInstance.post(`/${endpoint}`, payload);
      } catch (e) {
         console.error('Error syncing to backend', e);
      }
  };

  if (!currentUser) {
    if (globalUsers.length === 0) {
      return { isGlobalLoading: true } as any;
    }
    return { dbEmpty: true } as any;
  }

  const baseTimeMinutes = 7 * 60 + 30; // 450 (7:30 AM)

  useEffect(() => {
    let interval: ReturnType<typeof setInterval> | undefined;
    if (isRealTimeMode) {
      const syncTime = () => {
        const now = new Date();
        const minutesSinceMidnight = now.getHours() * 60 + now.getMinutes();
        const newSimMinutes = minutesSinceMidnight - baseTimeMinutes;
        setSimTimeMinutes(Math.max(0, newSimMinutes));
      };
      syncTime();
      interval = setInterval(syncTime, 10000); 
    }
    return () => clearInterval(interval);
  }, [isRealTimeMode]);
  const getRealTimeMins = () => {
    try {
      const tz = systemSettings?.timezone || 'America/Mexico_City';
      const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: tz,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
      });
      const parts = formatter.formatToParts(new Date());
      const h = parseInt(parts.find(p => p.type === 'hour')?.value || '0', 10);
      const m = parseInt(parts.find(p => p.type === 'minute')?.value || '0', 10);
      return h * 60 + m;
    } catch (e) {
      const now = new Date();
      return now.getHours() * 60 + now.getMinutes();
    }
  };

  // NUEVO: hora real en formato H:i:s (24h, con segundos) — para fichajes offline en modo real.
  // BUG FIX relacionado: el resto del código usa `formattedTime` (ej. "8:32 am", 12h sin segundos)
  // como valor de `time` al guardar en la cola offline, formato que el backend NO puede parsear como
  // hora real del fichaje (ClockService espera H:i:s). Se corrige aquí para la ruta offline nueva;
  // la ruta online tiene el mismo problema latente pero queda fuera de este cambio (ver nota en
  // syncToDB) porque el backend actualmente ignora `time` en el camino online no-simulado.
  const getRealHms = () => {
    try {
      const tz = systemSettings?.timezone || 'America/Mexico_City';
      const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: tz,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
      });
      const parts = formatter.formatToParts(new Date());
      const h = parts.find(p => p.type === 'hour')?.value || '00';
      const m = parts.find(p => p.type === 'minute')?.value || '00';
      const s = parts.find(p => p.type === 'second')?.value || '00';
      return `${h}:${m}:${s}`;
    } catch (e) {
      const now = new Date();
      return now.toTimeString().slice(0, 8);
    }
  };

  const isSimulatedMode = !!overrideUser;
  const [realTimeMins, setRealTimeMins] = useState(getRealTimeMins());

  useEffect(() => {
    if (!isSimulatedMode) {
      const updateClock = () => {
        setRealTimeMins(getRealTimeMins());
      };
      updateClock();
      const interval = setInterval(updateClock, 1000);
      return () => clearInterval(interval);
    }
  }, [isSimulatedMode, systemSettings?.timezone]);

  // NUEVO: "Configura tu alarma" (docs/funcionamiento_del_dial.md §3 / BACKEND_INTERFACES.md §5).
  // Notificación push LOCAL del navegador (no depende del backend para dispararse, solo para
  // persistir la preferencia vía PUT /me/pre-shift-alarm). Solo aplica en modo real (no en el
  // simulador Matrix, donde el tiempo corre acelerado y no tendría sentido pedir permiso de push).
  useEffect(() => {
    if (isSimulatedMode) return;
    const alarmMinutes = currentUser?.pre_shift_alarm_minutes;
    if (!alarmMinutes || typeof Notification === 'undefined') return;

    if (Notification.permission === 'default') {
      Notification.requestPermission().catch(() => {});
    }
    if (Notification.permission !== 'granted') return;

    const shiftStartStr = currentUser?.shiftStart || '09:00';
    const shiftStartMins = parseTimeToMins(shiftStartStr);
    const alarmTriggerMins = shiftStartMins - Number(alarmMinutes);
    const todayKey = `pre_shift_alarm_fired_${currentUser?.id}_${new Date().toDateString()}`;

    // realTimeMins tiene resolución de minuto; disparamos en la ventana [trigger, trigger+1)
    // para no depender de un segundo exacto, y solo una vez por día vía localStorage.
    if (
      realTimeMins >= alarmTriggerMins &&
      realTimeMins < alarmTriggerMins + 1 &&
      localStorage.getItem(todayKey) !== 'true'
    ) {
      localStorage.setItem(todayKey, 'true');
      try {
        new Notification('⏰ Talent360 — Hora de salir', {
          body: `Es hora de salir hacia tu sucursal para asegurar tu Bono de Apertura (turno ${shiftStartStr}).`,
          icon: '/pwa-192x192.png'
        });
      } catch (e) {
        console.error('No se pudo mostrar la notificación de alarma de pre-turno:', e);
      }
    }
  }, [realTimeMins, isSimulatedMode, currentUser?.pre_shift_alarm_minutes, currentUser?.shiftStart, currentUser?.id]);

  const currentSimTime = isSimulatedMode ? globalSimTime : realTimeMins;
  // Aliases to avoid breaking RelojVisual (Moved to top to prevent TDZ)
  const checkInTimes = globalCheckInTimes;
  const arrivalTimes = globalArrivalTimes;
  const clockState = globalClockStates[currentUser?.id] || 'inactive';
  const simTimeMinutes = currentSimTime;
  const setSimTimeMinutes = (_v?: number) => {}; // no-op (se deja como estaba, solo se tipa el argumento que ya se le pasaba)
  const setArrivalTimes = (obj: any) => {
      Object.entries(obj).forEach(([id, mins]) => setGlobalArrivalTime(Number(id), Number(mins)));
  };
  const setCheckInTimes = (obj: any) => {
      Object.entries(obj).forEach(([id, mins]) => setGlobalCheckInTime(Number(id), Number(mins)));
  };
  const setGlobalClockStates = (updater: any) => {
      const nextState = typeof updater === 'function' ? updater(globalClockStates) : updater;
      Object.entries(nextState).forEach(([id, state]) => setGlobalClockState(Number(id), state as string));
  };
  const simHours = Math.floor(currentSimTime / 60);
  const simMins = currentSimTime % 60;
  const ampm = simHours >= 12 ? 'pm' : 'am';
  const displayHours = simHours > 12 ? simHours - 12 : simHours;
  
  // Hora para alertas y logs
  const formattedTime = `${displayHours.toString()}:${simMins.toString().padStart(2, '0')} ${ampm}`;

  // Rutinas "Horario Fijo" (merge FE): al avanzar el reloj de esta instancia (currentSimTime =
  // realTimeMins en producción, globalSimTime en el simulador), dispara las rutinas `scheduled`
  // cuya hora ya se alcanzó, para el usuario de ESTA instancia. Es idempotente (el store dedupea
  // por un id determinista que lleva la fecha), así que puede correr cada minuto sin duplicar.
  // Sin esto, las rutinas de horario fijo se configuraban y guardaban pero NUNCA se asignaban.
  useEffect(() => {
    useTaskStore.getState().triggerScheduledRoutines(currentSimTime, currentUser);
  }, [currentSimTime, currentUser?.id]);

  // NUEVO (estado #16 de docs/Logica Dial.md — "Jornada Activa"): en turno activo, el centro del dial
  // debe mostrar un cronómetro VIVO de tiempo trabajado (HH:MM:SS) en vez de la hora actual. Se calcula
  // aquí y se pasa a <DialPrincipal> como workedElapsedLabel; el componente decide mostrarlo solo cuando
  // clockState === 'active'. Los minutos salen de (currentSimTime - hora de check-in); los segundos de
  // realSeconds (ticker 0-59 de pared) para que el contador avance visualmente segundo a segundo. En modo
  // simulado los segundos igual corren para dar sensación de vivo, aunque el reloj sim avance por minutos.
  const workedCheckInMins = checkInTimes[currentUser?.id];
  const workedElapsedLabel = (clockState === 'active' && workedCheckInMins !== undefined)
    ? (() => {
        const total = Math.max(0, currentSimTime - workedCheckInMins);
        const wh = Math.floor(total / 60);
        const wm = total % 60;
        return `${wh.toString().padStart(2, '0')}:${wm.toString().padStart(2, '0')}:${realSeconds.toString().padStart(2, '0')}`;
      })()
    : null;

  
  // const clockState = globalClockStates[currentUser.id];
  
  const updateClockState = (userId: any, state: any) => {
    setGlobalClockStates((prev: any) => {
        const prevState = prev[userId] || 'inactive';
        
        if (prevState !== state) {
            let actionName = 'Cambio de estado';
            let type: 'success'|'warning'|'error'|'info'|'system' = 'info';
            let desc = `Transición de [${prevState.toUpperCase()}] a [${state.toUpperCase()}]`;
            
            if (state === 'active' && prevState === 'inactive') { 
                actionName = 'Fichaje de Entrada';
                // Trigger Tasks
                const u = globalUsers.find(user => user.id === userId);
                if (u) {
                    useTaskStore.getState().triggerCheckInRoutines(userId, u.job_role_id ?? 0, currentSimTime);
                }

                // Fase 1 del reordenamiento (2026-07-26): la puntualidad se calculaba a mano
                // aquí, con tolerancia por defecto 10 — mientras que otros tres puntos del
                // mismo archivo usaban 15 o un 10 fijo. Ahora todos pasan por `logic/attendance.ts`,
                // que además garantiza que el retardo nunca sea negativo ni decimal (§61).
                const startMins = shiftConfigs[userId]?.start ? parseInt(shiftConfigs[userId].start.split(':')[0])*60 + parseInt(shiftConfigs[userId].start.split(':')[1]) : 480;
                const evaluacion = evaluateCheckIn({
                    nowMins: currentSimTime,
                    shiftStartMins: startMins,
                    toleranceMins: shiftConfigs[userId]?.tolerance,
                });
                if (!evaluacion.isLate) {
                    type = 'success';
                    desc = `Fichaje exitoso. El empleado ha llegado a tiempo (Puntual).`;
                } else {
                    type = 'warning';
                    desc = `Fichaje con retardo. El empleado llegó tarde por ${evaluacion.lateMins} minutos.`;
                }
            }
            else if (state === 'inactive' || state === 'finished') { 
                // BUG FIX: 'finished' es el nuevo estado post-checkout. Manejamos ambos por compatibilidad.
                actionName = 'Fichaje de Salida'; 
                type = 'warning'; 
                setCheckOutTimes((prev: any) => ({ ...prev, [userId]: currentSimTime }));
                // Trigger Spill-over de tareas no completadas
                const u = globalUsers.find(user => user.id === userId);
                if (u) {
                    useTaskStore.getState().handleSpillOver(userId, u.job_role_id ?? 0);
                }
            }
            else if (state === 'meal') { 
                actionName = 'Salida a Comer'; 
                type = 'info'; 
                setMealStartTimes((prev: any) => ({ ...prev, [userId]: currentSimTime }));
            }
            else if (prevState === 'meal' && state === 'active') { 
                actionName = 'Regreso de Comida'; 
                type = 'success'; 
                setMealEndTimes((prev: any) => ({ ...prev, [userId]: currentSimTime }));
            }
            else if (state === 'short_break') { 
                actionName = 'Descanso Corto (Ley Silla)'; 
                type = 'info'; 
                setBreakStartTimes((prev: any) => ({ ...prev, [userId]: currentSimTime }));
            }
            else if (prevState === 'short_break' && state === 'active') { 
                actionName = 'Fin de Descanso'; 
                type = 'success'; 
                setBreakEndTimes((prev: any) => ({ ...prev, [userId]: currentSimTime }));
            }
            else if (state === 'contingency') { actionName = 'Contingencia / Retardo'; type = 'error'; }
            else if (state === 'waiting_room') { actionName = 'En Sala de Espera'; type = 'system'; }
            
            useAppStore.getState().addMatrixEvent(
               actionName, 
               desc, 
               type, 
               userId
            );
        }

        if (prevState !== state) {
            const timeStr = `${Math.floor(currentSimTime/60).toString().padStart(2, '0')}:${(currentSimTime%60).toString().padStart(2, '0')}`;
            let type = 'check_in';
            if (state === 'meal') type = 'meal_start';
            else if (state === 'inactive' || state === 'finished') type = 'check_out'; // BUG FIX: ambos mapean a check_out
            else if (prevState === 'meal' && state === 'active') type = 'meal_end';
            
            // Fase 1 del reordenamiento (2026-07-26) — ESTE ES EL PUNTO QUE ESCRIBE A LA BASE.
            // Antes calculaba el retardo a mano y con una tolerancia distinta a la de otros
            // tres puntos del archivo. Ahora usa `logic/attendance.ts`, que garantiza que
            // `lateMins` sea entero y nunca negativo — las dos condiciones que fallaron en
            // producción (§61: -296.84 min a alguien que llegó temprano, con incidente de
            // descuento de salario y un insert reventado por el decimal).
            // Además maneja turnos que cruzan la medianoche, que aquí se calculaban mal.
            const startMins = shiftConfigs[userId]?.start ? parseInt(shiftConfigs[userId].start.split(':')[0])*60 + parseInt(shiftConfigs[userId].start.split(':')[1]) : 480;
            const evalFichaje = evaluateCheckIn({
                nowMins: currentSimTime,
                shiftStartMins: startMins,
                toleranceMins: shiftConfigs[userId]?.tolerance ?? timeBankConfigs.maxLateMinsAllowed,
            });
            const isLate = type === 'check_in' && evalFichaje.isLate;
            const lateMins = isLate ? evalFichaje.lateMins : 0;
            
            syncToBackend('clock/punch', {
                user_id: userId,
                type,
                time: timeStr,
                details: {}
            });
        }
        return { ...prev, [userId]: state };
    });
  };

  // Modales

  const [globalBroadcastMessage, setGlobalBroadcastMessage] = useState<string | null>(null);
  const [broadcastInput, setBroadcastInput] = useState("");
  
  // TODO: MUERTO — usar Chat Operativo modo privado.
  //
  // Diagnosticado el 2026-08-06: el mensaje privado del admin al colaborador NO EXISTE como
  // función. `setPrivateMessages` no tiene ni una llamada en todo el proyecto, así que el mapa
  // nace vacío y muere vacío, y la rama que pinta "🚨 Mensaje del Admin" en RelojVisual es
  // inalcanzable. `privateInput`/`privateTarget` tampoco los usa ninguna pantalla: no hay dónde
  // escribir el mensaje.
  //
  // Decisión de producto: NO se resucita este camino. El Chat Operativo del Monitor 360 ya
  // funciona sobre la misma tabla (`internal_messages`) y se le añadirá modo privado; mantener
  // dos canales sería duplicar el problema. **Estas tres líneas se borran junto con esa
  // implementación**, no antes, para que quien pase por aquí sepa que la función no existe sin
  // tener que volver a diagnosticarlo.
  //
  // (De aquí salió además la fuga ya cerrada: `/sync/state` repartía los mensajes privados de la
  // empresa a toda la plantilla. Ver `ClockController::getState`.)
  const [privateMessages, setPrivateMessages] = useState<Record<number, string>>({});
  const [privateInput, setPrivateInput] = useState("");
  const [privateTarget, setPrivateTarget] = useState<number>(1);
  
  const [justificanteText, setJustificanteText] = useState("");
  

  // NOTA (refactor Jul 2026): showEmergencyOpenModal, emergencyOpenSubmitting, showContingencyModal,
  // contingencySubmitting y activeContingency ahora viven en hooks/useStoreOpening.ts.

  // Alerta de GPS al salir del perímetro sin pase de salida
  const lastAlertSentRef = useRef<number | null>(null);

  // NUEVO (estado #6 de la matriz — "En Camino a Sucursal"): historial corto de distancia GPS
  // para detectar movimiento real de aproximación, en lugar de solo mostrar un "fuera de rango"
  // estático. Se guardan hasta 5 muestras de los últimos 2 minutos; si la distancia bajó al menos
  // 15m entre la primera y la última muestra, se considera que el empleado va en camino.
  const gpsDistanceHistoryRef = useRef<{ dist: number; ts: number }[]>([]);
  useEffect(() => {
    if (gpsStatus !== 'success') return;
    const now = Date.now();
    const history = gpsDistanceHistoryRef.current
      .filter(sample => now - sample.ts < 120000)
      .concat([{ dist: gpsDistance, ts: now }])
      .slice(-5);
    gpsDistanceHistoryRef.current = history;
  }, [gpsDistance, gpsStatus]);

  const isApproachingStore = () => {
    const history = gpsDistanceHistoryRef.current;
    if (history.length < 2) return false;
    const first = history[0].dist;
    const last = history[history.length - 1].dist;
    return last < first - 15;
  };

  useEffect(() => {
    // Solo con geocerca capturada: sin storeLocation/store_latitude, gpsDistance se mide contra
    // el fallback legacy (Zócalo CDMX) y esta alerta disparaba en falso a todo tenant sin
    // ubicación configurada (R105, misma familia que el gate del dial).
    if (clockState === 'active' && gpsStatus === 'success' && currentUser?.id && hasStoreLocation) {
      const alertThreshold = clockOpConfig.gpsAlertRangeMeters || 100;
      if (gpsDistance > alertThreshold) {
        const now = Date.now();
        if (!lastAlertSentRef.current || now - lastAlertSentRef.current > 60000) {
          lastAlertSentRef.current = now;
          useAppStore.getState().addMatrixEvent(
            '🚨 Abandono de Sucursal Detectado',
            `El colaborador ${currentUser.name} se encuentra fuera del perímetro permitido (${Math.round(gpsDistance)} metros de distancia) sin un pase registrado.`,
            'warning',
            currentUser.id
          );
          showCustomAlert(`⚠️ Alerta: Estás a ${Math.round(gpsDistance)}m de la sucursal. Se ha notificado al supervisor por abandono de perímetro.`);
        }
      }
    }
  }, [gpsDistance, clockState, gpsStatus, currentUser?.name, currentUser?.id, clockOpConfig.gpsAlertRangeMeters, hasStoreLocation]);

  const [paseListaEmployees, setPaseListaEmployees] = useState<any[]>([]);
  // §23: evidencia fotográfica de comedor
  const [showMealPhotoModal, setShowMealPhotoModal] = useState(false);
  const [mealPhotoType, setMealPhotoType] = useState<'meal_start' | 'meal_end'>('meal_start');
  const [mealPhotoSubmitting, setMealPhotoSubmitting] = useState(false);
  const isMealPhotoRequired = systemSettings?.clockOpConfig?.require_meal_photo_evidence === true;
  // §25: Ley Silla con aprobación de supervisor + aforo. Cuando el switch está activo, el descanso de
  // silla exige: (1) el empleado SOLICITA (POST /clock/silla/request), (2) el supervisor APRUEBA, y
  // (3) el empleado presiona de nuevo para fichar silla_start (el backend valida aprobación + aforo).
  const isSillaApprovalRequired = systemSettings?.clockOpConfig?.require_silla_approval === true;
  const [sillaRequestStage, setSillaRequestStage] = useState<'none' | 'requested'>('none');
  const [sillaStatus, setSillaStatus] = useState<{ max_simultaneous: number; active_count: number; available: number } | null>(null);
  const [cashCount, setCashCount] = useState("");
  const [kioscoInput, setKioscoInput] = useState('');
  const [evalStars, setEvalStars] = useState(0);
  const [storeOpenLog, setStoreOpenLog] = useState<{time: string, type: 'normal'|'forzosa'} | null>(null);
  const [absenceReason, setAbsenceReason] = useState("");
  const [earlyDepartureReason, setEarlyDepartureReason] = useState("Enfermedad");
  const [isOvertimeUnlocked, setIsOvertimeUnlocked] = useState<Record<number, boolean>>({});
  const [isSimulatedHoliday, setIsSimulatedHoliday] = useState(() => localStorage.getItem('is_simulated_holiday') === 'true');
  const [contingencyLogs, setContingencyLogs] = useState<any[]>([]);
  const [contingencyUsed, setContingencyUsed] = useState<Record<number, boolean>>({});
  const [absentUsers, setAbsentUsers] = useState<Record<number, boolean>>({});
  const [lateUsers, setLateUsers] = useState<Record<number, boolean>>({});

  const [auditoryLogs, setAuditoryLogs] = useState<any[]>([]);
  const [reportForm, setReportForm] = useState({ targetId: '', type: '', details: '' });

  const DIAS_SEMANA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
  const [currentDay, setCurrentDay] = [globalSimDay, setGlobalSimDay];
  const [selectedSummaryDay, setSelectedSummaryDay] = useState('Domingo');
  const [dailyHistory, setDailyHistory] = useState<Record<string, any>>({});

// MOTOR MATEMATICO
  const calculateDailyStats = (user: any, targetDay: any) => {
    const dayData = dailyHistory[targetDay] || {};
    const userData = dayData[user.id] || {};
    
    // Fallback to real-time state if looking at currentDay
    const isCurrentDay = targetDay === currentDay;
    const effectiveStatus = isCurrentDay ? (globalClockStates[user.id] || 'inactive') : (userData.status || 'inactive');
    const effectiveArrival = isCurrentDay ? globalArrivalTimes[user.id] : userData.arrivalTime;
    const activeMeal = activeTimers[user.id]?.type === 'meal' ? activeTimers[user.id] : null;
    const effectiveMealStart = isCurrentDay ? (activeMeal ? activeMeal.startSimTime : null) : userData.mealStart;
    const effectiveMealEnd = isCurrentDay ? (globalClockStates[user.id] !== 'meal' && activeMeal ? currentSimTime : null) : userData.mealEnd;
    const isAbsentLoc = isCurrentDay ? absentUsers[user.id] : userData.isAbsent;
    const isLateLoc = isCurrentDay ? lateUsers[user.id] : userData.isLate;

    let mealTaken = 0;
    if (effectiveMealStart && effectiveMealEnd) mealTaken = effectiveMealEnd - effectiveMealStart;
    else if (effectiveMealStart && !effectiveMealEnd) mealTaken = currentSimTime - effectiveMealStart;

    let status = 'A tiempo';
    let statusClass = 'bg-emerald-100 text-emerald-700';
    let penaltyText = null;

    if (isAbsentLoc) {
        status = 'Falta';
        statusClass = 'bg-rose-100 text-rose-700';
    } else if (isLateLoc) {
        status = 'Retardo';
        statusClass = 'bg-amber-100 text-amber-700';
        penaltyText = '-$50';
    } else if (effectiveStatus === 'inactive' && !effectiveArrival && !isAbsentLoc) {
        status = 'No Fichado';
        statusClass = 'bg-slate-100 text-slate-500';
    }

    if (mealTaken > timeBankConfigs.mealMinutes) {
        status = 'Penalidad';
        statusClass = 'bg-amber-100 text-amber-700';
        penaltyText = `-${(mealTaken - timeBankConfigs.mealMinutes) * 3}m`;
    }

    const restTaken = user.name === 'Valeria' ? 20 : 15;
    const allowedRest = 15;
    return { mealTaken, penaltyText, status, statusClass, restTaken, allowedRest, effectiveArrival, effectiveMealStart, effectiveMealEnd };
  };


  // Matrix Tabs
  const [matrixTab, setMatrixTab] = useState('simulador');
  
  const [weeklyHistory, setWeeklyHistory] = useState<any>({});

  // RESTAURADO TEMPORALMENTE PARA EVITAR PANTALLA BLANCA (Hasta completar Fase 7)
  

  
  
  const urlParams = new URLSearchParams(window.location.search);
  const isNativeURL = urlParams.get('mode') === 'native';
  const isNativeMode = featureFlags.modoNativo || isNativeURL;
  
  // Fase 2: Reservación de Comidas
  

  // Global Settings movidos a Matrix Rules
  
  
  const {
    reservedMeals, setReservedMeals,
    hasReservedMeal, setHasReservedMeal,
    userReservedMealSlots, setUserReservedMealSlots
  } = useAppStore();
  
  
  const confirmMealReservation = async (startSlotIndex: number) => {
    const safeStartHour = mealSettings?.startHour ?? 13;
    const safeStepMins = mealSettings?.stepMins ?? 15;
    const checkMins = safeStartHour * 60 + (startSlotIndex * safeStepMins);
    const ch = Math.floor(checkMins / 60);
    const cm = checkMins % 60;
    const campm = ch >= 12 ? 'PM' : 'AM';
    const firstSlot = `${ch > 12 ? ch - 12 : ch}:${cm.toString().padStart(2,'0')} ${campm}`;

    // Add safe checks to needed blocks
    const userMealMinutes = currentUser?.mealMinutes || timeBankConfigs?.mealMinutes || 60;
    const neededBlocks = Math.ceil(userMealMinutes / safeStepMins);
    
    let blocksToReserve: string[] = [];
    for(let j=0; j<neededBlocks; j++) {
       const bMins = safeStartHour * 60 + ((startSlotIndex + j) * safeStepMins);
       const bh = Math.floor(bMins / 60);
       const bm = bMins % 60;
       const bampm = bh >= 12 ? 'PM' : 'AM';
       blocksToReserve.push(`${bh > 12 ? bh - 12 : bh}:${bm.toString().padStart(2,'0')} ${bampm}`);
    }

    // Clean up any previous reservation for currentUser to guarantee strictly 1 slot per collaborator
    const oldSlots = userReservedMealSlots[currentUser.id] || [];
    const newReservedMeals = { ...reservedMeals };
    if (oldSlots.length > 0) {
      oldSlots.forEach(slot => {
        if (newReservedMeals[slot]) {
          newReservedMeals[slot] = newReservedMeals[slot].filter((item: any) => Number(item.userId) !== Number(currentUser.id));
          if (newReservedMeals[slot].length === 0) {
            delete newReservedMeals[slot];
          }
        }
      });
    }

    setHasReservedMeal({ ...hasReservedMeal, [currentUser.id]: true });
    setUserReservedMealSlots({ ...userReservedMealSlots, [currentUser.id]: blocksToReserve });
    
    blocksToReserve.forEach(slot => {
       if (!newReservedMeals[slot]) newReservedMeals[slot] = [];
       newReservedMeals[slot].push({ userId: currentUser.id, role: currentUser.role });
    });
    setReservedMeals(newReservedMeals);
    
    // We can't access syncToDB here if it's defined later, but since it's just a fetch:
    try {
        const todayStr = new Date().toLocaleDateString('sv-SE');
        await axiosInstance.post('/sync/clock', {
            user_id: currentUser.id,
            date: todayStr,
            type: 'meal_reservation',
            time: `${ch.toString().padStart(2,'0')}:${cm.toString().padStart(2,'0')}`,
            details: firstSlot
        });
        window.dispatchEvent(new Event('db_sync_updated'));
        setShowMealReservationModal(false);
        
        // EVENTO DE BITACORA PARA MATRIX/SANDBOX
        useAppStore.getState().addMatrixEvent(
            '🍽️ Reservación de Comida',
            `${currentUser.name} (${currentUser.role}) reservó bloque de comida iniciando a las ${firstSlot}.`,
            'info',
            currentUser.id
        );

        showCustomAlert(`✅ Reservación confirmada para las ${firstSlot}.`);
    } catch (e) {
        console.error(e);
    }
  };

  const cancelMealReservation = async (userId: number) => {
    try {
      const mySlots = userReservedMealSlots[userId] || [];
      
      const newHasReserved = { ...hasReservedMeal };
      delete newHasReserved[userId];
      setHasReservedMeal(newHasReserved);
      
      const newSlots = { ...userReservedMealSlots };
      delete newSlots[userId];
      setUserReservedMealSlots(newSlots);
      
      const newReservedMeals = { ...reservedMeals };
      mySlots.forEach(slot => {
        if (newReservedMeals[slot]) {
          newReservedMeals[slot] = newReservedMeals[slot].filter((item: any) => Number(item.userId) !== Number(userId));
          if (newReservedMeals[slot].length === 0) {
            delete newReservedMeals[slot];
          }
        }
      });
      setReservedMeals(newReservedMeals);
      
      const todayStr = new Date().toLocaleDateString('sv-SE');
      await axiosInstance.post('/sync/clock', {
        user_id: userId,
        date: todayStr,
        type: 'meal_cancel',
        time: '00:00',
        details: 'Cancelled meal reservation'
      });
      window.dispatchEvent(new Event('db_sync_updated'));
      
      const targetUser = globalUsers.find((u: any) => u.id === userId) || currentUser;
      useAppStore.getState().addMatrixEvent(
        '🍽️ Cancelación de Comida',
        `${targetUser.name} (${targetUser.role}) canceló su reserva de comida.`,
        'warning',
        userId
      );
      showCustomAlert(`✅ Reservación cancelada con éxito.`);
    } catch (e) {
      console.error(e);
    }
  };

  const swapMealSlots = async (userAId: number, userBId: number) => {
    try {
      const slotsA = userReservedMealSlots[userAId] || [];
      const slotsB = userReservedMealSlots[userBId] || [];
      
      const hasA = !!hasReservedMeal[userAId];
      const hasB = !!hasReservedMeal[userBId];
      
      const newSlots = { ...userReservedMealSlots };
      if (slotsB.length > 0) {
        newSlots[userAId] = slotsB;
      } else {
        delete newSlots[userAId];
      }
      
      if (slotsA.length > 0) {
        newSlots[userBId] = slotsA;
      } else {
        delete newSlots[userBId];
      }
      setUserReservedMealSlots(newSlots);
      
      const newHasReserved = { ...hasReservedMeal };
      newHasReserved[userAId] = hasB;
      newHasReserved[userBId] = hasA;
      setHasReservedMeal(newHasReserved);
      
      const newReservedMeals = { ...reservedMeals };
      
      slotsA.forEach(slot => {
        if (newReservedMeals[slot]) {
          newReservedMeals[slot] = newReservedMeals[slot].filter((item: any) => Number(item.userId) !== Number(userAId));
        }
      });
      slotsB.forEach(slot => {
        if (newReservedMeals[slot]) {
          newReservedMeals[slot] = newReservedMeals[slot].filter((item: any) => Number(item.userId) !== Number(userBId));
        }
      });
      
      const userAObj = globalUsers.find((u: any) => u.id === userAId) || currentUser;
      const userBObj = globalUsers.find((u: any) => u.id === userBId) || { name: 'Compañero', role: 'Colaborador' };
      
      slotsB.forEach(slot => {
        if (!newReservedMeals[slot]) newReservedMeals[slot] = [];
        newReservedMeals[slot].push({ userId: userAId, role: userAObj.role });
      });
      slotsA.forEach(slot => {
        if (!newReservedMeals[slot]) newReservedMeals[slot] = [];
        newReservedMeals[slot].push({ userId: userBId, role: userBObj.role });
      });
      setReservedMeals(newReservedMeals);
      
      const todayStr = new Date().toLocaleDateString('sv-SE');
      // R103 (merge FE): `swap_with` ESTRUCTURADO — el backend valida server-side el pareo, que
      // ambos tengan el MISMO job_role_id de expediente y que el actor sea parte del swap
      // (spec §5.3). Antes la regla "sólo compañeros de tu mismo puesto" vivía únicamente en el
      // filtro del modal, así que un cliente hostil intercambiaba a cualquier par del tenant.
      // El texto de `details` queda sólo como nota humana.
      await axiosInstance.post('/sync/clock', {
        user_id: userAId,
        date: todayStr,
        type: 'meal_swap',
        time: '00:00',
        swap_with: userBId,
        details: `Swapped meal slots with user ${userBId}`
      });
      await axiosInstance.post('/sync/clock', {
        user_id: userBId,
        date: todayStr,
        type: 'meal_swap',
        time: '00:00',
        swap_with: userAId,
        details: `Swapped meal slots with user ${userAId}`
      });
      window.dispatchEvent(new Event('db_sync_updated'));
      
      useAppStore.getState().addMatrixEvent(
        '🔄 Intercambio de Comida',
        `${userAObj.name} intercambió su horario de comida con ${userBObj.name}.`,
        'info',
        userAId
      );
      showCustomAlert(`✅ Horario intercambiado con ${userBObj.name}.`);
    } catch (e) {
      console.error(e);
    }
  };

  const requestBreak = async (userId: number) => {
    try {
      const timeStr = formattedTime;
      
      if (isSandboxMode) {
        setPendingBreakRequests((prev: any) => ({
          ...prev,
          [userId]: { time: currentSimTime }
        }));

        useAppStore.getState().addMatrixEvent(
          '🧘 Solicitud de Descanso',
          `${currentUser.name} ha solicitado iniciar un descanso de Ley Silla (15 min).`,
          'info',
          userId
        );
      } else {
        await axiosInstance.post('/clock/punch', {
          user_id: userId,
          type: 'break_request',
          time: timeStr,
          details: { note: 'Solicitud de descanso Ley Silla' }
        });
        
        useAppStore.setState((s: any) => ({
          globalPendingBreakRequests: {
            ...s.globalPendingBreakRequests,
            [userId]: { time: parseTimeToMins(timeStr), details: 'Solicitud de descanso Ley Silla' }
          }
        }));
      }
      showCustomAlert('✅ Solicitud de descanso enviada al supervisor.');
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch (e) {
      console.error(e);
      showCustomAlert('⚠️ Error al enviar la solicitud de descanso.');
    }
  };

  const approveBreakRequest = async (targetUserId: number) => {
    try {
      const timeStr = formattedTime;
      const targetUser = globalUsers.find((u: any) => u.id === targetUserId) || currentUser;

      if (isSandboxMode) {
        setPendingBreakRequests((prev: any) => {
          const next = { ...prev };
          delete next[targetUserId];
          return next;
        });

        setBreakStartTimes((prev: any) => ({
          ...prev,
          [targetUserId]: currentSimTime
        }));
        
        updateClockState(targetUserId, 'short_break');

        useAppStore.getState().addMatrixEvent(
          '🧘 Descanso Aprobado',
          `El supervisor aprobó el descanso de Ley Silla para ${targetUser.name}.`,
          'success',
          targetUserId
        );
      } else {
        await axiosInstance.post('/clock/punch', {
          user_id: targetUserId,
          type: 'break_start',
          time: timeStr,
          details: { note: 'Aprobado por el supervisor' }
        });

        useAppStore.setState((s: any) => {
          const nextPending = { ...s.globalPendingBreakRequests };
          delete nextPending[targetUserId];
          
          return {
            globalPendingBreakRequests: nextPending,
            globalClockStates: {
              ...s.globalClockStates,
              [targetUserId]: 'short_break'
            },
            globalBreakStartTimes: {
              ...s.globalBreakStartTimes,
              [targetUserId]: parseTimeToMins(timeStr)
            }
          };
        });
      }
      showCustomAlert(`✅ Descanso aprobado para ${targetUser.name}.`);
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch (e) {
      console.error(e);
      showCustomAlert('⚠️ Error al aprobar la solicitud.');
    }
  };

  const rejectBreakRequest = async (targetUserId: number) => {
    try {
      const timeStr = formattedTime;
      const targetUser = globalUsers.find((u: any) => u.id === targetUserId) || currentUser;

      if (isSandboxMode) {
        setPendingBreakRequests((prev: any) => {
          const next = { ...prev };
          delete next[targetUserId];
          return next;
        });

        useAppStore.getState().addMatrixEvent(
          '🧘 Descanso Rechazado',
          `El supervisor rechazó la solicitud de descanso para ${targetUser.name}.`,
          'warning',
          targetUserId
        );
      } else {
        await axiosInstance.post('/clock/punch', {
          user_id: targetUserId,
          type: 'break_rejected',
          time: timeStr,
          details: { note: 'Rechazado por el supervisor' }
        });

        useAppStore.setState((s: any) => {
          const nextPending = { ...s.globalPendingBreakRequests };
          delete nextPending[targetUserId];
          return {
            globalPendingBreakRequests: nextPending
          };
        });
      }
      showCustomAlert(`❌ Descanso rechazado para ${targetUser.name}.`);
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch (e) {
      console.error(e);
      showCustomAlert('⚠️ Error al rechazar la solicitud.');
    }
  };
  
  // NOTA (refactor Jul 2026): keyholders, showKeyDelegationModal, nextDayEncargadoId y toda la
  // lógica de "quién tiene llaves" ahora viven en hooks/useKeyholderDelegation.ts — ver la llamada
  // a useKeyholderDelegation() más arriba.


  const [userSettings, setUserSettings] = useState({ theme: 'light', fontSize: 'normal' });
  const [undoCount, setUndoCount] = useState(0);
  const [playedAlarms, setPlayedAlarms] = useState({ ya_llegue: false, tienda_cerrada: false });

  // Preferencias de Alarma y Alertas por Empleado
  const [userClockPrefs, setUserClockPrefs] = useState(() => {
    const saved = localStorage.getItem(`user_clock_prefs_${currentUser?.id || 'default'}`);
    return saved ? JSON.parse(saved) : {
      alarmsEnabled: true,
      selectedTone: 'classic', // classic, cheerful, urgent, chime
      preShiftReminderMins: 30,
      mealReminderMins: 5,
      leySillaAlert: true,
      newTaskAlert: true,
      taskExpiryWarningMins: 10,
    };
  });

  useEffect(() => {
    if (currentUser?.id) {
      localStorage.setItem(`user_clock_prefs_${currentUser.id}`, JSON.stringify(userClockPrefs));
    }
  }, [userClockPrefs, currentUser?.id]);

  const [supervisorPin, setSupervisorPin] = useState('');
  const [supervisorQrToken, setSupervisorQrToken] = useState('');

  const [expiringTasksAlerted, setExpiringTasksAlerted] = useState<Record<string, boolean>>({});

  // Reset alarms when user or day changes
  useEffect(() => {
    setPlayedAlarms({ ya_llegue: false, tienda_cerrada: false });
    setPreShiftAlarmPlayed(false);
    setMealReminderAlarmPlayed(false);
    setLeySillaAlarmPlayed(false);
    setExpiringTasksAlerted({});
  }, [currentUser?.id, currentDay]);

  const playAlarm = (type: 'ya_llegue' | 'tienda_cerrada' | 'alerta_tiempo') => {
    if (!userClockPrefs.alarmsEnabled) return;
    try {
      const AudioContextClass = window.AudioContext || (window as any).webkitAudioContext;
      if (!AudioContextClass) return;
      const ctx = new AudioContextClass();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      
      const tone = userClockPrefs.selectedTone;

      if (tone === 'cheerful') {
        // Melodía Alegre / Arpegio (Sine wave)
        osc.type = 'sine';
        gain.gain.setValueAtTime(0.08, ctx.currentTime);
        if (type === 'ya_llegue') {
          osc.frequency.setValueAtTime(523.25, ctx.currentTime); // C5
          osc.frequency.setValueAtTime(659.25, ctx.currentTime + 0.1); // E5
          osc.frequency.setValueAtTime(783.99, ctx.currentTime + 0.2); // G5
          osc.frequency.setValueAtTime(1046.50, ctx.currentTime + 0.3); // C6
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
          osc.start();
          osc.stop(ctx.currentTime + 0.6);
        } else if (type === 'tienda_cerrada') {
          osc.frequency.setValueAtTime(659.25, ctx.currentTime); // E5
          osc.frequency.setValueAtTime(587.33, ctx.currentTime + 0.15); // D5
          osc.frequency.setValueAtTime(523.25, ctx.currentTime + 0.3); // C5
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.6);
          osc.start();
          osc.stop(ctx.currentTime + 0.7);
        } else {
          osc.frequency.setValueAtTime(659.25, ctx.currentTime); // E5
          osc.frequency.setValueAtTime(783.99, ctx.currentTime + 0.1); // G5
          osc.frequency.setValueAtTime(1174.66, ctx.currentTime + 0.2); // D6
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
          osc.start();
          osc.stop(ctx.currentTime + 0.5);
        }
      } else if (tone === 'urgent') {
        // Zumbido Urgente / Alerta (Sawtooth wave)
        osc.type = 'sawtooth';
        gain.gain.setValueAtTime(0.05, ctx.currentTime);
        if (type === 'ya_llegue') {
          osc.frequency.setValueAtTime(880.00, ctx.currentTime); // A5
          osc.frequency.setValueAtTime(1174.66, ctx.currentTime + 0.1); // D6
          osc.frequency.setValueAtTime(880.00, ctx.currentTime + 0.2); // A5
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
          osc.start();
          osc.stop(ctx.currentTime + 0.45);
        } else if (type === 'tienda_cerrada') {
          osc.frequency.setValueAtTime(600, ctx.currentTime);
          osc.frequency.setValueAtTime(500, ctx.currentTime + 0.15);
          osc.frequency.setValueAtTime(600, ctx.currentTime + 0.3);
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.6);
          osc.start();
          osc.stop(ctx.currentTime + 0.65);
        } else {
          osc.frequency.setValueAtTime(880.00, ctx.currentTime);
          osc.frequency.setValueAtTime(880.00, ctx.currentTime + 0.15);
          osc.frequency.setValueAtTime(880.00, ctx.currentTime + 0.3);
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.55);
          osc.start();
          osc.stop(ctx.currentTime + 0.6);
        }
      } else if (tone === 'chime') {
        // Campana / Chime suave (Triangle wave)
        osc.type = 'triangle';
        gain.gain.setValueAtTime(0.12, ctx.currentTime);
        if (type === 'ya_llegue') {
          osc.frequency.setValueAtTime(1046.50, ctx.currentTime); // C6
          osc.frequency.setValueAtTime(783.99, ctx.currentTime + 0.15); // G5
          gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.7);
          osc.start();
          osc.stop(ctx.currentTime + 0.8);
        } else if (type === 'tienda_cerrada') {
          osc.frequency.setValueAtTime(783.99, ctx.currentTime); // G5
          osc.frequency.setValueAtTime(698.46, ctx.currentTime + 0.15); // F5
          osc.frequency.setValueAtTime(587.33, ctx.currentTime + 0.3); // D5
          gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.9);
          osc.start();
          osc.stop(ctx.currentTime + 1.0);
        } else {
          osc.frequency.setValueAtTime(698.46, ctx.currentTime); // F5
          osc.frequency.setValueAtTime(880.00, ctx.currentTime + 0.12); // A5
          gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
          osc.start();
          osc.stop(ctx.currentTime + 0.7);
        }
      } else {
        // Tono clásico original
        if (type === 'ya_llegue') {
          osc.type = 'sine';
          osc.frequency.setValueAtTime(523.25, ctx.currentTime); // Do5
          osc.frequency.setValueAtTime(659.25, ctx.currentTime + 0.15); // Mi5
          gain.gain.setValueAtTime(0.1, ctx.currentTime);
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
          osc.start();
          osc.stop(ctx.currentTime + 0.5);
        } else if (type === 'tienda_cerrada') {
          osc.type = 'square';
          osc.frequency.setValueAtTime(400, ctx.currentTime); 
          osc.frequency.setValueAtTime(300, ctx.currentTime + 0.2);
          osc.frequency.setValueAtTime(400, ctx.currentTime + 0.4);
          gain.gain.setValueAtTime(0.1, ctx.currentTime);
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.8);
          osc.start();
          osc.stop(ctx.currentTime + 0.9);
        } else if (type === 'alerta_tiempo') {
          osc.type = 'triangle';
          osc.frequency.setValueAtTime(587.33, ctx.currentTime); // Re5
          osc.frequency.setValueAtTime(880.00, ctx.currentTime + 0.15); // La5
          gain.gain.setValueAtTime(0.1, ctx.currentTime);
          gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.45);
          osc.start();
          osc.stop(ctx.currentTime + 0.5);
        }
      }
    } catch (e) {
      console.log("Audio no soportado");
    }
  };

  // Push Notification: Pase de Lista Retardado
  // BUG FIX (2026-07-21): antes esta notificación se re-disparaba en CADA tick del reloj mientras
  // paseListaDone siguiera en false. Al descartarla (setActivePushNotification(null)), el efecto se
  // re-ejecutaba porque currentSimTime cambia cada segundo y la volvía a poner de inmediato — para el
  // encargado activo (p.ej. Francisco en la Matrix) quedaba en un bucle que no dejaba hacer nada.
  // Ahora se dispara UNA SOLA VEZ por cada apertura (se recuerda el storeOpenSimTime ya notificado en
  // un ref); si el usuario la descarta, no vuelve a insistir — igual puede hacer el pase de lista desde
  // el botón. El ref se reinicia solo cuando hay una apertura nueva (otro storeOpenSimTime).
  const paseListaPromptedForRef = useRef<number | null>(null);
  useEffect(() => {
    if (storeStatus === 'open' && storeOpenSimTime !== null && !paseListaDone) {
      if (currentSimTime >= storeOpenSimTime && !showPaseListaModal) {
         const alreadyPrompted = paseListaPromptedForRef.current === storeOpenSimTime;
         if (Number(currentUser.id) === Number(activeEncargadoId) && !alreadyPrompted && activePushNotification?.type !== 'pase_lista') {
            const rollCallUnlocked = useAppStore.getState().isFeatureUnlocked('roll_call');
            if (rollCallUnlocked) {
              paseListaPromptedForRef.current = storeOpenSimTime;
              setActivePushNotification({
                 type: 'pase_lista',
                 text: 'Recordatorio: Tienes pendiente el Pase de Lista de apertura.',
                 action: () => {
                    setActivePushNotification(null);
                    initPaseLista(false);
                 }
              });
            }
         }
      }
    } else if (paseListaDone && activePushNotification?.type === 'pase_lista') {
        setActivePushNotification(null);
    }
  }, [currentSimTime, storeStatus, storeOpenSimTime, paseListaDone, showPaseListaModal, currentUser.id, activeEncargadoId, activePushNotification]);

  // Auto-Open Store when Encargado is in perimeter
  useEffect(() => {
    if (isSimulator) return; // No auto-abrir la tienda en el simulador QA Matrix
    
    if (storeStatus === 'closed' && Number(currentUser?.id) === Number(activeEncargadoId) && isWithinPerimeter) {
      handleOpenStore(false);
      showCustomAlert("📍 Encargado detectado en perímetro. Sucursal abierta automáticamente vía GPS.");
    }
  }, [isWithinPerimeter, currentUser?.id, activeEncargadoId, storeStatus]);

  // Delegate keys automatically if the active encargado is on rest day today
  useEffect(() => {
    if (activeEncargadoId && globalUsers.length > 0 && Object.keys(shiftConfigs).length > 0) {
      const activeEncargado = globalUsers.find(u => Number(u.id) === Number(activeEncargadoId));
      if (activeEncargado) {
        const isRestDay = shiftConfigs[activeEncargado.id]?.restDay === currentDay;
        if (isRestDay) {
          let nextEncargadoId = null;
          const hierarchy = globalUsers.filter(u => u.esAperturador).sort((a,b) => (a.jerarquiaLlaves ?? 0) - (b.jerarquiaLlaves ?? 0)).map(u => u.id);
          for (let id of hierarchy) {
            if (Number(id) !== Number(activeEncargado.id) && !absentUsers[id] && (shiftConfigs[id]?.restDay || '') !== currentDay) {
               nextEncargadoId = id;
               break;
            }
          }
          if (nextEncargadoId) {
            setActiveEncargadoId(nextEncargadoId);
            console.log("Delegated keys automatically due to rest day to user:", nextEncargadoId);
          }
        }
      }
    }
  }, [activeEncargadoId, currentDay, globalUsers, shiftConfigs]);

  // Push Notification: Reserva de Comida Escalonada (Desactivado a solicitud del usuario)
  /*
  useEffect(() => {
    if (storeStatus !== 'open' || !featureFlags.comidas) return;
    
    const isCheckIn = globalClockStates[currentUser.id] === 'active' || globalClockStates[currentUser.id] === 'short_break';
    const noReserved = !hasReservedMeal[currentUser.id];
    
    if (isCheckIn && noReserved && globalCheckInTimes[currentUser.id] !== undefined) {
        const myCheckInTime = globalCheckInTimes[currentUser.id];
        const sameTimeUsers = globalUsers.filter(x => globalCheckInTimes[x.id] === myCheckInTime).sort((a, b) => a.id - b.id);
        const myIndex = sameTimeUsers.findIndex(x => x.id === currentUser.id);
        
        const targetTime = myCheckInTime + 5 + (myIndex * 2);
        
        if (currentSimTime >= targetTime && activePushNotification?.type !== 'comida') {
            setActivePushNotification({
               type: 'comida',
               text: `🍔 Es tu turno para reservar tu horario de comida.`,
               action: () => {
                  setActivePushNotification(null);
                  setPhoneTab('checador');
                  setShowMealReservationModal(true);
               }
             });
        }
    }
  }, [currentSimTime, storeStatus, featureFlags.comidas, checkInTimes, hasReservedMeal, globalClockStates, currentUser.id, activePushNotification, globalUsers]);
  */

  // Push Notification: Apertura de Emergencia por Relevo de Llaves
  useEffect(() => {
    if (!isOpeningPremium || storeStatus !== 'closed' || !openingStatus) return;
    
    const currentRespId = Number(openingStatus.current_responsible_employee_id);
    const myId = Number(currentUser?.id);
    
    if (currentRespId === myId && (openingStatus.status === 'transferred' || openingStatus.status === 'active_window')) {
      if (activePushNotification?.type !== 'apertura_transferida') {
        setActivePushNotification({
          type: 'apertura_transferida',
          text: `🚨 Apertura de emergencia: se te ha asignado la responsabilidad de abrir hoy. Dirígete a la sucursal.`,
          action: () => {
            setActivePushNotification(null);
          }
        });
      }
    } else if (currentRespId !== myId && activePushNotification?.type === 'apertura_transferida') {
      setActivePushNotification(null);
    }
  }, [openingStatus, currentUser?.id, isOpeningPremium, storeStatus, activePushNotification]);

  // Push Notification: Tareas Retrasadas y Secuencia de Rutinas
  useEffect(() => {
    if (storeStatus !== 'open') return;
    const storeState = useTaskStore.getState();
    
    // Buscar rutinas asignadas al puesto del usuario
    const myRoleRoutines = storeState.routines.filter(r => r.targetRoleId === currentUser.job_role_id);
    const routineAssignments = storeState.assignments.filter(a => 
      a.userId === currentUser.id && 
      a.assignedFromRoutineId !== undefined &&
      myRoleRoutines.some(r => r.id === a.assignedFromRoutineId)
    );

    if (routineAssignments.length > 0) {
      // Ordenar tareas de la rutina según el orden de taskIds en la rutina
      const sortedAssignments = [...routineAssignments].sort((a, b) => {
        const routine = myRoleRoutines.find(r => r.id === a.assignedFromRoutineId);
        if (!routine) return 0;
        const idxA = routine.taskIds.indexOf(a.taskId);
        const idxB = routine.taskIds.indexOf(b.taskId);
        return idxA - idxB;
      });

      const uncompleted = sortedAssignments.filter(a => a.status !== 'completed');

      if (uncompleted.length > 0) {
        const nextAssignment = uncompleted[0];
        const remainingCount = uncompleted.length;
        const myTask = storeState.tasks.find(t => t.id === nextAssignment.taskId);
        const isDelayed = nextAssignment.expectedEndTimeMins && currentSimTime >= nextAssignment.expectedEndTimeMins;

        const delayedKey = `tarea_retrasada_${nextAssignment.id}`;
        const nextKey = `tarea_siguiente_${nextAssignment.id}`;

        if (isDelayed) {
          if (!dismissedTaskNotificationsRef.current.has(delayedKey) && (activePushNotification?.type !== 'tarea_retrasada' || !activePushNotification?.text.includes(myTask?.title || ''))) {
            setActivePushNotification({
              type: 'tarea_retrasada',
              text: `🚨 Retraso en rutina: desarrolla "${myTask?.title || 'Tarea'}". Quedan ${remainingCount} tareas.`,
              action: () => {
                dismissedTaskNotificationsRef.current.add(delayedKey);
                setActivePushNotification(null);
                setPhoneTab('tareas');
              },
              dismiss: () => {
                dismissedTaskNotificationsRef.current.add(delayedKey);
                setActivePushNotification(null);
              }
            });
          }
        } else {
          // Mostrar aviso amigable si tiene tareas de rutina pendientes por desarrollar
          if (!dismissedTaskNotificationsRef.current.has(nextKey) && activePushNotification?.type !== 'tarea_siguiente' && activePushNotification?.type !== 'tarea_retrasada') {
            setActivePushNotification({
              type: 'tarea_siguiente',
              text: `📋 Siguiente tarea de tu rutina: "${myTask?.title || 'Tarea'}". (${remainingCount} pendientes).`,
              action: () => {
                dismissedTaskNotificationsRef.current.add(nextKey);
                setActivePushNotification(null);
                setPhoneTab('tareas');
              },
              dismiss: () => {
                dismissedTaskNotificationsRef.current.add(nextKey);
                setActivePushNotification(null);
              }
            });
          }
        }
      } else {
        if (activePushNotification?.type === 'tarea_retrasada' || activePushNotification?.type === 'tarea_siguiente') {
          setActivePushNotification(null);
        }
      }
    } else {
      // Si no tiene rutinas, usar el comportamiento estándar de una tarea única en progreso
      const myAssignment = storeState.assignments.find(a => a.userId === currentUser.id && a.status === 'in_progress');
      if (myAssignment && myAssignment.expectedEndTimeMins && currentSimTime >= myAssignment.expectedEndTimeMins) {
        const notifKey = `tarea_retrasada_${myAssignment.id}`;
        if (!dismissedTaskNotificationsRef.current.has(notifKey) && activePushNotification?.type !== 'tarea_retrasada') {
          const myTask = storeState.tasks.find(t => t.id === myAssignment.taskId);
          setActivePushNotification({
            type: 'tarea_retrasada',
            text: `🚨 Estás retrasado en tu tarea: ${myTask?.title || 'Tarea Actual'}. ¡Apresúrate!`,
            action: () => {
              dismissedTaskNotificationsRef.current.add(notifKey);
              setActivePushNotification(null);
              setPhoneTab('tareas');
            },
            dismiss: () => {
              dismissedTaskNotificationsRef.current.add(notifKey);
              setActivePushNotification(null);
            }
          });
        }
      } else if (activePushNotification?.type === 'tarea_retrasada' && (!myAssignment || currentSimTime < (myAssignment.expectedEndTimeMins || 99999))) {
          setActivePushNotification(null);
      }
    }
  }, [currentSimTime, storeStatus, currentUser.id, activePushNotification, assignments]);

  // Auto-liberar tareas de la bolsa que expiraron su tiempo de reserva
  useEffect(() => {
    if (storeStatus !== 'open') return;
    const storeState = useTaskStore.getState();
    const reservedAssignments = storeState.assignments.filter(
      a => a.userId !== null && a.status === 'pending' && a.reservedAtMins !== undefined && a.reservedAtMins !== null
    );

    if (reservedAssignments.length === 0) return;

    let hasChanges = false;
    const updatedAssignments = storeState.assignments.map(a => {
      if (a.userId !== null && a.status === 'pending' && a.reservedAtMins !== undefined && a.reservedAtMins !== null) {
        const task = storeState.tasks.find(t => t.id === a.taskId);
        if (!task) return a;
        const limit = task.priority === 'bloqueante' ? 5 : 15;
        if (currentSimTime >= a.reservedAtMins + limit) {
          hasChanges = true;
          useAppStore.getState().addMatrixEvent(
            '⏳ Reserva Expirada',
            `La tarea de la Bolsa "${task.title}" superó el tiempo límite de inicio y se liberó automáticamente.`,
            'warning'
          );
          return { ...a, userId: null, ownerCleared: true, reservedAtMins: null };
        }
      }
      return a;
    });

    if (hasChanges) {
      useTaskStore.setState({ assignments: updatedAssignments });
      useTaskStore.getState().syncToBackend();
    }
  }, [currentSimTime, storeStatus]);

  // Push Notification: Advertencia 80% de tiempo (20% restante) para la tarea activa en progreso
  useEffect(() => {
    if (storeStatus !== 'open') return;
    const storeState = useTaskStore.getState();
    const myAssignment = storeState.assignments.find(a => a.userId === currentUser.id && a.status === 'in_progress');
    
    if (myAssignment) {
      const myTask = storeState.tasks.find(t => t.id === myAssignment.taskId);
      if (myTask) {
        const isDelayed = myAssignment.expectedEndTimeMins && currentSimTime >= myAssignment.expectedEndTimeMins;
        
        if (!isDelayed) {
          const elapsed = currentSimTime - (myAssignment.startedAtMins ?? currentSimTime) + (myAssignment.accumulatedMins || 0);
          const remaining = myTask.estimatedMins - elapsed;
          const threshold = Math.ceil(myTask.estimatedMins * 0.20);
          
          if (remaining <= threshold && remaining > 0 && !myAssignment.warned80Percent) {
            // Marcar como advertido localmente en store
            const updated = storeState.assignments.map(asg => 
              asg.id === myAssignment.id ? { ...asg, warned80Percent: true } : asg
            );
            useTaskStore.setState({ assignments: updated });
            
            // Alarmas
            playAlarm('alerta_tiempo');
            if (navigator.vibrate) {
              navigator.vibrate([100, 50, 100]);
            }
            
            setActivePushNotification({
              type: 'advertencia_tiempo',
              text: `⚠️ ¡Tiempo límite cerca! Tarea "${myTask.title}" al 80% de avance. Quedan ${remaining} min.`,
              action: () => {
                setActivePushNotification(null);
                setPhoneTab('tareas');
              }
            });
          }
        }
      }
    } else {
      if (activePushNotification?.type === 'advertencia_tiempo') {
        setActivePushNotification(null);
      }
    }
  }, [currentSimTime, storeStatus, currentUser.id, activePushNotification]);

  useEffect(() => {
    if (storeStatus !== 'closed') return;
    const shiftStartMins = parseTimeToMins((shiftConfigs[currentUser?.id]?.start || '09:00'));
    // const clockState = globalClockStates[currentUser.id];
    
    if (clockState === 'inactive') {
      const limitMins = shiftStartMins - (clockOpConfig.arrivalWindowMins ?? 30);
      if (currentSimTime >= limitMins && !playedAlarms.ya_llegue) {
        playAlarm('ya_llegue');
        setPlayedAlarms(prev => ({...prev, ya_llegue: true}));
      }
    }
    
    if (clockState === 'waiting_room') {
      if (currentSimTime >= shiftStartMins && !playedAlarms.tienda_cerrada) {
        playAlarm('tienda_cerrada');
        setPlayedAlarms(prev => ({...prev, tienda_cerrada: true}));
      }
    }

    // Dead Man's Switch (Fase 1.5)
    if (activeEncargadoId) {
      const activeEncargado = globalUsers.find(u => Number(u.id) === Number(activeEncargadoId));
      if (activeEncargado && !absentUsers[activeEncargado.id] && !lateUsers[activeEncargado.id]) {
        const encShiftStart = parseTimeToMins(shiftConfigs[activeEncargado.id]?.start || '09:00');
        if (currentSimTime >= encShiftStart + 10) {
           // GATILLO
           setAbsentUsers(prev => ({...prev, [activeEncargado.id]: true}));
           const log = { id: Date.now(), userId: activeEncargado.id, userName: activeEncargado.name, type: 'absent' as const, reason: 'SISTEMA: Failsafe Automático (Sin respuesta a los 10 mins)', time: formattedTime };
           setContingencyLogs(prev => [log, ...prev]);
           
           let nextEncargadoId = null;
           const hierarchy = globalUsers.filter(u => u.esAperturador).sort((a,b) => (a.jerarquiaLlaves ?? 0) - (b.jerarquiaLlaves ?? 0)).map(u => u.id);
           for(let id of hierarchy) {
             if (id !== activeEncargado.id && !absentUsers[id] && (shiftConfigs[id]?.restDay || '') !== currentDay) {
                nextEncargadoId = id;
                break;
             }
           }
           if (nextEncargadoId) {
             setActiveEncargadoId(nextEncargadoId);
             setAuditoryLogs(prev => [{
               id: Date.now(),
               targetId: activeEncargado.id,
               targetName: activeEncargado.name,
               type: 'abandono',
               time: formattedTime,
               details: `FAILSAFE ACTIVADO. Tienda no abierta a las ${formattedTime}. Llaves transferidas a ID ${nextEncargadoId}.`
             }, ...prev]);
           }
        }
      }
    }
  }, [currentSimTime, currentUser.id, storeStatus, globalClockStates, shiftConfigs, playedAlarms, currentSimTime, activeEncargadoId, absentUsers, lateUsers, currentDay]);

  // ----------------------------------------------------
  // Notificación de confirmación de trayecto (07:00 AM - 07:20 AM)
  // ----------------------------------------------------
  useEffect(() => {
    if (isSandboxMode) return;
    if (storeStatus !== 'closed') return;

    const myId = Number(currentUser?.id);
    const isOpeningManager = myId === Number(activeEncargadoId);

    // 1. Alerta al encargado principal a las 07:00 AM para confirmar que va en camino.
    if (isOpeningManager) {
      const isConfirmed = localStorage.getItem(`commute_confirmed_${currentDay}`) === 'true';
      if (currentSimTime >= 420 && currentSimTime < 440 && !isConfirmed) {
        if (activePushNotification?.type !== 'commute_confirm') {
          setActivePushNotification({
            type: 'commute_confirm',
            text: '⏰ Confirmación de Trayecto: ¿Vas en camino a la sucursal para la apertura?',
            action: () => {
              localStorage.setItem(`commute_confirmed_${currentDay}`, 'true');
              setActivePushNotification(null);
              showCustomAlert('🟢 Trayecto confirmado. ¡Conduce con cuidado!');
            }
          });
        }
      } else if ((currentSimTime >= 440 || isConfirmed) && activePushNotification?.type === 'commute_confirm') {
        setActivePushNotification(null);
      }
    }

    // 2. Si no se confirma para las 07:20 AM, se envía un aviso preventivo al suplente (Segundo Encargado).
    const isSuplente = currentUser?.role === 'Segundo Encargado';
    if (isSuplente) {
      const isConfirmedByManager = localStorage.getItem(`commute_confirmed_${currentDay}`) === 'true';
      if (currentSimTime >= 440 && currentSimTime < 460 && !isConfirmedByManager) {
        if (activePushNotification?.type !== 'commute_suplente_alert') {
          playAlarm('tienda_cerrada'); // Reproducir un tono preventivo
          setActivePushNotification({
            type: 'commute_suplente_alert',
            text: '⚠️ Alerta Preventiva: El Encargado Principal no ha confirmado su trayecto. Mantente alerta por posible cobertura de apertura.',
            action: () => {
              setActivePushNotification(null);
            }
          });
        }
      } else if (currentSimTime >= 460 && activePushNotification?.type === 'commute_suplente_alert') {
        setActivePushNotification(null);
      }
    }
  }, [currentSimTime, currentUser?.id, activeEncargadoId, storeStatus, currentDay, activePushNotification]);

  // Real-time alarm and reminder scheduler
  useEffect(() => {
    if (!currentUser?.id) return;
    
    const shiftStartStr = shiftConfigs[currentUser.id]?.start || '09:00';
    const shiftStartMins = parseTimeToMins(shiftStartStr);

    // 1. Pre-shift reminder alarm
    if (userClockPrefs.alarmsEnabled && clockState === 'inactive') {
      const minsToStart = shiftStartMins - currentSimTime;
      if (minsToStart === userClockPrefs.preShiftReminderMins && !preShiftAlarmPlayed) {
        playAlarm('alerta_tiempo');
        setPreShiftAlarmPlayed(true);
        showCustomAlert(`🔔 Alerta de Entrada: Tu turno de entrada inicia en ${userClockPrefs.preShiftReminderMins} minutos.`);
      }
    }

    // 2. Meal slot registration reminder (5 minutes after check-in if no reservation)
    const checkInTime = checkInTimes[currentUser.id];
    const mySlots = userReservedMealSlots[currentUser.id] || [];
    const hasMealReservation = mySlots.length > 0;
    const isPro = currentTier === 'pro' || currentTier === 'enterprise' || currentUser?.tenant_id === 1;

    if (isPro && userClockPrefs.alarmsEnabled && checkInTime !== undefined && !hasMealReservation && currentSimTime === checkInTime + 5 && !mealReminderAlarmPlayed) {
      playAlarm('alerta_tiempo');
      setMealReminderAlarmPlayed(true);
      showCustomAlert("🍔 Recuerda agendar o confirmar tu horario de almuerzo de hoy.");
    }

    // 2b. Free Plan simple lunch time alarm
    if (!isPro && currentUser?.lunch_time && userClockPrefs.alarmsEnabled) {
      const lunchMins = parseTimeToMins(currentUser.lunch_time);
      const alarmKey = `lunch_alarm_${currentDay}_${currentUser.id}`;
      const hasPlayedLunchAlarm = localStorage.getItem(alarmKey) === 'true';
      if (currentSimTime === lunchMins && !hasPlayedLunchAlarm) {
        localStorage.setItem(alarmKey, 'true');
        playAlarm('alerta_tiempo');
        showCustomAlert("🍔 ¡Hora de tu Almuerzo! Recuerda iniciar tu horario de comida en el Reloj Checador.");
      }
    }

    // 3. Ley Silla alarm (120 minutes after returning from meal)
    const mealEndTime = mealEndTimes[currentUser.id];
    if (userClockPrefs.alarmsEnabled && userClockPrefs.leySillaAlert && mealEndTime !== undefined && currentSimTime === mealEndTime + 120 && !leySillaAlarmPlayed) {
      playAlarm('alerta_tiempo');
      setLeySillaAlarmPlayed(true);
      showCustomAlert("🧘 Alerta Ley Silla: Recuerda tomar un descanso de 15 minutos de pie o sentado según la Ley Silla.");
    }

    // 4. Task expiry alerts
    if (userClockPrefs.alarmsEnabled) {
      const userAssignments = useTaskStore.getState().assignments;
      userAssignments.forEach((a: any) => {
        if (a.userId === currentUser.id && a.status === 'in_progress' && a.expectedEndTimeMins) {
          const minsRemaining = a.expectedEndTimeMins - currentSimTime;
          if (minsRemaining === userClockPrefs.taskExpiryWarningMins && !expiringTasksAlerted[a.id]) {
            playAlarm('alerta_tiempo');
            setExpiringTasksAlerted(prev => ({ ...prev, [a.id]: true }));
            showCustomAlert(`⚠️ Alerta de Tarea: Tu tarea asignada expira en ${userClockPrefs.taskExpiryWarningMins} minutos.`);
          }
        }
      });
    }

  }, [currentSimTime, currentUser?.id, clockState, shiftConfigs, userClockPrefs, preShiftAlarmPlayed, mealReminderAlarmPlayed, leySillaAlarmPlayed, expiringTasksAlerted, checkInTimes, userReservedMealSlots, mealEndTimes, currentTier, currentDay]);

  const prevAssignmentsRef = useRef<string[]>([]);
  useEffect(() => {
    if (!currentUser?.id) return;
    const userAssignments = assignments.filter((a: any) => a.userId === currentUser.id);
    const activeIds = userAssignments.map(a => a.id);
    
    // Check if there is any new assignment ID that wasn't in prevAssignmentsRef
    if (prevAssignmentsRef.current.length > 0) {
      const hasNew = activeIds.some(id => !prevAssignmentsRef.current.includes(id));
      if (hasNew && userClockPrefs.alarmsEnabled && userClockPrefs.newTaskAlert) {
        playAlarm('ya_llegue');
        showCustomAlert("📋 ¡Nueva Tarea Asignada! Tienes una nueva tarea en tu panel de actividades.");
      }
    }
    prevAssignmentsRef.current = activeIds;
  }, [assignments, currentUser?.id, userClockPrefs.alarmsEnabled, userClockPrefs.newTaskAlert]);

  const handleDayChange = (newDay: string) => {
    // Guardar log del día actual
    setWeeklyHistory((prev: any) => ({
      ...prev,
      [currentDay]: { arrivalTimes, storeOpenLog, storeStatus }
    }));
    setCurrentDay(newDay);
    
    // Resetear el simulador
    setStoreStatus('closed');
    const adminUser = globalUsers.find(u => u.system_role === 'admin' || u.role?.toLowerCase()?.includes('admin') || u.role?.toLowerCase()?.includes('gerente'));
    const defaultAdminId = adminUser ? adminUser.id : (globalUsers[0]?.id || 1);
    setActiveEncargadoId(defaultAdminId);
    setAmnestyActive(false);
    setGlobalClockStates(initialState);
    setArrivalTimes({});
    setEvalStars(0);
    setRequireEvaluation(MOCK_STORE.requireEvaluation);
    setPaseListaEmployees([]);
    setKioscoInput('');
    setSimTimeMinutes(0);
    setStoreOpenLog(null);
    setReservedMeals({});
    setHasReservedMeal({});
    setUserReservedMealSlots({});
    setAbsentUsers({});
    setLateUsers({});
    setContingencyLogs([]);
    setContingencyUsed({});
    setAuditoryLogs([]);
  };

  const resetSimulator = () => {
    setStoreStatus('closed');
    setOpeningStatus(null);
    setOpeningChecklistCompleted(false);
    setOpeningRollCallCompleted(false);
    setBuddyAlerts({});
    setActivePushNotification(null);
    const adminUser = globalUsers.find(u => u.system_role === 'admin' || u.role?.toLowerCase()?.includes('admin') || u.role?.toLowerCase()?.includes('gerente'));
    const defaultAdminId = adminUser ? adminUser.id : (globalUsers[0]?.id || 1);
    setActiveEncargadoId(defaultAdminId);
    setAmnestyActive(false);
    setGlobalClockStates(initialState);
    setArrivalTimes({});
    setEvalStars(0);
    setRequireEvaluation(MOCK_STORE.requireEvaluation);
    setPaseListaEmployees([]);
    setKioscoInput('');
    setSimTimeMinutes(0);
    setStoreOpenLog(null);
    setReservedMeals({});
    setHasReservedMeal({});
    setUserReservedMealSlots({});
    setAbsentUsers({});
    setLateUsers({});
    setContingencyLogs([]);
    setAuditoryLogs([]);
    setBreakStartTimes({});
    setBreakEndTimes({});
    setMealStartTimes({});
    setMealEndTimes({});
    setCheckOutTimes({});
    localStorage.removeItem('clock_break_start_times');
    localStorage.removeItem('clock_break_end_times');
    localStorage.removeItem('clock_meal_start_times');
    localStorage.removeItem('clock_meal_end_times');
    localStorage.removeItem('clock_checkout_times');
    localStorage.removeItem('store_opening_assignments');
    localStorage.removeItem('store_daily_opening_status');
    localStorage.removeItem('opening_checklist_completed');
    localStorage.removeItem('opening_roll_call_completed');
    setDailyHistory({});
    setCurrentDay('Domingo');
    setSelectedSummaryDay('Domingo');
    setActiveTimers({});
    setGpsCoordinates({ latitude: 19.4344, longitude: -99.1332 });
    setGpsStatus('success');
    setIsSimulatedOffline(false);
  };

  const handleContingency = async (type: 'late' | 'absent', etaTime?: string) => {
    if (!absenceReason.trim()) {
      showCustomAlert("Por favor, escribe el motivo de tu contingencia.");
      return;
    }
    
    // 1. Try sending to backend, but catch error so local simulation keeps working
    try {
      await axiosInstance.post('/sync/contingency', {
          user_id: currentUser.id,
          type: type,
          status: 'pending',
          justification_text: absenceReason,
          eta_time: etaTime || null
      });
    } catch (e: any) {
      console.log("Backend offline/simulated - proceeding locally with contingency:", e.message);
    }

    // 2. Perform local React state transitions
    if (type === 'absent') {
      setAbsentUsers(prev => ({ ...prev, [currentUser.id]: true }));
      cancelMealReservation(currentUser.id);
    } else {
      setLateUsers(prev => ({ ...prev, [currentUser.id]: true }));
    }

    const log = { 
      id: Date.now(), 
      userId: currentUser.id, 
      userName: currentUser.name, 
      type: type === 'absent' ? ('absent' as const) : ('late' as const), 
      reason: `REPORTE: ${absenceReason}${etaTime ? ` (ETA: ${etaTime})` : ''}`, 
      time: formattedTime 
    };
    setContingencyLogs(prev => [log, ...prev]);
    updateClockState(currentUser.id, 'contingency');

    const eventTitle = type === 'absent' ? '❌ Reporte de Falta' : '⏳ Reporte de Retraso';
    const eventDesc = type === 'absent'
      ? `${currentUser.name} (${currentUser.role}) reportó falta. Motivo: ${absenceReason}. Se liberó su comedor.`
      : `${currentUser.name} (${currentUser.role}) reportó retraso${etaTime ? ` (ETA: ${etaTime})` : ''}. Motivo: ${absenceReason}.`;
    useAppStore.getState().addMatrixEvent(
      eventTitle,
      eventDesc,
      type === 'absent' ? 'error' : 'warning',
      currentUser.id
    );

    // 3. Key cascading delegation
    if (Number(currentUser.id) === Number(activeEncargadoId)) {
       let nextEncargadoId = null;
       const hierarchy = globalUsers.filter(u => u.esAperturador).sort((a,b) => (a.jerarquiaLlaves ?? 0) - (b.jerarquiaLlaves ?? 0)).map(u => u.id);
       for(let id of hierarchy) {
         const isSelf = Number(id) === Number(currentUser.id);
         const isCandidateAbsent = absentUsers[id] || (isSelf && type === 'absent');
         const isCandidateRestDay = (shiftConfigs[id]?.restDay || '') === currentDay;
         
         if (!isSelf && !isCandidateAbsent && !isCandidateRestDay) {
            nextEncargadoId = id;
            break;
         }
       }
       if (nextEncargadoId) {
         try {
           await axiosInstance.post('/sync/clock', {
               user_id: currentUser.id,
               date: new Date().toLocaleDateString('sv-SE'),
               type: 'check_out',
               time: formattedTime,
               details: `{"evalStars":0,"delegatedKeysTo":${nextEncargadoId}}`
           });
         } catch (e: any) {
           console.log("Backend offline/simulated - key delegation saved locally:", e.message);
         }
         setActiveEncargadoId(nextEncargadoId);
         if (openingStatus) {
            const updatedOpening = {
              ...openingStatus,
              current_responsible_employee_id: nextEncargadoId,
              status: 'transferred',
              report_deadline_mins: globalSimTime + (openingSettings.absence_late_report_window_minutes || 5)
            };
            setOpeningStatus(updatedOpening);
            localStorage.setItem('store_daily_opening_status', JSON.stringify(updatedOpening));
         }
         showCustomAlert(`🚑 [${formattedTime}] Contingencia registrada. Al usar el botón de reporte, la responsabilidad de abrir la tienda se transfirió a: ${globalUsers.find(u => u.id === nextEncargadoId)?.name}.`);
       } else {
         showCustomAlert(`🚨 [${formattedTime}] Contingencia registrada. CRÍTICO: No hay más gerentes disponibles para abrir la tienda.`);
       }
    } else {
       showCustomAlert(`✅ [${formattedTime}] Contingencia registrada con éxito y notificada al gerente.`);
    }
    
    window.dispatchEvent(new Event('db_sync_updated'));
    setShowAbsenceModal(false);
    setAbsenceReason("");
  };

  const declareEmergency = () => {
    const supervisor = globalUsers.find(u => u.id !== currentUser.id && (u.role?.toLowerCase()?.includes('sup') || u.role?.toLowerCase()?.includes('gerente')));
    const backupId = supervisor ? supervisor.id : (globalUsers.find(u => u.id !== currentUser.id)?.id || currentUser.id);
    setActiveEncargadoId(backupId);
    setShowAbsenceModal(false);
    showCustomAlert(`🔴 [${formattedTime}] Emergencia registrada. Responsabilidad transferida al Segundo Encargado.`);
  };

  const handleAperturaForzosa = () => {
    const supervisor = globalUsers.find(u => u.id !== currentUser.id && (u.role?.toLowerCase()?.includes('sup') || u.role?.toLowerCase()?.includes('gerente')));
    const backupId = supervisor ? supervisor.id : (globalUsers.find(u => u.id !== currentUser.id)?.id || currentUser.id);
    setActiveEncargadoId(backupId);
    setShowForzosaModal(false);
  };

  const initPaseLista = (conAmnistia: boolean) => {
    const rollCallUnlocked = useAppStore.getState().isFeatureUnlocked('roll_call');
    if (!rollCallUnlocked) {
      showCustomAlert("⚠️ El Pase de Lista es una función PRO. Por favor, actualiza tu plan.");
      return;
    }
    if (conAmnistia) setAmnestyActive(true);
    
    const empleadosEnPuerta = globalUsers.filter(u => u.id !== currentUser.id && (globalClockStates[u.id] === 'waiting_room' || globalClockStates[u.id] === 'waiting')).map((u) => {
      const arrTime = globalArrivalTimes[u.id] || 0;
      const shiftStartMins = parseTimeToMins(shiftConfigs[u.id]?.start || '09:00');
      const toleranceEndMins = shiftStartMins + resolveTolerance(timeBankConfigs.maxLateMinsAllowed);
      const isOnTime = arrTime <= toleranceEndMins; 
      
      const arrH = Math.floor(arrTime / 60);
      const arrM = arrTime % 60;
      const arrTimeFormatted = `${arrH.toString().padStart(2, '0')}:${arrM.toString().padStart(2, '0')}`;

      return {
    isGlobalLoading: false,
        ...u,
        selected: true,
        onTime: isOnTime,
        statusLabel: isOnTime ? `A tiempo (${arrTimeFormatted})` : `Retardo (${arrTimeFormatted})`,
        toleranceEndMins
      };
    });
    
    setPaseListaEmployees(empleadosEnPuerta);
    setShowAmnestyModal(false);
    setShowPaseListaModal(true);
  };

  const handleOpenStore = async (conAmnistia: boolean = false) => {
    if (conAmnistia) setAmnestyActive(true);
    setStoreStatus('open');
    setStoreOpenSimTime(currentSimTime);
    setShowAmnestyModal(false);
    
    useAppStore.getState().addMatrixEvent(
       'Apertura desde el Celular', 
       `La sucursal fue abierta físicamente por el administrador/encargado (${currentUser?.name}) deslizando el switch desde su dispositivo.`, 
       'success', 
       currentUser?.id
    );
    
    // NOTA: Eliminamos el 'return' prematuro del modo Sandbox aquí
    // ya que syncToDB() se encarga de detener la petición a la BD pero SI cambia los estados.
    
    try {
      const typeStr = currentSimTime < 480 ? 'forzosa' : 'open';
      const todayStr = new Date().toLocaleDateString('sv-SE');
      
      await axiosInstance.post('/sync/store_log', {
          user_id: currentUser.id,
          date: todayStr,
          type: typeStr,
          time: formattedTime.split(' ')[0],
          notes: 'Apertura de tienda'
      });

      // Auto-check in the manager
      await syncToDB('check_in', false, 0, 'Apertura de tienda');

      // BUG FIX: Sincronizar estado diario de apertura en localStorage para congruencia con modo premium
      const savedStatusStr = localStorage.getItem('store_daily_opening_status');
      if (savedStatusStr) {
        try {
          const savedStatus = JSON.parse(savedStatusStr);
          savedStatus.status = 'opened';
          savedStatus.opened_at = formattedTime.split(' ')[0];
          localStorage.setItem('store_daily_opening_status', JSON.stringify(savedStatus));
        } catch (e) {}
      }

      window.dispatchEvent(new Event('db_sync_updated'));

      let msg = `🟢 [${formattedTime}] Tienda Abierta. Notificando a la matriz.`;
      showCustomAlert(msg);
    } catch(e) {
      console.error(e);
      showCustomAlert("Error abriendo tienda.");
    }
  };

  const handleSubmitPaseLista = async () => {
    setShowPaseListaModal(false);
    let registrados = 0;
    const todayStr = new Date().toLocaleDateString('sv-SE');
    
    try {
      for (const emp of paseListaEmployees) {
          if (emp.selectedStatus === 'presente') {
              await axiosInstance.post('/clock/punch', {
                  user_id: emp.id,
                  type: 'check_in',
                  details: 'Pase de lista diferido'
              });
              registrados++;
          }
      }

      window.dispatchEvent(new Event('db_sync_updated'));

      // NUEVO (§22 / estado #8 Logica Dial.md): si el switch require_pase_lista_rating está activo,
      // además del check_in se envían las calificaciones (Presentación/Imagen/Energía) de los presentes.
      // Endpoint separado del check_in (POST /clock/pase-lista/ratings), tal como quedó en el contrato.
      // employee_id apunta a users.id (mismo identificador que usa /clock/punch), no a employees.id.
      const requireRating = systemSettings?.clockOpConfig?.require_pase_lista_rating === true;
      if (requireRating && !isSandboxMode) {
        const ratings = paseListaEmployees
          .filter((emp: any) => emp.selectedStatus === 'presente' || emp.selected)
          .filter((emp: any) => emp.presentacion || emp.imagen || emp.energia)
          .map((emp: any) => ({
            employee_id: emp.id,
            presentacion: emp.presentacion || 0,
            imagen: emp.imagen || 0,
            energia: emp.energia || 0,
          }));
        if (ratings.length > 0) {
          try {
            await axiosInstance.post('/clock/pase-lista/ratings', { date: todayStr, ratings });
          } catch (e) {
            // No bloquea el pase de lista: el check_in ya se hizo; la calificación es complementaria.
            console.warn('No se pudieron guardar las calificaciones de pase de lista (§22):', e);
          }
        }
      }

      setPaseListaDone(true); // <--- CORRECCION VITAL: Marcar que ya se hizo
      showCustomAlert(`✅ Pase de Lista completado. Se dio acceso a ${registrados} empleados.`);
    } catch(e) {
      console.error(e);
      showCustomAlert("Error en Pase de Lista.");
    }
  };

  const toggleSelectAll = () => {
    const allSelected = paseListaEmployees.every(e => e.selected);
    setPaseListaEmployees(paseListaEmployees.map(e => ({...e, selected: !allSelected})));
  };

  const handleKioscoAdd = () => {
    if(!kioscoInput) return;
    
    // Asumimos horario default para kiosco.
    // Fase 1 (2026-07-26): antes la tolerancia estaba escrita a mano como `+ 10` aquí,
    // distinta a la de los otros puntos del archivo. Ahora sale de la única fuente.
    const shiftStartMins = parseTimeToMins('08:00');
    const toleranceEndMins = shiftStartMins + resolveTolerance(timeBankConfigs.maxLateMinsAllowed);

    const newEmp = { 
      id: Date.now(), 
      name: kioscoInput + " [Sin Dispositivo]", 
      role: "Agregado Manual", 
      selected: true,
      onTime: currentSimTime <= toleranceEndMins,
      statusLabel: `Añadido ${formattedTime}`,
      toleranceEndMins
    };
    setPaseListaEmployees([...paseListaEmployees, newEmp]);
    setKioscoInput('');
  };

  const syncToDB = async (type: string, isLate = false, lateMinutes = 0, details = '') => {
      const isOnline = navigator.onLine && !isSimulatedOffline;
      if (!isOnline) {
          const currentGps = gpsStatus === 'success' ? gpsCoordinates : null;

          // BUG FIX: antes se guardaba `time: formattedTime` (ej. "8:32 am", 12h sin segundos),
          // formato que el backend no puede interpretar como hora real del fichaje. Ahora se usa
          // H:i:s real (getSimTimeStr en modo simulado, getRealHms en modo real), consistente con
          // lo que ClockService::processPunch() espera al sincronizar vía /clock/punch-batch.
          const punchTime = isSimulatedMode ? getSimTimeStr(currentSimTime) : getRealHms();
          const clientTimestamp = new Date().toISOString();

          // Firma HMAC del fichaje offline (docs/BACKEND_INTERFACES.md §2). Si el secreto no está
          // cacheado (warmOfflineSecret no alcanzó a correr antes de perder conexión), el fichaje se
          // guarda igual SIN firma — se prefiere no perder el registro del empleado a bloquear el
          // fichaje por un problema de red al pedir el secreto. Ese ítem quedará marcado rejected por
          // el backend al sincronizar (invalid_signature) y visible para revisión manual, en vez de
          // perderse silenciosamente.
          let offlineStamp = '';
          try {
            offlineStamp = await computeOfflineStamp(currentUser?.id, type, punchTime, clientTimestamp);
          } catch (e) {
            console.error('No se pudo firmar el fichaje offline (se guardará sin firma):', e);
          }

          await offlineDb.savePunch({
              userId: currentUser?.id,
              type,
              time: punchTime,
              clientTimestamp,
              offlineStamp,
              gps: currentGps,
              details
          });
          const remaining = await offlineDb.getPunches();
          setSyncQueue(remaining);
          showCustomAlert(`📡 Fichaje guardado localmente en cola offline IndexedDB (Sin Internet).`);
          
          let newState = 'active';
          if (type === 'waiting') newState = 'waiting';
          else if (type === 'check_in') newState = 'active';
          else if (type === 'meal_start') newState = 'meal';
          else if (type === 'meal_end') newState = 'active';
          else if (type === 'break_start') newState = 'short_break';
          else if (type === 'break_end') newState = 'active';
          else if (type === 'silla_start') newState = 'short_break';
          else if (type === 'silla_end') newState = 'active';
          else if (type === 'temp_exit_start') newState = 'temp_exit';
          else if (type === 'temp_exit_end') newState = 'active';
          else if (type === 'check_out') newState = 'finished'; // BUG FIX: 'inactive' causaba que el dial mostrara 'Registrar Entrada' post checkout
          else if (type === 'contingency') newState = 'contingency';
          else if (type === 'absent') newState = 'absent';
          
          if (currentUser?.id) {
             updateClockState(currentUser.id, newState);
          }
          return { offline: true };
      }

      if (useAppStore.getState().isSandboxMode) {
          useAppStore.getState().addMatrixEvent(
             `[SANDBOX] Fichaje Simulado: ${type}`,
             `El empleado ${currentUser.name} completó la acción a las ${formattedTime}`,
             'info',
             currentUser.id
          );
          
          let newState = 'active';
          if (type === 'waiting') newState = 'waiting';
          else if (type === 'check_in') newState = 'active';
          else if (type === 'meal_start') newState = 'meal';
          else if (type === 'meal_end') newState = 'active';
          else if (type === 'break_start') newState = 'short_break';
          else if (type === 'break_end') newState = 'active';
          else if (type === 'silla_start') newState = 'short_break';
          else if (type === 'silla_end') newState = 'active';
          else if (type === 'temp_exit_start') newState = 'temp_exit';
          else if (type === 'temp_exit_end') newState = 'active';
          else if (type === 'check_out') newState = 'finished'; // BUG FIX: sandbox mode también debe usar 'finished'
          else if (type === 'contingency') newState = 'contingency';
          else if (type === 'absent') newState = 'absent';

          updateClockState(currentUser.id, newState);
          return {};
      }
      try {
         // BUG FIX: antes se mandaba `formattedTime` (12h, ej. "8:32 am", sin segundos). El backend
         // (ClockService::processPunch) solo usa este campo cuando el tenant está en time_mode
         // 'simulated'; en ese caso hace Carbon::createFromFormat('Y-m-d H:i:s', ...) esperando
         // H:i:s de 24h — "8:32 am" rompía ese parseo. En modo real el backend ignora este campo
         // y usa now(), así que enviar H:i:s aquí es inofensivo y correcto en ambos casos.
         const punchTimeHms = isSimulatedMode ? getSimTimeStr(currentSimTime) : getRealHms();
         const response = await axiosInstance.post('/clock/punch', {
             user_id: currentUser.id,
             type,
             time: punchTimeHms,
             details: {
                 note: details,
                 gps: gpsStatus === 'success' ? gpsCoordinates : null,
                 is_simulator: isSimulator
             }
         });
         const data = response.data;
         if (data && data.success) {
             let newState = 'active';
             if (type === 'waiting') newState = 'waiting';
             else if (type === 'check_in') newState = 'active';
             else if (type === 'meal_start') newState = 'meal';
             else if (type === 'meal_end') newState = 'active';
             else if (type === 'break_start') newState = 'short_break';
             else if (type === 'break_end') newState = 'active';
             else if (type === 'silla_start') newState = 'short_break';
             else if (type === 'silla_end') newState = 'active';
             else if (type === 'temp_exit_start') newState = 'temp_exit';
             else if (type === 'temp_exit_end') newState = 'active';
             else if (type === 'check_out') newState = 'finished'; // BUG FIX: producción también debe usar 'finished'
             else if (type === 'contingency') newState = 'contingency';
             else if (type === 'absent') newState = 'absent';

             updateClockState(currentUser.id, newState);
             window.dispatchEvent(new Event('db_sync_updated'));
         }
         return data;
      } catch (e: any) {
         // BUG FIX: antes este catch tragaba el error en silencio (solo console.error). Si el backend
         // rechaza el punch — por ejemplo con 400 "Completa el checklist de cierre antes de registrar
         // salida." (docs/BACKEND_INTERFACES.md §6) — el usuario no veía absolutamente nada al presionar
         // el dial. Ahora se muestra el mensaje real del backend cuando existe.
         console.error(e);
         showCustomAlert(e.response?.data?.message || '⚠️ No se pudo sincronizar el fichaje con el servidor.');
      }
  };
  const handleAction = async () => {
    const btnProps = getButtonProps();
    if (btnProps.isQrUnlockRequired) {
      setIsLateEntryValidation(true);
      setSupervisorPin('');
      setSupervisorQrToken('');
      setPendingTasksBlocker(true);
      return;
    }
    if (btnProps.isIncidenceReport) {
      setShowAbsenceModal(true);
      return;
    }
    if (btnProps.isMealReservationAlert) {
      setShowMealReservationModal(true);
      return;
    }
    if (btnProps.isEmergencyOpen) {
      setShowEmergencyOpenModal(true);
      return;
    }
    if (btnProps.isCallSuplenteMain) {
      // Estado #5 real de la matriz mostrado como texto principal del dial (ver getButtonProps):
      // reutiliza la misma acción que el botón secundario "Marcar a Suplente".
      handleCallSuplente();
      return;
    }

    // GPS Geofencing Check
    if (!isGpsValidationBypassed) {
      if (gpsStatus === 'error') {
        showCustomAlert("⚠️ Error de GPS: No se pudo determinar tu ubicación actual.");
        return;
      }
      if (!isWithinPerimeter) {
        showCustomAlert(`⚠️ Fichaje Denegado: Estás fuera del perímetro permitido (Distancia: ${Math.round(gpsDistance)}m).`);
        return;
      }
    }

    const shiftStartMins = parseTimeToMins((shiftConfigs[currentUser?.id]?.start || '09:00'));
    const toleranceEndMins = shiftStartMins + resolveTolerance(timeBankConfigs.maxLateMinsAllowed);
  


    const isOpeningPremium = useAppStore.getState().isFeatureUnlocked('store_opening');

    if (isOpeningPremium && storeStatus === 'closed') {
      if (btnProps.isOpeningActive) {
        await handleOpenStorePremium();
        return;
      }
      if (btnProps.isReportStoreClosed) {
        await handleReportStoreStillClosedPremium();
        return;
      }
      if (btnProps.disabled) {
        return;
      }
    }
    if (!isGpsValidationBypassed && gpsStatus !== 'success' && !clockOpConfig.allowManualCheckIn) {
      showCustomAlert("⚠️ Error de GPS: No se ha podido validar tu ubicación actual.");
      return;
    }

    if (!isGpsValidationBypassed && !isWithinPerimeter && !clockOpConfig.allowManualCheckIn) {
      if (isOpeningPremium && storeStatus === 'closed' && currentUser.id === activeEncargadoId) {
        if (globalPermissions.includes('manage_contingencies')) {
          setShowAmnestyModal(true);
          return;
        } else {
          handleOpenStore(false);
          return;
        }
      }
      
      if (clockState === 'inactive') {
        await syncToDB('waiting');
        showCustomAlert("En perímetro. Esperando apertura de tienda...");
      }
    } else {
      const actionText = btnProps.text;
      
      if (actionText === 'Registrar Entrada' || actionText === 'Registrar Entrada Manual' || actionText === 'Fichar Entrada') {
        const res = await syncToDB('check_in');
        if (res && res.entry && res.entry.late_type) {
            showCustomAlert(`🟢 Fichaje registrado. Se detectó: ${res.entry.late_type} (${res.entry.penalty_applied}% descuento)`);
            if (['Encargado Titular', 'Segundo Encargado', 'Supervisor'].includes(currentUser.role)) {
                setShowJustificanteModal(true);
            }
        } else {
            showCustomAlert(`🟢 Fichaje registrado a tiempo.`);
        }
      } else if (actionText === '📍 Ya llegué') {
        // BUG FIX: este botón (estado #7 de la matriz, VENTANA 2 de proximidad) nunca tenía una rama
        // en handleAction() — btnProps.isProximityCheck se seteaba pero nada lo consumía, así que
        // presionarlo no hacía nada. La acción correcta es registrar 'waiting', que es el mismo tipo
        // de entry que ClockService::processPunch() (Backend/app/Services/ClockService.php líneas 210-219)
        // busca para conceder hasAmnesty automáticamente en el check_in posterior.
        await syncToDB('waiting');
        showCustomAlert('📍 Llegada registrada. Amnistía de puntualidad asegurada.');
      } else if (actionText === 'Abrir Tienda') {
        handleOpenStore(false);
      } else if (actionText === 'Iniciar Comida' || actionText === 'Iniciar Horario de Comida' || actionText === 'Tomar Comida') {
        // BUG FIX: getButtonProps retorna 'Iniciar Comida', unificamos ambos strings
        // Si el usuario no ha apartado su lugar de comida, abre el modal de reservación primero
        if (!hasReservedMeal[currentUser?.id] && useAppStore.getState().isFeatureUnlocked('meal_reservation')) {
          setShowMealReservationModal(true);
          return;
        }
        // NUEVO (§23): si se exige evidencia fotográfica, se abre la cámara PRIMERO; el fichaje real
        // (meal_start) ocurre después, ya con la foto subida (ver submitMealPhotoAndPunch).
        if (isMealPhotoRequired && !isSandboxMode) {
          setMealPhotoType('meal_start');
          setShowMealPhotoModal(true);
          return;
        }
        await syncToDB('meal_start');
        showCustomAlert('🍔 Horario de comida iniciado.');
      } else if (actionText === 'Terminar Comida' || actionText === 'Regresar de Comida') {
        // BUG FIX: getButtonProps retorna 'Terminar Comida' (clockState === meal)
        if (isMealPhotoRequired && !isSandboxMode) {
          setMealPhotoType('meal_end');
          setShowMealPhotoModal(true);
          return;
        }
        const res = await syncToDB('meal_end');
        if (!res?.offline) {
          showCustomAlert('🏃 Has regresado de comer.');
        }
      } else if (actionText === 'Descanso' || actionText === 'Descanso Ley Silla' || actionText === 'Tomar Silla') {
        // BUG FIX: getButtonProps retorna 'Descanso', no 'Descanso Ley Silla'
        await handleBreakStart();
      } else if (actionText === 'Terminar Descanso' || actionText === 'Regresar de Descanso') {
        // BUG FIX: getButtonProps retorna 'Terminar Descanso' (clockState === short_break)
        await handleBreakEnd();
      } else if (actionText === 'Entrega de Turno' || actionText === 'Entregar Turno') {
        handleHandoverStart();
      } else if (actionText === 'Registrar Salida' || actionText === 'Fichar Salida') {
        handleClockOutRequest();
      } else if (actionText === 'Registrar Reingreso' || actionText === 'Fichar Reingreso') {
        await endTempExit();
      }
    }
  };

  const handleBreakEnd = async () => {
    // §25: en modo con aprobación, el fin de descanso se ficha como silla_end (tipo propio para que
    // nómina lo distinga de un break ordinario). En modo normal sigue siendo break_end.
    const endType = (isSillaApprovalRequired && !isSandboxMode) ? 'silla_end' : 'break_end';
    const res = await syncToDB(endType);
    if (!res?.offline) {
      // Marcar la tarea sentada como completada
      useTaskStore.setState(state => {
        const updated = state.assignments.map(a => {
          if (a.userId === currentUser.id && a.status === 'in_progress' && a.id.startsWith('seat_')) {
            return {
              ...a,
              status: 'completed' as const,
              completedAtMins: currentSimTime
            };
          }
          return a;
        });
        return { assignments: updated };
      });
      useTaskStore.getState().syncToBackend();
      showCustomAlert('🏃 Has regresado de tu descanso. Tarea de Ley Silla completada.');
    }
  };

  const handleBreakStart = async () => {
      const isPro = currentTier === 'pro' || currentTier === 'enterprise' || currentUser?.tenant_id === 1;

      // §25: modo con aprobación de supervisor + aforo.
      if (isSillaApprovalRequired && !isSandboxMode) {
        if (sillaRequestStage === 'none') {
          // Paso 1: solicitar la silla. NO inicia el descanso — espera aprobación del supervisor.
          try {
            await axiosInstance.post('/clock/silla/request', {});
            setSillaRequestStage('requested');
            showCustomAlert('🪑 Solicitud de silla enviada. Pide a tu supervisor que la apruebe y luego presiona de nuevo para iniciar.');
          } catch (e: any) {
            showCustomAlert(e?.response?.data?.message || 'No se pudo enviar la solicitud de silla.');
          }
          return;
        }
        // Paso 2 (ya solicitada): intentar fichar silla_start. El backend valida aprobación + aforo y
        // rechaza con mensaje claro si aún no aprueban o si no hay cupo (queda en cola).
        try {
          const res = await syncToDB('silla_start');
          if (!res?.offline) {
            setSillaRequestStage('none');
            showCustomAlert('🧘 Descanso de Ley Silla iniciado (aprobado).');
          }
        } catch (e: any) {
          showCustomAlert(e?.response?.data?.message || 'Aún no se aprueba tu silla o no hay cupo disponible. Intenta en un momento.');
        }
        return;
      }

      if (isPro) {
        setShowBreakSeatModal(true);
      } else {
        const res = await syncToDB('break_start');
        if (!res?.offline) {
          showCustomAlert('🧘 Has iniciado tu descanso (Ley Silla - Básico).');
        }
      }
  };

  // §25: el supervisor aprueba/rechaza una solicitud de silla. request_id se obtiene de la
  // notificación push / del panel de pendientes. method: 'pin' valida contra el PIN del supervisor;
  // 'remote' registra la aprobación sin credencial extra (la sesión ya autoriza).
  const approveSillaRequest = async (requestId: number, method: 'pin' | 'qr' | 'remote' = 'remote', supervisorPin?: string) => {
    try {
      await axiosInstance.post(`/clock/silla/${requestId}/approve`, { method, supervisor_pin: supervisorPin });
      showCustomAlert('✅ Silla aprobada. El colaborador ya puede sentarse cuando haya cupo.');
      return true;
    } catch (e: any) {
      showCustomAlert(e?.response?.data?.message || 'No se pudo aprobar la solicitud de silla.');
      return false;
    }
  };

  const rejectSillaRequest = async (requestId: number) => {
    try {
      await axiosInstance.post(`/clock/silla/${requestId}/reject`, {});
      showCustomAlert('Solicitud de silla rechazada.');
      return true;
    } catch (e: any) {
      showCustomAlert(e?.response?.data?.message || 'No se pudo rechazar la solicitud.');
      return false;
    }
  };

  // §25: consulta el aforo actual de sillas (cupo/activas/cola). Útil para mostrar "sin cupo" en la UI.
  const refreshSillaStatus = async () => {
    if (isSandboxMode) return;
    try {
      const todayStr = new Date().toLocaleDateString('sv-SE');
      const res = await axiosInstance.get('/clock/silla/status', { params: { date: todayStr } });
      setSillaStatus(res.data || null);
    } catch (e) {
      // Silencioso: es informativo.
    }
  };

  const startBreakWithSittingTask = async (taskId: number) => {
    const res = await syncToDB('break_start');
    if (res?.offline) return;
    
    const assignmentId = `seat_${currentUser.id}_${Date.now()}`;
    const newTaskAssignment = {
      id: assignmentId,
      taskId,
      userId: currentUser.id,
      status: 'in_progress' as const,
      startedAtMins: currentSimTime,
      completedAtMins: null,
      accumulatedMins: 0
    };
    
    useTaskStore.setState(state => ({
      assignments: [...state.assignments, newTaskAssignment]
    }));
    useTaskStore.getState().syncToBackend();
    
    setShowBreakSeatModal(false);
    showCustomAlert('🧘 Descanso (Ley Silla) iniciado y tarea sentado asignada.');
  };

  // NUEVO (estados #7 y #11 de docs/Logica Dial.md): el empleado común sin llaves avisa al encargado
  // que ya está en puerta esperando la apertura. DEGRADADO por ahora: registra el aviso en la Matrix
  // y confirma in-app. El push real al celular del encargado (aunque no tenga la app abierta) depende
  // de backend §26 (POST /clock/door-notice) — cuando exista, esta función además hará el POST.
  const handleSendDoorNotice = async () => {
    const responsibleId = openingStatus?.current_responsible_employee_id ?? 1;
    const responsibleUser = globalUsers.find((u: any) => Number(u.id) === Number(responsibleId));
    const responsibleName = responsibleUser?.name?.split(' ')[0] || 'Encargado';
    const message = `${currentUser?.name || 'Un colaborador'} está esperando en puerta.`;

    useAppStore.getState().addMatrixEvent(
      '💬 Aviso de Presencia en Puerta',
      `${message} Se notificó a ${responsibleName} que la tienda sigue cerrada.`,
      'info',
      currentUser?.id
    );

    if (isSandboxMode) {
      showCustomAlert(`💬 Aviso enviado a ${responsibleName} (Sandbox): estás esperando en puerta.`);
      return;
    }

    try {
      await axiosInstance.post('/clock/door-notice', {
        date: new Date().toLocaleDateString('sv-SE'),
        responsible_employee_id: responsibleId,
        message,
      });
    } catch (e) {
      // Backend §26 puede no estar implementado todavía — el aviso in-app/Matrix ya se mostró, así
      // que no se trata como error visible para el usuario; solo se deja traza en consola.
      console.warn('door-notice endpoint no disponible aún (backend §26 pendiente):', e);
    }
    showCustomAlert(`💬 Aviso enviado a ${responsibleName}: estás esperando en puerta.`);
  };

  // NUEVO (§23): recibe el data URL de la foto capturada, la sube a POST /clock/meal-photo y, solo si
  // la subida tiene éxito, ejecuta el fichaje real (meal_start / meal_end). Si la subida falla, NO
  // avanza el estado — la evidencia es obligatoria cuando el switch está activo (a diferencia del
  // door-notice, que es informativo). El modal se cierra y muestra el resultado.
  const submitMealPhoto = async (dataUrl: string) => {
    setMealPhotoSubmitting(true);
    const todayStr = new Date().toLocaleDateString('sv-SE');
    try {
      await axiosInstance.post('/clock/meal-photo', {
        type: mealPhotoType,
        date: todayStr,
        image: dataUrl,
        client_timestamp: new Date().toISOString(),
      });
    } catch (e: any) {
      setMealPhotoSubmitting(false);
      showCustomAlert(e?.response?.data?.message || '❌ No se pudo subir la foto del comedor. Intenta de nuevo.');
      return; // No avanza el fichaje: la evidencia es obligatoria.
    }

    const res = await syncToDB(mealPhotoType);
    setMealPhotoSubmitting(false);
    setShowMealPhotoModal(false);
    if (!res?.offline) {
      showCustomAlert(mealPhotoType === 'meal_start' ? '🍔 Comida iniciada (evidencia registrada).' : '🏃 Regreso de comida registrado (evidencia registrada).');
    }
  };

  const startTempExit = async (reason: string) => {
    const res = await syncToDB('temp_exit_start', false, 0, reason);
    if (!res?.offline) {
      setShowTempExitModal(false);
      showCustomAlert(`🚪 Pase de Salida Temporal registrado: "${reason}".`);
    }
  };

  const endTempExit = async () => {
    const res = await syncToDB('temp_exit_end');
    if (!res?.offline) {
      showCustomAlert('🚶 Reingreso de salida temporal registrado.');
    }
  };

  const triggerPanic = (emergencyType: string, description: string) => {
    setIsPanicActive(true);
    useAppStore.getState().addMatrixEvent(
      `🚨 EMERGENCIA CRÍTICA: ${emergencyType}`,
      `Se ha reportado un incidente de tipo [${emergencyType.toUpperCase()}]: ${description}. La sucursal ha entrado en modo bloqueo de pánico.`,
      'error',
      currentUser.id
    );
    setShowPanicModal(false);
    showCustomAlert(`🚨 Alerta de pánico activada: ${emergencyType}. Se ha notificado a administración.`);
  };

  const resolvePanic = () => {
    setIsPanicActive(false);
    useAppStore.getState().addMatrixEvent(
      `💚 EMERGENCIA RESUELTA`,
      `El modo pánico de la sucursal ha sido desactivado y la operación vuelve a la normalidad.`,
      'success',
      currentUser.id
    );
    showCustomAlert('💚 Modo pánico desactivado. Retornando a operaciones normales.');
  };

  const handleHandoverStart = () => {
    setShowKeyDelegationModal(true);
  };

  const completeHandover = async (delegatedToUserId: number, cashAmt: number) => {
    if (useAppStore.getState().isSandboxMode) {
      useAppStore.getState().addMatrixEvent(
        '🔑 Entrega de Turno Completada',
        `El encargado ${currentUser.name} entregó el turno a ${globalUsers.find(u => u.id === delegatedToUserId)?.name} con un arqueo de $${cashAmt}.`,
        'success',
        currentUser.id
      );
    } else {
      try {
        await axiosInstance.post('/key-transfers', {
          recipient_id: delegatedToUserId,
          notes: `Entrega de turno. Arqueo de caja: $${cashAmt}`
        });
      } catch (e) {
        console.error(e);
      }
    }
    setIsHandoverCompleted(true);
    setShowKeyDelegationModal(false);
    showCustomAlert('🔑 Entrega de turno y arqueo de caja registrados con éxito. Ahora puedes registrar tu salida.');
  };

  const handleClockOutRequest = () => {
    const isPro = currentTier === 'pro' || currentTier === 'enterprise' || currentUser?.tenant_id === 1;

    // NUEVO (estado #22 — Checklist de Cierre Seguro): gate previo al check_out, espejo del gate
    // que ya existe para el checklist de apertura. IMPORTANTE: se lee openingSettings.require_closing_checklist
    // (docs/BACKEND_INTERFACES.md §6) con comparación estricta === true, NO con "!== false" como otros
    // flags de este archivo — porque esta columna todavía no existe en el backend (⏳ pendiente al momento
    // de este cambio). Si se usara "!== false", un valor undefined activaría el bloqueo para TODOS los
    // usuarios en cuanto se despliegue este frontend, antes de que el backend tenga la columna lista.
    // Con === true, el gate queda inactivo (no bloquea a nadie) hasta que el backend confirme el campo.
    const requiresClosingChecklist = isOpeningPremium && openingSettings?.require_closing_checklist === true;
    if (requiresClosingChecklist && !closingChecklistCompleted) {
      setShowClosingChecklistModal(true);
      return;
    }

    const hasPendingTasks = useTaskStore.getState().assignments.some(
      (a: any) => Number(a.userId) === Number(currentUser.id) && (a.status === 'pending' || a.status === 'in_progress')
    );
    
    const currentShiftEndStr = shiftConfigs[currentUser?.id]?.end || '17:00';
    const currentShiftEndMins = parseTimeToMins(currentShiftEndStr);
    const isEarly = currentSimTime < currentShiftEndMins;

    if (isPro) {
      if (isEarly || hasPendingTasks) {
        setIsEarlyDepartureValidation(isEarly);
        setSupervisorPin('');
        setSupervisorQrToken('');
        setPendingTasksBlocker(true);
        return;
      }
    } else {
      if (hasPendingTasks) {
        setSupervisorPin('');
        setPendingTasksBlocker(true);
        return;
      }
    }

    if (requireEvaluation) {
      setShowEvalModal(true);
      return;
    }
    processFinalClockOut();
  };

  // NUEVO (estado #22): consume POST /store-opening/closing-checklist (docs/BACKEND_INTERFACES.md §6).
  // Tras confirmar los 3 checks, marca closingChecklistCompleted y re-invoca handleClockOutRequest()
  // para continuar exactamente el mismo flujo que hubiera corrido si el gate no hubiera interceptado.
  const submitClosingChecklist = async (checks: { lights_off: boolean; safe_secured: boolean; alarm_activated: boolean }) => {
    if (!checks.lights_off || !checks.safe_secured || !checks.alarm_activated) {
      showCustomAlert('⚠️ Debes confirmar los 3 puntos del checklist antes de continuar.');
      return;
    }
    setClosingChecklistSubmitting(true);
    try {
      if (isSandboxMode) {
        setClosingChecklistCompleted(true);
        localStorage.setItem('closing_checklist_completed', 'true');
        setShowClosingChecklistModal(false);
        showCustomAlert('📋 Checklist de cierre completado (Sandbox).');
        handleClockOutRequest();
        return;
      }

      await axiosInstance.post('/store-opening/closing-checklist', {
        user_id: currentUser.id,
        checks
      });

      // Cierre FORMAL de la sucursal (decisión P1-P3, 2026-08-03): registra quién/cuándo y
      // reparte las rutinas trigger='cierre'. BEST-EFFORT a propósito: si falla (otro
      // encargado ya declaró el cierre, o este usuario no es el responsable), la salida del
      // colaborador NO se frena — el cierre registra, nunca bloquea.
      try {
        const cierre = await axiosInstance.post('/store-opening/close');
        if (cierre.data?.success) {
          showCustomAlert('🔒 Sucursal cerrada: quedó registrado y el checklist de cierre fue repartido.');
        }
      } catch { /* no responsable o ya cerrada: la salida sigue su curso */ }

      setClosingChecklistCompleted(true);
      localStorage.setItem('closing_checklist_completed', 'true');
      setShowClosingChecklistModal(false);
      showCustomAlert('📋 Checklist de cierre completado.');
      handleClockOutRequest();
    } catch (e: any) {
      showCustomAlert(e.response?.data?.message || 'Error al guardar el checklist de cierre.');
    } finally {
      setClosingChecklistSubmitting(false);
    }
  };

  // Botón suelto "Cerrar sucursal" (P1-P3): declara el cierre SIN estar saliendo — registra
  // quién/cuándo y reparte el checklist de cierre. No bloquea nada; el backend rechaza si no
  // eres el responsable/mando o si ya estaba cerrada, y aquí solo se informa.
  const [cierreDeclarado, setCierreDeclarado] = useState(false);
  const declararCierreSucursal = async () => {
    try {
      const res = await axiosInstance.post('/store-opening/close');
      if (res.data?.success) {
        setCierreDeclarado(true);
        showCustomAlert('🔒 Sucursal cerrada: quedó registrado y el checklist de cierre fue repartido.');
      }
    } catch (e: any) {
      showCustomAlert(e.response?.data?.message || 'No se pudo declarar el cierre.');
    }
  };

  const authorizeClockOutWithPendingTasks = async () => {
    const isPro = currentTier === 'pro' || currentTier === 'enterprise' || currentUser?.tenant_id === 1;
    
    if (isPro) {
      if (!supervisorQrToken || !supervisorQrToken.trim()) {
        showCustomAlert("⚠️ Token de Código QR de Supervisor inválido o incompleto.");
        return;
      }
      
      try {
        const res = await axiosInstance.post('/sync/supervisor/validate-qr', {
          token: supervisorQrToken
        });
        if (!res.data || !res.data.success) {
          showCustomAlert(`⚠️ Error de Validación: ${res.data.message || 'Token QR inválido o expirado.'}`);
          return;
        }
      } catch (e: any) {
        showCustomAlert(`⚠️ Error de Validación: ${e.response?.data?.message || 'Error al conectar con el servidor para validar el QR.'}`);
        return;
      }
    } else {
      if (!supervisorPin || supervisorPin.trim().length < 4) {
        showCustomAlert("⚠️ PIN de Supervisor inválido o incompleto.");
        return;
      }
    }

    if (isOvertimeValidation) {
      const typeStr = isSimulatedHoliday ? 'holiday_unlocked' : 'overtime_unlocked';
      const detailStr = isSimulatedHoliday ? 'Labor en Día Feriado (LFT Art. 75) habilitado por supervisor' : 'Horas Extras desbloqueadas por supervisor';
      const eventTitle = isSimulatedHoliday ? '📅 Labor en Feriado Habilitada' : '⏰ Horas Extras Habilitadas';
      const eventDesc = isSimulatedHoliday 
        ? `Se autorizó a ${currentUser.name} a laborar en Día Feriado (Natalicio de Benito Juárez) mediante ${isPro ? 'QR Dinámico' : 'PIN de Supervisor'} (Pago Triple LFT aplicable).`
        : `Se autorizó a ${currentUser.name} a laborar en su día de descanso mediante ${isPro ? 'QR Dinámico' : 'PIN de Supervisor'}.`;

      try {
        await axiosInstance.post('/sync/audit_log', {
          user_id: currentUser.id,
          type: typeStr,
          timestamp_str: getSimTimeStr(currentSimTime),
          reason: `Autorizado por supervisor mediante ${isPro ? 'QR Dinámico' : 'PIN'}`,
          details: detailStr
        });
      } catch {}

      setIsOvertimeUnlocked(prev => ({ ...prev, [currentUser.id]: true }));

      useAppStore.getState().addMatrixEvent(
        eventTitle,
        eventDesc,
        'success',
        currentUser.id
      );

      setPendingTasksBlocker(false);
      setSupervisorQrToken('');
      setSupervisorPin('');
      setIsOvertimeValidation(false);
      
      showCustomAlert(isSimulatedHoliday ? '📅 Labor en Día Feriado autorizada. Ya puedes registrar tu entrada.' : '⏰ Horas Extras autorizadas por supervisor. Ya puedes registrar tu entrada.');
      return;
    }

    if (isLateEntryValidation) {
      try {
        await axiosInstance.post('/sync/audit_log', {
          user_id: currentUser.id,
          type: 'late_entry_unlocked',
          timestamp_str: getSimTimeStr(currentSimTime),
          reason: `Autorizado por supervisor mediante ${isPro ? 'QR Dinámico' : 'PIN'}`,
          details: `Entrada tardía autorizada por supervisor`
        });
      } catch {}

      setPendingTasksBlocker(false);
      setSupervisorQrToken('');
      setSupervisorPin('');
      setIsLateEntryValidation(false);

      await syncToDB('check_in');

      // Auditoría reloj checador (2026-07-22), Hallazgo 1: el contador de retardos ya no vive
      // en localStorage — se lee del backend (TimeEntry.is_late acumulado del periodo vigente,
      // §12 de docs/BACKEND_INTERFACES.md). Se refresca aquí, DESPUÉS del check_in, para que el
      // conteo incluya el retardo recién registrado.
      let newRetardos = 0;
      let willBeBlocked = false;
      if (!isSandboxMode) {
        await fetchPunctualityStatus();
        const status = useAppStore.getState().punctualityStatus;
        newRetardos = status?.lates_count ?? 0;
        willBeBlocked = !!status?.blocked;
      } else {
        newRetardos = Number(localStorage.getItem('user_retardos_' + currentUser.id) || 0) + 1;
        localStorage.setItem('user_retardos_' + currentUser.id, String(newRetardos));
        willBeBlocked = newRetardos >= 3;
      }

      useAppStore.getState().addMatrixEvent(
        '🔑 Entrada Tardía Autorizada',
        `Se autorizó la entrada tardía de ${currentUser.name} tras vencer la tolerancia mediante ${isPro ? 'QR Dinámico' : 'PIN de Supervisor'}. Retardos acumulados este periodo: ${newRetardos}.`,
        'warning',
        currentUser.id
      );

      if (willBeBlocked) {
        showCustomAlert(`⚠️ Entrada autorizada con penalización. Has acumulado ${newRetardos} retardos. Tu checador queda BLOQUEADO hasta completar el curso obligatorio de Puntualidad en la Academia.`);
      } else {
        showCustomAlert(`✅ Entrada autorizada con penalización. Has acumulado ${newRetardos} retardos este periodo.`);
      }
      return;
    }

    // 1. Omitir todas las tareas pendientes del usuario actual
    const storeState = useTaskStore.getState();
    const pendingCount = storeState.assignments.filter(
      (a: any) => Number(a.userId) === Number(currentUser.id) && (a.status === 'pending' || a.status === 'in_progress')
    ).length;

    const updatedAssignments = storeState.assignments.map((a: any) => {
      if (Number(a.userId) === Number(currentUser.id) && (a.status === 'pending' || a.status === 'in_progress')) {
        return { ...a, status: 'omitted', validationFeedback: `Omitida al salir por supervisor mediante ${isPro ? 'QR Dinámico' : 'PIN'}.` };
      }
      return a;
    });
    useTaskStore.setState({ assignments: updatedAssignments });
    storeState.syncToBackend();

    // 2. Sincronizar log en el backend
    try {
      await axiosInstance.post('/clock/uncompleted-tasks-log', {
        user_id: currentUser.id,
        supervisor_pin: isPro ? 'QR_VALIDATED' : supervisorPin,
        pending_count: pendingCount
      });
    } catch {}

    // 3. Matrix Event Log
    useAppStore.getState().addMatrixEvent(
      '⚠️ Cierre con Pendientes',
      `${currentUser.name} finalizó jornada con ${pendingCount} tareas pendientes, autorizado por supervisor mediante ${isPro ? 'QR Dinámico' : 'PIN'}. Se aplicó penalización en métricas.`,
      'warning'
    );

    // 4. Resetear el bloqueador y continuar
    setPendingTasksBlocker(false);
    setSupervisorQrToken('');
    setSupervisorPin('');
    
    const wasEarly = isEarlyDepartureValidation;
    setIsEarlyDepartureValidation(false);

    if (wasEarly) {
      processFinalClockOut(null, `Salida Anticipada Autorizada por Supervisor: ${earlyDepartureReason}`);
    } else {
      if (requireEvaluation) {
        setShowEvalModal(true);
      } else {
        processFinalClockOut();
      }
    }
  };

  const handleEarlyDepartureClick = () => {
    setShowEarlyDepartureModal(true);
  };

  const handleOvertimeClick = () => {
    setIsOvertimeValidation(true);
    setSupervisorPin('');
    setSupervisorQrToken('');
    setPendingTasksBlocker(true);
  };

  const submitEarlyDeparture = async () => {
    setShowEarlyDepartureModal(false);
    const isPro = currentTier === 'pro' || currentTier === 'enterprise' || currentUser?.tenant_id === 1;
    
    if (isPro) {
      setIsEarlyDepartureValidation(true);
      setSupervisorPin('');
      setSupervisorQrToken('');
      setPendingTasksBlocker(true);
    } else {
      await processFinalClockOut(null, `Salida Anticipada por causa: ${earlyDepartureReason}`);
    }
  };

  const submitEvaluation = () => {
    setShowEvalModal(false);
    setRequireEvaluation(false); 
    showCustomAlert(`⭐ Evaluación enviada.`);
    processFinalClockOut();
  };

  const processFinalClockOut = async (delegatedTo: number | null = null, note = '') => {
    // FASE 1: Delegación del Día Previo
    const nextDay = DIAS_SEMANA[(DIAS_SEMANA.indexOf(currentDay) + 1) % 7];
    const userRestDay = shiftConfigs[currentUser?.id]?.restDay;
    
    // Si el usuario actual es el encargado activo, y mañana es su descanso...
    if (currentUser.id === activeEncargadoId && userRestDay === nextDay && !delegatedTo) {
      setShowKeyDelegationModal(true);
      return; // Bloquea la salida hasta que delegue
    }

    const detailsObj = {
       evalStars: requireEvaluation ? evalStars : null,
       delegatedKeysTo: delegatedTo,
       note: note
    };

    const res = await syncToDB('check_out', false, 0, JSON.stringify(detailsObj));
    if (!res?.offline) {
      showCustomAlert(`🔴 Salida registrada a las ${formattedTime}.${delegatedTo ? ' 🔑 Llaves delegadas con éxito.' : ''} ${note ? ' (' + note + ')' : ''}`);
    }
  };

  // NOTA (refactor Jul 2026): handleKeyDelegation ahora vive en hooks/useKeyholderDelegation.ts
  // (llama a processFinalClockOut vía el proxy definido más arriba).

  const submitReport = async () => {
    if (!reportForm.targetId || !reportForm.type || !reportForm.details) {
      showCustomAlert("Por favor llena todos los campos del reporte.");
      return;
    }
    
    if (useAppStore.getState().isSandboxMode) {
       useAppStore.getState().addMatrixEvent(
          `[SANDBOX] Reporte Simulado`,
          `Reporte contra ID ${reportForm.targetId} guardado en memoria.`,
          'info',
          currentUser.id
       );
       setReportForm({ targetId: '', type: '', details: '' });
       showCustomAlert("✅ Tu reporte anónimo ha sido enviado (Sandbox).");
       return;
    }
    
    try {
        const targetName = globalUsers.find(u => u.id === parseInt(reportForm.targetId))?.name || 'Desconocido';
        await axiosInstance.post('/sync/audit_log', {
               user_id: currentUser.id,
               type: reportForm.type,
               timestamp_str: formattedTime,
               reason: reportForm.details,
               details: `Denuncia anónima hacia: ${targetName}`
        });
        
        window.dispatchEvent(new Event('db_sync_updated'));
        
        setShowReportModal(false);
        setReportForm({ targetId: '', type: '', details: '' });
        showCustomAlert("✅ Tu reporte anónimo ha sido enviado a la administración con éxito.");
    } catch(e) {
        console.error(e);
        showCustomAlert("Error al enviar reporte.");
    }
  };

  const getButtonProps = () => {
    const hasCheckedIn = checkInTimes[currentUser?.id] !== undefined;
    // Auditoría reloj checador (2026-07-22), Hallazgo 1: en producción (no sandbox) el bloqueo
    // real viene del backend (GET /me/punctuality-status, cacheado en memoria vía Zustand) — ya
    // no es evadible borrando localStorage. En sandbox/Matrix no hay ese respaldo real, así que
    // se conserva el contador local solo para ese modo de prueba.
    const hasPunctualityBlock = !isSandboxMode
      ? !!punctualityStatus?.blocked
      : Number(localStorage.getItem('user_retardos_' + currentUser?.id) || 0) >= 3;

    if (!hasCheckedIn && clockState === 'inactive' && hasPunctualityBlock) {
      return {
        text: '🔒 Fichaje Bloqueado',
        bg: 'bg-slate-800 text-slate-400 border border-slate-700 cursor-not-allowed text-xs font-black shadow-none',
        icon: '🔒',
        iconKey: 'blocked',
        disabled: true,
        subtext: 'Acumulaste 3 retardos. Completa el curso de Puntualidad en la Academia.',
        requiredCourseId: !isSandboxMode ? punctualityStatus?.required_course_id ?? null : null
      };
    }

    if (isSimulatedHoliday && !hasCheckedIn && clockState === 'inactive' && !isOvertimeUnlocked[currentUser?.id]) {
      return {
        // Texto alineado a docs/Logica Dial.md (estado #2, "Texto Principal") — solo texto.
        text: 'Día Feriado',
        bg: 'bg-indigo-50 border border-indigo-200 text-indigo-700 cursor-not-allowed font-extrabold shadow-sm',
        icon: '📅',
        iconKey: 'holiday',
        disabled: true,
        subtext: 'Natalicio de Benito Juárez. Descanso de Ley.'
      };
    }

    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay && !isOvertimeUnlocked[currentUser?.id];
    if (isRestDay) return { text: 'Día Descanso', bg: 'bg-slate-300 text-slate-500 cursor-not-allowed', icon: '🌴', iconKey: 'restday', disabled: true, subtext: 'Día libre programado' };

    const isPro = currentTier === 'pro' || currentTier === 'enterprise' || currentUser?.tenant_id === 1;
    const isOpeningPremium = useAppStore.getState().isFeatureUnlocked('store_opening');
    const features = clockOpConfig.enabledDialerFeatures || {};

    let responsibleId = 1;
    if (openingStatus) {
      responsibleId = openingStatus.current_responsible_employee_id;
    } else {
      try {
        const savedAss = localStorage.getItem('store_opening_assignments');
        const ass = savedAss ? JSON.parse(savedAss) : [];
        const firstActive = ass
          .filter((a: any) => a.is_active)
          .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];
        if (firstActive) {
          // §29: preferir resolved_user_id (users.id, resuelto por backend) sobre employee_id crudo.
          responsibleId = firstActive.resolved_user_id ?? firstActive.employee_id;
        }
      } catch {}
    }
    const responsibleUser = globalUsers.find((u: any) => u.id === responsibleId) || { name: 'Encargado' };

    const shiftStartStr = shiftConfigs[currentUser?.id]?.start || '08:30';
    const shiftStartMins = parseTimeToMins(shiftStartStr);

    const isLate = currentSimTime > (shiftStartMins + resolveTolerance(timeBankConfigs.maxLateMinsAllowed));

    const formatTimeMins = (mins: number) => {
      const h = Math.floor(mins / 60);
      const m = mins % 60;
      const ampm = h >= 12 ? 'pm' : 'am';
      const displayH = h > 12 ? h - 12 : (h === 0 ? 12 : h);
      return `${displayH}:${m.toString().padStart(2, '0')} ${ampm}`;
    };

    // ----------------------------------------------------
    // VENTANA 1: Reportar Incidencia Anticipada (07:00 AM a Deadline)
    // ----------------------------------------------------
    if (!hasCheckedIn && clockState === 'inactive' && currentSimTime >= 420 && currentSimTime < shiftStartMins) {
      if (isPro) {
        const isResponsibleForOpening = Number(currentUser?.id) === Number(responsibleId);
        
        if (isResponsibleForOpening) {
          const travelTime = clockOpConfig.suplente_travel_time_mins || 60;
          const managerDeadlineMins = shiftStartMins - travelTime;
          
          if (currentSimTime < managerDeadlineMins && features.allow_manager_incidences !== false) {
            return {
              text: '⚠️ Reportar Falta',
              bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)] animate-pulse',
              icon: '⚠️',
              iconKey: 'incidence_report',
              isIncidenceReport: true,
              isOpeningManager: true,
              subtext: `🗝️ Límite de encargado: ${formatTimeMins(managerDeadlineMins)}`
            };
          }
        } else {
          const employeeDeadlineMins = shiftStartMins - 30;
          if (currentSimTime < employeeDeadlineMins && features.allow_employee_incidences !== false) {
            return {
              text: '⚠️ Reportar Falta',
              bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)]',
              icon: '⚠️',
              iconKey: 'incidence_report',
              isIncidenceReport: true,
              subtext: `Límite para avisar: ${formatTimeMins(employeeDeadlineMins)}`
            };
          }
        }
      }
    }

    // ----------------------------------------------------
    // VENTANA 2: Registro de Cercanía ("Ya estoy aquí") (8:15 AM - 8:30 AM)
    // ----------------------------------------------------
    if (!hasCheckedIn && clockState === 'inactive' && currentSimTime >= shiftStartMins - 15 && currentSimTime < shiftStartMins) {
      if (isPro && features.enable_proximity_check !== false) {
        const isNear = isWithinPerimeter || isGpsValidationBypassed;
        if (isNear) {
          return {
            // BUG FIX: unificado con el texto exacto de la matriz maestra (docs/funcionamiento_del_dial.md, estado #7)
            // para que coincida con la comparación de actionText en handleAction().
            text: '📍 Ya llegué',
            bg: 'bg-emerald-600 hover:bg-emerald-700 text-white font-bold shadow-[0_0_20px_rgba(16,185,129,0.3)] animate-pulse',
            icon: '📍',
            iconKey: 'arrived',
            isProximityCheck: true,
            subtext: 'Registrar llegada anticipada para asegurar amnistía.'
          };
        } else if (isApproachingStore()) {
          // NUEVO: estado #6 de la matriz — "En Camino a Sucursal". Antes cualquier ubicación fuera
          // del geofence caía en el mismo placeholder deshabilitado "Cerca de Sucursal", sin distinguir
          // si el empleado se está acercando o simplemente está lejos y quieto. Igual que la fila 6
          // de docs/funcionamiento_del_dial.md, mantiene accesible "Reportar Incidencia" (isIncidenceReport)
          // por si ocurre un percance en el trayecto.
          // iconKey 'in_transit' (en vez de dejar que isIncidenceReport decida el ícono): este estado
          // sí debe verse como MapPin (trayecto), no como el AlertTriangle genérico de "reportar incidencia",
          // aunque ambos comparten el flag isIncidenceReport para mantener accesible esa acción secundaria.
          return {
            text: 'En Camino',
            bg: 'bg-amber-500 hover:bg-amber-600 text-white font-bold shadow-[0_0_20px_rgba(245,158,11,0.25)]',
            icon: '📍',
            iconKey: 'in_transit',
            isIncidenceReport: true,
            subtext: `Reportar incidencia si ocurre un percance (${Math.round(gpsDistance)}m restantes)`
          };
        } else {
          return {
            text: '📍 Cerca de Sucursal',
            bg: 'bg-slate-200 text-slate-400 cursor-not-allowed',
            icon: '🔒',
            iconKey: 'gps_locked',
            disabled: true,
            subtext: `Fuera de geocerca (${Math.round(gpsDistance)}m)`
          };
        }
      }
    }

    // Límite de retardo ordinario vencido.
    //
    // H6 (prueba en vivo 2026-07-29): NO bloquear a quien ya tiene la entrada tardía
    // AUTORIZADA. El backend ya lo deja fichar (ClockService consulta
    // `late_authorization_requests` con status `approved`), pero el dial no conocía ese
    // estado y seguía mostrando el candado: el colaborador autorizado se quedaba sin poder
    // registrar su entrada aunque el servidor sí se lo permitía.
    //
    // H7 (mismo día): este bloqueo se evaluaba ANTES de la rama de tienda cerrada, así que
    // el encargado de apertura que llegaba fuera de tolerancia nunca alcanzaba el botón de
    // abrir la sucursal → nadie abría, y con la tienda cerrada NADIE del equipo podía fichar
    // (deadlock: el primer día de una empresa, o cualquier día que el encargado llegue tarde,
    // el Reloj quedaba inoperable hasta intervenir por backend). Ahora, si la tienda está
    // cerrada y este usuario es quien puede abrirla, se deja pasar a la rama de apertura —
    // que es la acción que destraba a todo el equipo. Su retardo se sigue registrando
    // server-side al fichar; lo que se evita es el candado que no ofrece ninguna salida.
    // La decisión vive en `logic/accessBlock.ts` (función pura, con tests): estaba enterrada
    // en esta cadena de ifs y no había forma de verificarla sin abrir la app a la hora exacta.
    if (shouldBlockForLateTolerance({
      hasCheckedIn,
      isLate,
      clockState,
      storeStatus,
      currentUserId: currentUser?.id,
      responsibleId,
      esAperturador: currentUser?.esAperturador === true,
      lateAuthorizedUserIds,
      // H10: dato autoritativo del backend, por si el estado del motor se recalculó mal.
      tieneCheckInEnBackend: arrivalTimes?.[currentUser?.id] !== undefined,
    })) {
      return {
        text: '🔒 Acceso Bloqueado',
        bg: 'bg-slate-700 text-slate-300 hover:bg-slate-800 text-white font-extrabold shadow-[0_0_20px_rgba(100,116,139,0.3)] animate-pulse',
        icon: '🔒',
        iconKey: 'access_blocked',
        isQrUnlockRequired: true,
        subtext: 'Tolerancia vencida. Requiere desbloqueo QR de supervisor.'
      };
    }

    // BUG FIX: Evaluar storeStatus === 'closed' ANTES de la verificación general de !isWithinPerimeter
    // De lo contrario, un empleado fuera del perímetro ve 'Reportar Incidencia' cuando la tienda está cerrada,
    // en lugar de 'Esperando Apertura' o 'Notificar Tienda Cerrada'.
    if (storeStatus === 'closed') {
      const isOpeningManager = Number(currentUser?.id) === Number(responsibleId);

      // NUEVO (estado #5 real de la matriz — "Llamar a Suplente de Llaves" como texto PRINCIPAL del
      // dial, no solo el botón secundario "Marcar a Suplente"): cuando el encargado responsable ya
      // reportó falta/retardo, handleReportAbsencePremium/handleReportLatePremium mueven
      // openingStatus.status a 'transferred' (el equivalente real de clockState `handover_pending` que
      // describe el documento — ese valor nunca llegó a existir como clockState propio). Mientras dura
      // ese traspaso, cualquier otro titular/suplente de llaves disponible ve esto en vez del genérico
      // "Esperando Apertura", con la misma acción que el botón secundario (handleCallSuplente).
      const isHandoverInProgress = isOpeningPremium && openingStatus?.status === 'transferred';
      if (!isOpeningManager && isHandoverInProgress && isUserActiveKeyholder(currentUser?.id)) {
        return {
          text: 'Llamar Suplente',
          bg: 'bg-violet-600 hover:bg-violet-700 text-white font-bold shadow-[0_0_20px_rgba(124,58,237,0.3)] animate-pulse',
          icon: '📞',
          iconKey: 'call_suplente',
          isCallSuplenteMain: true,
          subtext: 'Pasar estafeta de apertura a suplente'
        };
      }

      if (!isOpeningManager) {
        if (!isPro) {
          return {
            text: '⏳ Esperando Apertura',
            bg: 'bg-slate-200 text-slate-400 cursor-not-allowed',
            icon: '⏳',
            iconKey: 'waiting_opening',
            disabled: true,
            subtext: `Apertura por: ${responsibleUser?.name ? responsibleUser.name.split(' ')[0] : 'Encargado'}`
          };
        } else {
          if (currentSimTime >= shiftStartMins && currentSimTime <= shiftStartMins + 20 && features.allow_store_closed_report !== false) {
            const hasReported = localStorage.getItem(`reported_closed_${currentDay}_${currentUser?.id}`) === 'true';
            if (hasReported) {
              return {
                text: '⏳ Esperando Apertura',
                bg: 'bg-slate-300 text-slate-500 cursor-not-allowed',
                icon: '⏳',
                iconKey: 'waiting_opening',
                disabled: true,
                subtext: 'Alerta de tienda cerrada ya enviada al administrador.'
              };
            }
            return {
              text: '🚨 Reportar Cerrado',
              bg: 'bg-amber-500 hover:bg-amber-600 text-white font-extrabold shadow-[0_0_20px_rgba(249,115,22,0.3)] animate-pulse',
              icon: '🚨',
              iconKey: 'report_store_closed',
              isReportStoreClosed: true,
              subtext: `Encargado: ${responsibleUser?.name ? responsibleUser.name.split(' ')[0] : 'Encargado'}`
            };
          }
          return {
            text: '⏳ Esperando Apertura',
            bg: 'bg-slate-200 text-slate-400 cursor-not-allowed',
            icon: '⏳',
            iconKey: 'waiting_opening',
            disabled: true,
            subtext: `Apertura por: ${responsibleUser?.name ? responsibleUser.name.split(' ')[0] : 'Encargado'}`
          };
        }
      }
    }

    // NUEVO (estado #9 de la matriz — "Apertura de Emergencia"): cuando la cadena de encargados/suplentes
    // se agotó (openingStatus.status === 'failed', ver syncApertura más arriba), cualquier titular o
    // suplente de llaves presente en el perímetro puede iniciar la apertura de emergencia con
    // co-validación de 2 testigos, en vez de quedarse bloqueado esperando indefinidamente.
    // Consume POST /clock/emergency-open (docs/BACKEND_INTERFACES.md §3, ya implementado por backend).
    if (isOpeningPremium && storeStatus === 'closed' && openingStatus?.status === 'failed') {
      // BUG FIX: la condición original solo miraba currentUser.portadorLlaves, pero el backend
      // (StoreOpeningService::emergencyOpenWithWitnesses) exige que el solicitante esté en
      // store_opening_assignments con has_keys=true e is_active=true — una fuente de datos distinta.
      // Con la condición vieja, alguien con portadorLlaves configurado pero sin asignación activa
      // vería el botón y luego el backend lo rechazaría con "no cuenta con llaves de sucursal activas",
      // o al revés: alguien con asignación activa pero sin portadorLlaves nunca vería el botón.
      // Ahora se valida contra la misma lista que usa getNextSuplenteUser().
      let hasActiveKeyAssignment = false;
      try {
        const savedAss = localStorage.getItem('store_opening_assignments');
        const ass = savedAss ? JSON.parse(savedAss) : [];
        hasActiveKeyAssignment = ass.some(
          (a: any) => Number(a.resolved_user_id ?? a.employee_id) === Number(currentUser?.id) && a.is_active && a.has_keys
        );
      } catch {}

      const isKeyholderPresent = hasActiveKeyAssignment && (isWithinPerimeter || isGpsValidationBypassed);
      if (isKeyholderPresent) {
        return {
          text: 'Apertura Emergencia',
          bg: 'bg-rose-600 hover:bg-rose-700 text-white font-black shadow-[0_0_25px_rgba(225,29,72,0.35)] animate-pulse',
          icon: '⚠️',
          iconKey: 'emergency_open',
          isEmergencyOpen: true,
          subtext: 'Requiere co-validación de 2 testigos presenciales'
        };
      }
    }

    if (isOpeningPremium && storeStatus === 'closed') {
      if (Number(currentUser.id) === Number(responsibleId)) {
        return {
          text: 'Abrir Tienda',
          bg: 'bg-violet-600 hover:bg-violet-700 text-white font-black shadow-[0_0_25px_rgba(139,92,246,0.35)] animate-pulse',
          icon: '🗝️',
          iconKey: 'open_store',
          isOpeningActive: true,
          subtext: 'Horario oficial de apertura. Suma bono.'
        };
      }
    }

    if (Number(currentUser.id) === Number(activeEncargadoId) && storeStatus === 'closed') {
      return { text: 'Abrir Tienda', bg: 'bg-indigo-600 hover:bg-indigo-700', icon: '🗝️', iconKey: 'open_store' };
    }

    if (!isWithinPerimeter && (clockState === 'inactive' || clockState === 'waiting_room')) {
      const isResponsibleForOpening = isOpeningPremium && storeStatus === 'closed' && Number(currentUser?.id) === Number(responsibleId);

      if (isResponsibleForOpening) {
        return {
          text: 'Reportar Incidencia',
          bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)]',
          icon: '⚠️',
          iconKey: 'incidence_report',
          isIncidenceReport: true,
          isResponsibleOutside: true,
          subtext: '🗝️ Eres el responsable de apertura de hoy. Dirígete a la sucursal para activar el botón.'
        };
      }

      return {
        text: 'Reportar Incidencia',
        bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)]',
        icon: '⚠️',
        iconKey: 'incidence_report',
        isIncidenceReport: true
      };
    }

    // BUG FIX: Si ya hubo check_out hoy (checkOutTimes tiene registro), mostrar 'Jornada Finalizada'
    // en lugar de 'Registrar Entrada'. Esto previene dobles fichajes accidentales.
    if (clockState === 'inactive' && checkOutTimes[currentUser?.id] !== undefined) {
      return { text: 'Fin Jornada', bg: 'bg-slate-200 text-slate-400 cursor-not-allowed', icon: '🏁', iconKey: 'finished', disabled: true, subtext: 'Turno concluido hoy.' };
    }
    if (clockState === 'inactive' || clockState === 'waiting_room') {
      return { text: 'Fichar Entrada', bg: 'bg-slate-800 hover:bg-slate-900', icon: '🟢', iconKey: 'entrada', subtext: 'Fichaje ordinario de entrada' };
    }

    if (clockState === 'active') {
      const hasTakenMeal = mealStartTimes[currentUser.id] !== undefined;
      if (!hasTakenMeal) {
        // BUG FIX (mySlots ReferenceError): declarado aquí, fuera del bloque isPro/featureFlags,
        // porque se usa más abajo (línea ~3349) independientemente de si esas condiciones se cumplen.
        // Antes esto reventaba con ReferenceError para cualquier usuario no-PRO o sin featureFlags.comidas,
        // causando pantalla blanca al llegar al estado 'Iniciar Comida'.
        const mySlots = userReservedMealSlots[currentUser.id] || [];
        const mealReservationUnlocked = useAppStore.getState().isFeatureUnlocked('meal_reservation');
        if (isPro && featureFlags.comidas && mealReservationUnlocked && features.enable_meal_slots !== false) {
          if (mySlots.length > 0) {
             const [sh, sm] = mySlots[0].split(' ')[0].split(':');
             const isPm = mySlots[0].includes('PM');
             let hour = parseInt(sh);
             if (isPm && hour !== 12) hour += 12;
             if (!isPm && hour === 12) hour = 0;
             const firstSlotMins = hour * 60 + parseInt(sm);
             
             if (currentSimTime < firstSlotMins - 5) {
                return { text: 'Tomar Comida', bg: 'bg-slate-200 text-slate-400 cursor-not-allowed opacity-60', icon: '🍔', disabled: true, subtext: `Reserva programada: ${mySlots[0]}` };
             }
          } else {
             return {
                // Alineado a docs/Logica Dial.md estado #16b ("Apartar Turno") — antes era una
                // frase de 4 palabras ("Reserva tu horario primero"), fuera del límite de 2-3.
                text: 'Apartar Turno',
                bg: 'bg-amber-600/20 text-amber-500 border border-amber-500/30 hover:bg-amber-600/30 font-bold shadow-md cursor-pointer animate-pulse',
                icon: '🍔',
                iconKey: 'meal_prompt',
                isMealReservationAlert: true,
                subtext: 'Haz clic para seleccionar tu slot en el comedor.'
             };
          }
        }
        // BUG FIX: Evitar que Iniciar Comida esté disponible inmediatamente a los 5 minutos de entrar.
        // Debe haber pasado al menos 90 minutos desde el check-in o estar dentro del rango de comida.
        const userCheckInTimeMins = checkInTimes[currentUser?.id];
        const minMealTimeMins = userCheckInTimeMins ? userCheckInTimeMins + 90 : shiftStartMins + 120;
        if (currentSimTime < minMealTimeMins && (!mySlots || mySlots.length === 0)) {
          return {
            text: 'Tomar Comida',
            bg: 'bg-slate-200 text-slate-400 cursor-not-allowed opacity-60',
            icon: '🍔',
            iconKey: 'meal_start',
            disabled: true,
            subtext: `Disponible a partir de las ${formatTimeMins(minMealTimeMins)}`
          };
        }

        return { text: 'Tomar Comida', bg: 'bg-amber-500 hover:bg-amber-600 text-amber-950 font-bold shadow-[0_0_20px_rgba(245,158,11,0.25)]', icon: '🍔', iconKey: 'meal_start', subtext: 'Haz clic para iniciar tu comida' };
      }

      const hasReturnedFromMeal = mealEndTimes[currentUser.id] !== undefined;
      const hasTakenBreak = breakStartTimes[currentUser.id] !== undefined;
      if (isPro && hasReturnedFromMeal && !hasTakenBreak && features.enable_ley_silla !== false) {
        return {
          text: 'Tomar Silla',
          bg: 'bg-violet-600 hover:bg-violet-700 text-white font-extrabold shadow-[0_0_20px_rgba(147,51,234,0.3)] animate-pulse',
          icon: '🧘',
          iconKey: 'break_start',
          subtext: 'Descanso Ley Silla (15 min)'
        };
      }

      const isManager = ['Encargado Titular', 'Segundo Encargado', 'Supervisor', 'Gerente'].includes(currentUser.role);
      // BUG FIX: Entrega de turno solo debe aparecer en ventana de cierre (15 min antes de salida)
      // para no bloquear indefinidamente a encargados PRO durante todo el turno.
      const currentShiftEndStrHO = shiftConfigs[currentUser?.id]?.end || '17:00';
      const currentShiftEndMinsHO = parseTimeToMins(currentShiftEndStrHO);
      const isHandoverWindow = currentSimTime >= currentShiftEndMinsHO - 15;
      // NUEVO (2026-07-21): antes este gate era solo "isPro" (chequeo de tier hardcodeado). Ahora
      // exige también 'keys_control' vía isFeatureUnlocked — hoy es idéntico en la práctica porque
      // el flag viene incluido por default en pro/enterprise, pero ya respeta de verdad el array
      // allowedFeatures real que manda el backend por tenant (ver docs/FEATURE_TIERS.md).
      const isKeysControlUnlockedHO = useAppStore.getState().isFeatureUnlocked('keys_control');
      if (isPro && isKeysControlUnlockedHO && isManager && !isHandoverCompleted && isHandoverWindow) {
        return {
          text: 'Entregar Turno',
          bg: 'bg-cyan-600 hover:bg-cyan-700 text-white font-bold shadow-[0_0_20px_rgba(8,145,178,0.3)] animate-pulse',
          icon: '🗝️',
          iconKey: 'handover',
          subtext: 'Realizar arqueo y entrega de llaves'
        };
      }

      // ----------------------------------------------------
      // VENTANA 4: Salida Normal (T-10 para Gratis)
      // ----------------------------------------------------
      if (!isPro) {
        const currentShiftEndStr = shiftConfigs[currentUser?.id]?.end || '17:00';
        const currentShiftEndMins = parseTimeToMins(currentShiftEndStr);
        const isWithinExitWindow = currentSimTime >= (currentShiftEndMins - 10);
        if (!isWithinExitWindow) {
          return {
            text: 'Jornada en Curso',
            bg: 'bg-slate-200 text-slate-400 cursor-not-allowed opacity-60',
            icon: '⏳',
            iconKey: 'waiting_opening',
            disabled: true,
            subtext: `Salida disponible a las ${formatTimeMins(currentShiftEndMins - 10)}`
          };
        }
      }

      return {
        text: 'Fichar Salida',
        bg: 'bg-rose-600 hover:bg-rose-700 text-white font-black shadow-[0_0_22px_rgba(225,29,72,0.35)]',
        icon: '🚪',
        iconKey: 'exit',
        subtext: 'Checklist cierre seguro (luces/caja)'
      };
    }

    if (clockState === 'meal') {
      return { text: 'Terminar Comida', bg: 'bg-emerald-500 hover:bg-emerald-600 text-white font-bold shadow-[0_0_20px_rgba(16,185,129,0.35)]', icon: '🏃', iconKey: 'meal_end', subtext: 'Haz clic al regresar a la sucursal' };
    }
    if (clockState === 'short_break') {
      return { text: 'Terminar Descanso', bg: 'bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-[0_0_20px_rgba(79,70,229,0.35)]', icon: '🏃', iconKey: 'break_end', subtext: 'Haz clic al reincorporarte' };
    }
    if (clockState === 'temp_exit') {
      return { text: 'Fichar Reingreso', bg: 'bg-teal-500 hover:bg-teal-600 text-white font-bold shadow-[0_0_20px_rgba(20,184,166,0.35)]', icon: '🚶', iconKey: 'reingreso', subtext: 'Pase de salida temporal (Regreso est. 30m)' };
    }
    if (clockState === 'absent') {
      return { text: 'Ausencia Registrada', bg: 'bg-rose-100 text-rose-500 cursor-not-allowed', icon: '🚷', iconKey: 'absent', disabled: true };
    }
    if (clockState === 'finished') {
      return { text: 'Fin Jornada', bg: 'bg-slate-200 text-slate-400 cursor-not-allowed', icon: '🏁', iconKey: 'finished', disabled: true, subtext: 'Turno concluido hoy.' };
    }
    return { text: 'Procesando...', bg: 'bg-slate-200', icon: '...' };
  };

  useEffect(() => {
    if (isSandboxMode) return;
    const fetchRealAssignments = async () => {
      try {
        const res = await axiosInstance.get('/store-opening/assignments');
        if (res.data) {
          localStorage.setItem('store_opening_assignments', JSON.stringify(res.data));
          window.dispatchEvent(new Event('store_opening_assignments_updated'));
        }
      } catch (e) {
        console.error("Error fetching real assignments from backend:", e);
      }
    };
    fetchRealAssignments();
  }, [isSandboxMode]);

  // --- NUEVAS HERRAMIENTAS INTEGRADAS CON EL SERVIDOR ---
  const [chatMessages, setChatMessages] = useState<any[]>([]);
  const [chatLoading, setChatLoading] = useState(false);

  // 1. Chat de equipo
  const fetchChatMessages = async () => {
    if (isSandboxMode) return;
    setChatLoading(true);
    try {
      const res = await axiosInstance.get('/chat/messages');
      if (res.data) {
        setChatMessages(res.data);
      }
    } catch (e) {
      console.error("Error al cargar mensajes del chat:", e);
    } finally {
      setChatLoading(false);
    }
  };

  const sendChatMessage = async (msg: string) => {
    if (isSandboxMode) {
      const mockMsg = {
        id: Date.now(),
        user_id: currentUser.id,
        message: msg,
        created_at: new Date().toISOString(),
        user: {
          id: currentUser.id,
          name: currentUser.name,
          role: currentUser.role,
          avatar: currentUser.avatar
        }
      };
      setChatMessages(prev => [...prev, mockMsg]);
      return;
    }
    try {
      const res = await axiosInstance.post('/chat/messages', { message: msg });
      if (res.data) {
        setChatMessages(prev => [...prev, res.data]);
      }
    } catch (e) {
      console.error("Error al enviar mensaje:", e);
      showCustomAlert("Error al enviar mensaje.");
    }
  };

  // 2. El Soplón (Reporte de Compañero)
  const sendEmployeeReport = async (accusedId: number, type: string, details: string) => {
    if (isSandboxMode) {
      showCustomAlert("✅ Reporte registrado con éxito (Modo Sandbox).");
      return true;
    }
    try {
      await axiosInstance.post('/reports/employee', {
        accused_id: accusedId,
        type: type,
        details: details
      });
      showCustomAlert("✅ Tu reporte confidencial ha sido enviado.");
      return true;
    } catch (e) {
      console.error("Error al enviar reporte de conducta:", e);
      showCustomAlert("Error al procesar el reporte.");
      return false;
    }
  };

  // 3. Buzón Anónimo de RRHH
  const sendAnonymousFeedback = async (type: string, content: string) => {
    if (isSandboxMode) {
      showCustomAlert("✅ Feedback anónimo enviado (Modo Sandbox).");
      return true;
    }
    try {
      await axiosInstance.post('/anonymous-feedback', {
        type: type,
        content: content
      });
      showCustomAlert("✅ Tu feedback anónimo ha sido enviado de forma segura.");
      return true;
    } catch (e) {
      console.error("Error al enviar feedback anónimo:", e);
      showCustomAlert("Error al enviar el feedback.");
      return false;
    }
  };

  // NOTA (refactor Jul 2026): pendingKeyTransfers, initiateKeyTransfer, checkPendingKeyTransfers,
  // respondToKeyTransfer y reportAbandonment ahora viven en hooks/useKeyholderDelegation.ts — ver
  // la llamada a useKeyholderDelegation() más arriba, que devuelve exactamente estos mismos nombres.

  // Polling para Chat y Llaves
  useEffect(() => {
    if (isSandboxMode) return;
    
    checkPendingKeyTransfers();

    const interval = setInterval(() => {
      checkPendingKeyTransfers();
      if (phoneTab === 'herramientas' && innerTool === 'chat') {
        fetchChatMessages();
      }
    }, 15000);

    return () => clearInterval(interval);
  }, [isSandboxMode, phoneTab, innerTool]);

  const btnProps = getButtonProps();


  

    
    return {
    openingSettings,
    openingStatus,
    openingChecklistCompleted,
    setOpeningChecklistCompleted,
    openingRollCallCompleted,
    setOpeningRollCallCompleted,
    handleOpenStorePremium,
    handleReportAbsencePremium,
    handleReportLatePremium,
    handleReportStoreStillClosedPremium,
    handleCallSuplente,
    getNextSuplenteUser,
    isUserActiveKeyholder,
    showEmergencyOpenModal,
    setShowEmergencyOpenModal,
    emergencyOpenSubmitting,
    handleEmergencyStoreOpen,
    showContingencyModal,
    setShowContingencyModal,
    contingencySubmitting,
    handleContingencyDeclaration,
    activeContingency,
    closingChecklistCompleted,
    showClosingChecklistModal,
    setShowClosingChecklistModal,
    closingChecklistSubmitting,
    submitClosingChecklist,
    declararCierreSucursal,
    cierreDeclarado,
    securityPinSubmitting,
    handleUpdateSecurityPin,
    isOpeningPremium,
    isKeysControlUnlocked,

    isGlobalLoading: false,
    DIAS_SEMANA,
    absenceReason,
    absentUsers,
    activeEncargadoId,
    activePushNotification,
    activeTimers,
    adminConfigs,
    amnestyActive,
    ampm,
    applyPunishments,
    arrivalTimes,
    auditoryLogs,
    baseTimeMinutes,
    breaksTaken,
    broadcastInput,
    btnProps,
    buddyAlerts,
    calculateDailyStats,
    checkInTimes,
    clockState,
    confirmMealReservation,
    contingencyLogs,
    contingencyUsed,
    currentDay,
    currentUser,
    setCurrentUser,
    currentSimTime,
    dailyHistory,
    dbPermissions,
    dbRolePermissions,
    declareEmergency,
    designatedCloserId,
    displayHours,
    evalStars,
    expandedCards,
    featureFlags,
    formattedTime,
    workedElapsedLabel,
    getButtonProps,
    globalBroadcastMessage,
    globalClockStates,
    globalPermissions,
    globalRoles,
    globalStoreShiftEnd,
    globalStoreShiftStart,
    globalTimeBank,
    globalToast,
    globalUsers,
    setGlobalUsers,
    isLoadingDB,
    setIsLoadingDB,
    systemSettings,
    updateSetting,
    fetchState,
    handleAction,
    handleAperturaForzosa,
    handleBreakStart,
    handleClockOutRequest,
    handleContingency,
    handleDayChange,
    handleKeyDelegation,
    handleKioscoAdd,
    handleOpenStore,
    handleSubmitPaseLista,
    hasReservedMeal,
    initPaseLista,
    initialShifts,
    initialState,
    innerTool,
    isDropdownOpen,
    isModulesOpen,
    isNativeMode,
    isNativeURL,
    isRealTimeMode,
    isSidebarOpen,
    justificanteText,
    keyholders,
    kioscoInput,
    setKioscoInput,
    lateUsers,
    leySillaConfig,
    masterClosePhase,
    matrixTab,
    mealSettings,
    nextDayEncargadoId,
    parseTimeToMins,
    paseListaDone,
    paseListaEmployees,
    phoneTab,
    playAlarm,
    playedAlarms,
    privateInput,
    privateMessages,
    privateTarget,
    processFinalClockOut,
    realSeconds,
    removeAlert,
    reportForm,
    requireEvaluation,
    reservedMeals,
    resetSimulator,
    selectedSummaryDay,
    setAbsenceReason,
    setAbsentUsers,
    setActiveEncargadoId,
    setActivePushNotification,
    setActiveTimers,
    setAdminConfigs,
    setAmnestyActive,
    setApplyPunishments,
    setArrivalTimes,
    setAuditoryLogs,
    setBreaksTaken,
    setBroadcastInput,
    setBuddyAlerts,
    setCheckInTimes,
    setContingencyLogs,
    setContingencyUsed,
    setCurrentDay,
    setDailyHistory,
    setDbPermissions,
    setDbRolePermissions,
    setDesignatedCloserId,
    setEvalStars,
    setExpandedCards,
    setFeatureFlags,
    setGlobalBroadcastMessage,
    setGlobalClockStates,
    setGlobalPermissions,
    setGlobalRoles,
    setGlobalStoreShiftEnd,
    setGlobalStoreShiftStart,
    setGlobalTimeBank,
    setGlobalToast,
    setHasReservedMeal,
    setInnerTool,
    setIsDropdownOpen,
    setIsModulesOpen,
    setIsSidebarOpen,
    setJustificanteText,
    setKeyholders,
    setLateUsers,
    setLeySillaConfig,
    setMasterClosePhase,
    setMatrixTab,
    setMealSettings,
    setNextDayEncargadoId,
    setPaseListaDone,
    setPaseListaEmployees,
    setPhoneTab,
    setPlayedAlarms,
    setPrivateInput,
    setPrivateMessages,
    setPrivateTarget,
    setRealSeconds,
    setReportForm,
    setRequireEvaluation,
    setReservedMeals,
    setSelectedSummaryDay,
    setShiftConfigs,
    setShowAbsenceModal,
    setShowAmnestyModal,
    setShowCCTVModal,
    setShowEvalModal,
    setShowForzosaModal,
    setShowGhostTheater,
    setShowJustificanteModal,
    setShowKeyDelegationModal,
    setShowMasterCloseModal,
    setShowMealReservationModal,
    setShowPaseListaModal,
    setShowReportModal,
    setShowTransferModal,
    setSimTimeMinutes,
    setStoreOpenLog,
    setStoreOpenSimTime,
    setStoreStatus,
    setSummaryView,
    setTasksChecked,
    setTimeBankConfigs,
    setUndoCount,
    setUserReservedMealSlots,
    setUserSettings,
    setWeeklyHistory,
    shiftConfigs,
    showAbsenceModal,
    showAmnestyModal,
    showCCTVModal,
    showCustomAlert,
    showEvalModal,
    showForzosaModal,
    showGhostTheater,
    showJustificanteModal,
    showKeyDelegationModal,
    showMasterCloseModal,
    showMealReservationModal,
    showPaseListaModal,
    showReportModal,
    showTransferModal,
    simHours,
    simMins,
    storeOpenLog,
    storeOpenSimTime,
    storeStatus,
    submitEvaluation,
    submitReport,
    summaryView,
    syncToBackend,
    syncToDB,
    tasksChecked,
    timeBankConfigs,
    toggleSelectAll,
    undoCount,
    updateClockState,
    urlParams,
    userReservedMealSlots,
    userSettings,
    weeklyHistory,
    syncQueue,
    gpsCoordinates,
    setGpsCoordinates,
    gpsStatus,
    setGpsStatus,
    gpsDistance,
    isGpsValidationBypassed,
    isWithinPerimeter,
    isSimulatedOffline,
    setIsSimulatedOffline,
    STORE_LAT,
    STORE_LNG,
    ALLOWED_RADIUS_METERS,
    breakStartTimes,
    breakEndTimes,
    setBreakEndTimes,
    mealStartTimes,
    mealEndTimes,
    checkOutTimes,
    cancelMealReservation,
    swapMealSlots,
    requestGPS,
    chatMessages,
    chatLoading,
    pendingKeyTransfers,
    fetchChatMessages,
    sendChatMessage,
    sendEmployeeReport,
    sendAnonymousFeedback,
    initiateKeyTransfer,
    checkPendingKeyTransfers,
    respondToKeyTransfer,
    reportAbandonment,
    showBreakSeatModal,
    setShowBreakSeatModal,
    showTempExitModal,
    setShowTempExitModal,
    showPanicModal,
    setShowPanicModal,
    isPanicActive,
    setIsPanicActive,
    showMealSwapModal,
    setShowMealSwapModal,
    isHandoverCompleted,
    setIsHandoverCompleted,
    startBreakWithSittingTask,
    handleSendDoorNotice,
    showMealPhotoModal, setShowMealPhotoModal,
    mealPhotoType,
    mealPhotoSubmitting,
    submitMealPhoto,
    sillaRequestStage,
    sillaStatus,
    isSillaApprovalRequired,
    approveSillaRequest,
    rejectSillaRequest,
    refreshSillaStatus,
    startTempExit,
    endTempExit,
    triggerPanic,
    resolvePanic,
    completeHandover,
    cashCount,
    setCashCount,
    pendingBreakRequests,
    requestBreak,
    approveBreakRequest,
    rejectBreakRequest,
    userClockPrefs,
    setUserClockPrefs,
    showAlarmSettingsModal,
    setShowAlarmSettingsModal,
    pendingTasksBlocker,
    setPendingTasksBlocker,
    supervisorPin,
    setSupervisorPin,
    supervisorQrToken,
    setSupervisorQrToken,
    showEarlyDepartureModal,
    setShowEarlyDepartureModal,
    earlyDepartureReason,
    setEarlyDepartureReason,
    handleEarlyDepartureClick,
    submitEarlyDeparture,
    isEarlyDepartureValidation,
    isOvertimeUnlocked,
    isOvertimeValidation,
    isLateEntryValidation,
    setIsLateEntryValidation,
    isSimulatedHoliday,
    setIsSimulatedHoliday,
    handleOvertimeClick,
    hasMealReservation: (userReservedMealSlots[currentUser?.id] || []).length > 0,
    authorizeClockOutWithPendingTasks
  };
}
