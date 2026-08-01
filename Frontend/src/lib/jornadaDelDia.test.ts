import { describe, it, expect } from 'vitest';
import { hoyEnZona, fechaDeFichaje, fichajesDeHoy, turnoCruzaMedianoche, jornadaVigente } from './jornadaDelDia';

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

describe('jornada nocturna en el cliente (H21)', () => {
  const MX = 'America/Mexico_City';

  it('reconoce el turno que cruza medianoche', () => {
    expect(turnoCruzaMedianoche('22:00:00', '02:00:00')).toBe(true);
    expect(turnoCruzaMedianoche('09:00', '18:00')).toBe(false);
    expect(turnoCruzaMedianoche(null, '02:00')).toBe(false);
    expect(turnoCruzaMedianoche('basura', '02:00')).toBe(false);
  });

  it('antes del corte la jornada sigue siendo la de AYER', () => {
    // 07:30Z = 01:30 en Ciudad de México: plena madrugada del turno nocturno.
    const madrugada = new Date('2026-07-30T07:30:00Z');
    expect(jornadaVigente(MX, '22:00', '02:00', madrugada)).toBe('2026-07-29');
  });

  it('despues del corte ya es la jornada de hoy', () => {
    // 04:00Z = 22:00 del 29: arranca la jornada del 29.
    const noche = new Date('2026-07-30T04:00:00Z');
    expect(jornadaVigente(MX, '22:00', '02:00', noche)).toBe('2026-07-29');

    // 20:00Z = 14:00 del 30, ya pasado el corte de las 12:00.
    const tarde = new Date('2026-07-30T20:00:00Z');
    expect(jornadaVigente(MX, '22:00', '02:00', tarde)).toBe('2026-07-30');
  });

  it('un turno diurno usa siempre el dia calendario', () => {
    const madrugada = new Date('2026-07-30T07:30:00Z');
    expect(jornadaVigente(MX, '09:00', '18:00', madrugada)).toBe('2026-07-30');
    expect(jornadaVigente(MX, null, null, madrugada)).toBe('2026-07-30');
  });

  it('cruza bien el cambio de mes', () => {
    // 07:00Z del 1 de agosto = 01:00 local: pertenece al 31 de julio.
    const finDeMes = new Date('2026-08-01T07:00:00Z');
    expect(jornadaVigente(MX, '22:00', '02:00', finDeMes)).toBe('2026-07-31');
  });

  it('EL CASO DEL BUG: cada quien se filtra con SU turno', () => {
    // Mismo tenant, dos turnos. El backend fecha al nocturno bajo la jornada de ayer.
    const hoy = hoyEnZona('America/Mexico_City');
    const ayer = (() => {
      const [a, m, d] = hoy.split('-').map(Number);
      const x = new Date(Date.UTC(a, m - 1, d));
      x.setUTCDate(x.getUTCDate() - 1);
      return x.toISOString().slice(0, 10);
    })();

    const entries = [
      { user_id: 1, type: 'check_in', date: hoy },   // diurno, fichó hoy
      { user_id: 2, type: 'check_in', date: ayer },  // nocturno, su jornada empezó ayer
    ];
    const turnos: Record<number, { shiftStart: string; shiftEnd: string }> = {
      1: { shiftStart: '09:00:00', shiftEnd: '18:00:00' },
      2: { shiftStart: '22:00:00', shiftEnd: '02:00:00' },
    };

    const filtrados = fichajesDeHoy(entries, MX, e => turnos[(e as any).user_id]);

    // El diurno siempre entra. El nocturno entra sólo si AHORA estamos en su madrugada; lo que
    // se comprueba aquí es que se le aplica SU turno y no el del otro.
    expect(filtrados.some(e => (e as any).user_id === 1)).toBe(true);
    const esperadoNocturno = jornadaVigente(MX, '22:00', '02:00') === ayer;
    expect(filtrados.some(e => (e as any).user_id === 2)).toBe(esperadoNocturno);
  });

  it('sin resolvedor de turno se comporta como antes', () => {
    const hoy = hoyEnZona(MX);
    const entries = [{ user_id: 1, type: 'check_in', date: hoy }];
    expect(fichajesDeHoy(entries, MX)).toHaveLength(1);
  });
});
