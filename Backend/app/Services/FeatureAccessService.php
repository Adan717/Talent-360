<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\CompanyFeature;
use Illuminate\Support\Facades\DB;

class FeatureAccessService
{
    public static function tenantHasFeature($tenantId, $featureKey)
    {
        // Tenant ID 1 (DecorArte) or subdomain talent360 is always fully unlocked
        if ((int)$tenantId === 1) {
            return true;
        }

        $tenant = Tenant::find($tenantId);
        if ($tenant) {
            if ($tenant->subdomain === 'talent360' || $tenant->plan === 'enterprise') {
                return true;
            }
            if ($tenant->isTrialActive()) {
                return true;
            }
        }

        // Check company_features row
        $companyFeature = CompanyFeature::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('feature_key', $featureKey)
            ->first();

        if ($companyFeature) {
            return (bool)$companyFeature->is_enabled;
        }

        // Default: check if is_premium global feature
        $globalFeature = DB::table('platform_features')->where('feature_key', $featureKey)->first();
        if ($globalFeature) {
            if (!$globalFeature->is_premium) {
                return true;
            }
            // Premium features require Pro or Enterprise plans
            if ($tenant && ($tenant->plan === 'pro' || $tenant->plan === 'enterprise')) {
                return true;
            }
        }

        return false;
    }
}
