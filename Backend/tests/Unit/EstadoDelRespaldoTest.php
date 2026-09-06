<?php

namespace Tests\Unit;

use App\Support\EstadoDelRespaldo;
use Tests\TestCase;

/**
 * El borde de las 26 h con la hora INYECTADA. Sin inyectar, esta prueba sólo se podría escribir
 * con fechas relativas al reloj de quien la corre, y el caso interesante —el minuto exacto en
 * que el respaldo pasa de aceptable a viejo— no se podría tocar nunca.
 */
class EstadoDelRespaldoTest extends TestCase
{
    private const TERMINADO = '2026-09-05T02:45:00Z';

    protected function tearDown(): void
    {
        $this->borrarMarca();
        parent::tearDown();
    }

    private function ruta(): string
    {
        return EstadoDelRespaldo::ruta();
    }

    private function escribirMarca(string $contenido): void
    {
        $dir = dirname($this->ruta());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->ruta(), $contenido);
    }

    private function marcaValida(string $terminado = self::TERMINADO): void
    {
        $this->escribirMarca(json_encode([
            'instancia' => 'v2',
            'terminado_utc' => $terminado,
            'dump_bytes' => 1234567,
        ]));
    }

    private function borrarMarca(): void
    {
        if (is_file($this->ruta())) {
            unlink($this->ruta());
        }
        $dir = dirname($this->ruta());
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    private function alas(string $instante): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instante, new \DateTimeZone('UTC'));
    }

    // ---------- el borde exacto ----------

    public function test_a_las_26_horas_clavadas_el_respaldo_todavia_vale(): void
    {
        $this->marcaValida();

        // 02:45 + 26 h = 04:45 del día siguiente.
        $r = EstadoDelRespaldo::revisar($this->alas('2026-09-06T04:45:00Z'));

        $this->assertTrue($r['ok']);
        $this->assertNull($r['motivo']);
        $this->assertSame(26.0, $r['horas']);
        $this->assertSame(self::TERMINADO, $r['ultimo_utc']);
    }

    public function test_un_minuto_despues_de_las_26_horas_ya_es_viejo(): void
    {
        $this->marcaValida();

        $r = EstadoDelRespaldo::revisar($this->alas('2026-09-06T04:46:00Z'));

        $this->assertFalse($r['ok']);
        $this->assertSame('viejo', $r['motivo']);
        $this->assertSame(26.02, $r['horas']);
    }

    public function test_el_umbral_declarado_es_el_que_se_usa(): void
    {
        // Si alguien cambia la constante, esta prueba lo sigue: lo que se fija es que el
        // límite REAL sea el declarado, no el número 26.
        $this->marcaValida();
        $limite = $this->alas(self::TERMINADO)
            ->modify('+' . (EstadoDelRespaldo::HORAS_MAXIMAS * 60) . ' minutes');

        $this->assertTrue(EstadoDelRespaldo::revisar($limite)['ok']);
        $this->assertFalse(EstadoDelRespaldo::revisar($limite->modify('+1 second'))['ok']);
    }

    public function test_un_respaldo_recien_hecho_esta_bien(): void
    {
        $this->marcaValida();

        $r = EstadoDelRespaldo::revisar($this->alas('2026-09-05T03:00:00Z'));

        $this->assertTrue($r['ok']);
        $this->assertSame(0.25, $r['horas']);
    }

    public function test_una_marca_del_futuro_no_se_castiga(): void
    {
        // Reloj del host adelantado. Lo que se vigila es que no FALTE un respaldo.
        $this->marcaValida();

        $r = EstadoDelRespaldo::revisar($this->alas('2026-09-05T01:45:00Z'));

        $this->assertTrue($r['ok']);
        $this->assertSame(-1.0, $r['horas']);
    }

    // ---------- lo que no se puede confirmar cuenta como fallo ----------

    public function test_sin_marca_el_respaldo_no_existe(): void
    {
        $this->borrarMarca();

        $r = EstadoDelRespaldo::revisar($this->alas('2026-09-05T03:00:00Z'));

        $this->assertFalse($r['ok']);
        $this->assertSame('sin_marca', $r['motivo']);
        $this->assertNull($r['horas']);
        $this->assertNull($r['ultimo_utc']);
    }

    public function test_una_marca_corrupta_es_ilegible(): void
    {
        $this->escribirMarca('{"instancia":"v2","terminado');

        $r = EstadoDelRespaldo::revisar($this->alas('2026-09-05T03:00:00Z'));

        $this->assertFalse($r['ok']);
        $this->assertSame('ilegible', $r['motivo']);
    }

    public function test_un_json_valido_sin_la_fecha_es_ilegible(): void
    {
        $this->escribirMarca(json_encode(['instancia' => 'v2', 'dump_bytes' => 10]));

        $this->assertSame('ilegible', EstadoDelRespaldo::revisar($this->alas('2026-09-05T03:00:00Z'))['motivo']);
    }

    /**
     * CANDADO. `new DateTimeImmutable('')` devuelve AHORA: una marca truncada a cero se leería
     * como un respaldo recién hecho y el vigilante se quedaría en verde para siempre. Es la
     * única forma en que esta clase podría mentir tranquilizando, así que se fija por escrito.
     */
    public function test_una_fecha_vacia_o_de_palabras_nunca_pasa_por_respaldo_fresco(): void
    {
        foreach (['', ' ', 'now', 'today', 'ayer', '0', 'null'] as $basura) {
            $this->escribirMarca(json_encode(['terminado_utc' => $basura]));

            $r = EstadoDelRespaldo::revisar($this->alas('2026-09-05T03:00:00Z'));

            $this->assertFalse($r['ok'], "'{$basura}' se coló como respaldo válido");
            $this->assertSame('ilegible', $r['motivo'], "'{$basura}' debería ser ilegible");
        }
    }

    public function test_una_fecha_con_forma_pero_imposible_es_ilegible(): void
    {
        $this->escribirMarca(json_encode(['terminado_utc' => '2026-99-99T99:99:99Z']));

        $this->assertSame('ilegible', EstadoDelRespaldo::revisar($this->alas('2026-09-05T03:00:00Z'))['motivo']);
    }

    // ---------- forma del contrato ----------

    public function test_acepta_la_marca_con_desfase_horario_y_la_normaliza_a_utc(): void
    {
        // Si alguien la escribe a mano con offset en vez de Z, la cuenta debe seguir siendo
        // en UTC y no depender de la zona del proceso de PHP.
        $this->marcaValida('2026-09-04T21:45:00-05:00'); // = 2026-09-05T02:45:00Z

        $r = EstadoDelRespaldo::revisar($this->alas('2026-09-05T03:45:00Z'));

        $this->assertTrue($r['ok']);
        $this->assertSame(1.0, $r['horas']);
        $this->assertSame(self::TERMINADO, $r['ultimo_utc']);
    }

    public function test_sin_hora_inyectada_usa_el_reloj_real(): void
    {
        // La pregunta de control: ¿pasaría a las 3 de la mañana? Sí — no hay ninguna fecha
        // literal aquí, la marca se escribe relativa a AHORA.
        $this->marcaValida(gmdate('Y-m-d\TH:i:s\Z', time() - 3600));

        $r = EstadoDelRespaldo::revisar();

        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(1.0, $r['horas'], 0.05);
    }
}
