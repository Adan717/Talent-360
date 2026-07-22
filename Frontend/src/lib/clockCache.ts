/**
 * Auditoría reloj checador (2026-07-22), Hallazgo 4:
 * El reloj checador cachea en localStorage datos personales del usuario que
 * acaba de cerrar sesión (horas de descanso/comida/checkout, checklists de
 * apertura/cierre del día, confirmación de "voy en camino"). El Reloj Checador
 * está pensado para dispositivos compartidos en tienda (varios empleados
 * fichan desde el mismo celular/tablet), así que si esto no se limpia al
 * cerrar sesión, el siguiente empleado que entre puede ver momentáneamente
 * datos del usuario anterior hasta que el primer fetchState() los sobreescriba.
 *
 * Exclusiones deliberadas (NO se limpian aquí):
 * - 'clock_sync_queue': cola de fichajes offline aún no sincronizados con el
 *   servidor. Borrarla en logout perdería fichajes reales pendientes de subir.
 * - 'user_retardos_<id>': mecanismo local (inseguro, ver Hallazgo 1 de la
 *   auditoría) de bloqueo por 3 retardos. Limpiarlo en logout lo haría MÁS
 *   fácil de evadir (bastaría cerrar sesión y volver a entrar). Se retirará
 *   por completo cuando el estado #1 se conecte a GET /me/punctuality-status
 *   (el backend ya existe, solo falta cablear el frontend).
 */
const CLOCK_KEYS_TO_CLEAR_ON_LOGOUT = [
  'clock_break_start_times',
  'clock_break_end_times',
  'clock_meal_start_times',
  'clock_meal_end_times',
  'clock_checkout_times',
  'clock_pending_break_requests',
  'store_opening_assignments',
  'store_daily_opening_status',
  'store_opening_settings',
  'opening_checklist_completed',
  'opening_roll_call_completed',
  'closing_checklist_completed',
  'commute_confirmed_', // prefijo: llave por día, no por usuario — puede filtrarse al siguiente empleado
];

export function clearClockLocalCache(): void {
  try {
    Object.keys(localStorage).forEach((key) => {
      if (CLOCK_KEYS_TO_CLEAR_ON_LOGOUT.some((prefix) => key === prefix || key.startsWith(prefix))) {
        localStorage.removeItem(key);
      }
    });
  } catch (e) {
    console.warn('No se pudo limpiar el caché local del reloj al cerrar sesión:', e);
  }
}
