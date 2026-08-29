<?php

namespace App\Http\Controllers;

use App\Services\ImportacionDePlantilla;
use Illuminate\Http\Request;

/**
 * Importación masiva de plantilla (2026-08-28). Tres puertas:
 *
 *   GET  /admin/employees/import/plantilla.csv  → el archivo de ejemplo con sus encabezados
 *   POST /admin/employees/import/revisar        → el veredicto, SIN escribir nada
 *   POST /admin/employees/import                → el alta, sólo si todo está bien
 *
 * El controlador valida la FORMA (que venga un archivo, que pese poco) y delega: las reglas de
 * negocio viven en `ImportacionDePlantilla`, que es también quien vuelve a revisar antes de
 * escribir. Dos puertas separadas —revisar y aplicar— para que la pantalla pueda enseñar el
 * "esto va a pasar" antes de que pase, sobre datos de personas.
 */
class ImportacionPlantillaController extends Controller
{
    public function __construct(private ImportacionDePlantilla $importacion)
    {
    }

    public function plantilla()
    {
        return response($this->importacion->plantilla(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla_colaboradores.csv"',
        ]);
    }

    public function revisar(Request $request)
    {
        $csv = $this->leerArchivo($request);

        return response()->json(array_merge(
            ['success' => true],
            $this->importacion->revisar($csv, (int) $request->user()->tenant_id)
        ));
    }

    public function importar(Request $request)
    {
        $csv = $this->leerArchivo($request);

        try {
            $resultado = $this->importacion->importar($csv, (int) $request->user()->tenant_id);
        } catch (\RuntimeException $e) {
            // El archivo no pasó la aduana: se devuelve el veredicto completo para que la
            // pantalla enseñe QUÉ hay que corregir, no sólo que falló.
            return response()->json(array_merge(
                ['success' => false, 'message' => $e->getMessage()],
                $this->importacion->revisar($csv, (int) $request->user()->tenant_id)
            ), 422);
        }

        return response()->json([
            'success' => true,
            'message' => $resultado['creados'] . ' colaborador(es) dados de alta.',
            'creados' => $resultado['creados'],
            'renglones' => $resultado['renglones'],
        ], 201);
    }

    /** El CSV puede llegar como archivo subido o como texto pegado. */
    private function leerArchivo(Request $request): string
    {
        $request->validate([
            'archivo' => 'required_without:csv|file|mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel|max:2048',
            'csv' => 'required_without:archivo|string|max:1000000',
        ]);

        if ($request->hasFile('archivo')) {
            return (string) file_get_contents($request->file('archivo')->getRealPath());
        }

        return (string) $request->input('csv');
    }
}
