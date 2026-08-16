<?php

namespace App\Support;

use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * El reporte como libro de Excel de verdad (.xlsx), no como CSV renombrado.
 *
 * Tres cosas que el CSV no puede hacer y que sí importan:
 *
 * 1. TIPOS. En un CSV todo es texto y Excel adivina — y adivina según el idioma de quien lo
 *    abre. "13125.00" en un Excel en español, donde el separador decimal es la coma, puede
 *    leerse como trece millones. Aquí un número se escribe como número y una fecha como fecha,
 *    y no hay nada que adivinar.
 *
 * 2. HOJAS. El resumen y las notas al pie iban pegados al final de la misma tabla, así que
 *    ordenar o filtrar los datos los revolvía con los renglones. Cada uno en su hoja.
 *
 * 3. FÓRMULAS. Es también la mitigación de inyección: TODO lo que no sea número o fecha se
 *    escribe con tipo texto explícito, así que un colaborador que se ponga de nombre
 *    `=HYPERLINK(...)` no consigue que Excel lo evalúe en la máquina del administrador. En el
 *    CSV eso se resuelve anteponiendo un apóstrofo; aquí no hace falta ensuciar el dato.
 */
class LibroDeReporte extends DefaultValueBinder implements WithMultipleSheets, WithCustomValueBinder
{
    /**
     * @param array|null $resumen ['titulo' => string, 'encabezados' => array, 'filas' => array]
     */
    public function __construct(
        private string $titulo,
        private array $encabezados,
        private array $filas,
        private array $notas = [],
        private ?array $resumen = null,
    ) {
    }

    public function sheets(): array
    {
        $hojas = [new HojaDeReporte($this->titulo, $this->encabezados, $this->filas)];

        if ($this->resumen && $this->resumen['filas']) {
            $hojas[] = new HojaDeReporte(
                $this->resumen['titulo'],
                $this->resumen['encabezados'],
                $this->resumen['filas'],
                conFiltros: false,
            );
        }

        if ($this->notas) {
            $hojas[] = new HojaDeReporte(
                'Cómo leer este reporte',
                ['Cómo leer este reporte'],
                array_map(fn ($n) => [$n], $this->notas),
                conFiltros: false,
            );
        }

        return $hojas;
    }

    public function bindValue(Cell $celda, $valor): bool
    {
        if (!is_string($valor)) {
            return parent::bindValue($celda, $valor);
        }

        if ($valor === '') {
            $celda->setValueExplicit('', DataType::TYPE_STRING);

            return true;
        }

        // Fecha de verdad, para poder filtrar "el mes pasado" sin trucos.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            $celda->setValueExplicit(Date::stringToExcel($valor), DataType::TYPE_NUMERIC);
            $celda->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd');

            return true;
        }

        // Porcentaje como número: en texto, ordenar pone "100%" antes que "80%".
        if (preg_match('/^(\d+(?:\.\d+)?)%$/', $valor, $partes)) {
            $celda->setValueExplicit((float) $partes[1] / 100, DataType::TYPE_NUMERIC);
            $celda->getStyle()->getNumberFormat()->setFormatCode('0%');

            return true;
        }

        // Número — salvo si trae ceros a la izquierda: "0012" es un identificador, y
        // convertirlo a 12 destruye el dato en silencio.
        if (is_numeric($valor) && !preg_match('/^-?0\d/', $valor)) {
            $celda->setValueExplicit((float) $valor, DataType::TYPE_NUMERIC);
            if (str_contains($valor, '.')) {
                $celda->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
            }

            return true;
        }

        $celda->setValueExplicit($valor, DataType::TYPE_STRING);

        return true;
    }
}
