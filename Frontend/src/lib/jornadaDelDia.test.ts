import { describe, it, expect } from 'vitest';
import { hoyEnZona, fechaDeFichaje, fichajesDeHoy } from './jornadaDelDia';

describe('fechaDeFichaje (H10)', () => {
  it('deja pasar el formato normal del backend', () => {
    expect(fechaDeFichaje('2026-07-30')).toBe('2026-07-30');
  });

  it('tolera el ISO completo que devuelven algunos endpoints', () => {
    expect(fechaDeFichaje('2026-07-30T00:00:00.000000Z')).toBe('2026-07-30');
  });

  it('no revienta con valores nulos o de otro tipo', () => {
    expect(fechaDeFichaje(null)).toBe('');
    expect(fechaDeFichaje(undefined)).toBe('');
    expect(fechaDeFichaje(12345)).toBe('');
  });
});

describe('hoyEnZona (H10)', () => {
  it('devuelve YYYY-MM-DD', () => {
    expect(hoyEnZona('America/Mexico_City')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('dos zonas separadas por el cambio de día pueden dar fechas distintas', () => {
    // No se fija un valor concreto (depende de la hora real de ejecución), pero ambas
    // deben ser fechas válidas: es el escenario que rompía el filtro.
    expect(hoyEnZona('Pacific/Kiritimati')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(hoyEnZona('Pacific/Midway')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('cae a la fecha del dispositivo si la zona es inválida', () => {
    expect(hoyEnZona('Zona/Inventada')).toBe(new Date().toLocaleDateString('sv-SE'));
    expect(hoyEnZona(null)).toBe(new Date().toLocaleDateString('sv-SE'));
  });
});

describe('fichajesDeHoy (H10)', () => {
  const hoyMx = hoyEnZona('America/Mexico_City');

  it('conserva la jornada del día del tenant', () => {
    const entries = [
      { type: 'check_in', date: hoyMx },
      { type: 'meal_start', date: hoyMx },
      { type: 'meal_end', date: hoyMx },
    ];
    expect(fichajesDeHoy(entries, 'America/Mexico_City')).toHaveLength(3);
  });

  it('descarta los de días anteriores', () => {
    const entries = [
      { type: 'check_in', date: hoyMx },
      { type: 'check_out', date: '2020-01-01' },
    ];
    const hoy = fichajesDeHoy(entries, 'America/Mexico_City');
    expect(hoy).toHaveLength(1);
    expect(hoy[0].type).toBe('check_in');
  });

  it('reconoce la jornada aunque la fecha venga en ISO completo', () => {
    const entries = [{ type: 'check_in', date: `${hoyMx}T00:00:00.000000Z` }];
    expect(fichajesDeHoy(entries, 'America/Mexico_City')).toHaveLength(1);
  });

  it('aguanta lista vacía o ausente', () => {
    expect(fichajesDeHoy([], 'America/Mexico_City')).toEqual([]);
    expect(fichajesDeHoy(null, 'America/Mexico_City')).toEqual([]);
    expect(fichajesDeHoy(undefined, undefined)).toEqual([]);
  });
});
