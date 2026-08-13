<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\Candidate;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bloque 5 / D2 (2026-08-13): la inducción es de Academia y ocurre YA CONTRATADO.
 *
 * El criterio del plan, literal: "contratar a alguien lo deja con sus cursos asignados, y la
 * prueba lo demuestra". La inscripción de Academia es implícita (curso del tenant, visible por
 * puesto, avance perezoso), así que lo que se demuestra de punta a punta es: contratar crea la
 * cuenta y el expediente CON hire_date, el contratado VE sus cursos de inducción, el aviso de
 * "inducción pendiente" le corre desde hoy, y la contratación DICE cuántos cursos le esperan
 * (o avisa que no hay ninguno — antes el alert prometía inscripción sin respaldo y se quitó
 * por mentira; ahora vuelve porque es verdad).
 */
class AtsInduccionAcademiaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $puesto;
    private Candidate $candidato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'ATS QA', 'subdomain' => 'atsqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@atsqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false,
        ]);

        $vacante = Vacancy::create([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $this->puesto->id,
            'title' => 'Cajero de fin de semana', 'description' => 'x', 'requirements' => '[]',
            'is_active' => true, 'is_hidden' => false,
        ]);

        $this->candidato = Candidate::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Persona Nueva',
            'email' => 'nueva@atsqa.test', 'status' => 'training',
            'applied_vacancy_id' => $vacante->id,
        ]);
    }

    public function test_contratar_deja_a_la_persona_con_sus_cursos_de_induccion(): void
    {
        // El que le toca (general), el de OTRO puesto (no le toca) y uno que no es inducción.
        $general = AcademyCourse::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Bienvenida a la empresa',
            'course_type' => 'induction', 'target_job_role_id' => null,
        ]);
        $otroPuesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Chef', 'area' => 'Cocina', 'esAperturador' => false,
        ]);
        AcademyCourse::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Inducción de cocina',
            'course_type' => 'induction', 'target_job_role_id' => $otroPuesto->id,
        ]);
        AcademyCourse::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Ventas avanzadas',
            'course_type' => 'training', 'target_job_role_id' => null,
        ]);

        $respuesta = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/candidates/{$this->candidato->id}", ['status' => 'hired'])
            ->assertOk();

        // La contratación dice la verdad: 1 curso de inducción le aplica (el general).
        $this->assertSame(1, $respuesta->json('induction_courses'));

        // La persona existe, con expediente y hire_date de HOY (de ahí cuenta su plazo).
        $contratada = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('email', 'nueva@atsqa.test')->firstOrFail();
        $this->assertSame(now()->toDateString(), \Illuminate\Support\Facades\DB::table('employees')
            ->where('user_id', $contratada->id)->value('hire_date'));

        // Y VE su curso de inducción en la Academia (la inscripción implícita, demostrada).
        $cursos = $this->actingAs($contratada)->getJson('/api/v1/academy/courses')
            ->assertOk()->json('courses');
        $this->assertContains($general->id, array_column($cursos, 'id'),
            'el contratado tiene que ver su curso de inducción');

        // Y el aviso de inducción pendiente le corre desde hoy.
        $this->actingAs($contratada)->getJson('/api/v1/academy/mi-induccion')
            ->assertOk()->assertJsonPath('pendiente', true);
    }

    public function test_sin_cursos_de_induccion_la_contratacion_lo_avisa(): void
    {
        $respuesta = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/candidates/{$this->candidato->id}", ['status' => 'hired'])
            ->assertOk();

        $this->assertSame(0, $respuesta->json('induction_courses'));
        $this->assertContains(
            'La Academia no tiene ningún curso de inducción que le aplique: no le llegará nada que completar.',
            $respuesta->json('avisos')
        );
    }
}
