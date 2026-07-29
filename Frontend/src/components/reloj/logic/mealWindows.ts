/**
 * Reglas puras de ventanas de comida y descanso.
 *
 * Cuarta pieza del reordenamiento del Reloj (Fase 2, 2026-07-26).
 *
 * Bug encontrado al extraerla: el punto medio del turno —que es donde se agenda la comida—
 * se calculaba como `inicio + (fin - inicio) / 2`. En un turno nocturno (22:00 a 06:00) el
 * fin es numéricamente MENOR que el inicio, así que la resta da negativo y el punto medio
 * retrocede: a alguien que trabaja de 10 de la noche a 6 de la mañana le agendaba la comida
 * a las 2 de la tarde. Aquí se corrige tratando el turno como duración, no como resta.
 *
 * Segundo problema corregido: `parseTimeToMins` devolvía **0** ante cualquier valor inválido,
 * lo que equivale a decir "medianoche" en silencio. Un horario mal capturado se convertía en
 * un turno que empieza a las 00:00 sin que nada avisara.
 */

const MINS_PER_DAY = 24 * 60;

export const DEFAULT_MEAL_MINUTES = 60;
export const DEFAULT_BREAK_MINUTES = 15;

/** Convierte "HH:MM" a minutos. Devuelve null si no es interpretable (nunca 0 en silencio). */
export function parseTimeToMins(value: unknown): number | null {
  if (typeof value !== 'string') return null;
  const m = value.trim().match(/^(\d{1,2}):(\d{2})$/);
  if (!m) return null;
  const h = Number(m[1]);
  const min = Number(m[2]);
  if (h < 0 || h > 23 || min < 0 || min > 59) return null;
  return h * 60 + min;
}

export function formatMins(mins: number): string {
  const v = ((Math.round(mins) % MINS_PER_DAY) + MINS_PER_DAY) % MINS_PER_DAY;
  return `${Math.floor(v / 60).toString().padStart(2, '0')}:${(v % 60).toString().padStart(2, '0')}`;
}

/**
 * Duración del turno en minutos, tratando correctamente los que cruzan la medianoche.
 * 22:00→06:00 son 8 horas, no −16.
 */
export function getShiftDurationMins(startMins: number, endMins: number): number {
  const diff = endMins - startMins;
  return diff > 0 ? diff : diff + MINS_PER_DAY;
}

/**
 * Punto medio del turno, que es donde se agenda la comida por defecto.
 * Devuelve minutos desde medianoche, ya envueltos al rango 0..1439.
 */
export function getShiftMidpointMins(startMins: number, endMins: number): number {
  const duration = getShiftDurationMins(startMins, endMins);
  return (startMins + Math.floor(duration / 2)) % MINS_PER_DAY;
}

export interface MealWindowInput {
  /** Momento actual, en minutos desde medianoche. */
  nowMins: number;
  /** Momento en que el colaborador inició su comida. null si no la ha iniciado. */
  mealStartedAtMins: number | null;
  /** Minutos de comida a los que tiene derecho. */
  allowedMinutes?: number | null;
}

export interface MealWindowResult {
  /** true si está actualmente en su comida. */
  isOnMeal: boolean;
  /** Minutos transcurridos desde que inició. 0 si no ha iniciado. Nunca negativo. */
  elapsedMins: number;
  /** Minutos que le quedan. 0 si ya se pasó. */
  remainingMins: number;
  /** true si excedió el tiempo permitido. */
  hasExceeded: boolean;
  /** Minutos de exceso. 0 si no se pasó. */
  exceededByMins: number;
  allowedMinutes: number;
}

export function resolveAllowedMinutes(value: unknown, fallback = DEFAULT_MEAL_MINUTES): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) return fallback;
  return Math.trunc(value);
}

export function evaluateMealWindow(input: MealWindowInput): MealWindowResult {
  const allowedMinutes = resolveAllowedMinutes(input.allowedMinutes);

  if (input.mealStartedAtMins === null || !Number.isFinite(input.mealStartedAtMins)) {
    return {
      isOnMeal: false, elapsedMins: 0, remainingMins: allowedMinutes,
      hasExceeded: false, exceededByMins: 0, allowedMinutes,
    };
  }

  // La comida puede cruzar la medianoche (turno nocturno): si el inicio es "mayor" que
  // ahora, es que ya pasó la medianoche, no que empezó en el futuro.
  let elapsed = input.nowMins - input.mealStartedAtMins;
  if (elapsed < 0) elapsed += MINS_PER_DAY;
  elapsed = Math.max(0, Math.round(elapsed));

  const hasExceeded = elapsed > allowedMinutes;

  return {
    isOnMeal: true,
    elapsedMins: elapsed,
    remainingMins: Math.max(0, allowedMinutes - elapsed),
    hasExceeded,
    exceededByMins: hasExceeded ? elapsed - allowedMinutes : 0,
    allowedMinutes,
  };
}

/**
 * ¿Ya le toca comer? Se considera que la ventana se abre un margen antes del punto medio
 * y se cierra un margen después, para no exigir exactitud al minuto.
 */
export function isWithinMealWindow(
  nowMins: number,
  midpointMins: number,
  toleranceMins = 90,
): boolean {
  let diff = nowMins - midpointMins;
  if (diff > MINS_PER_DAY / 2) diff -= MINS_PER_DAY;
  if (diff < -MINS_PER_DAY / 2) diff += MINS_PER_DAY;
  return Math.abs(diff) <= toleranceMins;
}
