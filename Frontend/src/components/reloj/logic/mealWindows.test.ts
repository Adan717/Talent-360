import { describe, it, expect } from 'vitest';
import {
  parseTimeToMins, formatMins, getShiftDurationMins, getShiftMidpointMins,
  evaluateMealWindow, resolveAllowedMinutes, isWithinMealWindow,
  DEFAULT_MEAL_MINUTES,
} from './mealWindows';

const M = (h: number, m = 0) => h * 60 + m;

describe('parseTimeToMins', () => {
  it('interpreta horas válidas', () => {
    expect(parseTimeToMins('09:00')).toBe(540);
    expect(parseTimeToMins('00:00')).toBe(0);
    expect(parseTimeToMins('23:59')).toBe(1439);
  });

  it('devuelve null ante basura, NO 0 (que significaría medianoche en silencio)', () => {
    // La versión anterior devolvía 0: un horario mal capturado se volvía un turno que
    // empieza a las 00:00 sin que nada avisara.
    for (const bad of ['', 'abc', '25:00', '10:99', null, undefined, 540]) {
      expect(parseTimeToMins(bad)).toBeNull();
    }
  });
});

describe('getShiftDurationMins', () => {
  it('turno diurno', () => {
    expect(getShiftDurationMins(M(9), M(18))).toBe(9 * 60);
  });

  it('turno nocturno que cruza la medianoche', () => {
    expect(getShiftDurationMins(M(22), M(6))).toBe(8 * 60);
    expect(getShiftDurationMins(M(23), M(7))).toBe(8 * 60);
  });
});

describe('getShiftMidpointMins — EL BUG DEL TURNO NOCTURNO', () => {
  it('turno diurno: el punto medio cae donde debe', () => {
    expect(getShiftMidpointMins(M(9), M(18))).toBe(M(13, 30));
  });

  it('turno 22:00-06:00: la comida va a las 02:00, no a las 14:00', () => {
    // Con la fórmula anterior (inicio + (fin - inicio)/2) esto daba 14:00: a alguien que
    // trabaja de 10 de la noche a 6 de la mañana se le agendaba la comida a media tarde.
    expect(getShiftMidpointMins(M(22), M(6))).toBe(M(2));
  });

  it('turno 23:00-07:00: la comida va a las 03:00', () => {
    expect(getShiftMidpointMins(M(23), M(7))).toBe(M(3));
  });

  it('el resultado siempre cae dentro del día', () => {
    for (const [s, e] of [[M(22), M(6)], [M(9), M(18)], [M(0), M(8)], [M(20), M(4)]]) {
      const mid = getShiftMidpointMins(s, e);
      expect(mid).toBeGreaterThanOrEqual(0);
      expect(mid).toBeLessThan(24 * 60);
    }
  });
});

describe('resolveAllowedMinutes', () => {
  it('usa el valor configurado y cae al default ante valores inválidos', () => {
    expect(resolveAllowedMinutes(30)).toBe(30);
    for (const bad of [undefined, null, 0, -20, NaN, 'abc']) {
      expect(resolveAllowedMinutes(bad)).toBe(DEFAULT_MEAL_MINUTES);
    }
  });
});

describe('evaluateMealWindow', () => {
  it('sin comida iniciada no reporta tiempo transcurrido', () => {
    const r = evaluateMealWindow({ nowMins: M(14), mealStartedAtMins: null, allowedMinutes: 60 });
    expect(r.isOnMeal).toBe(false);
    expect(r.elapsedMins).toBe(0);
    expect(r.remainingMins).toBe(60);
  });

  it('en curso: calcula transcurrido y restante', () => {
    const r = evaluateMealWindow({ nowMins: M(14, 20), mealStartedAtMins: M(14), allowedMinutes: 60 });
    expect(r.isOnMeal).toBe(true);
    expect(r.elapsedMins).toBe(20);
    expect(r.remainingMins).toBe(40);
    expect(r.hasExceeded).toBe(false);
  });

  it('excedida: reporta el exceso y deja el restante en cero', () => {
    const r = evaluateMealWindow({ nowMins: M(15, 15), mealStartedAtMins: M(14), allowedMinutes: 60 });
    expect(r.hasExceeded).toBe(true);
    expect(r.exceededByMins).toBe(15);
    expect(r.remainingMins).toBe(0);
  });

  it('justo en el límite todavía NO se considera excedida', () => {
    const r = evaluateMealWindow({ nowMins: M(15), mealStartedAtMins: M(14), allowedMinutes: 60 });
    expect(r.hasExceeded).toBe(false);
    expect(r.remainingMins).toBe(0);
  });

  it('comida que cruza la medianoche: 23:40 a 00:10 son 30 minutos, no negativos', () => {
    const r = evaluateMealWindow({ nowMins: M(0, 10), mealStartedAtMins: M(23, 40), allowedMinutes: 60 });
    expect(r.elapsedMins).toBe(30);
    expect(r.hasExceeded).toBe(false);
  });

  it('el tiempo transcurrido nunca es negativo', () => {
    for (const now of [M(0), M(6), M(12), M(23, 59)]) {
      const r = evaluateMealWindow({ nowMins: now, mealStartedAtMins: M(14), allowedMinutes: 60 });
      expect(r.elapsedMins).toBeGreaterThanOrEqual(0);
    }
  });
});

describe('isWithinMealWindow', () => {
  it('acepta un margen alrededor del punto medio', () => {
    expect(isWithinMealWindow(M(13, 30), M(13, 30))).toBe(true);
    expect(isWithinMealWindow(M(12, 30), M(13, 30), 90)).toBe(true);
    expect(isWithinMealWindow(M(15), M(13, 30), 90)).toBe(true);
  });

  it('rechaza fuera del margen', () => {
    expect(isWithinMealWindow(M(9), M(13, 30), 90)).toBe(false);
  });

  it('funciona con puntos medios de madrugada (turno nocturno)', () => {
    expect(isWithinMealWindow(M(1, 30), M(2), 90)).toBe(true);
    // Cruce real de medianoche DENTRO del margen: 23:45 está a 45 min de las 00:30.
    expect(isWithinMealWindow(M(23, 45), M(0, 30), 90)).toBe(true);
    // Y fuera del margen sigue siendo fuera: de 23:30 a las 02:00 hay 150 min, no 90.
    expect(isWithinMealWindow(M(23, 30), M(2), 90)).toBe(false);
    expect(isWithinMealWindow(M(12), M(2), 90)).toBe(false);
  });
});

describe('formatMins', () => {
  it('formatea y envuelve dentro del día', () => {
    expect(formatMins(0)).toBe('00:00');
    expect(formatMins(M(13, 5))).toBe('13:05');
    expect(formatMins(1440)).toBe('00:00');
    expect(formatMins(-30)).toBe('23:30');
  });
});
