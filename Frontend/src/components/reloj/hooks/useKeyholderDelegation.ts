import { useState, useEffect } from 'react';
import axiosInstance from '../../../lib/axios';
import { useAppStore } from '../../../store/useAppStore';

interface UseKeyholderDelegationParams {
  globalUsers: any[];
  currentUser: any;
  showCustomAlert: (msg: string) => void;
  setDesignatedCloserId: (id: number) => void;
  // processFinalClockOut vive en useClockEngine.tsx (es lógica central que calcula la salida final
  // y depende de muchas otras piezas del motor). Se recibe aquí como un proxy estable — ver el
  // patrón de useRef en useClockEngine.tsx — para poder llamarlo sin importar el orden real de
  // declaración entre ambos.
  processFinalClockOut: (delegatedTo?: number | null, note?: string) => Promise<void>;
}

// Extraído de useClockEngine.tsx (refactor Jul 2026): agrupa todo lo relacionado con "quién tiene
// llaves" — el cálculo de portadores activos, la delegación de llaves al terminar el turno (estado
// que bloquea la salida hasta elegir un suplente), el aviso proactivo al siguiente suplente, y la
// transferencia de llaves en vivo entre dos titulares (endpoints /key-transfers/*). Misma lógica y
// nombres que antes, solo reubicados.
export function useKeyholderDelegation({
  globalUsers,
  currentUser,
  showCustomAlert,
  setDesignatedCloserId,
  processFinalClockOut,
}: UseKeyholderDelegationParams) {
  const isSandboxMode = useAppStore(s => s.isSandboxMode);
  const fetchState = useAppStore(s => s.fetchState);
  // NUEVO (2026-07-21): 'keys_control' existía en los arrays de allowedFeatures de useAppStore.ts
  // desde hace tiempo, pero ningún componente lo consultaba — la "Entrega de Turno" (estado #21)
  // usaba en su lugar un chequeo de tier hardcodeado (isPro) en useClockEngine.tsx, y la
  // transferencia de llaves en vivo (initiateKeyTransfer/respondToKeyTransfer) no tenía ningún
  // gate. Este flag es ahora la fuente real de verdad para ambas cosas.
  const isKeysControlUnlocked = useAppStore(s => s.isFeatureUnlocked('keys_control'));

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

  const handleKeyDelegation = async () => {
    if (!isKeysControlUnlocked) {
      showCustomAlert("🔒 La entrega de turno y delegación de llaves es una función del Plan Pro.");
      setShowKeyDelegationModal(false);
      return;
    }
    if (!nextDayEncargadoId) {
      showCustomAlert("Debes seleccionar a un encargado suplente.");
      return;
    }
    setShowKeyDelegationModal(false);
    await processFinalClockOut(nextDayEncargadoId);
  };

  // NUEVO: unifica las dos definiciones de "portador de llaves" que convivían en el frontend —
  // currentUser.portadorLlaves (campo del empleado) y store_opening_assignments.has_keys (lo que
  // el backend realmente exige en StoreOpeningService::emergencyOpenWithWitnesses). Antes, distintas
  // partes de la UI (botón "Llamar a Encargado", botón de pánico, gate de Apertura de Emergencia)
  // consultaban fuentes distintas y podían no coincidir sobre si una misma persona "tiene llaves".
  // Devuelve true si CUALQUIERA de las dos fuentes lo confirma, para no quitarle acceso a nadie que
  // ya estuviera habilitado por una sola de ellas mientras ambos sistemas conviven sin unificarse.
  const isUserActiveKeyholder = (userId: number | undefined | null) => {
    if (!userId) return false;

    const user = globalUsers.find((u: any) => Number(u.id) === Number(userId));
    const hasPortadorLlaves = Boolean(
      user?.portadorLlaves && String(user.portadorLlaves).toLowerCase() !== 'ninguno'
    );

    let hasActiveAssignment = false;
    try {
      const savedAss = localStorage.getItem('store_opening_assignments');
      const ass = savedAss ? JSON.parse(savedAss) : [];
      // §29 (docs/BACKEND_INTERFACES.md): el backend ahora expone resolved_user_id (users.id) además
      // del employee_id crudo (employees.id) en /store-opening/assignments. Se prioriza el resuelto y
      // se cae a employee_id solo como resguardo (localStorage viejo sin el campo nuevo todavía).
      hasActiveAssignment = ass.some(
        (a: any) => Number(a.resolved_user_id ?? a.employee_id) === Number(userId) && a.is_active && a.has_keys
      );
    } catch {}

    return hasPortadorLlaves || hasActiveAssignment;
  };

  // NUEVO (estado #5 de la matriz — "Llamar a Suplente de Llaves"): botón secundario que permite
  // al encargado responsable de apertura de HOY avisar proactivamente al siguiente suplente en la
  // lista de prioridad, ANTES de que se dispare el traspaso automático por deadline vencido
  // (que ya maneja handleReportAbsencePremium/handleReportLatePremium en useStoreOpening.ts). Reutiliza
  // la misma lectura de 'store_opening_assignments' que esas dos funciones para encontrar al siguiente
  // en orden. Nota de diseño: el estado de la matriz no tiene hoy un sub-estado formal "reportó y va a
  // llamar" en el backend, así que esto se implementa como acción de aviso (llamada + log en Matrix)
  // que NO cede la responsabilidad por sí sola — la cesión formal sigue ocurriendo solo por el flujo de
  // ausencia/retardo o por vencimiento de deadline, evitando inventar un estado que el backend no rastrea.
  const getNextSuplenteUser = () => {
    let ass: any[] = [];
    try {
      const savedAss = localStorage.getItem('store_opening_assignments');
      ass = savedAss ? JSON.parse(savedAss) : [];
    } catch {}

    const currentAss = ass.find((a: any) => Number(a.resolved_user_id ?? a.employee_id) === Number(currentUser?.id));
    const currentOrder = currentAss ? currentAss.priority_order : 1;

    const nextAss = ass
      .filter((a: any) => a.is_active && a.priority_order > currentOrder)
      .sort((a: any, b: any) => a.priority_order - b.priority_order)[0];

    if (!nextAss) return null;
    return globalUsers.find((u: any) => Number(u.id) === Number(nextAss.resolved_user_id ?? nextAss.employee_id)) || null;
  };

  const handleCallSuplente = () => {
    const suplente = getNextSuplenteUser();
    if (!suplente) {
      showCustomAlert('⚠️ No hay un suplente configurado en la lista de prioridad.');
      return;
    }

    useAppStore.getState().addMatrixEvent(
      '📞 Aviso a Suplente de Llaves',
      `${currentUser?.name || 'Encargado'} contactó a ${suplente.name} para pasar la estafeta de apertura.`,
      'info',
      currentUser?.id
    );

    if (suplente.phone) {
      window.location.href = `tel:${suplente.phone}`;
    }
    showCustomAlert(`📞 Contactando a ${suplente.name} (Suplente de Llaves).`);
  };

  const [pendingKeyTransfers, setPendingKeyTransfers] = useState<any[]>([]);

  const initiateKeyTransfer = async (receiverId: number, notes: string) => {
    if (!isKeysControlUnlocked) {
      showCustomAlert("🔒 La transferencia de llaves en vivo es una función del Plan Pro.");
      return false;
    }
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
    } catch (e: any) {
      console.error("Error al transferir cierre:", e);
      showCustomAlert(e.response?.data?.error || "Error al procesar la transferencia.");
      return false;
    }
  };

  const checkPendingKeyTransfers = async () => {
    // Sin keys_control ni siquiera consultamos el endpoint — así un tenant Freemium jamás ve el
    // banner de "te propuso cederte la custodia de llaves", ni aunque quedara un registro viejo.
    if (!isKeysControlUnlocked || isSandboxMode) return;
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
    if (!isKeysControlUnlocked || isSandboxMode) return;
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

  return {
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
  };
}
