/**
 * Reglas puras de horario de tienda.
 *
 * Primera pieza del reordenamiento del Reloj (acordado con Francisco, 2026-07-26).
 * Se eligió empezar por aquí porque es la regla que YA falló en producción: durante la
 * auditoría, el mismo día y a la misma hora, el reloj le decía "Empresa Cerrada" al
 * administrador mientras a los colaboradores les decía "ABIERTO" y les permitía fichar.
 *
 * Por qué vive fuera de `useClockEngine.tsx` / `RelojVisual.tsx`:
 *   - No depende de React, del store, ni de la sesión: entran datos, sale una respuesta.
 *   - Se puede verificar en milisegundos sin abrir la aplicación (ver storeSchedule.test.ts).
 *   - Antes, la única forma de comprobar este comportamiento era entrar a la plataforma y
 *     probar a mano a distintas horas — imposible de hacer para los casos de medianoche.
 *
 * IMPORTANTE — esto NO decide por sí solo si la tienda está operando. Es solo la regla de
 * HORARIO. El estado real de apertura del día lo lleva el backend (`store_daily_opening_status`,
 * "Apertura de hoy a cargo de X"). Que existan dos fuentes y se contradigan fue justamente el
 * bug encontrado. Quien consuma esto debe combinar ambas, no usar solo una.
 */

export interface StoreScheduleInput {
  /** Minutos desde medianoche (0..1439). Ej. 8:40 a.m. = 520. */
  nowMins: number;
  /** Hora de apertura configurada, formato "HH:MM". */
  openTime: string;
  /** Hora de cierre configurada, formato "HH:MM". */
  closeTime: string;
  /**
   * Minutos que los colaboradores pueden entrar ANTES de la apertura oficial
   * (para alistar la tienda). Adelanta la hora efectiva de apertura.
   */
  preOpeningAccessMins?: number;
}

export interface StoreScheduleResult {
  isClosed: boolean;
  /** Minutos que faltan para la próxima apertura. 0 si está abierta. */
  remainingMins: number;
  /** Hora efectiva de apertura (ya con el margen previo aplicado), en minutos. */
  effectiveOpenMins: number;
  closeMins: number;
  /** true si la configuración no se pudo interpretar y se usaron valores por defecto. */
  usedFallback: boolean;
}

const MINS_PER_DAY = 24 * 60;
const DEFAULT_OPEN = '08:00';
const DEFAULT_CLOSE = '18:00';
const DEFAULT_PRE_OPENING = 60;

/**
 * Convierte "HH:MM" a minutos desde medianoche. Devuelve null si no es interpretable,
 * en vez de producir NaN silencioso — que era el riesgo de la versión anterior: un
 * `split(':').map(Number)` sobre un valor mal configurado propagaba NaN por todo el
 * cálculo y terminaba mostrando esperas absurdas al usuario.
 */
export function parseHHMM(value: string | null | undefined): number | null {
  if (typeof value !== 'string') return null;
  const match = value.trim().match(/^(\d{1,2}):(\d{2})$/);
  if (!match) return null;
  const h = Number(match[1]);
  const m = Number(match[2]);
  if (!Number.isFinite(h) || !Number.isFinite(m)) return null;
  if (h < 0 || h > 23 || m < 0 || m > 59) return null;
  return h * 60 + m;
}

/** Normaliza cualquier entero de minutos al rango 0..1439 (envuelve en el día). */
export function wrapMins(mins: number): number {
  return ((mins % MINS_PER_DAY) + MINS_PER_DAY) % MINS_PER_DAY;
}

export function getStoreScheduleState(input: StoreScheduleInput): StoreScheduleResult {
  const parsedOpen = parseHHMM(input.openTime);
  const parsedClose = parseHHMM(input.closeTime);
  const usedFallback = parsedOpen === null || parsedClose === null;

  const baseOpenMins = parsedOpen ?? parseHHMM(DEFAULT_OPEN)!;
  const closeMins = parsedClose ?? parseHHMM(DEFAULT_CLOSE)!;

  const pre = Number.isFinite(input.preOpeningAccessMins as number)
    ? Math.max(0, Math.trunc(input.preOpeningAccessMins as number))
    : DEFAULT_PRE_OPENING;

  const effectiveOpenMins = wrapMins(baseOpenMins - pre);
  const nowMins = wrapMins(input.nowMins);

  // Un horario puede cruzar la medianoche (ej. abre 22:00, cierra 02:00), y el margen
  // previo también puede empujar la apertura al día anterior (abre 00:30 con 60 min de
  // margen => apertura efectiva 23:30). Ambos casos se tratan igual: si la apertura
  // efectiva es POSTERIOR al cierre en el reloj, el turno cruza la medianoche.
  const crossesMidnight = effectiveOpenMins > closeMins;

  let isClosed: boolean;
  if (crossesMidnight) {
    // Abierto desde effectiveOpen hasta medianoche, y desde medianoche hasta close.
    isClosed = nowMins > closeMins && nowMins < effectiveOpenMins;
  } else {
    isClosed = nowMins < effectiveOpenMins || nowMins > closeMins;
  }

  let remainingMins = 0;
  if (isClosed) {
    remainingMins =
      nowMins < effectiveOpenMins
        ? effectiveOpenMins - nowMins
        : MINS_PER_DAY - nowMins + effectiveOpenMins;
  }

  return { isClosed, remainingMins, effectiveOpenMins, closeMins, usedFallback };
}

/** Formatea una espera en minutos a texto legible en español. */
export function formatWait(remainingMins: number): string {
  const total = Math.max(0, Math.round(remainingMins));
  const hours = Math.floor(total / 60);
  const mins = total % 60;
  if (hours > 0) {
    const h = `${hours} ${hours === 1 ? 'hora' : 'horas'}`;
    return mins > 0 ? `${h} y ${mins} ${mins === 1 ? 'minuto' : 'minutos'}` : h;
  }
  return `${mins} ${mins === 1 ? 'minuto' : 'minutos'}`;
}
