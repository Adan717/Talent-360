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
    /**
     * El rango que se entregó, para que el encabezado del PDF lo pueda imprimir. Queda vacío
     * en el reporte que no es de periodo (Expediente Documental es una foto de hoy), y ahí el
     * encabezado simplemente no lleva la línea.
     */
    private array $rangoEntregado = [];

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

        return $this->rangoEntregado = [$desde, $hasta];
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
     * La descarga, en los tres formatos: CSV (predeterminado), `?formato=xlsx` y `?formato=pdf`.
     *
     * Los tres salen de las MISMAS `$filas`: ni el Excel ni el PDF vuelven a consultar nada. En
     * un proyecto cuya familia de defectos es "dos cifras para el mismo dato", el documento que
     * acaba en manos de un inspector es el peor lugar donde dejar que eso vuelva a pasar.
     *
     * `$resumen` es el bloque de otra forma que algunos reportes llevan al final (el resumen
     * por vacante, los totales por periodo, el conteo de plantilla). En el CSV va debajo, que
     * es lo único que un CSV permite; en el Excel y en el PDF va aparte — pegado a la tabla
     * estorba, porque al ordenar o filtrar se revuelve con los datos.
     */
    private function csv(string $nombre, array $encabezados, array $filas, array $notas = [], ?array $resumen = null)
    {
        $formato = request()->query('formato');

        if ($formato === 'pdf') {
            return $this->pdf($nombre, $encabezados, $filas, $notas, $resumen);
        }

        if ($formato === 'xlsx') {
            return $this->xlsx($nombre, $encabezados, $filas, $notas, $resumen);
        }

        return response()->streamDownload(function () use ($encabezados, $filas, $notas, $resumen) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados);
            foreach ($filas as $fila) {
                fputcsv($salida, array_map([$this, 'celdaSegura'], $fila));
            }
            if ($resumen && $resumen['filas']) {
                fputcsv($salida, []);
                fputcsv($salida, [$resumen['titulo']]);
                fputcsv($salida, $resumen['encabezados']);
                foreach ($resumen['filas'] as $fila) {
                    fputcsv($salida, array_map([$this, 'celdaSegura'], $fila));
                }
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
     * El mismo reporte como libro de Excel de verdad: números que son números, fechas que son
     * fechas, encabezado congelado, filtros puestos, y el resumen y las notas en sus hojas.
     *
     * El detalle de por qué importa cada cosa está en `LibroDeReporte`.
     */
    private function xlsx(string $nombre, array $encabezados, array $filas, array $notas, ?array $resumen)
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Support\LibroDeReporte($this->tituloDelReporte(), $encabezados, $filas, $notas, $resumen),
            str_replace('.csv', '.xlsx', $nombre)
        );
    }

    /**
     * El título del catálogo. El id sale de la RUTA (`/admin/reports/<id>.csv`), no del nombre
     * del archivo: cuatro reportes se descargan con un nombre más largo que su id
     * ("retardos_y_faltas" para `retardos`), así que partir el nombre daría títulos equivocados
     * justo en esos.
     */
    private function tituloDelReporte(): string
    {
        $id = basename(request()->path(), '.csv');

        return CatalogoDeReportes::REPORTES[$id]['titulo'] ?? ucfirst(str_replace('_', ' ', $id));
    }

    /**
     * El mismo reporte, en documento: para entregar, imprimir o archivar (una inspección, un
     * expediente que se firma). Una sola plantilla para los 15 — el ancho y la orientación
     * salen del número de columnas, así que un reporte nuevo no necesita diseño propio.
     */
    private function pdf(string $nombre, array $encabezados, array $filas, array $notas, ?array $resumen)
    {
        $titulo = $this->tituloDelReporte();
        $total = count($filas);
        $recortado = $total > self::PDF_TOPE_FILAS;

        // El periodo va en el encabezado: impreso, un documento sin su rango no sirve de
        // evidencia. Sale del rango que este mismo trait validó, no de leerle la primera nota
        // al reporte — Rotación abre con "Altas contadas entre…" y se quedaba sin fecha arriba.
        $periodo = $this->rangoEntregado
            ? 'Periodo del ' . $this->rangoEntregado[0] . ' al ' . $this->rangoEntregado[1]
            : null;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.generico', [
            'titulo' => $titulo,
            'empresa' => DB::table('tenants')->where('id', request()->user()->tenant_id)->value('name') ?: 'Talent 360',
            'generadoPor' => request()->user()->name,
            'generadoEl' => Carbon::now(TenantTimezone::for((int) request()->user()->tenant_id))->format('d/m/Y H:i'),
            'periodo' => $periodo,
            'encabezados' => array_map([$this, 'sinSimbolosRaros'], $encabezados),
            'filas' => array_map(
                fn ($f) => array_map([$this, 'sinSimbolosRaros'], $f),
                $recortado ? array_slice($filas, 0, self::PDF_TOPE_FILAS) : $filas
            ),
            'notas' => array_map([$this, 'sinSimbolosRaros'], $notas),
            'resumen' => ($resumen && $resumen['filas']) ? [
                'titulo' => $this->sinSimbolosRaros($resumen['titulo']),
                'encabezados' => array_map([$this, 'sinSimbolosRaros'], $resumen['encabezados']),
                'filas' => array_map(fn ($f) => array_map([$this, 'sinSimbolosRaros'], $f), $resumen['filas']),
            ] : null,
            'total' => $total,
            'recortado' => $recortado,
            'tope' => self::PDF_TOPE_FILAS,
        ])->setPaper('letter', count($encabezados) > 6 ? 'landscape' : 'portrait');

        return $pdf->download(str_replace('.csv', '.pdf', $nombre));
    }

    /**
     * SÓLO para el PDF: las fuentes base de dompdf son WinAnsi, y todo lo que quede fuera de
     * ese juego se imprime como "?" — la nota "Directorio Digital → Laboral" salía
     * "Directorio Digital ? Laboral". El CSV no lo necesita: ahí el UTF-8 se ve bien.
     *
     * Si aparece otro símbolo, se agrega aquí: la prueba que recorre los 15 en PDF falla
     * cuando un carácter se convierte en un "?" suelto, así que no se cuela en silencio.
     */
    private function sinSimbolosRaros($valor)
    {
        return is_string($valor)
            ? strtr($valor, [
                '→' => '>', '←' => '<', '⚠' => '!', '≥' => '>=', '≤' => '<=', '…' => '...',
                // El signo MENOS de verdad (U+2212), no el guion: se cuela al escribir fórmulas
                // ("horas en sucursal − comida") y es indistinguible a simple vista del guion.
                '−' => '-', '×' => 'x', '÷' => '/', '≈' => '~',
            ])
            : $valor;
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
