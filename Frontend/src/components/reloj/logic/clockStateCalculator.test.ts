import { describe, it, expect } from 'vitest';
import { calculateClockState } from './clockStateCalculator';
import type { ClockEvaluationContext } from './clockStateCalculator';

describe('calculateClockState (Dialer FSM Engine)', () => {
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
  };

  it('Estado #1: Debe bloquear el fichaje si el usuario tiene >= 3 retardos acumulados', () => {
    const res = calculateClockState({
      ...baseContext,
      punctualityStatus: { acumulatedLates: 3, isBlocked: true },
    });
    expect(res.stateNumber).toBe(1);
    expect(res.text).toContain('🔒 Fichaje Bloqueado');
    expect(res.disabled).toBe(true);
  });

  it('Estado #2: Debe activar el estado Día Feriado LFT cuando isHoliday=true', () => {
    const res = calculateClockState({
      ...baseContext,
      isHoliday: true,
    });
    expect(res.stateNumber).toBe(2);
    expect(res.text).toBe('DÍA FERIADO (LFT)');
    expect(res.disabled).toBe(true);
  });

  it('Estado #3: Debe activar el estado Día de Descanso cuando isRestDay=true', () => {
    const res = calculateClockState({
      ...baseContext,
      isRestDay: true,
    });
    expect(res.stateNumber).toBe(3);
    expect(res.text).toBe('DÍA DE DESCANSO');
    expect(res.disabled).toBe(true);
  });

  it('Estado #10: Debe activar Declarar Eventualidad si hay falla eléctrica/red (offline)', () => {
    const res = calculateClockState({
      ...baseContext,
      isSimulatedOffline: true,
    });
    expect(res.stateNumber).toBe(10);
    expect(res.text).toBe('Declarar Eventualidad');
    expect(res.allowedActions).toContain('declare_contingency');
  });

  it('Estado #8: Debe mostrar Abrir Tienda si la tienda está cerrada y el usuario es el responsable de apertura', () => {
    const res = calculateClockState({
      ...baseContext,
      storeStatus: 'closed',
      isOpeningPremium: true,
      responsibleId: 101,
    });
    expect(res.stateNumber).toBe(8);
    expect(res.text).toBe('Abrir Tienda');
    expect(res.allowedActions).toContain('open_store');
  });

  it('Estado #9: Debe permitir Apertura de Emergencia si falló la apertura previa y hay un encargado con llaves', () => {
    const res = calculateClockState({
      ...baseContext,
      storeStatus: 'closed',
      isOpeningPremium: true,
      openingStatus: { status: 'failed' },
      hasActiveKeyAssignment: true,
      isWithinPerimeter: true,
    });
    expect(res.stateNumber).toBe(9);
    expect(res.text).toBe('Apertura Emergencia');
    expect(res.isEmergencyOpen).toBe(true);
  });

  it('Estado #6: Debe mostrar Reportar Incidencia si está fuera de perímetro', () => {
    const res = calculateClockState({
      ...baseContext,
      isWithinPerimeter: false,
    });
    expect(res.stateNumber).toBe(6);
    expect(res.text).toBe('Reportar Incidencia');
    expect(res.isIncidenceReport).toBe(true);
  });

  it('Estado #12: Debe permitir Fichar Entrada cuando está en puerta con tienda abierta', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'inactive',
      storeStatus: 'open',
    });
    expect(res.stateNumber).toBe(12);
    expect(res.text).toBe('Fichar Entrada');
    expect(res.allowedActions).toContain('check_in');
  });

  it('Estado #7: Debe mostrar "📍 Ya llegué" si el colaborador está en puerta esperando amnistía', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'waiting_room',
      storeStatus: 'open',
    });
    expect(res.stateNumber).toBe(7);
    expect(res.text).toBe('📍 Ya llegué');
  });

  it('Estado #23: Debe deshabilitar el Dialer con "Fin Jornada" post check_out', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'inactive',
      checkOutTimes: { 101: '17:00' },
    });
    expect(res.stateNumber).toBe(23);
    expect(res.text).toBe('Fin Jornada');
    expect(res.disabled).toBe(true);
  });

  it('Estado #16: Solicitar apartar turno en Plan PRO sin slot reservado', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'active',
      isPro: true,
      features: { enable_meal_slots: true },
      userReservedMealSlots: { 101: [] },
      checkInTimes: { 101: 540 },
      currentSimTime: 570,
    });
    expect(res.stateNumber).toBe(16);
    expect(res.text).toBe('Apartar Turno');
    expect(res.isMealReservationAlert).toBe(true);
  });

  it('Estado #16: Bloqueo de Comida si no han pasado 90 mins desde check_in (usuario no-PRO o slots deshabilitados)', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'active',
      isPro: false,
      checkInTimes: { 101: 540 }, // 09:00 AM
      currentSimTime: 570, // 09:30 AM (solo 30 min)
    });
    expect(res.stateNumber).toBe(16);
    expect(res.text).toBe('Tomar Comida');
    expect(res.disabled).toBe(true);
  });

  it('Estado #17: Debe habilitar Tomar Comida después de cumplir la ventana mínima (> 90 mins)', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'active',
      isPro: false,
      checkInTimes: { 101: 540 }, // 09:00 AM
      currentSimTime: 650, // 10:50 AM (> 90 min)
    });
    expect(res.stateNumber).toBe(17);
    expect(res.text).toBe('Tomar Comida');
    expect(res.disabled).toBeUndefined();
    expect(res.allowedActions).toContain('start_meal');
  });

  it('Estado #18: Debe mostrar Terminar Comida mientras clockState es meal', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'meal',
    });
    expect(res.stateNumber).toBe(18);
    expect(res.text).toBe('Terminar Comida');
    expect(res.allowedActions).toContain('end_meal');
  });

  it('Estado #19: Debe ofrecer Ley Silla después de regresar de la comida (Plan PRO)', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'active',
      mealStartTimes: { 101: '13:00' },
      mealEndTimes: { 101: '14:00' },
    });
    expect(res.stateNumber).toBe(19);
    expect(res.text).toBe('Tomar Silla');
    expect(res.allowedActions).toContain('start_break');
  });

  it('Estado #20: Debe mostrar Terminar Descanso durante short_break', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'short_break',
    });
    expect(res.stateNumber).toBe(20);
    expect(res.text).toBe('Terminar Descanso');
    expect(res.allowedActions).toContain('end_break');
  });

  it('Estado #21: Debe ofrecer Entregar Turno a encargados dentro de la ventana de cierre (15 min antes de salida)', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'active',
      mealStartTimes: { 101: '13:00' },
      mealEndTimes: { 101: '14:00' },
      breakStartTimes: { 101: '15:00' },
      currentSimTime: 1010, // 16:50 (salida 17:00)
      isHandoverCompleted: false,
    });
    expect(res.stateNumber).toBe(21);
    expect(res.text).toBe('Entregar Turno');
    expect(res.allowedActions).toContain('complete_handover');
  });

  it('Estado #22: Debe permitir Fichar Salida al cumplir los requisitos de cierre', () => {
    const res = calculateClockState({
      ...baseContext,
      clockState: 'active',
      mealStartTimes: { 101: '13:00' },
      mealEndTimes: { 101: '14:00' },
      breakStartTimes: { 101: '15:00' },
      isHandoverCompleted: true,
      currentSimTime: 1020, // 17:00
    });
    expect(res.stateNumber).toBe(22);
    expect(res.text).toBe('Fichar Salida');
    expect(res.allowedActions).toContain('check_out');
  });
});
