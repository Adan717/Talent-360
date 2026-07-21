<?php

namespace App\Services;

use App\Models\TenantOfflineSecret;
use Illuminate\Support\Facades\Crypt;

class OfflineSignatureService
{
    /**
     * Devuelve el secreto HMAC vigente del tenant, generando uno nuevo si no existe
     * o si el último ya expiró. Se rota cada 24h (fila nueva, la anterior se conserva
     * para la ventana de gracia de verifyStamp()).
     *
     * @return array{secret: string, issued_at: \Illuminate\Support\Carbon}
     */
    public function getOrCreateCurrentSecret(int $tenantId): array
    {
        $current = TenantOfflineSecret::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if (!$current) {
            $plain = base64_encode(random_bytes(32));

            $current = TenantOfflineSecret::create([
                'tenant_id' => $tenantId,
                'secret' => Crypt::encryptString($plain),
                'expires_at' => now()->addDay(),
            ]);

            return ['secret' => $plain, 'issued_at' => $current->created_at];
        }

        return ['secret' => Crypt::decryptString($current->secret), 'issued_at' => $current->created_at];
    }

    /**
     * Verifica un offline_stamp (HMAC-SHA256) contra el secreto vigente del tenant,
     * o contra uno que haya expirado hace ≤1h (ventana de gracia por rotación).
     */
    public function verifyStamp(int $tenantId, string $stamp, string $message): bool
    {
        $candidates = TenantOfflineSecret::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('expires_at', '>', now()->subHour())
            ->get();

        foreach ($candidates as $row) {
            $plain = Crypt::decryptString($row->secret);
            $expected = hash_hmac('sha256', $message, $plain);

            if (hash_equals($expected, $stamp)) {
                return true;
            }
        }

        return false;
    }
}
