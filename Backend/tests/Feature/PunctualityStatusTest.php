<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PunctualityStatusTest extends TestCase
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

    private function makeEmployee(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
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

    private function insertLate(User $user, string $date): void
    {
        DB::table('time_entries')->insert([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'date' => $date,
            'type' => 'check_in',
            'time' => '09:20:00',
            'is_late' => true,
            'late_minutes' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_blocks_after_three_lates_without_course_configured(): void
    {
        $user = $this->makeEmployee();
        $this->insertLate($user, '2026-06-01');
        $this->insertLate($user, '2026-06-08');
        $this->insertLate($user, '2026-06-15');

        $response = $this->actingAs($user)->getJson('/api/v1/me/punctuality-status');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'blocked' => true,
            'lates_count' => 3,
            'required_course_id' => null,
            'course_completed' => false,
        ]);
    }

    public function test_not_blocked_with_fewer_than_three_lates(): void
    {
        $user = $this->makeEmployee();
        $this->insertLate($user, '2026-06-01');
        $this->insertLate($user, '2026-06-08');

        $response = $this->actingAs($user)->getJson('/api/v1/me/punctuality-status');

        $response->assertStatus(200);
        $response->assertJson(['blocked' => false, 'lates_count' => 2]);
    }

    public function test_completing_required_course_unblocks(): void
    {
        $user = $this->makeEmployee();

        $courseId = DB::table('academy_courses')->insertGetId([
            'tenant_id' => 1,
            'title' => 'Curso de Puntualidad',
            'course_type' => 'training',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('system_settings')->insert([
            'tenant_id' => 1,
            'key' => 'punctuality_course_id',
            'value' => json_encode($courseId),
        ]);

        $this->insertLate($user, '2026-06-01');
        $this->insertLate($user, '2026-06-08');
        $this->insertLate($user, '2026-06-15');

        // Confirma que sin completar el curso, sigue bloqueado.
        $this->actingAs($user)->getJson('/api/v1/me/punctuality-status')
            ->assertJson(['blocked' => true, 'required_course_id' => $courseId]);

        DB::table('user_course_progress')->insert([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'course_id' => $courseId,
            'status' => 'completed',
            'completed_at' => '2026-06-20 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me/punctuality-status');

        $response->assertStatus(200);
        $response->assertJson([
            'blocked' => false,
            'lates_count' => 0,
            'course_completed' => true,
            'period_start' => '2026-06-20',
        ]);
    }

    public function test_reblocks_after_new_lates_following_completion(): void
    {
        $user = $this->makeEmployee();

        $courseId = DB::table('academy_courses')->insertGetId([
            'tenant_id' => 1,
            'title' => 'Curso de Puntualidad',
            'course_type' => 'training',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('system_settings')->insert([
            'tenant_id' => 1,
            'key' => 'punctuality_course_id',
            'value' => json_encode($courseId),
        ]);

        DB::table('user_course_progress')->insert([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'course_id' => $courseId,
            'status' => 'completed',
            'completed_at' => '2026-06-01 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Retardos previos a la finalización del curso no deben contar.
        $this->insertLate($user, '2026-05-01');

        $this->insertLate($user, '2026-06-05');
        $this->insertLate($user, '2026-06-12');
        $this->insertLate($user, '2026-06-19');

        $response = $this->actingAs($user)->getJson('/api/v1/me/punctuality-status');

        $response->assertStatus(200);
        $response->assertJson([
            'blocked' => true,
            'lates_count' => 3,
            'course_completed' => true,
        ]);
    }

    public function test_contingency_protected_dates_do_not_count_toward_block(): void
    {
        $user = $this->makeEmployee();
        $this->insertLate($user, '2026-06-01');
        $this->insertLate($user, '2026-06-08');
        $this->insertLate($user, '2026-06-15');

        DB::table('contingency_declarations')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'declared_by_user_id' => $user->id,
            'date' => '2026-06-15',
            'reason' => 'no_power',
            'declared_at' => '2026-06-15 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me/punctuality-status');

        $response->assertStatus(200);
        $response->assertJson(['blocked' => false, 'lates_count' => 2]);
    }
}
