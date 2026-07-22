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

    private function assignResponsible(User $responsible): void
    {
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'employee_id' => $responsible->id,
            'priority_order' => 1,
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
}
