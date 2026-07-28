import { describe, it, expect } from 'vitest';
import { getStoreScheduleState, parseHHMM, wrapMins, formatWait } from './storeSchedule';

/**
 * Red de seguridad de la regla de horario de tienda.
 *
 * Cada bloque documenta un comportamiento que el reloj DEBE tener. Si alguien cambia la
 * lógica y rompe uno, esto lo dice en segundos — antes, la única forma de detectarlo era
 * que un colaborador no pudiera fichar en una tienda real.
 */

const H = (hhmm: string) => parseHHMM(hhmm)!;

describe('parseHHMM', () => {
  it('interpreta horas válidas', () => {
    expect(parseHHMM('08:00')).toBe(480);
    expect(parseHHMM('8:05')).toBe(485);
    expect(parseHHMM('23:59')).toBe(1439);
    expect(parseHHMM('00:00')).toBe(0);
  });

  it('rechaza basura en vez de producir NaN', () => {
    // Esto es lo que protegía: antes un valor mal configurado se convertía en NaN y
    // se propagaba hasta mostrarle al usuario una espera sin sentido.
    for (const bad of ['', 'abc', '25:00', '10:99', '10', null, undefined, '10:5']) {
      expect(parseHHMM(bad as string)).toBeNull();
    }
  });
});

describe('wrapMins', () => {
  it('envuelve dentro del día', () => {
    expect(wrapMins(0)).toBe(0);
    expect(wrapMins(1440)).toBe(0);
    expect(wrapMins(-30)).toBe(1410); // 23:30 del día anterior
    expect(wrapMins(1500)).toBe(60);
  });
});

describe('horario normal (08:00 a 18:00, 60 min de margen previo)', () => {
  const base = { openTime: '08:00', closeTime: '18:00', preOpeningAccessMins: 60 };

  it('está ABIERTA en pleno horario', () => {
    expect(getStoreScheduleState({ ...base, nowMins: H('12:00') }).isClosed).toBe(false);
  });

  it('está ABIERTA dentro del margen previo (07:10, abre oficialmente a las 08:00)', () => {
    const r = getStoreScheduleState({ ...base, nowMins: H('07:10') });
    expect(r.isClosed).toBe(false);
    expect(r.effectiveOpenMins).toBe(H('07:00'));
  });

  it('está CERRADA de madrugada y dice cuánto falta', () => {
    const r = getStoreScheduleState({ ...base, nowMins: H('03:00') });
    expect(r.isClosed).toBe(true);
    expect(r.remainingMins).toBe(4 * 60); // faltan 4 h para las 07:00
    expect(formatWait(r.remainingMins)).toBe('4 horas');
  });

  it('está CERRADA después del cierre y cuenta hasta la apertura del día siguiente', () => {
    const r = getStoreScheduleState({ ...base, nowMins: H('20:00') });
    expect(r.isClosed).toBe(true);
    // de 20:00 a medianoche (4 h) + de medianoche a 07:00 (7 h) = 11 h
    expect(r.remainingMins).toBe(11 * 60);
    expect(formatWait(r.remainingMins)).toBe('11 horas');
  });

  it('EL CASO QUE FALLÓ EN PRODUCCIÓN: a las 8:40 a.m. la tienda está ABIERTA', () => {
    // 2026-07-26: el admin veía "Empresa Cerrada — faltan 12 horas y 32 minutos" a esta
    // hora, mientras los colaboradores veían ABIERTO y podían fichar. Con el horario
    // configurado normal, a las 8:40 la respuesta correcta es inequívoca: abierta.
    const r = getStoreScheduleState({ ...base, nowMins: H('08:40') });
    expect(r.isClosed).toBe(false);
    expect(r.remainingMins).toBe(0);
  });
});

describe('horario que cruza la medianoche (22:00 a 02:00)', () => {
  const base = { openTime: '22:00', closeTime: '02:00', preOpeningAccessMins: 0 };

  it('está ABIERTA antes de medianoche', () => {
    expect(getStoreScheduleState({ ...base, nowMins: H('23:30') }).isClosed).toBe(false);
  });

  it('está ABIERTA después de medianoche', () => {
    expect(getStoreScheduleState({ ...base, nowMins: H('01:00') }).isClosed).toBe(false);
  });

  it('está CERRADA a media tarde', () => {
    const r = getStoreScheduleState({ ...base, nowMins: H('15:00') });
    expect(r.isClosed).toBe(true);
    expect(r.remainingMins).toBe(7 * 60); // de 15:00 a 22:00
  });
});

describe('el margen previo empuja la apertura al día anterior', () => {
  it('abre 00:30 con 60 min de margen => apertura efectiva 23:30', () => {
    const base = { openTime: '00:30', closeTime: '06:00', preOpeningAccessMins: 60 };
    const r = getStoreScheduleState({ ...base, nowMins: H('23:45') });
    expect(r.effectiveOpenMins).toBe(H('23:30'));
    expect(r.isClosed).toBe(false); // ya entró en la ventana previa
  });
});

describe('configuración inválida', () => {
  it('cae a valores por defecto y lo señala, en vez de romperse', () => {
    const r = getStoreScheduleState({
      nowMins: H('12:00'), openTime: 'no-es-hora', closeTime: '', preOpeningAccessMins: 60,
    });
    expect(r.usedFallback).toBe(true);
    expect(r.isClosed).toBe(false); // 12:00 cae dentro del 08:00-18:00 por defecto
  });

  it('un margen previo negativo o basura no corrompe el cálculo', () => {
    const r = getStoreScheduleState({
      nowMins: H('12:00'), openTime: '08:00', closeTime: '18:00',
      preOpeningAccessMins: -500 as number,
    });
    expect(r.effectiveOpenMins).toBe(H('08:00'));
    expect(r.isClosed).toBe(false);
  });
});

describe('formatWait', () => {
  it('redondea y concuerda en singular/plural', () => {
    expect(formatWait(0)).toBe('0 minutos');
    expect(formatWait(1)).toBe('1 minuto');
    expect(formatWait(59)).toBe('59 minutos');
    expect(formatWait(60)).toBe('1 hora');
    expect(formatWait(61)).toBe('1 hora y 1 minuto');
    expect(formatWait(752)).toBe('12 horas y 32 minutos');
  });

  it('nunca muestra tiempos negativos', () => {
    expect(formatWait(-100)).toBe('0 minutos');
  });
});
