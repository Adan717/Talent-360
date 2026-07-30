import { describe, it, expect } from 'vitest';
import { resolverEtiquetaTurno } from './etiquetaTurno';

describe('etiqueta "Turno de hoy" (H16)', () => {
  it('EL CASO DEL BUG: con turno real 11:20-19:23 ya no anuncia 09:00-18:00', () => {
    // Lo que `useClockEngine` guarda de verdad: claves `start`/`end`.
    const r = resolverEtiquetaTurno({ start: '11:20:00', end: '19:23:00' });
    expect(r).toEqual({ inicio: '11:20', fin: '19:23', esReal: true });
  });

  it('recorta los segundos que trae el expediente', () => {
    expect(resolverEtiquetaTurno({ start: '08:00:00', end: '16:30:00' }).inicio).toBe('08:00');
    expect(resolverEtiquetaTurno({ start: '08:00:00', end: '16:30:00' }).fin).toBe('16:30');
  });

  it('acepta el formato ya corto', () => {
    expect(resolverEtiquetaTurno({ start: '07:45', end: '15:45' }))
      .toEqual({ inicio: '07:45', fin: '15:45', esReal: true });
  });

  it('cae al expediente del usuario cuando el motor aún no hidrató', () => {
    const r = resolverEtiquetaTurno(null, { shiftStart: '14:00:00', shiftEnd: '22:00:00' });
    expect(r).toEqual({ inicio: '14:00', fin: '22:00', esReal: true });
  });

  it('el motor gana sobre el expediente', () => {
    const r = resolverEtiquetaTurno({ start: '10:00', end: '18:00' }, { shiftStart: '09:00', shiftEnd: '17:00' });
    expect(r.inicio).toBe('10:00');
    expect(r.fin).toBe('18:00');
  });

  it('sin ningún dato usa el valor por defecto pero lo marca como NO real', () => {
    const r = resolverEtiquetaTurno(null, null);
    expect(r).toEqual({ inicio: '09:00', fin: '18:00', esReal: false });
  });

  it('cadenas vacías o basura cuentan como "sin dato", no como turno', () => {
    expect(resolverEtiquetaTurno({ start: '', end: '   ' }).esReal).toBe(false);
    expect(resolverEtiquetaTurno({ start: undefined, end: null }).esReal).toBe(false);
  });

  it('con un solo extremo conocido NO se presenta como turno real', () => {
    // Media etiqueta real y media inventada es lo que hacía creer que el dato era de fiar.
    const r = resolverEtiquetaTurno({ start: '11:20:00', end: null });
    expect(r.inicio).toBe('11:20');
    expect(r.fin).toBe('18:00');
    expect(r.esReal).toBe(false);
  });

  it('las claves EQUIVOCADAS que causaron el bug no cuelan como turno', () => {
    // `shiftStart`/`shiftEnd` sobre el objeto del motor: exactamente lo que leía la etiqueta.
    const r = resolverEtiquetaTurno({ shiftStart: '11:20', shiftEnd: '19:23' } as any);
    expect(r.esReal).toBe(false);
  });
});
