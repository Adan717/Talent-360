import { describe, it, expect } from 'vitest';
import { resolverEstadoSucursal } from './estadoSucursal';

describe('estado de la sucursal (H13) — combina horario y registro real', () => {
  it('abierto sólo cuando alguien la abrió de verdad', () => {
    const r = resolverEstadoSucursal({ storeStatus: 'open', aperturaDelDia: 'opened', aperturaPremium: true });
    expect(r).toEqual({ operando: true, etiqueta: 'Abierto', tono: 'verde' });
  });

  it('EL CASO DEL BUG: en horario pero la apertura quedó fallida -> ya no dice "Abierto"', () => {
    const r = resolverEstadoSucursal({ storeStatus: 'open', aperturaDelDia: 'failed', aperturaPremium: true });
    expect(r.operando).toBe(false);
    expect(r.etiqueta).toBe('Sin abrir');
    expect(r.tono).toBe('rojo');
  });

  it('en horario y sin registro todavía -> pendiente de apertura', () => {
    const r = resolverEstadoSucursal({ storeStatus: 'open', aperturaDelDia: null, aperturaPremium: true });
    expect(r.operando).toBe(false);
    expect(r.etiqueta).toBe('Pendiente de apertura');
    expect(r.tono).toBe('ambar');
  });

  it('en horario con la apertura aún pendiente -> pendiente', () => {
    const r = resolverEstadoSucursal({ storeStatus: 'open', aperturaDelDia: 'pending', aperturaPremium: true });
    expect(r.etiqueta).toBe('Pendiente de apertura');
  });

  it('una apertura transferida a un suplente sigue sin ser "abierto"', () => {
    const r = resolverEstadoSucursal({ storeStatus: 'open', aperturaDelDia: 'transferred', aperturaPremium: true });
    expect(r.operando).toBe(false);
  });

  it('fuera de horario siempre es cerrado, haya lo que haya registrado', () => {
    expect(resolverEstadoSucursal({ storeStatus: 'closed', aperturaDelDia: 'opened', aperturaPremium: true }).etiqueta).toBe('Cerrado');
    expect(resolverEstadoSucursal({ storeStatus: 'closed', aperturaDelDia: 'failed', aperturaPremium: true }).operando).toBe(false);
  });

  it('sin la operativa de apertura contratada manda sólo el horario', () => {
    expect(resolverEstadoSucursal({ storeStatus: 'open', aperturaDelDia: null, aperturaPremium: false }))
      .toEqual({ operando: true, etiqueta: 'Abierto', tono: 'verde' });
    expect(resolverEstadoSucursal({ storeStatus: 'closed', aperturaPremium: false }).etiqueta).toBe('Cerrado');
  });
});
