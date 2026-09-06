<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrossTenantPunchAndLateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1, 2] as $id) {
            DB::table('tenants')->insertOrIgnore([
                'id' => $id,
                'name' => "Tenant {$id}",
                'subdomain' => "tenant{$id}",
                'plan' => 'free',
                'max_users' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function makeUserForTenant(int $tenantId, string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        $user->refresh();

        return $user;
    }

    // ---------- §59: aislamiento de tenant en fichaje ----------

    public function test_punch_rejects_user_id_from_another_tenant(): void
    {
        $caller = $this->makeUserForTenant(1, 'admin');
        $victim = $this->makeUserForTenant(2, 'empleado');

        $response = $this->actingAs($caller)->postJson('/api/v1/clock/punch', [
            'user_id' => $victim->id,
            'type' => 'check_in',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);

        // No se creó ningún time_entry para la víctima de otro tenant.
        $this->assertDatabaseMissing('time_entries', ['user_id' => $victim->id]);
    }

    public function test_punch_batch_rejects_user_id_from_another_tenant(): void
    {
        // ENMIENDA resync 2026-07-27: /clock/punch-batch lo atiende PunchBatchController (línea
        // del Reloj), que es MÁS estricto que el punchBatch original (sólo ponches del PROPIO
        // emisor, B1/R84) y rechaza POR ÍTEM (200 + rejected) en vez de 403 global — un ítem
        // envenenado no debe tumbar el lote completo. La garantía de seguridad que este test
        // protege no cambia: el ponche cross-tenant NUNCA se persiste.
        $caller = $this->makeUserForTenant(1, 'admin');
        $victim = $this->makeUserForTenant(2, 'empleado');

        $response = $this->actingAs($caller)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [
                [
                    'user_id' => $victim->id,
                    'type' => 'check_in',
                    'time' => '08:00:00',
                    'client_timestamp' => '2026-07-20T08:00:00-06:00',
                    'offline_stamp' => 'cualquier-firma',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['processed' => 0, 'rejected' => 1]);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $victim->id]);
    }

    public function test_punch_accepts_user_id_from_same_tenant(): void
    {
        $caller = $this->makeUserForTenant(1, 'admin');
        $mate = $this->makeUserForTenant(1, 'empleado');

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        $response = $this->actingAs($caller)->postJson('/api/v1/clock/punch', [
            'user_id' => $mate->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $mate->id,
            'type' => 'check_in',
        ]);
    }

    // ---------- §61: llegada temprana nunca es retardo ----------

    public function test_early_check_in_is_not_marked_late_and_stores_zero_minutes(): void
    {
        $user = $this->makeUserForTenant(1, 'empleado');

        // Turno a las 09:00; el empleado ficha a las 07:00 (2h antes).
        DB::table('employees')->where('user_id', $user->id)->update(['shiftStart' => '09:00:00']);

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        $service = app(ClockService::class);
        $user->refresh();

        $result = $service->processPunch($user, 'check_in', '07:00:00', []);

        $entry = DB::table('time_entries')->where('user_id', $user->id)->where('type', 'check_in')->first();
        $this->assertNotNull($entry);
        $this->assertFalse((bool) $entry->is_late, 'Una llegada temprana no debe marcarse como retardo.');
        $this->assertEquals(0, (int) $entry->late_minutes);
    }

    public function test_genuine_late_check_in_stores_positive_integer_minutes(): void
    {
        $user = $this->makeUserForTenant(1, 'empleado');

        DB::table('employees')->where('user_id', $user->id)->update(['shiftStart' => '09:00:00']);

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        $service = app(ClockService::class);
        $user->refresh();

        // Ficha a las 09:30 con turno 09:00 (tolerancia por defecto 10 min) → 30 min tarde.
        $service->processPunch($user, 'check_in', '09:30:00', []);

        $entry = DB::table('time_entries')->where('user_id', $user->id)->where('type', 'check_in')->first();
        $this->assertNotNull($entry);
        $this->assertTrue((bool) $entry->is_late);
        $this->assertEquals(30, (int) $entry->late_minutes);
    }

    // ---------- §58: cupo de usuarios unificado ----------

    /**
     * (2026-09-05) Los números cambiaron y hay que decirlo:
     *
     *  - **freemium 5 → 10.** El método caía a 5 diciendo en su comentario que era "lo que
     *    anuncia la landing", pero la landing anuncia 10 y la pantalla del cliente también
     *    caía a 10. Como NADA aplica este tope —`max_users` se guarda y ningún código lo
     *    revisa—, el número que se conserva es el que se le prometió al cliente.
     *  - **pro sin dato 50 → 9999.** El 50 era el tope del deslizador de la landing usado como
     *    cupo; PRO se cobra por colaborador y no tiene tope, así que ahora dice lo mismo que
     *    enterprise. Con el número contratado, sigue siendo ese número.
     */
    public function test_max_users_for_plan_defaults(): void
    {
        $this->assertEquals(10, Tenant::maxUsersForPlan('freemium'));
        $this->assertEquals(Tenant::SIN_TOPE, Tenant::maxUsersForPlan('pro'));
        $this->assertEquals(25, Tenant::maxUsersForPlan('pro', 25));
        $this->assertEquals(Tenant::SIN_TOPE, Tenant::maxUsersForPlan('enterprise'));
    }

    /**
     * El cupo se cambia donde se cambian los precios: en `billing_plans`, sin recompilar.
     *
     * Antes vivía en `system_settings.freemium_max_users`, una llave que NINGUNA pantalla
     * escribía. La migración del tarifario se llevó su valor a `billing_plans` (conservándolo
     * si alguna instalación lo tenía puesto a mano) y retiró la llave, para que no queden dos
     * números capaces de divergir.
     */
    public function test_max_users_for_freemium_reads_billing_plan(): void
    {
        DB::table('billing_plans')->where('code', 'freemium')->update(['max_users' => 8]);
        \App\Support\Tarifario::olvidar();

        $this->assertEquals(8, Tenant::maxUsersForPlan('freemium'));

        \App\Support\Tarifario::olvidar();
    }
}
