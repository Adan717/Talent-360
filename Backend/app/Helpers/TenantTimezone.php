<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Zona horaria del negocio por tenant (system_settings.timezone), default America/Mexico_City.
 *
 * Unifica el resolver que estaba triplicado —y con robustez desigual— en ClockService
 * (trim ingenuo, sin json_decode ni validación), CloseOrphanShifts (sin validar la tz →
 * una tz mistyped reventaba Carbon después) y StoreOpeningService (la versión buena).
 * Ahora todos delegan aquí.
 */
class TenantTimezone
{
    public const DEFAULT = 'America/Mexico_City';

    /**
     * Resuelve la tz desde un arreglo de system_settings ya consultado (pluck value->key),
     * evitando una query extra cuando el caller ya lo tiene.
     *
     * Acepta tanto el valor json-encoded ("America\/..") como el crudo (America/..) —
     * ClockController::syncSettings guarda strings tal cual sin json_encode. Valida contra
     * DateTimeZone y cae al default si la zona es inválida: una tz mal escrita (legacy/typo)
     * no debe reventar el módulo con un 500.
     */
    public static function fromSettings(array $settings): string
    {
        $raw = $settings['timezone'] ?? null;

        if (is_string($raw) && $raw !== '') {
            // json_decode primero: maneja el valor json-encoded ("America\/New_York" — nota que
            // json_encode escapa la barra). Si no es un JSON string válido, es un string crudo:
            // se le quitan comillas por si acaso.
            $decoded = json_decode($raw, true);
            $tz = is_string($decoded) && $decoded !== '' ? $decoded : trim($raw, '"');

            try {
                new \DateTimeZone($tz);
                return $tz;
            } catch (\Exception $e) {
                // tz inválida (mistyped/legacy) → default.
            }
        }

        return self::DEFAULT;
    }

    /**
     * Consulta system_settings del tenant y resuelve la tz. Úsese cuando el caller aún no
     * tiene el arreglo de settings; si ya lo tiene, preferir fromSettings().
     */
    public static function for($tenantId): string
    {
        $settings = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->pluck('value', 'key')
            ->toArray();

        return self::fromSettings($settings);
    }
}
