{{--
    La plantilla ÚNICA de los 15 reportes en PDF.

    No hay una por reporte a propósito: el ancho y la orientación salen del número de columnas,
    así que un reporte nuevo hereda su documento sin diseñarle nada. Y, sobre todo, las filas
    que llegan aquí son literalmente las mismas que escribe el CSV — el PDF no consulta nada
    por su cuenta, así que no puede decir una cifra distinta a la del Excel del mismo día.
--}}
@php
    // El ancho manda sobre el tamaño de letra, pero con SUELO. Ojo con la unidad: dompdf
    // imprime 1px = 0.75pt, así que "9px" son 6.75 PUNTOS en el papel — la letra chica de un
    // contrato. Por debajo de eso el documento deja de poder leerse impreso, que es justo para
    // lo que existe. Antes que achicar más, la tabla parte palabras y crece a lo alto: un
    // renglón de dos líneas se lee, uno de 5 puntos no. En horizontal caben 729pt de ancho, así
    // que 12 columnas salen a ~61pt cada una y a 7pt de letra entran unos 17 caracteres.
    $cols = count($encabezados);
    $fuente = $cols > 11 ? 9.5 : ($cols > 8 ? 10 : ($cols > 6 ? 10.5 : 12));

    // El periodo ya subió al encabezado: repetirlo abajo es ruido en un documento impreso.
    $notasAlPie = ($periodo && $notas && str_starts_with($notas[0], 'Periodo del'))
        ? array_slice($notas, 1)
        : $notas;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 1.4cm 1.1cm 1.6cm 1.1cm; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: {{ $fuente }}px; margin: 0; }
        .enc { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 12px; }
        .enc h1 { font-size: {{ $fuente + 8 }}px; margin: 0 0 2px 0; color: #111827; }
        .enc .empresa { font-size: {{ $fuente + 2 }}px; color: #374151; margin: 0; font-weight: bold; }
        .enc .meta { font-size: {{ $fuente }}px; color: #6b7280; margin: 3px 0 0 0; }
        table.datos { width: 100%; border-collapse: collapse; }
        table.datos th { background-color: #f3f4f6; color: #374151; text-align: left; padding: 4px 5px; border-bottom: 1px solid #d1d5db; font-weight: bold; }
        table.datos td { padding: 3px 5px; border-bottom: 1px solid #f3f4f6; color: #4b5563; }
        table.datos tr:nth-child(even) td { background-color: #fafafa; }
        .vacio { padding: 20px; text-align: center; color: #6b7280; font-style: italic; }
        .aviso { margin-top: 12px; padding: 8px 10px; background-color: #fef3c7; border: 1px solid #f59e0b; color: #92400e; font-weight: bold; }
        .notas { margin-top: 16px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #6b7280; }
        .notas h2 { font-size: {{ $fuente + 1 }}px; color: #374151; margin: 0 0 4px 0; }
        .notas li { margin-bottom: 3px; line-height: 1.35; }
        .pie { position: fixed; bottom: -1cm; left: 0; right: 0; font-size: {{ $fuente - 1 }}px; color: #9ca3af; }
        /* Un documento archivado sin folio de página no se puede citar.
           ponytail: sólo el número, sin "de N": dompdf no conoce el total al pintar un elemento
           fijo y `counter(pages)` sale 0 — el pie decía "pág. 1 de 0", que miente. Para saber si
           falta una hoja está el conteo de renglones del encabezado. Si algún día hace falta el
           "de N", se hace con `getCanvas()->page_text()` y su marcador {PAGE_COUNT}. */
        .pie .pagina:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="pie">
        {{ $empresa }} · {{ $titulo }} · generado el {{ $generadoEl }} — Talent 360 · pág. <span class="pagina"></span>
    </div>

    <div class="enc">
        <h1>{{ $titulo }}</h1>
        <p class="empresa">{{ $empresa }}</p>
        <p class="meta">
            @if ($periodo){{ $periodo }} · @endif{{ number_format($total) }} {{ $total === 1 ? 'renglón' : 'renglones' }}
            · generado por {{ $generadoPor }} el {{ $generadoEl }}
        </p>
    </div>

    @if ($filas)
        <table class="datos">
            {{-- dompdf repite el <thead> en cada página: sin esto, de la hoja 2 en adelante
                 el lector no sabe qué columna es cuál. --}}
            <thead>
                <tr>@foreach ($encabezados as $e)<th>{{ $e }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @foreach ($filas as $fila)
                    <tr>@foreach ($fila as $celda)<td>{{ $celda }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    @else
        {{-- Una hoja en blanco se lee como "falla"; esto dice que sí corrió y no hubo nada. --}}
        <p class="vacio">No hubo registros en este periodo.</p>
    @endif

    @if ($recortado)
        <div class="aviso">
            Este documento muestra los primeros {{ number_format($tope) }} renglones de
            {{ number_format($total) }}. El PDF está pensado para entregar o archivar, no para
            analizar: para verlos todos acota las fechas o descarga el mismo reporte en CSV.
        </div>
    @endif

    @if ($notasAlPie)
        <div class="notas">
            <h2>Cómo leer este reporte</h2>
            <ul>
                @foreach ($notasAlPie as $n)<li>{{ $n }}</li>@endforeach
            </ul>
        </div>
    @endif
</body>
</html>
