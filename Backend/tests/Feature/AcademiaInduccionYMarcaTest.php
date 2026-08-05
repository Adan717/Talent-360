<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Academia AC5 y AC6 (auditoría del módulo, 2026-08-04).
 *
 * AC5 — el asistente promete "capacita e induce 100% en automático". La pregunta era si al dar
 * de alta a un colaborador le aparece su inducción sin que nadie se la asigne. Antes NO: todos
 * los cursos colgaban del puesto de mando (AC2), así que el de piso no veía ninguno. Aquí se fija
 * que sí, que es lo que hace verdadera la promesa — no hay "asignación" que hacer, la inducción
 * del giro le llega por el solo hecho de existir en la empresa.
 *
 * AC6 — las plantillas importables llevaban el nombre y la historia de una empresa cliente
 * ("Inducción DecorArte 360", "¿Cuál es el valor principal de DecorArte?"), videos que eran
 * marcadores de prueba —uno el rickroll de Rick Astley— y un bono de $500 que nada paga.
 */
class AcademiaInduccionYMarcaTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 9;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Panaderia QA', 'subdomain' => 'panqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function usuario(string $role = 'admin', ?int $jobRoleId = null): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)
            ->update(['tenant_id' => $this->tenantId, 'job_role_id' => $jobRoleId]);

        return $user->fresh();
    }

    // ---------------- AC5 ----------------

    public function test_al_colaborador_recien_dado_de_alta_le_aparece_su_induccion(): void
    {
        $this->actingAs($this->usuario())->postJson('/api/v1/admin/onboarding/configure-nicho', [
            'nicho' => 'restaurante',
        ])->assertStatus(200);

        // El puesto más bajo del giro: el ayudante recién contratado.
        $piso = DB::table('job_roles')->where('tenant_id', $this->tenantId)
            ->orderByDesc('jerarquiaLlaves')->first();

        $nuevo = $this->usuario('empleado', $piso->id);

        $cursos = collect($this->actingAs($nuevo)->getJson('/api/v1/academy/courses')->json('courses'))
            ->filter(fn ($c) => empty($c['target_job_role_id']) || $c['target_job_role_id'] === $piso->id);

        $this->assertNotEmpty(
            $cursos->where('course_type', 'induction'),
            "el puesto '{$piso->name}' no ve ninguna inducción, y nadie se la va a asignar a mano"
        );
    }

    public function test_la_induccion_no_bloquea_el_fichaje_de_nadie(): void
    {
        // AC5: la Academia decía "Tu BLOQUEO OPERATIVO ha sido levantado. Ya puedes registrar tu
        // entrada", pero `has_completed_induction` no gobierna ninguna puerta del backend —
        // sólo pinta un recordatorio. Esta prueba deja constancia de cuál es el comportamiento
        // real, para que el día que producto decida bloquear de verdad, se vea que cambió.
        $empleado = $this->usuario('empleado');

        $this->assertFalse((bool) $empleado->has_completed_induction);

        $estado = $this->actingAs($empleado)->getJson('/api/v1/me/punctuality-status');

        $estado->assertStatus(200)->assertJson(['blocked' => false]);
    }

    // ---------------- AC6 ----------------

    public function test_las_plantillas_no_llevan_la_marca_de_otra_empresa(): void
    {
        $plantillas = $this->actingAs($this->usuario())
            ->getJson('/api/v1/academy/course-templates')->json();

        $texto = mb_strtolower(json_encode($plantillas, JSON_UNESCAPED_UNICODE));

        $this->assertStringNotContainsString('decorarte', $texto,
            'las plantillas se le ofrecen a TODAS las empresas: no pueden llevar el nombre de una');
    }

    public function test_ninguna_plantilla_trae_un_video_de_prueba(): void
    {
        $plantillas = $this->actingAs($this->usuario())
            ->getJson('/api/v1/academy/course-templates')->json();

        foreach ($plantillas as $p) {
            // dQw4w9WgXcQ es el rickroll; los otros dos eran marcadores igual de reales.
            foreach (['dQw4w9WgXcQ', 'tgbNymZ7vqY', '1k8craCGv14'] as $marcador) {
                $this->assertStringNotContainsString($marcador, (string) $p['video_url'],
                    "la plantilla '{$p['title']}' importa un video de prueba a la empresa");
            }
        }
    }

    public function test_ninguna_plantilla_promete_un_bono_que_nadie_paga(): void
    {
        // La Academia le anuncia al colaborador "Bono de incentivo de $X MXN al completarlo" en
        // cuanto `incentive_bonus_cents` es mayor que cero, y no existe ningún circuito que lo
        // pague. Mientras no exista, ninguna plantilla debe prometerlo.
        $plantillas = $this->actingAs($this->usuario())
            ->getJson('/api/v1/academy/course-templates')->json();

        foreach ($plantillas as $p) {
            $this->assertSame(0, (int) $p['incentive_bonus_cents'],
                "la plantilla '{$p['title']}' promete dinero que el sistema no paga");
        }
    }

    public function test_la_plantilla_importada_llega_completa_a_la_empresa(): void
    {
        $admin = $this->usuario();

        $this->actingAs($admin)->postJson('/api/v1/academy/course-templates/1/import')
            ->assertStatus(201);

        $curso = DB::table('academy_courses')->where('tenant_id', $this->tenantId)->first();

        $this->assertSame('Inducción a la Empresa', $curso->title);
        $this->assertSame('induction', $curso->course_type);
        $this->assertNotEmpty(json_decode($curso->quiz_data, true), 'sin examen no se puede completar');
    }
}
