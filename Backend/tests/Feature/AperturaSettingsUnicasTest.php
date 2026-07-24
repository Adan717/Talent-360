<?php

namespace Tests\Feature;

use App\Helpers\TenantStore;
use App\Services\StoreOpeningSettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 51 (Reloj): `store_opening_settings` debe tener unique (tenant_id, store_id).
 *
 * Misma clase que R8 (store_daily_opening_statuses) y R12 (store_opening_assignments).
 *
 * `StoreOpeningSettingsService::getOpeningSettings` hace un check-then-insert NO atómico: si el
 * SELECT no halla fila, hace `create()`. Sin unique, dos requests concurrentes crean DOS filas y a
 * partir de ahí `->first()` (sin ORDER BY) es **no determinista**. En Postgres esto muerde de verdad:
 * un `first()` sin orden hace seq scan en orden FÍSICO, y un UPDATE reescribe la fila al final del
 * heap (MVCC) → tras `updateSettings` la fila editada se va al final y el siguiente `first()` puede
 * devolver **la otra** → los ajustes que acaba de guardar el admin "desaparecen" y el reloj obedece
 * los defaults. Justo el patrón que las rondas R46/R47 vinieron cerrando.
 *
 * OJO con la trampa de R8: `create()` LANZA al violar el unique, y todos los callers de apertura
 * envuelven esto en `DB::transaction` (`StoreOpeningService::openStoreAndClockIn:182`,
 * `reportStoreStillClosed:332`, `StoreOpeningHandoffService:34,82`). Un INSERT que falla dentro de
 * una transacción de Postgres la ABORTA (SQLSTATE 25P02) y rompe toda lectura posterior. Por eso el
 * fix es `insertOrIgnore` + relectura, NO try/catch.
 */
class AperturaSettingsUnicasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant(1, 'tenant-unicas');
        $this->seedTenant(2, 'otro-unicas');
    }

    private function seedTenant(int $id, string $slug): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => $id,
            'name' => 'Tenant ' . $id,
            'subdomain' => $slug,
            'public_slug' => $slug,
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function filaDeAjustes(int $tenantId, int $storeId, array $extra = []): array
    {
        return array_merge([
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
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra);
    }

    /**
     * El unique en sí. La migración `2026_06_28_030000` siembra una fila para el tenant 1, así que
     * se usa el tenant 2 (limpio) para no depender de eso.
     */
    public function test_el_unique_rechaza_una_segunda_fila_para_el_mismo_tenant_y_store(): void
    {
        $tienda = TenantStore::defaultIdFor(2);
        DB::table('store_opening_settings')->insert($this->filaDeAjustes(2, $tienda));

        $this->expectException(QueryException::class);
        DB::table('store_opening_settings')->insert($this->filaDeAjustes(2, $tienda));
    }

    /**
     * El unique es COMPUESTO: no debe estorbar a otro tenant ni a otra SEGUNDA sucursal del mismo
     * tenant. Desde R52 las sucursales son entidades reales con FK, así que hay que crearlas: ya no
     * vale inventarse un `store_id`.
     */
    public function test_permite_otro_tenant_y_otra_tienda_del_mismo_tenant(): void
    {
        // Tenants LIMPIOS: el tenant 1 lo crea la migración `2026_06_28_030000` y le siembra una
        // fila de ajustes (tenant 1, store 1), así que usarlo aquí chocaría con el unique.
        $this->seedTenant(4, 'tercero-unicas');

        $tiendaDos = TenantStore::defaultIdFor(2);
        $segundaDelDos = DB::table('stores')->insertGetId([
            'tenant_id' => 2, 'name' => 'Segunda Sucursal', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tiendaCuatro = TenantStore::defaultIdFor(4);

        DB::table('store_opening_settings')->insert($this->filaDeAjustes(2, $tiendaDos));
        DB::table('store_opening_settings')->insert($this->filaDeAjustes(2, $segundaDelDos)); // otra sucursal
        DB::table('store_opening_settings')->insert($this->filaDeAjustes(4, $tiendaCuatro)); // otro tenant

        $this->assertSame(2, DB::table('store_opening_settings')->where('tenant_id', 2)->count());
        $this->assertSame(1, DB::table('store_opening_settings')->where('tenant_id', 4)->count());
    }

    /**
     * El servicio no debe crear duplicados ni devolver filas distintas entre llamadas.
     *
     * OJO: este test **pasa también con el servicio viejo** (`create()`), porque sin concurrencia el
     * check-then-insert se comporta bien. No protege la regresión — los dientes están en
     * `test_perder_la_carrera_no_lanza_ni_aborta_la_transaccion_del_caller`, que sí falla al revertir
     * el servicio (comprobado).
     */
    public function test_get_opening_settings_es_idempotente(): void
    {
        $service = app(StoreOpeningSettingsService::class);

        $a = $service->getOpeningSettings(2);
        $b = $service->getOpeningSettings(2);

        $this->assertSame((int) $a->id, (int) $b->id, 'debe devolver SIEMPRE la misma fila');
        $this->assertSame(1, DB::table('store_opening_settings')->where('tenant_id', 2)->count());
    }

    /**
     * LA REGRESIÓN QUE IMPORTA (lección de R8): simula perder la carrera. Se engancha un listener
     * que, justo después del SELECT del servicio, inserta la fila desde "otro request". El
     * `create()` original reventaría con QueryException y, al estar dentro de la transacción del
     * caller, la ABORTARÍA. Con insertOrIgnore + relectura no debe lanzar, debe devolver la fila del
     * ganador, y la transacción debe seguir VIVA (se comprueba leyendo después dentro de la misma tx).
     */
    public function test_perder_la_carrera_no_lanza_ni_aborta_la_transaccion_del_caller(): void
    {
        $service = app(StoreOpeningSettingsService::class);
        $yaInsertado = false;

        DB::listen(function ($query) use (&$yaInsertado) {
            // Tras el SELECT del servicio sobre la tabla, un "otro request" gana la carrera.
            if (!$yaInsertado
                && stripos($query->sql, 'select') === 0
                && str_contains($query->sql, 'store_opening_settings')) {
                $yaInsertado = true;
                DB::table('store_opening_settings')->insert(
                    $this->filaDeAjustes(2, TenantStore::defaultIdFor(2), ['pre_opening_window_minutes' => 42])
                );
            }
        });

        $resultado = DB::transaction(function () use ($service) {
            $settings = $service->getOpeningSettings(2);

            // La transacción debe seguir usable: si el INSERT hubiera reventado, en Postgres esta
            // lectura fallaría con "current transaction is aborted".
            $filas = DB::table('store_opening_settings')->where('tenant_id', 2)->count();

            return ['settings' => $settings, 'filas' => $filas];
        });

        $this->assertSame(1, $resultado['filas'], 'no debe crearse una segunda fila');
        $this->assertSame(
            42,
            (int) $resultado['settings']->pre_opening_window_minutes,
            'debe devolver la fila del GANADOR de la carrera, no una inventada'
        );
    }
}
