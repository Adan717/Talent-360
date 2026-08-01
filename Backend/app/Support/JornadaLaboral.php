<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * H21 — el "día de negocio" de un fichaje, para turnos que CRUZAN MEDIANOCHE.
 *
 * EL PROBLEMA. `processPunch` asignaba `date = now->format('Y-m-d')`: el día CALENDARIO. Con un
 * turno 22:00–02:00 eso parte cada jornada en dos:
 *
 *     2026-07-29  check_in   22:00
 *     2026-07-30  check_out  02:00
 *
 * Y de ahí salían dos daños medidos en vivo:
 *
 *  1. La nómina cuenta días asistidos, así que UNA noche generaba DOS: las faltas de la semana
 *     bajaban de 5 a 4 y el neto se duplicaba (1 652.78 → 3 305.56) por una sola jornada.
 *  2. El retardo se medía contra el reloj del día calendario: un check-in a las 00:30 con turno
 *     de 22:00 —2h30 tarde— salía PUNTUAL, porque 30 < 1320.
 *
 * LA REGLA. Sólo se aplica cuando el turno del colaborador cruza medianoche (`shiftStart` posterior
 * a `shiftEnd`). El corte es el **punto medio del hueco** entre el fin de un turno y el inicio del
 * siguiente: todo lo anterior pertenece a la jornada que empezó el día previo.
 *
 * Con 22:00–02:00 el hueco va de 02:00 a 22:00 y el corte queda en las 12:00. Así:
 *   - fichar 22:00  → jornada de HOY (empieza)
 *   - fichar 02:00  → jornada de AYER (cierra)
 *   - fichar 03:00  → jornada de AYER (salió tarde, sigue contando igual)
 *   - fichar 21:00  → jornada de HOY (llegó temprano)
 *
 * El punto medio se elige en vez de un margen fijo porque se adapta solo a cualquier turno: con
 * 23:00–07:00 el corte cae a las 15:00, que es igual de sensato, sin números mágicos por caso.
 *
 * POR QUÉ ESTO ARREGLA TAMBIÉN EL RETARDO. `processPunch` ancla la hora esperada a `$date`
 * (`"$date $shiftStart"`). Corregida la fecha, un fichaje de las 00:30 se compara contra las 22:00
 * del día ANTERIOR y da 150 minutos, en vez de compararse contra las 22:00 del mismo día y dar
 * cero. Un solo cambio, no dos.
 *
 * ALCANCE DELIBERADAMENTE ESTRECHO: si el turno NO cruza medianoche, o falta alguno de los dos
 * datos, se devuelve la fecha calendario — exactamente lo de antes. El cambio no puede alterar el
 * comportamiento de ningún turno diurno, que son la inmensa mayoría.
 */
class JornadaLaboral
{
    private const MINUTOS_POR_DIA = 1440;

    /** "22:00:00" → 1320. `null` si el valor no es una hora interpretable. */
    public static function aMinutos(?string $hora): ?int
    {
        if (!is_string($hora)) {
            return null;
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($hora), $m)) {
            return null;
        }

        $h = (int) $m[1];
        $i = (int) $m[2];

        if ($h < 0 || $h > 23 || $i < 0 || $i > 59) {
            return null;
        }

        return $h * 60 + $i;
    }

    /**
     * ¿El turno termina al día siguiente de empezar?
     *
     * Un turno de 24h exactas (inicio == fin) NO se considera cruzado: no hay hueco que partir y
     * tratarlo como nocturno sólo añadiría un corte arbitrario.
     */
    public static function cruzaMedianoche(?string $inicio, ?string $fin): bool
    {
        $i = self::aMinutos($inicio);
        $f = self::aMinutos($fin);

        if ($i === null || $f === null) {
            return false;
        }

        return $i > $f;
    }

    /**
     * Minuto del día en el que una jornada nocturna deja de pertenecer al día anterior.
     * Devuelve `null` si el turno no cruza medianoche.
     */
    public static function minutoDeCorte(?string $inicio, ?string $fin): ?int
    {
        if (!self::cruzaMedianoche($inicio, $fin)) {
            return null;
        }

        $i = self::aMinutos($inicio);
        $f = self::aMinutos($fin);

        // Hueco entre el fin del turno y el comienzo del siguiente; el corte va a su mitad.
        return (int) floor($f + (($i - $f) / 2));
    }

    /**
     * La fecha a la que pertenece un fichaje: el día en que EMPEZÓ la jornada.
     *
     * `$instante` debe venir ya en la zona del tenant. Para turnos diurnos —o cuando falta el
     * horario— devuelve su propia fecha, sin tocar nada.
     */
    public static function fechaDeNegocio(Carbon $instante, ?string $inicio, ?string $fin): string
    {
        $corte = self::minutoDeCorte($inicio, $fin);

        if ($corte === null) {
            return $instante->format('Y-m-d');
        }

        $minutoActual = $instante->hour * 60 + $instante->minute;

        return $minutoActual < $corte
            ? $instante->copy()->subDay()->format('Y-m-d')
            : $instante->format('Y-m-d');
    }

    /**
     * La operación INVERSA: reconstruye el instante real de un fichaje ya guardado.
     *
     * Hace falta porque `time_entries` guarda la fecha de JORNADA y la hora por separado, y
     * pegarlas sin más (`"$date $time"`) coloca los fichajes de madrugada 24 horas antes de
     * cuando ocurrieron: un check-in de las 00:30 se guarda con la fecha del día anterior, así
     * que "29 00:30" apunta a un instante que nunca existió.
     *
     * Con eso, cualquier duración medida contra ese punto sale disparada — por ejemplo el mínimo
     * de turno antes de poder comer daba ~24 h y dejaba pasar la comida de inmediato.
     */
    public static function instanteDe(
        string $fechaJornada,
        string $hora,
        ?string $inicio,
        ?string $fin,
        string $zona
    ): Carbon {
        $hhmmss = strlen(trim($hora)) === 5 ? trim($hora) . ':00' : trim($hora);
        $instante = Carbon::createFromFormat('Y-m-d H:i:s', "$fechaJornada $hhmmss", $zona);

        $corte = self::minutoDeCorte($inicio, $fin);
        if ($corte === null) {
            return $instante;
        }

        $minuto = self::aMinutos($hora);
        if ($minuto === null) {
            return $instante;
        }

        // Si la hora cae antes del corte, ese fichaje ocurrió ya entrado el día SIGUIENTE al que
        // la jornada tiene asignado.
        return $minuto < $corte ? $instante->addDay() : $instante;
    }
}
