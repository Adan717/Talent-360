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

/**
 * Fecha "hoy" (YYYY-MM-DD) en la zona indicada; cae a la del dispositivo si no es válida.
 *
 * `momento` existe para poder fijar el instante en las pruebas: sin él, cualquier caso que
 * dependa de la hora sólo se verificaría de verdad el día y la hora en que se escribió.
 */
export function hoyEnZona(timezone?: string | null, momento: Date = new Date()): string {
  if (timezone) {
    try {
      // en-CA da directamente YYYY-MM-DD.
      return new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(momento);
    } catch {
      // Zona inválida (mal capturada o legacy): se usa la del dispositivo.
    }
  }
  return momento.toLocaleDateString('sv-SE');
}

/**
 * Normaliza la fecha de un fichaje a YYYY-MM-DD. Tolera tanto `"2026-07-30"` como el ISO
 * completo (`"2026-07-30T00:00:00.000000Z"`) que algunos endpoints devuelven.
 */
export function fechaDeFichaje(valor: unknown): string {
  if (typeof valor !== 'string') return '';
  return valor.slice(0, 10);
}

/**
 * H21 — espejo en el cliente del corte de jornada del backend (`App\Support\JornadaLaboral`).
 *
 * El backend dejó de fechar los ponches por día calendario: con un turno que cruza medianoche
 * (22:00–02:00), la salida de las 02:00 se guarda con la fecha del día en que EMPEZÓ la jornada.
 * Si el dial siguiera filtrando por "hoy", a las 00:30 buscaría fichajes del día nuevo mientras
 * el backend los tiene bajo el día anterior: descartaría TODOS y el motor caería a `inactive`
 * —el colaborador aparecería sin fichar en mitad de su turno—. Es exactamente el síntoma de H10,
 * así que la regla tiene que vivir en los dos lados o no vive en ninguno.
 *
 * Sólo aplica cuando el turno cruza medianoche; en cualquier otro caso manda el día calendario,
 * como siempre.
 */
function minutosDeHora(hora?: string | null): number | null {
  if (typeof hora !== 'string') return null;
  const m = hora.trim().match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
  if (!m) return null;
  const h = Number(m[1]);
  const i = Number(m[2]);
  if (h < 0 || h > 23 || i < 0 || i > 59) return null;
  return h * 60 + i;
}

/** ¿El turno termina al día siguiente de empezar? */
export function turnoCruzaMedianoche(inicio?: string | null, fin?: string | null): boolean {
  const i = minutosDeHora(inicio);
  const f = minutosDeHora(fin);
  if (i === null || f === null) return false;
  return i > f;
}

/**
 * La fecha de jornada vigente AHORA. Para un turno nocturno, entre medianoche y el corte sigue
 * siendo la del día anterior — que es donde el backend está guardando los fichajes.
 */
export function jornadaVigente(
  timezone?: string | null,
  turnoInicio?: string | null,
  turnoFin?: string | null,
  ahora: Date = new Date(),
): string {
  const hoy = hoyEnZona(timezone, ahora);

  if (!turnoCruzaMedianoche(turnoInicio, turnoFin)) return hoy;

  const i = minutosDeHora(turnoInicio)!;
  const f = minutosDeHora(turnoFin)!;
  const corte = Math.floor(f + (i - f) / 2);

  // Hora actual en la zona del tenant, no en la del dispositivo.
  const hhmm = (() => {
    try {
      return new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone || undefined, hour: '2-digit', minute: '2-digit', hour12: false,
      }).format(ahora);
    } catch {
      return `${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}`;
    }
  })();

  const minutoActual = minutosDeHora(hhmm);
  if (minutoActual === null || minutoActual >= corte) return hoy;

  // Antes del corte: la jornada sigue siendo la de ayer.
  const [a, m, d] = hoy.split('-').map(Number);
  const ayer = new Date(Date.UTC(a, m - 1, d));
  ayer.setUTCDate(ayer.getUTCDate() - 1);
  return ayer.toISOString().slice(0, 10);
}

/** El turno de un colaborador, como lo manda el backend. */
export interface TurnoDelColaborador {
  shiftStart?: string | null;
  shiftEnd?: string | null;
}

/**
 * Fichajes que pertenecen a la jornada operativa en curso.
 *
 * `turnoPorEntrada` es opcional y se resuelve POR FICHAJE, no una vez para todos: en una misma
 * empresa puede haber gente de turno diurno y gente de turno nocturno, y cada quien tiene su
 * propia frontera de jornada. Sin el resolvedor se comporta como siempre —día calendario del
 * tenant—, que es lo correcto para cualquier turno diurno.
 */
export function fichajesDeHoy<T extends { date?: unknown }>(
  entries: T[] | null | undefined,
  timezone?: string | null,
  turnoPorEntrada?: (entry: T) => TurnoDelColaborador | null | undefined,
): T[] {
  const jornadaDiurna = hoyEnZona(timezone);

  return (entries || []).filter(e => {
    const turno = turnoPorEntrada?.(e);
    const jornada = turno
      ? jornadaVigente(timezone, turno.shiftStart, turno.shiftEnd)
      : jornadaDiurna;
    return fechaDeFichaje(e.date) === jornada;
  });
}
