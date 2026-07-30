<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H2 (prueba en vivo 2026-07-29): el asistente de Giro Comercial —la puerta de entrada del
 * producto, que precarga puestos, tareas y cursos— NUNCA se abría solo en una empresa nueva.
 *
 * El frontend está bien (`App.tsx`: si `onboarding_completed` no está, muestra el wizard),
 * pero `ClockController::getState` trae un fallback pensado para que el wizard no reaparezca
 * en empresas ya configuradas:
 *
 *     if (!isset($systemSettings['onboarding_completed'])) {
 *         if (job_roles del tenant existen) -> onboarding_completed = true
 *     }
 *
 * y la creación del tenant **siembra puestos por defecto**, así que el fallback se cumplía
 * desde el primer login y marcaba el onboarding como hecho sin que nadie lo hubiera corrido.
 *
 * Regla de esta ronda: toda empresa nueva nace con `onboarding_completed = false` EXPLÍCITO.
 * Al estar la clave presente, el fallback ya no aplica (sigue vigente para los tenants
 * antiguos que no la tienen, que es justo para lo que se escribió).
 */
class OnboardingWizardAppearsForNewTenantTest extends TestCase
{
    use RefreshDatabase;

    private function settingDelTenant(int $tenantId, string $key)
    {
        return DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->value('value');
    }

    private function crearTenant(string $sub): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $sub,
            'subdomain' => $sub,
            'public_slug' => $sub,
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
        ]);
    }

    public function test_una_empresa_nueva_nace_con_el_onboarding_pendiente(): void
    {
        $tenant = $this->crearTenant('nueva');

        $valor = $this->settingDelTenant($tenant->id, 'onboarding_completed');

        $this->assertNotNull($valor, 'La clave debe existir para que el fallback de getState no la dé por completada.');
        $this->assertFalse((bool) json_decode($valor, true), 'Una empresa recién creada no tiene el onboarding hecho.');
    }

    public function test_el_estado_reporta_el_onboarding_pendiente_pese_a_los_puestos_sembrados(): void
    {
        $tenant = $this->crearTenant('conpuestos');

        // Reproduce la causa del bug: el alta siembra puestos por defecto.
        DB::table('job_roles')->insert([
            'tenant_id' => $tenant->id, 'name' => 'Gerente de Sucursal', 'area' => 'Operaciones',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenant->id]);

        $res = $this->actingAs($user->fresh())->getJson('/api/v1/sync/state');

        $res->assertStatus(200);
        $flag = $res->json('system_settings.onboarding_completed');
        $this->assertFalse((bool) $flag, 'Con puestos sembrados pero sin correr el wizard, debe seguir pendiente.');
    }

    public function test_el_fallback_sigue_protegiendo_a_las_empresas_antiguas(): void
    {
        // Tenant "antiguo": se le borra la clave, como los creados antes de este fix.
        $tenant = $this->crearTenant('antigua');
        DB::table('system_settings')
            ->where('tenant_id', $tenant->id)
            ->where('key', 'onboarding_completed')
            ->delete();

        DB::table('job_roles')->insert([
            'tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Ventas',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenant->id]);

        $flag = $this->actingAs($user->fresh())->getJson('/api/v1/sync/state')
            ->json('system_settings.onboarding_completed');

        $this->assertTrue((bool) $flag, 'A una empresa ya configurada no debe reaparecerle el wizard.');
    }

    public function test_completar_el_wizard_lo_marca_como_hecho(): void
    {
        $tenant = $this->crearTenant('completada');

        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenant->id]);
        $user = $user->fresh();

        // Es lo que hace el FE al terminar el wizard (updateSetting).
        $this->actingAs($user)->postJson('/api/v1/sync/settings', [
            'key' => 'onboarding_completed', 'value' => true,
        ])->assertStatus(200);

        $flag = $this->actingAs($user)->getJson('/api/v1/sync/state')
            ->json('system_settings.onboarding_completed');

        $this->assertTrue((bool) $flag);
    }
}
