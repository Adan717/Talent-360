<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\JobRole;
use App\Models\Task;
use App\Models\Vacancy;
use App\Models\AcademyCourse;

class OnboardingMateriasPrimasGiroTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_configure_materias_primas_giro_with_decorarte_roles_and_tasks()
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Repostería Test',
            'subdomain' => 'reposteriatest',
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@reposteriatest.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/admin/onboarding/configure-nicho', [
            'nicho' => 'materias_primas',
            'sub_nicho' => 'reposteria',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
        ]);

        // Validar que se hayan creado los 7 puestos de Decorarte 360
        $rolesCount = JobRole::where('tenant_id', $tenant->id)->count();
        $this->assertEquals(7, $rolesCount);

        $adminGerenteRole = JobRole::where('tenant_id', $tenant->id)->where('name', 'Administrador Gerente')->first();
        $this->assertNotNull($adminGerenteRole);
        $this->assertTrue((bool)$adminGerenteRole->esAperturador);

        // Validar que se hayan inyectado las 92 tareas
        $tasksCount = Task::where('tenant_id', $tenant->id)->count();
        $this->assertEquals(92, $tasksCount);

        // Validar vacantes y cursos
        $vacanciesCount = Vacancy::where('tenant_id', $tenant->id)->count();
        $this->assertGreaterThanOrEqual(1, $vacanciesCount);

        $coursesCount = AcademyCourse::where('tenant_id', $tenant->id)->count();
        $this->assertGreaterThanOrEqual(1, $coursesCount);
    }
}
