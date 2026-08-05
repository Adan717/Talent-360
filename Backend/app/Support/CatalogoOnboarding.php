<?php

namespace App\Support;

/**
 * Catálogo de puestos y tareas que el asistente de alta carga según el giro.
 *
 * POR QUÉ ES UN ARCHIVO Y NO CÓDIGO
 * ---------------------------------
 * Hasta hoy este catálogo vivía dentro de `OnboardingController::configureNicho`, en 165 líneas
 * de arreglos PHP a media función. Eso tenía una consecuencia que no era técnica:
 * **sólo un programador podía agregar una tarea**, y quien sabe cómo se abre un restaurante no
 * escribe PHP. Por eso hay un giro con 92 tareas y cuatro con 2 o 3.
 *
 * Al ser archivos JSON, el contenido lo edita quien conoce el negocio. La repartición queda:
 *
 *   - **Contenido** (qué tareas, cuántos minutos, de qué puesto) → producto, editando el JSON.
 *   - **Forma** (qué campos son obligatorios y qué significan) → aquí, porque hay cálculos
 *     colgando de ella: `estimated_mins` alimenta el costo en pesos de cada tarea y `momento`
 *     decide qué entra en la rutina de apertura.
 *
 * `CatalogoOnboardingValidoTest` verifica cada archivo, para que editar sin programador no
 * signifique editar a ciegas: si una tarea queda mal, lo dice una prueba en el momento y no un
 * cálculo de nómina tres semanas después.
 *
 * PARA AGREGAR UN GIRO: basta con dejar `<giro>.json` en `resources/catalogos/onboarding/`.
 * No hay que registrarlo en ningún lado.
 */
class CatalogoOnboarding
{
    /**
     * Giros que comparten catálogo con otro.
     *
     * `reposteria` y `materias_primas` son el mismo negocio —una tienda de insumos para
     * repostería— y el controlador siempre los trató igual. Se resuelve con un alias en vez de
     * duplicar 92 tareas, que es justo el problema que este archivo viene a quitar.
     *
     * El alias sólo aplica si el giro no tiene archivo propio: en cuanto exista
     * `reposteria.json`, ése manda.
     */
    private const ALIAS = [
        'reposteria' => 'materias_primas',
    ];

    /**
     * Devuelve `['puestos' => [...], 'cursos' => [...], 'tareas' => [...]]`, o `null` si el giro
     * no tiene catálogo.
     *
     * `cursos` se añadió con la auditoría de Academia (AC1): antes los cursos del giro estaban
     * escritos a mano dentro de `configureNicho` y la selección que hacía el dueño en el
     * asistente no llegaba nunca al servidor. Cada curso declara `title`, `description`,
     * `course_type` (induction/training/promotion/recertification), `video_url`, su `quiz` y un
     * `target_role_name`: el puesto que debe cursarlo, o `null` para toda la plantilla — que es
     * el default, porque antes TODOS los cursos se colgaban del puesto de mando y nadie más los
     * veía (AC2). Un catálogo sin `cursos` es válido: se trata como lista vacía.
     */
    public static function para(string $nicho): ?array
    {
        $ruta = self::rutaDe($nicho);

        if ($ruta === null) {
            return null;
        }

        $datos = json_decode(file_get_contents($ruta), true);

        if (!is_array($datos) || !isset($datos['puestos'], $datos['tareas'])) {
            return null;
        }

        $datos['cursos'] = $datos['cursos'] ?? [];

        return $datos;
    }

    public static function existe(string $nicho): bool
    {
        return self::rutaDe($nicho) !== null;
    }

    /** Los giros con catálogo propio, sin contar los alias. Lo usa la prueba de validación. */
    public static function giros(): array
    {
        $archivos = glob(self::directorio() . '/*.json') ?: [];

        return array_map(fn ($a) => basename($a, '.json'), $archivos);
    }

    /**
     * A propósito NO usa `resource_path()`: los proveedores de datos de PHPUnit se evalúan antes
     * de que arranque la aplicación, y ahí el helper todavía no existe. La ruta se calcula desde
     * este mismo archivo (`app/Support/` → raíz del backend), que es válido siempre.
     */
    public static function directorio(): string
    {
        return dirname(__DIR__, 2) . '/resources/catalogos/onboarding';
    }

    private static function rutaDe(string $nicho): ?string
    {
        // Un giro no puede traer separadores ni puntos: el valor viene de la petición y con
        // `../` se leería cualquier archivo del servidor.
        $nicho = strtolower(trim($nicho));

        if ($nicho === '' || !preg_match('/^[a-z0-9_-]+$/', $nicho)) {
            return null;
        }

        $ruta = self::directorio() . "/{$nicho}.json";

        if (is_file($ruta)) {
            return $ruta;
        }

        $alias = self::ALIAS[$nicho] ?? null;

        if ($alias !== null && is_file($rutaAlias = self::directorio() . "/{$alias}.json")) {
            return $rutaAlias;
        }

        return null;
    }
}
