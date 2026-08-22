import { useState, useEffect } from 'react';
import axiosInstance from '../../../lib/axios';
import { useAppStore } from '../../../store/useAppStore';

interface UseStoreOpeningParams {
  currentUser: any;
  showCustomAlert: (msg: string) => void;
  isSimulatedOffline: boolean;
  saveOfflineContingency: (item: { userId: number; reason: string; declaredAt: string }) => void;
  syncToDB: (type: string, isLate?: boolean, lateMinutes?: number, details?: string) => Promise<any>;
  isSimulator?: boolean;
}

export function useStoreOpening({
  currentUser,
  showCustomAlert,
  isSimulatedOffline,
  saveOfflineContingency,
  syncToDB,
  isSimulator = false,
}: UseStoreOpeningParams) {
  const isSandboxMode = useAppStore(s => s.isSandboxMode);
  const systemSettings = useAppStore(s => s.systemSettings);
  const globalSimTime = useAppStore(s => s.globalSimTime);
  const globalUsers = useAppStore(s => s.globalUsers);
  const setStoreStatus = useAppStore(s => s.setStoreStatus);
  const fetchState = useAppStore(s => s.fetchState);

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

  // NUEVO (estado #22 de la matriz — "Checklist de Cierre Seguro"): espejo del checklist de apertura
  // de arriba, misma key sin scope de fecha para mantener paridad de comportamiento con su par.
  // (2026-08-22) Con scope de FECHA: la bandera nunca se limpiaba, así que el checklist de ayer
  // contaba como el de hoy, el modal no salía y el servidor rechazaba la salida sin remedio.
  const [closingChecklistCompleted, setClosingChecklistCompleted] = useState(() => {
    return localStorage.getItem('closing_checklist_completed') === new Date().toLocaleDateString('sv-SE');
  });
  // R100 (merge FE, spec §3/§5 "Llamar a Encargado de Llaves"): teléfono del responsable de
  // apertura. El backend lo manda SÓLO si quien pregunta es portador de llaves (gate server-side);
  // si llega null, el botón simplemente no se muestra.
  const [responsiblePhone, setResponsiblePhone] = useState<string | null>(null);
  const [showClosingChecklistModal, setShowClosingChecklistModal] = useState(false);
  const [closingChecklistSubmitting, setClosingChecklistSubmitting] = useState(false);

  const getSimTimeStr = (mins: number) => {
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:00`;
  };

  useEffect(() => {
    const syncApertura = async () => {
      const isOpeningPremium = useAppStore.getState().isFeatureUnlocked('store_opening');
      if (!isOpeningPremium) return;

      // Auditoría Matrix (2026-07-22): antes este efecto tenía `globalSimTime` en su arreglo de
      // dependencias, y como la Máquina del Tiempo lo cambia cada ~1s durante Auto-Run, el efecto
      // se desmontaba y volvía a montar en cada tick — el `setInterval` de 5s nunca llegaba a
      // completar un ciclo y `syncApertura()` (la llamada inmediata de la línea de abajo) terminaba
      // disparándose ~1 vez por segundo por cada celular de la Matrix en vez de 1 vez cada 5s. Con
      // varios empleados en pantalla eso multiplicaba las peticiones a /store-opening/today. Se
      // saca `globalSimTime` de las deps y se lee aquí adentro, fresco en cada llamada, vía
      // useAppStore.getState() (mismo patrón que ya usa el resto de este archivo).
      const currentSimTimeNow = useAppStore.getState().globalSimTime;

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
            current_responsible_employee_id: firstActive ? (firstActive.resolved_user_id ?? firstActive.employee_id) : 1,
            status: 'pending',
          };
          localStorage.setItem('store_daily_opening_status', JSON.stringify(statusObj));
        }

        if (statusObj.status !== 'opened' && statusObj.status !== 'failed' && statusObj.status !== 'closed_reported_by_employees') {
          if (currentSimTimeNow < windowStartMins) {
            statusObj.status = 'pending';
          } else if (currentSimTimeNow >= windowStartMins && currentSimTimeNow < statusObj.report_deadline_mins) {
            statusObj.status = 'active_window';
          } else if (currentSimTimeNow >= statusObj.report_deadline_mins) {
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

              // §29: preferir resolved_user_id (users.id, ya resuelto por backend) sobre el employee_id
              // crudo (employees.id); se conserva employee_id como resguardo para datos viejos en caché.
              const currentAss = assList.find((a: any) => (a.resolved_user_id ?? a.employee_id) === statusObj.current_responsible_employee_id);
              const currentOrder = currentAss ? currentAss.priority_order : 1;

              const nextAss = assList
                .filter((a: any) => a.is_active && a.priority_order > currentOrder)
                .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];

              if (nextAss) {
                statusObj.current_responsible_employee_id = nextAss.resolved_user_id ?? nextAss.employee_id;
                statusObj.status = 'transferred';
                statusObj.report_deadline_mins = currentSimTimeNow + (openingSettings.absence_late_report_window_minutes || 5);
                const nextName = globalUsers.find((u: any) => u.id === Number(nextAss.resolved_user_id ?? nextAss.employee_id))?.name || 'suplente';
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
            params: { simTime: getSimTimeStr(currentSimTimeNow) }
          });
          setOpeningStatus(res.data.status);
          // R100: sólo llega a portadores de llaves (gate server-side).
          setResponsiblePhone(res.data.responsible_phone ?? null);
        } catch (e) {
          console.error(e);
        }
      }
    };

    syncApertura();
    const interval = setInterval(syncApertura, 5000);
    return () => clearInterval(interval);
  }, [isSandboxMode]);

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

      // syncToDB calcula la hora internamente (getSimTimeStr/getRealHms); el segundo parámetro
      // real es `isLate` (boolean, hoy sin uso dentro de la función), no una hora — antes se le
      // pasaba aquí un string de hora por error (encontrado al reactivar el chequeo de tipos).
      await syncToDB('check_in');

      showCustomAlert("🗝️ Apertura de tienda registrada con éxito y registro de entrada completado.");
    } else {
      try {
        const res = await axiosInstance.post('/store-opening/open-and-clock-in', {
          simTime: getSimTimeStr(globalSimTime),
          user_id: currentUser?.id,
          is_simulator: isSimulator
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

  // NUEVO (estado #9 — Apertura de Emergencia): consume POST /clock/emergency-open
  // (docs/BACKEND_INTERFACES.md §3). El backend YA hace el check_in del solicitante como parte
  // de esta llamada (confirmado en las notas de implementación del 2026-07-21 del propio documento),
  // así que aquí NO se debe llamar syncToDB('check_in') por separado — se duplicaría el fichaje.
  //
  // ADVERTENCIA CONOCIDA: al día de este cambio no existe todavía un endpoint PUT /me/pin para que
  // los empleados configuren su PIN. La validación de testigos en el backend siempre fallará con
  // "PIN de testigo incorrecto." hasta que ese endpoint exista (o se semillen PINs manualmente).
  // La UI queda funcionalmente completa y lista para cuando ese endpoint se agregue.
  const [showEmergencyOpenModal, setShowEmergencyOpenModal] = useState(false);
  const [emergencyOpenSubmitting, setEmergencyOpenSubmitting] = useState(false);
  const handleEmergencyStoreOpen = async (witness1Id: number, witness1Pin: string, witness2Id: number, witness2Pin: string) => {
    if (!witness1Id || !witness2Id || !witness1Pin || !witness2Pin) {
      showCustomAlert('⚠️ Completa los datos de ambos testigos para continuar.');
      return;
    }
    if (Number(witness1Id) === Number(witness2Id)) {
      showCustomAlert('⚠️ Los testigos deben ser dos empleados distintos.');
      return;
    }

    setEmergencyOpenSubmitting(true);
    try {
      if (isSandboxMode) {
        // Simulación local: no valida PIN real, solo demuestra el flujo completo.
        const updated = {
          ...openingStatus,
          status: 'opened',
          opened_by_employee_id: currentUser.id,
          opened_at: new Date().toISOString()
        };
        setOpeningStatus(updated);
        localStorage.setItem('store_daily_opening_status', JSON.stringify(updated));
        setStoreStatus('open');
        await syncToDB('check_in');

        useAppStore.getState().addMatrixEvent(
          '⚠️ Apertura de Emergencia (Sandbox)',
          `${currentUser.name} abrió la tienda en modo emergencia con testigos ${witness1Id} y ${witness2Id}.`,
          'warning',
          currentUser.id
        );
        showCustomAlert('⚠️ Apertura de emergencia autorizada (Sandbox).');
        setShowEmergencyOpenModal(false);
        return;
      }

      const res = await axiosInstance.post('/clock/emergency-open', {
        requester_id: currentUser.id,
        witness_1_id: witness1Id,
        witness_1_pin: witness1Pin,
        witness_2_id: witness2Id,
        witness_2_pin: witness2Pin,
        store_id: 1
      });
      if (res.data?.success) {
        if (res.data.status) setOpeningStatus(res.data.status);
        setStoreStatus('open');
        await fetchState();
        showCustomAlert(res.data.message || '⚠️ Apertura de emergencia autorizada.');
        setShowEmergencyOpenModal(false);
      } else {
        showCustomAlert(res.data?.message || 'No se pudo autorizar la apertura de emergencia.');
      }
    } catch (e: any) {
      showCustomAlert(e.response?.data?.message || 'Error al procesar la apertura de emergencia.');
    } finally {
      setEmergencyOpenSubmitting(false);
    }
  };

  // NUEVO: configuración de PIN de seguridad (docs/BACKEND_INTERFACES.md §10, decisión final del
  // 2026-07-21). Consume PUT /me/security-pin. Distinto de currentUser.pin_code (que es el código de
  // invitación de un solo uso del onboarding, y también reutilizado como PIN de bloqueo de sesión local
  // en RelojVisual.tsx — NO tocar ese, es un concepto de seguridad separado). El security_pin es lo que
  // valida el backend en la co-validación de 2 testigos de /clock/emergency-open.
  const [securityPinSubmitting, setSecurityPinSubmitting] = useState(false);
  const handleUpdateSecurityPin = async (currentPassword: string, newPin: string, confirmPin: string) => {
    if (!/^\d{4,6}$/.test(newPin)) {
      showCustomAlert('⚠️ El PIN debe ser numérico, de 4 a 6 dígitos.');
      return false;
    }
    if (newPin !== confirmPin) {
      showCustomAlert('⚠️ Los PIN no coinciden.');
      return false;
    }
    if (!currentPassword) {
      showCustomAlert('⚠️ Ingresa tu contraseña actual para confirmar el cambio.');
      return false;
    }

    setSecurityPinSubmitting(true);
    try {
      if (isSandboxMode) {
        showCustomAlert('🔐 PIN de seguridad actualizado (Sandbox).');
        return true;
      }
      const res = await axiosInstance.put('/me/security-pin', {
        current_password: currentPassword,
        pin: newPin
      });
      showCustomAlert(res.data?.message || '🔐 PIN de seguridad actualizado.');
      return true;
    } catch (e: any) {
      showCustomAlert(e.response?.data?.message || 'Error al actualizar el PIN de seguridad.');
      return false;
    } finally {
      setSecurityPinSubmitting(false);
    }
  };

  // NUEVO (estado #10/#15 — Declarar Eventualidad / Modo Contingencia Activo): consume
  // POST /clock/declare-contingency (docs/BACKEND_INTERFACES.md §4).
  // Nota de diseño: este handler es para el camino EN LÍNEA (dispositivo con datos/señal aunque
  // sin luz eléctrica en la sucursal). El caso verdaderamente sin internet debe pasar por la cola
  // offline de IndexedDB igual que los demás punches — ver syncToDB(), que ya detecta
  // navigator.onLine y encola localmente. No se implementa aquí offline_stamp (HMAC) todavía:
  // eso es una pieza aparte (ver tarea de firma HMAC offline) que aún no se ha construido en el
  // cliente; declarar sin firma funciona porque esta llamada va autenticada por Sanctum en vivo.
  const [showContingencyModal, setShowContingencyModal] = useState(false);
  const [contingencySubmitting, setContingencySubmitting] = useState(false);
  const [activeContingency, setActiveContingency] = useState<{ reason: string; contingency_id?: number } | null>(() => {
    try {
      const saved = localStorage.getItem('active_contingency_' + new Date().toDateString());
      return saved ? JSON.parse(saved) : null;
    } catch {
      return null;
    }
  });

  const handleContingencyDeclaration = async (reason: 'no_power' | 'no_internet' | 'no_power_and_internet') => {
    setContingencySubmitting(true);
    try {
      const isOnline = navigator.onLine && !isSimulatedOffline;

      if (isSandboxMode) {
        const record = { reason, contingency_id: Date.now() };
        setActiveContingency(record);
        localStorage.setItem('active_contingency_' + new Date().toDateString(), JSON.stringify(record));
        useAppStore.getState().addMatrixEvent(
          '⚡ Contingencia Declarada (Sandbox)',
          `${currentUser.name} declaró eventualidad: ${reason}.`,
          'warning',
          currentUser.id
        );
        showCustomAlert('⚡ Contingencia declarada. Jornada protegida al 100% (Sandbox).');
        setShowContingencyModal(false);
        return;
      }

      if (!isOnline) {
        // BUG FIX: antes esto se guardaba en la MISMA cola de punches de offlineDb con
        // type: 'contingency_declare' — un tipo que /clock/punch-batch no reconoce
        // (Rule::in(ClockService::ALLOWED_TYPES) lo rechaza). Como Laravel valida el batch
        // COMPLETO de una sola vez, un solo ítem así habría tumbado la sincronización de
        // TODOS los fichajes legítimos en la misma cola. Ahora usa su propia cola dedicada
        // (localStorage, bajo volumen esperado: como mucho una declaración por usuario por día),
        // sincronizada por separado contra /clock/declare-contingency, no contra punch-batch.
        saveOfflineContingency({ userId: currentUser.id, reason, declaredAt: new Date().toISOString() });
        showCustomAlert('📡 Contingencia guardada localmente. Se sincronizará al recuperar conexión.');
        setShowContingencyModal(false);
        return;
      }

      const res = await axiosInstance.post('/clock/declare-contingency', {
        user_id: currentUser.id,
        reason,
        declared_at: new Date().toISOString()
      });
      if (res.data?.success) {
        const record = { reason, contingency_id: res.data.contingency_id };
        setActiveContingency(record);
        localStorage.setItem('active_contingency_' + new Date().toDateString(), JSON.stringify(record));
        showCustomAlert(res.data.message || '⚡ Contingencia declarada. Jornada protegida al 100% conforme LFT.');
        setShowContingencyModal(false);
      } else {
        showCustomAlert(res.data?.message || 'No se pudo declarar la contingencia.');
      }
    } catch (e: any) {
      showCustomAlert(e.response?.data?.message || 'Error al declarar la contingencia.');
    } finally {
      setContingencySubmitting(false);
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

      // §29: preferir resolved_user_id sobre employee_id crudo (ver nota en el mismo hook, arriba).
      const currentAss = ass.find((a: any) => (a.resolved_user_id ?? a.employee_id) === currentUser.id);
      const currentOrder = currentAss ? currentAss.priority_order : 1;

      const nextAss = ass
        .filter((a: any) => a.is_active && a.priority_order > currentOrder)
        .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];

      const updated = { ...openingStatus };
      if (nextAss) {
        updated.current_responsible_employee_id = nextAss.resolved_user_id ?? nextAss.employee_id;
        updated.status = 'transferred';
        updated.report_deadline_mins = globalSimTime + (openingSettings.absence_late_report_window_minutes || 5);
        const nextName = globalUsers.find((u: any) => u.id === Number(nextAss.resolved_user_id ?? nextAss.employee_id))?.name || 'suplente';
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
          simTime: getSimTimeStr(globalSimTime),
          user_id: currentUser?.id
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

        // §29: preferir resolved_user_id sobre employee_id crudo (ver nota en el mismo hook, arriba).
        const currentAss = ass.find((a: any) => (a.resolved_user_id ?? a.employee_id) === currentUser.id);
        const currentOrder = currentAss ? currentAss.priority_order : 1;

        const nextAss = ass
          .filter((a: any) => a.is_active && a.priority_order > currentOrder)
          .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];

        const updated = { ...openingStatus };
        if (nextAss) {
          updated.current_responsible_employee_id = nextAss.resolved_user_id ?? nextAss.employee_id;
          updated.status = 'transferred';
          updated.report_deadline_mins = globalSimTime + (openingSettings.absence_late_report_window_minutes || 5);
          const nextName = globalUsers.find((u: any) => u.id === Number(nextAss.resolved_user_id ?? nextAss.employee_id))?.name || 'suplente';
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
          simTime: getSimTimeStr(globalSimTime),
          user_id: currentUser?.id
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

  return {
    openingSettings, setOpeningSettings,
    openingStatus, setOpeningStatus,
    responsiblePhone,
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
  };
}
