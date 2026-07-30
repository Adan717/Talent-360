/**
 * H10 (prueba en vivo 2026-07-29): el dial se re-bloqueaba con "ACCESO BLOQUEADO / TOLERANCIA
 * VENCIDA" pese a que el colaborador ya estaba en turno y el backend tenía la jornada completa
 * (`check_in → meal_start → meal_end`).
 *
 * `useAppStore.fetchState` reconstruye el estado del dial filtrando los fichajes del día así:
 *
 *     const todayStr = new Date().toLocaleDateString('sv-SE');   // fecha del DISPOSITIVO
 *     data.time_entries.filter(e => e.date === todayStr)
 *
 * pero el backend fecha cada ponche con la zona horaria del TENANT. Cuando ambas no coinciden
 * —un colaborador de viaje, un dispositivo con la zona mal puesta, o una empresa que opera en
 * otra región— el filtro descarta TODOS los fichajes del día: sin `check_in` el motor cae a
 * `inactive`, y si además hay retardo aparece el candado que no ofrece salida. Es la misma
 * familia del bug que ya se corrigió en el backend (corte del día por tenant, A5/M5).
 */

/** Fecha "hoy" (YYYY-MM-DD) en la zona indicada; cae a la del dispositivo si no es válida. */
export function hoyEnZona(timezone?: string | null): string {
  if (timezone) {
    try {
      // en-CA da directamente YYYY-MM-DD.
      return new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
    } catch {
      // Zona inválida (mal capturada o legacy): se usa la del dispositivo.
    }
  }
  return new Date().toLocaleDateString('sv-SE');
}

/**
 * Normaliza la fecha de un fichaje a YYYY-MM-DD. Tolera tanto `"2026-07-30"` como el ISO
 * completo (`"2026-07-30T00:00:00.000000Z"`) que algunos endpoints devuelven.
 */
export function fechaDeFichaje(valor: unknown): string {
  if (typeof valor !== 'string') return '';
  return valor.slice(0, 10);
}

/** Fichajes que pertenecen al día operativo del tenant. */
export function fichajesDeHoy<T extends { date?: unknown }>(
  entries: T[] | null | undefined,
  timezone?: string | null,
): T[] {
  const hoy = hoyEnZona(timezone);
  return (entries || []).filter(e => fechaDeFichaje(e.date) === hoy);
}
