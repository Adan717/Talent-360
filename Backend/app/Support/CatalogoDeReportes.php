<?php

namespace App\Support;

/**
 * Catálogo ÚNICO de los reportes operativos (2026-08-13).
 *
 * Con dos reportes la lista podía vivir duplicada en tres lados; con once se desincroniza
 * sola — y el síntoma sería el peor posible: el asistente ofrece un reporte cuya descarga no
 * existe, o la pantalla muestra una tarjeta que el servidor no sabe servir. Así que la lista
 * vive AQUÍ y de aquí la leen el asistente (para su vocabulario), el controlador (para
 * validar lo que devuelve el modelo) y el frontend (por `/admin/reports/catalogo`).
 *
 * `descripcion` es lo que el modelo usa para elegir: se escribe pensando en cómo lo diría un
 * encargado, no en cómo se llama la tabla.
 */
class CatalogoDeReportes
{
    /**
     * id => [titulo, descripcion (para el asistente y la pantalla), dias_por_defecto].
     * El id es el nombre del archivo: /admin/reports/<id>.csv
     */
    public const REPORTES = [
        'asistencia' => [
            'titulo' => 'Asistencia',
            'descripcion' => 'el detalle de entradas, salidas y retardos, movimiento por movimiento',
            'dias' => 1,
        ],
        'retardos' => [
            'titulo' => 'Retardos y Faltas por Colaborador',
            'descripcion' => 'el RESUMEN por persona de cuántos retardos y faltas acumula (quién reincide, quién falta seguido)',
            'dias' => 30,
        ],
        'horas' => [
            'titulo' => 'Horas Trabajadas y Extra',
            'descripcion' => 'horas trabajadas y efectivas por día y por persona (descontando comida), y trabajo en día no laborable',
            'dias' => 14,
        ],
        'rutinas' => [
            'titulo' => 'Cumplimiento de Rutinas',
            'descripcion' => 'cumplimiento de rutinas y tareas: qué se hizo, qué se omitió y qué quedó sin cerrar, por persona y por tarea',
            'dias' => 30,
        ],
        'tareas' => [
            'titulo' => 'Tareas Completadas',
            'descripcion' => 'el listado de tareas completadas, una por renglón',
            'dias' => 30,
        ],
        'justificantes' => [
            'titulo' => 'Justificantes y Autorizaciones',
            'descripcion' => 'qué justificantes de retardo, contingencias, entradas tardías, horas extra y salidas anticipadas se aprobaron o rechazaron, y quién lo resolvió',
            'dias' => 30,
        ],
        'aperturas' => [
            'titulo' => 'Aperturas y Cierres de Sucursal',
            'descripcion' => 'quién abrió y cerró la sucursal, a qué hora, si fue a tiempo y las aperturas de emergencia',
            'dias' => 30,
        ],
        'comedor' => [
            'titulo' => 'Comedor y Ley Silla',
            'descripcion' => 'comidas tomadas, excesos de tiempo de comida y descansos de Ley Silla',
            'dias' => 30,
        ],
        'academia' => [
            'titulo' => 'Inducción y Capacitación',
            'descripcion' => 'quién trae la inducción pendiente, quién se atoró reprobando un curso y qué certificados se emitieron',
            'dias' => 90,
        ],
        'expedientes' => [
            'titulo' => 'Expediente Documental',
            'descripcion' => 'qué documentos tiene validados cada colaborador y cuáles le faltan de su expediente',
            'dias' => 1,
        ],
        'reclutamiento' => [
            'titulo' => 'Embudo de Reclutamiento',
            'descripcion' => 'candidatos por etapa, contratados y rechazados por vacante',
            'dias' => 90,
        ],
        'monedero' => [
            'titulo' => 'Monedero y Reconocimientos',
            'descripcion' => 'monedas y puntos ganados por persona, y las tareas validadas o rechazadas',
            'dias' => 30,
        ],
        'rotacion' => [
            'titulo' => 'Rotación de Personal',
            'descripcion' => 'altas, bajas, plantilla activa y antigüedad de la gente',
            'dias' => 90,
        ],
        // Con dinero: sólo lo ve quien tiene la capacidad de nómina (ver `esDeNomina`).
        'nomina_historica' => [
            'titulo' => 'Nómina Histórica',
            'descripcion' => 'lo que se pagó en periodos anteriores: netos, deducciones, firmas y timbrado',
            'dias' => 90,
            'nomina' => true,
        ],
        'costo_por_puesto' => [
            'titulo' => 'Costo de Nómina por Puesto y Área',
            'descripcion' => 'cuánto cuesta cada puesto o área: sueldo, bonos y cuánto se descontó por faltas y retardos',
            'dias' => 90,
            'nomina' => true,
        ],
    ];

    /** ¿Este reporte trae datos salariales? (define detrás de qué candado vive su ruta) */
    public static function esDeNomina(string $id): bool
    {
        return (bool) (self::REPORTES[$id]['nomina'] ?? false);
    }

    /** Los que puede bajar cualquiera con acceso a Reportes (sin dato salarial). */
    public static function idsOperativos(): array
    {
        return array_keys(array_filter(self::REPORTES, fn ($r) => empty($r['nomina'])));
    }

    /** @return string[] los ids válidos (lo que el asistente puede devolver). */
    public static function ids(): array
    {
        return array_keys(self::REPORTES);
    }

    /** Días por defecto de un reporte cuando la frase no trae periodo. */
    public static function diasPorDefecto(string $id): int
    {
        return self::REPORTES[$id]['dias'] ?? 30;
    }

    /** La lista que el modelo lee para elegir reporte, en su propio vocabulario. */
    public static function paraElPrompt(): string
    {
        $lineas = [];
        foreach (self::REPORTES as $id => $r) {
            $lineas[] = "- \"{$id}\": {$r['descripcion']}.";
        }

        return implode("\n", $lineas);
    }

    /** Lo que la pantalla necesita para pintar las tarjetas sin repetir la lista. */
    public static function paraLaPantalla(): array
    {
        return array_map(
            fn ($id, $r) => ['id' => $id, 'titulo' => $r['titulo'], 'descripcion' => ucfirst($r['descripcion']) . '.'],
            array_keys(self::REPORTES),
            self::REPORTES
        );
    }
}
