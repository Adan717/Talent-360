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

        $tenant = Tenant::find($tenantId);
        $subdomain = $tenant ? $tenant->subdomain : 'demo';
        $domain = $subdomain . '.com';

        // 2. Usuarios de Prueba (Colaboradores)
        $users = [
            [
                'name' => 'Roberto Sánchez',
                'email' => 'roberto.sanchez@' . $domain,
                'password' => Hash::make('password123'),
                'role' => 'empleado',
                'job_role_id' => $puestoGerente->id ?? null,
                'tenant_id' => $tenantId,
            ],
            [
                'name' => 'María García',
                'email' => 'maria.garcia@' . $domain,
                'password' => Hash::make('password123'),
                'role' => 'empleado',
                'job_role_id' => $puestoVentas->id ?? null,
                'tenant_id' => $tenantId,
            ],
            [
                'name' => 'Carlos López',
                'email' => 'carlos.lopez@' . $domain,
                'password' => Hash::make('password123'),
                'role' => 'empleado',
                'job_role_id' => $puestoCaja->id ?? null,
                'tenant_id' => $tenantId,
            ],
            [
                'name' => 'Ana Martínez',
                'email' => 'ana.martinez@' . $domain,
                'password' => Hash::make('password123'),
                'role' => 'empleado',
                'job_role_id' => $puestoVentas->id ?? null,
                'tenant_id' => $tenantId,
            ],
            [
                'name' => 'Luis Fernández',
                'email' => 'luis.fernandez@' . $domain,
                'password' => Hash::make('password123'),
                'role' => 'empleado',
                'job_role_id' => $puestoAlmacen->id ?? null,
                'tenant_id' => $tenantId,
            ]
        ];

        foreach ($users as $u) {
            if (!User::where('email', $u['email'])->exists()) {
                User::create($u);
            }
        }

        // Import necessary models for tasks and routines
        // 3. Checklist de Apertura
        $routineApertura = \App\Models\Routine::create([
            'title' => 'Checklist Diario de Apertura',
            'target_role_id' => $puestoGerente->id,
            'trigger' => 'apertura',
            'assign_mode' => 'fijo',
            'tenant_id' => $tenantId
        ]);

        $tasksApertura = [
            'Desactivar alarma perimetral y encender switch principal',
            'Verificar funcionamiento de las luces del piso de ventas',
            'Realizar conteo del fondo de caja',
            'Encender equipos de refrigeración/clima',
            'Tomar foto de la fachada frontal limpia y despejada'
        ];

        foreach ($tasksApertura as $t) {
            $task = \App\Models\Task::create([
                'title' => $t,
                'priority' => 'bloqueante',
                'target_type' => 'role',
                'target_id' => $puestoGerente->id,
                'assistant_type' => str_contains($t, 'foto') ? 'evidencia_foto' : 'ninguno',
                'tenant_id' => $tenantId
            ]);
            $routineApertura->tasks()->attach($task->id);
        }

        // 4. Checklist de Operación
        $routineOperacion = \App\Models\Routine::create([
            'title' => 'Checklist Diario de Operación',
            'target_role_id' => $puestoGerente->id,
            'trigger' => 'hora_fija',
            'assign_mode' => 'fijo',
            'tenant_id' => $tenantId
        ]);

        $tasksOperacion = [
            'Recorrer pasillos asegurando que el piso esté libre de cajas',
            'Alinear los precios en las etiquetas de los domos principales',
            'Validar que el personal esté portando el gafete y uniforme limpios',
            'Revisar stock de bolsas de empaque en las cajas'
        ];

        foreach ($tasksOperacion as $t) {
            $task = \App\Models\Task::create([
                'title' => $t,
                'priority' => 'normal',
                'target_type' => 'role',
                'target_id' => $puestoGerente->id,
                'assistant_type' => 'ninguno',
                'tenant_id' => $tenantId
            ]);
            $routineOperacion->tasks()->attach($task->id);
        }

        // 5. Checklist de Cierre
        $routineCierre = \App\Models\Routine::create([
            'title' => 'Checklist Diario de Cierre',
            'target_role_id' => $puestoGerente->id,
            'trigger' => 'cierre',
            'assign_mode' => 'fijo',
            'tenant_id' => $tenantId
        ]);

        $tasksCierre = [
            'Ejecutar corte X y validar los retiros parciales (Arqueos)',
            'Hacer el cierre de terminales bancarias y adjuntar voucher',
            'Guardar el efectivo en la tómbola',
            'Apagar aires acondicionados y luces',
            'Activar alarma perimetral y asegurar puertas'
        ];

        foreach ($tasksCierre as $t) {
            $task = \App\Models\Task::create([
                'title' => $t,
                'priority' => 'bloqueante',
                'target_type' => 'role',
                'target_id' => $puestoGerente->id,
                'assistant_type' => 'ninguno',
                'tenant_id' => $tenantId
            ]);
            $routineCierre->tasks()->attach($task->id);
        }
    }
}
