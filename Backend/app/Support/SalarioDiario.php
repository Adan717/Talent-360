<?php

namespace App\Support;

/**
 * Conversión de un sueldo capturado a SALARIO DIARIO — la única unidad en que el sistema
 * almacena dinero de nómina a partir de 2026-08.
 *
 * POR QUÉ EL DIARIO: es la unidad de la LFT (IMSS, aguinaldo, prima vacacional e
 * indemnizaciones se calculan sobre salario diario). No es una elección de ingeniería, es la
 * unidad del dominio. Antes `base_salary` no declaraba periodicidad y cada módulo lo
 * interpretaba distinto (nómina: semanal; costo de tarea: diario; facturación: mensual) —
 * ver docs/NOMINA_PERIODICIDAD_MULTIEMPRESA_2026-08-02.md.
 *
 * DIVISORES (los de la práctica LFT/SAT, no días hábiles):
 *   mensual/30 · quincenal/15 · semanal/7 · diario/1
 *
 * El 7 del semanal incluye el séptimo día (descanso pagado, art. 69 LFT): quien pacta
 * $1,400 a la semana gana $200 diarios, no $233. Es deliberadamente DISTINTO del /6 histórico
 * de ClockService — ese /6 sobre-pagaba (convertía 6 días de trabajo en 7 pagados inflando el
 * diario) y es parte de lo que el informe de impacto debe exhibir antes de migrar a nadie.
 */
class SalarioDiario
{
    public const DIVISORES = [
        'diario' => 1,
        'semanal' => 7,
        'quincenal' => 15,
        'mensual' => 30,
    ];

    public static function desde(float $monto, string $periodicidad): float
    {
        $divisor = self::DIVISORES[$periodicidad] ?? null;

        if ($divisor === null) {
            throw new \InvalidArgumentException(
                "Periodicidad desconocida '{$periodicidad}'. Válidas: " . implode(', ', self::periodicidades())
            );
        }

        return round($monto / $divisor, 2);
    }

    /** @return string[] */
    public static function periodicidades(): array
    {
        return array_keys(self::DIVISORES);
    }

    public static function esValida(?string $periodicidad): bool
    {
        return $periodicidad !== null && isset(self::DIVISORES[$periodicidad]);
    }
}
