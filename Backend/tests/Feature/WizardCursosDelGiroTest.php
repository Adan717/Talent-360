<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Academia AC1 y AC2 (auditoría del módulo, 2026-08-04).
 *
 * AC1 — la selección de cursos del asistente era teatro. El bloque 1D dejaba marcar cursos, pero
 * `handleConfigureNicho` nunca mandaba `selected_cursos`: el servidor caía SIEMPRE a una lista
 * escrita a mano dentro de `configureNicho`, con la que el dueño no tenía nada que ver.
 *
 * AC2 — todos los cursos se colgaban del puesto de mando (`target_job_role_id = $firstGerenteRole`),
 * así que sólo el gerente veía alguno: el cajero recién dado de alta no tenía ni inducción.
 * Medido en la V2: de los tres colaboradores del tenant 2, dos veían CERO cursos.
 *
 * Los cursos viven ahora en el catálogo del giro, igual que puestos y tareas, y se reparten por
 * el `target_role_name` que declaren (o a toda la plantilla si no declaran ninguno).
 */
class WizardCursosDelGiroTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 8;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Tienda QA', 'subdomain' => 'tiendaqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        return $user->fresh();
    }

    private function aplicarGiro(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->postJson('/api/v1/admin/onboarding/configure-nicho',
            array_merge(['nicho' => 'retail'], $extra));
    }

    private function cursos(): \Illuminate\Support\Collection
    {
        return DB::table('academy_courses')->where('tenant_id', $this->tenantId)->get();
    }

    public function test_el_catalogo_del_giro_sirve_sus_cursos_al_asistente(): void
    {
        $respuesta = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/onboarding/catalogo?nicho=retail');

        $respuesta->assertStatus(200);

        $cursos = $respuesta->json('cursos');
        $this->assertNotEmpty($cursos, 'sin esto el asistente no tiene qué ofrecer y vuelve al teatro de AC1');

        // Viajan COMPLETOS: antes sólo se mandaban títulos y el servidor inventaba la
        // descripción ("precargado desde el Wizard") y adivinaba el tipo buscando palabras.
        $primero = $cursos[0];
        $this->assertArrayHasKey('description', $primero);
        $this->assertArrayHasKey('course_type', $primero);
        $this->assertArrayHasKey('target_role_name', $primero);
    }

    public function test_se_inyectan_los_cursos_que_el_dueno_eligio_y_no_otros(): void
    {
        $catalogo = \App\Support\CatalogoOnboarding::para('retail')['cursos'];
        $elegidos = [$catalogo[0], $catalogo[2]];

        $this->aplicarGiro(['selected_cursos' => $elegidos])->assertStatus(200);

        $titulos = $this->cursos()->pluck('title')->all();

        $this->assertCount(2, $titulos, 'sólo deben entrar los elegidos; antes entraba la lista del servidor');
        $this->assertContains($catalogo[0]['title'], $titulos);
        $this->assertContains($catalogo[2]['title'], $titulos);
        $this->assertNotContains($catalogo[1]['title'], $titulos, 'el curso que el dueño desmarcó no debe entrar');
    }

    public function test_el_curso_elegido_conserva_su_descripcion_y_su_tipo(): void
    {
        $catalogo = \App\Support\CatalogoOnboarding::para('retail')['cursos'];
        $induccion = collect($catalogo)->firstWhere('course_type', 'induction');

        $this->aplicarGiro(['selected_cursos' => [$induccion]])->assertStatus(200);

        $curso = $this->cursos()->firstWhere('title', $induccion['title']);

        $this->assertSame($induccion['description'], $curso->description);
        $this->assertSame('induction', $curso->course_type);
        $this->assertNotEmpty(json_decode($curso->quiz_data, true), 'el examen del catálogo viaja con el curso');
    }

    public function test_sin_seleccion_entra_el_catalogo_del_giro_completo(): void
    {
        $this->aplicarGiro()->assertStatus(200);

        $esperados = array_column(\App\Support\CatalogoOnboarding::para('retail')['cursos'], 'title');

        $this->assertEqualsCanonicalizing($esperados, $this->cursos()->pluck('title')->all());
    }

    public function test_los_cursos_ya_no_cuelgan_todos_del_puesto_de_mando(): void
    {
        $this->aplicarGiro()->assertStatus(200);

        $mando = DB::table('job_roles')->where('tenant_id', $this->tenantId)
            ->where('jerarquiaLlaves', 1)->first();

        $delMando = $this->cursos()->where('target_job_role_id', $mando->id);

        $this->assertCount(0, $delMando,
            'antes TODOS los cursos apuntaban al puesto de mando y nadie más veía ninguno (AC2)');
    }

    public function test_un_colaborador_de_piso_ve_los_cursos_de_la_empresa(): void
    {
        $this->aplicarGiro()->assertStatus(200);

        // El puesto más bajo del giro: el que antes no veía absolutamente nada.
        $piso = DB::table('job_roles')->where('tenant_id', $this->tenantId)
            ->orderByDesc('jerarquiaLlaves')->first();

        $empleado = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $empleado->id)
            ->update(['tenant_id' => $this->tenantId, 'job_role_id' => $piso->id]);

        $cursos = $this->actingAs($empleado->fresh())->getJson('/api/v1/academy/courses')->json('courses');

        // Mismo filtro que aplica la Academia: sin puesto declarado, o el suyo.
        $visibles = collect($cursos)->filter(
            fn ($c) => empty($c['target_job_role_id']) || $c['target_job_role_id'] === $piso->id
        );

        $this->assertNotEmpty($visibles, "el puesto '{$piso->name}' no ve ningún curso");
        $this->assertNotEmpty(
            $visibles->filter(fn ($c) => str_contains($c['title'], 'Ley Federal del Trabajo')),
            'los cursos de ley tienen que llegarle a toda la plantilla'
        );
    }

    public function test_reaplicar_el_giro_no_duplica_los_cursos(): void
    {
        $this->aplicarGiro()->assertStatus(200);
        $primeraVuelta = $this->cursos()->count();

        $this->aplicarGiro()->assertStatus(200);

        // Las tareas y las vacantes se borran antes de reinyectar; los cursos no, así que se
        // acumulaban en cada pasada del asistente.
        $this->assertSame($primeraVuelta, $this->cursos()->count());
    }

    public function test_reaplicar_el_giro_conserva_el_progreso_del_colaborador(): void
    {
        $this->aplicarGiro()->assertStatus(200);

        $curso = $this->cursos()->first();
        $empleado = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $empleado->id)->update(['tenant_id' => $this->tenantId]);

        DB::table('user_course_progress')->insert([
            'user_id' => $empleado->id, 'course_id' => $curso->id, 'tenant_id' => $this->tenantId,
            'status' => 'completed', 'score' => 100, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->aplicarGiro()->assertStatus(200);

        // Borrar y reinsertar los cursos habría llevado el progreso por delante (FK en cascada).
        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $empleado->id,
            'course_id' => $curso->id,
            'status' => 'completed',
        ]);
    }

    public function test_un_giro_sin_catalogo_conserva_los_cursos_de_ley(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/admin/onboarding/configure-nicho', [
            'nicho' => 'custom',
            'custom_nicho_description' => 'Un giro que no está en la lista',
            'selected_puestos' => [
                ['name' => 'Encargado', 'area' => 'General', 'esAperturador' => true, 'jerarquiaLlaves' => 1],
            ],
        ])->assertStatus(200);

        $titulos = $this->cursos()->pluck('title')->implode(' | ');

        $this->assertStringContainsString('Ley Federal del Trabajo', $titulos);
        $this->assertStringContainsString('Ley Silla', $titulos);
    }
}
