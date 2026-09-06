<?php

namespace App\Support;

/**
 * La ÚNICA cuenta de los minutos que una persona trabajó en un día.
 *
 * (2026-09-05) Esta fórmula vivía como método PRIVADO de `ReportesOperativosController`
 * (`minutosDeJornada`). Mientras nadie más midiera horas daba igual; en cuanto hizo falta un
 * acumulador de tiempo extraordinario, copiarla habría creado la segunda cuenta del mismo dato —
 * el defecto que este proyecto lleva ocho rondas quitando (la tarjeta de $4,875 contra los $13,125
 * de la nómina; los 70 minutos de comida que daban 10 de exceso en el reporte y 0 en el motor).
 *
 * Así que se saca a `App\Support`, igual que se hizo con `ExcesoDeDescanso`, y la consumen los dos:
 * el reporte de horas trabajadas y `JornadaExtraordinaria`. Si un día cambia la definición de
 * "horas efectivas", cambia en un solo lugar y las dos pantallas siguen diciendo lo mismo.
 *
 * REGLAS (las que ya tenía el reporte, palabra por palabra):
 *  - Se empareja check_in con check_out en orden de INSTANTE, no de hora del reloj: en un turno
 *    22:00–02:00 la salida de las 02:00 es POSTERIOR a la entrada de las 22:00 aunque "02" < "22".
 *  - Un turno sin cerrar NO produce minutos: no se inventan horas que nadie vio.
 *  - Comida y descansos se restan de las horas en sucursal para dar las EFECTIVAS.
 *  - La observación dice qué pasó cuando la jornada quedó rara (sin salida, reentrada, comida
 *    abierta, cierre automático del sistema), porque una fila que no lo dice obliga a adivinar.
 */
class JornadaTrabajada
{
    /**
     * @param  array  $marcas  Fichajes del día (objetos con ->type, ->time y ->details).
     * @return array{entrada:?string, salida:?string, brutos:int, comida:int, descanso:int, efectivos:int, incompleta:string}
     */
    public static function delDia(
        array $marcas,
        string $fechaJornada,
        ?string $turnoInicio,
        ?string $turnoFin,
        string $zona
    ): array {
        $instante = function ($m) use ($fechaJornada, $turnoInicio, $turnoFin, $zona) {
            return JornadaLaboral::instanteDe(
                $fechaJornada,
                substr((string) $m->time, 0, 8),
                $turnoInicio,
                $turnoFin,
                $zona
            );
        };

        // Se ordena por el INSTANTE, no por la hora del reloj: en un turno 22:00–02:00 la
        // salida de las 02:00 es POSTERIOR a la entrada de las 22:00, aunque "02" < "22".
        // Ordenar por la hora cruda emparejaba la salida con nada y daba 0 horas.
        usort($marcas, fn ($a, $b) => $instante($a) <=> $instante($b));

        $brutos = 0; $comida = 0; $descanso = 0;
        $abierto = null; $comidaAbierta = null; $descansoAbierto = null;
        $entrada = null; $salida = null; $autoCerrada = false;

        foreach ($marcas as $m) {
            $t = $instante($m);
            switch ($m->type) {
                case 'check_in':
                    $abierto = $t;
                    $entrada = $entrada ?? substr((string) $m->time, 0, 5);
                    break;
                case 'check_out':
                    if ($abierto) {
                        // (int): Carbon 3 devuelve FLOAT en diffInMinutes. El reporte no lo notaba
                        // porque `hhmm(int $minutos)` lo coaccionaba al imprimir, pero cualquier
                        // otro consumidor recibía 120.0 donde la firma promete 120. Se trunca —no
                        // se redondea— para conservar exactamente lo que el reporte venía dando.
                        $brutos += (int) max(0, $abierto->diffInMinutes($t));
                        $abierto = null;
                    }
                    $salida = substr((string) $m->time, 0, 5);
                    $detalles = json_decode((string) ($m->details ?? ''), true);
                    if (!empty($detalles['auto_closed'])) {
                        $autoCerrada = true;
                    }
                    break;
                case 'meal_start':   $comidaAbierta = $t; break;
                case 'meal_end':
                    if ($comidaAbierta) { $comida += (int) max(0, $comidaAbierta->diffInMinutes($t)); $comidaAbierta = null; }
                    break;
                case 'break_start':  $descansoAbierto = $t; break;
                case 'break_end':
                    if ($descansoAbierto) { $descanso += (int) max(0, $descansoAbierto->diffInMinutes($t)); $descansoAbierto = null; }
                    break;
            }
        }

        $observacion = '';
        if ($autoCerrada) {
            $observacion = 'Cerrada por el sistema (olvidó checar salida)';
        } elseif ($abierto && $brutos > 0) {
            // (2026-08-22) Quien salió y VOLVIÓ a entrar el mismo día deja un turno abierto sobre
            // horas que sí se contaron (las del turno que cerró). La fila decía a la vez "salida
            // 09:09 · 6:22 horas" y "sin salida registrada: no se cuentan horas" — dos afirmaciones
            // contrarias en el mismo renglón. Se cuentan las cerradas y se dice cuál queda abierta.
            $observacion = 'Volvió a entrar y no cerró ese turno: sólo se cuentan las horas ya cerradas';
        } elseif ($abierto) {
            $observacion = 'Sin salida registrada: no se cuentan horas';
        } elseif ($comidaAbierta) {
            $observacion = 'Comida sin cerrar';
        }

        return [
            'entrada' => $entrada, 'salida' => $salida,
            'brutos' => $brutos, 'comida' => $comida, 'descanso' => $descanso,
            'efectivos' => max(0, $brutos - $comida - $descanso),
            'incompleta' => $observacion,
        ];
    }

    /**
     * Los tipos de fichaje que esta cuenta necesita. Se declara aquí para que el reporte y el
     * acumulador consulten EXACTAMENTE lo mismo: si un caller trae menos tipos, mide de menos.
     */
    public const TIPOS = ['check_in', 'check_out', 'meal_start', 'meal_end', 'break_start', 'break_end'];
}
