<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R89 (Fase 4 / T4.1): baja operativa bloquea fichar.
 *
 * Un colaborador con `employees.is_active_employee = false` (archivado) NO puede INICIAR turno por
 * ningún camino. El login web ya frena a los archivados con `users.is_active = false`, PERO:
 *  - el `update` de RRHH puede poner `is_active_employee = false` SIN tocar `users.is_active`;
 *  - `EmployeeController::destroy` exime del `user.is_active = false` a los usuarios admin;
 *  - el KIOSKO ficha por PIN, no pasa por el login;
 * y `processPunch` (el choke point) no lo miraba → seguían fichando. Se enforcea ahí.
 *
 * Sólo `check_in`: no estrangula a quien fue archivado a mitad de turno (puede cerrar/comer).
 * `is_active_employee` null/true = activo (convención de los dashboards, que usan `!= false`).
 */
class ArchivedEmployeeClockTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(bool $active, string $role = 'empleado'): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Baja', 'subdomain' => 'baja' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $jr = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Piso']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'b' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => $role, 'is_active' => true,
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => $active, 'job_role_id' => $jr->id,
        ]);
        return [$tenant, $user];
    }

    private function attempt(User $user, string $type = 'check_in', $occurredAt = null): array
    {
        try {
            app(ClockService::class)->processPunch($user, $type, null, [], $occurredAt);
            return ['ok' => true, 'msg' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    public function test_archivado_no_puede_check_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [, $user] = $this->makeEmployee(false);
            $res = $this->attempt($user, 'check_in');

            $this->assertFalse($res['ok']);
            $this->assertStringContainsStringIgnoringCase('baja', $res['msg']);
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_activo_puede_check_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [, $user] = $this->makeEmployee(true);
            $res = $this->attempt($user, 'check_in');

            $this->assertTrue($res['ok'], $res['msg']);
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_archivado_puede_check_out_a_mitad_de_turno(): void
    {
        // Archivado DESPUÉS de iniciar turno: puede CERRAR (no se le estrangula la jornada abierta).
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [, $user] = $this->makeEmployee(true);
            app(ClockService::class)->processPunch($user, 'check_in');
            DB::table('employees')->where('user_id', $user->id)->update(['is_active_employee' => false]);

            $res = $this->attempt($user, 'check_out');

            $this->assertTrue($res['ok'], $res['msg']);
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_endpoint_real_bloquea_archivado(): void
    {
        // Camino HTTP real: is_active_employee=false pero user.is_active=true (el hueco del formulario).
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [, $user] = $this->makeEmployee(false);

            $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id, 'type' => 'check_in',
            ]);

            $res->assertStatus(400);
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_no_cruza_a_otro_tenant(): void
    {
        // Guard resuelve el expediente del PROPIO usuario/tenant; un archivado de otro tenant no afecta.
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [, $activo] = $this->makeEmployee(true);
            [, $archivado] = $this->makeEmployee(false);

            $this->assertTrue($this->attempt($activo, 'check_in')['ok']);
            $this->assertFalse($this->attempt($archivado, 'check_in')['ok']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_offline_no_bloquea_archivado(): void
    {
        // EXENTO OFFLINE (review R89 #1, bug de dinero): un check_in encolado ocurrió cuando la persona
        // estaba activa; rechazarlo por el estado de HOY borraría un día trabajado. occurredAt seteado.
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00'));
        try {
            [, $user] = $this->makeEmployee(false);
            $res = $this->attempt($user, 'check_in', '2026-07-10T09:00:00Z');

            $this->assertTrue($res['ok'], $res['msg']);
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_kiosk_bloquea_archivado(): void
    {
        // El camino MOTIVANTE: el kiosko ficha por PIN (sin login) y su propio guard sólo mira
        // `user.is_active` (aquí TRUE, el hueco del formulario). El guard de processPunch cierra el gap.
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        try {
            [$tenant, $user] = $this->makeEmployee(false); // is_active_employee=false, user.is_active=true
            $employee = Employee::withoutGlobalScopes()->where('user_id', $user->id)->first();
            $admin = User::create([
                'tenant_id' => $tenant->id, 'name' => 'Admin Host',
                'email' => 'adm' . uniqid() . '@t.local', 'password' => bcrypt('x'),
                'role' => 'admin', 'is_active' => true,
            ]);

            $this->actingAs($admin)
                ->postJson("/api/v1/admin/employees/{$employee->id}/kiosk-pin", ['pin' => '135790'])
                ->assertStatus(200);

            $res = $this->actingAs($admin)->postJson('/api/v1/kiosk/punch', [
                'pin' => '135790', 'type' => 'check_in',
            ]);

            $res->assertStatus(400);
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
