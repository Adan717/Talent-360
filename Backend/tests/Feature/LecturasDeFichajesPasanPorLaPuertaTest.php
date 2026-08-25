<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Toda lectura de fichajes pasa por la puerta (2026-08-25).
 *
 * Desde la bitácora inmutable, corregir un fichaje lo ANULA en vez de borrarlo: la fila se queda
 * con su `anulado_at`. El scope global de `TimeEntry` los descarta, pero medio sistema no lee por
 * Eloquent — nómina, reportes, Monitor y barridos consultaban `DB::table('time_entries')` a pelo,
 * y un scope de Eloquent no los toca. Eran 27 sitios.
 *
 * Se migraron todos a `App\Support\FichajesVigentes::query()`. Pero migrarlos no basta: **el
 * defecto no es que falte un filtro, es que sea posible olvidarlo**. Esta prueba es el candado —
 * una lectura cruda nueva no llega al despliegue.
 *
 * Si algún día hace falta ver los anulados (una auditoría, la pantalla de correcciones, un juicio),
 * la salida es `FichajesVigentes::todos()`, que lo dice en su nombre. Salirse del camino seguro
 * cuesta una llamada distinta a propósito: obliga a decirlo en voz alta.
 */
class LecturasDeFichajesPasanPorLaPuertaTest extends TestCase
{
    /**
     * Únicos archivos donde `DB::table('time_entries')` en crudo es legítimo.
     *
     * @var array<string,string> ruta => por qué se le permite
     */
    private const PERMITIDOS = [
        // La puerta misma.
        'app/Support/FichajesVigentes.php' => 'es la puerta',

        // ESCRITURAS: insertar o borrar no necesita filtrar por anulado.
        'app/Http/Controllers/ClockController.php' => 'archiva y borra la jornada en el reinicio',
        'app/Http/Controllers/DashboardMonitorController.php' => 'inserta el cierre forzado',
        'app/Console/Commands/RepararCierresSinteticos.php' => 'borra cierres sintéticos',
        'app/Console/Commands/MigrateLegacySqlite.php' => 'migración de datos legados',
        'app/Console/Commands/PurgeTestTenantsCommand.php' => 'purga de empresas de prueba',
        'app/Console/Commands/CloseOrphanShifts.php' => 'inserta la salida automática',
        'app/Services/ClockService.php' => 'inserta el fichaje',
        'app/Http/Controllers/BackupController.php' => 'respaldo: copia la tabla entera a propósito',
        'app/Http/Controllers/AuthController.php' => 'escritura del kiosco',
        'app/Services/SimulatorSessionService.php' => 'purga de la sesión del Simulador',
        'app/Console/Commands/PurgeClockPhotos.php' => 'purga de fotos',
    ];

    public function test_ninguna_lectura_nueva_consulta_time_entries_en_crudo(): void
    {
        $sospechosas = [];

        foreach ($this->archivosPhpDeLaApp() as $ruta => $contenido) {
            if (isset(self::PERMITIDOS[$ruta])) {
                continue;
            }

            foreach ($this->sentenciasCrudas($contenido) as $numero => $sentencia) {
                if ($this->esEscritura($sentencia)) {
                    continue;
                }

                $sospechosas[] = $ruta . ':' . $numero;
            }
        }

        $this->assertSame(
            [],
            $sospechosas,
            "Lectura cruda de `time_entries`: esas consultas SIGUEN CONTANDO los fichajes anulados "
            . "por una corrección, así que la nómina o el reporte vería la jornada vieja y la nueva. "
            . 'Usa App\Support\FichajesVigentes::query(). Si de verdad necesitas ver los anulados '
            . '(auditoría o juicio), usa ::todos() y déjalo dicho.'
        );
    }

    /** Las excepciones no se acumulan sin que nadie mire: cada una está justificada por escrito. */
    public function test_cada_excepcion_de_la_lista_esta_justificada(): void
    {
        foreach (self::PERMITIDOS as $ruta => $porQue) {
            $this->assertNotSame('', trim($porQue), "La excepción {$ruta} no dice por qué se le permite.");
            $this->assertFileExists(base_path($ruta), "La excepción {$ruta} ya no existe: quítala de la lista.");
        }
    }

    /** @return array<string,string> ruta relativa => contenido */
    private function archivosPhpDeLaApp(): array
    {
        $archivos = [];
        $raiz = base_path('app');

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));
        foreach ($iterador as $archivo) {
            if (!$archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }

            $ruta = str_replace('\\', '/', substr($archivo->getPathname(), strlen(base_path()) + 1));
            $archivos[$ruta] = file_get_contents($archivo->getPathname());
        }

        return $archivos;
    }

    /**
     * Sentencias que arrancan con `DB::table('time_entries')`, reconstruidas hasta su `;` para
     * poder distinguir una lectura de una escritura encadenada en varias líneas.
     *
     * @return array<int,string> número de línea => sentencia completa
     */
    private function sentenciasCrudas(string $contenido): array
    {
        $lineas = explode("\n", $contenido);
        $sentencias = [];

        foreach ($lineas as $i => $linea) {
            if (!str_contains($linea, "DB::table('time_entries')")) {
                continue;
            }

            $sentencia = '';
            for ($j = $i; $j < min($i + 12, count($lineas)); $j++) {
                $sentencia .= $lineas[$j];
                if (str_contains($lineas[$j], ';')) {
                    break;
                }
            }

            $sentencias[$i + 1] = $sentencia;
        }

        return $sentencias;
    }

    private function esEscritura(string $sentencia): bool
    {
        return str_contains($sentencia, '->insert(')
            || str_contains($sentencia, '->insertGetId(')
            || str_contains($sentencia, '->delete()')
            || str_contains($sentencia, '->update(');
    }
}
