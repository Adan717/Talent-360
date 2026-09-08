<?php

namespace App\Support;

/**
 * Depura lo que la IA propone tras leer un reglamento interior (Plan A3, 2026-09-07).
 *
 * POR QUÉ EXISTE: el modelo devuelve JSON libre. Antes de que llegue a la pantalla, TODO pasa por
 * la misma lista blanca que acepta `LftSettingController::saveSettings` — claves desconocidas se
 * tiran, tipos se fuerzan, y lo que la ley no permite (más de 9 h extra por semana, art. 66) se
 * recorta al techo y se AVISA. Así la propuesta que ve el admin ya es guardable tal cual, y nunca
 * puede colar una clave o un valor que el servidor rechazaría después.
 *
 * NO escribe nada: es una función pura. Guardar sigue siendo un clic del admin en la pantalla de
 * LFT, que pasa por `saveSettings` con su validación completa.
 */
final class PropuestaDeReglamentoLft
{
    /** Clave => tipo. Las mismas que valida saveSettings y que la pantalla sabe pintar. */
    public const CLAVES = [
        'late_tolerance_minutes' => 'entero',
        'meal_tolerance_minutes' => 'entero',
        'rest_tolerance_minutes' => 'entero',
        'lates_per_absence' => 'entero_positivo',
        'absences_for_warning' => 'entero_positivo',
        'absences_for_suspension' => 'entero_positivo',
        'deduct_absence_day' => 'booleano',
        'proportional_rest_day' => 'booleano',
        'paid_rest_day' => 'booleano',
        'late_action_mode' => 'modo_retardo',
        'overtime_weekly_cap_minutes' => 'minutos_extra',
    ];

    public const MODOS_DE_RETARDO = ['deduct', 'extend_shift'];

    /** Tope razonable para minutos de tolerancia: más de un turno entero no es tolerancia. */
    private const MAX_MINUTOS_TOLERANCIA = 480;

    /**
     * @param  array $cruda  Lo que devolvió `GeminiAIService::leerReglamentoLft`.
     * @return array {propuesta: {clave: {valor, cita, confianza}}, articulos: [], advertencias: [], descartadas: []}
     */
    public static function depurar(array $cruda): array
    {
        $propuesta = [];
        $advertencias = [];
        $descartadas = [];

        $reglas = is_array($cruda['reglas'] ?? null) ? $cruda['reglas'] : [];

        foreach ($reglas as $clave => $regla) {
            if (!isset(self::CLAVES[$clave])) {
                $descartadas[] = (string) $clave;
                continue;
            }

            $valorCrudo = is_array($regla) ? ($regla['valor'] ?? null) : $regla;
            $cita = is_array($regla) ? trim((string) ($regla['cita'] ?? '')) : '';
            $confianza = is_array($regla) ? (string) ($regla['confianza'] ?? 'baja') : 'baja';
            if (!in_array($confianza, ['alta', 'media', 'baja'], true)) {
                $confianza = 'baja';
            }

            if ($valorCrudo === null || $valorCrudo === '') {
                continue;
            }

            [$valor, $aviso] = self::coaccionar(self::CLAVES[$clave], $valorCrudo, $clave);
            if ($valor === null) {
                $descartadas[] = (string) $clave;
                if ($aviso) {
                    $advertencias[] = $aviso;
                }
                continue;
            }
            if ($aviso) {
                $advertencias[] = $aviso;
            }

            $propuesta[$clave] = [
                'valor' => $valor,
                'cita' => mb_substr($cita, 0, 400),
                'confianza' => $confianza,
            ];
        }

        // Coherencia interna: la suspensión no puede llegar antes que la llamada de atención.
        if (isset($propuesta['absences_for_warning'], $propuesta['absences_for_suspension'])
            && $propuesta['absences_for_suspension']['valor'] < $propuesta['absences_for_warning']['valor']) {
            $advertencias[] = 'El reglamento fija la suspensión con MENOS faltas que la llamada de atención; revísalo antes de guardar.';
        }

        $articulos = [];
        foreach ((array) ($cruda['articulos'] ?? []) as $art) {
            if (!is_array($art)) {
                continue;
            }
            $ref = trim((string) ($art['referencia'] ?? ''));
            $res = trim((string) ($art['resumen'] ?? ''));
            if ($ref === '' && $res === '') {
                continue;
            }
            $articulos[] = ['referencia' => mb_substr($ref, 0, 80), 'resumen' => mb_substr($res, 0, 300)];
        }

        foreach ((array) ($cruda['advertencias'] ?? []) as $adv) {
            if (is_string($adv) && trim($adv) !== '') {
                $advertencias[] = mb_substr(trim($adv), 0, 300);
            }
        }

        return [
            'propuesta' => $propuesta,
            'articulos' => array_slice($articulos, 0, 30),
            'advertencias' => array_values(array_unique($advertencias)),
            'descartadas' => array_values(array_unique($descartadas)),
        ];
    }

    /** @return array{0: mixed, 1: ?string} [valor depurado o null, aviso o null] */
    private static function coaccionar(string $tipo, mixed $crudo, string $clave): array
    {
        switch ($tipo) {
            case 'booleano':
                if (is_bool($crudo)) {
                    return [$crudo, null];
                }
                $texto = strtolower(trim((string) $crudo));
                if (in_array($texto, ['1', 'true', 'sí', 'si', 'yes'], true)) {
                    return [true, null];
                }
                if (in_array($texto, ['0', 'false', 'no'], true)) {
                    return [false, null];
                }
                return [null, "«{$clave}»: la IA devolvió «{$crudo}», que no es sí/no; se descartó."];

            case 'modo_retardo':
                $texto = strtolower(trim((string) $crudo));
                if (in_array($texto, self::MODOS_DE_RETARDO, true)) {
                    return [$texto, null];
                }
                return [null, "«{$clave}»: «{$crudo}» no es un modo válido (deduct/extend_shift); se descartó."];

            case 'entero':
            case 'entero_positivo':
            case 'minutos_extra':
                if (!is_numeric($crudo)) {
                    return [null, "«{$clave}»: la IA devolvió «{$crudo}», que no es un número; se descartó."];
                }
                $n = (int) round((float) $crudo);
                if ($tipo === 'entero_positivo' && $n < 1) {
                    return [null, "«{$clave}»: debe ser al menos 1; se descartó el {$n}."];
                }
                if ($n < 0) {
                    return [null, "«{$clave}»: no puede ser negativo; se descartó el {$n}."];
                }
                if ($tipo === 'minutos_extra' && $n > JornadaExtraordinaria::TECHO_LFT_MINUTOS_SEMANA) {
                    return [
                        JornadaExtraordinaria::TECHO_LFT_MINUTOS_SEMANA,
                        "El reglamento permite {$n} minutos de tiempo extra por semana; la LFT (art. 66) tope en "
                        . JornadaExtraordinaria::TECHO_LFT_MINUTOS_SEMANA . '. Se propone el máximo legal.',
                    ];
                }
                if ($tipo === 'entero' && str_ends_with($clave, '_minutes') && $n > self::MAX_MINUTOS_TOLERANCIA) {
                    return [null, "«{$clave}»: {$n} minutos no parece una tolerancia; se descartó."];
                }
                return [$n, null];
        }

        return [null, null];
    }
}
