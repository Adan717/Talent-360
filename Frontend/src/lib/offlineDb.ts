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
