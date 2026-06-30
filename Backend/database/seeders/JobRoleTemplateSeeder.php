<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class JobRoleTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // Oficina
            [
                'name' => 'Gerente General',
                'area' => 'Dirección',
                'industry' => 'oficina',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 15,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Director de Operaciones',
                'area' => 'Operaciones',
                'industry' => 'oficina',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 15,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Recepcionista',
                'area' => 'Administración',
                'industry' => 'oficina',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Contador',
                'area' => 'Finanzas',
                'industry' => 'oficina',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Recursos Humanos',
                'area' => 'Capital Humano',
                'industry' => 'oficina',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Retail
            [
                'name' => 'Supervisor de Tienda',
                'area' => 'Operaciones Retail',
                'industry' => 'retail',
                'default_schedule_start' => '08:00',
                'default_schedule_end' => '17:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Supervisor de Cajas',
                'area' => 'Cajas',
                'industry' => 'retail',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Cajero',
                'area' => 'Cajas',
                'industry' => 'retail',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Ayudante de Ventas',
                'area' => 'Ventas',
                'industry' => 'retail',
                'default_schedule_start' => '10:00',
                'default_schedule_end' => '19:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Almacenista',
                'area' => 'Logística',
                'industry' => 'retail',
                'default_schedule_start' => '07:00',
                'default_schedule_end' => '16:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Restaurante
            [
                'name' => 'Cocinero',
                'area' => 'Cocina',
                'industry' => 'restaurante',
                'default_schedule_start' => '12:00',
                'default_schedule_end' => '21:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 45,
                'is_opener' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Mesero',
                'area' => 'Servicio',
                'industry' => 'restaurante',
                'default_schedule_start' => '13:00',
                'default_schedule_end' => '22:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 45,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('job_role_templates')->insert($templates);
    }
}
