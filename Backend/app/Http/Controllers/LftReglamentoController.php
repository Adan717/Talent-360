<?php

namespace App\Http\Controllers;

use App\Services\GeminiAIService;
use App\Support\PropuestaDeReglamentoLft;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as LectorDePdf;

/**
 * Asistente del reglamento interior (Plan A3, 2026-09-07).
 *
 * POST /admin/lft/leer-reglamento — sólo admin. Recibe el reglamento (PDF o TXT, o el texto
 * pegado), lo lee con la IA y devuelve una PROPUESTA depurada. NUNCA escribe en `lft_settings`:
 * guardar es un acto del admin en la pantalla de LFT (`LftSettingController::saveSettings`), con
 * la validación completa de siempre. Aquí sólo se propone y se cita de dónde salió cada número.
 *
 * Antes, `LftManager.handleLftFileUpload` era teatro: 7 segundos de barra de progreso y números
 * fijos que no venían del archivo.
 */
class LftReglamentoController extends Controller
{
    /** Tamaño máximo del archivo, en KB (lo que anuncia la pantalla: 5 MB). */
    private const MAX_KB = 5120;

    /** Texto mínimo para intentar una lectura: menos que esto no es un reglamento. */
    private const MIN_CARACTERES = 200;

    public function leer(Request $request)
    {
        $request->validate([
            'archivo' => 'required_without:texto|file|mimes:pdf,txt|max:' . self::MAX_KB,
            'texto' => 'required_without:archivo|nullable|string|max:400000',
        ], [
            'archivo.mimes' => 'Sólo se aceptan archivos PDF o TXT.',
            'archivo.max' => 'El archivo pasa de 5 MB.',
            'archivo.required_without' => 'Sube el reglamento (PDF o TXT) o pega su texto.',
        ]);

        if (!GeminiAIService::disponible()) {
            return response()->json([
                'success' => false,
                'message' => 'Sin llave de IA configurada en el servidor: el asistente no puede leer el reglamento. '
                    . 'Puedes capturar las tolerancias a mano en esta misma pantalla.',
            ], 503);
        }

        $nombre = 'texto pegado';
        $texto = (string) $request->input('texto', '');

        if ($request->hasFile('archivo')) {
            $archivo = $request->file('archivo');
            $nombre = $archivo->getClientOriginalName();
            $extension = strtolower((string) $archivo->getClientOriginalExtension());

            try {
                $texto = $extension === 'pdf'
                    ? (new LectorDePdf())->parseFile($archivo->getRealPath())->getText()
                    : (string) file_get_contents($archivo->getRealPath());
            } catch (\Throwable $e) {
                Log::warning('LftReglamento: no se pudo leer el archivo', ['archivo' => $nombre, 'error' => $e->getMessage()]);
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo leer el archivo. Si es un PDF escaneado (imagen), pega el texto del capítulo de asistencia.',
                ], 422);
            }
        }

        $texto = trim(preg_replace('/[ \t]+/', ' ', (string) $texto) ?? '');

        if (mb_strlen($texto) < self::MIN_CARACTERES) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo casi no tiene texto legible. Si es un PDF escaneado (imagen), pega el texto del capítulo de asistencia.',
            ], 422);
        }

        try {
            $cruda = app(GeminiAIService::class)->leerReglamentoLft($texto);
        } catch (\Throwable $e) {
            Log::error('LftReglamento: la IA falló', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'La IA no pudo leer el reglamento en este momento: ' . $e->getMessage(),
            ], 502);
        }

        $depurada = PropuestaDeReglamentoLft::depurar($cruda);

        return response()->json([
            'success' => true,
            'fuente' => ['nombre' => $nombre, 'caracteres' => mb_strlen($texto)],
            'propuesta' => $depurada['propuesta'],
            'articulos' => $depurada['articulos'],
            'advertencias' => $depurada['advertencias'],
            'descartadas' => $depurada['descartadas'],
            'nota' => 'Es una propuesta: nada se guarda hasta que revises los valores y pulses Guardar en el reglamento.',
        ]);
    }
}
