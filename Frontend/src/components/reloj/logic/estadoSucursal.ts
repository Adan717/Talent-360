/**
 * H13 (jornada de regresión 2026-07-30): el tablero mostraba **"ESTADO DE LA SUCURSAL:
 * ABIERTO"** mientras `store_daily_opening_statuses.status` estaba en **`failed`** (nadie abrió
 * dentro de la ventana). La etiqueta se derivaba SÓLO del horario configurado, ignorando el
 * registro real de apertura del día.
 *
 * Es exactamente la contradicción que advierte `logic/storeSchedule.ts`:
 *
 *   > esto NO decide por sí solo si la tienda está operando. Es solo la regla de HORARIO. El
 *   > estado real de apertura del día lo lleva el backend. Que existan dos fuentes y se
 *   > contradigan fue justamente el bug encontrado.
 *
 * Aquí se combinan las dos, que es lo que faltaba: el horario dice si la tienda DEBERÍA estar
 * operando; el registro dice si alguien la abrió de verdad.
 */

export type EstadoApertura = 'opened' | 'pending' | 'failed' | 'transferred' | string | null | undefined;

export interface EstadoSucursalInput {
  /** Lo que dicta el HORARIO configurado ('open' | 'closed'). */
  storeStatus?: string | null;
  /** `store_daily_opening_statuses.status` del día, si existe registro. */
  aperturaDelDia?: EstadoApertura;
  /** ¿La empresa tiene contratada la operativa de apertura? Sin ella sólo manda el horario. */
  aperturaPremium?: boolean;
}

export interface EstadoSucursalResult {
  /** Para el semáforo: sólo `true` cuando la sucursal está REALMENTE operando. */
  operando: boolean;
  /** Texto corto de la píldora. */
  etiqueta: string;
  /** Tono visual sugerido. */
  tono: 'verde' | 'ambar' | 'rojo';
}

export function resolverEstadoSucursal(input: EstadoSucursalInput): EstadoSucursalResult {
  const enHorario = input.storeStatus === 'open';
  // Si el día TIENE registro de apertura, manda ese dato aunque el flag premium venga apagado
  // (así lo pide la realidad: en la V2 el registro existía —`failed`— mientras
  // `isFeatureUnlocked('store_opening')` devolvía false, y la píldora seguía diciendo
  // "Abierto"). El flag sólo decide qué hacer cuando NO hay registro que consultar.
  const hayRegistro = input.aperturaDelDia !== null && input.aperturaDelDia !== undefined;

  // Fuera del horario la sucursal está cerrada, se haya abierto o no por la mañana: el
  // registro `opened` es del EVENTO de apertura, no significa "sigue operando ahora".
  if (!enHorario) {
    return { operando: false, etiqueta: 'Cerrado', tono: 'rojo' };
  }

  // En horario y sin operativa de apertura ni registro: no hay nada que contrastar.
  if (!hayRegistro && !input.aperturaPremium) {
    return { operando: true, etiqueta: 'Abierto', tono: 'verde' };
  }

  // En horario y alguien la abrió de verdad: único caso plenamente "abierto".
  if (input.aperturaDelDia === 'opened') {
    return { operando: true, etiqueta: 'Abierto', tono: 'verde' };
  }

  // En horario pero nadie la abrió (o la apertura se dio por fallida / fue transferida).
  // Antes esto se pintaba como "Abierto" y contradecía lo que el sistema tenía registrado.
  if (input.aperturaDelDia === 'failed') {
    return { operando: false, etiqueta: 'Sin abrir', tono: 'rojo' };
  }

  return { operando: false, etiqueta: 'Pendiente de apertura', tono: 'ambar' };
}
