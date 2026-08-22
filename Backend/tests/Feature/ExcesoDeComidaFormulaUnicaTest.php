<?php

namespace Tests\Feature;

use App\Support\ExcesoDeDescanso;
use Tests\TestCase;

/**
 * Una sola fórmula del exceso de comida (2026-08-22).
 *
 * Antes había dos: el motor de nómina emparejaba cada comida y aplicaba la tolerancia como
 * umbral; el reporte de Comedor sumaba los segmentos del día e ignoraba la tolerancia. Las dos
 * pantallas daban cifras distintas del mismo día. Estas pruebas fijan la fórmula buena — la del
 * motor, que es la que se usa para juzgar a una persona.
 */
class ExcesoDeComidaFormulaUnicaTest extends TestCase
{
    private function marca(string $type, string $time): object
    {
        return (object) ['type' => $type, 'time' => $time];
    }

    private function exceso(array $marcas, int $permitido = 60, int $tolerancia = 15, ?string $ini = null, ?string $fin = null): int
    {
        return ExcesoDeDescanso::calcular($marcas, '2026-08-22', 'meal_start', 'meal_end', $permitido, $tolerancia, $ini, $fin)['exceso'];
    }

    /** 70 minutos con permitido 60 y tolerancia 15: NO excede (el reporte decía 10). */
    public function test_la_tolerancia_es_umbral_no_franquicia(): void
    {
        $this->assertSame(0, $this->exceso([
            $this->marca('meal_start', '14:00:00'),
            $this->marca('meal_end', '15:10:00'),
        ]));
    }

    /** 80 minutos: pasa el umbral, y se cobra duración − permitido = 20 (no 5). */
    public function test_al_exceder_se_cobra_desde_el_permitido(): void
    {
        $this->assertSame(20, $this->exceso([
            $this->marca('meal_start', '14:00:00'),
            $this->marca('meal_end', '15:20:00'),
        ]));
    }

    /** Dos pausas de 40 no se suman para inventar un exceso (el reporte daba 20). */
    public function test_cada_pausa_se_evalua_por_separado(): void
    {
        $this->assertSame(0, $this->exceso([
            $this->marca('meal_start', '12:00:00'),
            $this->marca('meal_end', '12:40:00'),
            $this->marca('meal_start', '17:00:00'),
            $this->marca('meal_end', '17:40:00'),
        ]));
    }

    /** Turno nocturno: la comida que cruza medianoche se mide, no se pierde ni se infla. */
    public function test_la_comida_nocturna_se_mide_bien(): void
    {
        $this->assertSame(60, $this->exceso([
            $this->marca('meal_start', '23:00:00'),
            $this->marca('meal_end', '01:00:00'),
        ], 60, 15, '22:00', '02:00'));
    }

    /** Se fue a comer y no volvió: no hay dato, no se inventan minutos. */
    public function test_una_comida_sin_regreso_no_produce_exceso(): void
    {
        $r = ExcesoDeDescanso::calcular(
            [$this->marca('meal_start', '14:00:00')],
            '2026-08-22', 'meal_start', 'meal_end', 60, 15
        );

        $this->assertSame(0, $r['exceso']);
        $this->assertTrue($r['abierta'], 'debe avisar que quedó abierta para que un humano la vea');
    }
}
