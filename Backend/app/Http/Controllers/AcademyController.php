<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AcademyCourse;
use App\Models\UserCourseProgress;
use App\Models\JobRole;

class AcademyController extends Controller
{
    public function getCourses(Request $request)
    {
        $courses = AcademyCourse::all();
        $roles = JobRole::all();

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

    private function getTemplatesData()
    {
        return [
            [
                'id' => 1,
                'title' => 'Inducción DecorArte 360',
                'description' => 'Conoce nuestra historia, misión, visión y los valores fundamentales que nos hacen la mejor empresa.',
                'course_type' => 'induction',
                'target_job_role_name' => null,
                'incentive_bonus_cents' => 0,
                'video_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                'quiz_data' => [
                    [
                        'question' => '¿Cuál es el valor principal de DecorArte?',
                        'options' => ['Puntualidad', 'Creatividad', 'Velocidad'],
                        'answer' => 'Creatividad',
                    ],
                    [
                        'question' => '¿En qué año se fundó la empresa?',
                        'options' => ['1999', '2010', '2020'],
                        'answer' => '2010',
                    ],
                ],
                'is_active' => true,
            ],
            [
                'id' => 2,
                'title' => 'Entrenamiento: Manejo de Caja Registradora',
                'description' => 'Aprende a usar el sistema de cobro, cortes de caja y devoluciones.',
                'course_type' => 'training',
                'target_job_role_name' => 'Cajeros',
                'incentive_bonus_cents' => 0,
                'video_url' => 'https://www.youtube.com/embed/tgbNymZ7vqY',
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
                'target_job_role_name' => 'Sup. Cajas',
                'incentive_bonus_cents' => 50000,
                'video_url' => 'https://www.youtube.com/embed/1k8craCGv14',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
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
                'target_job_role_name' => 'Sup. Tienda y Compras',
                'incentive_bonus_cents' => 0,
                'video_url' => '',
                'quiz_data' => [],
                'is_active' => true,
            ]
        ];
    }
}

