<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeminiAIService
 * Integración con la API de Google Gemini para:
 *   - parseVoiceTask: Interpretar comandos de voz del supervisor → tarea estructurada
 *   - analyzeCandidate: Analizar perfil de candidato vs requisitos del puesto
 *   - generateExam: Generar examen interactivo a partir de documentos Markdown
 *   - suggestOptimalAssignee: Sugerir colaborador óptimo según carga de trabajo
 */
class GeminiAIService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('services.gemini.api_key', env('GEMINI_API_KEY', ''));
        $this->model   = config('services.gemini.model', 'gemini-2.0-flash');
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';
    }

    // =========================================================
    // MÉTODO PRIVADO: Llamada HTTP a la API de Gemini
    // =========================================================

    /**
     * Envía un prompt a Gemini y devuelve el texto de respuesta.
     *
     * @throws \Exception si la API key no está configurada o hay error HTTP
     */
    private function callGemini(string $prompt, float $temperature = 0.3, int $maxOutputTokens = 1024): string
    {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GEMINI_API_KEY') {
            throw new \Exception('GEMINI_API_KEY no configurada. Añade tu clave en el archivo .env: GEMINI_API_KEY=tu_clave_aqui');
        }

        $endpoint = $this->baseUrl . $this->model . ':generateContent?key=' . $this->apiKey;

        $response = Http::timeout(30)
            ->retry(2, 1000)
            ->post($endpoint, [
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [['text' => $prompt]],
                    ]
                ],
                'generationConfig' => [
                    'temperature'     => $temperature,
                    'maxOutputTokens' => $maxOutputTokens,
                    'responseMimeType' => 'application/json',
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Gemini API error', [
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            throw new \Exception('Error al contactar Gemini API: HTTP ' . $response->status());
        }

        $body = $response->json();

        return $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    // =========================================================
    // 1. PARSEO DE TAREA POR VOZ
    // =========================================================

    /**
     * Interpreta un comando de voz del supervisor y devuelve una tarea estructurada.
     *
     * @param string $voiceText  Texto transcrito del audio del supervisor
     * @param array  $employees  Lista de empleados disponibles en el turno [{id, name, job_role}]
     * @param array  $taskCatalog Catálogo de tareas existentes [{id, title}]
     * @return array  Estructura: {title, estimated_mins, priority, target_user_id, assistant_type, ...}
     */
    public function parseVoiceTask(string $voiceText, array $employees = [], array $taskCatalog = []): array
    {
        $employeeList  = empty($employees)   ? 'Ningún empleado disponible.' : json_encode($employees, JSON_UNESCAPED_UNICODE);
        $catalogList   = empty($taskCatalog) ? 'Sin catálogo previo.'        : json_encode(array_slice($taskCatalog, 0, 20), JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Eres el asistente de IA operativo de la plataforma Talent360. El supervisor ha dictado la siguiente instrucción por voz:

"$voiceText"

Empleados en turno activo: $employeeList

Catálogo de tareas existentes (usa el id si ya existe una igual): $catalogList

Extrae y responde ÚNICAMENTE con un JSON con esta estructura exacta:
{
  "title": "Título breve de la tarea (máx 80 caracteres)",
  "estimated_mins": 15,
  "priority": "regular",
  "category": "operaciones",
  "target_user_id": null,
  "target_user_name": null,
  "matched_catalog_task_id": null,
  "assistant_type": "texto",
  "assistant_prompt": "Descripción o instrucción adicional para el colaborador",
  "time_detected": null,
  "confidence": 0.9
}

Reglas:
- "priority" solo puede ser: "regular" o "bloqueante"
- "assistant_type" solo puede ser: "ninguno", "evidencia_foto", "captura_numero", "texto"
- "target_user_id" es el id del empleado mencionado o al que mejor le corresponde la tarea. null si no se detecta.
- "time_detected" es la hora en formato "HH:MM" si se menciona una hora específica. null si no.
- "confidence" es tu nivel de certeza del parseo (0.0 a 1.0)
- Si se menciona fotografía, limpieza o evidencia visual → assistant_type: "evidencia_foto"
- Si se menciona conteo, cantidad, inventario → assistant_type: "captura_numero"
- Si se pide reporte o descripción → assistant_type: "texto"
- Responde SOLO el JSON, sin explicaciones adicionales.
PROMPT;

        try {
            $raw  = $this->callGemini($prompt, 0.2, 512);
            $data = json_decode($this->extractJson($raw), true);

            if (!$data || !isset($data['title'])) {
                throw new \Exception('Respuesta inválida de Gemini');
            }

            return $data;
        } catch (\Exception $e) {
            Log::warning('GeminiAIService::parseVoiceTask fallback', ['error' => $e->getMessage()]);

            // Fallback graceful: parseo básico sin IA
            return [
                'title'                  => $this->extractTitle($voiceText),
                'estimated_mins'         => 15,
                'priority'               => 'regular',
                'category'               => 'operaciones',
                'target_user_id'         => null,
                'target_user_name'       => null,
                'matched_catalog_task_id'=> null,
                'assistant_type'         => 'texto',
                'assistant_prompt'       => $voiceText,
                'time_detected'          => null,
                'confidence'             => 0.3,
            ];
        }
    }

    // =========================================================
    // 2. ANÁLISIS DE PERFIL DE CANDIDATO
    // =========================================================

    /**
     * Analiza si un candidato cumple con los requisitos de un puesto.
     *
     * @param array $candidate  Datos del candidato {name, experience, skills, education, ...}
     * @param array $vacancy    Datos de la vacante {title, requirements, salary, ...}
     * @return array  {score, recommendation, strengths, gaps, alternative_roles}
     */
    public function analyzeCandidate(array $candidate, array $vacancy): array
    {
        $candidateJson = json_encode($candidate, JSON_UNESCAPED_UNICODE);
        $vacancyJson   = json_encode($vacancy,   JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Eres un experto en selección de personal para empresas mexicanas de retail y operaciones. Analiza si el siguiente candidato es adecuado para la vacante.

CANDIDATO: $candidateJson

VACANTE: $vacancyJson

Responde ÚNICAMENTE con un JSON con esta estructura:
{
  "score": 82,
  "recommendation": "Apto",
  "summary": "Resumen ejecutivo del candidato (2 oraciones)",
  "strengths": ["Fortaleza 1", "Fortaleza 2"],
  "gaps": ["Brecha 1 (qué le falta)"],
  "alternative_roles": ["Puesto alternativo si no califica completamente"],
  "interview_questions": ["Pregunta específica 1 para la entrevista", "Pregunta 2"],
  "risk_flags": []
}

- "score": 0-100 (qué tan bien encaja)
- "recommendation": "Apto" | "Apto con reservas" | "No Apto" | "Considerar para otro puesto"
- "risk_flags": aspectos de riesgo detectados (lagunas laborales, poca estabilidad, etc.)
PROMPT;

        try {
            $raw  = $this->callGemini($prompt, 0.4, 768);
            $data = json_decode($this->extractJson($raw), true);

            if (!$data || !isset($data['score'])) {
                throw new \Exception('Respuesta inválida');
            }

            return $data;
        } catch (\Exception $e) {
            Log::warning('GeminiAIService::analyzeCandidate error', ['error' => $e->getMessage()]);

            return [
                'score'               => 50,
                'recommendation'      => 'Revisión manual requerida',
                'summary'             => 'No se pudo analizar automáticamente. Se requiere revisión manual.',
                'strengths'           => [],
                'gaps'                => [],
                'alternative_roles'   => [],
                'interview_questions' => [],
                'risk_flags'          => ['Análisis de IA no disponible'],
            ];
        }
    }

    // =========================================================
    // 3. GENERACIÓN DE EXAMEN DESDE MARKDOWN
    // =========================================================

    /**
     * Genera un examen interactivo con preguntas de opción múltiple a partir de contenido Markdown.
     *
     * @param string $markdownContent  Contenido del documento SOP/Manual
     * @param string $documentTitle    Título del documento
     * @param int    $numQuestions     Número de preguntas a generar (default: 5)
     * @param string $difficulty       Dificultad: "basico" | "intermedio" | "avanzado"
     * @return array  Arreglo de preguntas con opciones y respuesta correcta
     */
    public function generateExam(string $markdownContent, string $documentTitle, int $numQuestions = 5, string $difficulty = 'basico'): array
    {
        // Truncar contenido largo para respetar límites de tokens
        $truncatedContent = mb_strlen($markdownContent) > 6000
            ? mb_substr($markdownContent, 0, 6000) . "\n\n[Contenido truncado para el análisis]"
            : $markdownContent;

        $prompt = <<<PROMPT
Eres un experto en diseño instruccional para capacitación empresarial en México. Basándote en el siguiente documento, genera exactamente $numQuestions preguntas de opción múltiple de dificultad $difficulty.

DOCUMENTO: "$documentTitle"

CONTENIDO:
$truncatedContent

Responde ÚNICAMENTE con un JSON con esta estructura:
{
  "questions": [
    {
      "id": 1,
      "question": "Texto de la pregunta",
      "options": ["Opción A", "Opción B", "Opción C", "Opción D"],
      "correct_index": 0,
      "explanation": "Por qué es correcta esta respuesta (referencia al documento)"
    }
  ],
  "estimated_minutes": 10,
  "passing_score": 70
}

Reglas:
- Cada pregunta debe tener exactamente 4 opciones
- "correct_index" es el índice (0-3) de la opción correcta
- Las preguntas deben basarse ESTRICTAMENTE en el contenido del documento
- Nivel basico: preguntas directas de comprensión
- Nivel intermedio: preguntas de aplicación
- Nivel avanzado: preguntas de análisis y procedimientos
- "passing_score" es el porcentaje mínimo para acreditar (entre 60 y 80)
PROMPT;

        try {
            $raw  = $this->callGemini($prompt, 0.3, 2048);
            $data = json_decode($this->extractJson($raw), true);

            if (!$data || !isset($data['questions']) || empty($data['questions'])) {
                throw new \Exception('Respuesta inválida de Gemini para examen');
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('GeminiAIService::generateExam error', ['error' => $e->getMessage()]);
            throw $e; // Este error SÍ debe propagarse al controlador
        }
    }

    // =========================================================
    // 4. SUGERENCIA DE ASIGNACIÓN ÓPTIMA
    // =========================================================

    /**
     * Sugiere el colaborador óptimo del turno para una tarea basándose en carga de trabajo.
     *
     * @param string $taskDescription  Descripción de la tarea a asignar
     * @param array  $employees        [{id, name, job_role, active_tasks_count, completed_today, clock_state}]
     * @return array  {suggested_user_id, suggested_user_name, reason, confidence}
     */
    public function suggestOptimalAssignee(string $taskDescription, array $employees): array
    {
        if (empty($employees)) {
            return [
                'suggested_user_id'   => null,
                'suggested_user_name' => null,
                'reason'              => 'No hay empleados disponibles en el turno.',
                'confidence'          => 0.0,
            ];
        }

        $employeeJson = json_encode($employees, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Eres el motor de asignación de tareas de la plataforma Talent360. Selecciona el colaborador más adecuado para la siguiente tarea.

TAREA: "$taskDescription"

COLABORADORES EN TURNO: $employeeJson

Responde ÚNICAMENTE con un JSON:
{
  "suggested_user_id": 5,
  "suggested_user_name": "María García",
  "reason": "Tiene menor carga de trabajo activa (1 tarea) y su puesto es el más adecuado.",
  "confidence": 0.85
}

Prioriza: 1) Menor cantidad de tareas activas, 2) Puesto/rol más relevante, 3) Que esté en estado "activo" (no en comida ni descanso).
PROMPT;

        try {
            $raw  = $this->callGemini($prompt, 0.2, 256);
            $data = json_decode($this->extractJson($raw), true);

            if (!$data || !isset($data['suggested_user_id'])) {
                throw new \Exception('Respuesta inválida');
            }

            return $data;
        } catch (\Exception $e) {
            Log::warning('GeminiAIService::suggestOptimalAssignee fallback', ['error' => $e->getMessage()]);

            // Fallback: asignar al que tenga menos tareas activas
            usort($employees, fn($a, $b) => ($a['active_tasks_count'] ?? 0) <=> ($b['active_tasks_count'] ?? 0));
            $best = $employees[0] ?? null;

            return [
                'suggested_user_id'   => $best['id']   ?? null,
                'suggested_user_name' => $best['name'] ?? null,
                'reason'              => 'Asignado automáticamente por menor carga de trabajo (modo sin IA).',
                'confidence'          => 0.4,
            ];
        }
    }

    // =========================================================
    // 5. COMPARACIÓN DE EVIDENCIA FOTOGRÁFICA DE TAREA (§35)
    // =========================================================

    /**
     * Compara la evidencia fotográfica de una tarea contra imágenes de referencia.
     * A diferencia del resto de métodos de este servicio, NO atrapa la excepción
     * internamente — el controlador decide cómo degradar (mandar a revisión humana)
     * si Gemini falla, así que el error debe propagarse.
     *
     * @param string $evidenceBase64        Foto del empleado, con o sin prefijo data:image/...;base64,
     * @param array  $referenceImagesBase64 3-5 imágenes de referencia subidas por el admin
     * @param string|null $toleranceDescription Criterio de tolerancia en texto libre
     * @return array {match: bool, confidence: float, reasoning: string}
     * @throws \Exception si la API key no está configurada o Gemini falla/responde inválido
     */
    public function compareTaskEvidence(string $evidenceBase64, array $referenceImagesBase64, ?string $toleranceDescription = null): array
    {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GEMINI_API_KEY') {
            throw new \Exception('GEMINI_API_KEY no configurada. Añade tu clave en el archivo .env: GEMINI_API_KEY=tu_clave_aqui');
        }

        $toleranceText = $toleranceDescription
            ? "Criterio de tolerancia definido por el supervisor: \"{$toleranceDescription}\""
            : 'Sin criterio de tolerancia adicional — compara contra el estándar general de las imágenes de referencia.';

        $promptText = <<<PROMPT
Eres un supervisor de calidad operativa. Te doy primero {$this->countLabel(count($referenceImagesBase64))} de referencia que muestran cómo debe verse una tarea correctamente ejecutada, y al final la foto de evidencia entregada por un colaborador. Compáralas.

{$toleranceText}

Responde ÚNICAMENTE con un JSON con esta estructura exacta:
{
  "match": true,
  "confidence": 0.87,
  "reasoning": "Explicación breve (1-2 oraciones) de por qué coincide o no coincide"
}
PROMPT;

        $parts = [['text' => $promptText]];
        foreach ($referenceImagesBase64 as $refImage) {
            [$mimeType, $data] = $this->splitDataUri($refImage);
            $parts[] = ['inline_data' => ['mime_type' => $mimeType, 'data' => $data]];
        }
        [$mimeType, $data] = $this->splitDataUri($evidenceBase64);
        $parts[] = ['inline_data' => ['mime_type' => $mimeType, 'data' => $data]];

        $endpoint = $this->baseUrl . $this->model . ':generateContent?key=' . $this->apiKey;

        $response = Http::timeout(30)
            ->retry(2, 1000)
            ->post($endpoint, [
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 512,
                    'responseMimeType' => 'application/json',
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Gemini API error (compareTaskEvidence)', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Error al contactar Gemini API: HTTP ' . $response->status());
        }

        $raw = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $decoded = json_decode($this->extractJson($raw), true);

        if (!$decoded || !isset($decoded['match'])) {
            throw new \Exception('Respuesta inválida de Gemini para comparación de evidencia');
        }

        return $decoded;
    }

    private function countLabel(int $count): string
    {
        return $count === 1 ? '1 imagen' : "{$count} imágenes";
    }

    /**
     * Separa un data URI (data:image/jpeg;base64,XXXX) en [mime_type, base64_data].
     * Si no trae prefijo, asume que ya es base64 crudo y usa image/jpeg por default.
     */
    private function splitDataUri(string $dataUri): array
    {
        if (preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/s', $dataUri, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return ['image/jpeg', $dataUri];
    }

    // =========================================================
    // HELPERS PRIVADOS
    // =========================================================

    /**
     * Extrae el primer bloque JSON válido de una respuesta de texto de Gemini.
     * Gemini a veces envuelve el JSON en markdown ```json ... ```
     */
    private function extractJson(string $text): string
    {
        // Remover bloques de código markdown
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);

        // Buscar primer { y último }
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    /**
     * Extrae un título corto de un texto de voz (fallback sin IA).
     */
    private function extractTitle(string $text): string
    {
        // Limpiar palabras comunes de mando ("necesito que", "por favor", etc.)
        $filler = ['necesito que', 'por favor', 'quiero que', 'dile a', 'asígnale a',
                   'favor de', 'que alguien', 'alguien', 'la tarea es'];
        foreach ($filler as $f) {
            $text = str_ireplace($f, '', $text);
        }

        $text = trim($text);
        $words = explode(' ', $text);

        // Tomar las primeras 8 palabras como título
        $title = implode(' ', array_slice($words, 0, 8));
        return ucfirst(trim($title, " ,.:;"));
    }
}
