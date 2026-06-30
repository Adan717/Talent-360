<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleNormalizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

        \Illuminate\Support\Facades\DB::table('job_roles')->delete();

        $roles = [
            ['id' => 1, 'name' => 'Administrador / Gerente', 'area' => 'Gerencia', 'esAperturador' => true, 'portadorLlaves' => 'ambos', 'jerarquiaLlaves' => 1, 'tiempoTolerancia' => 15, 'requiereJustificante' => false, 'puedeEmitirAvisos' => true],
            ['id' => 2, 'name' => 'Sup. Tienda y Compras', 'area' => 'Piso', 'esAperturador' => true, 'portadorLlaves' => 'ambos', 'jerarquiaLlaves' => 2, 'tiempoTolerancia' => 10, 'requiereJustificante' => true, 'puedeEmitirAvisos' => true],
            ['id' => 3, 'name' => 'Sup. Cajas', 'area' => 'Cajas', 'esAperturador' => false, 'portadorLlaves' => 'cierre', 'jerarquiaLlaves' => 3, 'tiempoTolerancia' => 10, 'requiereJustificante' => true, 'puedeEmitirAvisos' => true],
            ['id' => 4, 'name' => 'Sup. Producción', 'area' => 'Taller', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'jerarquiaLlaves' => 3, 'tiempoTolerancia' => 10, 'requiereJustificante' => true, 'puedeEmitirAvisos' => true],
            ['id' => 5, 'name' => 'Cajeros', 'area' => 'Cajas', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'jerarquiaLlaves' => 4, 'tiempoTolerancia' => 5, 'requiereJustificante' => true, 'puedeEmitirAvisos' => false],
            ['id' => 6, 'name' => 'Ayudante Integral', 'area' => 'Piso', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'jerarquiaLlaves' => 5, 'tiempoTolerancia' => 5, 'requiereJustificante' => true, 'puedeEmitirAvisos' => false],
            ['id' => 7, 'name' => 'Apoyo Eventual (Ex-Colaborador)', 'area' => 'Temporada', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'jerarquiaLlaves' => 6, 'tiempoTolerancia' => 5, 'requiereJustificante' => true, 'puedeEmitirAvisos' => false],
        ];

        foreach ($roles as $role) {
            $role['created_at'] = now();
            $role['updated_at'] = now();
            \Illuminate\Support\Facades\DB::table('job_roles')->insert($role);
        }

        // Mapear usuarios actuales a los nuevos puestos
        $mapping = [
            'francisco@talent360.com' => 1, // Admin / Gerente
            'alberto@talent360.com' => 2, // Sup. Tienda y Compras
            'david@talent360.com' => 3, // Sup. Cajas
            'guillermo@talent360.com' => 4, // Sup. Producción
            'eduardo@talent360.com' => 5, // Cajeros
            'juancarlos@talent360.com' => 5, // Cajeros
        ];

        $users = \Illuminate\Support\Facades\DB::table('users')->get();
        foreach ($users as $u) {
            $newRoleId = $mapping[$u->email] ?? 6; // Por defecto Ayudante Integral
            \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->update(['job_role_id' => $newRoleId]);
        }

        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    }
}
