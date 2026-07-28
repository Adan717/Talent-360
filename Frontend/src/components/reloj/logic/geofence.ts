/**
 * Reglas puras de geocerca (validación de ubicación al fichar).
 *
 * Tercera pieza del reordenamiento del Reloj (Fase 2, 2026-07-26). Es matemática de
 * coordenadas: entran dos puntos y un radio, sale una distancia y un veredicto. No necesita
 * React, ni el GPS del navegador, ni la sesión — por eso se puede verificar exhaustivamente,
 * incluidos los casos que a mano son imposibles de reproducir (cruce del meridiano 180,
 * coordenadas inválidas, el ecuador vs. latitudes altas).
 *
 * Historial que motivó extraerla: la versión anterior vivía dentro de `useGeoAndOfflineSync`
 * mezclada con el manejo del GPS del navegador, y arrastró un bug documentado en su propio
 * comentario — las coordenadas de la tienda estaban escritas a mano apuntando al Zócalo de
 * CDMX, así que la geocerca fallaba para cualquier sucursal fuera de ese punto exacto.
 *
 * Nota de diseño (§67.E.2): la geocerca es una **medición**, no un permiso. El dispositivo
 * puede determinarla solo, sin consultar al servidor. Por eso sí admite decisión local
 * cuando no hay conexión — a diferencia del bloqueo por retardos, que es una restricción
 * sobre la persona y nunca debe poder levantarse por falta de red.
 */

const EARTH_RADIUS_METERS = 6371e3;
export const DEFAULT_RADIUS_METERS = 50;

export interface Coordinates {
  latitude: number;
  longitude: number;
}

export interface GeofenceInput {
  /** Dónde está el colaborador según el dispositivo. */
  current: Coordinates;
  /** Dónde está la sucursal, según la configuración del tenant. */
  store: Coordinates;
  /** Radio permitido en metros. Si no viene o es inválido, se usa DEFAULT_RADIUS_METERS. */
  radiusMeters?: number | null;
  /**
   * true cuando la validación por GPS está desactivada por configuración del administrador
   * (`gpsValidationEnabled === false`, `allowManualCheckIn`, o modo simulación).
   */
  bypassed?: boolean;
  /** true solo si el navegador entregó una lectura de GPS confiable. */
  hasReliableFix?: boolean;
}

export interface GeofenceResult {
  /** Distancia en metros entre el colaborador y la sucursal. Redondeada a entero. */
  distanceMeters: number;
  /** Veredicto final: ¿se le permite fichar por ubicación? */
  isWithinPerimeter: boolean;
  /** Radio efectivamente aplicado. */
  radiusMeters: number;
  /** true si pasó por configuración y no por estar realmente dentro del radio. */
  wasBypassed: boolean;
  /** true si alguna coordenada no era utilizable y se tomó una decisión conservadora. */
  hasInvalidCoordinates: boolean;
}

/** ¿Es una coordenada usable? Rechaza NaN, infinitos y rangos imposibles. */
export function isValidCoordinate(c: Coordinates | null | undefined): boolean {
  if (!c) return false;
  const { latitude: lat, longitude: lng } = c;
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;
  if (lat < -90 || lat > 90) return false;
  if (lng < -180 || lng > 180) return false;
  return true;
}

/**
 * Distancia entre dos puntos sobre la superficie terrestre (fórmula de Haversine), en metros.
 * Devuelve NaN si alguna coordenada es inutilizable — quien la use debe contemplarlo.
 */
export function getDistanceInMeters(a: Coordinates, b: Coordinates): number {
  if (!isValidCoordinate(a) || !isValidCoordinate(b)) return NaN;

  const phi1 = (a.latitude * Math.PI) / 180;
  const phi2 = (b.latitude * Math.PI) / 180;
  const deltaPhi = ((b.latitude - a.latitude) * Math.PI) / 180;
  const deltaLambda = ((b.longitude - a.longitude) * Math.PI) / 180;

  const h =
    Math.sin(deltaPhi / 2) * Math.sin(deltaPhi / 2) +
    Math.cos(phi1) * Math.cos(phi2) * Math.sin(deltaLambda / 2) * Math.sin(deltaLambda / 2);

  return EARTH_RADIUS_METERS * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

export function resolveRadius(value?: number | null): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
    return DEFAULT_RADIUS_METERS;
  }
  return value;
}

export function evaluateGeofence(input: GeofenceInput): GeofenceResult {
  const radiusMeters = resolveRadius(input.radiusMeters);
  const coordsOk = isValidCoordinate(input.current) && isValidCoordinate(input.store);
  const rawDistance = coordsOk ? getDistanceInMeters(input.current, input.store) : NaN;
  const distanceMeters = Number.isFinite(rawDistance) ? Math.round(rawDistance) : NaN;

  // El bypass por configuración manda sobre todo lo demás: es una decisión explícita del
  // administrador (tiendas sin cobertura, validación desactivada, o simulación).
  if (input.bypassed) {
    return {
      distanceMeters,
      isWithinPerimeter: true,
      radiusMeters,
      wasBypassed: true,
      hasInvalidCoordinates: !coordsOk,
    };
  }

  // Sin coordenadas utilizables o sin lectura confiable, NO se afirma que esté dentro.
  // Decisión conservadora deliberada: dar por válido un perímetro que no se pudo medir
  // convertiría "el GPS falló" en la forma de saltarse la geocerca.
  const isWithinPerimeter =
    coordsOk && input.hasReliableFix !== false && Number.isFinite(rawDistance)
      ? rawDistance <= radiusMeters
      : false;

  return { distanceMeters, isWithinPerimeter, radiusMeters, wasBypassed: false, hasInvalidCoordinates: !coordsOk };
}

/**
 * ¿El colaborador se está ACERCANDO a la sucursal? (estado #6 "En Camino a Sucursal").
 * Recibe muestras ordenadas de la más antigua a la más reciente.
 */
export function isApproaching(distanceSamples: number[], minImprovementMeters = 10): boolean {
  const valid = distanceSamples.filter((d) => Number.isFinite(d));
  if (valid.length < 2) return false;
  return valid[0] - valid[valid.length - 1] >= minImprovementMeters;
}
