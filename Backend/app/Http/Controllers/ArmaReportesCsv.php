<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use App\Support\CatalogoDeReportes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El andamio común de los reportes CSV (2026-08-13): rango validado con tope, CSV con BOM y
 * notas al pie, celdas neutralizadas contra fórmulas de Excel, y el directorio de nombres.
 *
 * Existe porque con once reportes repartidos en tres controladores, copiar el `csv()` en cada
 * uno garantizaba que la mitigación de fórmulas (o el tope de días) acabara aplicada en unos
 * sí y en otros no.
 */
trait ArmaReportesCsv
{
    /** Rango validado, con los días por defecto que declara el catálogo para ese reporte. */
    private function rango(Request $request, string $reporteId): array
    {
        $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        $tz = TenantTimezone::for((int) $request->user()->tenant_id);
        $hasta = $request->query('to', Carbon::now($tz)->toDateString());
        $dias = CatalogoDeReportes::diasPorDefecto($reporteId);
        $desde = $request->query('from', Carbon::createFromFormat('Y-m-d', $hasta, $tz)->subDays(max(0, $dias - 1))->toDateString());

        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        if (Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) + 1 > ReportesBasicosController::DIAS_TOPE) {
            abort(response()->json([
                'message' => 'El periodo máximo por reporte es de ' . ReportesBasicosController::DIAS_TOPE . ' días. Acota las fechas.',
            ], 422));
        }

        return [$desde, $hasta];
    }

    /**
     * Directorio users.id => [nombre, puesto, mealMinutes]. El join canónico es por las DOS
     * llaves (user_id + tenant_id) y cae a `users.name` para los mandos sin expediente —
     * confundir employees.id con users.id es la familia de defectos §29/§30 de este proyecto.
     */
    private function nombresPorUsuario(int $tenantId): array
    {
        return DB::table('users')
            ->leftJoin('employees', function ($j) {
                $j->on('employees.user_id', '=', 'users.id')
                    ->on('employees.tenant_id', '=', 'users.tenant_id');
            })
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('users.tenant_id', $tenantId)
            ->get(['users.id', 'users.name as nombre_cuenta', 'employees.name as nombre_expediente',
                   'employees.mealMinutes', 'job_roles.name as puesto'])
            ->keyBy('id')
            ->map(fn ($u) => [
                'nombre' => $u->nombre_expediente ?: $u->nombre_cuenta,
                'puesto' => $u->puesto ?: 'Sin puesto',
                'mealMinutes' => $u->mealMinutes,
            ])
            ->all();
    }

    /**
     * ponytail: tope de renglones del PDF. dompdf arma el documento entero en memoria, y un
     * reporte a 92 días son miles de filas: sin tope, "Descargar PDF" tumba al servidor.
     * Arriba de esto el formato correcto es el CSV, y el propio documento lo dice en su
     * última página en vez de recortar callado. Subirlo si a alguien le hace falta.
     */
    private const PDF_TOPE_FILAS = 2000;

    /**
     * La descarga: CSV (predeterminado, es lo que Excel abre) o PDF con `?formato=pdf`.
     *
     * Los dos salen de las MISMAS `$filas`: el PDF no vuelve a consultar nada. En un proyecto
     * cuya familia de defectos es "dos cifras para el mismo dato", un documento con aire de
     * oficial —el que acaba en manos de un inspector— es el peor lugar donde dejar que eso
     * vuelva a pasar.
     */
    private function csv(string $nombre, array $encabezados, array $filas, array $notas = [])
    {
        if (request()->query('formato') === 'pdf') {
            return $this->pdf($nombre, $encabezados, $filas, $notas);
        }

        return response()->streamDownload(function () use ($encabezados, $filas, $notas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados);
            foreach ($filas as $fila) {
                fputcsv($salida, array_map([$this, 'celdaSegura'], $fila));
            }
            if ($notas) {
                fputcsv($salida, []);
                fputcsv($salida, ['Cómo leer este reporte']);
                foreach ($notas as $n) {
                    fputcsv($salida, [$this->celdaSegura($n)]);
                }
            }
            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * El mismo reporte, en documento: para entregar, imprimir o archivar (una inspección, un
     * expediente que se firma). Una sola plantilla para los 15 — el ancho y la orientación
     * salen del número de columnas, así que un reporte nuevo no necesita diseño propio.
     */
    private function pdf(string $nombre, array $encabezados, array $filas, array $notas)
    {
        // El id sale de la RUTA (`/admin/reports/<id>.csv`), no del nombre del archivo: cuatro
        // reportes se descargan con un nombre más largo que su id ("retardos_y_faltas" para
        // `retardos`), así que partir el nombre daría títulos equivocados justo en esos.
        $id = basename(request()->path(), '.csv');
        $titulo = CatalogoDeReportes::REPORTES[$id]['titulo'] ?? ucfirst(str_replace('_', ' ', $id));

        $total = count($filas);
        $recortado = $total > self::PDF_TOPE_FILAS;

        // ponytail: el periodo sube al encabezado tomándolo de su nota. Impreso, un documento
        // sin periodo no sirve de evidencia, y el rango no llega hasta aquí. Si la nota no
        // empieza así (Expediente Documental es foto de hoy, no periodo), no se pinta la línea
        // y el rango sigue en las notas y en el nombre del archivo — nunca se pierde.
        $periodo = ($notas && str_starts_with($notas[0], 'Periodo del')) ? rtrim($notas[0], '.') : null;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.generico', [
            'titulo' => $titulo,
            'empresa' => DB::table('tenants')->where('id', request()->user()->tenant_id)->value('name') ?: 'Talent 360',
            'generadoPor' => request()->user()->name,
            'generadoEl' => Carbon::now(TenantTimezone::for((int) request()->user()->tenant_id))->format('d/m/Y H:i'),
            'periodo' => $periodo,
            'encabezados' => $encabezados,
            'filas' => $recortado ? array_slice($filas, 0, self::PDF_TOPE_FILAS) : $filas,
            'notas' => $notas,
            'total' => $total,
            'recortado' => $recortado,
            'tope' => self::PDF_TOPE_FILAS,
        ])->setPaper('letter', count($encabezados) > 6 ? 'landscape' : 'portrait');

        return $pdf->download(str_replace('.csv', '.pdf', $nombre));
    }

    /**
     * Mitigación OWASP de inyección de fórmulas: un colaborador controla su propio nombre, y
     * `=HYPERLINK(...)` se ejecutaría al abrir el CSV en el Excel del admin.
     */
    private function celdaSegura($celda)
    {
        if (is_string($celda) && $celda !== '' && in_array($celda[0], ['=', '+', '-', '@'], true)) {
            return "'" . $celda;
        }

        return $celda;
    }

    private function pct(int $parte, int $total): string
    {
        return $total > 0 ? round($parte * 100 / $total) . '%' : '—';
    }

    private function hhmm(int $minutos): string
    {
        if ($minutos <= 0) {
            return '0:00';
        }

        return intdiv($minutos, 60) . ':' . str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }
}
