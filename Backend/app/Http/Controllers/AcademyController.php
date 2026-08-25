<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AcademyCourse;
use App\Models\CourseCertificate;
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

        // Guardar el examen desde esta pantalla ES la aprobación de la empresa (Fase 2): quien lo
        // escribe es su administrador y responde por él. Sin esta marca no se expiden folios.
        $data = $this->marcarExamenPropio($data);

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

        $course->update($this->marcarExamenPropio($data));

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
                // Un intento nuevo reabre el caso en el tablero del encargado: si ya lo había
                // marcado como atendido y la persona vuelve a reprobar, tiene que volver a
                // enterarse. Y si aprueba, el caso se cierra solo.
                'supervisor_atendido_at' => null,
                'completed_at' => $aprobado ? now() : null,
                'updated_at' => now(),
                'created_at' => $progreso->created_at ?? now(),
            ]
        );

        // APAGÓN DE FOLIOS (Fase 2, 2026-08-24). El certificado lleva folio y se verifica EN
        // PÚBLICO, sin sesión: es un documento que sale de la empresa y que un tercero —un
        // inspector, un abogado— va a leer como constancia de capacitación. No se expide sobre un
        // examen que la empresa no configuró: los del catálogo traen UNA pregunta de relleno con
        // la respuesta siempre en la primera opción, y uno de esos cursos es "Derechos Laborales y
        // LFT". El curso sí se da por aprobado (el avance se guarda, la inducción se cumple); lo
        // que no se emite es el papel verificable.
        $examenPropio = $this->examenAprobadoPorLaEmpresa($course);
        if ($aprobado && $examenPropio) {
            $this->emitirCertificado($course, $score);
        }

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
            // Que la pantalla no prometa un certificado que no existe.
            'certificado_emitido' => $aprobado && $examenPropio,
            'certificado_bloqueado_motivo' => ($aprobado && !$examenPropio)
                ? 'Este curso todavía usa el examen de ejemplo del catálogo. Se registró tu avance, '
                    . 'pero no se expide constancia con folio verificable hasta que tu empresa '
                    . 'configure su propia evaluación.'
                : null,
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

    /**
     * Emite el certificado del curso al colaborador en sesión (familia H23).
     *
     * Antes el certificado era un `div` que se mandaba a la impresora: sin folio, sin registro y
     * con las fechas escritas a mano en el código. Ahora queda una fila con folio verificable y
     * una FOTO de los datos al momento de emitir.
     *
     * Es idempotente: volver a aprobar el mismo curso NO emite otro ni cambia el folio que el
     * colaborador ya tiene impreso.
     */
    /**
     * Sella el examen como propio de la empresa cuando su administrador lo guarda con preguntas.
     * Guardar un curso SIN examen no sella nada: no hay evaluación que aprobar.
     */
    private function marcarExamenPropio(array $data): array
    {
        if (!empty($data['quiz_data']) && is_array($data['quiz_data'])) {
            $data['quiz_approved_at'] = now();
            $data['quiz_approved_by'] = auth()->id();
        }

        return $data;
    }

    /**
     * ¿El examen de este curso lo configuró la empresa, o sigue siendo el relleno del catálogo?
     *
     * La marca la pone el propio administrador al guardar el examen desde su pantalla; no se
     * adivina mirando el contenido. Adivinar aquí sería el mismo error que esta ronda persigue:
     * el sistema declararía "aprobado por la empresa" algo que la empresa nunca aprobó.
     */
    private function examenAprobadoPorLaEmpresa(?AcademyCourse $course): bool
    {
        return $course !== null && $course->quiz_approved_at !== null;
    }

    private function emitirCertificado(AcademyCourse $course, int $score): CourseCertificate
    {
        $user = auth()->user();

        $existente = CourseCertificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existente) {
            return $existente;
        }

        // La colisión de folio es improbable (31^8), pero se reintenta en vez de reventarle el
        // curso aprobado al colaborador por un choque de la columna única.
        for ($intento = 0; $intento < 5; $intento++) {
            try {
                return CourseCertificate::create([
                    'tenant_id' => $user->tenant_id ?? 1,
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'folio' => CourseCertificate::generarFolio(),
                    'issued_at' => now(),
                    'score' => $score,
                    'participant_name' => $user->name,
                    'course_title' => $course->title,
                    'company_name' => $user->tenant->name ?? null,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Si el choque fue por (user_id, course_id) —dos aprobaciones simultáneas— el
                // certificado ya existe y es el bueno.
                $existente = CourseCertificate::where('user_id', $user->id)
                    ->where('course_id', $course->id)
                    ->first();

                if ($existente) {
                    return $existente;
                }
            }
        }

        throw new \RuntimeException('No se pudo generar un folio de certificado único.');
    }

    /**
     * Certificados del colaborador en sesión.
     *
     * También emite los que falten por cursos ya completados: el registro nació después de que
     * hubiera progreso en las bases, y sin esto un colaborador que aprobó ayer se quedaría sin
     * certificado para siempre. Es idempotente, así que no duplica nada.
     */
    public function myCertificates()
    {
        $user = auth()->user();

        $completados = UserCourseProgress::where('user_id', $user->id)
            ->where('status', 'completed')
            ->get();

        foreach ($completados as $progreso) {
            $course = AcademyCourse::find($progreso->course_id);

            // (2026-08-24) Esta es una SEGUNDA puerta de emisión, y se estaba saltando el apagón
            // de folios de la Fase 2: bastaba con abrir "Mis certificados" para que se expidiera
            // la constancia de un curso con el examen de relleno del catálogo. El candado tiene
            // que estar en las dos puertas o no es un candado.
            if ($course && $this->examenAprobadoPorLaEmpresa($course)) {
                $this->emitirCertificado($course, (int) ($progreso->score ?? 100));
            }
        }

        // Los revocados no se listan: dejaron de valer. Siguen en la base porque fueron un hecho.
        $certificados = CourseCertificate::where('user_id', $user->id)
            ->vigente()
            ->orderByDesc('issued_at')
            ->get();

        return response()->json(['certificates' => $certificados]);
    }

    /**
     * ¿El colaborador en sesión trae su inducción pendiente, y cuántos días le quedan?
     *
     * Alimenta el banner de su app: *"Tienes N días para tu inducción. Complétala aquí."*
     * (decisión de producto 2026-08-06: banner en la app, **no** correo).
     *
     * La cuenta la hace el servidor a propósito, con la misma constante que decide cuándo el
     * caso se pinta en rojo en el tablero del encargado (`PlazoInduccion::DIAS`). Si el cliente
     * la calculara por su cuenta, el colaborador y su jefe acabarían mirando relojes distintos.
     *
     * **No bloquea nada**: es un aviso. El colaborador ficha y trabaja igual.
     */
    public function miInduccionPendiente(Request $request)
    {
        $user = $request->user();
        $plazo = \App\Support\PlazoInduccion::DIAS;

        $sinPendiente = [
            'pendiente' => false,
            'plazo' => $plazo,
        ];

        // Los administradores no ven banner, igual que no salen en el tablero del encargado
        // (decisión de producto 2026-08-06). Nadie va a darle seguimiento a la dueña de la
        // empresa, así que recordárselo cada día es ruido — y las dos puntas de la regla tienen
        // que decir lo mismo: si no aparece en el tablero, tampoco se le presiona a ella.
        if (in_array($user->role, ['admin', 'platform_admin'], true)) {
            return response()->json($sinPendiente);
        }

        if ($user->has_completed_induction) {
            return response()->json($sinPendiente);
        }

        // El curso que le toca: uno de inducción visible para su puesto (sin puesto declarado =
        // toda la plantilla, criterio de AC2). Si su empresa no tiene ninguno, no hay nada que
        // reclamarle.
        // El filtro por empresa va EXPLÍCITO: `TenantScope` no se aplica cuando la app corre en
        // consola (pruebas, comandos, colas), así que confiar sólo en el scope global deja la
        // consulta abierta justo en los contextos donde nadie la está mirando.
        $curso = AcademyCourse::where('tenant_id', $user->tenant_id ?? 1)
            ->where('course_type', 'induction')
            ->where(function ($q) use ($user) {
                $q->whereNull('target_job_role_id')
                    ->orWhere('target_job_role_id', $user->job_role_id);
            })
            ->orderBy('id')
            ->first();

        if (!$curso) {
            return response()->json($sinPendiente);
        }

        $ingreso = \Illuminate\Support\Facades\DB::table('employees')
            ->where('tenant_id', $user->tenant_id ?? 1)
            ->where('user_id', $user->id)
            ->value('hire_date');

        $transcurridos = $ingreso
            ? max(0, \Carbon\Carbon::parse($ingreso)->startOfDay()->diffInDays(\Carbon\Carbon::today(), false))
            : null;

        return response()->json([
            'pendiente' => true,
            'course_id' => $curso->id,
            'course_title' => $curso->title,
            'plazo' => $plazo,
            // Null cuando el expediente no tiene fecha de ingreso (altas viejas): el banner
            // avisa igual, pero sin inventar una cuenta regresiva.
            'dias_transcurridos' => $transcurridos,
            'dias_restantes' => $transcurridos === null ? null : max(0, $plazo - $transcurridos),
            'vencido' => $transcurridos !== null && $transcurridos >= $plazo,
        ]);
    }

    /**
     * Verificación pública de un certificado por su folio (SIN sesión).
     *
     * Es la mitad que le faltaba al certificado para significar algo: quien lo recibe impreso
     * puede comprobar que existe. Devuelve sólo lo que ya está en el papel —nombre, curso,
     * empresa y fecha— y nada más: ni correo, ni puesto, ni el resto del expediente. El folio
     * lleva 8 caracteres aleatorios y la ruta va con throttle, así que no se puede ir probando
     * folios hasta encontrar los de otros (la lección de AC7).
     */
    public function verifyCertificate($folio)
    {
        $certificado = CourseCertificate::withoutGlobalScopes()
            ->where('folio', $folio)
            ->first();

        if (!$certificado) {
            return response()->json([
                'valid' => false,
                'message' => 'No existe ningún certificado con ese folio.',
            ], 404);
        }

        // REVOCADO (2026-08-24): se responde 404 y NO se filtra un solo dato del curso, del
        // colaborador ni de la empresa. Quien consulta un folio revocado no tiene por que
        // enterarse de que existio, a quien se le expidio ni de que curso hablaba: eso seguiria
        // sirviendo de constancia informal. La razon de la revocacion vive en la base, para la
        // empresa, no en una respuesta publica.
        if ($certificado->estaRevocado()) {
            return response()->json([
                'valid' => false,
                'message' => 'Certificado inválido o revocado para este folio.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'folio' => $certificado->folio,
            'participant_name' => $certificado->participant_name,
            'course_title' => $certificado->course_title,
            'company_name' => $certificado->company_name,
            'issued_at' => $certificado->issued_at->toDateString(),
            'score' => $certificado->score,
        ]);
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

        // Un curso SIN examen se completa viéndolo, y también certifica.
        if ($data['status'] === 'completed') {
            $this->emitirCertificado($course, (int) ($data['score'] ?? 100));
        }

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

        if ($course && $data['status'] === 'completed') {
            $this->emitirCertificado($course, (int) ($data['score'] ?? 100));
        }

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

