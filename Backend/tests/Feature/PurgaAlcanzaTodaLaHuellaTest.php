<?php

namespace Tests\Feature;

use App\Support\HuellaDelColaborador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ninguna tabla con datos de una persona se queda fuera de la purga sin que alguien lo diga
 * (2026-09-05).
 *
 * El defecto que esta prueba evita no es "falta una tabla": es que **sea posible olvidarla en
 * silencio**. Una purga de retención que deja fuera dos tablas no falla, no avisa y no se nota —
 * simplemente la empresa cree que suprimió los datos personales de alguien y no lo hizo. Ese es
 * justo el incumplimiento que la purga venía a arreglar.
 *
 * Así que el mapa (`App\Support\HuellaDelColaborador`) se contrasta contra el ESQUEMA REAL en las
 * dos direcciones: ninguna tabla nueva con `user_id`/`employee_id` puede aparecer sin declararse,
 * y ninguna columna declarada puede dejar de existir sin que salte.
 */
class PurgaAlcanzaTodaLaHuellaTest extends TestCase
{
    use RefreshDatabase;

    /** Tablas del framework y del negocio que no guardan a una persona por esas columnas. */
    private const INFRAESTRUCTURA = ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'];

    public function test_toda_tabla_con_user_id_o_employee_id_esta_declarada(): void
    {
        $declaradas = array_merge(
            array_keys(HuellaDelColaborador::POR_ID_DE_USUARIO),
            array_keys(HuellaDelColaborador::POR_ID_DE_EXPEDIENTE),
            array_keys(HuellaDelColaborador::PARES),
            array_keys(HuellaDelColaborador::FUERA),
        );

        $sinDeclarar = [];

        foreach ($this->tablas() as $tabla) {
            if (in_array($tabla, self::INFRAESTRUCTURA, true) || in_array($tabla, $declaradas, true)) {
                continue;
            }

            $columnas = Schema::getColumnListing($tabla);
            if (array_intersect($columnas, ['user_id', 'employee_id'])) {
                $sinDeclarar[] = $tabla;
            }
        }

        sort($sinDeclarar);

        $this->assertSame(
            [],
            $sinDeclarar,
            "Estas tablas guardan a una persona y la purga de retención no sabe de ellas. Decláralas en "
            . "App\\Support\\HuellaDelColaborador: en POR_ID_DE_USUARIO o POR_ID_DE_EXPEDIENTE si hay que "
            . "borrarlas (y comprueba en su migración a QUÉ tabla apunta de verdad la columna: en varias "
            . "se llama `employee_id` y guarda un users.id), en PARES si la persona puede estar en "
            . "cualquiera de dos lados, o en FUERA con el motivo escrito de por qué se queda."
        );
    }

    public function test_cada_columna_declarada_existe_de_verdad(): void
    {
        $problemas = [];

        foreach (HuellaDelColaborador::plan() as $paso) {
            if (!Schema::hasTable($paso['tabla'])) {
                $problemas[] = $paso['tabla'] . ' (la tabla ya no existe)';
                continue;
            }

            foreach ($paso['columnas'] as $columna) {
                if (!Schema::hasColumn($paso['tabla'], $columna)) {
                    $problemas[] = $paso['tabla'] . '.' . $columna;
                }
            }
        }

        $this->assertSame(
            [],
            $problemas,
            'El mapa de la purga apunta a columnas que ya no están: se borraría menos de lo que dice.'
        );
    }

    public function test_cada_exclusion_dice_por_que_se_queda(): void
    {
        foreach (HuellaDelColaborador::FUERA as $tabla => $porQue) {
            $this->assertNotSame('', trim($porQue), "La exclusión de {$tabla} no dice por qué.");
            $this->assertTrue(Schema::hasTable($tabla), "La exclusión {$tabla} ya no existe: quítala.");
        }
    }

    public function test_ninguna_tabla_esta_declarada_dos_veces(): void
    {
        // Una tabla en dos grupos se borraría dos veces con criterios distintos, y el segundo
        // pasaría por el id equivocado.
        $todas = array_merge(
            array_keys(HuellaDelColaborador::POR_ID_DE_USUARIO),
            array_keys(HuellaDelColaborador::POR_ID_DE_EXPEDIENTE),
            array_keys(HuellaDelColaborador::PARES),
            array_keys(HuellaDelColaborador::FUERA),
        );

        $repetidas = array_keys(array_filter(array_count_values($todas), fn ($n) => $n > 1));

        $this->assertSame([], $repetidas, 'Estas tablas están declaradas en más de un grupo.');
    }

    /** @return array<int,string> */
    private function tablas(): array
    {
        return array_map(
            // sqlite devuelve "main.tabla"; Postgres, "tabla".
            fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t,
            Schema::getTableListing()
        );
    }
}
