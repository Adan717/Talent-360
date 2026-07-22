<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreOpeningAssignmentValidationTest extends TestCase
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

        return $user;
    }

    public function test_create_assignment_accepts_a_valid_user_id_and_stores_it(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $employee = $this->makeUser();

        try {
            \App\Models\StoreOpeningAssignment::create([
                'tenant_id' => 1,
                'company_id' => 1,
                'store_id' => 1,
                'employee_id' => $employee->id,
                'priority_order' => 1,
            ]);
            file_put_contents(sys_get_temp_dir() . '/debug_users.txt', 'OK direct create');
        } catch (\Throwable $e) {
            file_put_contents(sys_get_temp_dir() . '/debug_users.txt', $e->getMessage());
        }

        $response = $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'employee_id' => $employee->id,
            'priority_order' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('store_opening_assignments', [
            'tenant_id' => 1,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_create_assignment_rejects_an_employees_table_id_that_is_not_a_valid_user_id(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // employees.id es una tabla y secuencia de ids totalmente distinta a users.id —
        // este id de employees no corresponde a ningún usuario real.
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => null,
            'name' => 'Solo Employee (sin cuenta de usuario)',
            'email' => 'solo-employee@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employeesTableId = DB::table('employees')->where('email', 'solo-employee@example.com')->value('id');

        $response = $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'employee_id' => $employeesTableId,
            'priority_order' => 1,
        ]);

        $response->assertStatus(422);
    }

    public function test_get_assignments_returns_the_correct_user_as_employee(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $employee = $this->makeUser();

        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'employee_id' => $employee->id,
            'priority_order' => 1,
            'can_open_store' => true,
            'can_close_store' => true,
            'has_keys' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/store-opening/assignments');

        $response->assertStatus(200);
        $response->assertJsonPath('0.employee.id', $employee->id);
        $response->assertJsonPath('0.employee.email', $employee->email);
    }
}
