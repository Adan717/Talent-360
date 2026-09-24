import axiosInstance from './axios';
import { clearClockLocalCache } from './clockCache';
import { offlineDb, subirFichajesPendientes } from './offlineDb';
import { confirmAction } from './appDialogs';
import { useAppStore } from '../store/useAppStore';

// Debe coincidir con el `cacheName` de la regla de `/api/` en vite.config.ts.
export const API_CACHE = 'talent360-api-por-cuenta';

/** Quita del dispositivo todo rastro de la sesión: tokens, caché del reloj y copia de la API. */
export function limpiarDispositivo(): void {
  localStorage.removeItem('talent_auth_token');
  localStorage.removeItem('platform_admin_token');
  clearClockLocalCache();
  if (typeof caches !== 'undefined') caches.delete(API_CACHE).catch(() => {});
}

/**
 * Reporte del jefe (2026-09-23): "Cerrar sesión" sólo borraba el token del navegador; el servidor
 * lo seguía aceptando y la cookie httpOnly de un año seguía autenticando. Ahora se revoca en el
 * servidor (que además expira la cookie). Sin red no se puede revocar: al menos el dispositivo
 * queda limpio, y el token muere solo a los 30 días sin uso.
 */
export async function cerrarSesion(): Promise<void> {
  // Reporte del jefe (2026-09-23): antes de salir, subir lo que se fichó sin señal y, si algo no
  // pudo subir, AVISAR. Lo pendiente no se borra: queda en el dispositivo y el servidor sólo lo
  // acepta de su dueño, así que se sube la próxima vez que esa persona entre aquí.
  const { currentUser, isSandboxMode } = useAppStore.getState();
  if (navigator.onLine && !isSandboxMode) await subirFichajesPendientes().catch(() => {});
  const pendientes = currentUser?.role === 'Loading' ? 0 : (await offlineDb.getPunches().catch(() => []))
    .filter(p => Number(p.userId) === Number(currentUser?.id)).length;
  if (pendientes > 0 && !(await confirmAction(
    `Tienes ${pendientes} fichaje(s) hechos sin conexión que todavía no llegan al servidor. Se quedan guardados en este dispositivo y se subirán la próxima vez que entres aquí con tu cuenta. ¿Cerrar sesión de todos modos?`,
    { title: 'Fichajes sin subir', confirmLabel: 'Cerrar sesión', cancelLabel: 'Esperar', tone: 'warning' },
  ))) return;

  try {
    await axiosInstance.post('/logout');
  } catch {
    /* sin red o token ya inválido */
  }
  limpiarDispositivo();
  window.location.href = '/login';
}
