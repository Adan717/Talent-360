<?php

namespace Tests\Feature;

use App\Events\StoreOpened;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StoreOpeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant(1, 'Tenant Uno');
        $this->seedTenant(2, 'Tenant Dos');
        // En producción users.id y employees.id difieren (secuencias desacopladas);
        // en un test fresco arrancan alineadas. Este usuario "decoy" desalinea las
        // secuencias para que employee_id != user_id y el bug de espacios de ID se
        // reproduzca fielmente.
        User::factory()->create(['role' => 'empleado']);
    }

    private function seedTenant(int $id, string $name): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => $id,
            'name' => $name,
            'subdomain' => 'tenant' . $id,
            'public_slug' => 'tenant' . $id,
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(int $tenantId, string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        return User::withoutGlobalScopes()->find($user->id);
    }

    private function makeEmployee(int $tenantId, string $name = 'Empleado X'): int
    {
        return DB::table('employees')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . $tenantId . '@t.local',
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Crea un empleado con su usuario vinculado (employees.user_id -> users.id).
     * Devuelve ['user' => User, 'employee_id' => int]. Los ids de user y employee
     * NO coinciden (users y employees están desacoplados), que es justo la condición
     * que dispara el bug de espacios de ID.
     */
    private function makeEmployeeWithUser(int $tenantId, string $role = 'empleado', string $name = 'Encargado'): array
    {
        $user = $this->makeUser($tenantId, $role);
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . $user->id . '@t.local',
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['user' => $user, 'employee_id' => $employeeId];
    }

    private function makeAssignment(int $tenantId, int $employeeId, int $priority): void
    {
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => $tenantId,
            'company_id' => 1,
            'store_id' => 1,
            'employee_id' => $employeeId,
            'priority_order' => $priority,
            'can_open_store' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    public function test_create_assignment_happy_path(): void
    {
        $admin = $this->makeUser(1, 'admin');
        $employeeId = $this->makeEmployee(1);

        $response = $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'employee_id' => $employeeId,
            'priority_order' => 1,
            'can_open_store' => true,
            'is_active' => true,
        ]);

        $response->assertStatus(200)->assertJsonFragment(['success' => true]);
        $this->assertDatabaseHas('store_opening_assignments', [
            'tenant_id' => 1,
            'employee_id' => $employeeId,
            'priority_order' => 1,
        ]);
    }

    /**
     * Fix de fuga cross-tenant: no se puede asignar a la apertura un empleado
     * de otro tenant (la regla exists ahora está acotada por tenant_id).
     */
    public function test_create_assignment_rejects_employee_from_other_tenant(): void
    {
        $admin = $this->makeUser(1, 'admin');
        $foreignEmployeeId = $this->makeEmployee(2);

        $response = $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'employee_id' => $foreignEmployeeId,
            'priority_order' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('store_opening_assignments', [
            'tenant_id' => 1,
            'employee_id' => $foreignEmployeeId,
        ]);
    }

    /**
     * Baseline verde del happy path de apertura + fichaje, con un opener admin
     * (pasa por el override administrativo, no depende del bug de IDs de Ronda 2).
     * Se usa tiempo simulado dentro de la ventana activa para evitar disparar el
     * path de handoff. NO se crea ninguna asignación: eso evita el bug conocido
     * de la línea 85 de StoreOpeningService (que se aborda en Ronda 2).
     */
    public function test_open_store_and_clock_in_happy_path_with_admin_opener(): void
    {
        $admin = $this->makeUser(1, 'admin');

        // time_mode simulado para que el backend honre el simTime enviado.
        DB::table('system_settings')->insert([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => '"simulated"',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/store-opening/open-and-clock-in', [
            'simTime' => '08:16:00', // dentro de la ventana activa (open 08:30, pre 15min)
        ]);

        $response->assertStatus(200)->assertJsonFragment(['success' => true]);

        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'status' => 'opened',
            'opened_by_employee_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('time_entries', [
            'tenant_id' => 1,
            'user_id' => $admin->id,
            'type' => 'check_in',
        ]);
    }

    /**
     * La ruta premium (open-and-clock-in) debe emitir StoreOpened por WebSocket, igual que
     * la legacy /sync/store_log. El FE (useClockEngine) escucha `.App\Events\StoreOpened` en
     * `tenant.{id}.clock` para avisar la apertura y refrescar. Antes de R28 el premium abría
     * por la legacy (que sí emite); tras R28 la ruta canónica premium quedó muda → los
     * tenants premium dejaron de recibir el aviso en tiempo real.
     */
    public function test_open_and_clock_in_broadcasts_store_opened(): void
    {
        Event::fake([StoreOpened::class]);
        $admin = $this->makeUser(1, 'admin');
        $foreign = $this->makeUser(2, 'empleado'); // usuario de OTRO tenant
        DB::table('system_settings')->insert([
            'tenant_id' => 1, 'key' => 'time_mode', 'value' => '"simulated"',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/api/v1/store-opening/open-and-clock-in', [
            'simTime' => '08:16:00',
        ])->assertStatus(200);

        Event::assertDispatched(StoreOpened::class, function (StoreOpened $e) use ($admin, $foreign) {
            return (int) $e->tenantId === 1
                && in_array($admin->id, $e->approved_employees)        // el opener sí
                && !in_array($foreign->id, $e->approved_employees);    // el ajeno NO (scoping)
        });
    }

    /**
     * Un intento de apertura sobre una tienda YA abierta lanza antes de emitir el evento;
     * no debe haber broadcast fantasma de apertura.
     */
    public function test_already_open_store_does_not_broadcast(): void
    {
        Event::fake([StoreOpened::class]);
        $admin = $this->makeUser(1, 'admin');
        DB::table('system_settings')->insert([
            'tenant_id' => 1, 'key' => 'time_mode', 'value' => '"simulated"',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Primera apertura: OK y emite.
        $this->actingAs($admin)->postJson('/api/v1/store-opening/open-and-clock-in', ['simTime' => '08:16:00'])
            ->assertStatus(200);
        // Segunda: la tienda ya está abierta → rechazo, sin nuevo broadcast.
        $this->actingAs($admin)->postJson('/api/v1/store-opening/open-and-clock-in', ['simTime' => '08:20:00'])
            ->assertStatus(400);

        Event::assertDispatchedTimes(StoreOpened::class, 1);
    }

    // ---- Ronda 2: bug de espacios de ID (employees.id vs users.id) ----

    /**
     * El status del día debe guardar el responsable como users.id (la columna es
     * FK a users), no como employees.id. Antes del fix, getTodayOpeningStatus mete
     * el employee_id del primer responsable en una columna con FK a users → id
     * equivocado (y en Postgres, violación de FK / 500).
     */
    public function test_opening_status_resolves_responsible_as_user_id(): void
    {
        $resp = $this->makeEmployeeWithUser(1, 'empleado', 'Responsable Uno');
        $this->makeAssignment(1, $resp['employee_id'], 1);
        $this->enableSimulatedTime(1);

        $response = $this->actingAs($resp['user'])
            ->getJson('/api/v1/store-opening/today?simTime=08:16:00');

        $response->assertStatus(200);
        // El responsable debe ser el users.id del empleado, NO su employee_id.
        $response->assertJsonPath('status.current_responsible_employee_id', $resp['user']->id);
        $this->assertNotEquals($resp['employee_id'], $resp['user']->id, 'precondición: los ids deben diferir');
    }

    /**
     * Un empleado con rol 'empleado' (no admin/supervisor) que ES el responsable
     * asignado debe poder abrir la tienda. Antes del fix, la comparación
     * current_responsible_employee_id(=employee_id) !== user->id lo bloqueaba con
     * "no eres el encargado responsable".
     */
    public function test_regular_employee_responsible_can_open_store(): void
    {
        $resp = $this->makeEmployeeWithUser(1, 'empleado', 'Responsable Regular');
        $this->assertNotEquals($resp['employee_id'], $resp['user']->id, 'precondición: los ids deben diferir');
        $this->makeAssignment(1, $resp['employee_id'], 1);
        $this->enableSimulatedTime(1);

        $response = $this->actingAs($resp['user'])
            ->postJson('/api/v1/store-opening/open-and-clock-in', ['simTime' => '08:16:00']);

        $response->assertStatus(200)->assertJsonFragment(['success' => true]);
        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'status' => 'opened',
            'opened_by_employee_id' => $resp['user']->id,
        ]);
        $this->assertDatabaseHas('time_entries', [
            'tenant_id' => 1,
            'user_id' => $resp['user']->id,
            'type' => 'check_in',
        ]);
    }

    /**
     * Al reportar ausencia el responsable primario, la responsabilidad se cede al
     * siguiente en jerarquía, y current_responsible_employee_id debe pasar al
     * users.id del segundo responsable (no a su employee_id). Ejercita la
     * traducción de ida y vuelta en handoffToNextResponsible.
     */
    public function test_handoff_transfers_to_next_responsible_user(): void
    {
        $first = $this->makeEmployeeWithUser(1, 'empleado', 'Primero');
        $second = $this->makeEmployeeWithUser(1, 'empleado', 'Segundo');
        $this->assertNotEquals($first['employee_id'], $first['user']->id, 'precondición: los ids deben diferir');
        $this->assertNotEquals($second['employee_id'], $second['user']->id, 'precondición: los ids deben diferir');
        $this->makeAssignment(1, $first['employee_id'], 1);
        $this->makeAssignment(1, $second['employee_id'], 2);
        $this->enableSimulatedTime(1);

        $response = $this->actingAs($first['user'])
            ->postJson('/api/v1/store-opening/report-absence', ['simTime' => '08:16:00']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'current_responsible_employee_id' => $second['user']->id,
            'status' => 'transferred',
        ]);
    }

    /**
     * Si el siguiente responsable en jerarquía es un empleado SIN usuario vinculado
     * (no puede iniciar sesión ni abrir), la cesión no es válida: el status debe
     * quedar 'failed' (con alerta crítica), no 'transferred' con responsable null.
     */
    public function test_handoff_fails_when_next_responsible_has_no_linked_user(): void
    {
        $first = $this->makeEmployeeWithUser(1, 'empleado', 'Primero');
        $orphanEmployeeId = $this->makeEmployee(1, 'Sin Usuario'); // employee sin user_id
        $this->makeAssignment(1, $first['employee_id'], 1);
        $this->makeAssignment(1, $orphanEmployeeId, 2);
        $this->enableSimulatedTime(1);

        $response = $this->actingAs($first['user'])
            ->postJson('/api/v1/store-opening/report-absence', ['simTime' => '08:16:00']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'status' => 'transferred',
        ]);
    }

    /** Siembra la fila completa de store_opening_settings con un valor de allow_late dado. */
    private function seedOpeningSettings(int $tenantId, bool $allowLate): void
    {
        DB::table('store_opening_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'store_id' => 1],
            [
                'company_id' => 1, 'pre_opening_window_minutes' => 15,
                'absence_late_report_window_minutes' => 5,
                'allow_automatic_handoff' => true, 'allow_late_if_before_opening' => $allowLate,
                'allow_store_closed_report' => true, 'enable_amnesty_if_store_closed' => true,
                'require_opening_checklist' => true, 'require_opening_roll_call' => true,
                'notify_admin_on_handoff' => true, 'notify_supervisor_on_handoff' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }

    /**
     * R70: con `allow_late_if_before_opening` = TRUE (el DEFAULT), reportar retardo NO cede la
     * apertura — el responsable la conserva. Antes, el `|| $willBeLate` cedía siempre.
     */
    public function test_report_late_conserva_la_apertura_si_el_ajuste_lo_permite(): void
    {
        $first = $this->makeEmployeeWithUser(1, 'empleado', 'Primero');
        $second = $this->makeEmployeeWithUser(1, 'empleado', 'Segundo');
        $this->makeAssignment(1, $first['employee_id'], 1);
        $this->makeAssignment(1, $second['employee_id'], 2);
        $this->enableSimulatedTime(1);
        $this->seedOpeningSettings(1, allowLate: true);

        $response = $this->actingAs($first['user'])
            ->postJson('/api/v1/store-opening/report-late', [
                'estimated_arrival_time' => '09:00', 'simTime' => '07:00:00', // 09:00 > apertura 08:30
            ]);

        $response->assertStatus(200);
        // NO se transfirió: el primero sigue de responsable.
        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1, 'current_responsible_employee_id' => $first['user']->id,
        ]);
        $this->assertDatabaseMissing('store_daily_opening_statuses', [
            'tenant_id' => 1, 'status' => 'transferred',
        ]);
    }

    /**
     * R70: con `allow_late_if_before_opening` = FALSE, reportar retardo SÍ cede la apertura al
     * suplente (el ajuste ahora manda; antes era letra muerta).
     */
    public function test_report_late_cede_la_apertura_si_el_ajuste_no_lo_permite(): void
    {
        $first = $this->makeEmployeeWithUser(1, 'empleado', 'Primero');
        $second = $this->makeEmployeeWithUser(1, 'empleado', 'Segundo');
        $this->makeAssignment(1, $first['employee_id'], 1);
        $this->makeAssignment(1, $second['employee_id'], 2);
        $this->enableSimulatedTime(1);
        $this->seedOpeningSettings(1, allowLate: false);

        $response = $this->actingAs($first['user'])
            ->postJson('/api/v1/store-opening/report-late', [
                'estimated_arrival_time' => '09:00', 'simTime' => '07:00:00',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'current_responsible_employee_id' => $second['user']->id,
            'status' => 'transferred',
        ]);
    }
}
