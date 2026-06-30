<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AcademyCourseTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the fallback tenant needed due to BelongsToTenant trait
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

    public function test_can_list_course_templates(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        $response = $this->actingAs($user)->getJson('/api/v1/academy/course-templates');
        $response->assertStatus(200);
        
        // Assert we get list of 14 course templates
        $response->assertJsonCount(14);
        $response->assertJsonFragment(['title' => 'Inducción DecorArte 360']);
    }

    public function test_can_import_course_template_and_resolve_job_role_by_name(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        // Create local job role to map name 'Cajeros'
        $jobRole = JobRole::create([
            'name' => 'Cajeros',
            'area' => 'Cajas',
            'tenant_id' => 1,
            'esAperturador' => false,
            'tiempoTolerancia' => 10,
        ]);

        // Template ID 2 is "Entrenamiento: Manejo de Caja Registradora" which has target_job_role_name = 'Cajeros'
        $response = $this->actingAs($user)->postJson('/api/v1/academy/course-templates/2/import');
        $response->assertStatus(201);
        
        $this->assertDatabaseHas('academy_courses', [
            'title' => 'Entrenamiento: Manejo de Caja Registradora',
            'tenant_id' => 1,
            'target_job_role_id' => $jobRole->id,
        ]);
    }

    public function test_can_import_course_template_with_mapped_job_role_name(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        // Create local job role to map name 'Sup. Tienda y Compras'
        $jobRole = JobRole::create([
            'name' => 'Sup. Tienda y Compras',
            'area' => 'Compras',
            'tenant_id' => 1,
            'esAperturador' => true,
            'tiempoTolerancia' => 15,
        ]);

        // Template ID 4 is "Documento 06: Protocolo de Apertura de Operación" which has target_job_role_name = 'Sup. Tienda y Compras'
        $response = $this->actingAs($user)->postJson('/api/v1/academy/course-templates/4/import');
        $response->assertStatus(201);
        
        $this->assertDatabaseHas('academy_courses', [
            'title' => 'Documento 06: Protocolo de Apertura de Operación',
            'tenant_id' => 1,
            'target_job_role_id' => $jobRole->id,
        ]);
    }

    public function test_import_non_existing_template_returns_404(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        $response = $this->actingAs($user)->postJson('/api/v1/academy/course-templates/999/import');
        $response->assertStatus(404);
    }
}
