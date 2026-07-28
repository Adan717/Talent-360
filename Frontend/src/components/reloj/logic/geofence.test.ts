import { describe, it, expect } from 'vitest';
import {
  getDistanceInMeters,
  evaluateGeofence,
  isValidCoordinate,
  resolveRadius,
  isApproaching,
  DEFAULT_RADIUS_METERS,
} from './geofence';

const TIENDA = { latitude: 20.6736, longitude: -101.3564 }; // Irapuato, Gto.

/** Desplaza un punto ~N metros al norte (1° de latitud ≈ 111,320 m). */
const norte = (base: typeof TIENDA, metros: number) => ({
  latitude: base.latitude + metros / 111_320,
  longitude: base.longitude,
});

describe('getDistanceInMeters', () => {
  it('la distancia a sí mismo es cero', () => {
    expect(getDistanceInMeters(TIENDA, TIENDA)).toBeCloseTo(0, 5);
  });

  it('mide correctamente un desplazamiento conocido', () => {
    expect(getDistanceInMeters(TIENDA, norte(TIENDA, 100))).toBeCloseTo(100, 0);
    expect(getDistanceInMeters(TIENDA, norte(TIENDA, 1000))).toBeCloseTo(1000, -1);
  });

  it('es simétrica', () => {
    const a = getDistanceInMeters(TIENDA, norte(TIENDA, 250));
    const b = getDistanceInMeters(norte(TIENDA, 250), TIENDA);
    expect(a).toBeCloseTo(b, 6);
  });

  it('funciona cruzando el meridiano 180 (caso imposible de probar a mano)', () => {
    const oeste = { latitude: 0, longitude: 179.999 };
    const este = { latitude: 0, longitude: -179.999 };
    // Son puntos vecinos, no antípodas: la distancia debe ser de cientos de metros.
    expect(getDistanceInMeters(oeste, este)).toBeLessThan(500);
  });

  it('devuelve NaN ante coordenadas inutilizables en vez de un número falso', () => {
    expect(Number.isNaN(getDistanceInMeters(TIENDA, { latitude: NaN, longitude: 0 }))).toBe(true);
    expect(Number.isNaN(getDistanceInMeters(TIENDA, { latitude: 91, longitude: 0 }))).toBe(true);
  });
});

describe('isValidCoordinate', () => {
  it('acepta coordenadas reales y rechaza basura', () => {
    expect(isValidCoordinate(TIENDA)).toBe(true);
    expect(isValidCoordinate({ latitude: 0, longitude: 0 })).toBe(true);
    for (const mala of [
      null, undefined,
      { latitude: NaN, longitude: 0 },
      { latitude: 0, longitude: Infinity },
      { latitude: 91, longitude: 0 },
      { latitude: 0, longitude: 181 },
    ]) {
      expect(isValidCoordinate(mala as never)).toBe(false);
    }
  });
});

describe('resolveRadius', () => {
  it('usa el radio configurado y cae al default ante valores inválidos', () => {
    expect(resolveRadius(120)).toBe(120);
    for (const mala of [undefined, null, 0, -30, NaN]) {
      expect(resolveRadius(mala as number)).toBe(DEFAULT_RADIUS_METERS);
    }
  });
});

describe('evaluateGeofence', () => {
  const base = { store: TIENDA, radiusMeters: 50, hasReliableFix: true };

  it('dentro del radio: permite', () => {
    const r = evaluateGeofence({ ...base, current: norte(TIENDA, 30) });
    expect(r.isWithinPerimeter).toBe(true);
    expect(r.distanceMeters).toBe(30);
  });

  it('fuera del radio: no permite', () => {
    const r = evaluateGeofence({ ...base, current: norte(TIENDA, 200) });
    expect(r.isWithinPerimeter).toBe(false);
    expect(r.distanceMeters).toBe(200);
  });

  it('justo en el borde cuenta como dentro', () => {
    const r = evaluateGeofence({ ...base, current: norte(TIENDA, 50) });
    expect(r.isWithinPerimeter).toBe(true);
  });

  it('el bypass del administrador manda sobre la distancia', () => {
    const r = evaluateGeofence({ ...base, current: norte(TIENDA, 5000), bypassed: true });
    expect(r.isWithinPerimeter).toBe(true);
    expect(r.wasBypassed).toBe(true);
  });

  it('SEGURIDAD: sin lectura confiable de GPS NO se da por válido', () => {
    // Si "el GPS falló" permitiera fichar, apagar la ubicación sería la forma
    // de saltarse la geocerca. La decisión conservadora es deliberada.
    const r = evaluateGeofence({ ...base, current: norte(TIENDA, 10), hasReliableFix: false });
    expect(r.isWithinPerimeter).toBe(false);
  });

  it('SEGURIDAD: coordenadas inválidas tampoco dan por válido el perímetro', () => {
    const r = evaluateGeofence({ ...base, current: { latitude: NaN, longitude: NaN } });
    expect(r.isWithinPerimeter).toBe(false);
    expect(r.hasInvalidCoordinates).toBe(true);
  });

  it('un radio inválido no abre la puerta: cae al default, no a infinito', () => {
    const r = evaluateGeofence({ ...base, current: norte(TIENDA, 200), radiusMeters: -1 });
    expect(r.radiusMeters).toBe(DEFAULT_RADIUS_METERS);
    expect(r.isWithinPerimeter).toBe(false);
  });
});

describe('isApproaching (estado "En Camino a Sucursal")', () => {
  it('detecta que se acerca', () => {
    expect(isApproaching([500, 420, 300])).toBe(true);
  });

  it('no confunde alejarse ni quedarse quieto con acercarse', () => {
    expect(isApproaching([300, 420, 500])).toBe(false);
    expect(isApproaching([300, 299, 298])).toBe(false); // ruido de GPS, no movimiento
  });

  it('con menos de dos muestras no afirma nada', () => {
    expect(isApproaching([])).toBe(false);
    expect(isApproaching([500])).toBe(false);
  });
});
