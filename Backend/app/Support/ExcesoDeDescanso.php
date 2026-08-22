<?php

namespace App\Support;

/**
 * La ÚNICA fórmula del exceso de comida y de descanso.
 *
 * (2026-08-22) Antes había dos. El motor de nómina emparejaba cada `meal_start` con su
 * `meal_end` y cobraba el par sólo si pasaba de `permitido + tolerancia`; el reporte de Comedor
 * sumaba todos los segmentos del día e ignoraba la tolerancia por completo. Con permitido 60 y
 * tolerancia 15, una comida de 70 minutos daba **10 de exceso en el reporte y 0 en la nómina**;
 * dos comidas de 40, **20 en el reporte y 0 en la nómina**. Dos pantallas, el mismo día, cifras
 * distintas — y nadie podía saber cuál creer.
 *
 * Reglas (las del motor, que son las que se usan para juzgar a una persona):
 *  - Se empareja inicio con fin. Un inicio sin fin NO produce minutos: no hay dato para medirlo.
 *  - La tolerancia es UMBRAL, no franquicia: un par excede si dura más de permitido+tolerancia,
 *    y entonces se cobra (duración − permitido), no (duración − permitido − tolerancia).
 *  - Cada par se evalúa por separado: dos pausas cortas no se suman para inventar un exceso.
 *  - Se mide por el INSTANTE de la jornada, no por la hora cruda, para que una pausa que cruza
 *    la medianoche en un turno nocturno no se pierda ni se convierta en horas fantasma.
 */
class ExcesoDeDescanso
{
    /**
     * @param  iterable  $marcasDelDia  Registros del día con ->type y ->time.
     * @return array{minutos:int, exceso:int, abierta:bool, inicio:?string, fin:?string}
     */
    public static function calcular(
        iterable $marcasDelDia,
        string $fechaJornada,
        string $tipoInicio,
        string $tipoFin,
        int $permitido,
        int $tolerancia,
        ?string $turnoInicio = null,
        ?string $turnoFin = null,
        string $zona = 'UTC'
    ): array {
        $instante = fn ($m) => JornadaLaboral::instanteDe(
            $fechaJornada,
            substr((string) $m->time, 0, 8),
            $turnoInicio,
            $turnoFin,
            $zona
        );

        $pares = [];
        foreach ($marcasDelDia as $m) {
            if (in_array($m->type, [$tipoInicio, $tipoFin], true)) {
                $pares[] = $m;
            }
        }
        usort($pares, fn ($a, $b) => $instante($a)->getTimestamp() <=> $instante($b)->getTimestamp());

        $abiertos = [];
        $minutos = 0;
        $exceso = 0;
        $primerInicio = null;
        $ultimoFin = null;

        foreach ($pares as $m) {
            if ($m->type === $tipoInicio) {
                $abiertos[] = $instante($m);
                $primerInicio = $primerInicio ?? substr((string) $m->time, 0, 5);
            } elseif ($m->type === $tipoFin && !empty($abiertos)) {
                $inicio = array_pop($abiertos);
                $dura = intdiv($instante($m)->getTimestamp() - $inicio->getTimestamp(), 60);
                if ($dura > 0) {
                    $minutos += $dura;
                }
                if ($dura > ($permitido + $tolerancia)) {
                    $exceso += max(0, $dura - $permitido);
                }
                $ultimoFin = substr((string) $m->time, 0, 5);
            }
        }

        return [
            'minutos' => $minutos,
            'exceso' => $exceso,
            'abierta' => !empty($abiertos),
            'inicio' => $primerInicio,
            'fin' => $ultimoFin,
        ];
    }
}
