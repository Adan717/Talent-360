<?php

namespace Tests\Feature;

use App\Helpers\TenantStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 47 (Reloj): los ajustes de apertura que edita el admin deben LLEGAR al reloj.
 *
 * Bug cazado en la auditoría R42: el admin guarda los ajustes con `PUT /store-opening/settings`
 * (van a la tabla `store_opening_settings`), pero el motor del reloj **nunca los leía**:
 *  - `useClockEngine.tsx:122-141` inicializa `openingSettings` desde `localStorage` o, si no hay,
 *    desde defaults HARDCODEADOS (todos en `true`).
 *  - El único `setOpeningSettings` vivía dentro de `if (isSandboxMode)` (`:163-169`), y esa llave de
 *    localStorage **sólo se escribe en el sandbox** (`CompanySettingsPanel.tsx:83`).
 *  - La rama de producción sólo hacía `setOpeningStatus(res.data.status)` — jamás tocaba los ajustes.
 *  - `useClockEngine` **nunca hace `GET /store-opening/settings`** (y esa ruta es admin-only, así que
 *    un empleado tampoco podría llamarla).
 * Resultado: en producción los ajustes eran ETERNAMENTE los defaults. Apagar "requiere checklist"
 * o "requiere pase de lista" desde el panel no apagaba nada.
 *
 * Fix: servirlos en `GET /store-opening/today`, que es el endpoint que el motor YA consulta cada 5s
 * y que SÍ es accesible al empleado (grupo `role:empleado,…` en api.php:255).
 */
class AperturaSettingsLleganAlRelojTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Tenant Settings',
            'subdomain' => 'tenant-settings',
            'public_slug' => 'tenant-settings',
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEmpleado(int $tenantId = 1): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        return User::withoutGlobalScopes()->find($user->id);
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

    /**
     * El caso real: el admin APAGA el checklist y el pase de lista. El empleado debe recibirlos
     * apagados — antes se quedaba con los defaults hardcodeados (`true`) para siempre.
     */
    public function test_today_devuelve_los_ajustes_reales_del_admin(): void
    {
        $empleado = $this->makeEmpleado();

        // OJO: la migración `2026_06_28_030000` ya siembra una fila de ajustes para el tenant 1, y
        // la tabla NO tiene unique en (tenant_id, store_id) — un insert crearía un DUPLICADO y el
        // `first()` del servicio devolvería el sembrado, no el nuestro. Se actualiza la fila real,
        // que es justo lo que hace el admin desde el panel.
        DB::table('store_opening_settings')->updateOrInsert(
            ['tenant_id' => 1, 'store_id' => 1],
            [
                'company_id' => 1,
                'pre_opening_window_minutes' => 20,
                'absence_late_report_window_minutes' => 5,
                'allow_automatic_handoff' => true,
                'allow_late_if_before_opening' => true,
                'allow_store_closed_report' => true,
                'enable_amnesty_if_store_closed' => true,
                'require_opening_checklist' => false, // el admin lo APAGÓ
                'require_opening_roll_call' => false, // el admin lo APAGÓ
                'notify_admin_on_handoff' => true,
                'notify_supervisor_on_handoff' => true,
                'updated_at' => now(),
            ]
        );

        $response = $this->actingAs($empleado)->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertFalse(
            (bool) $response->json('settings.require_opening_checklist'),
            'el reloj debe recibir el checklist APAGADO; antes usaba el default hardcodeado true'
        );
        $this->assertFalse((bool) $response->json('settings.require_opening_roll_call'));
        $this->assertSame(20, (int) $response->json('settings.pre_opening_window_minutes'));
    }

    /**
     * Sin fila de ajustes, el servicio siembra los defaults: el reloj igual debe recibirlos
     * (y no quedarse sin `settings`). Se usa un tenant LIMPIO: el 1 ya trae fila sembrada por la
     * migración `2026_06_28_030000`, así que ahí este caso no se probaría de verdad.
     */
    public function test_today_siembra_y_devuelve_defaults_si_no_hay_fila(): void
    {
        $this->seedTenant(3, 'tenant-limpio');
        $empleado = $this->makeEmpleado(3);

        $this->assertDatabaseMissing('store_opening_settings', ['tenant_id' => 3]);

        $response = $this->actingAs($empleado)->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertNotNull($response->json('settings'), 'siempre debe venir el objeto de ajustes');
        $this->assertTrue((bool) $response->json('settings.require_opening_checklist'));
        $this->assertDatabaseHas('store_opening_settings', ['tenant_id' => 3]);
    }

    /**
     * Aislamiento: los ajustes de OTRO tenant no deben filtrarse.
     */
    public function test_los_ajustes_son_del_tenant_del_usuario(): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => 2,
            'name' => 'Otro Tenant',
            'subdomain' => 'otro-settings',
            'public_slug' => 'otro-settings',
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Ajustes del tenant ajeno, con una ventana inconfundible.
        DB::table('store_opening_settings')->insert([
            'tenant_id' => 2,
            'company_id' => 1,
            // R52: la sucursal debe ser LA DEL TENANT 2. Poner `1` aquí apuntaría a la sucursal del
            // tenant 1 — justo la corrupción cross-tenant que R52 vino a impedir. La FK lo aceptaría
            // (el id existe), así que esto sólo lo garantiza usar el resolver.
            'store_id' => TenantStore::defaultIdFor(2),
            'pre_opening_window_minutes' => 99,
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

        $empleado = $this->makeEmpleado(); // tenant 1

        $response = $this->actingAs($empleado)->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertNotSame(
            99,
            (int) $response->json('settings.pre_opening_window_minutes'),
            'no deben filtrarse los ajustes de otro tenant'
        );
    }
}
