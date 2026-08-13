<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Bloque 2 / D3 (2026-08-13): días de retención del chat de equipo, por empresa.
 * 7 por defecto, tope 30 (decisión del dueño). Lo leen la purga nocturna y el Monitor
 * (que lo muestra en la pantalla del chat: la retención que no se anuncia es una emboscada).
 */
class RetencionChat
{
    public const DIAS_POR_DEFECTO = 7;
    public const DIAS_TOPE = 30;

    public static function dias(int $tenantId): int
    {
        $crudo = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', 'chatRetentionDays')
            ->value('value');

        $valor = is_string($crudo) ? json_decode($crudo, true) : $crudo;

        // Ronda adversarial: un JSON no numérico casteado a (int) daba 1 — la retención MÁS
        // agresiva, en silencio. Ante basura, la dirección segura es retener MÁS: default 7.
        if (!is_numeric($valor) || (int) $valor <= 0) {
            return self::DIAS_POR_DEFECTO;
        }

        return min(self::DIAS_TOPE, (int) $valor);
    }
}
