<?php

namespace Tests\Feature;

use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reloj R96 (Fase 6, config sin UI): los knobs opt-in de Fases 3-5 (checklist de cierre R88, montos de
 * bono R94) ya se pueden fijar desde el panel admin (POST /admin/lft-settings), no sólo por SQL crudo.
 * El controller valida con whitelist explícita → sin añadir las reglas, los campos se DROPEABAN.
 */
class LftSettingsConfigTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED = [
        'lates_per_absence' => 3, 'deduct_absence_day' => true, 'absences_for_warning' => 3,
        'absences_for_suspension' => 4, 'proportional_rest_day' => true, 'late_tolerance_minutes' => 10,
        'meal_tolerance_minutes' => 15, 'rest_tolerance_minutes' => 10, 'late_action_mode' => 'deduct',
        'paid_rest_day' => true,
    ];

    private function admin(): array
    {
        $tenant = Tenant::create(['name' => 'C', 'subdomain' => 'c' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
        return [$tenant, $admin];
    }

    public function test_admin_guarda_los_knobs_nuevos(): void
    {
        [$tenant, $admin] = $this->admin();

        $res = $this->actingAs($admin)->postJson('/api/v1/admin/lft-settings', array_merge(self::REQUIRED, [
            'require_closing_checklist' => true,
            'punctuality_bonus_amount' => 500,
            'punctuality_bonus_max_lates' => 2,
            'opening_bonus_per_open' => 100,
        ]));

        $res->assertStatus(200);
        $this->assertDatabaseHas('lft_settings', [
            'tenant_id' => $tenant->id, 'require_closing_checklist' => true,
            'punctuality_bonus_amount' => 500, 'punctuality_bonus_max_lates' => 2, 'opening_bonus_per_open' => 100,
        ]);
    }

    public function test_getsettings_devuelve_los_knobs(): void
    {
        [$tenant, $admin] = $this->admin();
        LftSetting::create(array_merge(self::REQUIRED, [
            'tenant_id' => $tenant->id, 'require_closing_checklist' => true, 'opening_bonus_per_open' => 100,
        ]));

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/lft-settings');

        $res->assertStatus(200);
        // getSettings devuelve el modelo completo; los knobs deben venir para poblar el formulario.
        $this->assertTrue((bool) $res->json('require_closing_checklist') || (bool) $res->json('data.require_closing_checklist'));
    }

    public function test_omitir_knobs_conserva_valor_existente(): void
    {
        // `sometimes`: un guardado que NO envía los knobs (FE viejo) no los pone en 0/false.
        [$tenant, $admin] = $this->admin();
        LftSetting::create(array_merge(self::REQUIRED, [
            'tenant_id' => $tenant->id, 'require_closing_checklist' => true, 'opening_bonus_per_open' => 100,
        ]));

        $this->actingAs($admin)->postJson('/api/v1/admin/lft-settings', self::REQUIRED)->assertStatus(200);

        // No se enviaron → se conservan.
        $this->assertDatabaseHas('lft_settings', [
            'tenant_id' => $tenant->id, 'require_closing_checklist' => true, 'opening_bonus_per_open' => 100,
        ]);
    }

    public function test_empleado_no_puede_guardar(): void
    {
        [$tenant] = $this->admin();
        $empleado = User::create([
            'tenant_id' => $tenant->id, 'name' => 'E', 'email' => 'e' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true,
        ]);

        $this->actingAs($empleado)->postJson('/api/v1/admin/lft-settings', self::REQUIRED)->assertStatus(403);
    }

    public function test_max_lates_rechaza_valor_fuera_de_rango(): void
    {
        // R96-#3: la columna es unsignedSmallInteger (máx 65535); un valor mayor debe dar 422, no 500.
        [, $admin] = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/admin/lft-settings', array_merge(self::REQUIRED, [
            'punctuality_bonus_max_lates' => 70000,
        ]))->assertStatus(422);
    }

    public function test_toggle_amnistia_persiste_por_su_endpoint(): void
    {
        // R96-#5: el toggle enable_amnesty_if_store_closed va por PUT /store-opening/settings (pure-FE,
        // sin cambio backend) — un test que guarda que persiste, para que un futuro refactor no lo dropee.
        [$tenant, $admin] = $this->admin();

        $this->actingAs($admin)->putJson('/api/v1/store-opening/settings', [
            'enable_amnesty_if_store_closed' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('store_opening_settings', [
            'tenant_id' => $tenant->id, 'enable_amnesty_if_store_closed' => false,
        ]);
    }

    public function test_ventana_comida_persiste_por_sync_settings(): void
    {
        // R96-#5: minWorkMinutes viaja en el blob mealSettings a POST /sync/settings (sin whitelist).
        [$tenant, $admin] = $this->admin();

        // Formato real del FE (updateSetting → { key, value }).
        $this->actingAs($admin)->postJson('/api/v1/sync/settings', [
            'key' => 'mealSettings', 'value' => ['minWorkMinutes' => 90, 'maxChairs' => 4],
        ])->assertStatus(200);

        $stored = \Illuminate\Support\Facades\DB::table('system_settings')
            ->where('tenant_id', $tenant->id)->where('key', 'mealSettings')->value('value');
        $this->assertSame(90, json_decode($stored, true)['minWorkMinutes']);
    }
}
