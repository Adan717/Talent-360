<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RoleClockPolicy;

class RoleClockPolicySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1: Administrador / Gerente
        RoleClockPolicy::updateOrCreate(
            ['job_role_id' => 1, 'tenant_id' => 1],
            [
                'policy_name' => 'Perfil Ejecutivo',
                'config' => [
                    'tolerancia_retardo_mins' => 15,
                    'requiere_evaluacion_salida' => false,
                    'puede_abrir_sucursal' => true,
                    'tiene_boton_panico' => true,
                    'puede_usar_kiosko' => true,
                    'minutos_comida' => 60,
                    'paseDeLista' => true,
                ]
            ]
        );

        // 2: Sup. Tienda y Compras
        RoleClockPolicy::updateOrCreate(
            ['job_role_id' => 2, 'tenant_id' => 1],
            [
                'policy_name' => 'Perfil Supervisor Maestro',
                'config' => [
                    'tolerancia_retardo_mins' => 10,
                    'requiere_evaluacion_salida' => false,
                    'puede_abrir_sucursal' => true,
                    'tiene_boton_panico' => true,
                    'puede_usar_kiosko' => true,
                    'minutos_comida' => 45,
                    'paseDeLista' => true,
                ]
            ]
        );

        // 3: Sup. Cajas, 4: Sup. Producción
        $supervisores = [3, 4];
        foreach ($supervisores as $roleId) {
            RoleClockPolicy::updateOrCreate(
                ['job_role_id' => $roleId, 'tenant_id' => 1],
                [
                    'policy_name' => 'Perfil Supervisor Área',
                    'config' => [
                        'tolerancia_retardo_mins' => 10,
                        'requiere_evaluacion_salida' => true,
                        'puede_abrir_sucursal' => false,
                        'tiene_boton_panico' => true,
                        'puede_usar_kiosko' => true,
                        'minutos_comida' => 45,
                        'paseDeLista' => true,
                    ]
                ]
            );
        }

        // 5: Cajeros, 6: Ayudante Integral
        $operativos = [5, 6];
        foreach ($operativos as $roleId) {
            RoleClockPolicy::updateOrCreate(
                ['job_role_id' => $roleId, 'tenant_id' => 1],
                [
                    'policy_name' => 'Perfil Operativo',
                    'config' => [
                        'tolerancia_retardo_mins' => 10,
                        'requiere_evaluacion_salida' => true,
                        'puede_abrir_sucursal' => false,
                        'tiene_boton_panico' => false,
                        'puede_usar_kiosko' => true,
                        'minutos_comida' => 30,
                        'paseDeLista' => true,
                    ]
                ]
            );
        }
    }
}
