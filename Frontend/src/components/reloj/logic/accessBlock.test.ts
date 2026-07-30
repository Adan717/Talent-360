import { describe, it, expect } from 'vitest';
import {
  shouldBlockForLateTolerance,
  canOpenClosedStore,
  hasApprovedLateAuthorization,
  type AccessBlockInput,
} from './accessBlock';

/** Escenario base: colaborador con retardo que aún no ficha y la tienda ya abierta. */
const base: AccessBlockInput = {
  hasCheckedIn: false,
  isLate: true,
  clockState: 'inactive',
  storeStatus: 'open',
  currentUserId: 5,
  responsibleId: 9,
  esAperturador: false,
  lateAuthorizedUserIds: [],
};

describe('candado por tolerancia vencida', () => {
  it('bloquea al colaborador con retardo, sin autorización y sin llaves', () => {
    expect(shouldBlockForLateTolerance(base)).toBe(true);
  });

  it('no bloquea si ya fichó', () => {
    expect(shouldBlockForLateTolerance({ ...base, hasCheckedIn: true })).toBe(false);
  });

  it('no bloquea si llegó en tiempo', () => {
    expect(shouldBlockForLateTolerance({ ...base, isLate: false })).toBe(false);
  });

  it('no bloquea si la jornada ya arrancó (clockState != inactive)', () => {
    expect(shouldBlockForLateTolerance({ ...base, clockState: 'active' })).toBe(false);
  });
});

describe('H6 — autorización de entrada tardía aprobada', () => {
  it('levanta el candado a quien está en la lista de autorizados', () => {
    expect(shouldBlockForLateTolerance({ ...base, lateAuthorizedUserIds: [5] })).toBe(false);
  });

  it('tolera ids como texto (el backend puede serializarlos así)', () => {
    expect(shouldBlockForLateTolerance({ ...base, lateAuthorizedUserIds: ['5'] })).toBe(false);
  });

  it('no levanta el candado por la autorización de OTRO colaborador', () => {
    expect(shouldBlockForLateTolerance({ ...base, lateAuthorizedUserIds: [7, 8] })).toBe(true);
  });

  it('aguanta la lista vacía o ausente sin romper', () => {
    expect(hasApprovedLateAuthorization({ ...base, lateAuthorizedUserIds: null })).toBe(false);
    expect(hasApprovedLateAuthorization({ ...base, lateAuthorizedUserIds: [] })).toBe(false);
  });
});

describe('H7 — deadlock de apertura', () => {
  it('deja pasar al responsable de la apertura cuando la tienda está cerrada', () => {
    expect(shouldBlockForLateTolerance({
      ...base, storeStatus: 'closed', currentUserId: 9, responsibleId: 9,
    })).toBe(false);
  });

  it('deja pasar a cualquiera con llaves (esAperturador) si nadie abrió', () => {
    expect(shouldBlockForLateTolerance({
      ...base, storeStatus: 'closed', esAperturador: true,
    })).toBe(false);
  });

  it('sigue bloqueando al colaborador SIN llaves aunque la tienda esté cerrada', () => {
    expect(shouldBlockForLateTolerance({
      ...base, storeStatus: 'closed', esAperturador: false,
    })).toBe(true);
  });

  it('no da paso libre con la tienda ABIERTA sólo por ser aperturador', () => {
    // Con la tienda ya abierta no hay deadlock que romper: el retardo se trata normal.
    expect(shouldBlockForLateTolerance({
      ...base, storeStatus: 'open', esAperturador: true,
    })).toBe(true);
    expect(canOpenClosedStore({ ...base, storeStatus: 'open', esAperturador: true })).toBe(false);
  });
});
