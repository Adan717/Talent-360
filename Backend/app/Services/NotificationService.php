<?php

namespace App\Services;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    protected ?Messaging $messaging = null;

    public function __construct()
    {
        try {
            if (class_exists(\Kreait\Firebase\Contract\Messaging::class) && app()->bound(Messaging::class)) {
                $this->messaging = app(Messaging::class);
            }
        } catch (\Exception $e) {
            Log::warning('Firebase messaging not initialized: ' . $e->getMessage());
        }
    }

    /**
     * Send notification to a specific user by ID.
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): bool
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            Log::warning("Cannot send notification. User ID {$userId} not found.");
            return false;
        }

        if (!$user->fcm_token) {
            Log::info("User ID {$userId} does not have an FCM token. Logged notification: [{$title}] {$body}");
            return true; // Return true as it was handled without crash
        }

        return $this->sendRawNotification($user->fcm_token, $title, $body, $data);
    }

    /**
     * Send notification to all users with a specific role WITHIN a tenant.
     *
     * Merge F3 (seguridad, R69): el `tenant_id` es OBLIGATORIO — sin él, la consulta seleccionaba
     * destinatarios sólo por `role` y difundía el push a los admin/supervisor de TODOS los tenants
     * (fuga cross-tenant de estado operativo + PII).
     */
    public function sendToRole(int $tenantId, string $role, string $title, string $body, array $data = []): bool
    {
        $tokens = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('role', $role)
            ->whereNotNull('fcm_token')
            ->pluck('fcm_token')
            ->toArray();

        if (empty($tokens)) {
            Log::info("No users with role {$role} found with FCM tokens. Logged notification: [{$title}] {$body}");
            return true;
        }

        $success = true;
        foreach ($tokens as $token) {
            if (!$this->sendRawNotification($token, $title, $body, $data)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * §39: enviar a todos los usuarios que ocupan un PUESTO específico (job_role_id)
     * dentro de un tenant. Distinto de sendToRole(), que filtra por la columna gruesa
     * users.role (admin/supervisor/empleado) — aquí resolvemos por employees.job_role_id,
     * que es el puesto real que RRHH mantiene (ej. "Compras", "Producción").
     */
    public function sendToJobRole(int $jobRoleId, int $tenantId, string $title, string $body, array $data = []): bool
    {
        $userIds = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('job_role_id', $jobRoleId)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->all();

        if (empty($userIds)) {
            Log::info("No users with job_role_id {$jobRoleId} in tenant {$tenantId}. Logged notification: [{$title}] {$body}");
            return true;
        }

        $success = true;
        foreach ($userIds as $userId) {
            if (!$this->sendToUser($userId, $title, $body, $data)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Notifica a los mandos (admin/supervisor) DE UN TENANT. Helper compartido (R80): antes cada
     * módulo duplicaba este loop. Se usa sendToUser por id (y no sendToRole) para conservar el log
     * por-destinatario aun sin fcm_token — en este entorno FCM se simula y ese log es la evidencia.
     */
    public function sendToTenantAdmins(int $tenantId, string $title, string $body, array $data = []): void
    {
        $adminIds = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['admin', 'supervisor'])
            ->pluck('id');

        foreach ($adminIds as $adminId) {
            $this->sendToUser((int) $adminId, $title, $body, $data);
        }
    }

    /**
     * Broadcast notification to all users of a tenant.
     */
    public function sendBroadcast(int $tenantId, string $title, string $body, array $data = []): bool
    {
        $tokens = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('fcm_token')
            ->pluck('fcm_token')
            ->toArray();

        if (empty($tokens)) {
            Log::info("No users in tenant {$tenantId} found with FCM tokens. Logged notification: [{$title}] {$body}");
            return true;
        }

        $success = true;
        foreach ($tokens as $token) {
            if (!$this->sendRawNotification($token, $title, $body, $data)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Send raw notification via FCM.
     */
    protected function sendRawNotification(string $token, string $title, string $body, array $data = []): bool
    {
        Log::info("Sending FCM Notification to token [{$token}]: [{$title}] {$body}", $data);

        if (!$this->messaging) {
            Log::info("Firebase Messaging client not configured. Notification was simulated successfully.");
            return true;
        }

        try {
            $message = CloudMessage::withTarget('token', $token)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $this->messaging->send($message);
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send Firebase Notification: " . $e->getMessage());
            return false;
        }
    }
}
