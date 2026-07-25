<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * §46: caché por tenant de la configuración casi-estática que alimenta /sync/state
 * (puestos, permisos, reglas RBAC, políticas de reloj). Centraliza la clave, el TTL y
 * la invalidación en un solo lugar, para que ningún punto de escritura tenga que
 * conocer el detalle del caché — solo llama a forget($tenantId).
 */
class TenantConfigCache
{
    /** Red de seguridad: aunque se olvide invalidar en algún punto, el caché caduca. */
    public const TTL_SECONDS = 300;

    public static function key(int $tenantId): string
    {
        return "tenant.{$tenantId}.sync_static_config";
    }

    public static function remember(int $tenantId, callable $callback): array
    {
        return Cache::remember(self::key($tenantId), self::TTL_SECONDS, $callback);
    }

    /** Invalida la config cacheada de un tenant — se llama al editar puestos/permisos/etc. */
    public static function forget(int $tenantId): void
    {
        Cache::forget(self::key($tenantId));
    }
}
