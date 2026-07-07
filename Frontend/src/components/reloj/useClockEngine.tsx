// @ts-nocheck
import React, { useState, useEffect, useRef } from 'react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';
import { useTaskStore } from '../../store/useTaskStore';
import { MOCK_STORE } from '../../mockData';
import { echoInstance } from '../../lib/echo';
import { offlineDb } from '../../lib/offlineDb';

export function useClockEngine(overrideUser?: any) {
  const assignments = useTaskStore(s => s.assignments);
  
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
    activeEncargadoId, setActiveEncargadoId,
    globalSimDay, setGlobalSimDay,
    isSandboxMode,
    globalBreakStartTimes,
    globalBreakEndTimes,
    globalMealStartTimes,
    globalMealEndTimes,
    globalCheckOutTimes,
    globalPendingBreakRequests,
    currentTier
  } = useAppStore();
  
  const currentUser = overrideUser || globalUser;
  const setCurrentUser = overrideUser ? () => {} : setGlobalUser;
  const isSimulator = !!overrideUser;
  
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

  // --- Estados y Lógica de Apertura de Tienda Premium ---
  const [openingSettings, setOpeningSettings] = useState<any>(() => {
    try {
      const saved = localStorage.getItem('store_opening_settings');
      return saved ? JSON.parse(saved) : {
        is_enabled: true,
        pre_opening_window_minutes: 15,
        absence_late_report_window_minutes: 5,
        early_clock_in_allowed_minutes: 10,
        allow_automatic_handoff: true,
        allow_late_if_before_opening: true,
        allow_store_closed_report: true,
        enable_amnesty_if_store_closed: true,
        require_opening_checklist: true,
        require_opening_roll_call: true,
        notify_admin_on_handoff: true,
        notify_supervisor_on_handoff: true,
      };
    } catch {
      return {};
    }
  });

  const [openingStatus, setOpeningStatus] = useState<any>(null);
  const [openingChecklistCompleted, setOpeningChecklistCompleted] = useState(() => {
    return localStorage.getItem('opening_checklist_completed') === 'true';
  });
  const [openingRollCallCompleted, setOpeningRollCallCompleted] = useState(() => {
    return localStorage.getItem('opening_roll_call_completed') === 'true';
  });

  const getSimTimeStr = (mins: number) => {
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:00`;
  };

  useEffect(() => {
    const syncApertura = async () => {
      const isOpeningPremium = useAppStore.getState().isFeatureUnlocked('store_opening');
      if (!isOpeningPremium) return;

      if (isSandboxMode) {
        try {
          const savedSettings = localStorage.getItem('store_opening_settings');
          if (savedSettings) {
            setOpeningSettings(JSON.parse(savedSettings));
          }
        } catch {}

        let statusObj = null;
        try {
          const savedStatus = localStorage.getItem('store_daily_opening_status');
          if (savedStatus) {
            statusObj = JSON.parse(savedStatus);
          }
        } catch {}

        const openTimeStr = systemSettings.storeSchedule?.openTime || '08:30';
        const [oh, om] = openTimeStr.split(':').map(Number);
        const openTimeMins = oh * 60 + om;
        const preWindowMins = openingSettings.pre_opening_window_minutes || 15;
        const windowStartMins = openTimeMins - preWindowMins;
        const reportDeadlineMins = windowStartMins + (openingSettings.absence_late_report_window_minutes || 5);

        const todayStr = new Date().toDateString();
        if (!statusObj || statusObj.date_str !== todayStr) {
          let ass = [];
          try {
            const isSandbox = useAppStore.getState().isSandboxMode;
            const savedAss = localStorage.getItem('store_opening_assignments');
            ass = savedAss ? JSON.parse(savedAss) : (
              isSandbox ? [
                { id: 1, employee_id: 1, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
                { id: 2, employee_id: 2, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
                { id: 3, employee_id: 3, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
              ] : [
                { id: 11, employee_id: 11, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
                { id: 12, employee_id: 12, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
                { id: 13, employee_id: 13, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
              ]
            );
          } catch {}

          const firstActive = ass
            .filter((a: any) => a.is_active)
            .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];

          statusObj = {
            date_str: todayStr,
            scheduled_opening_time: openTimeStr,
            pre_opening_window_start_mins: windowStartMins,
            report_deadline_mins: reportDeadlineMins,
            current_responsible_employee_id: firstActive ? firstActive.employee_id : 1,
            status: 'pending',
          };
          localStorage.setItem('store_daily_opening_status', JSON.stringify(statusObj));
        }

        if (statusObj.status !== 'opened' && statusObj.status !== 'failed' && statusObj.status !== 'closed_reported_by_employees') {
          if (globalSimTime < windowStartMins) {
            statusObj.status = 'pending';
          } else if (globalSimTime >= windowStartMins && globalSimTime < statusObj.report_deadline_mins) {
            statusObj.status = 'active_window';
          } else if (globalSimTime >= statusObj.report_deadline_mins) {
            if (openingSettings.allow_automatic_handoff) {
              let assList = [];
              try {
                const isSandbox = useAppStore.getState().isSandboxMode;
                const savedAss = localStorage.getItem('store_opening_assignments');
                assList = savedAss ? JSON.parse(savedAss) : (
                  isSandbox ? [
                    { id: 1, employee_id: 1, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
                    { id: 2, employee_id: 2, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
                    { id: 3, employee_id: 3, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
                  ] : [
                    { id: 11, employee_id: 11, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
                    { id: 12, employee_id: 12, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
                    { id: 13, employee_id: 13, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
                  ]
                );
              } catch {}

              const currentAss = assList.find((a: any) => a.employee_id === statusObj.current_responsible_employee_id);
              const currentOrder = currentAss ? currentAss.priority_order : 1;

              const nextAss = assList
                .filter((a: any) => a.is_active && a.priority_order > currentOrder)
                .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];

              if (nextAss) {
                statusObj.current_responsible_employee_id = nextAss.employee_id;
                statusObj.status = 'transferred';
                statusObj.report_deadline_mins = globalSimTime + (openingSettings.absence_late_report_window_minutes || 5);
                const nextName = globalUsers.find((u: any) => u.id === Number(nextAss.employee_id))?.name || 'suplente';
                showCustomAlert(`⏳ Límite excedido. Responsabilidad de apertura cedida a ${nextName}.`);
              } else {
                statusObj.status = 'failed';
                showCustomAlert(`🚨 Alerta Crítica: Todos los responsables de apertura fallaron.`);
              }
            } else {
              statusObj.status = 'failed';
            }
          }
          localStorage.setItem('store_daily_opening_status', JSON.stringify(statusObj));
        }

        setOpeningStatus(statusObj);
      } else {
        try {
          const res = await axiosInstance.get('/store-opening/today', {
            params: { simTime: getSimTimeStr(globalSimTime) }
          });
          setOpeningStatus(res.data.status);
        } catch (e) {
          console.error(e);
        }
      }
    };

    syncApertura();
    const interval = setInterval(syncApertura, 5000);
    return () => clearInterval(interval);
  }, [globalSimTime, isSandboxMode]);

  const handleOpenStorePremium = async () => {
    if (isSandboxMode) {
      const updated = {
        ...openingStatus,
        status: 'opened',
        opened_by_employee_id: currentUser.id,
        opened_at: new Date().toISOString()
      };
      setOpeningStatus(updated);
      localStorage.setItem('store_daily_opening_status', JSON.stringify(updated));
      setStoreStatus('open');

      const nowStr = getSimTimeStr(globalSimTime);
      await syncToDB('check_in', nowStr);

      showCustomAlert("🗝️ Apertura de tienda registrada con éxito y registro de entrada completado.");
    } else {
      try {
        const res = await axiosInstance.post('/store-opening/open-and-clock-in', {
          simTime: getSimTimeStr(globalSimTime)
        });
        if (res.data.success) {
          setOpeningStatus(res.data.status);
          setStoreStatus('open');
          await fetchState();
          showCustomAlert(res.data.message);
        }
      } catch (e: any) {
        showCustomAlert(e.response?.data?.message || "Error al abrir la tienda.");
      }
    }
  };

  const handleReportAbsencePremium = async () => {
    if (isSandboxMode) {
      let ass = [];
      try {
        const savedAss = localStorage.getItem('store_opening_assignments');
        ass = savedAss ? JSON.parse(savedAss) : [
          { id: 1, employee_id: 1, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
          { id: 2, employee_id: 2, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
        ];
      } catch {}

      const currentAss = ass.find((a: any) => a.employee_id === currentUser.id);
      const currentOrder = currentAss ? currentAss.priority_order : 1;

      const nextAss = ass
        .filter((a: any) => a.is_active && a.priority_order > currentOrder)
        .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];

      const updated = { ...openingStatus };
      if (nextAss) {
        updated.current_responsible_employee_id = nextAss.employee_id;
        updated.status = 'transferred';
        updated.report_deadline_mins = globalSimTime + (openingSettings.absence_late_report_window_minutes || 5);
        const nextName = globalUsers.find((u: any) => u.id === Number(nextAss.employee_id))?.name || 'suplente';
        showCustomAlert(`Ausencia reportada. Apertura cedida a ${nextName}.`);
      } else {
        updated.status = 'failed';
        showCustomAlert("Ausencia reportada. Alerta crítica enviada: no quedan más encargados.");
      }
      setOpeningStatus(updated);
      localStorage.setItem('store_daily_opening_status', JSON.stringify(updated));
    } else {
      try {
        const res = await axiosInstance.post('/store-opening/report-absence', {
          simTime: getSimTimeStr(globalSimTime)
        });
        if (res.data.success) {
          setOpeningStatus(res.data.handoff);
          showCustomAlert(res.data.message);
        }
      } catch (e: any) {
        showCustomAlert(e.response?.data?.message || "Error al reportar ausencia.");
      }
    }
  };

  const handleReportLatePremium = async (estimatedTimeStr: string) => {
    if (isSandboxMode) {
      const [eh, em] = estimatedTimeStr.split(':').map(Number);
      const etaMins = eh * 60 + em;

      const openTimeStr = systemSettings.storeSchedule?.openTime || '08:30';
      const [oh, om] = openTimeStr.split(':').map(Number);
      const openTimeMins = oh * 60 + om;

      const willBeLate = etaMins > openTimeMins;
      const mustHandoff = willBeLate && !openingSettings.allow_late_if_before_opening;

      if (mustHandoff || willBeLate) {
        let ass = [];
        try {
          const savedAss = localStorage.getItem('store_opening_assignments');
          ass = savedAss ? JSON.parse(savedAss) : [
            { id: 1, employee_id: 1, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
            { id: 2, employee_id: 2, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
          ];
        } catch {}

        const currentAss = ass.find((a: any) => a.employee_id === currentUser.id);
        const currentOrder = currentAss ? currentAss.priority_order : 1;

        const nextAss = ass
          .filter((a: any) => a.is_active && a.priority_order > currentOrder)
          .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];

        const updated = { ...openingStatus };
        if (nextAss) {
          updated.current_responsible_employee_id = nextAss.employee_id;
          updated.status = 'transferred';
          updated.report_deadline_mins = globalSimTime + (openingSettings.absence_late_report_window_minutes || 5);
          const nextName = globalUsers.find((u: any) => u.id === Number(nextAss.employee_id))?.name || 'suplente';
          showCustomAlert(`Retardo reportado. Apertura cedida a ${nextName}.`);
        } else {
          updated.status = 'failed';
          showCustomAlert("Retardo reportado. Alerta crítica enviada: no quedan suplentes.");
        }
        setOpeningStatus(updated);
        localStorage.setItem('store_daily_opening_status', JSON.stringify(updated));
      } else {
        showCustomAlert("Retardo reportado. Conservas la responsabilidad por estar dentro del margen.");
      }
    } else {
      try {
        const res = await axiosInstance.post('/store-opening/report-late', {
          estimated_arrival_time: estimatedTimeStr,
          simTime: getSimTimeStr(globalSimTime)
        });
        if (res.data.success) {
          if (res.data.handoff) {
            setOpeningStatus(res.data.handoff);
          }
          showCustomAlert(res.data.message);
        }
      } catch (e: any) {
        showCustomAlert(e.response?.data?.message || "Error al reportar retardo.");
      }
    }
  };

  const handleReportStoreStillClosedPremium = async () => {
    if (isSandboxMode) {
      const updated = {
        ...openingStatus,
        status: 'closed_reported_by_employees'
      };
      setOpeningStatus(updated);
      localStorage.setItem('store_daily_opening_status', JSON.stringify(updated));
      showCustomAlert("🚨 Reporte de tienda cerrada enviado. Se registrará incidencia para aplicar amnistía de retardo.");
    } else {
      try {
        const res = await axiosInstance.post('/store-opening/report-store-still-closed', {
          simTime: getSimTimeStr(globalSimTime)
        });
        if (res.data.success) {
          setOpeningStatus(res.data.status);
          showCustomAlert(res.data.message);
        }
      } catch (e: any) {
        showCustomAlert(e.response?.data?.message || "Error al enviar reporte.");
      }
    }
  };

  // --- PWA Advanced & Geofencing States ---
  const [syncQueue, setSyncQueue] = useState<any[]>(() => {
    try {
      const saved = localStorage.getItem('clock_sync_queue');
      return saved ? JSON.parse(saved) : [];
    } catch {
      return [];
    }
  });

  const [gpsCoordinates, setGpsCoordinates] = useState<any>({ latitude: 19.4344, longitude: -99.1332 });
  const [gpsStatus, setGpsStatus] = useState<'seeking' | 'success' | 'error'>('seeking');
  const [isSimulatedOffline, setIsSimulatedOffline] = useState(false);

  const fetchIpLocation = async () => {
    try {
      console.log("Intentando obtener ubicación por IP...");
      const res = await fetch('https://ipapi.co/json/');
      if (!res.ok) throw new Error("Fallo en API de IP");
      const data = await res.json();
      if (data && typeof data.latitude === 'number' && typeof data.longitude === 'number') {
        console.log("Ubicación IP obtenida con éxito:", data.latitude, data.longitude);
        setGpsCoordinates({
          latitude: data.latitude,
          longitude: data.longitude
        });
        setGpsStatus('success');
      } else {
        throw new Error("Coordenadas de IP inválidas");
      }
    } catch (err) {
      console.error("Fallo definitivo en geolocalización por IP:", err);
      setGpsStatus('error');
    }
  };

  const requestGPS = () => {
    setGpsStatus('seeking');
    if (!navigator.geolocation) {
      console.warn("API de Geolocalización no soportada por el navegador, recurriendo a IP...");
      fetchIpLocation();
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setGpsCoordinates({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude
        });
        setGpsStatus('success');
      },
      (error) => {
        console.warn("Error en Geolocation nativa del navegador, intentando fallback por IP...", error);
        fetchIpLocation();
      },
      { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
    );
  };

  useEffect(() => {
    if (isSimulator) {
      setGpsStatus('success');
      setGpsCoordinates({ latitude: 19.4326, longitude: -99.1332 });
      return;
    }

    const clockOpConfig = systemSettings.clockOpConfig || {};
    if (clockOpConfig.gpsValidationEnabled === false) {
      setGpsStatus('success');
    } else {
      requestGPS();
    }
  }, [systemSettings.clockOpConfig?.gpsValidationEnabled, isSimulator]);

  const [localBreakStartTimes, setLocalBreakStartTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_break_start_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  const [localMealStartTimes, setLocalMealStartTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_meal_start_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  const [localMealEndTimes, setLocalMealEndTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_meal_end_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  const [localCheckOutTimes, setLocalCheckOutTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_checkout_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  const [localBreakEndTimes, setLocalBreakEndTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_break_end_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  useEffect(() => {
     localStorage.setItem('clock_break_start_times', JSON.stringify(localBreakStartTimes));
  }, [localBreakStartTimes]);

  useEffect(() => {
     localStorage.setItem('clock_meal_start_times', JSON.stringify(localMealStartTimes));
  }, [localMealStartTimes]);

  useEffect(() => {
     localStorage.setItem('clock_meal_end_times', JSON.stringify(localMealEndTimes));
  }, [localMealEndTimes]);

  useEffect(() => {
     localStorage.setItem('clock_checkout_times', JSON.stringify(localCheckOutTimes));
  }, [localCheckOutTimes]);

  useEffect(() => {
     localStorage.setItem('clock_break_end_times', JSON.stringify(localBreakEndTimes));
  }, [localBreakEndTimes]);

  const [localPendingBreakRequests, setLocalPendingBreakRequests] = useState<Record<number, any>>(() => {
     try {
       const saved = localStorage.getItem('clock_pending_break_requests');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  useEffect(() => {
     localStorage.setItem('clock_pending_break_requests', JSON.stringify(localPendingBreakRequests));
  }, [localPendingBreakRequests]);

  const pendingBreakRequests = isSandboxMode ? localPendingBreakRequests : (globalPendingBreakRequests || {});

  const setPendingBreakRequests = (updater: any) => {
    if (isSandboxMode) {
      setLocalPendingBreakRequests(prev => typeof updater === 'function' ? updater(prev) : updater);
    } else {
      useAppStore.setState((state: any) => ({
        globalPendingBreakRequests: typeof updater === 'function' ? updater(state.globalPendingBreakRequests || {}) : updater
      }));
    }
  };

  const breakStartTimes = isSandboxMode ? localBreakStartTimes : (globalBreakStartTimes || {});
  const breakEndTimes = isSandboxMode ? localBreakEndTimes : (globalBreakEndTimes || {});
  const mealStartTimes = isSandboxMode ? localMealStartTimes : (globalMealStartTimes || {});
  const mealEndTimes = isSandboxMode ? localMealEndTimes : (globalMealEndTimes || {});
  const checkOutTimes = isSandboxMode ? localCheckOutTimes : (globalCheckOutTimes || {});

  const setBreakStartTimes = (updater: any) => {
    setLocalBreakStartTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };
  const setBreakEndTimes = (updater: any) => {
    setLocalBreakEndTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };
  const setMealStartTimes = (updater: any) => {
    setLocalMealStartTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };
  const setMealEndTimes = (updater: any) => {
    setLocalMealEndTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };
  const setCheckOutTimes = (updater: any) => {
    setLocalCheckOutTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };

  const STORE_LAT = 19.4326;
  const STORE_LNG = -99.1332;
  const ALLOWED_RADIUS_METERS = 50;

  const getDistanceInMeters = (lat1: number, lon1: number, lat2: number, lon2: number) => {
    const R = 6371e3; // Earth radius in meters
    const phi1 = (lat1 * Math.PI) / 180;
    const phi2 = (lat2 * Math.PI) / 180;
    const deltaPhi = ((lat2 - lat1) * Math.PI) / 180;
    const deltaLambda = ((lon2 - lon1) * Math.PI) / 180;

    const a =
      Math.sin(deltaPhi / 2) * Math.sin(deltaPhi / 2) +
      Math.cos(phi1) * Math.cos(phi2) * Math.sin(deltaLambda / 2) * Math.sin(deltaLambda / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return R * c; // in meters
  };

  const clockOpConfig = systemSettings.clockOpConfig || {};
  const isGpsValidationBypassed = clockOpConfig.gpsValidationEnabled === false || !!clockOpConfig.allowManualCheckIn || isSandboxMode;
  const gpsDistance = getDistanceInMeters(gpsCoordinates.latitude, gpsCoordinates.longitude, STORE_LAT, STORE_LNG);
  const isWithinPerimeter = isGpsValidationBypassed ? true : (gpsDistance <= ALLOWED_RADIUS_METERS && gpsStatus === 'success');

  const syncOfflineQueue = async () => {
    let currentQueue: any[] = [];
    try {
      currentQueue = await offlineDb.getPunches();
    } catch (e) {
      console.error("Error reading punches from IndexedDB:", e);
      currentQueue = [];
    }
    
    if (currentQueue.length === 0) return;
    
    const isOnline = navigator.onLine && !isSimulatedOffline;
    if (!isOnline) return;
    
    console.log("Sincronizando cola offline de fichajes de IndexedDB...");
    let successCount = 0;
    
    for (const item of currentQueue) {
      try {
        if (useAppStore.getState().isSandboxMode) {
          useAppStore.getState().addMatrixEvent(
            `[OFFLINE-SYNC] Fichaje Sincronizado: ${item.type}`,
            `El empleado ${currentUser?.name || 'Desconocido'} sincronizó offline a las ${item.time}`,
            'success',
            item.userId
          );
        } else {
          await axiosInstance.post('/clock/punch', { 
            user_id: item.userId, 
            type: item.type, 
            time: item.time,
            details: { note: item.details, offline: true, gps: item.gps } 
          });
        }
        if (item.id !== undefined) {
          await offlineDb.deletePunch(item.id);
        }
        successCount++;
      } catch (e) {
        console.error("Error sincronizando ítem de cola offline de IndexedDB:", e);
        break;
      }
    }
    
    if (successCount > 0) {
      const remaining = await offlineDb.getPunches();
      setSyncQueue(remaining);
      showCustomAlert(`🔄 Cola offline IndexedDB: ${successCount} registros sincronizados con éxito.`);
      window.dispatchEvent(new Event('db_sync_updated'));
    }
  };

  useEffect(() => {
    const handleOnline = () => {
      syncOfflineQueue();
    };
    window.addEventListener('online', handleOnline);
    const isOnline = navigator.onLine && !isSimulatedOffline;
    if (isOnline) {
      syncOfflineQueue();
    }
    return () => window.removeEventListener('online', handleOnline);
  }, [isSimulatedOffline]);

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

  const currentSimTime = isSimulatedMode ? globalSimTime : realTimeMins;
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
                setCheckOutTimes(prev => ({ ...prev, [userId]: currentSimTime }));
                // Trigger Spill-over
                const u = globalUsers.find(user => user.id === userId);
                if (u) {
                    useTaskStore.getState().handleSpillOver(userId, u.job_role_id);
                }
            }
            else if (state === 'meal') { 
                actionName = 'Salida a Comer'; 
                type = 'info'; 
                setMealStartTimes(prev => ({ ...prev, [userId]: currentSimTime }));
            }
            else if (prevState === 'meal' && state === 'active') { 
                actionName = 'Regreso de Comida'; 
                type = 'success'; 
                setMealEndTimes(prev => ({ ...prev, [userId]: currentSimTime }));
            }
            else if (state === 'short_break') { 
                actionName = 'Descanso Corto (Ley Silla)'; 
                type = 'info'; 
                setBreakStartTimes(prev => ({ ...prev, [userId]: currentSimTime }));
            }
            else if (prevState === 'short_break' && state === 'active') { 
                actionName = 'Fin de Descanso'; 
                type = 'success'; 
                setBreakEndTimes(prev => ({ ...prev, [userId]: currentSimTime }));
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
            else if (state === 'inactive') type = 'check_out';
            else if (prevState === 'meal' && state === 'active') type = 'meal_end';
            
            const startMins = shiftConfigs[userId]?.start ? parseInt(shiftConfigs[userId].start.split(':')[0])*60 + parseInt(shiftConfigs[userId].start.split(':')[1]) : 480;
             const isLate = type === 'check_in' && currentSimTime > startMins + (shiftConfigs[userId]?.tolerance ?? (timeBankConfigs.maxLateMinsAllowed ?? 15));
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
  const [showBreakSeatModal, setShowBreakSeatModal] = useState(false);
  const [showTempExitModal, setShowTempExitModal] = useState(false);
  const [showPanicModal, setShowPanicModal] = useState(false);
  const [isPanicActive, setIsPanicActive] = useState(false);
  const [showMealSwapModal, setShowMealSwapModal] = useState(false);
  const [isHandoverCompleted, setIsHandoverCompleted] = useState(false);

  // Alerta de GPS al salir del perímetro sin pase de salida
  const lastAlertSentRef = useRef<number | null>(null);

  useEffect(() => {
    if (clockState === 'active' && gpsStatus === 'success' && currentUser?.id) {
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
  }, [gpsDistance, clockState, gpsStatus, currentUser?.name, currentUser?.id, clockOpConfig.gpsAlertRangeMeters]);

  const [paseListaEmployees, setPaseListaEmployees] = useState([]);
  const [cashCount, setCashCount] = useState("");
  const [kioscoInput, setKioscoInput] = useState('');
  const [evalStars, setEvalStars] = useState(0);
  const [storeOpenLog, setStoreOpenLog] = useState<{time: string, type: 'normal'|'forzosa'} | null>(null);
  const [absenceReason, setAbsenceReason] = useState("");
  const [showEarlyDepartureModal, setShowEarlyDepartureModal] = useState(false);
  const [earlyDepartureReason, setEarlyDepartureReason] = useState("Enfermedad");
  const [isEarlyDepartureValidation, setIsEarlyDepartureValidation] = useState(false);
  const [isOvertimeUnlocked, setIsOvertimeUnlocked] = useState<Record<number, boolean>>({});
  const [isOvertimeValidation, setIsOvertimeValidation] = useState(false);
  const [isSimulatedHoliday, setIsSimulatedHoliday] = useState(() => localStorage.getItem('is_simulated_holiday') === 'true');
  const [isLateEntryValidation, setIsLateEntryValidation] = useState(false);
  const [contingencyLogs, setContingencyLogs] = useState<any[]>([]);
  const [contingencyUsed, setContingencyUsed] = useState<Record<number, boolean>>({});
  const [absentUsers, setAbsentUsers] = useState<Record<number, boolean>>({});
  const [lateUsers, setLateUsers] = useState<Record<number, boolean>>({});

  const [auditoryLogs, setAuditoryLogs] = useState([]);
  const [reportForm, setReportForm] = useState({ targetId: '', type: '', details: '' });

  const DIAS_SEMANA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
  const [currentDay, setCurrentDay] = [globalSimDay, setGlobalSimDay];
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
      await axiosInstance.post('/sync/clock', {
        user_id: userAId,
        date: todayStr,
        type: 'meal_swap',
        time: '00:00',
        details: `Swapped meal slots with user ${userBId}`
      });
      await axiosInstance.post('/sync/clock', {
        user_id: userBId,
        date: todayStr,
        type: 'meal_swap',
        time: '00:00',
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
        setLocalPendingBreakRequests(prev => ({
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
        setLocalPendingBreakRequests(prev => {
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
        setLocalPendingBreakRequests(prev => {
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

  const [showAlarmSettingsModal, setShowAlarmSettingsModal] = useState(false);
  const [pendingTasksBlocker, setPendingTasksBlocker] = useState(false);
  const [supervisorPin, setSupervisorPin] = useState('');
  const [supervisorQrToken, setSupervisorQrToken] = useState('');

  const [preShiftAlarmPlayed, setPreShiftAlarmPlayed] = useState(false);
  const [mealReminderAlarmPlayed, setMealReminderAlarmPlayed] = useState(false);
  const [leySillaAlarmPlayed, setLeySillaAlarmPlayed] = useState(false);
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
  useEffect(() => {
    if (storeStatus === 'open' && storeOpenSimTime !== null && !paseListaDone) {
      if (currentSimTime >= storeOpenSimTime && !showPaseListaModal) {
         if (Number(currentUser.id) === Number(activeEncargadoId) && activePushNotification?.type !== 'pase_lista') {
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
          const hierarchy = globalUsers.filter(u => u.esAperturador).sort((a,b) => a.jerarquiaLlaves - b.jerarquiaLlaves).map(u => u.id);
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

        if (isDelayed) {
          if (activePushNotification?.type !== 'tarea_retrasada' || !activePushNotification?.text.includes(myTask?.title || '')) {
            setActivePushNotification({
              type: 'tarea_retrasada',
              text: `🚨 Retraso en rutina: desarrolla "${myTask?.title || 'Tarea'}". Quedan ${remainingCount} tareas.`,
              action: () => {
                setActivePushNotification(null);
                setPhoneTab('tareas');
              }
            });
          }
        } else {
          // Mostrar aviso amigable si tiene tareas de rutina pendientes por desarrollar
          if (activePushNotification?.type !== 'tarea_siguiente' && activePushNotification?.type !== 'tarea_retrasada') {
            setActivePushNotification({
              type: 'tarea_siguiente',
              text: `📋 Siguiente tarea de tu rutina: "${myTask?.title || 'Tarea'}". (${remainingCount} pendientes).`,
              action: () => {
                setActivePushNotification(null);
                setPhoneTab('tareas');
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
        if (activePushNotification?.type !== 'tarea_retrasada') {
          const myTask = storeState.tasks.find(t => t.id === myAssignment.taskId);
          setActivePushNotification({
            type: 'tarea_retrasada',
            text: `🚨 Estás retrasado en tu tarea: ${myTask?.title || 'Tarea Actual'}. ¡Apresúrate!`,
            action: () => {
              setActivePushNotification(null);
              setPhoneTab('tareas');
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
           const hierarchy = globalUsers.filter(u => u.esAperturador).sort((a,b) => a.jerarquiaLlaves - b.jerarquiaLlaves).map(u => u.id);
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
       const hierarchy = globalUsers.filter(u => u.esAperturador).sort((a,b) => a.jerarquiaLlaves - b.jerarquiaLlaves).map(u => u.id);
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
         } catch (e) {
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
    if (conAmnistia) setAmnistiaActive(true);
    
    const empleadosEnPuerta = globalUsers.filter(u => u.id !== currentUser.id && (globalClockStates[u.id] === 'waiting_room' || globalClockStates[u.id] === 'waiting')).map((u) => {
      const arrTime = globalArrivalTimes[u.id] || 0;
      const shiftStartMins = parseTimeToMins(shiftConfigs[u.id]?.start || '09:00');
      const toleranceEndMins = shiftStartMins + (timeBankConfigs.maxLateMinsAllowed ?? 15);
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
      const isOnline = navigator.onLine && !isSimulatedOffline;
      if (!isOnline) {
          const currentGps = gpsStatus === 'success' ? gpsCoordinates : null;
          await offlineDb.savePunch({
              userId: currentUser?.id,
              type,
              time: formattedTime,
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
          else if (type === 'temp_exit_start') newState = 'temp_exit';
          else if (type === 'temp_exit_end') newState = 'active';
          else if (type === 'check_out') newState = 'inactive';
          else if (type === 'contingency') newState = 'contingency';
          
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
          else if (type === 'temp_exit_start') newState = 'temp_exit';
          else if (type === 'temp_exit_end') newState = 'active';
          else if (type === 'check_out') newState = 'inactive';
          else if (type === 'contingency') newState = 'contingency';

          updateClockState(currentUser.id, newState);
          return {};
      }
      try {
         const response = await axiosInstance.post('/clock/punch', { 
             user_id: currentUser.id, 
             type, 
             time: formattedTime, // En produccion esto debe omitirse para que el servidor use now()
             details: { note: details } 
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
             else if (type === 'temp_exit_start') newState = 'temp_exit';
             else if (type === 'temp_exit_end') newState = 'active';
             else if (type === 'check_out') newState = 'inactive';
             else if (type === 'contingency') newState = 'contingency';

             updateClockState(currentUser.id, newState);
         }
         return data;
      } catch (e) {
         console.error(e);
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
    const toleranceEndMins = shiftStartMins + (timeBankConfigs.maxLateMinsAllowed ?? 15);
  


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
      
      if (actionText === 'Registrar Entrada' || actionText === 'Registrar Entrada Manual') {
        const res = await syncToDB('check_in');
        if (res && res.entry && res.entry.late_type) {
            showCustomAlert(`🟢 Fichaje registrado. Se detectó: ${res.entry.late_type} (${res.entry.penalty_applied}% descuento)`);
            if (['Encargado Titular', 'Segundo Encargado', 'Supervisor'].includes(currentUser.role)) {
                setShowJustificanteModal(true);
            }
        } else {
            showCustomAlert(`🟢 Fichaje registrado a tiempo.`);
        }
      } else if (actionText === 'Abrir Tienda') {
        handleOpenStore(false);
      } else if (actionText === 'Iniciar Horario de Comida') {
        await syncToDB('meal_start');
      } else if (actionText === 'Regresar de Comida') {
        const res = await syncToDB('meal_end');
        if (!res?.offline) {
          showCustomAlert('🏃 Has regresado de comer.');
        }
      } else if (actionText === 'Descanso Ley Silla') {
        await handleBreakStart();
      } else if (actionText === 'Regresar de Descanso') {
        await handleBreakEnd();
      } else if (actionText === 'Entrega de Turno') {
        handleHandoverStart();
      } else if (actionText === 'Registrar Salida') {
        handleClockOutRequest();
      } else if (actionText === 'Registrar Reingreso') {
        await endTempExit();
      }
    }
  };

  const handleBreakEnd = async () => {
    const res = await syncToDB('break_end');
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
      if (isPro) {
        setShowBreakSeatModal(true);
      } else {
        const res = await syncToDB('break_start');
        if (!res?.offline) {
          showCustomAlert('🧘 Has iniciado tu descanso (Ley Silla - Básico).');
        }
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

      const newRetardos = Number(localStorage.getItem('user_retardos_' + currentUser.id) || 0) + 1;
      localStorage.setItem('user_retardos_' + currentUser.id, String(newRetardos));

      useAppStore.getState().addMatrixEvent(
        '🔑 Entrada Tardía Autorizada',
        `Se autorizó la entrada tardía de ${currentUser.name} tras vencer la tolerancia mediante ${isPro ? 'QR Dinámico' : 'PIN de Supervisor'}. Retardos acumulados este mes: ${newRetardos}.`,
        'warning',
        currentUser.id
      );

      setPendingTasksBlocker(false);
      setSupervisorQrToken('');
      setSupervisorPin('');
      setIsLateEntryValidation(false);

      if (newRetardos >= 3) {
        showCustomAlert(`⚠️ Entrada autorizada con penalización. Has acumulado ${newRetardos} retardos. Tu checador queda BLOQUEADO hasta completar el curso obligatorio de Puntualidad en la Academia.`);
      } else {
        showCustomAlert(`✅ Entrada autorizada con penalización. Has acumulado ${newRetardos} retardos este mes.`);
      }

      await syncToDB('check_in');
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

  const processFinalClockOut = async (delegatedTo = null, note = '') => {
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
    const hasCheckedIn = checkInTimes[currentUser?.id] !== undefined;
    const retardosCount = Number(localStorage.getItem('user_retardos_' + currentUser?.id) || 0);
    const hasPunctualityBlock = retardosCount >= 3;

    if (!hasCheckedIn && clockState === 'inactive' && hasPunctualityBlock) {
      return {
        text: '🔒 Fichaje Bloqueado',
        bg: 'bg-slate-800 text-slate-400 border border-slate-700 cursor-not-allowed text-xs font-black shadow-none',
        icon: '🔒',
        disabled: true,
        subtext: 'Acumulaste 3 retardos. Completa el curso de Puntualidad en la Academia.'
      };
    }

    if (isSimulatedHoliday && !hasCheckedIn && clockState === 'inactive' && !isOvertimeUnlocked[currentUser?.id]) {
      return {
        text: 'DÍA FERIADO (LFT)',
        bg: 'bg-indigo-50 border border-indigo-200 text-indigo-700 cursor-not-allowed font-extrabold shadow-sm',
        icon: '📅',
        disabled: true,
        subtext: 'Natalicio de Benito Juárez. Descanso de Ley.'
      };
    }

    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay && !isOvertimeUnlocked[currentUser?.id];
    if (isRestDay) return { text: 'DÍA DE DESCANSO', bg: 'bg-slate-300 text-slate-500 cursor-not-allowed', icon: '🌴', disabled: true };

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
          responsibleId = firstActive.employee_id;
        }
      } catch {}
    }
    const responsibleUser = globalUsers.find((u: any) => u.id === responsibleId) || { name: 'Encargado' };

    const shiftStartStr = shiftConfigs[currentUser?.id]?.start || '08:30';
    const shiftStartMins = parseTimeToMins(shiftStartStr);

    const isLate = currentSimTime > (shiftStartMins + (timeBankConfigs.maxLateMinsAllowed ?? 15));

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
          const travelTime = clockOpConfig.suplente_travel_time_mins || 45;
          const managerDeadlineMins = shiftStartMins - travelTime;
          
          if (currentSimTime < managerDeadlineMins && features.allow_manager_incidences !== false) {
            return {
              text: '⚠️ Reportar Ausencia/Retardo',
              bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)] animate-pulse',
              icon: '⚠️',
              isIncidenceReport: true,
              isOpeningManager: true,
              subtext: `🗝️ Límite de encargado: ${formatTimeMins(managerDeadlineMins)}`
            };
          }
        } else {
          const employeeDeadlineMins = shiftStartMins - 30;
          if (currentSimTime < employeeDeadlineMins && features.allow_employee_incidences !== false) {
            return {
              text: '⚠️ Reportar Ausencia/Retardo',
              bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)]',
              icon: '⚠️',
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
            text: '📍 Ya llegué (Cerca de área)',
            bg: 'bg-emerald-600 hover:bg-emerald-700 text-white font-bold shadow-[0_0_20px_rgba(16,185,129,0.3)] animate-pulse',
            icon: '📍',
            isProximityCheck: true,
            subtext: 'Registrar llegada anticipada para asegurar amnistía.'
          };
        } else {
          return {
            text: '📍 Cerca de Sucursal',
            bg: 'bg-slate-200 text-slate-400 cursor-not-allowed',
            icon: '🔒',
            disabled: true,
            subtext: `Fuera de geocerca (${Math.round(gpsDistance)}m)`
          };
        }
      }
    }

    // Límite de retardo ordinario vencido
    if (!hasCheckedIn && isLate && clockState === 'inactive') {
      return {
        text: '🔒 Acceso Bloqueado',
        bg: 'bg-slate-700 text-slate-350 hover:bg-slate-800 text-white font-extrabold shadow-[0_0_20px_rgba(100,116,139,0.3)] animate-pulse',
        icon: '🔒',
        isQrUnlockRequired: true,
        subtext: 'Tolerancia vencida. Requiere desbloqueo QR de supervisor.'
      };
    }

    if (!isWithinPerimeter && (clockState === 'inactive' || clockState === 'waiting_room')) {
      const isResponsibleForOpening = isOpeningPremium && storeStatus === 'closed' && Number(currentUser?.id) === Number(responsibleId);

      if (isResponsibleForOpening) {
        return {
          text: 'Reportar Incidencia',
          bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)]',
          icon: '⚠️',
          isIncidenceReport: true,
          isResponsibleOutside: true,
          subtext: '🗝️ Eres el responsable de apertura de hoy. Dirígete a la sucursal para activar el botón.'
        };
      }

      return {
        text: 'Reportar Incidencia',
        bg: 'bg-amber-600 hover:bg-amber-700 text-white font-extrabold shadow-[0_0_20px_rgba(217,119,6,0.3)]',
        icon: '⚠️',
        isIncidenceReport: true
      };
    }

    // ----------------------------------------------------
    // VENTANA 3: Notificar Tienda Cerrada (8:30 AM - 8:50 AM)
    // ----------------------------------------------------
    if (storeStatus === 'closed') {
      const isOpeningManager = Number(currentUser?.id) === Number(responsibleId);
      
      if (!isOpeningManager) {
        if (!isPro) {
          return {
            text: '⏳ Esperando Apertura',
            bg: 'bg-slate-200 text-slate-400 cursor-not-allowed',
            icon: '⏳',
            disabled: true,
            subtext: `Apertura por: ${responsibleUser.name.split(' ')[0]}`
          };
        } else {
          if (currentSimTime >= shiftStartMins && currentSimTime <= shiftStartMins + 20 && features.allow_store_closed_report !== false) {
            const hasReported = localStorage.getItem(`reported_closed_${currentDay}_${currentUser?.id}`) === 'true';
            if (hasReported) {
              return {
                text: '⏳ Esperando Apertura',
                bg: 'bg-slate-300 text-slate-500 cursor-not-allowed',
                icon: '⏳',
                disabled: true,
                subtext: 'Alerta de tienda cerrada ya enviada al administrador.'
              };
            }
            return {
              text: '🚨 Notificar Tienda Cerrada',
              bg: 'bg-orange-500 hover:bg-orange-600 text-white font-extrabold shadow-[0_0_20px_rgba(249,115,22,0.3)] animate-pulse',
              icon: '🚨',
              isReportStoreClosed: true,
              subtext: `Encargado: ${responsibleUser.name.split(' ')[0]}`
            };
          }
          return {
            text: '⏳ Esperando Apertura',
            bg: 'bg-slate-200 text-slate-400 cursor-not-allowed',
            icon: '⏳',
            disabled: true,
            subtext: `Apertura por: ${responsibleUser.name.split(' ')[0]}`
          };
        }
      }
    }

    if (isOpeningPremium && storeStatus === 'closed') {
      if (Number(currentUser.id) === Number(responsibleId)) {
        return { 
          text: 'Abrir Tienda', 
          bg: 'bg-violet-650 hover:bg-violet-700 text-white font-black shadow-[0_0_25px_rgba(139,92,246,0.35)] animate-pulse', 
          icon: '🗝️',
          isOpeningActive: true 
        };
      }
    }

    if (Number(currentUser.id) === Number(activeEncargadoId) && storeStatus === 'closed') {
      return { text: 'Abrir Tienda', bg: 'bg-indigo-600 hover:bg-indigo-700', icon: '🗝️' };
    }

    if (clockState === 'inactive' || clockState === 'waiting_room') {
      return { text: 'Registrar Entrada', bg: 'bg-slate-800 hover:bg-slate-900', icon: '🟢' };
    }

    if (clockState === 'active') {
      const hasTakenMeal = mealStartTimes[currentUser.id] !== undefined;
      if (!hasTakenMeal) {
        const mealReservationUnlocked = useAppStore.getState().isFeatureUnlocked('meal_reservation');
        if (isPro && featureFlags.comidas && mealReservationUnlocked && features.enable_meal_slots !== false) {
          const mySlots = userReservedMealSlots[currentUser.id] || [];
          if (mySlots.length > 0) {
             const [sh, sm] = mySlots[0].split(' ')[0].split(':');
             const isPm = mySlots[0].includes('PM');
             let hour = parseInt(sh);
             if (isPm && hour !== 12) hour += 12;
             if (!isPm && hour === 12) hour = 0;
             const firstSlotMins = hour * 60 + parseInt(sm);
             
             if (currentSimTime < firstSlotMins - 5) {
                return { text: 'Iniciar Comida', bg: 'bg-slate-200 text-slate-400 cursor-not-allowed opacity-60', icon: '🍔', disabled: true, subtext: `Reserva programada: ${mySlots[0]}` };
             }
          } else {
             return { 
                text: 'Reserva tu horario primero', 
                bg: 'bg-amber-600/20 text-amber-500 border border-amber-500/30 hover:bg-amber-600/30 font-bold shadow-md cursor-pointer animate-pulse', 
                icon: '🍔', 
                isMealReservationAlert: true,
                subtext: 'Haz clic para seleccionar tu slot en el comedor.'
             };
          }
        }
        return { text: 'Iniciar Comida', bg: 'bg-amber-500 hover:bg-amber-600 text-amber-950 font-bold shadow-[0_0_20px_rgba(245,158,11,0.25)]', icon: '🍔' };
      }

      const hasReturnedFromMeal = mealEndTimes[currentUser.id] !== undefined;
      const hasTakenBreak = breakStartTimes[currentUser.id] !== undefined;
      if (isPro && hasReturnedFromMeal && !hasTakenBreak && features.enable_ley_silla !== false) {
        return { 
          text: 'Descanso', 
          bg: 'bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow-[0_0_20px_rgba(147,51,234,0.3)] animate-pulse', 
          icon: '🧘' 
        };
      }

      const isManager = ['Encargado Titular', 'Segundo Encargado', 'Supervisor', 'Gerente'].includes(currentUser.role);
      if (isPro && isManager && !isHandoverCompleted) {
        return { 
          text: 'Entrega de Turno', 
          bg: 'bg-cyan-600 hover:bg-cyan-700 text-white font-bold shadow-[0_0_20px_rgba(8,145,178,0.3)] animate-pulse', 
          icon: '🗝️' 
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
            disabled: true,
            subtext: `Salida disponible a las ${formatTimeMins(currentShiftEndMins - 10)}`
          };
        }
      }

      return { 
        text: 'Registrar Salida', 
        bg: 'bg-rose-600 hover:bg-rose-700 text-white font-black shadow-[0_0_22px_rgba(225,29,72,0.35)]', 
        icon: '🚪' 
      };
    }

    if (clockState === 'meal') {
      return { text: 'Terminar Comida', bg: 'bg-emerald-500 hover:bg-emerald-600 text-white font-bold shadow-[0_0_20px_rgba(16,185,129,0.35)]', icon: '🏃' };
    }
    if (clockState === 'short_break') {
      return { text: 'Terminar Descanso', bg: 'bg-indigo-650 hover:bg-indigo-700 text-white font-bold shadow-[0_0_20px_rgba(79,70,229,0.35)]', icon: '🏃' };
    }
    if (clockState === 'temp_exit') {
      return { text: 'Registrar Reingreso', bg: 'bg-teal-500 hover:bg-teal-600 text-white font-bold shadow-[0_0_20px_rgba(20,184,166,0.35)]', icon: '🚶' };
    }
    if (clockState === 'absent') {
      return { text: 'Ausencia Registrada', bg: 'bg-rose-100 text-rose-500 cursor-not-allowed', icon: '🚷', disabled: true };
    }
    if (clockState === 'finished') {
      return { text: 'Jornada Finalizada', bg: 'bg-slate-200 text-slate-400 cursor-not-allowed', icon: '🏁', disabled: true };
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
  const [pendingKeyTransfers, setPendingKeyTransfers] = useState<any[]>([]);

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

  // 4. Transferencia de Llaves / Cierre
  const initiateKeyTransfer = async (receiverId: number, notes: string) => {
    if (isSandboxMode) {
      setDesignatedCloserId(receiverId);
      showCustomAlert("✅ Cierre transferido con éxito (Modo Sandbox).");
      return true;
    }
    try {
      await axiosInstance.post('/key-transfers', {
        receiver_id: receiverId,
        notes: notes
      });
      showCustomAlert("✅ Solicitud de transferencia enviada. Tu compañero debe aceptarla.");
      return true;
    } catch (e) {
      console.error("Error al transferir cierre:", e);
      showCustomAlert(e.response?.data?.error || "Error al procesar la transferencia.");
      return false;
    }
  };

  const checkPendingKeyTransfers = async () => {
    if (isSandboxMode) return;
    try {
      const res = await axiosInstance.get('/key-transfers/pending');
      if (res.data) {
        setPendingKeyTransfers(res.data);
      }
    } catch (e) {
      console.error("Error al cargar transferencias pendientes:", e);
    }
  };

  const respondToKeyTransfer = async (transferId: number, status: 'accepted' | 'rejected') => {
    if (isSandboxMode) return;
    try {
      const res = await axiosInstance.post(`/key-transfers/${transferId}/respond`, { status });
      if (res.data) {
        showCustomAlert(res.data.message || "Respuesta procesada.");
        fetchState();
        checkPendingKeyTransfers();
      }
    } catch (e) {
      console.error("Error al responder transferencia:", e);
      showCustomAlert("Error al responder.");
    }
  };

  // 5. Alerta de Abandono (Huida de tienda)
  const reportAbandonment = async () => {
    if (isSandboxMode) {
      showCustomAlert("⚠️ Alerta Crítica (Sandbox): Abandonaste la tienda sin transferir. Se notificó a Gerencia.");
      return;
    }
    try {
      await axiosInstance.post('/security/abandonment');
      showCustomAlert("⚠️ Alerta de abandono enviada a Gerencia y RRHH por pérdida de red.");
    } catch (e) {
      console.error("Error al reportar abandono:", e);
    }
  };

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
    isOpeningPremium,

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
    setShiftConfigs,
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
