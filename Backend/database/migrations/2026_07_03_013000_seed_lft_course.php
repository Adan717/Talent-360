<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('academy_courses')->insert([
            'title' => 'Ley Federal del Trabajo (LFT): Derechos y Límites',
            'description' => 'Aprende sobre tus derechos laborales, límites de jornada, y las regulaciones de la LFT sobre retardos, comidas y penalizaciones.',
            'course_type' => 'training',
            'video_url' => 'https://www.youtube.com/watch?v=5V920K64X54',
            'is_active' => true,
            'quiz_data' => json_encode([
                [
                    'question' => '¿Está permitido descontar dinero del salario base del trabajador como multa por llegar tarde?',
                    'options' => [
                        'Sí, si está escrito en el reglamento interior.',
                        'No, el Artículo 107 prohíbe estrictamente imponer multas al salario del trabajador.',
                        'Sí, pero máximo un 10% del salario mensual.'
                    ],
                    'correctAnswer' => 1
                ],
                [
                    'question' => '¿Qué descuento está legalmente permitido ante un retardo injustificado?',
                    'options' => [
                        'Descontar únicamente el tiempo proporcional no laborado (minutos exactos del retardo).',
                        'Descontar el día completo de trabajo.',
                        'No se permite ningún descuento de ningún tipo.'
                    ],
                    'correctAnswer' => 0
                ],
                [
                    'question' => '¿Se pueden condicionar los bonos extra de puntualidad o productividad al desempeño general?',
                    'options' => [
                        'No, los bonos deben pagarse completos siempre.',
                        'Sí, siempre y cuando estas condiciones estén debidamente establecidas en el Reglamento Interior de Trabajo.',
                        'Solo si el empleado firma de conformidad al momento del pago.'
                    ],
                    'correctAnswer' => 1
                ]
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('academy_courses')->where('title', 'like', 'Ley Federal del Trabajo%')->delete();
    }
};
