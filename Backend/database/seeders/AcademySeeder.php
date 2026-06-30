<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademySeeder extends Seeder
{
    public function run(): void
    {
        // 1. Curso de Inducción Básico (Para candidatos externos)
        $inductionId = DB::table('academy_courses')->insertGetId([
            'title' => 'Inducción Talent 360',
            'description' => 'Conoce nuestra historia, misión, visión y los valores fundamentales que nos hacen la mejor empresa.',
            'course_type' => 'induction',
            'target_job_role_id' => null, // Aplica para todos al inicio
            'video_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ', // Dummy video
            'quiz_data' => json_encode([
                ['question' => '¿Cuál es el valor principal de Talent360?', 'options' => ['Puntualidad', 'Creatividad', 'Velocidad'], 'answer' => 'Creatividad'],
                ['question' => '¿En qué año se fundó la empresa?', 'options' => ['1999', '2010', '2020'], 'answer' => '2010']
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Curso de Entrenamiento: Manejo de Cajas (Para empleados fase 2)
        $cajasId = DB::table('academy_courses')->insertGetId([
            'title' => 'Entrenamiento: Manejo de Caja Registradora',
            'description' => 'Aprende a usar el sistema de cobro, cortes de caja y devoluciones.',
            'course_type' => 'training',
            'target_job_role_id' => 5, // Cajero
            'prerequisite_course_id' => $inductionId,
            'video_url' => 'https://www.youtube.com/embed/tgbNymZ7vqY',
            'quiz_data' => json_encode([
                ['question' => '¿Qué debes hacer al final del turno?', 'options' => ['Irte', 'Corte de Caja', 'Limpiar vidrios'], 'answer' => 'Corte de Caja']
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 3. Curso de Ascenso: Supervisor de Cajas (Fase 3)
        DB::table('academy_courses')->insert([
            'title' => 'Liderazgo y Supervisión de Cajas',
            'description' => 'Desarrolla habilidades de liderazgo para resolver conflictos, autorizar devoluciones complejas y supervisar al equipo.',
            'course_type' => 'promotion',
            'target_job_role_id' => 3, // Sup. Cajas
            'prerequisite_course_id' => $cajasId, // Requiere el curso básico de cajas
            'incentive_bonus_cents' => 50000, // $500.00 de bono al pasar
            'video_url' => 'https://www.youtube.com/embed/1k8craCGv14',
            'quiz_data' => json_encode([
                ['question' => '¿Cómo resuelves una queja?', 'options' => ['Gritando', 'Ignorando', 'Escucha Activa'], 'answer' => 'Escucha Activa']
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
