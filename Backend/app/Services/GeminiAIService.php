<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeminiAIService
 * Integración con la API de Google Gemini para:
 *   - parseVoiceTask: Interpretar comandos de voz del supervisor → tarea estructurada

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

    /**
     * Ronda 2026-08-13: el centinela comparaba contra 'YOUR_GEMINI_API_KEY' y el .env de
     * ejemplo trae 'YOUR_GEMINI_API_KEY_HERE' — no coincidían, así que con el placeholder el
     * sistema se creía "con IA", ofrecía el Plan IA y salía a Google con una llave falsa
     * (dos reintentos con la foto a cuestas). Ahora cualquier placeholder cuenta como "sin llave".
     */
    public static function llaveConfigurada(): bool
    {
        $key = (string) config('services.gemini.api_key', env('GEMINI_API_KEY', ''));

        return $key !== '' && !str_starts_with($key, 'YOUR_') && !str_contains($key, 'tu_clave');
    }

    /**
     * 2026-08-13: el dueño entregó llave de OpenAI y NO hay llave de Gemini en ninguna
     * instancia. En vez de reescribir cada llamada, este servicio elige el proveedor por la
     * llave que exista — OpenAI primero (la que sirve), Gemini si es la única. Los prompts, los
     * parsers y las degradaciones con gracia de cada método quedan tal cual: solo cambia el
     * transporte. Los controladores no se enteran.
     */
    public static function proveedor(): ?string
    {
        if ((string) config('services.openai.api_key', env('OPENAI_API_KEY', '')) !== '') {
            return 'openai';
        }

        return self::llaveConfigurada() ? 'gemini' : null;
    }

    /** ¿Hay ALGUNA IA configurada? Es lo que el Monitor usa para ofrecer (o no) el Plan IA. */
    public static function disponible(): bool
    {
        return self::proveedor() !== null;
    }

    /**
     * Pregunta en lenguaje natural → respuesta en texto (Wiki de la empresa, Copiloto de
     * soporte). 2026-08-13: esos cuatro sitios pegaban directo a la URL de Gemini con su
     * propio `env('GEMINI_API_KEY')`; ahora pasan por aquí y salen por el proveedor que exista.
     *
     * @throws \Exception si no hay ninguna llave o el proveedor falla.
     */
    public function responder(string $instruccion, string $pregunta, ?string $llaveGeminiPropia = null): string
    {
        $prompt = $instruccion . "\n\n" . $pregunta;

        if (self::proveedor() === 'openai') {
            $apiKey = (string) config('services.openai.api_key', env('OPENAI_API_KEY', ''));
            $model = (string) config('services.openai.model', env('OPENAI_MODEL', 'gpt-4o-mini'));
            $response = Http::timeout(30)->withToken($apiKey)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0.3,
                'messages' => [
                    ['role' => 'system', 'content' => $instruccion],
                    ['role' => 'user', 'content' => $pregunta],
                ],
            ]);
            if ($response->failed()) {
                throw new \Exception('Error al contactar OpenAI: HTTP ' . $response->status());
            }
            return trim((string) ($response->json('choices.0.message.content') ?? ''));
        }

        // La Wiki permite una llave de Gemini propia por empresa; se respeta si viene.
        $key = $llaveGeminiPropia ?: (self::llaveConfigurada() ? $this->apiKey : '');
        if ($key === '') {
            throw new \Exception('Sin llave de IA configurada.');
        }
        $response = Http::timeout(30)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $key,
            ['contents' => [['parts' => [['text' => $prompt]]]]]
        );
        if ($response->failed()) {
            throw new \Exception('Error al contactar Gemini: HTTP ' . $response->status());
        }
        return trim((string) ($response->json('candidates.0.content.parts.0.text') ?? ''));
    }

    /** Transporte OpenAI para prompts de texto que piden JSON (mismo contrato que callGemini). */
    private function callOpenAiText(string $prompt, float $temperature, int $maxOutputTokens): string
    {
        $apiKey = (string) config('services.openai.api_key', env('OPENAI_API_KEY', ''));
        $model = (string) config('services.openai.model', env('OPENAI_MODEL', 'gpt-4o-mini'));

        $response = Http::timeout(30)->retry(2, 1000)->withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxOutputTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if ($response->failed()) {
            Log::error('OpenAI API error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
            throw new \Exception('Error al contactar OpenAI: HTTP ' . $response->status());
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    /** Transporte OpenAI con visión: texto + imágenes (data URIs), pide JSON. */
    private function callOpenAiVision(string $promptText, array $dataUris, float $temperature, int $maxOutputTokens): string
    {
        $apiKey = (string) config('services.openai.api_key', env('OPENAI_API_KEY', ''));
        $model = (string) config('services.openai.vision_model', env('OPENAI_VISION_MODEL', 'gpt-4o-mini'));

        $content = [['type' => 'text', 'text' => $promptText]];
        foreach ($dataUris as $uri) {
            [$mime, $data] = $this->splitDataUri($uri);
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$data}", 'detail' => 'low']];
        }

        $response = Http::timeout(30)->retry(2, 1000)->withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxOutputTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);

        if ($response->failed()) {
            Log::error('OpenAI API error (vision)', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
            throw new \Exception('Error al contactar OpenAI: HTTP ' . $response->status());
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
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
        // Proveedor por llave disponible: con OpenAI configurada, todo lo de texto sale por ahí.
        if (self::proveedor() === 'openai') {
            return $this->callOpenAiText($prompt, $temperature, $maxOutputTokens);
        }

        if (!self::llaveConfigurada()) {
            throw new \Exception('Sin llave de IA configurada (OPENAI_API_KEY o GEMINI_API_KEY).');
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

    // analyzeCandidate() SE ELIMINÓ el 2026-08-11: código muerto verificado.
    //
    // Ningún controlador, ruta, job ni pantalla lo llamaba (comprobado también dentro del
    // contenedor de producción). Su rama de error devolvía un score de 50 que nadie calculó —
    // exactamente el tipo de cifra sin dueño que esta ronda persigue— pero nunca llegó a
    // ejecutarse. Si algún día se quiere análisis de candidatos, se construye con la regla de
    // siempre: el modelo interpreta, nunca produce la cifra.

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
    // 6. SUGERENCIA DE PLAN DE TRABAJO DEL DÍA (§42)
    // =========================================================

    /**
     * Sugiere cómo repartir el trabajo del día tomando en cuenta quién asistió,
     * las tareas pendientes y el contexto operativo (vault + puestos). Es solo
     * sugerencia: no crea ni asigna nada. Sigue el mismo patrón de fallback graceful
     * que el resto del servicio — si Gemini falla, devuelve ai_available: false.
     *
     * @param array  $present   Empleados presentes hoy [{user_id, name, job_role}]
     * @param array  $absent    Empleados ausentes hoy [{user_id, name, job_role}]
     * @param array  $pending   Tareas pendientes [{task_assignment_id, title, target_role, status}]
     * @param array  $jobRoles  Puestos del tenant [{id, name, description, responsibilities}]
     * @param string $vaultContext Texto plano del manual operativo (vault Obsidian)
     * @return array {ai_available, summary, staffing_gap_detected, suggestions}
     */
    public function suggestWorkPlan(array $present, array $absent, array $pending, array $jobRoles, string $vaultContext): array
    {
        $presentJson = json_encode($present, JSON_UNESCAPED_UNICODE);
        $absentJson  = json_encode($absent, JSON_UNESCAPED_UNICODE);
        $pendingJson = json_encode($pending, JSON_UNESCAPED_UNICODE);
        $rolesJson   = json_encode($jobRoles, JSON_UNESCAPED_UNICODE);
        $vaultTrunc  = mb_strlen($vaultContext) > 8000
            ? mb_substr($vaultContext, 0, 8000) . "\n\n[Manual truncado para el análisis]"
            : $vaultContext;

        $prompt = <<<PROMPT
Eres el asistente operativo de la plataforma Talent360. El admin está armando el plan de trabajo del día en la junta matutina. Sugiere cómo repartir el trabajo tomando en cuenta con cuánto personal se cuenta REALMENTE hoy (quién asistió y quién faltó) y el contexto operativo de la empresa.

EMPLEADOS PRESENTES HOY: $presentJson

EMPLEADOS AUSENTES HOY: $absentJson

TAREAS PENDIENTES (de hoy y días anteriores sin resolver): $pendingJson

PUESTOS DE LA EMPRESA (con descripción y responsabilidades): $rolesJson

MANUAL OPERATIVO / ORGANIGRAMA DE LA EMPRESA:
$vaultTrunc

Responde ÚNICAMENTE con un JSON con esta estructura exacta:
{
  "summary": "Resumen ejecutivo de 1-2 oraciones sobre el estado del personal de hoy y la estrategia sugerida",
  "staffing_gap_detected": true,
  "suggestions": [
    {
      "task_assignment_id": "id de la tarea pendiente a reasignar, o null si es una tarea nueva sugerida",
      "suggested_new_task_title": "título de la tarea nueva, o null si es una reasignación",
      "suggested_target_type": "role",
      "suggested_target_id": 6,
      "estimated_mins": 30,
      "reason": "Por qué se sugiere esta redistribución, citando el manual/puesto cuando aplique"
    }
  ]
}

Reglas:
- "suggested_target_type" solo puede ser "role" o "user".
- Prioriza cubrir con personal presente las responsabilidades de los puestos que faltaron hoy, basándote en las descripciones de puestos y el manual.
- Si no detectas ningún hueco de personal, "staffing_gap_detected": false y "suggestions" puede ir vacío o con mejoras menores.
- Responde SOLO el JSON, sin explicaciones adicionales.
PROMPT;

        try {
            $raw  = $this->callGemini($prompt, 0.3, 2048);
            $data = json_decode($this->extractJson($raw), true);

            if (!$data || !array_key_exists('suggestions', $data)) {
                throw new \Exception('Respuesta inválida de Gemini para plan de trabajo');
            }

            return [
                'ai_available'          => true,
                'summary'               => $data['summary'] ?? null,
                'staffing_gap_detected' => $data['staffing_gap_detected'] ?? false,
                'suggestions'           => $data['suggestions'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::warning('GeminiAIService::suggestWorkPlan fallback', ['error' => $e->getMessage()]);

            return [
                'ai_available'          => false,
                'summary'               => null,
                'staffing_gap_detected' => false,
                'suggestions'           => [],
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
        if (self::proveedor() === null) {
            throw new \Exception('Sin llave de IA configurada (OPENAI_API_KEY o GEMINI_API_KEY).');
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

        // Con OpenAI: mismo prompt, mismo orden de imágenes (referencias primero, evidencia al
        // final), mismo parser de abajo — solo cambia el transporte.
        if (self::proveedor() === 'openai') {
            $raw = $this->callOpenAiVision($promptText, [...$referenceImagesBase64, $evidenceBase64], 0.2, 512);
            $decoded = json_decode($this->extractJson($raw), true);
            if (!$decoded || !isset($decoded['match'])) {
                throw new \Exception('Respuesta inválida de OpenAI para comparación de evidencia');
            }
            return $decoded;
        }

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
