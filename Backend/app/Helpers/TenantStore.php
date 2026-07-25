<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * "La sucursal de este tenant" (Ronda 52).
 *
 * POR QUÉ EXISTE: antes no había entidad `stores` y todo el backend caía a `$storeId = 1`
 * hardcodeado (7 firmas de servicio + 6 lecturas del request). Eso funcionaba **por accidente**:
 * `store_id` valía 1 en todos los tenants porque no era un id global, sino "la tienda 1 de cada
 * empresa". Con `stores` los ids son globales, así que un `1` hardcodeado apuntaría a la sucursal
 * del tenant 1 desde cualquier tenant. Este helper sustituye a ese default.
 *
 * Mismo patrón que `TenantTimezone` (R25): un solo resolver, en vez de la lógica repetida y con
 * robustez desigual por medio módulo.
 *
 * Siembra la sucursal si el tenant no tiene (tenants nuevos, creados después de la migración de
 * R52): así ningún camino de alta de tenant tiene que acordarse de crearla. La siembra es race-safe
 * por la lección de R8/R51 — `insertOrIgnore` (ON CONFLICT DO NOTHING) y NO try/catch, porque un
 * INSERT que falla dentro de una transacción de Postgres la ABORTA (25P02) incluso si atrapas la
 * excepción, y los callers de apertura envuelven todo esto en `DB::transaction`. El unique
 * (tenant_id, name) de `stores` es lo que hace que la carrera se resuelva sola.
 *
 * ALCANCE HONESTO de esa race-safety: `insertOrIgnore` sólo se traga los conflictos de UNIQUE. Un
 * `tenant_id` inexistente viola la FK y SÍ lanza (23503), abortando la transacción del caller. Hoy
 * no es alcanzable —la única entrada es `$user->tenant_id ?? 1`, `users.tenant_id` es FK, y el
 * tenant 1 lo crea siempre la migración `2026_06_28_030000`— pero si algún día se llama a esto con
 * un tenant arbitrario, hay que validarlo antes.
 */
class TenantStore
{
    public const DEFAULT_NAME = 'Sucursal Principal';

    /**
     * Id de la sucursal por defecto del tenant. La siembra si no existe.
     *
     * "Por defecto" = la de menor id, que con la migración de R52 es la única. Cuando exista
     * multi-sucursal de verdad, esto es lo que habrá que sustituir por la sucursal del contexto
     * (kiosko, usuario o petición) — y ese es justo el punto: hoy hay UN sitio que cambiar, no 13.
     *
     * Sin cache a propósito: es un lookup por índice y el ahorro no compensa tener estado estático
     * que sobrevive entre tests y dentro de un worker de cola de vida larga.
     */
    public static function defaultIdFor($tenantId): int
    {
        $tenantId = (int) $tenantId;

        $id = static::leer($tenantId);

        if ($id === null) {
            DB::table('stores')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'name' => self::DEFAULT_NAME,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Relectura: si perdimos la carrera, aquí está la sucursal del ganador.
            $id = static::leer($tenantId);
        }

        return $id;
    }

    protected static function leer(int $tenantId): ?int
    {
        // withoutGlobalScopes vía query builder crudo: este helper corre también desde contextos sin
        // auth (jobs, comandos), donde el TenantScope resolvería mal (ver R41).
        $id = DB::table('stores')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
