<?php

namespace Tests\Feature;

use App\Support\CatalogoOnboarding;
use Tests\TestCase;

/**
 * Valida los catálogos de `resources/catalogos/onboarding/*.json`.
 *
 * RAZÓN DE SER: el catálogo se sacó del código PHP justamente para que lo edite quien conoce el
 * giro, sin programar. Esta prueba es la contraparte de esa libertad — el aviso de que algo quedó
 * mal tiene que llegar al editar, no tres semanas después dentro de un cálculo de nómina.
 *
 * Cada cosa que se comprueba aquí corresponde a algo que se rompe de verdad si falta:
 *
 *  - `estimated_mins`  → alimenta el costo en pesos de la tarea. Sin él, el valor por defecto es
 *                        15 min y el costo sale mal **sin ningún error visible**.
 *  - `momento`         → decide qué tareas entran en la rutina de apertura. Sin él, la tarea
 *                        existe pero nunca se reparte sola.
 *  - `target_role_name`→ si no corresponde a un puesto del mismo giro, la tarea queda sin dueño.
 *  - `jerarquiaLlaves` → construye el organigrama, del que depende la firma del supervisor.
 *
 * No hereda `RefreshDatabase`: son archivos, no filas.
 */
class CatalogoOnboardingValidoTest extends TestCase
{
    public static function giros(): array
    {
        // `giros()` lee el directorio, así que un archivo nuevo entra a la prueba solo.
        return array_map(fn ($g) => [$g], CatalogoOnboarding::giros());
    }

    public function test_hay_catalogos_que_validar(): void
    {
        $this->assertNotEmpty(CatalogoOnboarding::giros(),
            'No se encontró ningún catálogo en ' . CatalogoOnboarding::directorio());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_el_catalogo_del_giro_es_legible(string $giro): void
    {
        $catalogo = CatalogoOnboarding::para($giro);

        $this->assertNotNull($catalogo, "'{$giro}.json' no es JSON válido o le falta 'puestos'/'tareas'.");
        $this->assertNotEmpty($catalogo['puestos'], "El giro '{$giro}' no declara ningún puesto.");
        $this->assertNotEmpty($catalogo['tareas'], "El giro '{$giro}' no declara ninguna tarea.");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_cada_puesto_trae_lo_que_el_organigrama_necesita(string $giro): void
    {
        $catalogo = CatalogoOnboarding::para($giro);

        foreach ($catalogo['puestos'] as $i => $p) {
            $donde = "{$giro}.json, puesto #{$i}";

            $this->assertArrayHasKey('name', $p, "{$donde}: falta 'name'.");
            $this->assertNotEmpty($p['name'], "{$donde}: 'name' vacío.");
            $this->assertArrayHasKey('area', $p, "{$donde} ('{$p['name']}'): falta 'area'.");

            $this->assertArrayHasKey('jerarquiaLlaves', $p,
                "{$donde} ('{$p['name']}'): falta 'jerarquiaLlaves'; sin ella no se arma el "
                . 'organigrama y no se exige la firma de ningún supervisor.');
            $this->assertIsInt($p['jerarquiaLlaves'], "{$donde} ('{$p['name']}'): 'jerarquiaLlaves' debe ser un número.");
            $this->assertGreaterThanOrEqual(1, $p['jerarquiaLlaves'], "{$donde} ('{$p['name']}'): el nivel más alto es 1.");

            $this->assertArrayHasKey('esAperturador', $p, "{$donde} ('{$p['name']}'): falta 'esAperturador'.");
            $this->assertIsBool($p['esAperturador'], "{$donde} ('{$p['name']}'): 'esAperturador' debe ser true o false.");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_el_giro_tiene_un_solo_puesto_de_mando(string $giro): void
    {
        // `construirOrganigrama` cuelga a todos del nivel 1. Con dos cabezas o con ninguna, el
        // organigrama queda ambiguo o entero huérfano.
        $catalogo = CatalogoOnboarding::para($giro);

        $mando = array_filter($catalogo['puestos'], fn ($p) => ($p['jerarquiaLlaves'] ?? 0) === 1);

        $this->assertCount(1, $mando,
            "El giro '{$giro}' debe tener exactamente un puesto con jerarquiaLlaves=1; tiene "
            . count($mando) . '.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_alguien_tiene_las_llaves(string $giro): void
    {
        $catalogo = CatalogoOnboarding::para($giro);

        $this->assertNotEmpty(
            array_filter($catalogo['puestos'], fn ($p) => $p['esAperturador'] ?? false),
            "En el giro '{$giro}' ningún puesto abre: la rutina de apertura no tendría a quién "
            . 'asignarse.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_cada_tarea_declara_sus_minutos(string $giro): void
    {
        // EL CAMPO QUE MÁS CARO SALE OLVIDAR: sin él la tarea cuesta 15 min por defecto y el
        // costo en pesos queda mal sin que nada falle.
        $catalogo = CatalogoOnboarding::para($giro);

        foreach ($catalogo['tareas'] as $i => $t) {
            $titulo = $t['title'] ?? "#{$i}";

            $this->assertArrayHasKey('estimated_mins', $t,
                "{$giro}.json, '{$titulo}': falta 'estimated_mins'. Sin ese dato la tarea cuesta "
                . 'lo que cueste el valor por defecto, y nadie se entera.');
            $this->assertIsInt($t['estimated_mins'], "{$giro}.json, '{$titulo}': 'estimated_mins' debe ser un número.");
            $this->assertGreaterThan(0, $t['estimated_mins'], "{$giro}.json, '{$titulo}': una tarea no dura 0 minutos.");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_cada_tarea_trae_titulo_prioridad_y_categoria(string $giro): void
    {
        $catalogo = CatalogoOnboarding::para($giro);

        foreach ($catalogo['tareas'] as $i => $t) {
            $titulo = $t['title'] ?? "#{$i}";

            $this->assertArrayHasKey('title', $t, "{$giro}.json, tarea #{$i}: falta 'title'.");
            $this->assertNotEmpty($t['title'], "{$giro}.json, tarea #{$i}: 'title' vacío.");
            $this->assertArrayHasKey('priority', $t, "{$giro}.json, '{$titulo}': falta 'priority'.");
            $this->assertContains($t['priority'], ['bloqueante', 'alta', 'normal', 'baja'],
                "{$giro}.json, '{$titulo}': prioridad '{$t['priority']}' desconocida.");
            $this->assertArrayHasKey('category', $t, "{$giro}.json, '{$titulo}': falta 'category'.");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_ninguna_tarea_apunta_a_un_puesto_inexistente(string $giro): void
    {
        // Un `target_role_name` mal escrito deja la tarea sin dueño: se crea, se ve en el panel y
        // no le llega a nadie. Es el error más fácil de cometer editando a mano.
        $catalogo = CatalogoOnboarding::para($giro);
        $nombres = array_column($catalogo['puestos'], 'name');

        foreach ($catalogo['tareas'] as $t) {
            $this->assertArrayHasKey('target_role_name', $t, "{$giro}.json, '{$t['title']}': falta 'target_role_name'.");
            $this->assertContains($t['target_role_name'], $nombres,
                "{$giro}.json, '{$t['title']}': apunta al puesto '{$t['target_role_name']}', que no "
                . "existe en este giro. Puestos válidos: " . implode(', ', $nombres));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_el_momento_de_la_tarea_es_uno_de_los_conocidos(string $giro): void
    {
        // 'momento' es opcional —una tarea puede no ser ni de apertura ni de cierre—, pero si
        // viene tiene que ser un valor que el backend sepa interpretar. Un 'appertura' con dos
        // pes se queda fuera de la rutina en silencio.
        $catalogo = CatalogoOnboarding::para($giro);

        $desconocidos = [];

        foreach ($catalogo['tareas'] as $t) {
            $momento = $t['momento'] ?? null;

            if ($momento !== null && !in_array($momento, ['apertura', 'cierre'], true)) {
                $desconocidos[] = "'{$t['title']}' → '{$momento}'";
            }
        }

        $this->assertSame([], $desconocidos,
            "{$giro}.json tiene tareas con un momento que el backend no sabe interpretar, así que "
            . 'no entrarían en ninguna rutina: ' . implode(' | ', $desconocidos));
    }

    /**
     * Giros que hoy NO tienen ninguna tarea de apertura.
     *
     * NO ES UNA EXCEPCIÓN DE ESTILO, ES UN PENDIENTE CON NOMBRE. Un giro sin tareas de apertura
     * no genera rutina, y sin rutina el asistente crea las tareas pero **nada las reparte al abrir
     * la sucursal**: hay que darlas de alta a mano una por una. El módulo se vende como
     * "Automatiza Rutinas", así que un cliente de estos cuatro giros no recibe lo que compró.
     *
     * Se descubrió al sacar el catálogo a JSON: sólo repostería marca `momento`, y por eso era la
     * única con automatización real. Antes no se veía porque estaba repartido en 165 líneas de PHP.
     *
     * **Al llenar un giro hay que quitarlo de esta lista**, y `test_la_lista_de_giros_a_medias_no_miente`
     * avisa si a alguien se le olvida.
     */
    private const GIROS_SIN_APERTURA = ['oficina', 'retail', 'taller'];

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_el_giro_tiene_tareas_de_apertura(string $giro): void
    {
        $apertura = self::tareasDeApertura($giro);

        if (in_array($giro, self::GIROS_SIN_APERTURA, true)) {
            $this->assertSame([], $apertura,
                "El giro '{$giro}' YA tiene tareas de apertura: quítalo de GIROS_SIN_APERTURA "
                . 'para que esta prueba empiece a protegerlo.');

            return;
        }

        $this->assertNotEmpty($apertura,
            "El giro '{$giro}' no tiene ninguna tarea marcada con momento='apertura': al abrir la "
            . 'sucursal no se repartiría nada y habría que dar de alta todo a mano.');
    }

    public function test_la_lista_de_giros_a_medias_no_miente(): void
    {
        // Que la lista no acumule giros que ya se llenaron ni giros que ya no existen.
        foreach (self::GIROS_SIN_APERTURA as $giro) {
            $this->assertTrue(CatalogoOnboarding::existe($giro),
                "GIROS_SIN_APERTURA menciona '{$giro}', que ya no tiene catálogo.");
        }

        $pendientes = array_values(array_intersect(self::GIROS_SIN_APERTURA, CatalogoOnboarding::giros()));

        $this->assertNotEmpty($pendientes,
            'Si ya no queda ningún giro sin tareas de apertura, borra GIROS_SIN_APERTURA y con '
            . 'ella este comentario: el pendiente estaría cerrado.');
    }

    /** @return array<int, array<string, mixed>> */
    private static function tareasDeApertura(string $giro): array
    {
        $catalogo = CatalogoOnboarding::para($giro);

        return array_values(array_filter(
            $catalogo['tareas'],
            fn ($t) => ($t['momento'] ?? null) === 'apertura'
        ));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('giros')]
    public function test_no_hay_tareas_repetidas(string $giro): void
    {
        $catalogo = CatalogoOnboarding::para($giro);
        $titulos = array_column($catalogo['tareas'], 'title');

        $repetidos = array_keys(array_filter(array_count_values($titulos), fn ($n) => $n > 1));

        $this->assertEmpty($repetidos,
            "{$giro}.json tiene tareas repetidas: " . implode(' | ', $repetidos));
    }

    public function test_reposteria_sigue_resolviendo_al_catalogo_de_materias_primas(): void
    {
        // El alias evita duplicar 92 tareas. Si alguien lo quita sin querer, el giro se queda sin
        // catálogo y el asistente termina sin crear nada.
        $this->assertTrue(CatalogoOnboarding::existe('reposteria'));
        $this->assertSame(
            CatalogoOnboarding::para('materias_primas'),
            CatalogoOnboarding::para('reposteria')
        );
    }

    public function test_un_giro_inventado_no_devuelve_catalogo(): void
    {
        $this->assertNull(CatalogoOnboarding::para('peluqueria_espacial'));
        $this->assertFalse(CatalogoOnboarding::existe('peluqueria_espacial'));
    }

    public function test_el_giro_no_puede_salirse_del_directorio_de_catalogos(): void
    {
        // El giro llega en la petición: sin esta comprobación, `../../.env` sería un catálogo.
        foreach (['../../../.env', '..\\..\\config\\app', 'materias_primas/../../.env', '.'] as $malicioso) {
            $this->assertNull(CatalogoOnboarding::para($malicioso),
                "El giro '{$malicioso}' no debe resolver a ningún archivo.");
        }
    }
}
