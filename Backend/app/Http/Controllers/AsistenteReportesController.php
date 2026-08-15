<?php

namespace App\Http\Controllers;

use App\Helpers\TenantTimezone;
use App\Services\OpenAiReportIntentParser;
use App\Services\PayrollWeekService;
use App\Services\ReportIntentParser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bloque 6 (2026-08-13): asistente de reportes por frase.
 *
 * Diseño acordado en el plan, al pie de la letra:
 *  - El parser (OpenAI, strict) devuelve INTENCIÓN; las fechas las resuelve ESTE servidor
 *    con el código que ya usa la pantalla (PayrollWeekService + zona horaria del tenant).
 *  - Autocompletador: llena el formulario y el humano confirma. NUNCA descarga directo.
 *  - No hay segunda puerta: la descarga sale por los endpoints de reportes que ya existen
 *    y ya autorizan. Este controlador no entrega ni un dato de asistencia.
 *  - Nómina NO: espera la fase A del bloque 4 (el neto vivo es ambiguo y ya mordió).
 *  - Cada frase se registra (frase + intención + quién) con la retención del bloque 2.
 */
class AsistenteReportesController extends Controller
{
    /**
     * Máximo de días por reporte: el candado contra "todos los datos desde 1990". El dueño
     * del tope es la puerta de DESCARGA (ReportesBasicosController) — aquí solo se espeja
     * para rechazar antes con un mensaje mejor.
     */
    private const DIAS_TOPE = ReportesBasicosController::DIAS_TOPE;

    /** Los reportes que el asistente sabe llenar; cada uno tiene su descarga ya autorizada. */
    private const REPORTES = ['asistencia', 'tareas', 'retardos', 'horas', 'rutinas'];

    public function estado()
    {
        return response()->json(['disponible' => OpenAiReportIntentParser::disponible()]);
    }

    public function interpretar(Request $request, ReportIntentParser $parser, PayrollWeekService $semanas)
    {
        $request->validate(['frase' => 'required|string|max:300']);

        if (!OpenAiReportIntentParser::disponible()) {
            return response()->json([
                'code' => 'sin_llave',
                'message' => 'El asistente no está configurado en esta instancia.',
            ], 503);
        }

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $frase = trim($request->input('frase'));

        try {
            // El modelo NO sabe qué día es hoy: sin decírselo interpretaba "de julio" como el
            // julio de su entrenamiento (se vio en vivo: 2023-07). La fecha va en la zona del
            // inquilino, la misma con la que se resuelve el periodo abajo.
            $intent = $parser->parse($frase, Carbon::now(TenantTimezone::for($tenantId))->toDateString());
        } catch (\Throwable $e) {
            $this->registrar($tenantId, $user->id, $frase, null, false, substr($e->getMessage(), 0, 250));
            return response()->json([
                'code' => 'no_interpretada',
                'message' => 'No se pudo interpretar la frase en este momento. Usa los botones de siempre.',
            ], 502);
        }

        // La frontera de confianza es ésta, no el proveedor: se revalida TODO.
        $reporte = $intent['reporte'] ?? null;
        if ($reporte === 'no_soportado' || !in_array($reporte, self::REPORTES, true)) {
            $motivo = trim((string) ($intent['motivo_rechazo'] ?? '')) ?: 'Eso no es uno de los reportes disponibles (asistencia o tareas).';
            $this->registrar($tenantId, $user->id, $frase, $intent, true, 'rechazada: ' . substr($motivo, 0, 200));
            return response()->json(['code' => 'no_soportado', 'message' => $motivo], 422);
        }

        try {
            [$desde, $hasta, $etiqueta] = $this->resolverPeriodo($tenantId, $intent['periodo'] ?? [], $semanas);
        } catch (\InvalidArgumentException $e) {
            $this->registrar($tenantId, $user->id, $frase, $intent, true, 'periodo inválido: ' . $e->getMessage());
            return response()->json(['code' => 'periodo_invalido', 'message' => $e->getMessage()], 422);
        }

        $this->registrar($tenantId, $user->id, $frase, $intent, true, null);

        // SOLO el formulario lleno. La descarga la confirma el humano por la puerta de siempre.
        return response()->json([
            'reporte' => $reporte,
            'desde' => $desde,
            'hasta' => $hasta,
            'etiqueta' => $etiqueta,
        ]);
    }

    /**
     * INTENCIÓN → [desde, hasta, etiqueta], en la zona horaria del tenant y con SU semana
     * (PayrollWeekService: el mismo código que decide la semana de nómina de la pantalla).
     */
    private function resolverPeriodo(int $tenantId, array $periodo, PayrollWeekService $semanas): array
    {
        $tz = TenantTimezone::for($tenantId);
        $hoy = Carbon::now($tz)->startOfDay();

        [$desde, $hasta, $etiqueta] = $this->resolverCrudo($tenantId, $periodo, $semanas, $tz, $hoy);

        // Recorte a futuro UNIFORME (ronda adversarial: solo lo tenía rango_absoluto, y
        // "la semana 40" pedida en agosto devolvía una semana entera de octubre, vacía).
        if (Carbon::parse($desde, $tz)->gt($hoy)) {
            throw new \InvalidArgumentException('Ese periodo aún no ocurre: no hay asistencia del futuro.');
        }
        if (Carbon::parse($hasta, $tz)->gt($hoy)) {
            $hasta = $hoy->toDateString();
        }

        return [$desde, $hasta, $etiqueta];
    }

    private function resolverCrudo(int $tenantId, array $periodo, PayrollWeekService $semanas, string $tz, Carbon $hoy): array
    {
        $tipo = $periodo['tipo'] ?? 'hoy';

        switch ($tipo) {
            case 'hoy':
                return [$hoy->toDateString(), $hoy->toDateString(), 'hoy'];
            case 'ayer':
                $ayer = $hoy->copy()->subDay();
                return [$ayer->toDateString(), $ayer->toDateString(), 'ayer'];
            case 'dias_atras':
                $n = (int) ($periodo['dias'] ?? 0);
                if ($n < 1 || $n > self::DIAS_TOPE) {
                    throw new \InvalidArgumentException('El periodo máximo por reporte es de ' . self::DIAS_TOPE . ' días.');
                }
                return [$hoy->copy()->subDays($n - 1)->toDateString(), $hoy->toDateString(), "últimos {$n} días"];
            case 'semana_actual':
                [$ini] = $semanas->weekRangeFor($tenantId, $hoy);
                return [$ini->toDateString(), $hoy->toDateString(), 'la semana en curso'];
            case 'semana_pasada':
                [$ini, $fin] = $semanas->weekRangeFor($tenantId, $hoy->copy()->subDays(7));
                return [$ini->toDateString(), $fin->toDateString(), 'la semana pasada'];
            case 'semana_numero':
                $n = (int) ($periodo['numero'] ?? 0);
                if ($n < 1 || $n > 53) {
                    throw new \InvalidArgumentException('Una semana del año va del 1 al 53.');
                }
                // Ancla: 1 de enero + (N-1) semanas, encajada en la semana CONFIGURADA del tenant.
                $ancla = Carbon::create($hoy->year, 1, 1, 0, 0, 0, $tz)->addWeeks($n - 1);
                [$ini, $fin] = $semanas->weekRangeFor($tenantId, $ancla);
                return [$ini->toDateString(), $fin->toDateString(), "la semana {$n} de {$hoy->year}"];
            case 'mes_actual':
                return [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString(), 'el mes en curso'];
            case 'mes_pasado':
                $mes = $hoy->copy()->subMonthNoOverflow();
                return [$mes->copy()->startOfMonth()->toDateString(), $mes->copy()->endOfMonth()->toDateString(), 'el mes pasado'];
            case 'rango_absoluto':
                try {
                    $desde = Carbon::createFromFormat('Y-m-d', (string) ($periodo['desde'] ?? ''), $tz)->startOfDay();
                    $hasta = Carbon::createFromFormat('Y-m-d', (string) ($periodo['hasta'] ?? ''), $tz)->startOfDay();
                } catch (\Throwable $e) {
                    throw new \InvalidArgumentException('No entendí las fechas. Dilas como "del 1 al 15 de julio" o usa el formato AAAA-MM-DD.');
                }
                if ($hasta->lt($desde)) {
                    [$desde, $hasta] = [$hasta, $desde];
                }
                if ($desde->diffInDays($hasta) + 1 > self::DIAS_TOPE) {
                    throw new \InvalidArgumentException('El periodo máximo por reporte es de ' . self::DIAS_TOPE . ' días. Acota las fechas.');
                }
                return [$desde->toDateString(), $hasta->toDateString(), 'del ' . $desde->toDateString() . ' al ' . $hasta->toDateString()];
        }

        throw new \InvalidArgumentException('No entendí el periodo de la frase.');
    }

    /** El log hereda la retención del bloque 2 (la purga vive en chat:clean-old-messages). */
    private function registrar(int $tenantId, int $userId, string $frase, ?array $intent, bool $exito, ?string $nota): void
    {
        DB::table('report_intent_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'frase' => $frase,
            'intent' => $intent === null ? null : json_encode($intent, JSON_UNESCAPED_UNICODE),
            'exito' => $exito,
            'nota' => $nota,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
