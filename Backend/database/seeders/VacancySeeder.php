<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VacancySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('vacancies')->delete();

        $vacancies = [
            [
                'job_role_id' => 11,
                'title' => 'Gerente de Sucursal',
                'description' => 'Buscamos un líder estratégico con experiencia en retail, capaz de gestionar equipos, metas de ventas y la operación integral de la tienda.',
                'requirements' => "Licenciatura trunca o terminada.\nExperiencia mínima de 2 años en gerencia.\nManejo de KPIs y personal.",
                'is_active' => true,
                'is_hidden' => false,
                'image_url' => 'https://images.unsplash.com/photo-1556761175-4b46a572b786?auto=format&fit=crop&q=80&w=1000',
                'work_type' => 'Tiempo Completo',
                'schedule' => 'Lunes a Sábado 9:00 AM a 7:00 PM',
                'salary_range' => '$12,000 - $15,000 Mensuales',
            ],
            [
                'job_role_id' => 12,
                'title' => 'Supervisor(a) de Tienda y Compras',
                'description' => 'Responsable de la supervisión de piso de ventas, atención a proveedores, resurtido de mercancía y apoyo general a gerencia.',
                'requirements' => "Experiencia en piso de ventas y almacén.\nHabilidad de negociación con proveedores.\nManejo de inventarios.",
                'is_active' => true,
                'is_hidden' => false,
                'image_url' => 'https://images.unsplash.com/photo-1604719312566-8912e9227c6a?auto=format&fit=crop&q=80&w=1000',
                'work_type' => 'Tiempo Completo',
                'schedule' => 'Lunes a Sábado 9:00 AM a 7:00 PM',
                'salary_range' => '$8,000 - $10,000 Mensuales',
            ],
            [
                'job_role_id' => 13,
                'title' => 'Supervisor(a) de Cajas',
                'description' => 'Asegurar el correcto funcionamiento de las cajas, realización de arqueos, cortes y atención a quejas o devoluciones.',
                'requirements' => "Experiencia de 1 año como cajero principal o supervisor.\nManejo avanzado de terminales y efectivo.\nHonestidad y organización.",
                'is_active' => true,
                'is_hidden' => false,
                'image_url' => 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?auto=format&fit=crop&q=80&w=1000',
                'work_type' => 'Tiempo Completo',
                'schedule' => 'Lunes a Sábado 9:00 AM a 7:00 PM',
                'salary_range' => '$7,500 - $8,500 Mensuales',
            ],
            [
                'job_role_id' => 14,
                'title' => 'Supervisor(a) de Producción (Taller)',
                'description' => 'Coordinar las actividades del taller, asegurando la calidad y tiempos de entrega de la producción de artículos Talent360.',
                'requirements' => "Habilidad manual y supervisión de procesos de manufactura ligera.\nManejo de personal operativo.",
                'is_active' => true,
                'is_hidden' => false,
                'image_url' => 'https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?auto=format&fit=crop&q=80&w=1000',
                'work_type' => 'Tiempo Completo',
                'schedule' => 'Lunes a Sábado 8:00 AM a 6:00 PM',
                'salary_range' => '$7,500 - $8,500 Mensuales',
            ],
            [
                'job_role_id' => 15,
                'title' => 'Cajero(a) de Sucursal',
                'description' => 'Atención cálida y rápida en la línea de cajas, cobro de mercancía y empaque.',
                'requirements' => "Experiencia de 6 meses en cajas o sin experiencia con actitud.\nSecundaria terminada.",
                'is_active' => true,
                'is_hidden' => false,
                'image_url' => 'https://images.unsplash.com/photo-1600880292203-757bb62b4baf?auto=format&fit=crop&q=80&w=1000',
                'work_type' => 'Medio Tiempo / Tiempo Completo',
                'schedule' => 'L-D con descanso entre semana. Turnos rotativos.',
                'salary_range' => '$1,500 - $1,800 Semanales',
            ],
            [
                'job_role_id' => 16,
                'title' => 'Ayudante Integral (Piso y Almacén)',
                'description' => 'Apoyo dinámico en limpieza, acomodo de mercancía, carga y descarga de inventario y atención a dudas de clientes.',
                'requirements' => "Energía y actitud de servicio.\nDisponibilidad para esfuerzo físico medio.",
                'is_active' => true,
                'is_hidden' => false,
                'image_url' => 'https://images.unsplash.com/photo-1587293852726-70cdb56c28ea?auto=format&fit=crop&q=80&w=1000',
                'work_type' => 'Tiempo Completo',
                'schedule' => 'Lunes a Sábado 9:00 AM a 7:00 PM',
                'salary_range' => '$1,400 - $1,600 Semanales',
            ],
            [
                'job_role_id' => 17,
                'title' => 'Apoyo Operativo de Temporada',
                'description' => 'Vacante exclusiva para reingresos (personas que ya trabajaron con nosotros) para apoyo en temporada navideña o alta.',
                'requirements' => "Haber trabajado previamente en Talent360.\nBuena referencia de salida.",
                'is_active' => true,
                'is_hidden' => true, // Oculta al publico general
                'image_url' => 'https://images.unsplash.com/photo-1512389142860-9c449e58a543?auto=format&fit=crop&q=80&w=1000',
                'work_type' => 'Temporal / Por Evento',
                'schedule' => 'Flexible según temporada',
                'salary_range' => 'Pago por hora / Ajustable',
            ]
        ];

        foreach ($vacancies as $vacancy) {
            $vacancy['created_at'] = now();
            $vacancy['updated_at'] = now();
            $vacancy['tenant_id'] = 1;
            DB::table('vacancies')->insert($vacancy);
        }
    }
}
