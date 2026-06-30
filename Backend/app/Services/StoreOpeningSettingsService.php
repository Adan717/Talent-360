<?php

namespace App\Services;

use App\Models\StoreOpeningSetting;

class StoreOpeningSettingsService
{
    public function getOpeningSettings($tenantId, $storeId = 1)
    {
        $settings = StoreOpeningSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->first();

        if (!$settings) {
            // Create default settings row
            $settings = StoreOpeningSetting::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'is_enabled' => true,
                'pre_opening_window_minutes' => 15,
                'absence_late_report_window_minutes' => 5,
                'early_clock_in_allowed_minutes' => 10,
                'allow_automatic_handoff' => true,
                'allow_late_if_before_opening' => true,
                'allow_store_closed_report' => true,
                'enable_amnesty_if_store_closed' => true,
                'require_opening_checklist' => true,
                'require_opening_roll_call' => true,
                'notify_admin_on_handoff' => true,
                'notify_supervisor_on_handoff' => true,
            ]);
        }

        return $settings;
    }
}
