<?php

namespace Tests\Unit;

use App\Support\JornadaLaboral;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * H21 — fronteras del corte de jornada nocturna. Es la pieza que decide a qué día se cobra un
 * fichaje, así que sus bordes se prueban uno a uno antes de cablearla en `processPunch`.
 */
class JornadaLaboralTest extends TestCase
{
    private function enZona(string $fechaHora): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $fechaHora, 'America/Mexico_City');
    }

    // ---------- detección del turno nocturno ----------

    public function test_reconoce_un_turno_que_cruza_medianoche(): void
    {
        $this->assertTrue(JornadaLaboral::cruzaMedianoche('22:00:00', '02:00:00'));
        $this->assertTrue(JornadaLaboral::cruzaMedianoche('23:00', '07:00'));
    }

    public function test_un_turno_diurno_no_cruza(): void
    {
        $this->assertFalse(JornadaLaboral::cruzaMedianoche('09:00:00', '18:00:00'));
        $this->assertFalse(JornadaLaboral::cruzaMedianoche('11:20:00', '19:23:00'));
    }

    public function test_sin_horario_no_se_asume_nada(): void
    {
        $this->assertFalse(JornadaLaboral::cruzaMedianoche(null, '02:00:00'));
        $this->assertFalse(JornadaLaboral::cruzaMedianoche('22:00:00', null));
        $this->assertFalse(JornadaLaboral::cruzaMedianoche('basura', '02:00'));
        $this->assertFalse(JornadaLaboral::cruzaMedianoche('25:00', '02:00'));
    }

    public function test_un_turno_de_24h_exactas_no_cuenta_como_cruzado(): void
    {
        // No hay hueco que partir; inventar un corte sólo añadiría un comportamiento arbitrario.
        $this->assertFalse(JornadaLaboral::cruzaMedianoche('08:00', '08:00'));
    }

    // ---------- punto de corte ----------

    public function test_el_corte_cae_a_mitad_del_hueco(): void
    {
        // 22:00–02:00: el hueco va de 02:00 a 22:00, su mitad son las 12:00.
        $this->assertSame(720, JornadaLaboral::minutoDeCorte('22:00', '02:00'));

        // 23:00–07:00: hueco 07:00→23:00, mitad a las 15:00.
        $this->assertSame(900, JornadaLaboral::minutoDeCorte('23:00', '07:00'));
    }

    public function test_un_turno_diurno_no_tiene_corte(): void
    {
        $this->assertNull(JornadaLaboral::minutoDeCorte('09:00', '18:00'));
    }

    // ---------- la fecha de negocio ----------

    public function test_el_turno_diurno_conserva_su_fecha_calendario(): void
    {
        // El caso de la inmensa mayoría: nada debe cambiar.
        $this->assertSame('2026-07-30',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 09:00:00'), '09:00', '18:00'));
        $this->assertSame('2026-07-30',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 17:59:00'), '09:00', '18:00'));
        // Incluso de madrugada: sin turno nocturno, la fecha es la suya.
        $this->assertSame('2026-07-30',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 01:00:00'), '09:00', '18:00'));
    }

    public function test_sin_horario_conserva_su_fecha(): void
    {
        $this->assertSame('2026-07-30',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 02:00:00'), null, null));
    }

    public function test_la_entrada_nocturna_abre_la_jornada_del_dia(): void
    {
        $this->assertSame('2026-07-29',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-29 22:00:00'), '22:00', '02:00'));
    }

    public function test_la_salida_de_madrugada_pertenece_al_dia_ANTERIOR(): void
    {
        // EL CASO DEL BUG: antes esto caía en 2026-07-30 y la noche se cobraba dos veces.
        $this->assertSame('2026-07-29',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 02:00:00'), '22:00', '02:00'));
    }

    public function test_salir_tarde_no_cambia_la_jornada(): void
    {
        // Se quedó una hora de más: sigue siendo la noche de ayer.
        $this->assertSame('2026-07-29',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 03:30:00'), '22:00', '02:00'));
    }

    public function test_llegar_temprano_ya_cuenta_como_la_jornada_de_hoy(): void
    {
        $this->assertSame('2026-07-30',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 21:00:00'), '22:00', '02:00'));
    }

    // ---------- fronteras exactas ----------

    public function test_los_bordes_del_corte(): void
    {
        // Corte a las 12:00 para un turno 22:00–02:00.
        $this->assertSame('2026-07-29',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 11:59:00'), '22:00', '02:00'));
        $this->assertSame('2026-07-30',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 12:00:00'), '22:00', '02:00'),
            'El minuto del corte ya pertenece al día nuevo.');
    }

    public function test_medianoche_exacta(): void
    {
        $this->assertSame('2026-07-29',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 00:00:00'), '22:00', '02:00'));
        $this->assertSame('2026-07-29',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-29 23:59:00'), '22:00', '02:00'));
        $this->assertSame('2026-07-29',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-07-30 00:01:00'), '22:00', '02:00'));
    }

    public function test_cambio_de_mes_y_de_ano(): void
    {
        // `subDay` debe retroceder bien el calendario, no restar 24h a ciegas sobre el string.
        $this->assertSame('2026-07-31',
            JornadaLaboral::fechaDeNegocio($this->enZona('2026-08-01 01:00:00'), '22:00', '02:00'));
        $this->assertSame('2026-12-31',
            JornadaLaboral::fechaDeNegocio($this->enZona('2027-01-01 01:00:00'), '22:00', '02:00'));
    }

    // ---------- reconstruir el instante real de un fichaje guardado ----------

    public function test_reconstruye_el_instante_de_un_fichaje_de_madrugada(): void
    {
        // Guardado como jornada del 29 a las 00:30 → ocurrió el 30 a las 00:30.
        $i = JornadaLaboral::instanteDe('2026-07-29', '00:30:00', '22:00', '02:00', 'America/Mexico_City');

        $this->assertSame('2026-07-30 00:30:00', $i->format('Y-m-d H:i:s'));
    }

    public function test_reconstruye_el_instante_de_la_entrada_nocturna(): void
    {
        // Las 22:00 son del propio día de la jornada, no del siguiente.
        $i = JornadaLaboral::instanteDe('2026-07-29', '22:00:00', '22:00', '02:00', 'America/Mexico_City');

        $this->assertSame('2026-07-29 22:00:00', $i->format('Y-m-d H:i:s'));
    }

    public function test_en_turno_diurno_el_instante_es_literal(): void
    {
        $i = JornadaLaboral::instanteDe('2026-07-30', '09:15:00', '09:00', '18:00', 'America/Mexico_City');

        $this->assertSame('2026-07-30 09:15:00', $i->format('Y-m-d H:i:s'));
    }

    public function test_ida_y_vuelta_son_coherentes(): void
    {
        // Lo que `fechaDeNegocio` archiva, `instanteDe` lo devuelve intacto.
        $original = $this->enZona('2026-07-30 01:45:00');
        $fecha = JornadaLaboral::fechaDeNegocio($original, '22:00', '02:00');

        $recuperado = JornadaLaboral::instanteDe($fecha, '01:45:00', '22:00', '02:00', 'America/Mexico_City');

        $this->assertSame($original->format('Y-m-d H:i:s'), $recuperado->format('Y-m-d H:i:s'));
    }

    public function test_frontera_de_horario_de_verano(): void
    {
        // México suprimió el horario de verano en 2022, pero la pieza no debe romperse si el
        // tenant vive en una zona que sí lo aplica. 2026-11-01 02:00 en Nueva York ocurre tras
        // el atraso de reloj; lo que importa es que la fecha de negocio siga siendo la del día
        // anterior y no reviente.
        $instante = Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-01 01:30:00', 'America/New_York');
        $this->assertSame('2026-10-31',
            JornadaLaboral::fechaDeNegocio($instante, '22:00', '02:00'));
    }
}
