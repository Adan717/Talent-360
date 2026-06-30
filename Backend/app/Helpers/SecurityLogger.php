<?php
 
namespace App\Helpers;
 
use App\Models\SaasAuditLog;
 
class SecurityLogger
{
    /**
     * Registra un evento de ciberseguridad o facturación en saas_audit_logs.
     */
    public static function log($eventType, $description, $tenantId = null, $userId = null)
    {
        try {
            $user = auth()->user();
            SaasAuditLog::create([
                'tenant_id' => $tenantId ?? ($user ? $user->tenant_id : null),
                'user_id' => $userId ?? ($user ? $user->id : null),
                'event_type' => $eventType,
                'description' => $description,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error writing to saas_audit_logs: " . $e->getMessage());
        }
    }
}
