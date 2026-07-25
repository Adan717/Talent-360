<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /sync/clock (ClockController::sync) no debe ser un bypass de las reglas de
 * asistencia: los ponches reales deben pasar por ClockService::processPunch
 * (is_late calculado en servidor, reglas de negocio), mientras que los registros
 * auxiliares de reserva de comida se insertan tal cual y no cuentan como asistencia.
 */
class ClockSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(string $plan = 'enterprise'): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Test Sync',
            'subdomain' => 'test-sync',
            'plan' => $plan,
            'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Colaborador Sync',
            'email' => 'colab.sync@test.com',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Colaborador Sync',
            'base_salary' => 3000.00,
            'restDay' => 'Domingo',
            'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        return [$tenant, $user, $employee];
    }

    private function enableSimulatedTime(int $tenantId): void
    {
        DB::table('system_settings')->insert([
            'tenant_id' => $tenantId,
            'key' => 'time_mode',
            'value' => '"simulated"',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El cliente miente (is_late=false) pero la hora está fuera de tolerancia; el
     * servidor debe recalcular is_late=true. Prueba que el bypass está cerrado.
     */
    public function test_sync_attendance_punch_recomputes_late_server_side(): void
    {
        [$tenant, $user] = $this->makeSetup('enterprise');
        $this->enableSimulatedTime($tenant->id);

        $response = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id,
            'date' => '2026-07-10',
            'type' => 'check_in',
            'time' => '10:00:00', // turno default 09:00 + tolerancia 10 min => tarde
            'is_late' => false,
            'late_minutes' => 0,
        ]);

        $response->assertStatus(200);
        $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_in')->first();
        $this->assertNotNull($entry);
        $this->assertTrue((bool) $entry->is_late, 'el servidor debe marcar el retardo ignorando el is_late del cliente');
        $this->assertGreaterThan(0, (int) $entry->late_minutes);
    }

    /**
     * Un festivo bloqueante debe impedir el fichaje también por este camino.
     */
    public function test_sync_attendance_respects_holiday_block(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 15:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup('enterprise');
            DB::table('lft_holidays')->insert([
                'tenant_id' => $tenant->id,
                'date' => '2026-07-15',
                'name' => 'Festivo Bloqueado',
                'block_app' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
                'user_id' => $user->id,
                'date' => '2026-07-15',
                'type' => 'check_in',
                'time' => '09:00:00',
                'is_late' => false,
            ]);

            // 400 (contrato consistente con TimeEntryController::punch) por regla de negocio.
            $response->assertStatus(400);
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * El retardo debe calcularse contra el shiftStart REAL del empleado (employees.shiftStart),
     * no contra el default 09:00. El bug (cazado por e2e): processPunch leía $user->shiftStart —
     * pero $user es el modelo User y esa columna vive en employees → siempre NULL → todo retardo
     * se computaba contra 09:00. Turno vespertino: llegar 14:05 con turno 14:00 marcaba
     * ~305 min de retardo; turno matutino 07:00: llegar 08:30 (90 min tarde) NO marcaba retardo.
     */
    public function test_late_uses_employee_shift_start_evening_shift_on_time(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup('enterprise');
        $employee->update(['shiftStart' => '14:00:00']);
        $this->enableSimulatedTime($tenant->id);

        $response = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id,
            'date' => '2026-07-10',
            'type' => 'check_in',
            'time' => '14:05:00', // turno 14:00 + tolerancia 10 => a tiempo
            'is_late' => true, // el cliente miente; el servidor decide
            'late_minutes' => 999,
        ]);

        $response->assertStatus(200);
        $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_in')->first();
        $this->assertNotNull($entry);
        $this->assertFalse((bool) $entry->is_late, 'turno 14:00, llegada 14:05: NO es retardo (el bug lo computaba vs 09:00)');
        $this->assertSame(0, (int) $entry->late_minutes);
    }

    public function test_late_uses_employee_shift_start_morning_shift_late(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup('enterprise');
        $employee->update(['shiftStart' => '07:00:00']);
        $this->enableSimulatedTime($tenant->id);

        $response = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id,
            'date' => '2026-07-10',
            'type' => 'check_in',
            'time' => '08:30:00', // turno 07:00 => 90 min tarde (el bug decía "a tiempo" vs 09:00)
            'is_late' => false,
            'late_minutes' => 0,
        ]);

        $response->assertStatus(200);
        $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_in')->first();
        $this->assertNotNull($entry);
        $this->assertTrue((bool) $entry->is_late, 'turno 07:00, llegada 08:30: SÍ es retardo (el bug lo ocultaba vs 09:00)');
        $this->assertSame(90, (int) $entry->late_minutes);
    }

    /**
     * Los registros auxiliares de reserva de comida se siguen insertando (no son
     * ponches de asistencia; nunca 'late').
     */
    public function test_sync_auxiliary_meal_record_still_inserts(): void
    {
        [$tenant, $user] = $this->makeSetup('enterprise');

        $response = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id,
            'date' => '2026-07-10',
            'type' => 'meal_reservation',
            'time' => '14:00',
            'details' => '14:00-15:00',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $user->id,
            'type' => 'meal_reservation',
            'is_late' => false,
        ]);
    }

    /**
     * Los registros auxiliares no deben contar como asistencia en la nómina: un día
     * con SÓLO una reserva de comida (sin fichaje real) es una falta física.
     */
    public function test_payroll_ignores_auxiliary_entries(): void
    {
        [$tenant, $user, $employee] = $this->makeSetup('enterprise');

        // Lunes 2026-06-01 con sólo un registro auxiliar, sin asistencia real.
        TimeEntry::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'date' => '2026-06-01',
            'type' => 'meal_reservation',
            'time' => '14:00',
            'is_late' => false,
            'late_minutes' => 0,
        ]);

        $payroll = app(ClockService::class)->calculatePayrollForEmployee($employee, '2026-06-01', '2026-06-01');

        // Sin el filtro de tipos, el registro auxiliar ocultaría la falta (0);
        // con el filtro, el lunes cuenta como falta física.
        $this->assertEquals(1, $payroll['incidents']['physical_absences']);
    }

    /**
     * Regresión (cazado por e2e): el enum original de time_entries.type dejó un CHECK
     * constraint (time_entries_type_check) que la migración de "cambiar a string" no
     * eliminó en Postgres. Sólo permitía check_in/meal_start/meal_end/check_out, así que
     * TODOS los tipos nuevos —break_start/break_end (Ley Silla), waiting (amnistía "Ya
     * llegué"), temp_exit_start/temp_exit_end— reventaban con SQLSTATE 23514 → 400 al
     * fichar, y NADA de eso persistía. (SQLite no enforce el CHECK, por eso el suite no lo
     * cazaba; en Postgres sí.) Este test bloquea la vocabulario completo de fichajes.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('extendedPunchTypeProvider')]
    public function test_sync_persists_extended_punch_types(string $type): void
    {
        [$tenant, $user] = $this->makeSetup('enterprise');
        $this->enableSimulatedTime($tenant->id);

        $response = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id,
            'date' => '2026-07-10',
            'type' => $type,
            'time' => '14:00:00',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $user->id,
            'type' => $type,
        ]);
    }

    public static function extendedPunchTypeProvider(): array
    {
        return [
            'ley silla inicio' => ['break_start'],
            'ley silla fin' => ['break_end'],
            'amnistia ya llegue' => ['waiting'],
            'salida temporal inicio' => ['temp_exit_start'],
            'salida temporal fin' => ['temp_exit_end'],
        ];
    }

    /**
     * Guarda directa del fix real (migración 2026_07_15_120000): el CHECK huérfano
     * time_entries_type_check —que en Postgres bloqueaba break_start/waiting/temp_exit—
     * debe estar eliminado tras migrar. Es un bug ESPECÍFICO de Postgres: en SQLite el
     * ->change() a string reconstruyó la tabla sin el check, así que el test extendido de
     * arriba nunca ejercita el constraint. Este test corre en Postgres (skip en SQLite) y
     * atrapa una regresión donde alguien reintroduzca el enum o la migración deje de correr.
     */
    public function test_no_hay_check_huerfano_de_type_en_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El CHECK huérfano solo existe en Postgres; SQLite reconstruye la tabla sin él.');
        }

        $rows = DB::select(
            "SELECT conname FROM pg_constraint WHERE conname = 'time_entries_type_check'"
        );
        $this->assertCount(0, $rows, 'El CHECK huérfano de time_entries.type debe estar eliminado (bloqueaba break_start/waiting/temp_exit_*).');
    }

    /**
     * No se puede sincronizar un fichaje de un usuario de otro tenant (el mensaje ya
     * lo afirmaba, pero antes no se validaba el tenant del user_id destino).
     */
    public function test_sync_rejects_user_from_other_tenant(): void
    {
        [$tenant, $user] = $this->makeSetup('enterprise');
        $otherTenant = Tenant::create([
            'name' => 'Otro Tenant',
            'subdomain' => 'otro-tenant',
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
        $foreign = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Ajeno',
            'email' => 'ajeno@test.com',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $foreign->id,
            'date' => '2026-07-10',
            'type' => 'meal_reservation',
            'time' => '14:00',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('time_entries', ['user_id' => $foreign->id]);
    }

    /**
     * Un fichaje de asistencia con la hora en formato de display ("10:05 am") no debe
     * reventar: sync la normaliza antes de delegar a processPunch.
     */
    public function test_sync_tolerates_display_time_format(): void
    {
        [$tenant, $user] = $this->makeSetup('enterprise');
        $this->enableSimulatedTime($tenant->id);

        // ENMIENDA merge F3: la secuencia §15 exige turno abierto para el check_out; se abre con
        // un check_in directo en BD (lo que este test prueba es la TOLERANCIA DE FORMATO de hora).
        \DB::table('time_entries')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            // La fecha en la TZ DEL TENANT (la misma que usa processPunch): con now() del servidor
            // (UTC), entre 00:00 y 06:00 UTC el check_in caía en "mañana" y la secuencia no lo veía.
            'date' => now(\App\Helpers\TenantTimezone::for($tenant->id))->format('Y-m-d'),
            'type' => 'check_in', 'time' => '08:00:00',
            'is_late' => false, 'late_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id,
            'date' => '2026-07-10',
            'type' => 'check_out',
            'time' => '10:05 am',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $user->id,
            'type' => 'check_out',
        ]);
    }

    /**
     * El punishment_amount que se escribe en audit_logs usa la MISMA tarifa
     * configurable que la nómina (antes hardcodeaba `* 2`, quedando inconsistente).
     */
    public function test_sync_audit_punishment_uses_configurable_rate(): void
    {
        [$tenant, $user] = $this->makeSetup('enterprise');
        $this->enableSimulatedTime($tenant->id);
        \App\Models\LftSetting::create([
            'tenant_id' => $tenant->id,
            'lates_per_absence' => 3,
            'deduct_absence_day' => true,
            'absences_for_warning' => 3,
            'absences_for_suspension' => 4,
            'proportional_rest_day' => true,
            'late_tolerance_minutes' => 10,
            'meal_tolerance_minutes' => 15,
            'rest_tolerance_minutes' => 10,
            'late_action_mode' => 'deduct',
            'paid_rest_day' => true,
            'late_penalty_per_minute' => 5.00,
        ]);

        $this->actingAs($user)->postJson('/api/v1/sync/clock', [
            'user_id' => $user->id,
            'date' => '2026-07-10',
            'type' => 'check_in',
            'time' => '10:00:00',
            'is_late' => false,
        ])->assertStatus(200);

        $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_in')->first();
        $this->assertTrue((bool) $entry->is_late);

        $audit = DB::table('audit_logs')->where('user_id', $user->id)->where('type', 'check_in')->first();
        $this->assertNotNull($audit);
        $this->assertEquals((int) $entry->late_minutes * 5, (float) $audit->punishment_amount);
    }
}
