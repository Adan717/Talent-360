<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobRoleTemplateControllerTest extends TestCase
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
        
        DB::table('tenants')->insertOrIgnore([
            'id' => 2,
            'name' => 'Second Tenant',
            'subdomain' => 'second',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_can_list_templates_and_filter_by_industry(): void
    {
        // Insert dummy templates
        DB::table('job_role_templates')->insert([
            [
                'name' => 'Gerente General',
                'area' => 'Dirección',
                'industry' => 'oficina',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 15,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Cajero',
                'area' => 'Cajas',
                'industry' => 'retail',
                'default_schedule_start' => '09:00',
                'default_schedule_end' => '18:00',
                'default_tolerance_mins' => 10,
                'default_meal_mins' => 60,
                'is_opener' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // Get all
        $response = $this->getJson('/api/v1/job-role-templates');
        $response->assertStatus(200);
        $response->assertJsonCount(2);

        // Get filtered by industry=retail
        $response = $this->getJson('/api/v1/job-role-templates?industry=retail');
        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'Cajero']);
    }

    public function test_can_import_template_to_tenant(): void
    {
        // Insert a dummy template
        $templateId = DB::table('job_role_templates')->insertGetId([
            'name' => 'Cajero de Prueba',
            'area' => 'Cajas',
            'industry' => 'retail',
            'default_schedule_start' => '09:00',
            'default_schedule_end' => '18:00',
            'default_tolerance_mins' => 10,
            'default_meal_mins' => 60,
            'is_opener' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 2]);
        $user->refresh();

        $response = $this->actingAs($user)->postJson("/api/v1/job-role-templates/{$templateId}/import");
        $response->assertStatus(201);
        
        // Assert JobRole was created for tenant 2
        $this->assertDatabaseHas('job_roles', [
            'name' => 'Cajero de Prueba',
            'tenant_id' => 2,
            'esAperturador' => 0,
            'tiempoTolerancia' => 10,
        ]);

        // Assert RoleClockPolicy was created
        $this->assertDatabaseHas('role_clock_policies', [
            'policy_name' => 'Perfil Cajero de Prueba',
        ]);
    }
}
