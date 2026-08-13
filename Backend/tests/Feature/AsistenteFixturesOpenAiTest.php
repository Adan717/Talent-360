<?php

namespace Tests\Feature;

use App\Services\OpenAiReportIntentParser;
use Tests\TestCase;

/**
 * Bloque 6: las ~40 frases del fixture contra OpenAI DE VERDAD (incluidas las adversarias).
 *
 * Es el criterio de selección de proveedor y lo que convierte un retiro/degradación de
 * modelo en `php artisan test`. OPT-IN porque llama a la API real (cuesta dinero y necesita
 * red): corre con
 *
 *     RUN_OPENAI_FIXTURES=1 OPENAI_API_KEY=... php artisan test --filter=AsistenteFixturesOpenAi
 *
 * La suite normal lo salta. El umbral es 90%: un modelo puede fallar una frase ambigua sin
 * romper el build, pero una degradación real truena aquí antes que con un cliente.
 */
class AsistenteFixturesOpenAiTest extends TestCase
{
    public function test_las_frases_del_fixture_se_interpretan_como_se_espera(): void
    {
        if (env('RUN_OPENAI_FIXTURES') !== '1') {
            $this->markTestSkipped('Opt-in: RUN_OPENAI_FIXTURES=1 (llama a la API real de OpenAI).');
        }
        if (!OpenAiReportIntentParser::disponible()) {
            $this->markTestSkipped('Sin OPENAI_API_KEY configurada.');
        }

        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/asistente_frases.json')), true);
        $parser = new OpenAiReportIntentParser();

        $fallas = [];
        foreach ($fixture['frases'] as $caso) {
            try {
                $intent = $parser->parse($caso['frase']);
            } catch (\Throwable $e) {
                $fallas[] = "«{$caso['frase']}» → EXCEPCIÓN: {$e->getMessage()}";
                continue;
            }

            $reporte = $intent['reporte'] ?? '(nada)';
            if (!in_array($reporte, $caso['reportes'], true)) {
                $fallas[] = "«{$caso['frase']}» → reporte '{$reporte}', se esperaba: " . implode('|', $caso['reportes']);
                continue;
            }

            if ($reporte !== 'no_soportado' && $caso['tipos'] !== []
                && !in_array($intent['periodo']['tipo'] ?? '(nada)', $caso['tipos'], true)) {
                $fallas[] = "«{$caso['frase']}» → periodo '{$intent['periodo']['tipo']}', se esperaba: " . implode('|', $caso['tipos']);
            }
        }

        $total = count($fixture['frases']);
        $tasa = 1 - count($fallas) / max(1, $total);

        $this->assertGreaterThanOrEqual(
            0.9,
            $tasa,
            sprintf("El modelo falló %d de %d frases del fixture:\n%s", count($fallas), $total, implode("\n", $fallas))
        );
    }
}
