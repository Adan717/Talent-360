<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R93 (Fase 4 / D2): los switches del dial (`clockOpConfig.enabledDialerFeatures`) con efecto
 * server-side REAL se enforcean en el servidor. Antes, apagar un switch en el panel sólo ESCONDÍA el
 * botón — un cliente crudo disparaba la acción igual:
 *  - `enable_proximity_check` OFF y un `waiting` forjado seguía COMPRANDO AMNISTÍA (dinero).
 *  - `enable_temp_exit` OFF y el pase de salida temporal se registraba igual (y el switch ni se leía).
 *  - `enable_meal_slots` OFF y la reserva ocupaba sillas del aforo igual.
 *  - `allow_store_closed_report` del PANEL apagado y el reporte (→ amnistía de equipo) pasaba igual:
 *    R90 leía OTRA fuente (columna store_opening_settings, sin UI desde R71) — doble-cerebro.
 *  - switches de incidencias apagados y el reporte se insertaba igual.
 * Convención compartida con el FE: clave AUSENTE = ON.
 */
class DialerSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeTenantAndEmployee(array $dialerFeatures = null): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 08:55:00'));
        $tenant = Tenant::create([
            'name' => 'Empresa Switches', 'subdomain' => 'sw' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
            );
        if ($dialerFeatures !== null) {
            // updateOrInsert: Tenant::created ya siembra un clockOpConfig default (TenantInitializationService).
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'clockOpConfig'],
                ['value' => json_encode(['enabledDialerFeatures' => $dialerFeatures]),
                    'created_at' => now(), 'updated_at' => now()]
            );
        }
        $jr = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Piso']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'sw' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado', 'is_active' => true,
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true, 'job_role_id' => $jr->id,
        ]);
        return [$tenant, $user];
    }

    private function attempt(User $user, string $type, $occurredAt = null): array
    {
        try {
            app(ClockService::class)->processPunch($user, $type, null, [], $occurredAt);
            return ['ok' => true, 'msg' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // ---- enable_proximity_check (waiting → amnistía: DINERO) --------------------------

    public function test_waiting_bloqueado_con_proximity_off(): void
    {
        [, $user] = $this->makeTenantAndEmployee(['enable_proximity_check' => false]);

        $res = $this->attempt($user, 'waiting');

        $this->assertFalse($res['ok']);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'waiting']);
    }

    public function test_waiting_bloqueado_tambien_offline(): void
    {
        // A diferencia de R89/R91, el waiting NO se exime offline: su único efecto server-side es
        // comprar amnistía (dinero) — con el switch apagado esa vía no debe existir ni vía batch.
        [, $user] = $this->makeTenantAndEmployee(['enable_proximity_check' => false]);

        $res = $this->attempt($user, 'waiting', '2026-07-15T08:55:00Z');

        $this->assertFalse($res['ok']);
    }

    public function test_waiting_permitido_con_clave_ausente(): void
    {
        // Clave ausente = ON (convención del FE); y sin fila clockOpConfig en absoluto también.
        [, $user] = $this->makeTenantAndEmployee(null);

        $res = $this->attempt($user, 'waiting');

        $this->assertTrue($res['ok'], $res['msg']);
        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'waiting']);
    }

    // ---- enable_temp_exit -------------------------------------------------------------

    public function test_temp_exit_start_bloqueado_con_switch_off(): void
    {
        [, $user] = $this->makeTenantAndEmployee(['enable_temp_exit' => false]);
        $this->attempt($user, 'check_in');

        $res = $this->attempt($user, 'temp_exit_start');

        $this->assertFalse($res['ok']);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'temp_exit_start']);
    }

    public function test_temp_exit_end_nunca_se_bloquea(): void
    {
        // Quien ya está fuera debe poder registrar su REGRESO aunque el switch se apague en medio.
        [, $user] = $this->makeTenantAndEmployee(['enable_temp_exit' => false]);
        $this->attempt($user, 'check_in');

        $res = $this->attempt($user, 'temp_exit_end');

        $this->assertTrue($res['ok'], $res['msg']);
    }

    public function test_temp_exit_start_offline_exento(): void
    {
        // Un pase encolado ya ocurrió físicamente — registrarlo es honesto (criterio R89/R91).
        [, $user] = $this->makeTenantAndEmployee(['enable_temp_exit' => false]);

        $res = $this->attempt($user, 'temp_exit_start', '2026-07-15T08:30:00Z');

        $this->assertTrue($res['ok'], $res['msg']);
    }

    // ---- enable_meal_slots (reserva vía /sync/clock) ----------------------------------

    public function test_reserva_rechazada_con_slots_off(): void
    {
        [, $user] = $this->makeTenantAndEmployee(['enable_meal_slots' => false]);

        $res = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id, 'type' => 'meal_reservation',
            'date' => '2026-07-15', 'time' => '13:00:00',
        ]);

        $res->assertStatus(409);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'meal_reservation']);
    }

    public function test_cancelacion_permitida_con_slots_off(): void
    {
        // meal_cancel libera una silla — siempre válido, aunque el switch se apagara después.
        [, $user] = $this->makeTenantAndEmployee(['enable_meal_slots' => false]);

        $res = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id, 'type' => 'meal_cancel',
            'date' => '2026-07-15', 'time' => '13:00:00',
        ]);

        $res->assertStatus(200);
    }

    // ---- allow_store_closed_report (doble-cerebro R90/R93) ----------------------------

    public function test_reporte_tienda_cerrada_rechazado_con_switch_del_panel_off(): void
    {
        // La columna store_opening_settings (sin UI desde R71) permite; el switch del PANEL —el que
        // el admin sí togglea— está off → el reporte debe rechazarse (antes pasaba: doble-cerebro).
        [$tenant, $user] = $this->makeTenantAndEmployee(['allow_store_closed_report' => false]);
        Carbon::setTestNow(Carbon::parse('2026-07-15 09:30:00'));
        DB::table('store_daily_opening_statuses')->insert([
            'tenant_id' => $tenant->id, 'company_id' => 1, 'store_id' => 1, 'date' => '2026-07-15',
            'scheduled_opening_time' => '09:00:00', 'pre_opening_window_start' => '08:45:00',
            'report_deadline' => '09:15:00', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            app(\App\Services\StoreOpeningService::class)->reportStoreStillClosed($user->id, 1);
            $this->fail('el reporte debió rechazarse con el switch del panel apagado');
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('no está habilitado', $e->getMessage());
        }
        $this->assertDatabaseMissing('store_daily_opening_statuses', [
            'tenant_id' => $tenant->id, 'late_amnesty_granted' => true,
        ]);
    }

    // ---- incidencias ------------------------------------------------------------------

    public function test_contingencia_rechazada_con_ambos_switches_off(): void
    {
        [, $user] = $this->makeTenantAndEmployee([
            'allow_employee_incidences' => false, 'allow_manager_incidences' => false,
        ]);

        $res = $this->actingAs($user)->postJson('/api/v1/sync/contingency', [
            'user_id' => $user->id, 'type' => 'absence', 'justification_text' => 'x',
        ]);

        $res->assertStatus(403);
        $this->assertDatabaseMissing('contingencies', ['user_id' => $user->id]);
    }

    public function test_contingencia_permitida_con_uno_encendido(): void
    {
        [, $user] = $this->makeTenantAndEmployee([
            'allow_employee_incidences' => true, 'allow_manager_incidences' => false,
        ]);

        $res = $this->actingAs($user)->postJson('/api/v1/sync/contingency', [
            'user_id' => $user->id, 'type' => 'absence', 'justification_text' => 'x',
        ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('contingencies', ['user_id' => $user->id]);
    }

    public function test_report_absence_rechazado_con_manager_incidences_off(): void
    {
        [, $user] = $this->makeTenantAndEmployee(['allow_manager_incidences' => false]);

        $res = $this->actingAs($user)->postJson('/api/v1/store-opening/report-absence', []);

        $res->assertStatus(403);
    }
}
