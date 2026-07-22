<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreOpeningAdminOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'pro',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'empleado'], $overrides));
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        // Fila "decoy" antes de la real para que employees.id nunca coincida por
        // accidente con users.id — si el código volviera a comparar mal (bug de
        // 2026-07-07: store_opening_assignments.employee_id es employees.id, pero
        // store_daily_opening_statuses.current_responsible_employee_id sigue siendo
        // users.id), este desfase lo delataría en vez de esconderlo.
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => null,
            'name' => 'Decoy',
            'email' => 'decoy-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    // store_opening_assignments.employee_id es employees.id (migración
    // 2026_07_07_192928_fix_store_opening_assignments_foreign_key), no users.id.
    private function assignResponsible(User $responsible, int $priority = 1): void
    {
        $employeeId = DB::table('employees')->where('user_id', $responsible->id)->value('id');

        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'employee_id' => $employeeId,
            'priority_order' => $priority,
            'can_open_store' => true,
            'can_close_store' => true,
            'has_keys' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_platform_admin_can_open_store_even_when_not_the_assigned_responsible(): void
    {
        $responsible = $this->makeUser();
        $this->assignResponsible($responsible);

        $platformAdmin = $this->makeUser(['role' => 'platform_admin']);

        $response = $this->actingAs($platformAdmin)->postJson('/api/v1/store-opening/open-and-clock-in', [
            'store_id' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'status' => 'opened',
            'opened_by_employee_id' => $platformAdmin->id,
        ]);
    }

    public function test_regular_employee_still_cannot_open_store_when_not_the_assigned_responsible(): void
    {
        $responsible = $this->makeUser();
        $this->assignResponsible($responsible);

        $otherEmployee = $this->makeUser();

        $response = $this->actingAs($otherEmployee)->postJson('/api/v1/store-opening/open-and-clock-in', [
            'store_id' => 1,
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'No eres el encargado responsable de la apertura en este momento.',
        ]);
    }

    public function test_assigned_responsible_can_open_store(): void
    {
        $responsible = $this->makeUser();
        $this->assignResponsible($responsible);

        $response = $this->actingAs($responsible)->postJson('/api/v1/store-opening/open-and-clock-in', [
            'store_id' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_handoff_to_next_responsible_resolves_the_real_users_id_of_the_backup(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-22 08:05:00');

        $primary = $this->makeUser();
        $this->assignResponsible($primary, 1);

        $backup = $this->makeUser();
        $this->assignResponsible($backup, 2);

        $this->actingAs($primary);

        app(\App\Services\StoreOpeningService::class)->getTodayOpeningStatus(1, 1);

        $handoff = app(\App\Services\StoreOpeningHandoffService::class)
            ->handoffToNextResponsible(1, $primary->id, 'report_absence', null, 1);

        $this->assertIsArray($handoff);
        $this->assertSame($backup->id, $handoff['next_responsible_id']);

        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'store_id' => 1,
            'current_responsible_employee_id' => $backup->id,
            'status' => 'transferred',
        ]);

        $openResponse = $this->actingAs($backup)->postJson('/api/v1/store-opening/open-and-clock-in', [
            'store_id' => 1,
        ]);

        $openResponse->assertStatus(200);
        $openResponse->assertJson(['success' => true]);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_get_assignments_exposes_resolved_user_id_alongside_the_raw_employees_id(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $employee = $this->makeUser();
        $this->assignResponsible($employee);

        $employeesTableId = DB::table('employees')->where('user_id', $employee->id)->value('id');

        $response = $this->actingAs($admin)->getJson('/api/v1/store-opening/assignments');

        $response->assertStatus(200);
        $response->assertJsonPath('0.employee_id', $employeesTableId);
        $response->assertJsonPath('0.resolved_user_id', $employee->id);
        $this->assertNotEquals($employeesTableId, $employee->id, 'El decoy debe garantizar que employees.id y users.id difieran en este test.');
    }
}
