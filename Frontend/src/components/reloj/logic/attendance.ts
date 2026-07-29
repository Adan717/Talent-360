/**
 * Reglas puras de puntualidad y retardo.
 *
 * Segunda pieza del reordenamiento del Reloj (2026-07-26). Se eligió esta porque la regla
 * de retardo ya produjo un daño real: el backend calculó un retardo de **−296.84 minutos**
 * para alguien que fichó ANTES de su hora, marcó `is_late = 1`, y generó un incidente LFT
 * con acción "descontar salario" (ver §61 del contrato). Es decir, iba a castigar a un
 * trabajador por llegar temprano.
 *
 * Además, al extraerla apareció otro problema: la **tolerancia tenía cuatro valores por
 * defecto distintos** repartidos en `useClockEngine.tsx` — 10 en un punto, 15 en otros dos,
 * y un 10 fijo escrito a mano en otro. El mismo concepto contestaba distinto según quién
 * preguntara. Aquí hay una sola fuente de verdad.
 *
 * Invariantes que este módulo garantiza y que las pruebas verifican:
 *   1. `lateMins` NUNCA es negativo. Llegar temprano no es un retardo.
 *   2. `lateMins` siempre es un entero (la columna en base de datos es entera; un decimal
 *      revienta el insert, que fue exactamente el error observado en producción).
 *   3. Si `isLate` es falso, `lateMins` es 0.
 */

export const DEFAULT_TOLERANCE_MINS = 15;

const MINS_PER_DAY = 24 * 60;

export interface CheckInEvaluationInput {
  /** Minutos desde medianoche del momento del fichaje. */
  nowMins: number;
  /** Minutos desde medianoche en que inicia el turno del colaborador. */
  shiftStartMins: number;
  /** Tolerancia en minutos. Si no viene, se usa DEFAULT_TOLERANCE_MINS. */
  toleranceMins?: number | null;
}

export interface CheckInEvaluation {
  /** true si el fichaje ocurrió después de la tolerancia. */
  isLate: boolean;
  /** Minutos de retardo respecto al INICIO DEL TURNO. Entero, nunca negativo. */
  lateMins: number;
  /** true si llegó antes o justo en la hora de inicio (sin usar la tolerancia). */
  isEarly: boolean;
  /** Minutos de anticipación. Entero, nunca negativo. 0 si no llegó antes. */
  earlyMins: number;
  /** Último minuto aceptado como puntual. */
  toleranceEndMins: number;
}

/** Resuelve la tolerancia efectiva desde una configuración que puede venir incompleta. */
export function resolveTolerance(value?: number | null): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
    return DEFAULT_TOLERANCE_MINS;
  }
  return Math.trunc(value);
}

/**
 * Calcula la diferencia en minutos entre el fichaje y el inicio del turno, tomando en
 * cuenta que un turno puede cruzar la medianoche. Sin esto, alguien con turno de 22:00
 * que ficha a las 22:05 podría calcularse como si llegara con ~1435 minutos de anticipación.
 *
 * Se resuelve eligiendo la distancia más corta en el reloj de 24 h: si la diferencia supera
 * medio día, se asume que el evento cayó del otro lado de la medianoche.
 */
export function signedMinutesFromShiftStart(nowMins: number, shiftStartMins: number): number {
  let diff = nowMins - shiftStartMins;
  if (diff > MINS_PER_DAY / 2) diff -= MINS_PER_DAY;
  if (diff < -MINS_PER_DAY / 2) diff += MINS_PER_DAY;
  return diff;
}

export function evaluateCheckIn(input: CheckInEvaluationInput): CheckInEvaluation {
  const tolerance = resolveTolerance(input.toleranceMins);
  const diff = Math.round(signedMinutesFromShiftStart(input.nowMins, input.shiftStartMins));

  const isLate = diff > tolerance;

  // Invariante 1 y 2: nunca negativo, siempre entero. `Math.max(0, ...)` es la línea que
  // habría evitado el incidente de "-296.84 min" y el descuento de salario indebido.
  const lateMins = isLate ? Math.max(0, diff) : 0;

  const isEarly = diff < 0;
  const earlyMins = isEarly ? Math.max(0, -diff) : 0;

  return {
    isLate,
    lateMins,
    isEarly,
    earlyMins,
    toleranceEndMins: (input.shiftStartMins + tolerance) % MINS_PER_DAY,
  };
}

/**
 * ¿Debe generarse un incidente laboral (con posible descuento) por este fichaje?
 * Se expone aparte de `evaluateCheckIn` a propósito: generar un incidente tiene
 * consecuencias sobre el salario de una persona, así que la decisión debe ser explícita
 * y no un efecto colateral de calcular minutos.
 */
export function shouldRaiseLateIncident(evaluation: CheckInEvaluation): boolean {
  return evaluation.isLate && evaluation.lateMins > 0;
}

/** Texto legible del retardo, para mostrar o registrar. Evita imprimir decimales crudos. */
export function describeLateness(evaluation: CheckInEvaluation): string {
  if (evaluation.isLate) {
    const m = evaluation.lateMins;
    return `Retardo de ${m} ${m === 1 ? 'minuto' : 'minutos'}.`;
  }
  if (evaluation.isEarly) {
    const m = evaluation.earlyMins;
    return `Llegada anticipada por ${m} ${m === 1 ? 'minuto' : 'minutos'}.`;
  }
  return 'Llegada puntual.';
}
