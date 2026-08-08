<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrgCycleRatingsMealPhotoTest extends TestCase
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

    private function makeUser(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    // §21 --------------------------------------------------------------------

    public function test_reports_to_role_ids_rejects_a_cycle(): void
    {
        $admin = $this->makeUser('admin');

        $roleA = JobRole::create(['name' => 'Rol A', 'area' => 'Ventas', 'tenant_id' => 1]);
        $roleB = JobRole::create(['name' => 'Rol B', 'area' => 'Ventas', 'tenant_id' => 1, 'reports_to_role_ids' => [$roleA->id]]);

        // A -> reporta a B, pero B ya reporta a A: crearía un ciclo A->B->A.
        $response = $this->actingAs($admin)->putJson("/api/v1/job-roles/{$roleA->id}", [
            'reports_to_role_ids' => [$roleB->id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ciclo', $response->json('message'));
    }

    public function test_reports_to_role_ids_allows_a_valid_non_cyclic_chain(): void
    {
        $admin = $this->makeUser('admin');

        $roleA = JobRole::create(['name' => 'Rol A', 'area' => 'Ventas', 'tenant_id' => 1]);
        $roleB = JobRole::create(['name' => 'Rol B', 'area' => 'Ventas', 'tenant_id' => 1]);

        $response = $this->actingAs($admin)->putJson("/api/v1/job-roles/{$roleB->id}", [
            'reports_to_role_ids' => [$roleA->id],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('job_roles', ['id' => $roleB->id]);
    }

    // §22 ----------------------------------------------------------------------

    public function test_responsible_opener_can_submit_pase_lista_ratings_idempotently(): void
    {
        $responsible = $this->makeUser('empleado');
        $colleague = $this->makeUser('empleado');

        DB::table('store_daily_opening_statuses')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'date' => now()->format('Y-m-d'),
            'scheduled_opening_time' => '08:30:00',
            'pre_opening_window_start' => '08:15:00',
            'report_deadline' => '08:35:00',
            'current_responsible_employee_id' => $responsible->id,
            'status' => 'opened',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'date' => now()->format('Y-m-d'),
            'ratings' => [
                ['employee_id' => $colleague->id, 'presentacion' => 5, 'imagen' => 4, 'energia' => 5],
            ],
        ];

        $first = $this->actingAs($responsible)->postJson('/api/v1/clock/pase-lista/ratings', $payload);
        $first->assertStatus(200);
        $this->assertDatabaseCount('pase_lista_ratings', 1);

        // Recalificar el mismo día actualiza, no duplica.
        $payload['ratings'][0]['presentacion'] = 3;
        $second = $this->actingAs($responsible)->postJson('/api/v1/clock/pase-lista/ratings', $payload);
        $second->assertStatus(200);
        $this->assertDatabaseCount('pase_lista_ratings', 1);
        $this->assertDatabaseHas('pase_lista_ratings', ['employee_id' => $colleague->id, 'presentacion' => 3]);
    }

    public function test_non_responsible_employee_cannot_submit_ratings(): void
    {
        $responsible = $this->makeUser('empleado');
        $bystander = $this->makeUser('empleado');
        $colleague = $this->makeUser('empleado');

        DB::table('store_daily_opening_statuses')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'date' => now()->format('Y-m-d'),
            'scheduled_opening_time' => '08:30:00',
            'pre_opening_window_start' => '08:15:00',
            'report_deadline' => '08:35:00',
            'current_responsible_employee_id' => $responsible->id,
            'status' => 'opened',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($bystander)->postJson('/api/v1/clock/pase-lista/ratings', [
            'date' => now()->format('Y-m-d'),
            'ratings' => [
                ['employee_id' => $colleague->id, 'presentacion' => 5, 'imagen' => 4, 'energia' => 5],
            ],
        ]);

        $response->assertStatus(403);
    }

    // §23 -----------------------------------------------------------------------

    private function makeBase64Image(int $bytes = 100): string
    {
        return 'data:image/jpeg;base64,' . base64_encode(random_bytes($bytes));
    }

    public function test_meal_photo_upload_succeeds_and_persists_record(): void
    {
        $user = $this->makeUser('empleado');

        $response = $this->actingAs($user)->postJson('/api/v1/clock/meal-photo', [
            'type' => 'meal_start',
            'date' => now()->format('Y-m-d'),
            'image' => $this->makeBase64Image(),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('url'));

        $this->assertDatabaseHas('meal_photo_evidences', [
            'employee_id' => $user->id,
            'type' => 'meal_start',
        ]);

        // Desde 2026-08-08 la evidencia vive en el disco PRIVADO y `path` es una ruta
        // relativa a él. Antes esta prueba comprobaba un archivo dentro de `public/`, que
        // era justamente el agujero: servido por nginx sin autenticación. El contrato
        // completo está en EvidenciaComedorPrivadaTest.
        $path = DB::table('meal_photo_evidences')->where('employee_id', $user->id)->value('path');
        $this->assertStringStartsWith('meal-evidence/', $path);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($path);

        \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
    }

    public function test_meal_photo_rejects_invalid_format(): void
    {
        $user = $this->makeUser('empleado');

        $response = $this->actingAs($user)->postJson('/api/v1/clock/meal-photo', [
            'type' => 'meal_start',
            'date' => now()->format('Y-m-d'),
            'image' => 'not-a-valid-data-uri',
        ]);

        $response->assertStatus(422);
    }

    public function test_meal_photo_rejects_oversized_image(): void
    {
        $user = $this->makeUser('empleado');

        $response = $this->actingAs($user)->postJson('/api/v1/clock/meal-photo', [
            'type' => 'meal_end',
            'date' => now()->format('Y-m-d'),
            'image' => $this->makeBase64Image(3 * 1024 * 1024),
        ]);

        $response->assertStatus(422);
    }

    public function test_purge_command_deletes_old_evidence_only(): void
    {
        $user = $this->makeUser('empleado');

        $oldPath = sys_get_temp_dir() . '/old_evidence_test.jpg';
        file_put_contents($oldPath, 'fake');
        $recentPath = sys_get_temp_dir() . '/recent_evidence_test.jpg';
        file_put_contents($recentPath, 'fake');

        DB::table('meal_photo_evidences')->insert([
            'tenant_id' => 1,
            'employee_id' => $user->id,
            'date' => now()->subDays(120)->format('Y-m-d'),
            'type' => 'meal_start',
            'url' => '/old.jpg',
            'path' => $oldPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('meal_photo_evidences')->insert([
            'tenant_id' => 1,
            'employee_id' => $user->id,
            'date' => now()->subDays(5)->format('Y-m-d'),
            'type' => 'meal_start',
            'url' => '/recent.jpg',
            'path' => $recentPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('meal-evidence:purge', ['--days' => 90])->assertExitCode(0);

        $this->assertDatabaseCount('meal_photo_evidences', 1);
        $this->assertDatabaseHas('meal_photo_evidences', ['url' => '/recent.jpg']);
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($recentPath);

        @unlink($recentPath);
    }
}
