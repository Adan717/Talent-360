<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncSettingsPermissionTest extends TestCase
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

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    public function test_regular_employee_cannot_write_tenant_settings(): void
    {
        $employee = $this->makeUser('empleado');

        $response = $this->actingAs($employee)->postJson('/api/v1/sync/settings', [
            'key' => 'punctuality_course_id',
            'value' => 4,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_write_tenant_settings(): void
    {
        $admin = $this->makeUser('admin');

        $courseId = DB::table('academy_courses')->insertGetId([
            'tenant_id' => 1,
            'title' => 'Curso de Puntualidad',
            'course_type' => 'training',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/sync/settings', [
            'key' => 'punctuality_course_id',
            'value' => $courseId,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('system_settings', [
            'tenant_id' => 1,
            'key' => 'punctuality_course_id',
            'value' => (string) $courseId,
        ]);
    }

    public function test_supervisor_cannot_set_punctuality_course_even_though_supervisor_can_write_other_settings(): void
    {
        $supervisor = $this->makeUser('supervisor');

        // Un supervisor sí puede modificar configuración general del reloj checador...
        $generalResponse = $this->actingAs($supervisor)->postJson('/api/v1/sync/settings', [
            'key' => 'timezone',
            'value' => 'America/Mexico_City',
        ]);
        $generalResponse->assertStatus(200);

        // ...pero no la llave específica del curso de puntualidad, reservada a admin.
        $response = $this->actingAs($supervisor)->postJson('/api/v1/sync/settings', [
            'key' => 'punctuality_course_id',
            'value' => 1,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_cannot_set_nonexistent_course_as_punctuality_course(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/sync/settings', [
            'key' => 'punctuality_course_id',
            'value' => 99999,
        ]);

        $response->assertStatus(422);
    }
}
