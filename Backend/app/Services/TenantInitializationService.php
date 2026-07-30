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
            'timeBankConfigs' => [
                'maxLateMinsAllowed' => 15,
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
