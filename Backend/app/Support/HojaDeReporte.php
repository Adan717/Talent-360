<?php

namespace App\Support;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Una hoja de un reporte en Excel: encabezado congelado, filtros puestos y columnas al ancho
 * del contenido.
 *
 * Es lo que el CSV no puede dar. Un CSV es texto: al abrirlo hay que congelar el encabezado a
 * mano cada vez, y si el archivo trae un bloque de resumen pegado abajo —como lo traían
 * Rotación, Reclutamiento y Nómina Histórica— ordenar o filtrar la tabla revuelve ese bloque
 * con los datos. Por eso el resumen y las notas viven en SUS PROPIAS hojas.
 */
class HojaDeReporte implements FromArray, WithHeadings, WithTitle, WithStyles, WithEvents, ShouldAutoSize
{
    public function __construct(
        private string $titulo,
        private array $encabezados,
        private array $filas,
        private bool $conFiltros = true,
    ) {
    }

    public function array(): array
    {
        return $this->filas;
    }

    public function headings(): array
    {
        return $this->encabezados;
    }

    /** Excel no admite más de 31 caracteres ni `[]:*?/\` en el nombre de una pestaña. */
    public function title(): string
    {
        $limpio = str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $this->titulo);

        return mb_substr(trim($limpio), 0, 31) ?: 'Datos';
    }

    public function styles(Worksheet $hoja): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $evento) {
                $hoja = $evento->sheet->getDelegate();
                $hoja->freezePane('A2');

                // Sin filas no hay nada que filtrar, y un autofiltro sobre una hoja vacía deja
                // el archivo con un rango inválido que Excel reporta como dañado.
                if ($this->conFiltros && $this->filas) {
                    $hoja->setAutoFilter($hoja->calculateWorksheetDimension());
                }
            },
        ];
    }
}
