// @ts-nocheck
import React, { useState, useEffect, useRef } from 'react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';
import { useTaskStore } from '../../store/useTaskStore';
import { MOCK_STORE } from '../../mockData';
import { echoInstance } from '../../lib/echo';

export function useClockEngine(overrideUser?: any) {

  

  // --- FULLSTACK GLOBAL STATE ---
  
  const [globalPermissions, setGlobalPermissions] = useState<string[]>([]);
  
  useEffect(() => {
    fetchState();

    window.addEventListener('db_sync_updated', fetchState);
    return () => window.removeEventListener('db_sync_updated', fetchState);
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
    activeEncargadoId, setActiveEncargadoId
  } = useAppStore();
  
  const currentUser = overrideUser || globalUser;
  const setCurrentUser = overrideUser ? () => {} : setGlobalUser;
  
  let leySillaConfig = systemSettings.leySillaConfig || {};
  const setLeySillaConfig = (v) => updateSetting('leySillaConfig', typeof v === 'function' ? v(leySillaConfig) : v);
  
  let featureFlags = systemSettings.featureFlags || {};
  const setFeatureFlags = (v) => updateSetting('featureFlags', typeof v === 'function' ? v(featureFlags) : v);
  
  let mealSettings = systemSettings.mealSettings || {};
  const setMealSettings = (v) => updateSetting('mealSettings', typeof v === 'function' ? v(mealSettings) : v);
  
  let timeBankConfigs = systemSettings.timeBankConfigs || {};
  const setTimeBankConfigs = (v) => updateSetting('timeBankConfigs', typeof v === 'function' ? v(timeBankConfigs) : v);
  
  const adminConfigs = systemSettings.adminConfigs || {};
  const setAdminConfigs = (v) => updateSetting('adminConfigs', typeof v === 'function' ? v(adminConfigs) : v);

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
  const setGlobalStoreShiftStart = (v) => updateSetting('globalStoreShiftStart', typeof v === 'function' ? v(globalStoreShiftStart) : v);
  
  const globalStoreShiftEnd = systemSettings.globalStoreShiftEnd;
  const setGlobalStoreShiftEnd = (v) => updateSetting('globalStoreShiftEnd', typeof v === 'function' ? v(globalStoreShiftEnd) : v);

  

  useEffect(() => {
    fetchState();
    const interval = setInterval(fetchState, 5000);
    return () => {
      clearInterval(interval);
    };
  }, [currentUser.id]);

  const [globalToast, setGlobalToast] = useState<string | null>(null);
  const [storeOpenSimTime, setStoreOpenSimTime] = useState<number | null>(null);
  const [paseListaDone, setPaseListaDone] = useState(false);
  const [activePushNotification, setActivePushNotification] = useState<{type: string, text: string, action: () => void} | null>(null);

  const showCustomAlert = (msg: string) => {
    setGlobalToast(msg);
    setTimeout(() => setGlobalToast(null), 4000);
  };

  useEffect(() => {
    if (!currentUser || !currentUser.tenant_id) return;
    const channelName = `tenant.${currentUser.tenant_id}.clock`;
    const channel = echoInstance.channel(channelName);
    
    channel.listen('.App\\Events\\StoreOpened', (e: any) => {
      console.log('StoreOpened event received via WebSockets:', e);
      fetchState();
      showCustomAlert('¡La tienda ha sido abierta oficialmente!');
    });

    return () => {
      channel.stopListening('.App\\Events\\StoreOpened');
    };
  }, [currentUser?.tenant_id]);
  
  useEffect(() => {
    if (!currentUser || globalRoles.length === 0 || dbPermissions.length === 0) return;
    const myRole = globalRoles.find(r => r.id === currentUser.job_role_id);
    if (myRole) {
       const myPermIds = dbRolePermissions.filter(rp => rp.job_role_id === myRole.id).map(rp => rp.permission_id);
       const myPermNames = dbPermissions.filter(p => myPermIds.includes(p.id)).map(p => p.name);
       setGlobalPermissions(myPermNames);
    }
  }, [currentUser, globalRoles, dbPermissions, dbRolePermissions]);

  // using global storeStatus
  const [summaryView, setSummaryView] = useState('daily');
  const [applyPunishments, setApplyPunishments] = useState(false);
  const [showMasterCloseModal, setShowMasterCloseModal] = useState(false);
  const [designatedCloserId, setDesignatedCloserId] = useState(1);
  const [masterClosePhase, setMasterClosePhase] = useState('checklist');
  const [tasksChecked, setTasksChecked] = useState({ t1: false, t2: false, t3: false });
  const [showTransferModal, setShowTransferModal] = useState(false);


  

  const [amnestyActive, setAmnestyActive] = useState(MOCK_STORE.hasAmnesty);
  const [requireEvaluation, setRequireEvaluation] = useState(MOCK_STORE.requireEvaluation);
  
  const [showCCTVModal, setShowCCTVModal] = useState(false);
  
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


  const [expandedCards, setExpandedCards] = useState({});
  const [phoneTab, setPhoneTab] = useState('checador');
  const [innerTool, setInnerTool] = useState(null);
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const [realSeconds, setRealSeconds] = useState(0);

  useEffect(() => {
    const interval = setInterval(() => {
      setRealSeconds(prev => (prev + 1) % 60);
    }, 1000);
    return () => clearInterval(interval);
  }, []);

    const parseTimeToMins = (timeStr) => {
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
  const [shiftConfigs, setShiftConfigs] = useState(initialShifts);

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
    let interval;
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
  const currentSimTime = globalSimTime;
  // Aliases to avoid breaking RelojVisual (Moved to top to prevent TDZ)
  const checkInTimes = globalCheckInTimes;
  const arrivalTimes = globalArrivalTimes;
  const clockState = globalClockStates[currentUser?.id] || 'inactive';
  const simTimeMinutes = currentSimTime;
  const setSimTimeMinutes = () => {}; // no-op
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
  
  
  // const clockState = globalClockStates[currentUser.id];
  
  const updateClockState = (userId, state) => {
    setGlobalClockStates(prev => {
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
                    useTaskStore.getState().triggerCheckInRoutines(userId, u.job_role_id, currentSimTime);
                }

                const startMins = shiftConfigs[userId]?.start ? parseInt(shiftConfigs[userId].start.split(':')[0])*60 + parseInt(shiftConfigs[userId].start.split(':')[1]) : 480;
                const tolerance = shiftConfigs[userId]?.tolerance || 10;
                if (currentSimTime <= startMins + tolerance) {
                    type = 'success';
                    desc = `Fichaje exitoso. El empleado ha llegado a tiempo (Puntual).`;
                } else {
                    type = 'warning';
                    const delayMins = currentSimTime - startMins;
                    desc = `Fichaje con retardo. El empleado llegó tarde por ${delayMins} minutos.`;
                }
            }
            else if (state === 'inactive') { 
                actionName = 'Fichaje de Salida'; 
                type = 'warning'; 
                // Trigger Spill-over
                const u = globalUsers.find(user => user.id === userId);
                if (u) {
                    useTaskStore.getState().handleSpillOver(userId, u.job_role_id);
                }
            }
            else if (state === 'meal') { actionName = 'Salida a Comer'; type = 'info'; }
            else if (prevState === 'meal' && state === 'active') { actionName = 'Regreso de Comida'; type = 'success'; }
            else if (state === 'short_break') { actionName = 'Descanso Corto (Ley Silla)'; type = 'info'; }
            else if (prevState === 'short_break' && state === 'active') { actionName = 'Fin de Descanso'; type = 'success'; }
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
            else if (state === 'inactive') type = 'check_out';
            else if (prevState === 'meal' && state === 'active') type = 'meal_end';
            
            const startMins = shiftConfigs[userId]?.start ? parseInt(shiftConfigs[userId].start.split(':')[0])*60 + parseInt(shiftConfigs[userId].start.split(':')[1]) : 480;
            const isLate = type === 'check_in' && currentSimTime > startMins + (shiftConfigs[userId]?.tolerance || 10);
            const lateMins = isLate ? currentSimTime - startMins : 0;
            
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
  const [showAbsenceModal, setShowAbsenceModal] = useState(false);
  const [showAmnestyModal, setShowAmnestyModal] = useState(false);
  const [showGhostTheater, setShowGhostTheater] = useState(false);

  const [globalBroadcastMessage, setGlobalBroadcastMessage] = useState<string | null>(null);
  const [broadcastInput, setBroadcastInput] = useState("");
  
  const [privateMessages, setPrivateMessages] = useState<Record<number, string>>({});
  const [privateInput, setPrivateInput] = useState("");
  const [privateTarget, setPrivateTarget] = useState<number>(1);
  
  const [showJustificanteModal, setShowJustificanteModal] = useState(false);
  const [justificanteText, setJustificanteText] = useState("");
  
  const [showReportModal, setShowReportModal] = useState(false);
  const [showEvalModal, setShowEvalModal] = useState(false);
  const [showForzosaModal, setShowForzosaModal] = useState(false);
  const [showPaseListaModal, setShowPaseListaModal] = useState(false);
  
  const [paseListaEmployees, setPaseListaEmployees] = useState([]);
  const [kioscoInput, setKioscoInput] = useState('');
  const [evalStars, setEvalStars] = useState(0);
  const [storeOpenLog, setStoreOpenLog] = useState<{time: string, type: 'normal'|'forzosa'} | null>(null);
  const [absenceReason, setAbsenceReason] = useState("");
  const [contingencyLogs, setContingencyLogs] = useState<any[]>([]);
  const [contingencyUsed, setContingencyUsed] = useState<Record<number, boolean>>({});
  const [absentUsers, setAbsentUsers] = useState<Record<number, boolean>>({});
  const [lateUsers, setLateUsers] = useState<Record<number, boolean>>({});

  const [auditoryLogs, setAuditoryLogs] = useState([]);
  const [reportForm, setReportForm] = useState({ targetId: '', type: '', details: '' });

  const DIAS_SEMANA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
  const [currentDay, setCurrentDay] = useState('Domingo');
  const [selectedSummaryDay, setSelectedSummaryDay] = useState('Domingo');
  const [dailyHistory, setDailyHistory] = useState({});

// MOTOR MATEMATICO
  const calculateDailyStats = (user, targetDay) => {
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
        statusClass = 'bg-orange-100 text-orange-700';
        penaltyText = `-${(mealTaken - timeBankConfigs.mealMinutes) * 3}m`;
    }

    const restTaken = user.name === 'Valeria' ? 20 : 15;
    const allowedRest = 15;
    return { mealTaken, penaltyText, status, statusClass, restTaken, allowedRest, effectiveArrival, effectiveMealStart, effectiveMealEnd };
  };


  // Matrix Tabs
  const [matrixTab, setMatrixTab] = useState('simulador');
  const [isSidebarOpen, setIsSidebarOpen] = useState(true);
  const [isModulesOpen, setIsModulesOpen] = useState(true);
  
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
  
  const [showMealReservationModal, setShowMealReservationModal] = useState(false);
  
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

    setHasReservedMeal({ ...hasReservedMeal, [currentUser.id]: true });
    setUserReservedMealSlots({ ...userReservedMealSlots, [currentUser.id]: blocksToReserve });
    
    const newReservedMeals = { ...reservedMeals };
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
  
  // Fase 1: Portadores de Llaves
  const [keyholders, setKeyholders] = useState<number[]>([]);

  useEffect(() => {
    if (globalUsers && globalUsers.length > 0) {
      const activeKeyholders = globalUsers
        .filter(u => u.portadorLlaves && u.portadorLlaves.toLowerCase() !== 'ninguno')
        .map(u => u.id);
      setKeyholders(activeKeyholders);
    }
  }, [globalUsers]);

  const [showKeyDelegationModal, setShowKeyDelegationModal] = useState(false);
  const [nextDayEncargadoId, setNextDayEncargadoId] = useState<number | null>(null);


  const [userSettings, setUserSettings] = useState({ theme: 'light', fontSize: 'normal' });
  const [undoCount, setUndoCount] = useState(0);
  const [playedAlarms, setPlayedAlarms] = useState({ ya_llegue: false, tienda_cerrada: false });

  // Reset alarms when user or day changes
  useEffect(() => {
    setPlayedAlarms({ ya_llegue: false, tienda_cerrada: false });
  }, [currentUser.id, currentDay]);

  const playAlarm = (type: 'ya_llegue' | 'tienda_cerrada' | 'alerta_tiempo') => {
    try {
      const AudioContextClass = window.AudioContext || (window as any).webkitAudioContext;
      if (!AudioContextClass) return;
      const ctx = new AudioContextClass();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      
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
    } catch (e) {
      console.log("Audio no soportado");
    }
  };

  // Push Notification: Pase de Lista Retardado
  useEffect(() => {
    if (storeStatus === 'open' && storeOpenSimTime !== null && !paseListaDone) {
      if (currentSimTime >= storeOpenSimTime && !showPaseListaModal) {
         if (currentUser.id === activeEncargadoId && activePushNotification?.type !== 'pase_lista') {
            const rollCallUnlocked = useAppStore.getState().isFeatureUnlocked('roll_call');
            if (rollCallUnlocked) {
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



  // Push Notification: Tareas Retrasadas / Advertencia 80% de tiempo
  useEffect(() => {
    if (storeStatus !== 'open') return;
    const storeState = useTaskStore.getState();
    const myAssignment = storeState.assignments.find(a => a.userId === currentUser.id && a.status === 'in_progress');
    
    if (myAssignment) {
      const myTask = storeState.tasks.find(t => t.id === myAssignment.taskId);
      if (myTask) {
        const isDelayed = myAssignment.expectedEndTimeMins && currentSimTime >= myAssignment.expectedEndTimeMins;
        
        if (isDelayed) {
          if (activePushNotification?.type !== 'tarea_retrasada') {
            setActivePushNotification({
              type: 'tarea_retrasada',
              text: `🚨 Estás retrasado en tu tarea: ${myTask.title}. ¡Apresúrate!`,
              action: () => {
                setActivePushNotification(null);
                setPhoneTab('tareas');
              }
            });
          }
        } else {
          // Evaluar si falta 20% o menos para terminar
          const elapsed = currentSimTime - myAssignment.startedAtMins + (myAssignment.accumulatedMins || 0);
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
      if (activePushNotification?.type === 'tarea_retrasada' || activePushNotification?.type === 'advertencia_tiempo') {
        setActivePushNotification(null);
      }
    }
  }, [currentSimTime, storeStatus, currentUser.id, activePushNotification]);

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
          return { ...a, userId: null, reservedAtMins: null };
        }
      }
      return a;
    });

    if (hasChanges) {
      useTaskStore.setState({ assignments: updatedAssignments });
      useTaskStore.getState().syncToBackend();
    }
  }, [currentSimTime, storeStatus]);

  useEffect(() => {
    if (storeStatus !== 'closed') return;
    const shiftStartMins = parseTimeToMins((shiftConfigs[currentUser?.id]?.start || '09:00'));
    // const clockState = globalClockStates[currentUser.id];
    
    if (clockState === 'inactive') {
      const limitMins = shiftStartMins - 30;
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
      const activeEncargado = globalUsers.find(u => u.id === activeEncargadoId);
      if (activeEncargado && !absentUsers[activeEncargado.id] && !lateUsers[activeEncargado.id]) {
        const encShiftStart = parseTimeToMins(shiftConfigs[activeEncargado.id].start);
        if (currentSimTime >= encShiftStart + 10) {
           // GATILLO
           setAbsentUsers(prev => ({...prev, [activeEncargado.id]: true}));
           const log = { id: Date.now(), userId: activeEncargado.id, userName: activeEncargado.name, type: 'absent' as const, reason: 'SISTEMA: Failsafe Automático (Sin respuesta a los 10 mins)', time: formattedTime };
           setContingencyLogs(prev => [log, ...prev]);
           
           let nextEncargadoId = null;
           const hierarchy = globalUsers.filter(u => u.esAperturador).sort((a,b) => a.jerarquiaLlaves - b.jerarquiaLlaves).map(u => u.id);
           for(let id of hierarchy) {
             if (id !== activeEncargado.id && !absentUsers[id] && shiftConfigs[id].restDay !== currentDay) {
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

  const handleDayChange = (newDay: string) => {
    // Guardar log del día actual
    setWeeklyHistory(prev => ({
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
    setDailyHistory({});
    setCurrentDay('Domingo');
    setSelectedSummaryDay('Domingo');
    setActiveTimers({});
  };

  const handleContingency = async (type: 'late' | 'absent') => {
    if (!absenceReason.trim()) {
      showCustomAlert("Por favor, escribe el motivo de tu contingencia.");
      return;
    }
    
    try {
      await axiosInstance.post('/sync/contingency', {
          user_id: currentUser.id,
          type: type,
          status: 'pending',
          justification_text: absenceReason
      });
      
      // Cascada de Llaves Inmediata
      if (currentUser.id === activeEncargadoId) {
         let nextEncargadoId = null;
         const hierarchy = globalUsers.filter(u => u.esAperturador).sort((a,b) => a.jerarquiaLlaves - b.jerarquiaLlaves).map(u => u.id);
         for(let id of hierarchy) {
           if (id !== currentUser.id && !absentUsers[id] && !contingencyUsed[id]) {
              nextEncargadoId = id;
              break;
           }
         }
         if (nextEncargadoId) {
           await axiosInstance.post('/sync/clock', {
               user_id: currentUser.id,
               date: new Date().toLocaleDateString('sv-SE'),
               type: 'check_out',
               time: formattedTime,
               details: `{"evalStars":0,"delegatedKeysTo":${nextEncargadoId}}`
           });
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
    } catch (e) {
      console.error(e);
      showCustomAlert("Error al registrar contingencia.");
    }
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
      const shiftStartMins = parseTimeToMins(shiftConfigs[u.id].start);
      const toleranceEndMins = shiftStartMins + 10;
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
    
    // Asumimos horario default para kiosco
    const shiftStartMins = parseTimeToMins('08:00');
    const toleranceEndMins = shiftStartMins + 10;

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
          else if (type === 'check_out') newState = 'inactive';
          else if (type === 'contingency') newState = 'contingency';

          updateClockState(currentUser.id, newState);
          return;
      }
      try {
         const response = await axiosInstance.post('/clock/punch', { 
             user_id: currentUser.id, 
             type, 
             time: formattedTime, // En produccion esto debe omitirse para que el servidor use now()
             details: { note: details } 
         });
         const data = response.data;
         // window.dispatchEvent(new Event('db_sync_updated')); // Quitar si no se usa
         return data;
      } catch (e) {
         console.error(e);
      }
  };
  const handleAction = async () => {
    const shiftStartMins = parseTimeToMins((shiftConfigs[currentUser?.id]?.start || '09:00'));
    const toleranceEndMins = shiftStartMins + 10;
    
    const btnProps = getButtonProps();
  


    if (btnProps.isReport) {
      showCustomAlert(`🚨 [${formattedTime}] Reporte crítico enviado a Administración: "Tienda Cerrada y Personal Afuera".`);
      await syncToDB('check_in', false, 0, 'Reporte de tienda cerrada');
      return;
    }

    if (storeStatus === 'closed') {
      if (currentUser.id === activeEncargadoId) {
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
      if (clockState === 'inactive' || clockState === 'waiting_room' || clockState === 'waiting') {
        // Lógica de retardos movida al backend (Server-Authoritative)
        const res = await syncToDB('check_in');
        if (res && res.entry && res.entry.late_type) {
            showCustomAlert(`🟢 Fichaje registrado. Se detectó: ${res.entry.late_type} (${res.entry.penalty_applied}% descuento)`);
            if (['Encargado Titular', 'Segundo Encargado', 'Supervisor'].includes(currentUser.role)) {
                setShowJustificanteModal(true);
            }
        } else {
            showCustomAlert(`🟢 Fichaje registrado a tiempo.`);
        }

      } else if (clockState === 'active') {
        await syncToDB('meal_start');
      } else if (clockState === 'meal') {
        // En FASE real, aquí se valida si pasaron los minutos obligatorios
        await syncToDB('meal_end');
        showCustomAlert('🏃 Has regresado de comer.');
      } else if (clockState === 'short_break') {
        await syncToDB('break_end');
        showCustomAlert('🏃 Has regresado de tu descanso.');
      }
    }
  };

  const handleBreakStart = async () => {
      await syncToDB('break_start');
      showCustomAlert('🧘 Has iniciado tu descanso (Ley Silla).');
  };

  const handleClockOutRequest = () => {
    if (requireEvaluation) {
      setShowEvalModal(true);
      return;
    }
    processFinalClockOut();
  };

  const submitEvaluation = () => {
    setShowEvalModal(false);
    setRequireEvaluation(false); 
    showCustomAlert(`⭐ Evaluación enviada.`);
    processFinalClockOut();
  };

  const processFinalClockOut = async (delegatedTo = null) => {
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
       delegatedKeysTo: delegatedTo
    };

    await syncToDB('check_out', false, 0, JSON.stringify(detailsObj));
    showCustomAlert(`🔴 Salida registrada a las ${formattedTime}.${delegatedTo ? ' 🔑 Llaves delegadas con éxito.' : ''}`);
  };

  const handleKeyDelegation = async () => {
    if (!nextDayEncargadoId) {
      showCustomAlert("Debes seleccionar a un encargado suplente.");
      return;
    }
    setShowKeyDelegationModal(false);
    await processFinalClockOut(nextDayEncargadoId);
  };

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
    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay;
    if (isRestDay) return { text: 'DÍA DE DESCANSO', bg: 'bg-slate-300 text-slate-500 cursor-not-allowed', icon: '🌴', disabled: true };

    if (currentUser.id === activeEncargadoId && storeStatus === 'closed') {
      return { text: 'Deslizar para Abrir Sucursal', bg: 'bg-indigo-600 hover:bg-indigo-700', icon: '🗝️' };
    }
    if (storeStatus === 'closed') {
      const shiftStartMins = parseTimeToMins((shiftConfigs[currentUser?.id]?.start || '09:00'));
      
      if (clockState === 'inactive') {
        const limitMins = shiftStartMins - 30;
        if (currentSimTime < limitMins) {
          const formatLimit = () => {
            const h = Math.floor(limitMins / 60);
            const m = limitMins % 60;
            const ampm = h >= 12 ? 'pm' : 'am';
            return `${h > 12 ? h - 12 : h}:${m.toString().padStart(2,'0')} ${ampm}`;
          };
          return { text: `Disponible a las ${formatLimit()}`, bg: 'bg-slate-200 text-slate-400 cursor-not-allowed', icon: '🔒', disabled: true };
        }
        return { text: '👋 Ya Llegué', bg: 'bg-slate-800 hover:bg-slate-900', icon: '📍' };
      }
      if (clockState === 'waiting_room') {
        if (currentSimTime >= shiftStartMins) {
          return { text: '⚠️ Reportar tienda cerrada', bg: 'bg-rose-500 hover:bg-rose-600 text-white', icon: '🚨', isReport: true };
        }
        return { text: 'En perímetro. Esperando...', bg: 'bg-slate-300 text-slate-500 cursor-not-allowed', icon: '⏳', disabled: true };
      }
    }
    if (clockState === 'inactive' || clockState === 'waiting_room') {
      return { text: 'Registrar Entrada Manual', bg: 'bg-emerald-500 hover:bg-emerald-600', icon: '🟢' };
    }
     if (clockState === 'active') {
      const mealReservationUnlocked = useAppStore.getState().isFeatureUnlocked('meal_reservation');
      if (featureFlags.comidas && mealReservationUnlocked) {
        const mySlots = userReservedMealSlots[currentUser.id] || [];
        if (mySlots.length > 0) {
           const [sh, sm] = mySlots[0].split(' ')[0].split(':');
           const isPm = mySlots[0].includes('PM');
           let hour = parseInt(sh);
           if (isPm && hour !== 12) hour += 12;
           if (!isPm && hour === 12) hour = 0;
           const firstSlotMins = hour * 60 + parseInt(sm);
           
           if (currentSimTime < firstSlotMins - 5) {
              return { text: 'Iniciar Horario de Comida', bg: 'bg-slate-200 text-slate-400 cursor-not-allowed opacity-60', icon: '🍔', disabled: true, subtext: `Tu turno inicia a las ${mySlots[0]}` };
           }
        } else {
           return { text: 'Reserva tu horario primero', bg: 'bg-slate-200 text-slate-400 cursor-not-allowed', icon: '🔒', disabled: true };
        }
      }
      return { text: 'Iniciar Horario de Comida', bg: 'bg-amber-500 hover:bg-amber-600 text-amber-950', icon: '🍔' };
    }
    if (clockState === 'meal') {
      return { text: 'Regresar de Comida', bg: 'bg-emerald-500 hover:bg-emerald-600', icon: '🏃' };
    }
    if (clockState === 'absent') {
      return { text: 'Ausencia Registrada', bg: 'bg-rose-100 text-rose-500 cursor-not-allowed', icon: '🚷', disabled: true };
    }
    if (clockState === 'finished') {
      return { text: 'Jornada Finalizada', bg: 'bg-slate-200 text-slate-400 cursor-not-allowed', icon: '🏁', disabled: true };
    }
    return { text: 'Procesando...', bg: 'bg-slate-200', icon: '...' };
  };

  const btnProps = getButtonProps();


  

    
    return {

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
    setKioscoInput,
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
    currentSimTime,
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
    weeklyHistory
  };
}
