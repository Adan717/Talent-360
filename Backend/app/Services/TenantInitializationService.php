<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TenantInitializationService
{
    /**
     * Inicializa las configuraciones por defecto en system_settings para un nuevo tenant.
     *
     * @param int $tenantId
     * @return void
     */
    public function initializeSettingsForTenant(int $tenantId)
    {
        $defaults = [
            // H2 (prueba en vivo 2026-07-29): el asistente de Giro Comercial —la puerta de
            // entrada del producto, que precarga puestos, tareas y cursos— NUNCA se abría solo
            // en una empresa nueva. `ClockController::getState` trae un fallback pensado para
            // que el wizard no reaparezca en empresas ya configuradas ("si el tenant tiene
            // job_roles, dalo por completado"), pero el alta SIEMBRA puestos por defecto, así
            // que ese fallback se cumplía desde el primer login. Al dejar la clave presente y
            // en `false`, el fallback (`!isset(...)`) ya no aplica a los tenants nuevos y sigue
            // vigente para los antiguos, que es justo para lo que se escribió.
            'onboarding_completed' => false,
            'storeSchedule' => [
                'openTime' => '08:00',
                'closeTime' => '18:00',
            ],
            // 10, igual que el default de `lft_settings.late_tolerance_minutes`, que es con lo
            // que el SERVIDOR decide el retardo. Nacía en 15: el dial le decía al colaborador
            // que seguía a tiempo hasta el minuto 15 mientras el servidor lo marcaba tarde
            // desde el 10. `/sync/state` además lo alinea en vivo por si llegan a diferir.
            'timeBankConfigs' => [
                'maxLateMinsAllowed' => 10,
            ],
            'leySillaConfig' => [
                'enabled' => true,
                'consecutiveMinutes' => 120,
                'breakMinutes' => 15,
            ],
            'clockOpConfig' => [
                'gpsValidationEnabled' => true,
                'gpsAlertRangeMeters' => 100,
                'allowManualCheckIn' => false,
                'arrivalWindowMins' => 30,
                'storeClosedReportDelayMins' => 0,
                'preOpeningAccessMins' => 60,
            ],
            'moduleCustomizations' => [
                'reloj' => [
                    'title' => 'Reloj Checador',
                    'desc' => 'Control de asistencia inteligente',
                    'iconName' => 'Clock',
                ]
            ],
            'hiddenMenuModules' => []
        ];

        foreach ($defaults as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key, 'tenant_id' => $tenantId],
                [
                    'value' => is_string($value) ? $value : json_encode($value),
                    'updated_at' => now(),
                ]
            );
        }

        // H12 (prueba en vivo 2026-07-29): el alta no sembraba `company_name`, así que quedaba
        // NULL y el frontend caía a un default hardcodeado — una empresa recién registrada
        // saludaba con "Bienvenido a DecorArte 360", el nombre de OTRA empresa, en su primera
        // pantalla. Se siembra con el nombre del propio tenant.
        //
        // Va fuera del bucle de $defaults a propósito: aquel usa updateOrInsert (re-aplica el
        // valor en cada llamada) y este dato es EDITABLE por el cliente desde Configuración —
        // una re-inicialización no debe revertir la marca que ya personalizó.
        $yaTieneNombre = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', 'company_name')
            ->exists();

        if (!$yaTieneNombre) {
            $nombreTenant = DB::table('tenants')->where('id', $tenantId)->value('name');
            if (!empty($nombreTenant)) {
                DB::table('system_settings')->insert([
                    'tenant_id' => $tenantId,
                    'key' => 'company_name',
                    'value' => json_encode($nombreTenant),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->seedPermissionCatalog($tenantId);
        $this->seedSeatedTask($tenantId);
    }

    /**
     * La tarea que se puede hacer SENTADO, para que el descanso de Ley Silla tenga con qué
     * arrancar. La migración 2026_07_22_000003 la sembró en las empresas que existían ese día y
     * nadie la creaba para las NUEVAS: en la prueba del dueño (empresa del 20 de agosto) el
     * modal de Ley Silla decía "No hay tareas configuradas para tomar sentado todavía" y el
     * único botón era "Cancelar descanso" — el descanso que la ley garantiza era imposible.
     */
    public function seedSeatedTask(int $tenantId): void
    {
        $existe = DB::table('tasks')
            ->where('tenant_id', $tenantId)
            ->where('title', 'Monitoreo de seguridad desde silla')
            ->exists();
        if ($existe) {
            return;
        }

        DB::table('tasks')->insert([
            'tenant_id' => $tenantId,
            'title' => 'Monitoreo de seguridad desde silla',
            'estimated_mins' => 15,
            'points' => 5,
            'priority' => 'normal',
            'category' => 'operativo',
            'target_type' => 'role',
            'validation_mode' => 'auto',
            'can_be_done_sitting' => true,
            'frequency' => 'Diaria',
            'evidence_type' => 'Supervisión directa',
            'is_auto_capture' => false,
            'is_validated' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * §65: siembra el catálogo de capacidades delegables para un tenant nuevo. No asigna
     * nada a puestos todavía (al crearse el tenant aún no hay job_roles) — eso lo hace el
     * administrador desde la matriz, o los defaults al configurar el nicho. El admin dueño
     * pasa siempre por bypass en PermissionMiddleware, así que nunca se queda fuera.
     */
    public function seedPermissionCatalog(int $tenantId): void
    {
        foreach (\App\Support\PermissionCatalog::DELEGABLE as $name => $description) {
            DB::table('permissions')->updateOrInsert(
                ['tenant_id' => $tenantId, 'name' => $name],
                ['description' => $description, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
