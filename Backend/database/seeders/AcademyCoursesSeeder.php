<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademyCoursesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $courses = [
            [
                'title' => 'Documento 06: Protocolo de Apertura de Operación',
                'description' => 'Aprende los pasos vitales para iniciar el día en la tienda. Garantiza que las luces, la caja y el ambiente estén listos para el primer cliente.',
                'course_type' => 'induction',
                'target_job_role_id' => 2, // Supervisor de Compras
                'video_url' => '', // Pendiente
                'quiz_data' => json_encode([
                    [
                        'question' => '¿Cuál es la primera acción al llegar a la tienda?',
                        'options' => ['Encender el clima', 'Revisar la bitácora del día anterior', 'Desactivar alarma y encender switch principal', 'Hacer café'],
                        'correctAnswer' => 2
                    ]
                ])
            ],
            [
                'title' => 'Documento 07: Protocolo de Recepción de Mercancía',
                'description' => 'Reglas de oro para recibir a los proveedores, validar cantidades, caducidades y reportar mermas inmediatamente.',
                'course_type' => 'training',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 08: Protocolo de Captura de Compras en SICAR',
                'description' => 'Guía paso a paso para dar de alta en el sistema los productos recién recibidos, asegurando que el costo y precio de venta sean correctos.',
                'course_type' => 'training',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 09: Protocolo de Captura de Gastos',
                'description' => 'Registra adecuadamente las salidas de efectivo para mantener las finanzas cuadradas al final del día.',
                'course_type' => 'training',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 10: Protocolo de Generación de Pedidos',
                'description' => 'Aprende a analizar los faltantes y máximos/mínimos en SICAR para pedirle al proveedor solo lo que necesitamos vender.',
                'course_type' => 'promotion',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 11: Protocolo de Ajustes de Inventario',
                'description' => 'Técnicas de conteo y cómo solicitar una autorización de ajuste cuando sobra o falta mercancía en el sistema.',
                'course_type' => 'training',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 12: Protocolo de Diferencias de Inventario',
                'description' => 'Investigación de diferencias: cómo rastrear facturas y tickets para hallar el descuadre.',
                'course_type' => 'training',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 13: Productos Dañados o Caducados',
                'description' => 'Qué hacer con la merma, cómo registrarla en el sistema y cómo separarla físicamente para evitar contaminación.',
                'course_type' => 'training',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 14: Protocolo de Supervisión Comercial',
                'description' => 'Caminata de tienda: supervisa pasillos, góndolas y garantiza que el piso de venta sea atractivo visualmente.',
                'course_type' => 'promotion',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 15: Coordinación de Personal',
                'description' => 'Desarrolla tus habilidades blandas. Aprende a delegar tareas y dar retroalimentación a tu equipo.',
                'course_type' => 'promotion',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
            [
                'title' => 'Documento 16: Protocolo de Cierre Diario',
                'description' => 'Asegura la tienda, realiza el corte de caja, deposita en la tómbola y deja la bitácora lista para mañana.',
                'course_type' => 'induction',
                'target_job_role_id' => 2,
                'video_url' => '',
                'quiz_data' => json_encode([])
            ],
        ];

        foreach ($courses as $course) {
            DB::table('academy_courses')->insert(array_merge($course, [
                'tenant_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'is_active' => true
            ]));
        }
    }
}
