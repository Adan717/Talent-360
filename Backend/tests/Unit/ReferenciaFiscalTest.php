<?php

namespace Tests\Unit;

use App\Support\ReferenciaFiscal;
use PHPUnit\Framework\TestCase;

/**
 * EL CANDADO DE LAS TABLAS FISCALES (Plan B, 2026-09-08).
 *
 * Estas cifras caducan y se re-teclean cada año. Un dígito mal en un renglón de la tarifa del
 * art. 96 no revienta nada: sale un ISR plausible pero equivocado en un reporte que un
 * contador va a usar. Por eso los casos de abajo están calculados A MANO contra la tabla
 * publicada, no derivados del propio código: si alguien altera un renglón, aquí truena.
 *
 * Al actualizar el año hay que RECALCULAR estos números, no ajustarlos hasta que pasen.
 */
class ReferenciaFiscalTest extends TestCase
{
    /** La UMA mensual publicada es la diaria por 30.4: si alguien actualiza una y olvida la otra, se ve aquí. */
    public function test_la_uma_mensual_es_congruente_con_la_diaria(): void
    {
        $this->assertEqualsWithDelta(
            ReferenciaFiscal::UMA_MENSUAL,
            ReferenciaFiscal::UMA_DIARIA * ReferenciaFiscal::DIAS_DEL_MES_FISCAL,
            0.01,
            'la UMA mensual y la diaria dejaron de corresponder: revisa el DOF'
        );
    }

    /** La tarifa va en orden: un renglón pegado en el lugar equivocado se caza aquí. */
    public function test_la_tarifa_del_isr_esta_completa_y_en_orden(): void
    {
        $tarifa = ReferenciaFiscal::TARIFA_MENSUAL_ISR;

        $this->assertCount(11, $tarifa, 'la tarifa del art. 96 tiene 11 renglones');
        $this->assertSame(0.01, $tarifa[0][0]);
        $this->assertSame(0.0, $tarifa[0][1], 'el primer renglón no tiene cuota fija');
        $this->assertSame(35.00, $tarifa[10][2], 'la tasa máxima es 35 %');

        for ($i = 1; $i < count($tarifa); $i++) {
            $this->assertGreaterThan($tarifa[$i - 1][0], $tarifa[$i][0], "el límite inferior del renglón {$i} no crece");
            $this->assertGreaterThan($tarifa[$i - 1][1], $tarifa[$i][1], "la cuota fija del renglón {$i} no crece");
            $this->assertGreaterThan($tarifa[$i - 1][2], $tarifa[$i][2], "el porcentaje del renglón {$i} no crece");
        }
    }

    /** Vacaciones dignas (art. 76 LFT): 12 el primer año, +2 hasta el quinto, luego por quinquenio. */
    public function test_las_vacaciones_siguen_la_reforma_de_2023(): void
    {
        $this->assertSame(12, ReferenciaFiscal::diasDeVacaciones(1));
        $this->assertSame(14, ReferenciaFiscal::diasDeVacaciones(2));
        $this->assertSame(20, ReferenciaFiscal::diasDeVacaciones(5));
        $this->assertSame(22, ReferenciaFiscal::diasDeVacaciones(6));
        $this->assertSame(22, ReferenciaFiscal::diasDeVacaciones(10));
        $this->assertSame(24, ReferenciaFiscal::diasDeVacaciones(11));
        $this->assertSame(26, ReferenciaFiscal::diasDeVacaciones(16));
        // Antes del primer año cumplido se usa el primer escalón, no cero.
        $this->assertSame(12, ReferenciaFiscal::diasDeVacaciones(0));
    }

    /** Factor de integración = (365 + 15 de aguinaldo + vacaciones × 25 %) / 365. */
    public function test_el_factor_de_integracion_del_primer_ano_es_el_de_ley(): void
    {
        // (365 + 15 + 12 × 0.25) / 365 = 383 / 365
        $this->assertSame(1.0493, ReferenciaFiscal::factorDeIntegracion(1));
        // (365 + 15 + 20 × 0.25) / 365 = 385 / 365
        $this->assertSame(1.0548, ReferenciaFiscal::factorDeIntegracion(5));
    }

    public function test_el_sbc_integra_y_se_topa_en_25_uma(): void
    {
        $sinTope = ReferenciaFiscal::salarioBaseDeCotizacion(315.04, 1);
        $this->assertSame(1.0493, $sinTope['factor']);
        $this->assertEqualsWithDelta(330.57, $sinTope['sbc'], 0.01, '315.04 × 1.0493');
        $this->assertFalse($sinTope['topado']);

        $conTope = ReferenciaFiscal::salarioBaseDeCotizacion(5000.00, 3);
        $this->assertTrue($conTope['topado']);
        $this->assertEqualsWithDelta(25 * ReferenciaFiscal::UMA_DIARIA, $conTope['sbc'], 0.01);

        // Sin fecha de ingreso NO se cae: se usa el factor del primer año (y el reporte lo dice).
        $this->assertSame(1.0493, ReferenciaFiscal::salarioBaseDeCotizacion(315.04, null)['factor']);
    }

    public function test_la_antiguedad_no_se_inventa_sin_fecha_de_ingreso(): void
    {
        $this->assertNull(ReferenciaFiscal::antiguedadEnAnios(null, '2026-09-08'));
        $this->assertSame(2, ReferenciaFiscal::antiguedadEnAnios('2024-09-08', '2026-09-08'));
        $this->assertSame(1, ReferenciaFiscal::antiguedadEnAnios('2024-09-09', '2026-09-08'));
        // Alta futura (captura equivocada): 0, no un número negativo.
        $this->assertSame(0, ReferenciaFiscal::antiguedadEnAnios('2027-01-01', '2026-09-08'));
    }

    public function test_el_salario_minimo_general_2026_marca_la_frontera(): void
    {
        $this->assertTrue(ReferenciaFiscal::esSalarioMinimo(315.04));
        $this->assertTrue(ReferenciaFiscal::esSalarioMinimo(300.00));
        $this->assertFalse(ReferenciaFiscal::esSalarioMinimo(315.05));
        $this->assertFalse(ReferenciaFiscal::esSalarioMinimo(0.0), 'sin sueldo capturado no se declara salario mínimo');
    }

    /**
     * La cuenta que primero revisa un contador: percepciones = gravado + exento, y el total
     * coincide con el neto que el recibo ya pagó.
     */
    public function test_las_percepciones_se_separan_y_la_suma_cuadra(): void
    {
        // Bruto 3,000 (con 400 de prima de festivo dentro), 200 de bonos, 300 de descuentos.
        $p = ReferenciaFiscal::clasificaPercepciones(3000.0, 400.0, 200.0, 300.0, 7, false);

        $this->assertSame(2300.0, $p['sueldo'], '(3000 − 400) − 300');
        $this->assertSame(200.0, $p['exento'], '50 % de la prima, muy por debajo del tope de 5 UMA por semana');
        $this->assertSame(2900.0, $p['total']);
        $this->assertSame(2700.0, $p['gravado']);
        $this->assertEqualsWithDelta($p['total'], $p['gravado'] + $p['exento'], 0.01);
    }

    /** Salario mínimo: la prima por trabajar el día de descanso va 100 % exenta (LISR art. 93 fr. I). */
    public function test_al_salario_minimo_la_prima_de_festivo_va_toda_exenta(): void
    {
        $p = ReferenciaFiscal::clasificaPercepciones(3000.0, 400.0, 200.0, 300.0, 7, true);

        $this->assertSame(400.0, $p['exento']);
        $this->assertSame(2500.0, $p['gravado']);
        $this->assertSame(2900.0, $p['total'], 'el total no cambia: cambia de qué lado cae la prima');
    }

    /**
     * Si los descuentos se comen el sueldo, el reparto NO puede seguir mostrando la prima
     * entera: sumaría dinero que el recibo no pagó. Las partes siguen sumando el neto real
     * (`max(0, bruto − descuentos) + bonos`).
     */
    public function test_aunque_los_descuentos_se_coman_el_sueldo_las_partes_suman_el_neto(): void
    {
        // Bruto 4,500 con 1,000 de prima dentro, 4,200 de descuentos, 200 de bonos.
        // El recibo pagó max(0, 4500 − 4200) + 200 = 500.
        $p = ReferenciaFiscal::clasificaPercepciones(4500.0, 1000.0, 200.0, 4200.0, 7, false);

        $this->assertSame(0.0, $p['sueldo']);
        $this->assertSame(300.0, $p['prima_festivo'], 'de la prima sólo sobrevive lo que de verdad quedó');
        $this->assertSame(500.0, $p['total']);
        $this->assertEqualsWithDelta($p['total'], $p['gravado'] + $p['exento'], 0.01);
    }

    /** La exención de la prima tiene tope: 5 UMA por semana (no el 50 % sin fin). */
    public function test_la_exencion_de_la_prima_se_topa_en_cinco_uma_por_semana(): void
    {
        $p = ReferenciaFiscal::clasificaPercepciones(10000.0, 8000.0, 0.0, 0.0, 7, false);

        $this->assertEqualsWithDelta(586.55, $p['exento'], 0.01, '5 × 117.31 en una semana, no 4,000');
    }

    /**
     * ISR de una semana con $2,205.28 gravado (7 días al salario mínimo de 2026).
     *
     * A mano, con la tarifa mensual llevada a 7 días (art. 175 RLISR), proporción 7/30.4:
     *   límite del renglón: 7,168.52 × 7 / 30.4 = 1,650.65
     *   cuota fija:         420.95 × 7 / 30.4   = 96.93
     *   ISR = 96.93 + (2,205.28 − 1,650.65) × 10.88 % = 96.93 + 60.34 = 157.27
     * Subsidio: mensualizado 9,577.22 ≤ 11,492.66 → 535.65 × 7 / 30.4 = 123.34
     */
    public function test_el_isr_semanal_de_un_salario_minimo_sale_de_la_tarifa_publicada(): void
    {
        $isr = ReferenciaFiscal::isrDelPeriodo(2205.28, 7);

        $this->assertEqualsWithDelta(157.27, $isr['causado'], 0.02);
        $this->assertEqualsWithDelta(123.34, $isr['subsidio'], 0.02);
        $this->assertEqualsWithDelta(33.93, $isr['retencion'], 0.03);
        $this->assertEqualsWithDelta(9577.22, $isr['mensualizado'], 0.02);
    }

    /** Arriba del tope de ingreso ya no hay subsidio, y el ISR se retiene completo. */
    public function test_arriba_del_tope_de_ingreso_no_hay_subsidio(): void
    {
        $isr = ReferenciaFiscal::isrDelPeriodo(15000.0, 15);

        $this->assertGreaterThan(ReferenciaFiscal::SUBSIDIO_TOPE_INGRESO_MENSUAL, $isr['mensualizado']);
        $this->assertSame(0.0, $isr['subsidio']);
        $this->assertSame($isr['causado'], $isr['retencion']);
    }

    /** El subsidio se acredita contra el ISR, pero el excedente NO se le entrega al trabajador. */
    public function test_el_subsidio_nunca_deja_una_retencion_negativa(): void
    {
        $isr = ReferenciaFiscal::isrDelPeriodo(500.0, 7);

        $this->assertGreaterThan($isr['causado'], $isr['subsidio']);
        $this->assertSame(0.0, $isr['retencion'], 'el excedente del subsidio no se paga en efectivo');
    }

    /**
     * Cuota obrera de una semana con SBC de $500:
     *   excedente sobre 3 UMA: 500 − 351.93 = 148.07 → 0.40 % × 7 =  4.15
     *   prestaciones en dinero:        0.25 % × 500 × 7 =  8.75
     *   gastos médicos de pensionados: 0.375 % × 500 × 7 = 13.13
     *   invalidez y vida:              0.625 % × 500 × 7 = 21.88
     *   cesantía y vejez:              1.125 % × 500 × 7 = 39.38
     */
    public function test_la_cuota_obrera_del_imss_sale_de_los_cinco_ramos(): void
    {
        $imss = ReferenciaFiscal::cuotaObreraImss(500.0, 7, false);

        $this->assertCount(5, $imss['desglose']);
        $this->assertEqualsWithDelta(4.15, $imss['desglose']['Excedente de 3 UMA (enfermedad y maternidad)'], 0.02);
        $this->assertEqualsWithDelta(87.29, $imss['total'], 0.03);
        $this->assertFalse($imss['exento_por_minimo']);
    }

    /** Debajo de 3 UMA no hay cuota adicional: la base del excedente es cero, no negativa. */
    public function test_debajo_de_tres_uma_no_hay_cuota_de_excedente(): void
    {
        $imss = ReferenciaFiscal::cuotaObreraImss(300.0, 7, false);

        $this->assertSame(0.0, $imss['desglose']['Excedente de 3 UMA (enfermedad y maternidad)']);
    }

    /** Al salario mínimo el patrón cubre la cuota obrera completa (LSS art. 36). */
    public function test_al_salario_minimo_el_trabajador_no_aporta_al_imss(): void
    {
        $imss = ReferenciaFiscal::cuotaObreraImss(330.57, 7, true);

        $this->assertSame(0.0, $imss['total']);
        $this->assertTrue($imss['exento_por_minimo']);
    }
}
