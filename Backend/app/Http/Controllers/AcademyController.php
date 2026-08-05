<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AcademyCourse;
use App\Models\UserCourseProgress;
use App\Models\JobRole;

class AcademyController extends Controller
{
    /**
     * ¿Este usuario administra los cursos? Solo admin y supervisor (los mismos que pueden
     * crear/editar/borrar, ver el grupo `role:admin,supervisor` de routes/api.php). Es quien
     * necesita ver las respuestas correctas del examen; al colaborador se le ocultan.
     */
    private function puedeAdministrarCursos(): bool
    {
        $role = auth()->user()->role ?? null;

        return in_array($role, ['admin', 'supervisor', 'platform_admin'], true);
    }

    /**
     * Academia AC3 (auditoría 2026-08-04): el examen se calificaba en el navegador y la
     * respuesta correcta viajaba dentro de `quiz_data` en esta misma respuesta — se veía en
     * las herramientas del navegador antes de contestar. Aquí se quita para quien no
     * administra cursos: quedan la pregunta y las opciones, que es lo que hace falta para
     * presentar el examen. La calificación la hace el servidor (`submitQuizAttempt`).
     */
    private function ocultarRespuestas($quizData)
    {
        if (!is_array($quizData)) {
            return $quizData;
        }

        return array_map(function ($pregunta) {
            if (!is_array($pregunta)) {
                return $pregunta;
            }

            unset($pregunta['correctAnswer'], $pregunta['answer']);

            return $pregunta;
        }, $quizData);
    }

    public function getCourses(Request $request)
    {
        $courses = AcademyCourse::all();
        $roles = JobRole::all();

        if (!$this->puedeAdministrarCursos()) {
            $courses->each(function ($course) {
                $course->quiz_data = $this->ocultarRespuestas($course->quiz_data);
            });
        }

        $userProgress = [];
        if (auth()->check()) {
            $userProgress = UserCourseProgress::where('user_id', auth()->id())->get();
        }

        return response()->json([
            'courses' => $courses,
            'job_roles' => $roles,
            'user_progress' => $userProgress
        ]);
    }

    public function getCourse($id)
    {
        $course = AcademyCourse::findOrFail($id);

        if (!$this->puedeAdministrarCursos()) {
            $course->quiz_data = $this->ocultarRespuestas($course->quiz_data);
        }

        return response()->json($course);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'video_url' => 'nullable|string',
            'course_type' => 'required|string|in:induction,training,promotion,recertification',
            'quiz_data' => 'nullable|array',
            'certificate_template_id' => 'nullable|integer',
            'target_job_role_id' => 'nullable|integer|exists:job_roles,id'
        ]);

        if (isset($data['target_job_role_id']) && $data['target_job_role_id']) {
            if (!JobRole::where('id', $data['target_job_role_id'])->exists()) {
                return response()->json(['message' => 'El puesto seleccionado no pertenece a su organización.'], 403);
            }
        }

        $course = AcademyCourse::create($data);

        return response()->json(['status' => 'success', 'id' => $course->id]);
    }

    public function update(Request $request, $id)
    {
        $course = AcademyCourse::findOrFail($id);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'video_url' => 'nullable|string',
            'course_type' => 'required|string|in:induction,training,promotion,recertification',
            'quiz_data' => 'nullable|array',
            'certificate_template_id' => 'nullable|integer',
            'target_job_role_id' => 'nullable|integer|exists:job_roles,id'
        ]);

        if (isset($data['target_job_role_id']) && $data['target_job_role_id']) {
            if (!JobRole::where('id', $data['target_job_role_id'])->exists()) {
                return response()->json(['message' => 'El puesto seleccionado no pertenece a su organización.'], 403);
            }
        }

        $course->update($data);

        return response()->json(['status' => 'success']);
    }

    public function destroy($id)
    {
        $course = AcademyCourse::findOrFail($id);
        $course->delete();
        return response()->json(['status' => 'success']);
    }

    /**
     * Academia AC3 — el examen se califica AQUÍ, con las respuestas que manda el colaborador.
     *
     * Antes: `submitQuiz` del frontend comparaba en el navegador y, si le salía que aprobaba,
     * posteaba `{status:'completed', score:100}` a `updateProgress`. El `score` era un literal,
     * las respuestas del alumno no viajaban nunca y el backend no podía recalificar; además las
     * respuestas correctas se le mandaban al navegador dentro de `quiz_data`. Completar un
     * curso era, en los hechos, apretar un botón. Y como el bloqueo del checador por 3 retardos
     * se levanta con el `completed_at` del curso de puntualidad
     * (`ClockService::getPunctualityStatus`), el colaborador se desbloqueaba el reloj solo.
     *
     * El progreso se busca por (user_id, course_id), que es el único índice de la tabla —
     * meter `tenant_id` en la clave, como hacen los métodos viejos, deja fuera cualquier fila
     * anterior con otro tenant y el insert siguiente choca contra el unique.
     */
    public function submitQuizAttempt(Request $request, $id)
    {
        $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'nullable|integer',
        ]);

        $course = AcademyCourse::find($id);
        if (!$course) {
            return response()->json(['status' => 'error', 'message' => 'Curso no encontrado.'], 404);
        }

        $preguntas = is_array($course->quiz_data) ? $course->quiz_data : [];
        if (count($preguntas) === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este curso no tiene evaluación.',
            ], 422);
        }

        $respuestas = $request->input('answers');
        $correctas = 0;

        foreach ($preguntas as $i => $pregunta) {
            $esperada = $this->indiceCorrecto($pregunta);
            $dada = $respuestas[$i] ?? null;

            if ($esperada !== null && $dada !== null && (int) $dada === $esperada) {
                $correctas++;
            }
        }

        $total = count($preguntas);
        // Mismo criterio de aprobación que tenía el frontend: se aprueba con todas.
        $aprobado = $correctas === $total;
        $score = (int) round($correctas / $total * 100);

        $userId = auth()->id();
        $tenantId = auth()->user()->tenant_id ?? 1;

        $progreso = UserCourseProgress::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->first();

        $intentosFallidos = (int) ($progreso->failed_attempts ?? 0);
        if (!$aprobado) {
            $intentosFallidos++;
        }

        UserCourseProgress::updateOrInsert(
            ['user_id' => $userId, 'course_id' => $course->id],
            [
                'tenant_id' => $tenantId,
                'status' => $aprobado ? 'completed' : 'failed',
                'score' => $score,
                'failed_attempts' => $intentosFallidos,
                'completed_at' => $aprobado ? now() : null,
                'updated_at' => now(),
                'created_at' => $progreso->created_at ?? now(),
            ]
        );

        if ($aprobado && $course->course_type === 'induction') {
            auth()->user()->update(['has_completed_induction' => true]);
        }

        return response()->json([
            'status' => 'success',
            'passed' => $aprobado,
            'score' => $score,
            'correct_count' => $correctas,
            'total' => $total,
            'failed_attempts' => $intentosFallidos,
        ]);
    }

    /**
     * Índice de la opción correcta de una pregunta. El formato no es uniforme (los cursos
     * nacieron de fuentes distintas): unos traen `correctAnswer` con el índice, otros `answer`
     * con el TEXTO de la opción y otros `answer` con el índice. Devuelve null si la pregunta
     * no declara respuesta — esas no se pueden acertar, así que el curso no se aprueba hasta
     * que su administrador la configure.
     */
    private function indiceCorrecto($pregunta): ?int
    {
        if (!is_array($pregunta)) {
            return null;
        }

        if (isset($pregunta['correctAnswer']) && is_numeric($pregunta['correctAnswer'])) {
            return (int) $pregunta['correctAnswer'];
        }

        if (!isset($pregunta['answer'])) {
            return null;
        }

        if (is_numeric($pregunta['answer'])) {
            return (int) $pregunta['answer'];
        }

        $opciones = $pregunta['options'] ?? [];
        if (is_array($opciones)) {
            $indice = array_search($pregunta['answer'], $opciones, true);
            if ($indice !== false) {
                return (int) $indice;
            }
        }

        return null;
    }

    /**
     * ¿El curso exige aprobar un examen para darse por completado? (AC3) Si lo exige, la única
     * vía es `submitQuizAttempt`; `updateProgress`/`saveProgress` dejan de aceptar 'completed'.
     * Los cursos SIN examen conservan el comportamiento de siempre: se completan viendo el
     * video, que es de lo que depende el gate de la Academia en Tareas (§38, TaskRunner).
     */
    private function exigeExamen(?AcademyCourse $course): bool
    {
        return $course && is_array($course->quiz_data) && count($course->quiz_data) > 0;
    }

    private function respuestaExamenObligatorio()
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Este curso se completa aprobando su evaluación.',
        ], 422);
    }

    public function updateProgress(Request $request, $id)
    {
        $data = $request->validate([
            'status' => 'required|string|in:enrolled,in_progress,completed,failed',
            'score' => 'nullable|integer'
        ]);

        // Merge F3: el curso debe existir ANTES de escribir el progreso —
        // user_course_progress.course_id tiene FK a academy_courses, así que un id fantasma
        // (p.ej. el curso SINTÉTICO de Puntualidad, id 999, que el FE inyecta como fallback)
        // provocaba una violación de FK → 500. Se responde 404 limpio.
        $course = \App\Models\AcademyCourse::find($id);
        if (!$course) {
            return response()->json(['status' => 'error', 'message' => 'Curso no encontrado.'], 404);
        }

        // AC3: si el curso tiene examen, aprobarlo es la ÚNICA vía para completarlo.
        if ($data['status'] === 'completed' && $this->exigeExamen($course)) {
            return $this->respuestaExamenObligatorio();
        }

        $userId = auth()->id();
        $tenantId = auth()->user()->tenant_id ?? 1;

        UserCourseProgress::updateOrInsert(
            ['user_id' => $userId, 'course_id' => $id, 'tenant_id' => $tenantId],
            [
                'status' => $data['status'],
                'score' => $data['score'] ?? 100,
                'completed_at' => $data['status'] === 'completed' ? now() : null,
                'updated_at' => now()
            ]
        );

        // Si el curso completado es de tipo 'induction', también levantamos el bloqueo operativo en users table
        $course = AcademyCourse::find($id);
        if ($course && $course->course_type === 'induction' && $data['status'] === 'completed') {
            auth()->user()->update(['has_completed_induction' => true]);
        }

        return response()->json(['status' => 'success']);
    }

    public function saveProgress(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|integer|exists:academy_courses,id',
            'status' => 'required|string|in:enrolled,in_progress,completed,failed',
            'score' => 'nullable|integer'
        ]);

        // AC3: mismo candado que en updateProgress — con examen, solo se completa aprobándolo.
        if ($data['status'] === 'completed' && $this->exigeExamen(AcademyCourse::find($data['course_id']))) {
            return $this->respuestaExamenObligatorio();
        }

        $userId = auth()->id();
        $tenantId = auth()->user()->tenant_id ?? 1;

        \App\Models\UserCourseProgress::updateOrInsert(
            ['user_id' => $userId, 'course_id' => $data['course_id'], 'tenant_id' => $tenantId],
            [
                'status' => $data['status'],
                'score' => $data['score'] ?? 100,
                'completed_at' => $data['status'] === 'completed' ? now() : null,
                'updated_at' => now()
            ]
        );

        $course = AcademyCourse::find($data['course_id']);
        if ($course && $course->course_type === 'induction' && $data['status'] === 'completed') {
            auth()->user()->update(['has_completed_induction' => true]);
        }

        return response()->json(['status' => 'success']);
    }

    public function getTemplates()
    {
        return response()->json($this->getTemplatesData());
    }

    public function importTemplate(Request $request, $id)
    {
        $templates = $this->getTemplatesData();
        $template = collect($templates)->firstWhere('id', (int)$id);

        if (!$template) {
            return response()->json(['message' => 'Plantilla no encontrada'], 404);
        }

        // Resolving target_job_role_id by name
        $targetJobRoleId = null;
        if ($template['target_job_role_name']) {
            $mappedName = $template['target_job_role_name'];
            $role = JobRole::all()->first(function($r) use ($mappedName) {
                return strtolower(trim($r->name)) === strtolower(trim($mappedName));
            });

            if ($role) {
                $targetJobRoleId = $role->id;
            }
        }

        $tenantId = auth()->user()->tenant_id ?? 1;

        $course = AcademyCourse::create([
            'title' => $template['title'],
            'description' => $template['description'],
            'course_type' => $template['course_type'],
            'target_job_role_id' => $targetJobRoleId,
            'incentive_bonus_cents' => $template['incentive_bonus_cents'],
            'video_url' => $template['video_url'],
            'quiz_data' => $template['quiz_data'],
            'is_active' => true,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'message' => 'Curso importado con éxito',
            'course' => $course
        ], 201);
    }

    /**
     * Plantillas que cualquier empresa puede importar a su Academia desde el gestor.
     *
     * Academia AC6 (auditoría 2026-08-04): esta lista se le ofrece a TODOS los clientes y venía
     * con el nombre y la historia de una empresa concreta —"Inducción DecorArte 360", "¿Cuál es
     * el valor principal de DecorArte?", "¿En qué año se fundó la empresa?" con la respuesta
     * 2010— y con tres videos que eran marcadores de prueba, uno de ellos el rickroll de Rick
     * Astley (`dQw4w9WgXcQ`). Importar la inducción dejaba a la empresa con un curso sobre otra
     * empresa y un video troll. Los títulos y preguntas quedaron neutros; los videos, vacíos
     * (no hay video que poner: cada empresa sube el suyo). Misma familia que H12.
     */
    private function getTemplatesData()
    {
        return [
            [
                'id' => 1,
                'title' => 'Inducción a la Empresa',
                'description' => 'Conoce la historia, misión, visión y los valores fundamentales de tu empresa.',
                'course_type' => 'induction',
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [
                    [
                        'question' => '¿Dónde puedes consultar los valores y las reglas de tu empresa?',
                        'options' => ['En la Wiki y el manual de operaciones', 'En ningún lado', 'Solo preguntando'],
                        'answer' => 'En la Wiki y el manual de operaciones',
                    ],
                ],
                'is_active' => true,
            ],
            [
                'id' => 2,
                'title' => 'Entrenamiento: Manejo de Caja Registradora',
                'description' => 'Aprende a usar el sistema de cobro, cortes de caja y devoluciones.',
                'course_type' => 'training',
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [
                    [
                        'question' => '¿Qué debes hacer al final del turno?',
                        'options' => ['Irte', 'Corte de Caja', 'Limpiar vidrios'],
                        'answer' => 'Corte de Caja',
                    ],
                ],
                'is_active' => true,
            ],
            [
                'id' => 3,
                'title' => 'Liderazgo y Supervisión de Cajas',
                'description' => 'Desarrolla habilidades de liderazgo para resolver conflictos, autorizar devoluciones complejas y supervisar al equipo.',
                'course_type' => 'promotion',
                'target_job_role_name' => null,
                // AC6: venía con 50000 (=$500 MXN) y la Academia se lo anuncia al colaborador
                // como "Bono de incentivo de $500.00 MXN al completarlo" — pero NADA en el
                // sistema paga ese bono. Se deja en 0 hasta que exista el circuito de pago o se
                // decida quitar la promesa de la interfaz (decisión de producto, ver bitácora).
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [
                    [
                        'question' => '¿Cómo resuelves una queja?',
                        'options' => ['Gritando', 'Ignorando', 'Escucha Activa'],
                        'answer' => 'Escucha Activa',
                    ],
                ],
                'is_active' => true,
            ],
            [
                'id' => 4,
                'title' => 'Documento 06: Protocolo de Apertura de Operación',
                'description' => 'Aprende los pasos vitales para iniciar el día en la tienda. Garantiza que las luces, la caja y el ambiente estén listos para el primer cliente.',
                'course_type' => 'induction',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [
                    [
                        'question' => '¿Cuál es la primera acción al llegar a la tienda?',
                        'options' => ['Encender el clima', 'Revisar la bitácora del día anterior', 'Desactivar alarma y encender switch principal', 'Hacer café'],
                        'correctAnswer' => 2,
                    ],
                ],
                'is_active' => true,
            ],
            [
                'id' => 5,
                'title' => 'Documento 07: Protocolo de Recepción de Mercancía',
                'description' => 'Reglas de oro para recibir a los proveedores, validar cantidades, caducidades y reportar mermas inmediatamente.',
                'course_type' => 'training',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 6,
                'title' => 'Documento 08: Protocolo de Captura de Compras en SICAR',
                'description' => 'Guía paso a paso para dar de alta en el sistema los productos recién recibidos, asegurando que el costo y precio de venta sean correctos.',
                'course_type' => 'training',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 7,
                'title' => 'Documento 09: Protocolo de Captura de Gastos',
                'description' => 'Registra adecuadamente las salidas de efectivo para mantener las finanzas cuadradas al final del día.',
                'course_type' => 'training',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 8,
                'title' => 'Documento 10: Protocolo de Generación de Pedidos',
                'description' => 'Aprende a analizar los faltantes y máximos/mínimos en SICAR para pedirle al proveedor solo lo que necesitamos vender.',
                'course_type' => 'promotion',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 9,
                'title' => 'Documento 11: Protocolo de Ajustes de Inventario',
                'description' => 'Técnicas de conteo y cómo solicitar una autorización de ajuste cuando sobra o falta mercancía en el sistema.',
                'course_type' => 'training',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 10,
                'title' => 'Documento 12: Protocolo de Diferencias de Inventario',
                'description' => 'Investigación de diferencias: cómo rastrear facturas y tickets para hallar el descuadre.',
                'course_type' => 'training',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 11,
                'title' => 'Documento 13: Productos Dañados o Caducados',
                'description' => 'Qué hacer con la merma, cómo registrarla en el sistema y cómo separarla físicamente para evitar contaminación.',
                'course_type' => 'training',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 12,
                'title' => 'Documento 14: Protocolo de Supervisión Comercial',
                'description' => 'Caminata de tienda: supervisa pasillos, góndolas y garantiza que el piso de venta sea atractivo visualmente.',
                'course_type' => 'promotion',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 13,
                'title' => 'Documento 15: Coordinación de Personal',
                'description' => 'Desarrolla tus habilidades blandas. Aprende a delegar tareas y dar retroalimentación a tu equipo.',
                'course_type' => 'promotion',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ],
            [
                'id' => 14,
                'title' => 'Documento 16: Protocolo de Cierre Diario',
                'description' => 'Asegura la tienda, realiza el corte de caja, deposita en la tómbola y deja la bitácora lista para mañana.',
                'course_type' => 'induction',
                // AC6: apuntaba a 'Sup. Tienda y Compras', un puesto que sólo existe en el
                // organigrama de una empresa concreta; en cualquier otra no resolvía a nada.
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ]
        ];
    }
}

