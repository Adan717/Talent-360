<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\JobRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Solo inyectar si ya se ha creado un Tenant (el contexto actual)
        $tenantId = session('tenant_id');
        if (!$tenantId) {
            return;
        }

        // 1. Roles de Trabajo (Puestos)
        $roles = [
            ['name' => 'Gerente de Sucursal', 'description' => 'Responsable general de operaciones y ventas.', 'area' => 'Gerencia', 'esAperturador' => true, 'portadorLlaves' => 'ambos', 'nivel_mando' => 1],
            ['name' => 'Cajero(a)', 'description' => 'Atención al cliente y cobro en caja.', 'area' => 'Cajas', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'nivel_mando' => 4],
            ['name' => 'Asesor de Ventas', 'description' => 'Atención en piso y venta directa.', 'area' => 'Piso', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'nivel_mando' => 4],
            ['name' => 'Almacenista', 'description' => 'Control de inventarios y bodega.', 'area' => 'Almacén', 'esAperturador' => false, 'portadorLlaves' => 'ninguno', 'nivel_mando' => 4],
        ];

        $roleModels = [];
        foreach ($roles as $r) {
            $roleModels[$r['name']] = JobRole::create([
                'name' => $r['name'],
                'description' => $r['description'],
                'area' => $r['area'],
                'esAperturador' => $r['esAperturador'],
                'portadorLlaves' => $r['portadorLlaves'],
                'nivel_mando' => $r['nivel_mando'],
                'tenant_id' => $tenantId
            ]);
        }

        $puestoGerente = $roleModels['Gerente de Sucursal'];
        $puestoVentas = $roleModels['Asesor de Ventas'];
        $puestoCaja = $roleModels['Cajero(a)'];
        $puestoAlmacen = $roleModels['Almacenista'];

        // Establecer Jerarquía (Reportar a Gerente de Sucursal) y Organigrama
        if ($puestoGerente) {
            if ($puestoVentas) {
                $puestoVentas->update([
                    'reports_to_role_id' => $puestoGerente->id,
                    'reports_to_role_ids' => [$puestoGerente->id],
                    'org_parent_role_id' => $puestoGerente->id,
                    'nivel_mando' => 4
                ]);
            }
            if ($puestoCaja) {
                $puestoCaja->update([
                    'reports_to_role_id' => $puestoGerente->id,
                    'reports_to_role_ids' => [$puestoGerente->id],
                    'org_parent_role_id' => $puestoGerente->id,
                    'nivel_mando' => 4
                ]);
            }
            if ($puestoAlmacen) {
                $puestoAlmacen->update([
                    'reports_to_role_id' => $puestoGerente->id,
                    'reports_to_role_ids' => [$puestoGerente->id],
                    'org_parent_role_id' => $puestoGerente->id,
                    'nivel_mando' => 4
                ]);
            }
        }

        // 1b. Crear políticas de reloj (RoleClockPolicy) por defecto para cada puesto de trabajo
        if ($puestoGerente) {
            \App\Models\RoleClockPolicy::create([
                'job_role_id' => $puestoGerente->id,
                'policy_name' => 'Perfil Ejecutivo',
                'tenant_id' => $tenantId,
                'config' => [
                    'tolerancia_retardo_mins' => 15,
                    'requiere_evaluacion_salida' => false,
                    'puede_abrir_sucursal' => true,
                    'tiene_boton_panico' => true,
                    'puede_usar_kiosko' => true,
                    'minutos_comida' => 60,
                    'paseDeLista' => true,
                ]
            ]);
        }

        if ($puestoVentas) {
            \App\Models\RoleClockPolicy::create([
                'job_role_id' => $puestoVentas->id,
                'policy_name' => 'Perfil Operativo Ventas',
                'tenant_id' => $tenantId,
                'config' => [
                    'tolerancia_retardo_mins' => 10,
                    'requiere_evaluacion_salida' => true,
                    'puede_abrir_sucursal' => false,
                    'tiene_boton_panico' => false,
                    'puede_usar_kiosko' => true,
                    'minutos_comida' => 45,
                    'paseDeLista' => true,
                ]
            ]);
        }

        if ($puestoCaja) {
            \App\Models\RoleClockPolicy::create([
                'job_role_id' => $puestoCaja->id,
                'policy_name' => 'Perfil Cajero',
                'tenant_id' => $tenantId,
                'config' => [
                    'tolerancia_retardo_mins' => 10,
                    'requiere_evaluacion_salida' => true,
                    'puede_abrir_sucursal' => false,
                    'tiene_boton_panico' => true,
                    'puede_usar_kiosko' => true,
                    'minutos_comida' => 30,
                    'paseDeLista' => true,
                ]
            ]);
        }

        if ($puestoAlmacen) {
            \App\Models\RoleClockPolicy::create([
                'job_role_id' => $puestoAlmacen->id,
                'policy_name' => 'Perfil Almacén',
                'tenant_id' => $tenantId,
                'config' => [
                    'tolerancia_retardo_mins' => 10,
                    'requiere_evaluacion_salida' => true,
                    'puede_abrir_sucursal' => false,
                    'tiene_boton_panico' => false,
                    'puede_usar_kiosko' => true,
                    'minutos_comida' => 45,
                    'paseDeLista' => true,
                ]
            ]);
        }

        // Las secciones de usuarios y checklists de prueba quedan desactivadas para mantener la base de datos limpia de datos demo.
        /*
        $tenant = Tenant::find($tenantId);
        $subdomain = $tenant ? $tenant->subdomain : 'demo';
        $domain = $subdomain . '.com';

        // 2. Usuarios de Prueba (Colaboradores)
        $users = [
            ...
        ];
        */
    }
}
