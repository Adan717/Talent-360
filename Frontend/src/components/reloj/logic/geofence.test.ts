import { describe, it, expect } from 'vitest';
import {
  getDistanceInMeters,
  evaluateGeofence,
  isValidCoordinate,
  resolveRadius,
  resolveStoreGeofenceConfig,
  isApproaching,
  DEFAULT_RADIUS_METERS,
  LEGACY_FALLBACK_STORE,
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

describe('resolveStoreGeofenceConfig (R105: sin ubicación capturada la geocerca NO aplica)', () => {
  it('sin ninguna clave de ubicación: hasStoreLocation=false y el centro es solo el fallback de display', () => {
    for (const cfg of [undefined, null, {}, { gpsValidationEnabled: true, gpsAlertRangeMeters: 100 }]) {
      const r = resolveStoreGeofenceConfig(cfg);
      expect(r.hasStoreLocation).toBe(false);
      expect(r.store).toEqual(LEGACY_FALLBACK_STORE);
    }
  });

  it('claves planas (línea §1–§42): centro y radio geo_radius_meters', () => {
    const r = resolveStoreGeofenceConfig({
      store_latitude: TIENDA.latitude, store_longitude: TIENDA.longitude, geo_radius_meters: 80,
    });
    expect(r.hasStoreLocation).toBe(true);
    expect(r.store).toEqual(TIENDA);
    expect(r.radiusMeters).toBe(80);
  });

  it('claves planas sin radio: default 50 (el vigente de esa línea)', () => {
    const r = resolveStoreGeofenceConfig({ store_latitude: TIENDA.latitude, store_longitude: TIENDA.longitude });
    expect(r.radiusMeters).toBe(DEFAULT_RADIUS_METERS);
  });

  it('storeLocation (línea Reloj): centro anidado y radio gpsAlertRangeMeters con default 100 — espejo del gate del servidor', () => {
    const conRadio = resolveStoreGeofenceConfig({
      storeLocation: { lat: TIENDA.latitude, lng: TIENDA.longitude }, gpsAlertRangeMeters: 120,
    });
    expect(conRadio.hasStoreLocation).toBe(true);
    expect(conRadio.store).toEqual(TIENDA);
    expect(conRadio.radiusMeters).toBe(120);
    const sinRadio = resolveStoreGeofenceConfig({ storeLocation: { lat: TIENDA.latitude, lng: TIENDA.longitude } });
    expect(sinRadio.radiusMeters).toBe(100);
  });

  it('con las dos familias configuradas gana la plana (comportamiento vigente del gate)', () => {
    const r = resolveStoreGeofenceConfig({
      store_latitude: TIENDA.latitude, store_longitude: TIENDA.longitude, geo_radius_meters: 80,
      storeLocation: { lat: 1, lng: 2 }, gpsAlertRangeMeters: 200,
    });
    expect(r.store).toEqual(TIENDA);
    expect(r.radiusMeters).toBe(80);
  });

  it('captura a medias o basura no cuenta como ubicación', () => {
    for (const cfg of [
      { store_latitude: TIENDA.latitude },                           // falta longitud
      { storeLocation: { lat: TIENDA.latitude } },                   // falta lng
      { storeLocation: { lat: null, lng: null } },                   // limpiada desde el panel
      { store_latitude: 'abc', store_longitude: 'def' },             // basura no numérica
    ]) {
      expect(resolveStoreGeofenceConfig(cfg).hasStoreLocation).toBe(false);
    }
  });

  it('espeja el criterio del servidor en los ceros: plana con 0 no aplica (truthiness PHP), storeLocation con 0 sí (isset)', () => {
    expect(resolveStoreGeofenceConfig({ store_latitude: 0, store_longitude: 0 }).hasStoreLocation).toBe(false);
    expect(resolveStoreGeofenceConfig({ storeLocation: { lat: 0, lng: 0 } }).hasStoreLocation).toBe(true);
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
