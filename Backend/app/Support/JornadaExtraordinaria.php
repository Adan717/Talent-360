<?php

namespace App\Support;

use App\Helpers\TenantTimezone;
use App\Models\Employee;
use App\Services\PayrollWeekService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TIEMPO EXTRAORDINARIO acumulado por semana y por persona, y el tope que cada empresa se pone.
 *
 * QUÉ NO EXISTÍA ANTES (2026-09-05). El producto no tenía tope de horas extra: ni constante, ni
 * ajuste, ni aviso, ni contador. Lo único que se llamaba "Autorizar horas extras"
 * (`overtime_authorizations`) NO autoriza horas: levanta el bloqueo de un DÍA feriado o de
 * descanso para que la persona pueda fichar. Una vez dentro, podía acumular las horas que fueran
 * sin que nadie contara ni avisara.
 *
 * DE DÓNDE SALEN LAS HORAS. De `JornadaTrabajada`, la MISMA clase que alimenta el reporte de horas
 * trabajadas — no de una segunda cuenta. Ése era el riesgo entero de este renglón: dos cifras del
 * mismo dato en dos pantallas (el historial de este proyecto está lleno de eso). La fórmula se
 * sacó del controlador de reportes a `App\Support` justo para poder compartirla, igual que se hizo
 * con `ExcesoDeDescanso`.
 *
 * QUÉ SEMANA. La del tenant, vía `PayrollWeekService::weekRangeFor` — la misma definición de
 * semana que usa la nómina (día de inicio configurable, no el lunes de Carbon). Se mide la semana
 * EN CURSO, porque el aviso sirve para llegar a tiempo, no para enterarse el viernes.
 *
 * QUÉ ES "EXTRAORDINARIO". Los minutos EFECTIVOS del día que pasan de la jornada ordinaria de esa
 * persona: `turno programado − su comida programada`. Ambos lados excluyen la comida, así que se
 * comparan peras con peras.
 *
 *   Quien no tiene turno configurado NO acumula tiempo extraordinario. Es deliberado: sin jornada
 *   ordinaria no hay de qué medir el exceso, y adivinarla (asumir 8 h, por ejemplo) sería inventar
 *   un número para acusar a alguien. Regla de la casa: adivina o avisa, nunca las dos cosas.
 *
 * ESTO NO TOCA EL DINERO. La nómina de este sistema paga por DÍA, no por horas (ver
 * `ClockService::calculatePayrollForEmployee`): el tiempo extraordinario no entra en ningún recibo,
 * y cómo se PAGA depende del divisor de la tarifa horaria, que es una pregunta abierta al contador
 * del dueño. Este acumulador es operativo y de cumplimiento: cuenta y avisa, no cobra ni descuenta.
 *
 * EL TOPE. `lft_settings.overtime_weekly_cap_minutes`, por empresa, con TECHO DURO en el máximo del
 * art. 66 de la LFT (3 horas diarias y no más de 3 veces por semana = 9 h). La empresa puede poner
 * uno MENOR; mayor lo rechaza el servidor. Y al rebasarlo sólo AVISA: no bloquea el fichaje ni la
 * jornada — criterio del dueño, "nada bloquea, todo avisa".
 */
class JornadaExtraordinaria
{
    /** LFT art. 66: la jornada se puede prolongar hasta 3 horas en un día. */
    public const TECHO_LFT_MINUTOS_DIA = 180;

    /** LFT art. 66: ...y no más de 3 veces en una semana. 3 h × 3 días = 9 h. */
    public const TECHO_LFT_MINUTOS_SEMANA = 540;

    /**
     * El tope de la empresa, en minutos por semana. Nunca por encima del techo de ley: si una fila
     * vieja o un UPDATE por SQL crudo dejara un valor mayor, aquí se recorta — la ley no depende de
     * que el formulario haya validado bien.
     */
    public static function tope(int $tenantId): int
    {
        $guardado = DB::table('lft_settings')
            ->where('tenant_id', $tenantId)
            ->value('overtime_weekly_cap_minutes');

        $minutos = $guardado === null ? self::TECHO_LFT_MINUTOS_SEMANA : (int) $guardado;

        return max(0, min($minutos, self::TECHO_LFT_MINUTOS_SEMANA));
    }

    /**
     * El acumulado de UNA persona en la semana que contiene `$enFecha` (hoy si no se dice otra).
     *
     * Devuelve `null` cuando no hay nada que decir: sin expediente activo, sin turno programado, o
     * con cero minutos extraordinarios. Un aviso que aparece con 0 no es un aviso.
     *
     * @return array{minutos:int, tope:int, rebasado:bool, dias:int, desde:string, hasta:string}|null
     */
    public static function deLaSemana(int $tenantId, $userId, ?Carbon $enFecha = null): ?array
    {
        if (!$userId) {
            return null;
        }

        // `soloUsuario`: /sync/state es el endpoint más consultado del producto (el dial lo pide en
        // bucle). Sin ese filtro, cada colaborador pagaría el cálculo de TODA la plantilla para
        // leer su propio renglón. Es el mismo código, con menos filas.
        $fila = self::delTenant($tenantId, $enFecha, (int) $userId)[(int) $userId] ?? null;
        if (!$fila || $fila['minutos'] <= 0) {
            return null;
        }

        unset($fila['user_id'], $fila['nombre']);

        return $fila;
    }

    /**
     * El acumulado de TODA la plantilla en la semana que contiene `$enFecha`, indexado por
     * `users.id`. Una sola consulta de fichajes para toda la empresa: el Monitor pregunta esto cada
     * pocos segundos y no puede permitirse una consulta por persona.
     *
     * @param  int|null  $soloUsuario  Limita el cálculo a una persona (el dial). Mismo camino.
     * @return array<int, array{user_id:int, nombre:string, minutos:int, tope:int, rebasado:bool, dias:int, desde:string, hasta:string}>
     */
    public static function delTenant(int $tenantId, ?Carbon $enFecha = null, ?int $soloUsuario = null): array
    {
        $zona = TenantTimezone::for($tenantId);
        $fecha = $enFecha ? $enFecha->copy()->setTimezone($zona) : Carbon::now($zona);

        [$inicio, $fin] = app(PayrollWeekService::class)->weekRangeFor($tenantId, $fecha);
        $desde = $inicio->toDateString();
        $hasta = $fin->toDateString();

        $tope = self::tope($tenantId);

        // Sólo quien tiene turno programado: sin jornada ordinaria no hay exceso que medir.
        $expedientes = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active_employee', '!=', false)
            ->whereNotNull('user_id')
            ->whereNotNull('shiftStart')
            ->whereNotNull('shiftEnd')
            ->when($soloUsuario !== null, fn ($q) => $q->where('user_id', $soloUsuario))
            ->get(['user_id', 'name', 'shiftStart', 'shiftEnd', 'mealMinutes'])
            ->keyBy('user_id');

        if ($expedientes->isEmpty()) {
            return [];
        }

        $fichajes = FichajesVigentes::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $expedientes->keys()->all())
            ->whereBetween('date', [$desde, $hasta])
            ->whereNull('simulation_session_id')
            ->whereIn('type', JornadaTrabajada::TIPOS)
            ->get(['user_id', 'date', 'type', 'time', 'details']);

        $porDia = [];
        foreach ($fichajes as $f) {
            $porDia[(int) $f->user_id][(string) $f->date][] = $f;
        }

        $resultado = [];
        foreach ($porDia as $userId => $dias) {
            $emp = $expedientes[$userId] ?? null;
            if (!$emp) {
                continue;
            }

            $ordinaria = self::jornadaOrdinaria($emp->shiftStart, $emp->shiftEnd, $emp->mealMinutes);
            if ($ordinaria === null) {
                continue;
            }

            $minutos = 0;
            $diasConExtra = 0;
            foreach ($dias as $dia => $marcas) {
                $jornada = JornadaTrabajada::delDia($marcas, $dia, $emp->shiftStart, $emp->shiftEnd, $zona);
                $extra = max(0, $jornada['efectivos'] - $ordinaria);
                if ($extra > 0) {
                    $minutos += $extra;
                    $diasConExtra++;
                }
            }

            if ($minutos <= 0) {
                continue;
            }

            $resultado[$userId] = [
                'user_id' => (int) $userId,
                'nombre' => (string) $emp->name,
                'minutos' => $minutos,
                'tope' => $tope,
                'rebasado' => $minutos > $tope,
                'dias' => $diasConExtra,
                'desde' => $desde,
                'hasta' => $hasta,
            ];
        }

        return $resultado;
    }

    /**
     * Minutos de jornada ordinaria EFECTIVA de una persona: su turno menos su comida programada.
     *
     * La comida ausente se toma como 0, no como los "60 por default" que usa el Monitor para
     * pintar: dar por hecha una comida que nadie configuró AGRANDA el tiempo extraordinario
     * calculado. Ante la duda, el número se equivoca a favor del colaborador.
     */
    public static function jornadaOrdinaria(?string $turnoInicio, ?string $turnoFin, $comidaMinutos): ?int
    {
        $i = JornadaLaboral::aMinutos($turnoInicio);
        $f = JornadaLaboral::aMinutos($turnoFin);

        if ($i === null || $f === null) {
            return null;
        }

        // Turno que cruza medianoche (22:00–02:00): la duración es 24 h menos el hueco.
        $duracion = $f > $i ? $f - $i : (1440 - $i) + $f;

        if ($duracion <= 0) {
            return null;
        }

        return max(0, $duracion - max(0, (int) $comidaMinutos));
    }

    /** "570" → "9 h 30 min". Para que el aviso diga el número, no una frase genérica. */
    public static function enHoras(int $minutos): string
    {
        $h = intdiv(max(0, $minutos), 60);
        $m = max(0, $minutos) % 60;

        if ($h === 0) {
            return "{$m} min";
        }

        return $m === 0 ? "{$h} h" : "{$h} h {$m} min";
    }
}
