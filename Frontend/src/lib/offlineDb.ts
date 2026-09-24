import axiosInstance from './axios';

export interface OfflinePunch {
    id?: number;
    userId: number;
    type: string;
    // Hora del fichaje en formato H:i:s (24h, con segundos) — NO usar strings de display tipo
    // "8:32 am".
    // OJO (2026-08-28 r2b): este campo YA NO fija la hora registrada. El batch manda el momento
    // real en `clientTimestamp` (→ occurred_at → details.instante_utc) y el protocolo viejo
    // (details.offline_sync + time) está CERRADO en el servidor: la bandera del cliente con hora
    // propia se rechaza (ClockService::processPunch). `time` sobrevive sólo como parte del mensaje
    // firmado en offlineStamp — debe coincidir byte a byte con lo que se firmó, nada más.
    time: string;
    // Timestamp ISO 8601 real del dispositivo al momento de guardar el punch localmente. Se usa
    // para el orden cronológico de sincronización y forma parte del mensaje firmado en offlineStamp.
    clientTimestamp: string;
    // HMAC-SHA256 (hex) calculado con computeOfflineStamp() de './offlineSecret'. Ver ese archivo
    // para el detalle exacto del mensaje firmado y por qué debe coincidir byte a byte con el backend.
    offlineStamp: string;
    gps: any;
    details: string;
    timestamp: number;
}
 
class OfflineDatabase {
    private dbName = 'talent360_offline_db';
    private dbVersion = 1;
    private storeName = 'punches';
 
    private openDB(): Promise<IDBDatabase> {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.dbVersion);
 
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
 
            request.onupgradeneeded = (event) => {
                const db = request.result;
                if (!db.objectStoreNames.contains(this.storeName)) {
                    db.createObjectStore(this.storeName, { keyPath: 'id', autoIncrement: true });
                }
            };
        });
    }
 
    public async savePunch(punch: Omit<OfflinePunch, 'id' | 'timestamp'>): Promise<number> {
        const db = await this.openDB();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(this.storeName, 'readwrite');
            const store = transaction.objectStore(this.storeName);
            
            const item: OfflinePunch = {
                ...punch,
                timestamp: Date.now()
            };
 
            const request = store.add(item);
            request.onsuccess = () => resolve(request.result as number);
            request.onerror = () => reject(request.error);
        });
    }
 
    public async getPunches(): Promise<OfflinePunch[]> {
        const db = await this.openDB();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(this.storeName, 'readonly');
            const store = transaction.objectStore(this.storeName);
            const request = store.getAll();
 
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }
 
    public async deletePunch(id: number): Promise<void> {
        const db = await this.openDB();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(this.storeName, 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const request = store.delete(id);
 
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }
 
    public async clearPunches(): Promise<void> {
        const db = await this.openDB();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(this.storeName, 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const request = store.clear();
 
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }
}
 
export const offlineDb = new OfflineDatabase();

// Espejo de Backend/app/Services/ClockService::ALLOWED_TYPES (más temp_exit_start/temp_exit_end,
// ya agregados por backend). Se usa para filtrar defensivamente antes de mandar el batch: si un
// solo ítem del array tiene un `type` no reconocido, Laravel rechaza la petición COMPLETA con 422
// (Rule::in aplica a punches.*.type), lo que bloquearía también los ítems legítimos del mismo lote.
const PUNCH_BATCH_ALLOWED_TYPES = [
    'check_in', 'check_out', 'break_start', 'break_end',
    'meal_start', 'meal_end', 'waiting', 'temp_exit_start', 'temp_exit_end'
];

/**
 * Sube la cola offline por /clock/punch-batch y saca de ella los ítems con resultado DEFINITIVO.
 * Vive aquí (antes dentro de useGeoAndOfflineSync) porque la usan el reloj, al volver la red, y
 * `cerrarSesion`, antes de salir. Si falla el lote entero (red/servidor) lanza y la cola queda
 * intacta para el próximo intento.
 */
export async function subirFichajesPendientes(): Promise<{ subidos: number; rechazados: number }> {
    const cola = await offlineDb.getPunches();
    // Los de `type` desconocido se quedan en la cola sin enviarse: no se pierden, pero tampoco
    // bloquean al resto.
    const validos = cola.filter(item => PUNCH_BATCH_ALLOWED_TYPES.includes(item.type));
    if (validos.length < cola.length) {
        console.warn(`${cola.length - validos.length} ítem(s) de la cola offline tienen un type no reconocido por punch-batch y se omitieron de este intento de sincronización.`);
    }
    if (validos.length === 0) return { subidos: 0, rechazados: 0 };

    const res = await axiosInstance.post('/clock/punch-batch', {
        // (2026-08-28 r2b) `offline_stamp` se envía SÓLO si existe. Antes iba `|| ''`: un string
        // vacío tumbaba TODO el lote con 422 (píldora venenosa) y congelaba la cola. Un ítem sin
        // firma (encolado sin secreto en caché) ya no puede validarse — el servidor lo rechaza
        // como 'missing_stamp' y abajo se descarta con aviso, en vez de reintentarse por siempre.
        punches: validos.map(item => {
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
    //  · rejected  → rechazo PERMANENTE (type inválido, vencido >7d, firma/credencial mala).
    // Todo lo demás se queda: el fallo de red del lote y 'ajeno' — un ponche de OTRA cuenta que
    // se conserva hasta que su dueño entre en este dispositivo (reporte del jefe, 2026-09-23).
    const porIndice = new Map<number, any>((res.data?.results || []).map((r: any) => [r.index, r]));
    let subidos = 0;
    let rechazados = 0;
    for (let i = 0; i < validos.length; i++) {
        const r = porIndice.get(i);
        if (!r || validos[i].id === undefined) continue;
        if (r.success || r.status === 'duplicate' || r.status === 'rejected') {
            await offlineDb.deletePunch(validos[i].id!);
            if (r.success) subidos++;
            if (r.status === 'rejected') {
                rechazados++;
                console.warn('Ponche offline rechazado permanentemente y descartado de la cola:', r);
            }
        }
    }
    return { subidos, rechazados };
}
