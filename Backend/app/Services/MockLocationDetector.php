<?php

namespace App\Services;

/**
 * MockLocationDetector
 *
 * Detecta si un dispositivo está usando GPS falso (Mock Location / GPS Spoofing)
 * en el momento del fichaje. Implementa 4 heurísticas apiladas.
 *
 * SPEC Pilar III: "El sistema valida coordenadas GPS enviadas por el dispositivo
 * para garantizar que el colaborador está físicamente en la sucursal. Si se
 * detecta uso de aplicaciones de GPS falso, el fichaje es rechazado."
 *
 * Las 4 capas de detección:
 * 1. Accuracy Check — GPS falso reporta precisiones perfectas (0-5m) o irreales (>5000m)
 * 2. Speed Check    — Velocidad física imposible entre dos fichajes consecutivos
 * 3. Altitude Check — Altitudes imposibles (> 8849m o < -500m)
 * 4. Provider Check — Si el cliente reporta el provider como "mock" o "fused" con alta precision
 */
class MockLocationDetector
{
    // Umbrales de detección
    private const MIN_REALISTIC_ACCURACY_METERS  = 3;    // < 3m = demasiado perfecto para GPS real
    private const MAX_REALISTIC_ACCURACY_METERS  = 500;  // > 500m = GPS muy malo, posible mock
    private const MAX_HUMAN_SPEED_KMH            = 200;  // > 200 km/h entre fichajeS = imposible a pie
    private const MAX_ALTITUDE_METERS            = 8849; // Everest
    private const MIN_ALTITUDE_METERS            = -500; // Mar Muerto aprox

    /**
     * Analiza las coordenadas GPS de un fichaje y determina si son confiables.
     *
     * @param array  $gpsData  {
     *   latitude: float,
     *   longitude: float,
     *   accuracy: float,          // metros (precisión reportada por el dispositivo)
     *   altitude: float|null,     // metros sobre el nivel del mar
     *   speed: float|null,        // m/s (velocidad actual del dispositivo)
     *   provider: string|null,    // "gps" | "network" | "fused" | "mock" | null
     *   timestamp: int|null       // Unix timestamp del GPS
     * }
     * @param array|null $previousLocation  Último fichaje registrado {latitude, longitude, timestamp}
     * @return array  {is_mock: bool, confidence: float, flags: string[], risk_level: string}
     */
    public function analyze(array $gpsData, ?array $previousLocation = null): array
    {
        $flags      = [];
        $riskScore  = 0.0;

        $lat      = $gpsData['latitude']  ?? null;
        $lng      = $gpsData['longitude'] ?? null;
        $accuracy = $gpsData['accuracy']  ?? null;
        $altitude = $gpsData['altitude']  ?? null;
        $speed    = $gpsData['speed']     ?? null;
        $provider = strtolower($gpsData['provider'] ?? '');

        // ── 1. ACCURACY CHECK ──────────────────────────────────────────────
        if ($accuracy !== null) {
            if ($accuracy < self::MIN_REALISTIC_ACCURACY_METERS) {
                $flags[]    = "accuracy_too_perfect:{$accuracy}m";
                $riskScore += 0.4; // Alta probabilidad de mock
            } elseif ($accuracy > self::MAX_REALISTIC_ACCURACY_METERS) {
                $flags[]    = "accuracy_too_poor:{$accuracy}m";
                $riskScore += 0.15;
            }
        }

        // ── 2. PROVIDER CHECK ──────────────────────────────────────────────
        if (str_contains($provider, 'mock') || str_contains($provider, 'passive')) {
            $flags[]    = "provider_suspicious:{$provider}";
            $riskScore += 0.5; // Mock declarado explícitamente
        }

        // ── 3. ALTITUDE CHECK ──────────────────────────────────────────────
        if ($altitude !== null) {
            if ($altitude > self::MAX_ALTITUDE_METERS || $altitude < self::MIN_ALTITUDE_METERS) {
                $flags[]    = "altitude_impossible:{$altitude}m";
                $riskScore += 0.4;
            }
        }

        // ── 4. COORDINATES VALIDITY ────────────────────────────────────────
        if ($lat !== null && $lng !== null) {
            // Coordenadas (0,0) = Null Island — clásico de GPS falso mal configurado
            if (abs($lat) < 0.01 && abs($lng) < 0.01) {
                $flags[]    = "null_island_coordinates";
                $riskScore += 0.6;
            }

            // Fuera de México (bounding box aproximado)
            if ($lat < 14.5 || $lat > 32.7 || $lng < -117.2 || $lng > -86.7) {
                $flags[]    = "coordinates_outside_mexico";
                $riskScore += 0.3;
            }
        }

        // ── 5. SPEED / TRAVEL IMPOSSIBILITY CHECK ─────────────────────────
        if ($previousLocation && $lat !== null && $lng !== null) {
            $prevLat       = $previousLocation['latitude']  ?? null;
            $prevLng       = $previousLocation['longitude'] ?? null;
            $prevTimestamp = $previousLocation['timestamp'] ?? null;
            $currTimestamp = $gpsData['timestamp']          ?? time();

            if ($prevLat !== null && $prevLng !== null && $prevTimestamp !== null) {
                $distanceKm  = $this->haversine($lat, $lng, $prevLat, $prevLng);
                $timeDiffHrs = max(($currTimestamp - $prevTimestamp) / 3600, 0.001);
                $speedKmh    = $distanceKm / $timeDiffHrs;

                if ($speedKmh > self::MAX_HUMAN_SPEED_KMH) {
                    $flags[]    = "impossible_travel_speed:" . round($speedKmh) . "kmh";
                    $riskScore += 0.5;
                }
            }
        }

        // ── RESULTADO FINAL ────────────────────────────────────────────────
        $riskScore  = min($riskScore, 1.0);
        $confidence = round($riskScore * 100, 1); // 0-100%
        $isMock     = $riskScore >= 0.5;

        $riskLevel = match(true) {
            $riskScore >= 0.7 => 'critico',
            $riskScore >= 0.5 => 'alto',
            $riskScore >= 0.3 => 'medio',
            default           => 'bajo',
        };

        return [
            'is_mock'    => $isMock,
            'confidence' => $confidence,
            'risk_score' => round($riskScore, 3),
            'risk_level' => $riskLevel,
            'flags'      => $flags,
            'verdict'    => $isMock
                ? "GPS falso detectado (confianza: {$confidence}%). Fichaje rechazado."
                : "GPS válido (riesgo: {$riskLevel}).",
        ];
    }

    /**
     * Valida si las coordenadas están dentro del radio autorizado de la sucursal.
     *
     * @param float $empLat     Latitud del empleado
     * @param float $empLng     Longitud del empleado
     * @param float $storeLat   Latitud de la sucursal
     * @param float $storeLng   Longitud de la sucursal
     * @param float $radiusM    Radio permitido en metros (default: 100m)
     * @return array  {inside_geofence: bool, distance_meters: float}
     */
    public function validateGeofence(float $empLat, float $empLng, float $storeLat, float $storeLng, float $radiusM = 100.0): array
    {
        $distanceKm = $this->haversine($empLat, $empLng, $storeLat, $storeLng);
        $distanceM  = $distanceKm * 1000;
        $inside     = $distanceM <= $radiusM;

        return [
            'inside_geofence' => $inside,
            'distance_meters' => round($distanceM, 1),
            'radius_meters'   => $radiusM,
            'message'         => $inside
                ? "Dentro del geofence (a {$distanceM}m de la sucursal)."
                : "Fuera del geofence: estás a " . round($distanceM) . "m pero el radio máximo es {$radiusM}m.",
        ];
    }

    /**
     * Fórmula de Haversine para calcular distancia entre dos coordenadas.
     * Retorna distancia en kilómetros.
     */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R   = 6371; // Radio de la Tierra en km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * asin(sqrt($a));

        return $R * $c;
    }
}
