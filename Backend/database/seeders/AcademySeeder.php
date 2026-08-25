<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra tres cursos de demostración en la Academia.
 *
 * OJO (Academia AC6, auditoría 2026-08-04): **hoy no lo llama nadie** — no está registrado en
 * `DatabaseSeeder` ni en ningún comando; sólo corre a mano con
 * `php artisan db:seed --class=AcademySeeder`. Se le quitaron los videos de prueba (uno era el
 * rickroll de Rick Astley) y el bono de $500 que ningún circuito paga, porque si alguien lo
 * corriera sobre una base real dejaría eso dentro.
 *
 * 2026-08-05: al escribirle una prueba se descubrió que además **no podía correr**: apuntaba a
 * los puestos 3 y 5 por id fijo, que en una base limpia no existen, así que reventaba con una
 * violación de llave foránea. Ahora los cursos nacen sin puesto (visibles para toda la plantilla,
 * criterio de AC2) y el seeder es ejecutable.
 *
 * Le queda un defecto de fondo: **no escribe `tenant_id`**, así que los cursos caen en la empresa
 * 1. Mientras nadie lo llame da igual; si se va a usar de verdad hay que pasarle el tenant.
 */
class AcademySeeder extends Seeder
{
    public function run(): void
    {
        // Fase 2 (2026-08-24): este seeder escribe cursos CON su examen. Correrlo sobre una
        // empresa viva le reescribe evaluaciones que su gente ya presento y de las que ya hay
        // certificados con folio verificable en la calle.
        \App\Support\CandadoDeSeeders::verificar('cursos de Academia (demo)', [
            'user_course_progress' => null,
            'course_certificates' => null,
        ]);

        // 1. Curso de Inducción Básico (Para candidatos externos)
        $inductionId = DB::table('academy_courses')->insertGetId([
            'title' => 'Inducción Talent 360',
            'description' => 'Conoce nuestra historia, misión, visión y los valores fundamentales que nos hacen la mejor empresa.',
            'course_type' => 'induction',
            'target_job_role_id' => null, // Aplica para todos al inicio
            'video_url' => '',
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
            'target_job_role_id' => null, // antes: 5 por id fijo, que en base limpia no existe
            'prerequisite_course_id' => $inductionId,
            'video_url' => '',
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
            'target_job_role_id' => null, // antes: 3 por id fijo, que en base limpia no existe
            'prerequisite_course_id' => $cajasId, // Requiere el curso básico de cajas
            // AC6: eran 50000 (=$500 MXN) y la Academia se lo anuncia al colaborador como bono
            // al completar el curso, pero nada en el sistema lo paga.
            'incentive_bonus_cents' => 0,
            'video_url' => '',
            'quiz_data' => json_encode([
                ['question' => '¿Cómo resuelves una queja?', 'options' => ['Gritando', 'Ignorando', 'Escucha Activa'], 'answer' => 'Escucha Activa']
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
