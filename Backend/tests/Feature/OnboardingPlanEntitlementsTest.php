<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El wizard es una ayuda visual, no una frontera de permisos. Estas pruebas fijan que una
 * empresa Free no puede recibir registros de ATS o Academia aunque alguien altere el POST.
 */
class OnboardingPlanEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 27;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Freemium QA',
            'subdomain' => 'freemiumqa',
            'plan' => 'freemium',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        return $user->fresh();
    }

    public function test_catalogo_expone_solo_las_capacidades_free_del_onboarding(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/onboarding/catalogo?nicho=retail')
            ->assertOk()
            ->assertJsonPath('capabilities.tareas', true)
            ->assertJsonPath('capabilities.vacantes_ats', false)
            ->assertJsonPath('capabilities.cursos_academia', false);
    }

    public function test_freemium_no_puede_inyectar_ats_ni_academia_aun_con_payload_manipulado(): void
    {
        $catalogo = \App\Support\CatalogoOnboarding::para('retail');

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/onboarding/configure-nicho', [
                'nicho' => 'retail',
                'selected_puestos' => $catalogo['puestos'],
                'selected_tareas' => $catalogo['tareas'],
                // Estas dos listas imitan una petición editada desde DevTools.
                'selected_cursos' => $catalogo['cursos'],
            ])
            ->assertOk();

        $this->assertCount(count($catalogo['puestos']), DB::table('job_roles')->where('tenant_id', $this->tenantId)->get());
        $this->assertCount(count($catalogo['tareas']), DB::table('tasks')->where('tenant_id', $this->tenantId)->get());
        $this->assertSame(0, DB::table('vacancies')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(0, DB::table('academy_courses')->where('tenant_id', $this->tenantId)->count());
    }
}
