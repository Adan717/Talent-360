<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Las tablas fiscales y de seguridad social vigentes en 2026, y el cálculo de REFERENCIA que
 * alimenta el reporte "Pre-nómina para tu contador" (Plan B, 2026-09-08).
 *
 * QUÉ ES Y QUÉ NO ES. La decisión del dueño (2026-09-06) es que la nómina **orienta, no
 * timbra**: el sistema no emite CFDI ni sustituye al contador de la empresa cliente. Lo que
 * sale de aquí son CIFRAS DE REFERENCIA para que ese contador arranque de algo, no una
 * retención definitiva. Por eso nada de esto toca el neto que ya se paga ni el recibo que el
 * colaborador firma: se calcula al vuelo para un reporte y ahí se queda.
 *
 * POR QUÉ TODO EN UN SOLO ARCHIVO. Estas cifras CADUCAN: la UMA cambia cada 1 de febrero, la
 * tarifa del art. 96 y el subsidio se publican en diciembre, el salario mínimo cada enero. Un
 * número fiscal repartido en tres archivos es un número que el año que entra se actualiza en
 * dos. Aquí viven todas, con su fuente y su fecha, y `VIGENTE_DESDE` deja que el reporte avise
 * cuando le piden un periodo anterior a lo que estas tablas saben.
 *
 * FUENTES (verificadas el 2026-09-08):
 *  - UMA 2026: INEGI, DOF 09-ene-2026. Vigente del 01-feb-2026 al 31-ene-2027.
 *  - Tarifa mensual del ISR (art. 96 LISR): Anexo 8 de la RMF 2026, DOF 28-dic-2025.
 *  - Subsidio al empleo: decreto DOF 31-dic-2025 (15.02 % de la UMA mensual desde el
 *    01-feb-2026; en enero de 2026 fue 15.59 % sobre la UMA de 2025, que este archivo NO
 *    modela — por eso el reporte avisa si el periodo cae antes del 01-feb-2026).
 *  - Cuotas obreras del IMSS: arts. 25, 36, 106, 107, 147 y 168 LSS (sin cambio en 2026).
 *  - Salario mínimo 2026: CONASAMI, DOF 09-dic-2025, vigente desde el 01-ene-2026.
 *
 * AL ACTUALIZAR EL AÑO: se cambian las constantes de este archivo y `VIGENTE_DESDE`, y se
 * corre `ReferenciaFiscalTest` — sus casos conocidos están calculados a mano contra la tabla,
 * así que si alguien teclea mal un renglón la prueba lo caza antes de que salga en un reporte.
 */
class ReferenciaFiscal
{
    /** El ejercicio de estas tablas, tal como lo imprime el reporte al pie. */
    public const VIGENCIA = '2026';

    /**
     * Desde cuándo son válidas. La UMA entra el 1 de febrero y el subsidio de enero se calculó
     * con la UMA anterior: un recibo de enero pasado por estas tablas saldría mal, así que el
     * reporte lo DECLARA en vez de disimularlo.
     */
    public const VIGENTE_DESDE = '2026-02-01';

    /** UMA diaria y mensual 2026 (INEGI, DOF 09-ene-2026). La mensual es la publicada. */
    public const UMA_DIARIA = 117.31;
    public const UMA_MENSUAL = 3566.22;

    /** Base mensual del art. 175 RLISR para llevar la tarifa mensual a un periodo de N días. */
    public const DIAS_DEL_MES_FISCAL = 30.4;

    /**
     * Salario mínimo general 2026 (CONASAMI). El de la Zona Libre de la Frontera Norte es
     * $440.87 y este sistema NO sabe en qué zona está la empresa: se usa el general, que es el
     * criterio conservador (marca a menos gente como de salario mínimo).
     */
    public const SALARIO_MINIMO_GENERAL = 315.04;
    public const SALARIO_MINIMO_FRONTERA = 440.87;

    /**
     * Tarifa MENSUAL del art. 96 LISR para 2026 (Anexo 8 RMF 2026).
     * Cada renglón: [límite inferior, cuota fija, % sobre el excedente del límite inferior].
     * El límite superior de un renglón es el inferior del siguiente menos un centavo, así que
     * no se guarda: tenerlo dos veces es la forma clásica de que un año se actualice uno y el
     * otro no.
     */
    public const TARIFA_MENSUAL_ISR = [
        [0.01, 0.00, 1.92],
        [844.60, 16.22, 6.40],
        [7168.52, 420.95, 10.88],
        [12598.03, 1011.68, 16.00],
        [14644.65, 1339.14, 17.92],
        [17533.65, 1856.84, 21.36],
        [35362.84, 5665.16, 23.52],
        [55736.69, 10457.09, 30.00],
        [106410.51, 25659.23, 32.00],
        [141880.67, 37009.69, 34.00],
        [425642.00, 133488.54, 35.00],
    ];

    /**
     * Subsidio al empleo: 15.02 % de la UMA mensual, y sólo si el ingreso mensual gravado no
     * rebasa el tope. Si el subsidio excede al ISR causado, el excedente NO se le entrega al
     * trabajador (decreto vigente): se acredita contra el impuesto y ahí para.
     */
    public const SUBSIDIO_FACTOR_UMA = 0.1502;
    public const SUBSIDIO_TOPE_INGRESO_MENSUAL = 11492.66;

    /**
     * Cuota OBRERA del IMSS, en % del SBC. La patronal no se calcula aquí: este reporte es lo
     * que se le retiene al TRABAJADOR, no el costo patronal.
     */
    public const IMSS_OBRERO = [
        'Excedente de 3 UMA (enfermedad y maternidad)' => 0.40,
        'Prestaciones en dinero' => 0.25,
        'Gastos médicos de pensionados' => 0.375,
        'Invalidez y vida' => 0.625,
        'Cesantía en edad avanzada y vejez' => 1.125,
    ];

    /** Tope del SBC (art. 28 LSS) y el umbral del excedente de enfermedad y maternidad. */
    public const TOPE_SBC_EN_UMA = 25;
    public const UMBRAL_EXCEDENTE_EN_UMA = 3;

    /** Tope de la exención de la prima por día de descanso trabajado, en UMA por semana. */
    public const TOPE_EXENCION_EN_UMA_POR_SEMANA = 5;

    /** Aguinaldo mínimo de ley (art. 87 LFT) y prima vacacional (art. 80), para el factor. */
    public const DIAS_DE_AGUINALDO = 15;
    public const PRIMA_VACACIONAL = 0.25;

    /**
     * Vacaciones por año cumplido (art. 76 LFT, reforma "vacaciones dignas" 2023): 12 el
     * primero y +2 por año hasta el quinto; de ahí, +2 por cada quinquenio.
     */
    public static function diasDeVacaciones(int $aniosCumplidos): int
    {
        $anio = max(1, $aniosCumplidos);

        if ($anio <= 5) {
            return 10 + ($anio * 2);
        }

        // 6-10 → 22, 11-15 → 24, 16-20 → 26, …
        return 22 + ((int) floor(($anio - 6) / 5)) * 2;
    }

    /**
     * Factor de integración: (365 + aguinaldo + vacaciones × prima) / 365. Es lo que convierte
     * el sueldo diario en salario base de cotización.
     */
    public static function factorDeIntegracion(int $aniosCumplidos): float
    {
        $vacaciones = self::diasDeVacaciones($aniosCumplidos);

        return round((365 + self::DIAS_DE_AGUINALDO + ($vacaciones * self::PRIMA_VACACIONAL)) / 365, 4);
    }

    /** Años cumplidos al día de corte. Sin fecha de ingreso no se inventa: devuelve null. */
    public static function antiguedadEnAnios(?string $fechaDeIngreso, string $alDia): ?int
    {
        if (!$fechaDeIngreso) {
            return null;
        }

        $ingreso = Carbon::parse($fechaDeIngreso)->startOfDay();
        $corte = Carbon::parse($alDia)->startOfDay();

        if ($ingreso->greaterThan($corte)) {
            return 0;
        }

        return (int) $ingreso->diffInYears($corte);
    }

    /**
     * Salario base de cotización = sueldo diario × factor de integración, topado a 25 UMA.
     *
     * Es la parte FIJA. Los premios de puntualidad y asistencia no integran mientras cada uno
     * no rebase el 10 % del SBC (art. 27 fr. VII LSS); el reporte lo declara, pero no los
     * integra solo: hacerlo mal mueve dinero real.
     *
     * @return array{sbc:float, factor:float, anios:?int, topado:bool}
     */
    public static function salarioBaseDeCotizacion(float $salarioDiario, ?int $aniosCumplidos): array
    {
        $factor = self::factorDeIntegracion($aniosCumplidos ?? 1);
        $sbc = round($salarioDiario * $factor, 2);
        $tope = round(self::TOPE_SBC_EN_UMA * self::UMA_DIARIA, 2);
        $topado = $sbc > $tope;

        return [
            'sbc' => $topado ? $tope : $sbc,
            'factor' => $factor,
            'anios' => $aniosCumplidos,
            'topado' => $topado,
        ];
    }

    /**
     * ¿Percibe el salario mínimo general? De ahí cuelgan dos reglas (art. 93 fr. I LISR y
     * art. 36 LSS) que cambian el resultado para la mayor parte de la plantilla de una tienda.
     */
    public static function esSalarioMinimo(float $salarioDiario): bool
    {
        return $salarioDiario > 0 && $salarioDiario <= self::SALARIO_MINIMO_GENERAL;
    }

    /**
     * Separa lo gravado de lo exento de un recibo ya guardado.
     *
     * El motor paga por día y el bruto YA trae dentro la prima de día festivo trabajado
     * (`ClockService`: `bruto = diario × días + prima`), así que aquí se vuelve a separar para
     * poder tratarla distinto: de esa prima, el art. 93 fr. I LISR exenta el 50 % con tope de
     * 5 UMA por semana — y el 100 %, dentro de los límites de la LFT, a quien percibe el
     * salario mínimo. Todo lo demás (sueldo, bono de puntualidad, bono de apertura) es gravado.
     *
     * Los descuentos internos (faltas, retardos, séptimo proporcional) reducen el sueldo
     * PAGADO, que es sobre lo que se causa el impuesto: no son deducciones fiscales.
     *
     * El reparto sigue al peso lo que el recibo pagó de verdad —`neto = max(0, bruto −
     * descuentos) + bonos`—, así que las partes SIEMPRE suman ese neto. Si los descuentos se
     * comieran el sueldo, restarlos de un lado y dejar la prima entera del otro haría aparecer
     * dinero que nadie pagó: en un reporte que va al contador, eso es el defecto, no el caso raro.
     *
     * @return array{sueldo:float, prima_festivo:float, bonos:float, total:float, gravado:float, exento:float}
     */
    public static function clasificaPercepciones(
        float $bruto,
        float $primaFestivo,
        float $bonos,
        float $deduccionesInternas,
        int $dias,
        bool $esSalarioMinimo
    ): array {
        // Lo que quedó del bruto después de los descuentos, repartido: primero la prima (los
        // descuentos son por días de sueldo ordinario), y el resto es sueldo.
        $pagado = max(0.0, round($bruto - $deduccionesInternas, 2));
        $primaPagada = min(round($primaFestivo, 2), $pagado);
        $sueldoPagado = round($pagado - $primaPagada, 2);

        $topeSemanal = self::UMA_DIARIA * self::TOPE_EXENCION_EN_UMA_POR_SEMANA * (max(1, $dias) / 7);
        $exento = $esSalarioMinimo
            ? $primaPagada
            : min($primaPagada * 0.5, $topeSemanal);
        $exento = round(min($exento, $primaPagada), 2);

        return [
            'sueldo' => $sueldoPagado,
            'prima_festivo' => $primaPagada,
            'bonos' => round($bonos, 2),
            'total' => round($sueldoPagado + $primaPagada + $bonos, 2),
            'gravado' => round($sueldoPagado + ($primaPagada - $exento) + $bonos, 2),
            'exento' => $exento,
        ];
    }

    /**
     * ISR del periodo con la tarifa mensual llevada a N días (art. 175 RLISR: la tarifa
     * mensual entre 30.4, por los días del periodo), y el subsidio al empleo que corresponda.
     *
     * @return array{causado:float, subsidio:float, retencion:float, mensualizado:float}
     */
    public static function isrDelPeriodo(float $gravado, int $dias): array
    {
        $dias = max(1, $dias);
        $proporcion = $dias / self::DIAS_DEL_MES_FISCAL;
        $causado = 0.0;

        foreach (array_reverse(self::TARIFA_MENSUAL_ISR) as [$limiteInferior, $cuotaFija, $porciento]) {
            $limite = round($limiteInferior * $proporcion, 2);
            if ($gravado >= $limite) {
                $causado = round(($cuotaFija * $proporcion) + (($gravado - $limite) * $porciento / 100), 2);
                break;
            }
        }

        $mensualizado = round($gravado / $proporcion, 2);
        $subsidio = $mensualizado <= self::SUBSIDIO_TOPE_INGRESO_MENSUAL
            ? round(self::UMA_MENSUAL * self::SUBSIDIO_FACTOR_UMA * $proporcion, 2)
            : 0.0;

        return [
            'causado' => $causado,
            'subsidio' => $subsidio,
            // Si el subsidio excede al ISR, el excedente NO se le entrega al trabajador.
            'retencion' => round(max(0.0, $causado - $subsidio), 2),
            'mensualizado' => $mensualizado,
        ];
    }

    /**
     * Cuota OBRERA del IMSS del periodo, sobre el SBC.
     *
     * Quien percibe el salario mínimo no aporta: el patrón cubre íntegramente su cuota
     * (art. 36 LSS). En la plantilla de una tienda eso es la mayoría, así que ignorarlo no
     * sería un detalle: sería restarle a casi todos un dinero que no se les retiene.
     *
     * @return array{total:float, desglose:array<string,float>, exento_por_minimo:bool}
     */
    public static function cuotaObreraImss(float $sbc, int $dias, bool $esSalarioMinimo): array
    {
        if ($esSalarioMinimo) {
            return ['total' => 0.0, 'desglose' => [], 'exento_por_minimo' => true];
        }

        $dias = max(1, $dias);
        $excedente = max(0.0, $sbc - (self::UMBRAL_EXCEDENTE_EN_UMA * self::UMA_DIARIA));
        $desglose = [];

        foreach (self::IMSS_OBRERO as $concepto => $porciento) {
            $base = str_starts_with($concepto, 'Excedente') ? $excedente : $sbc;
            $desglose[$concepto] = round($base * $dias * $porciento / 100, 2);
        }

        return [
            'total' => round(array_sum($desglose), 2),
            'desglose' => $desglose,
            'exento_por_minimo' => false,
        ];
    }
}
