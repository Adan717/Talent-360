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
        // Limpiar registros antiguos para evitar duplicados
        DB::table('academy_courses')->where('tenant_id', 1)->delete();

        // 1. Curso LFT (Inducción, Global/Todos los puestos)
        DB::table('academy_courses')->insert([
            'tenant_id' => 1,
            'title' => 'Ley Federal del Trabajo (LFT): Derechos y Límites',
            'description' => 'Aprende sobre tus derechos laborales fundamentales: límites de jornada, regulaciones sobre retardos, tiempos de comida, Ley Silla y multas prohibidas al salario.',
            'course_type' => 'induction',
            'video_url' => 'https://www.youtube.com/watch?v=5V920K64X54',
            'is_active' => true,
            'target_job_role_id' => null,
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

        // 2. Curso de Ventas (Cajero/Ventas - Puesto ID 5)
        DB::table('academy_courses')->insert([
            'tenant_id' => 1,
            'title' => 'Técnicas de Ventas y Atención al Cliente en DecorArte',
            'description' => 'Domina el arte de asesorar clientes en diseño de interiores, combinaciones de colores de temporada, cotizaciones rápidas, cobro eficiente y facturación directa en DecorArte.',
            'course_type' => 'training',
            'video_url' => 'https://www.youtube.com/watch?v=R9Z8b_zV5Wc',
            'is_active' => true,
            'target_job_role_id' => 5,
            'quiz_data' => json_encode([
                [
                    'question' => '¿Cuál es el primer paso en la venta consultiva de decoración y muebles?',
                    'options' => [
                        'Ofrecer el producto más costoso de inmediato.',
                        'Escucha activa para entender las necesidades de espacio, iluminación y estilo del cliente.',
                        'Presionar al cliente para que realice el pago rápido.'
                    ],
                    'correctAnswer' => 1
                ],
                [
                    'question' => 'Ante una inconformidad de cobro en caja de DecorArte, ¿cuál es la acción correcta?',
                    'options' => [
                        'Discutir con el cliente para defender los precios del sistema.',
                        'Mantener la calma, disculparse por el inconveniente, validar el ticket y aplicar la corrección correspondiente.',
                        'Pedirle al cliente que regrese al día siguiente.'
                    ],
                    'correctAnswer' => 1
                ]
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Curso de Almacén (Ayudante General - Puesto ID 6)
        DB::table('academy_courses')->insert([
            'tenant_id' => 1,
            'title' => 'Logística, PEPS y Orden en Bodega DecorArte',
            'description' => 'Aprende el método PEPS (Primeras Entradas, Primeras Salidas) para rotación de stock de decoración, prevención de mermas, uso de faja lumbar y orden en piso de venta.',
            'course_type' => 'training',
            'video_url' => 'https://www.youtube.com/watch?v=3-n5mO2W9yM',
            'is_active' => true,
            'target_job_role_id' => 6,
            'quiz_data' => json_encode([
                [
                    'question' => '¿Qué significa el método PEPS en el manejo de mercancía en bodega?',
                    'options' => [
                        'Primeras Entradas, Primeras Salidas (evitar que el stock viejo se dañe u olvide).',
                        'Productos Especiales Para Sucursal.',
                        'Pedidos Exclusivos Para Clientes.'
                    ],
                    'correctAnswer' => 0
                ],
                [
                    'question' => '¿Qué equipo es obligatorio utilizar al mover muebles pesados de exhibición?',
                    'options' => [
                        'Únicamente ropa casual cómoda.',
                        'Faja de soporte lumbar y calzado cerrado industrial.',
                        'No se requiere equipo especial.'
                    ],
                    'correctAnswer' => 1
                ]
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Curso de Liderazgo (Gerente - Puesto ID 1)
        DB::table('academy_courses')->insert([
            'tenant_id' => 1,
            'title' => 'Liderazgo, Gestión y Auditoría en DecorArte',
            'description' => 'Aprende a coordinar tu equipo de sucursal utilizando los paneles de Talent 360: monitor de actividad en tiempo real, administración de tolerancias LFT y liderazgo positivo.',
            'course_type' => 'promotion',
            'video_url' => 'https://www.youtube.com/watch?v=Z61Bv_DsgqQ',
            'is_active' => true,
            'target_job_role_id' => 1,
            'quiz_data' => json_encode([
                [
                    'question' => 'Si el monitor en tiempo real muestra a un colaborador inactivo por más de 15 minutos en turno laboral, ¿cómo debe actuar el Gerente?',
                    'options' => [
                        'Llamarle la atención de forma severa y pública.',
                        'Acercarse de forma asertiva para entender si hay un bloqueo (falta de insumos, duda técnica), ofrecer apoyo y reasignar tareas.',
                        'Ignorarlo y dejar que siga inactivo.'
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
        DB::table('academy_courses')->where('tenant_id', 1)->delete();
    }
};
