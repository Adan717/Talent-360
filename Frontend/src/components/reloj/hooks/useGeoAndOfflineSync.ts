import { useState, useEffect } from 'react';
import axiosInstance from '../../../lib/axios';
import { offlineDb } from '../../../lib/offlineDb';
import { useAppStore } from '../../../store/useAppStore';
import { resolveStoreGeofenceConfig } from '../logic/geofence';

// Espejo de Backend/app/Services/ClockService::ALLOWED_TYPES (más temp_exit_start/temp_exit_end,
// ya agregados por backend). Se usa para filtrar defensivamente antes de mandar el batch: si un
// solo ítem del array tiene un `type` no reconocido, Laravel rechaza la petición COMPLETA con 422
// (Rule::in aplica a punches.*.type), lo que bloquearía también los ítems legítimos del mismo lote.
const PUNCH_BATCH_ALLOWED_TYPES = [
  'check_in', 'check_out', 'break_start', 'break_end',
  'meal_start', 'meal_end', 'waiting', 'temp_exit_start', 'temp_exit_end'
];

// NUEVO: cola offline dedicada para declaraciones de contingencia, separada de la cola de punches
// de offlineDb (ver comentario en syncOfflineQueue sobre por qué no se pueden mezclar).
const OFFLINE_CONTINGENCY_KEY = 'offline_contingency_queue';

interface UseGeoAndOfflineSyncParams {
  isSimulator: boolean;
  overrideUser?: any;
  currentUserName: string | undefined;
  clockOpConfig: any;
  showCustomAlert: (msg: string) => void;
}

// Extraído de useClockEngine.tsx (auditoría Jul 2026): agrupa todo lo relacionado con
// geolocalización/geofencing y las dos colas offline (punches vía IndexedDB, contingencias vía
// localStorage) que antes vivían sueltas en el hook gigante. Mantiene EXACTAMENTE la misma lógica
// y nombres de campo que antes — este es un refactor de organización, no de comportamiento.
export function useGeoAndOfflineSync({
  isSimulator,
  overrideUser,
  currentUserName,
  clockOpConfig,
  showCustomAlert,
}: UseGeoAndOfflineSyncParams) {
  const isSandboxMode = useAppStore(s => s.isSandboxMode);

  const [syncQueue, setSyncQueue] = useState<any[]>(() => {
    try {
      const saved = localStorage.getItem('clock_sync_queue');
      return saved ? JSON.parse(saved) : [];
    } catch {
      return [];
    }
  });

  const [gpsCoordinates, setGpsCoordinates] = useState<any>(() => {
    return overrideUser ? { latitude: 19.4326, longitude: -99.1332 } : { latitude: 19.4344, longitude: -99.1332 };
  });
  const [gpsStatus, setGpsStatus] = useState<'seeking' | 'success' | 'error'>(() => {
    return overrideUser ? 'success' : 'seeking';
  });
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
      // Ya inicializado correctamente en el estado inicial de React (useState)
      return;
    }

    if (clockOpConfig.gpsValidationEnabled === false) {
      setGpsStatus('success');
    } else {
      requestGPS();
    }
  }, [clockOpConfig?.gpsValidationEnabled, isSimulator]);

  // BUG FIX (coordenadas hardcodeadas): antes STORE_LAT/STORE_LNG apuntaban fijo al Zócalo de CDMX,
  // rompiendo el geofence para cualquier tenant/sucursal fuera de esas coordenadas exactas.
  // R105 (línea Reloj): la resolución vive en logic/geofence.ts (resolveStoreGeofenceConfig) y
  // contempla las DOS familias de claves del servidor (store_latitude/store_longitude planas y
  // storeLocation{lat,lng}) MÁS el caso "sin capturar": ahí hasStoreLocation=false y la geocerca
  // NO aplica (ver isGpsValidationBypassed abajo) — espejo del fail-open de processPunch. El
  // fallback Zócalo queda solo como centro de display; ya no decide ningún gate.
  const storeGeofence = resolveStoreGeofenceConfig(clockOpConfig);
  const hasStoreLocation = storeGeofence.hasStoreLocation;
  const STORE_LAT = storeGeofence.store.latitude;
  const STORE_LNG = storeGeofence.store.longitude;
  const ALLOWED_RADIUS_METERS = storeGeofence.radiusMeters;

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

  // !hasStoreLocation ⇒ bypass: sin ubicación capturada el servidor no valida geocerca
  // (GeofencePunchTest::test_unconfigured_store_location_skips_geofence) y el cliente tampoco
  // debe bloquear. No cambia nada para tenants con ubicación configurada.
  const isGpsValidationBypassed = clockOpConfig.gpsValidationEnabled === false || !!clockOpConfig.allowManualCheckIn || isSandboxMode || !hasStoreLocation;
  const gpsDistance = getDistanceInMeters(gpsCoordinates.latitude, gpsCoordinates.longitude, STORE_LAT, STORE_LNG);
  const isWithinPerimeter = isGpsValidationBypassed ? true : (gpsDistance <= ALLOWED_RADIUS_METERS && gpsStatus === 'success');

  const getOfflineContingencyQueue = (): Array<{ userId: number; reason: string; declaredAt: string }> => {
    try {
      const raw = localStorage.getItem(OFFLINE_CONTINGENCY_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch {
      return [];
    }
  };

  const saveOfflineContingency = (item: { userId: number; reason: string; declaredAt: string }) => {
    const queue = getOfflineContingencyQueue();
    queue.push(item);
    localStorage.setItem(OFFLINE_CONTINGENCY_KEY, JSON.stringify(queue));
  };

  const syncOfflineContingencies = async () => {
    const queue = getOfflineContingencyQueue();
    if (queue.length === 0) return;
    const isOnline = navigator.onLine && !isSimulatedOffline;
    if (!isOnline || isSandboxMode) return;

    const remaining: typeof queue = [];
    for (const item of queue) {
      try {
        await axiosInstance.post('/clock/declare-contingency', {
          user_id: item.userId,
          reason: item.reason,
          declared_at: item.declaredAt
        });
      } catch (e) {
        console.error('Error sincronizando contingencia offline, se reintentará después:', e);
        remaining.push(item);
      }
    }
    localStorage.setItem(OFFLINE_CONTINGENCY_KEY, JSON.stringify(remaining));
    if (remaining.length < queue.length) {
      showCustomAlert(`🔄 ${queue.length - remaining.length} contingencia(s) offline sincronizada(s) con éxito.`);
    }
  };

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

    console.log("Sincronizando cola offline de fichajes de IndexedDB vía /clock/punch-batch...");

    if (useAppStore.getState().isSandboxMode) {
      // Sandbox: sin llamada real al backend, solo simula el log y limpia la cola local.
      for (const item of currentQueue) {
        useAppStore.getState().addMatrixEvent(
          `[OFFLINE-SYNC] Fichaje Sincronizado: ${item.type}`,
          `El empleado ${currentUserName || 'Desconocido'} sincronizó offline a las ${item.time}`,
          'success',
          item.userId
        );
      }
      await offlineDb.clearPunches();
      setSyncQueue([]);
      showCustomAlert(`🔄 Cola offline: ${currentQueue.length} registros sincronizados con éxito (Sandbox).`);
      window.dispatchEvent(new Event('db_sync_updated'));
      return;
    }

    // Filtra ítems con type desconocido ANTES de construir el batch (ver comentario arriba).
    // Se quedan en la cola sin enviarse — no se pierden, pero tampoco bloquean al resto.
    const validItems = currentQueue.filter(item => PUNCH_BATCH_ALLOWED_TYPES.includes(item.type));
    const skippedCount = currentQueue.length - validItems.length;
    if (skippedCount > 0) {
      console.warn(`${skippedCount} ítem(s) de la cola offline tienen un type no reconocido por punch-batch y se omitieron de este intento de sincronización.`);
    }
    if (validItems.length === 0) return;

    try {
      const res = await axiosInstance.post('/clock/punch-batch', {
        // (2026-08-28 r2b) `offline_stamp` se envía SÓLO si existe. Antes iba `|| ''`: un string
        // vacío tumbaba TODO el lote con 422 (píldora venenosa) y congelaba la cola. Un ítem sin
        // firma (encolado sin secreto en caché) ya no puede validarse — el servidor lo rechaza
        // como 'missing_stamp' y abajo se descarta con aviso, en vez de reintentarse por siempre.
        punches: validItems.map(item => {
          const punch: Record<string, unknown> = {
            user_id: item.userId,
            type: item.type,
            time: item.time,
            details: { note: item.details, gps: item.gps },
            gps: item.gps,
            client_timestamp: item.clientTimestamp || new Date(item.timestamp || Date.now()).toISOString(),
          };
          if (item.offlineStamp) punch.offline_stamp = item.offlineStamp;
          return punch;
        })
      });

      // El servidor responde POR ÍTEM. Un ítem con resultado DEFINITIVO sale de la cola:
      //  · success   → grabado.
      //  · duplicate → ya estaba en el servidor (una respuesta previa se perdió tras grabar).
      //  · rejected  → rechazo PERMANENTE (type inválido, vencido >7d, firma/credencial mala);
      //                reintentarlo es inútil y mantenía la cola llena para siempre con una
      //                alerta falsa de "firma inválida" en cada evento 'online'.
      // Sólo los ítems SIN resultado (fallo de red del lote) se quedan para el próximo intento.
      const results: any[] = res.data?.results || [];
      const byIndex = new Map<number, any>(results.map(r => [r.index, r]));

      let syncedCount = 0;
      let rejectedCount = 0;
      for (let i = 0; i < validItems.length; i++) {
        const r = byIndex.get(i);
        if (!r || validItems[i].id === undefined) continue;
        if (r.success) {
          await offlineDb.deletePunch(validItems[i].id!);
          syncedCount++;
        } else if (r.status === 'duplicate') {
          await offlineDb.deletePunch(validItems[i].id!); // ya registrado en el servidor
        } else if (r.status === 'rejected') {
          await offlineDb.deletePunch(validItems[i].id!);
          rejectedCount++;
          console.warn('Ponche offline rechazado permanentemente y descartado de la cola:', r);
        }
      }

      const remaining = await offlineDb.getPunches();
      setSyncQueue(remaining);

      if (syncedCount > 0) {
        showCustomAlert(`🔄 Cola offline: ${syncedCount} registro(s) sincronizados con éxito.`);
        window.dispatchEvent(new Event('db_sync_updated'));
      }
      if (rejectedCount > 0) {
        // Rechazo permanente: no se pudo registrar y NO se reintentará. La jornada se captura a
        // mano o con justificante. Un solo aviso, sin mentir con "firma inválida".
        showCustomAlert(`⚠️ ${rejectedCount} fichaje(s) offline no pudieron registrarse y se descartaron; captúrelos manualmente o pida un justificante.`);
      }
    } catch (e) {
      // Fallo de red/servidor a nivel de TODO el batch (no de un ítem individual) — se reintentará
      // en el próximo evento 'online' o próximo ciclo, la cola queda intacta.
      console.error("Error sincronizando el batch offline completo:", e);
    }
  };

  useEffect(() => {
    const handleOnline = () => {
      syncOfflineQueue();
      syncOfflineContingencies();
    };
    window.addEventListener('online', handleOnline);
    const isOnline = navigator.onLine && !isSimulatedOffline;
    if (isOnline) {
      syncOfflineQueue();
      syncOfflineContingencies();
    }
    return () => window.removeEventListener('online', handleOnline);
  }, [isSimulatedOffline]);

  return {
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
  };
}
