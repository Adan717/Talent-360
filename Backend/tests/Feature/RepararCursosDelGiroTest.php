<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Academia AC1/AC2, la parte de los datos YA generados: los tenants configurados antes del
 * arreglo se quedaron con un solo curso genérico colgado del puesto de mando, y eso no se
 * corrige solo. Regla de la casa: al corregir algo que genera datos, hay que responder qué pasa
 * con lo ya generado.
 */
class RepararCursosDelGiroTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 9;
    private int $puestoMando;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Tienda Vieja', 'subdomain' => 'vieja',
            'plan' => 'pro', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Una empresa como quedaban antes: su giro configurado, sus puestos, y UN curso
        // genérico colgado del puesto de mando.
        $this->puestoMando = DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenantId, 'name' => 'Gerente de Tienda', 'area' => 'Gerencia',
            'jerarquiaLlaves' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('job_roles')->insert([
            'tenant_id' => $this->tenantId, 'name' => 'Asesor de Ventas y Piso', 'area' => 'Piso',
            'jerarquiaLlaves' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('system_settings')->insert([
            'tenant_id' => $this->tenantId, 'key' => 'nicho_configurado',
            'value' => json_encode(['nicho' => 'retail', 'subNicho' => null]),
        ]);
    }

    private function sembrarCursoViejo(string $titulo): int
    {
        return DB::table('academy_courses')->insertGetId([
            'tenant_id' => $this->tenantId,
            'title' => $titulo,
            'description' => 'El de antes.',
            'course_type' => 'induction',
            'target_job_role_id' => $this->puestoMando,
            'video_url' => '',
            'quiz_data' => json_encode([]),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cursos(): \Illuminate\Support\Collection
    {
        return DB::table('academy_courses')->where('tenant_id', $this->tenantId)->get();
    }

    public function test_en_seco_no_cambia_nada(): void
    {
        $id = $this->sembrarCursoViejo(\App\Support\CatalogoOnboarding::para('retail')['cursos'][0]['title']);

        $this->artisan('academia:reparar-cursos-del-giro', ['--dry-run' => true, '--tenant' => $this->tenantId])
            ->assertSuccessful();

        $this->assertSame(1, $this->cursos()->count(), 'en seco no debe crear nada');
        $this->assertSame($this->puestoMando,
            (int) DB::table('academy_courses')->where('id', $id)->value('target_job_role_id'));
    }

    public function test_repone_los_cursos_que_faltaban_y_los_saca_del_puesto_de_mando(): void
    {
        $catalogo = \App\Support\CatalogoOnboarding::para('retail')['cursos'];
        $id = $this->sembrarCursoViejo($catalogo[0]['title']);

        $this->artisan('academia:reparar-cursos-del-giro', ['--tenant' => $this->tenantId])
            ->assertSuccessful();

        $this->assertSame(count($catalogo), $this->cursos()->count(), 'deben estar todos los del giro');

        // El que ya existía deja de ser exclusivo del gerente.
        $this->assertNull(DB::table('academy_courses')->where('id', $id)->value('target_job_role_id'));
        $this->assertSame(0, $this->cursos()->where('target_job_role_id', $this->puestoMando)->count());
    }

    public function test_no_toca_los_cursos_que_el_administrador_dio_de_alta(): void
    {
        $propio = DB::table('academy_courses')->insertGetId([
            'tenant_id' => $this->tenantId, 'title' => 'Curso propio de la empresa',
            'description' => 'Lo hizo el administrador.', 'course_type' => 'training',
            'target_job_role_id' => $this->puestoMando, 'video_url' => '', 'quiz_data' => json_encode([]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('academia:reparar-cursos-del-giro', ['--tenant' => $this->tenantId])
            ->assertSuccessful();

        $fila = DB::table('academy_courses')->where('id', $propio)->first();

        $this->assertNotNull($fila, 'no se borra ningún curso ajeno al catálogo');
        $this->assertSame('Lo hizo el administrador.', $fila->description);
        $this->assertSame($this->puestoMando, (int) $fila->target_job_role_id);
    }

    public function test_conserva_el_progreso_de_los_colaboradores(): void
    {
        $catalogo = \App\Support\CatalogoOnboarding::para('retail')['cursos'];
        $id = $this->sembrarCursoViejo($catalogo[0]['title']);

        $empleado = \App\Models\User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $empleado->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('user_course_progress')->insert([
            'user_id' => $empleado->id, 'course_id' => $id, 'tenant_id' => $this->tenantId,
            'status' => 'completed', 'score' => 100, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('academia:reparar-cursos-del-giro', ['--tenant' => $this->tenantId])
            ->assertSuccessful();

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $empleado->id, 'course_id' => $id, 'status' => 'completed',
        ]);
    }

    public function test_repetir_la_reparacion_no_duplica(): void
    {
        $this->sembrarCursoViejo(\App\Support\CatalogoOnboarding::para('retail')['cursos'][0]['title']);

        $this->artisan('academia:reparar-cursos-del-giro', ['--tenant' => $this->tenantId])->assertSuccessful();
        $primera = $this->cursos()->count();

        $this->artisan('academia:reparar-cursos-del-giro', ['--tenant' => $this->tenantId])->assertSuccessful();

        $this->assertSame($primera, $this->cursos()->count());
    }
}
