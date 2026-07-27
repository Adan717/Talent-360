<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PunctualityStreakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEmployee(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    private function checkIn(User $user, string $date, bool $late): void
    {
        DB::table('time_entries')->insert([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'date' => $date,
            'type' => 'check_in',
            'time' => '09:00:00',
            'is_late' => $late,
            'late_minutes' => $late ? 20 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_streak_counts_consecutive_on_time_check_ins(): void
    {
        $user = $this->makeEmployee();

        $this->checkIn($user, '2026-07-24', false);
        $this->checkIn($user, '2026-07-25', false);
        $this->checkIn($user, '2026-07-26', false);

        $response = $this->actingAs($user)->getJson('/api/v1/me/punctuality-streak');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'streak_days' => 3,
            'last_late_date' => null,
        ]);
    }

    public function test_recent_late_breaks_streak_and_reports_last_late_date(): void
    {
        $user = $this->makeEmployee();

        $this->checkIn($user, '2026-07-24', false);
        $this->checkIn($user, '2026-07-25', true);  // retardo más reciente corta la racha
        $this->checkIn($user, '2026-07-26', false); // hoy puntual → racha 1

        $response = $this->actingAs($user)->getJson('/api/v1/me/punctuality-streak');

        $response->assertStatus(200);
        $response->assertJson([
            'streak_days' => 1,
            'last_late_date' => '2026-07-25',
        ]);
    }

    public function test_old_late_does_not_break_current_streak_but_is_reported(): void
    {
        $user = $this->makeEmployee();

        $this->checkIn($user, '2026-07-20', true);   // retardo viejo
        $this->checkIn($user, '2026-07-24', false);
        $this->checkIn($user, '2026-07-25', false);
        $this->checkIn($user, '2026-07-26', false);

        $response = $this->actingAs($user)->getJson('/api/v1/me/punctuality-streak');

        $response->assertStatus(200);
        $response->assertJson([
            'streak_days' => 3,
            'last_late_date' => '2026-07-20',
        ]);
    }
}
