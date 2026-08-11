<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Horario de la EMPRESA para las altas que no traen uno (decisión del dueño, 2026-08-08).
 *
 * Vivía como método privado de EmployeeController, así que el alta de RRHH lo respetaba y el
 * alta del ATS —que escribe `Employee::create` por su cuenta— clavaba 09:00–18:00 en duro. A una
 * empresa que abre a las 11:00 eso le cobra dos horas de retardo desde el primer día.
 *
 * El horario vive en `system_settings.storeSchedule` (openTime/closeTime), que es lo que
 * configura el asistente de alta.
 */
class HorarioDeLaEmpresa
{
    /** Defaults de último recurso, sólo si la empresa no ha configurado su horario. */
    public const APERTURA_POR_DEFECTO = '09:00';
    public const CIERRE_POR_DEFECTO = '18:00';

    /**
     * Rellena shiftStart/shiftEnd con el horario de la empresa cuando falten.
     * Un horario declarado en el alta SIEMPRE manda sobre el de la empresa.
     */
    public static function completar(array $data, ?int $tenantId): array
    {
        $faltaInicio = empty($data['shiftStart']);
        $faltaFin = empty($data['shiftEnd']);

        if (!$faltaInicio && !$faltaFin) {
            return $data;
        }

        [$apertura, $cierre] = static::deLaEmpresa($tenantId);

        if ($faltaInicio) {
            $data['shiftStart'] = $apertura;
        }
        if ($faltaFin) {
            $data['shiftEnd'] = $cierre;
        }

        return $data;
    }

    /** @return array{0:string,1:string} [apertura, cierre] de la empresa, o los defaults. */
    public static function deLaEmpresa(?int $tenantId): array
    {
        $horario = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', 'storeSchedule')
            ->value('value');

        $horario = $horario ? json_decode($horario, true) : null;

        return [
            $horario['openTime'] ?? static::APERTURA_POR_DEFECTO,
            $horario['closeTime'] ?? static::CIERRE_POR_DEFECTO,
        ];
    }
}
