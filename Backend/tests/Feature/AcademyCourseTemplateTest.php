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
        // AC6: la inducción se llamaba 'Inducción DecorArte 360' — el nombre de una empresa
        // cliente dentro del catálogo de plantillas que se le ofrece a todas.
        $response->assertJsonFragment(['title' => 'Inducción a la Empresa']);
    }

    /**
     * AC6 — las plantillas ya no apuntan a puestos de una empresa concreta.
     *
     * Antes, la #2 declaraba `target_job_role_name = 'Cajeros'` y las once "Documento NN"
     * apuntaban a 'Sup. Tienda y Compras': nombres del organigrama de un solo cliente, que en
     * cualquier otra empresa no resolvían a nada. Ahora ninguna declara puesto, así que el curso
     * importado nace visible para TODA la plantilla (AC2: sin puesto = todos), incluso si la
     * empresa tiene por casualidad un puesto con aquel nombre.
     *
     * La resolución por nombre sigue viva en `importTemplate` para la plantilla que algún día
     * declare un puesto; lo que se quitó es el nombre ajeno, no la función.
     */
    public function test_el_curso_importado_queda_visible_para_toda_la_plantilla(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        // Un puesto con el nombre que la plantilla #4 traía escrito antes.
        JobRole::create([
            'name' => 'Sup. Tienda y Compras',
            'area' => 'Compras',
            'tenant_id' => 1,
            'esAperturador' => true,
            'tiempoTolerancia' => 15,
        ]);

        foreach ([2, 4] as $plantilla) {
            $this->actingAs($user)->postJson("/api/v1/academy/course-templates/{$plantilla}/import")
                ->assertStatus(201);
        }

        // El tenant 1 ya trae cursos sembrados por migración: se miran sólo los importados.
        $cursos = DB::table('academy_courses')->where('tenant_id', 1)->whereIn('title', [
            'Entrenamiento: Manejo de Caja Registradora',
            'Documento 06: Protocolo de Apertura de Operación',
        ])->get();

        $this->assertCount(2, $cursos, 'las dos plantillas deben haberse importado');

        foreach ($cursos as $curso) {
            $this->assertNull($curso->target_job_role_id,
                "'{$curso->title}' nació colgado de un puesto: sólo ese puesto lo vería (AC2)");
        }
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
