import { describe, it, expect } from 'vitest';
import {
  evaluateCheckIn,
  resolveTolerance,
  signedMinutesFromShiftStart,
  shouldRaiseLateIncident,
  describeLateness,
  DEFAULT_TOLERANCE_MINS,
} from './attendance';

const M = (h: number, m = 0) => h * 60 + m;

describe('resolveTolerance', () => {
  it('usa el valor configurado cuando es válido', () => {
    expect(resolveTolerance(10)).toBe(10);
    expect(resolveTolerance(0)).toBe(0);
  });

  it('cae al valor por defecto ante datos faltantes o inválidos', () => {
    // Esto unifica los CUATRO valores por defecto distintos que había regados en
    // useClockEngine.tsx (10, 15, 15 y un 10 escrito a mano).
    for (const bad of [undefined, null, NaN, -5, 'abc' as unknown as number]) {
      expect(resolveTolerance(bad as number)).toBe(DEFAULT_TOLERANCE_MINS);
    }
  });
});

describe('signedMinutesFromShiftStart (turnos que cruzan la medianoche)', () => {
  it('turno diurno normal', () => {
    expect(signedMinutesFromShiftStart(M(9, 20), M(9))).toBe(20);
    expect(signedMinutesFromShiftStart(M(8, 40), M(9))).toBe(-20);
  });

  it('turno nocturno: fichar 22:05 con turno de 22:00 son 5 minutos, no 1435', () => {
    expect(signedMinutesFromShiftStart(M(22, 5), M(22))).toBe(5);
  });

  it('turno nocturno: fichar 00:10 con turno de 23:50 son 20 minutos tarde', () => {
    expect(signedMinutesFromShiftStart(M(0, 10), M(23, 50))).toBe(20);
  });

  it('turno nocturno: fichar 23:40 con turno de 00:00 son 20 minutos de anticipación', () => {
    expect(signedMinutesFromShiftStart(M(23, 40), M(0))).toBe(-20);
  });
});

describe('evaluateCheckIn — puntualidad', () => {
  const shift = M(9);

  it('llegar exacto a la hora es puntual', () => {
    const r = evaluateCheckIn({ nowMins: shift, shiftStartMins: shift, toleranceMins: 15 });
    expect(r.isLate).toBe(false);
    expect(r.lateMins).toBe(0);
    expect(r.isEarly).toBe(false);
  });

  it('llegar dentro de la tolerancia es puntual', () => {
    const r = evaluateCheckIn({ nowMins: M(9, 15), shiftStartMins: shift, toleranceMins: 15 });
    expect(r.isLate).toBe(false);
    expect(r.lateMins).toBe(0);
  });

  it('pasada la tolerancia sí es retardo, medido desde el inicio del turno', () => {
    const r = evaluateCheckIn({ nowMins: M(9, 20), shiftStartMins: shift, toleranceMins: 15 });
    expect(r.isLate).toBe(true);
    expect(r.lateMins).toBe(20);
    expect(shouldRaiseLateIncident(r)).toBe(true);
  });
});

describe('EL BUG QUE COSTÓ DINERO: llegar temprano no es retardo', () => {
  // §61: en producción, un fichaje anticipado produjo late_minutes = -296.8416722,
  // con is_late = 1 y un incidente LFT marcado para DESCONTAR SALARIO.
  const shift = M(9);

  it('llegar 50 minutos antes NO es retardo y NO genera incidente', () => {
    const r = evaluateCheckIn({ nowMins: M(8, 10), shiftStartMins: shift, toleranceMins: 15 });
    expect(r.isLate).toBe(false);
    expect(r.lateMins).toBe(0);
    expect(r.isEarly).toBe(true);
    expect(r.earlyMins).toBe(50);
    expect(shouldRaiseLateIncident(r)).toBe(false);
  });

  it('lateMins nunca es negativo, sin importar cuán temprano se llegue', () => {
    for (const h of [8, 7, 6, 5, 4]) {
      const r = evaluateCheckIn({ nowMins: M(h), shiftStartMins: shift, toleranceMins: 15 });
      expect(r.lateMins).toBeGreaterThanOrEqual(0);
      expect(shouldRaiseLateIncident(r)).toBe(false);
    }
  });

  it('lateMins siempre es entero (la columna en base de datos es entera)', () => {
    // El insert reventó en producción con SQLSTATE[22P02] justamente por un decimal.
    const r = evaluateCheckIn({ nowMins: M(9, 20) + 0.84, shiftStartMins: shift, toleranceMins: 15 });
    expect(Number.isInteger(r.lateMins)).toBe(true);
  });

  it('si isLate es falso, lateMins es 0 (no quedan estados contradictorios)', () => {
    const casos = [M(6), M(8, 59), M(9), M(9, 10), M(9, 15)];
    for (const now of casos) {
      const r = evaluateCheckIn({ nowMins: now, shiftStartMins: shift, toleranceMins: 15 });
      if (!r.isLate) expect(r.lateMins).toBe(0);
    }
  });
});

describe('describeLateness — texto sin decimales crudos', () => {
  it('describe retardo, anticipación y puntualidad', () => {
    const tarde = evaluateCheckIn({ nowMins: M(9, 20), shiftStartMins: M(9), toleranceMins: 15 });
    expect(describeLateness(tarde)).toBe('Retardo de 20 minutos.');

    const temprano = evaluateCheckIn({ nowMins: M(8, 59), shiftStartMins: M(9), toleranceMins: 15 });
    expect(describeLateness(temprano)).toBe('Llegada anticipada por 1 minuto.');

    const puntual = evaluateCheckIn({ nowMins: M(9), shiftStartMins: M(9), toleranceMins: 15 });
    expect(describeLateness(puntual)).toBe('Llegada puntual.');
  });

  it('nunca imprime un número negativo ni decimal', () => {
    // Ojo: la frase SÍ termina con punto final; lo que no debe aparecer es un número
    // con signo (-296) ni con decimales (296.84), que fue lo que se mostró en producción.
    const r = evaluateCheckIn({ nowMins: M(4), shiftStartMins: M(9), toleranceMins: 15 });
    const texto = describeLateness(r);
    expect(texto).not.toMatch(/-\d/);      // sin números negativos
    expect(texto).not.toMatch(/\d+\.\d+/); // sin decimales
    expect(texto).toBe('Llegada anticipada por 300 minutos.');
  });
});
