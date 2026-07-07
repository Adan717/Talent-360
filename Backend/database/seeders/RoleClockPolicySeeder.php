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
        // 11: Administrador Gerente
        RoleClockPolicy::updateOrCreate(
            ['job_role_id' => 11, 'tenant_id' => 1],
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

        // 12: Supervisor de Compras
        RoleClockPolicy::updateOrCreate(
            ['job_role_id' => 12, 'tenant_id' => 1],
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

        // 13: Supervisor de Ventas, 14: Supervisor de Producción
        $supervisores = [13, 14];
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

        // 15: Atención al Cliente, 16: Ayudante Integral, 17: Apoyo Eventual
        $operativos = [15, 16, 17];
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
