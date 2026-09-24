import axiosInstance from './axios';
import { clearClockLocalCache } from './clockCache';

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
  try {
    await axiosInstance.post('/logout');
  } catch {
    /* sin red o token ya inválido */
  }
  limpiarDispositivo();
  window.location.href = '/login';
}
