<?php

namespace App\Http\Controllers;

use App\Support\EstadoDelRespaldo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `GET /api/health` — la única señal de salud del servidor, la que mira el vigilante externo
 * (docs/VIGILANTE_DEL_SERVIDOR.md).
 *
 * Reemplaza al cierre inline que vivía en routes/api.php desde el commit inicial y que tenía
 * tres defectos, cada uno suficiente para volverlo inútil o peligroso:
 *
 *  1. Respondía **HTTP 200 con `status: "ok"` aunque la base estuviera caída** (el fallo iba en
 *     un campo del cuerpo). Un vigilante externo mira el CÓDIGO: se habría quedado en verde con
 *     la nómina inalcanzable. Ahora 200 sólo si todo está bien; cualquier otra cosa, 503.
 *  2. Concatenaba el mensaje CRUDO de PDO al JSON — SQLSTATE, nombre del host de Postgres, a
 *     veces el usuario— en una ruta PÚBLICA y sin sesión. Ahora eso va al log y nunca al cuerpo.
 *  3. Anunciaba `version: '1.0.0'`, un literal que nadie actualizó nunca. Fuera: un dato que no
 *     significa nada es peor que ninguno.
 *
 * Y mira una segunda cosa además de la base: la EDAD DEL RESPALDO. De nada sirve saber que el
 * servidor respira si el día que muera no hay de dónde revivirlo.
 *
 * SIN throttle de ruta a propósito: el limitador se apoya en el cache store, que por defecto es
 * 'database'. Con Postgres caído el throttle reventaría en 500 y se perdería justo el JSON que
 * explica QUÉ está caído. Es una ruta de lectura, sin parámetros y sin consultas: no hay nada
 * que estrangular.
 */
class SaludController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = 'fail';
            // El detalle vive aquí dentro, donde sólo lo ve quien ya tiene el servidor.
            Log::warning('Salud: la base de datos no responde', ['detalle' => $e->getMessage()]);
        }

        $respaldo = EstadoDelRespaldo::revisar();
        $sano = $db === 'ok' && $respaldo['ok'];

        return response()->json([
            'status' => $sano ? 'ok' : 'degradado',
            'db' => $db,
            'respaldo' => $respaldo,
            'timestamp' => now()->toIso8601String(),
        ], $sano ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
