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
    }
}
