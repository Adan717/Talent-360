<?php

namespace Tests\Feature;

use App\Helpers\TenantStore;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 52 (Reloj): entidad `stores` — fundación para el Kiosko.
 *
 * ANTES: no existía tabla de sucursales. `store_id` vivía suelto en las 4 tablas `store_opening_*`
 * como un integer sin FK, y **valía 1 en TODOS los tenants**: no era un id global, era "la tienda 1
 * de cada empresa". El FE nunca manda `store_id`, así que todo el backend caía a `$storeId = 1`.
 *
 * LA TRAMPA DE ESTA RONDA: al crear `stores` con ids globales (uno por tenant), las filas del tenant
 * 3 seguirían apuntando a `store_id = 1`, que ahora pertenece al tenant 1 → **corrupción
 * cross-tenant**. Por eso la migración REMAPEA `store_opening_*.store_id` por tenant ANTES de poner
 * la FK, y todos los `$storeId = 1` del backend pasan a resolverse desde el tenant.
 *
 * Alcance acordado (2026-07-16): entidad MÍNIMA (tenant_id + nombre). Sin lat/lng ni horario: hoy
 * viven en `system_settings` y los consumen la geocerca (R33/R35) y la ventana de apertura; moverlos
 * es repuntar código de seguridad y va en ronda aparte. No se cementan columnas sin consumidor
 * (lección de `has_keys`, R50).
 */
class EntidadStoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant(1, 'tenant-stores');
        $this->seedTenant(2, 'otro-stores');
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

    private function makeEmpleado(int $tenantId): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        return User::withoutGlobalScopes()->find($user->id);
    }

    /**
     * Cada tenant resuelve SU sucursal. Es la garantía que sustituye al viejo `$storeId = 1`.
     */
    public function test_cada_tenant_resuelve_su_propia_sucursal(): void
    {
        $unoA = TenantStore::defaultIdFor(1);
        $dos = TenantStore::defaultIdFor(2);

        $this->assertNotSame($unoA, $dos, 'dos tenants NO pueden compartir sucursal');
        $this->assertSame(1, (int) DB::table('stores')->where('tenant_id', 1)->count());
        $this->assertSame(1, (int) DB::table('stores')->where('tenant_id', 2)->count());
        $this->assertSame($unoA, TenantStore::defaultIdFor(1), 'debe ser idempotente');
    }

    /**
     * Un tenant NUEVO (creado después de la migración) no tiene sucursal sembrada: el resolver debe
     * crearla, no devolver null ni caer a la sucursal de otro.
     */
    public function test_un_tenant_nuevo_recibe_su_sucursal_principal(): void
    {
        $this->seedTenant(9, 'tenant-nuevo');
        $this->assertSame(0, (int) DB::table('stores')->where('tenant_id', 9)->count());

        $storeId = TenantStore::defaultIdFor(9);

        $this->assertNotNull($storeId);
        $this->assertSame(9, (int) DB::table('stores')->where('id', $storeId)->value('tenant_id'));
    }

    /**
     * Race-safety (lección de R8/R51): se engancha un listener que, tras el SELECT del resolver,
     * inserta la sucursal desde "otro request". No debe lanzar (un INSERT que revienta ABORTA la
     * transacción del caller en Postgres) ni crear una segunda sucursal.
     */
    public function test_perder_la_carrera_no_crea_dos_sucursales_ni_lanza(): void
    {
        $this->seedTenant(8, 'tenant-carrera');
        $yaInsertado = false;

        DB::listen(function ($query) use (&$yaInsertado) {
            if (!$yaInsertado
                && stripos($query->sql, 'select') === 0
                && str_contains($query->sql, 'stores')) {
                $yaInsertado = true;
                DB::table('stores')->insert([
                    'tenant_id' => 8,
                    'name' => TenantStore::DEFAULT_NAME,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $resultado = DB::transaction(function () {
            $id = TenantStore::defaultIdFor(8);
            // La tx debe seguir viva: si el INSERT hubiera reventado, en Postgres esta lectura
            // fallaría con "current transaction is aborted".
            return ['id' => $id, 'filas' => DB::table('stores')->where('tenant_id', 8)->count()];
        });

        $this->assertSame(1, (int) $resultado['filas'], 'no debe crearse una segunda sucursal');
        $this->assertNotNull($resultado['id']);
    }

    /**
     * El unique (tenant_id, name) es lo que hace race-safe al resolver, y además es una regla de
     * negocio razonable: dos sucursales del mismo tenant no pueden llamarse igual.
     */
    public function test_no_puede_haber_dos_sucursales_con_el_mismo_nombre_en_un_tenant(): void
    {
        DB::table('stores')->insert(['tenant_id' => 1, 'name' => 'Centro', 'created_at' => now(), 'updated_at' => now()]);

        // Mismo nombre en OTRO tenant sí se permite.
        DB::table('stores')->insert(['tenant_id' => 2, 'name' => 'Centro', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('stores')->insert(['tenant_id' => 1, 'name' => 'Centro', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * La FK real: ya no se puede escribir un `store_id` que no exista. Antes era un integer suelto
     * apuntando a una tabla inexistente.
     */
    public function test_la_fk_rechaza_un_store_id_inexistente(): void
    {
        $this->expectException(QueryException::class);
        DB::table('store_opening_settings')->insert([
            'tenant_id' => 1,
            'company_id' => 1,
            'store_id' => 99999, // no existe
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
        ]);
    }

    /**
     * LA REGRESIÓN QUE IMPORTA, e2e: el reloj de un tenant que NO es el 1 debe operar sobre SU
     * sucursal. Con el viejo `$storeId = 1` hardcodeado, el tenant 2 escribiría su status de apertura
     * apuntando a la sucursal del tenant 1.
     */
    public function test_el_reloj_opera_sobre_la_sucursal_del_tenant_no_sobre_la_1(): void
    {
        $empleado = $this->makeEmpleado(2);
        $suStoreId = TenantStore::defaultIdFor(2);

        $response = $this->actingAs($empleado)->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertSame(
            $suStoreId,
            (int) $response->json('status.store_id'),
            'el status del día debe apuntar a la sucursal del tenant, no a la 1'
        );
        $this->assertSame(
            $suStoreId,
            (int) DB::table('store_opening_settings')->where('tenant_id', 2)->value('store_id'),
            'los ajustes sembrados también'
        );
    }

    /**
     * El `store_id` que aceptaba el request está MUERTO (el FE nunca lo mandó) y era una superficie
     * de lectura cross-sucursal (follow-up anotado en R50). El cliente ya no puede elegir sucursal:
     * se resuelve del tenant y punto.
     */
    public function test_el_cliente_no_puede_elegir_la_sucursal_por_query_string(): void
    {
        $empleado = $this->makeEmpleado(2);
        $ajena = DB::table('stores')->insertGetId([
            'tenant_id' => 1, 'name' => 'Sucursal Ajena', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($empleado)->getJson('/api/v1/store-opening/today?store_id=' . $ajena);

        $response->assertStatus(200);
        $this->assertSame(
            TenantStore::defaultIdFor(2),
            (int) $response->json('status.store_id'),
            'el store_id del query string debe IGNORARSE; manda el tenant del usuario'
        );
    }
}
