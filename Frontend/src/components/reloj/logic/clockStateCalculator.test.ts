import { describe, it, expect } from 'vitest';
import { calculateClockState } from './clockStateCalculator';
import type { ClockEvaluationContext } from './clockStateCalculator';

describe('calculateClockState (Dialer FSM Engine & Graceful Degradation)', () => {
  const defaultUser = {
    id: 101,
    name: 'Carlos Ramírez',
    role: 'Encargado Titular',
    portadorLlaves: true,
  };

  const baseContext: ClockEvaluationContext = {
    clockState: 'inactive',
    currentUser: defaultUser,
    isPro: true,
    isWithinPerimeter: true,
    currentSimTime: 540, // 09:00 AM
    storeStatus: 'open',
    shiftConfigs: { 101: { start: '09:00', end: '17:00' } },
    isFeatureUnlocked: () => true,
  };

  it('Estado #1: Bloquea si lates_academy_block está desbloqueado y tiene >= 3 retardos', () => {
    const res = calculateClockState({
      ...baseContext,
      punctualityStatus: { acumulatedLates: 3, isBlocked: true },
    });
    expect(res.stateNumber).toBe(1);
    expect(res.text).toContain('🔒 Fichaje Bloqueado');
    expect(res.featureTagKey).toBe('lates_academy_block');
  });

  it('FallBack lates_academy_block: Si el flag está deshabilitado, NO bloquea el Dialer', () => {
    const res = calculateClockState({
      ...baseContext,
      punctualityStatus: { acumulatedLates: 3, isBlocked: true },
      isFeatureUnlocked: (key) => key !== 'lates_academy_block',
    });
    expect(res.stateNumber).toBe(12);
    expect(res.text).toBe('Fichar Entrada');
  });

  it('Estado #10: Activa Declarar Eventualidad si hay falla eléctrica/red (offline)', () => {
    const res = calculateClockState({
      ...baseContext,
      isSimulatedOffline: true,
    });
    expect(res.stateNumber).toBe(10);
    expect(res.text).toBe('Declarar Eventualidad');
    expect(res.featureTagKey).toBe('offline_contingency');
  });

  it('Estado #8: Muestra Abrir Tienda si store_opening está activo y la tienda está cerrada', () => {
    const res = calculateClockState({
      ...baseContext,
      storeStatus: 'closed',
      isOpeningPremium: true,
      responsibleId: 101,
    });
    expect(res.stateNumber).toBe(8);
    expect(res.text).toBe('Abrir Tienda');
    expect(res.featureTagKey).toBe('store_opening');
  });

  it('FallBack keys_control: Si keys_control está deshabilitado, omite Entregar Turno y permite Fichar Salida', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'active',
      mealStartTimes: { 101: '13:00' },
      mealEndTimes: { 101: '14:00' },
      breakStartTimes: { 101: '15:00' },
      currentSimTime: 1010, // 16:50 (salida 17:00)
      isHandoverCompleted: false,
      isFeatureUnlocked: (key) => key !== 'keys_control',
    });
    expect(res.stateNumber).toBe(22);
    expect(res.text).toBe('Fichar Salida');
    expect(res.featureTagKey).toBe('basic_punch');
  });

  it('FallBack enable_ley_silla: Si Ley Silla está deshabilitado, omite el descanso y mantiene flujo de salida', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'active',
      mealStartTimes: { 101: '13:00' },
      mealEndTimes: { 101: '14:00' },
      isFeatureUnlocked: (key) => key !== 'enable_ley_silla',
    });
    expect(res.stateNumber).toBe(22);
    expect(res.text).toBe('Fichar Salida');
    expect(res.featureTagKey).toBe('basic_punch');
  });

  it('Plan Gratuito Puro (Solo basic_punch): Flujo de Entrada y Salida 100% Funcional', () => {
    const freeContext: ClockEvaluationContext = {
      ...baseContext,
      isPro: false,
      isFeatureUnlocked: (key) => key === 'basic_punch' || key === 'offline_contingency',
    };

    // 1. Entrada
    const entryRes = calculateClockState({ ...freeContext, clockState: 'inactive' });
    expect(entryRes.stateNumber).toBe(12);
    expect(entryRes.text).toBe('Fichar Entrada');

    // 2. Salida en hora
    const exitRes = calculateClockState({
      ...freeContext,
      clockState: 'active',
      currentSimTime: 1020, // 17:00
    });
    expect(exitRes.stateNumber).toBe(22);
    expect(exitRes.text).toBe('Fichar Salida');

    // 3. Fin Jornada
    const finishedRes = calculateClockState({
      ...freeContext,
      clockState: 'inactive',
      checkOutTimes: { 101: '17:00' },
    });
    expect(finishedRes.stateNumber).toBe(23);
    expect(finishedRes.text).toBe('Fin Jornada');
  });
});
