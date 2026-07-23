<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskAcademyLinkTest extends TestCase
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

    public function test_sync_tasks_accepts_and_persists_academy_lesson_id(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $lesson = AcademyCourse::create([
            'tenant_id' => 1,
            'title' => 'Cómo reponer góndola',
            'video_url' => 'https://www.youtube.com/embed/abc123',
            'course_type' => 'training',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/sync/tasks', [
            'tasks' => [
                ['id' => 601, 'title' => 'Reponer góndola', 'academy_lesson_id' => $lesson->id],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tasks', ['id' => 601, 'academy_lesson_id' => $lesson->id]);
    }

    public function test_sync_state_includes_video_url_of_the_linked_lesson(): void
    {
        $employee = $this->makeUser();
        $lesson = AcademyCourse::create([
            'tenant_id' => 1,
            'title' => 'Cómo abrir la caja',
            'video_url' => 'https://www.youtube.com/embed/xyz789',
            'course_type' => 'training',
            'is_active' => true,
        ]);
        Task::create([
            'id' => 602, 'title' => 'Abrir la caja', 'tenant_id' => 1, 'academy_lesson_id' => $lesson->id,
        ]);

        $response = $this->actingAs($employee)->getJson('/api/v1/sync/state');

        $response->assertStatus(200);
        $task = collect($response->json('tasks'))->firstWhere('id', 602);
        $this->assertNotNull($task);
        $this->assertEquals('https://www.youtube.com/embed/xyz789', $task['academy_lesson_video_url']);
    }

    public function test_update_profile_persists_academy_assistant_enabled_in_clock_preferences(): void
    {
        $employee = $this->makeUser();

        $response = $this->actingAs($employee)->postJson('/api/v1/me/update-profile', [
            'name' => $employee->name,
            'academy_assistant_enabled' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['academy_assistant_enabled' => true]);

        $stored = DB::table('employees')->where('user_id', $employee->id)->value('clock_preferences');
        $this->assertEquals(true, json_decode($stored, true)['academy_assistant_enabled']);
    }

    public function test_update_profile_does_not_touch_academy_preference_when_not_sent(): void
    {
        $employee = $this->makeUser();
        DB::table('employees')->where('user_id', $employee->id)->update([
            'clock_preferences' => json_encode(['academy_assistant_enabled' => true, 'other_pref' => 'x']),
        ]);

        $response = $this->actingAs($employee)->postJson('/api/v1/me/update-profile', [
            'name' => 'Nombre Actualizado',
        ]);

        $response->assertStatus(200);

        $stored = json_decode(DB::table('employees')->where('user_id', $employee->id)->value('clock_preferences'), true);
        $this->assertTrue($stored['academy_assistant_enabled']);
        $this->assertEquals('x', $stored['other_pref']);
    }
}
