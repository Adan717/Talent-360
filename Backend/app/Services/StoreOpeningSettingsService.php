<?php

namespace App\Services;

use App\Helpers\TenantStore;
use App\Models\StoreOpeningSetting;
use Illuminate\Support\Facades\DB;

class StoreOpeningSettingsService
{
    /**
     * Ajustes de apertura del (tenant, tienda). Los siembra con defaults si aún no existen.
     *
     * Race-safe (R51). Antes era un check-then-insert con `create()`: dos requests concurrentes
     * creaban DOS filas y `->first()` (sin ORDER BY) pasaba a ser no determinista — en Postgres un
     * UPDATE mueve la fila al final del heap, así que tras `updateSettings` la siguiente lectura
     * podía devolver la otra fila y los ajustes del admin "desaparecían".
     *
     * Se usa `insertOrIgnore` (ON CONFLICT DO NOTHING / INSERT OR IGNORE) y NO try/catch, por la
     * lección de R8: un INSERT que falla dentro de una transacción de Postgres la ABORTA
     * (SQLSTATE 25P02) y rompe toda lectura posterior — y TODOS los callers de apertura envuelven
     * esto en `DB::transaction` (`StoreOpeningService::openStoreAndClockIn`, `reportStoreStillClosed`,
     * `StoreOpeningHandoffService::reportOpeningAbsence`/`reportOpeningLate`). Ante una carrera el
     * perdedor no crea nada y ambos releen la fila del ganador.
     *
     * SUPUESTO: aislamiento **READ COMMITTED** (el default de Postgres; no se sobreescribe en
     * `config/database.php`). Sobre él la relectura NUNCA devuelve null: `ON CONFLICT DO NOTHING` se
     * bloquea sobre la fila conflictiva no committeada y, si el otro gana, nuestro SELECT posterior
     * —con snapshot fresco por statement, aun dentro de una transacción— la ve. Los callers
     * desreferencian el resultado sin comprobar null (`StoreOpeningService:160`,
     * `StoreOpeningHandoffService:95,156`), así que si alguien pusiera REPEATABLE READ/SERIALIZABLE
     * habría que revisar esto.
     */
    public function getOpeningSettings($tenantId, $storeId = null)
    {
        // R52: el default era `1`. Funcionaba por accidente (store_id valía 1 en todos los tenants
        // porque no era un id global). Con la entidad `stores` los ids son globales, así que un 1
        // hardcodeado apuntaría a la sucursal del TENANT 1 desde cualquier tenant — y este método se
        // llama sin storeId desde el panel del admin (`StoreOpeningController:60,88`).
        $storeId = $storeId ?? TenantStore::defaultIdFor($tenantId);

        $settings = $this->leer($tenantId, $storeId);

        if ($settings) {
            return $settings;
        }

        DB::table('store_opening_settings')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'company_id' => 1,
            'store_id' => $storeId,
            'pre_opening_window_minutes' => 15,
            'absence_late_report_window_minutes' => 5,
            'allow_automatic_handoff' => true,
            'allow_late_if_before_opening' => true,
            'allow_store_closed_report' => true,
            'enable_amnesty_if_store_closed' => true,
            'require_opening_checklist' => true,
            'require_opening_roll_call' => true,
            'notify_admin_on_handoff' => true,
            'notify_supervisor_on_handoff' => true,
            // insertOrIgnore no pasa por el modelo, así que los timestamps van a mano.
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Relectura: si perdimos la carrera, aquí está la fila del ganador.
        return $this->leer($tenantId, $storeId);
    }

    /**
     * `orderBy('id')` es defensivo: el unique de R51 garantiza una sola fila, pero si alguna vez
     * volviera a haber duplicados, esta lectura seguiría siendo determinista en vez de depender del
     * orden físico de Postgres.
     */
    protected function leer($tenantId, $storeId)
    {
        return StoreOpeningSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->first();
    }
}
