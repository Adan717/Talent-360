<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H15 (jornada de regresión 2026-07-30): la apertura de sucursal NUNCA se reflejaba en el dial.
 *
 * `getTodayStatus` (LECTURA) resuelve la sucursal del tenant con `TenantStore::defaultIdFor()`
 * —el fix R52 del merge, correcto—, pero las ESCRITURAS seguían con
 * `$request->input('store_id', 1)`: si el cliente no manda `store_id`, caen al **1
 * hardcodeado**, que es la sucursal del tenant 1.
 *
 * Reproducido en la V2 (tenant 2): `open-and-clock-in` respondía "Tienda abierta con éxito" y
 * dejaba dos filas para el mismo día —`store_id=1` en `opened` y `store_id=2` en `failed`—
 * mientras el tablero seguía diciendo "SIN ABRIR", porque lee la del tenant.
 *
 * Doble consecuencia:
 *  1. Funcional: abrir la sucursal no surte efecto para nadie.
 *  2. Aislamiento: una empresa escribe su operación de apertura sobre la sucursal de OTRA.
 *
 * Regla: toda escritura resuelve la sucursal del tenant, igual que la lectura, e ignora el
 * `store_id` que mande el cliente.
 */
class StoreOpeningStoreIdIsolationTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1, 2] as $id) {
            DB::table('tenants')->insertOrIgnore([
                'id' => $id, 'name' => "Empresa {$id}", 'subdomain' => "t{$id}",
                'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function usuario(int $tenantId): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user->fresh();
    }

    /** La sucursal que el sistema considera propia del tenant. */
    private function sucursalDelTenant(int $tenantId): int
    {
        return \App\Helpers\TenantStore::defaultIdFor($tenantId);
    }

    public function test_abrir_la_sucursal_escribe_en_la_del_propio_tenant(): void
    {
        $user = $this->usuario($this->tenantId);
        $esperado = $this->sucursalDelTenant($this->tenantId);

        $this->actingAs($user)->postJson('/api/v1/store-opening/open-and-clock-in', [])
            ->assertStatus(200);

        $filas = DB::table('store_daily_opening_statuses')->where('tenant_id', $this->tenantId)->get();

        $this->assertCount(1, $filas, 'No debe quedar una fila por sucursal equivocada además de la propia.');
        $this->assertSame($esperado, (int) $filas->first()->store_id);
        $this->assertSame('opened', $filas->first()->status);
    }

    public function test_lo_que_se_abre_es_lo_que_el_dial_lee(): void
    {
        $user = $this->usuario($this->tenantId);

        $this->actingAs($user)->postJson('/api/v1/store-opening/open-and-clock-in', [])
            ->assertStatus(200);

        // La misma vista que consume el dial: si escritura y lectura no coinciden, aquí sale.
        $res = $this->actingAs($user)->getJson('/api/v1/store-opening/today');

        $res->assertStatus(200);
        $this->assertSame('opened', $res->json('status.status'),
            'El dial debe ver como abierta la sucursal que se acaba de abrir.');
    }

    public function test_el_store_id_del_cliente_no_puede_desviar_la_escritura(): void
    {
        $user = $this->usuario($this->tenantId);
        $esperado = $this->sucursalDelTenant($this->tenantId);

        // Intento de escribir sobre la sucursal de otra empresa.
        $this->actingAs($user)->postJson('/api/v1/store-opening/open-and-clock-in', ['store_id' => 999])
            ->assertStatus(200);

        $fila = DB::table('store_daily_opening_statuses')->where('tenant_id', $this->tenantId)->first();
        $this->assertSame($esperado, (int) $fila->store_id);
        $this->assertDatabaseMissing('store_daily_opening_statuses', ['store_id' => 999]);
    }

    public function test_el_checklist_de_cierre_tambien_usa_la_sucursal_propia(): void
    {
        $user = $this->usuario($this->tenantId);
        $esperado = $this->sucursalDelTenant($this->tenantId);

        $this->actingAs($user)->postJson('/api/v1/store-opening/open-and-clock-in', [])->assertStatus(200);
        $this->actingAs($user)->postJson('/api/v1/store-opening/closing-checklist', [
            'user_id' => $user->id,
            'checks' => ['lights_off' => true, 'safe_secured' => true, 'alarm_activated' => true],
        ])->assertStatus(200);

        $filas = DB::table('store_daily_opening_statuses')->where('tenant_id', $this->tenantId)->get();
        $this->assertCount(1, $filas);
        $this->assertSame($esperado, (int) $filas->first()->store_id);
    }
}
