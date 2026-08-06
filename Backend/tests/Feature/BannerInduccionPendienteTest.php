<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\JobRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Banner de inducción pendiente en la app del colaborador (decisión de producto 2026-08-06:
 * *"un banner en su app, no un email"*).
 *
 * *"Que abra el celular y vea: 'Tienes 2 días para tu inducción. Complétala aquí.'"*
 *
 * La cuenta la hace el SERVIDOR, con la misma constante que decide cuándo el caso se pinta rojo
 * en el tablero del encargado (`PlazoInduccion::DIAS`). Si el cliente la calculara por su cuenta,
 * el colaborador y su jefe acabarían mirando relojes distintos. Y no bloquea nada: el
 * colaborador ficha y trabaja igual — *"no quiero que el sistema castigue al nuevo"*.
 */
class BannerInduccionPendienteTest extends TestCase
{
    use RefreshDatabase;

    // Tenant propio: el 1 trae cursos sembrados por la migración `seed_lft_course` —uno de
    // ellos de tipo `induction`—, y aquí hace falta partir de una empresa sin cursos para
    // distinguir "no tiene inducción configurada" de "sí la tiene".
    private int $tenantId = 24;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Banner QA', 'subdomain' => 'bannerqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function colaborador(?string $ingreso, bool $induccionHecha = false, ?int $puestoId = null): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update([
            'tenant_id' => $this->tenantId,
            'job_role_id' => $puestoId,
            'has_completed_induction' => $induccionHecha,
        ]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'hire_date' => $ingreso,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function cursoDeInduccion(?int $paraPuesto = null): AcademyCourse
    {
        return AcademyCourse::create([
            'title' => 'Inducción a la Empresa',
            'description' => 'La de siempre.',
            'course_type' => 'induction',
            'target_job_role_id' => $paraPuesto,
            'quiz_data' => [],
            'is_active' => true,
            'tenant_id' => $this->tenantId,
        ]);
    }

    public function test_el_recien_ingresado_ve_sus_dias_restantes(): void
    {
        $curso = $this->cursoDeInduccion();
        $user = $this->colaborador(now()->subDay()->toDateString());

        $this->actingAs($user)->getJson('/api/v1/academy/mi-induccion')
            ->assertStatus(200)
            ->assertJson([
                'pendiente' => true,
                'course_id' => $curso->id,
                'plazo' => 3,
                'dias_transcurridos' => 1,
                'dias_restantes' => 2, // "Tienes 2 días para tu inducción."
                'vencido' => false,
            ]);
    }

    public function test_al_vencerse_el_plazo_no_quedan_dias_pero_sigue_sin_bloquear(): void
    {
        $this->cursoDeInduccion();
        $user = $this->colaborador(now()->subDays(5)->toDateString());

        $respuesta = $this->actingAs($user)->getJson('/api/v1/academy/mi-induccion');

        $respuesta->assertJson(['pendiente' => true, 'dias_restantes' => 0, 'vencido' => true]);

        // Y lo importante: sigue pudiendo trabajar. El aviso presiona al encargado, no castiga
        // al colaborador.
        $this->actingAs($user)->getJson('/api/v1/me/punctuality-status')
            ->assertStatus(200)
            ->assertJson(['blocked' => false]);
    }

    public function test_los_administradores_no_ven_banner(): void
    {
        // Las dos puntas de la regla dicen lo mismo: si el admin no sale en el tablero del
        // encargado (porque nadie va a darle seguimiento a la dueña), tampoco se le recuerda a
        // ella todos los días. Un supervisor SÍ lo ve: a él sí lo siguen.
        $this->cursoDeInduccion();

        $jefa = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $jefa->id)->update([
            'tenant_id' => $this->tenantId, 'has_completed_induction' => false,
        ]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $jefa->id, 'name' => $jefa->name,
            'email' => $jefa->email, 'hire_date' => now()->subDays(9)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($jefa->fresh())->getJson('/api/v1/academy/mi-induccion')
            ->assertJson(['pendiente' => false]);

        $encargado = $this->colaborador(now()->subDays(9)->toDateString());
        DB::table('users')->where('id', $encargado->id)->update(['role' => 'supervisor']);

        $this->actingAs($encargado->fresh())->getJson('/api/v1/academy/mi-induccion')
            ->assertJson(['pendiente' => true]);
    }

    public function test_quien_ya_la_completo_no_ve_banner(): void
    {
        $this->cursoDeInduccion();
        $user = $this->colaborador(now()->subDays(9)->toDateString(), induccionHecha: true);

        $this->actingAs($user)->getJson('/api/v1/academy/mi-induccion')
            ->assertJson(['pendiente' => false]);
    }

    public function test_sin_curso_de_induccion_en_la_empresa_no_se_le_reclama_nada(): void
    {
        $user = $this->colaborador(now()->subDays(9)->toDateString());

        $this->actingAs($user)->getJson('/api/v1/academy/mi-induccion')
            ->assertJson(['pendiente' => false]);
    }

    public function test_el_curso_que_se_le_ofrece_es_el_de_su_puesto(): void
    {
        $mio = JobRole::create(['name' => 'Cajero', 'area' => 'Cajas', 'tenant_id' => $this->tenantId,
            'esAperturador' => false, 'tiempoTolerancia' => 10]);
        $ajeno = JobRole::create(['name' => 'Almacén', 'area' => 'Logística', 'tenant_id' => $this->tenantId,
            'esAperturador' => false, 'tiempoTolerancia' => 10]);

        $this->cursoDeInduccion($ajeno->id);
        $suyo = $this->cursoDeInduccion($mio->id);

        $user = $this->colaborador(now()->toDateString(), puestoId: $mio->id);

        $this->actingAs($user)->getJson('/api/v1/academy/mi-induccion')
            ->assertJson(['pendiente' => true, 'course_id' => $suyo->id]);
    }

    public function test_sin_fecha_de_ingreso_avisa_pero_no_inventa_la_cuenta(): void
    {
        // Expedientes viejos, de cuando `hire_date` era opcional.
        $this->cursoDeInduccion();
        $user = $this->colaborador(null);

        $this->actingAs($user)->getJson('/api/v1/academy/mi-induccion')
            ->assertJson(['pendiente' => true, 'dias_restantes' => null, 'vencido' => false]);
    }

    public function test_el_plazo_es_el_mismo_que_pinta_el_rojo_del_encargado(): void
    {
        // Son las dos puntas de la misma regla: si se separaran, el colaborador y su jefe
        // estarían mirando relojes distintos.
        $this->cursoDeInduccion();
        $user = $this->colaborador(now()->subDays(3)->toDateString());
        $jefa = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $jefa->id)->update(['tenant_id' => $this->tenantId]);

        $suyo = $this->actingAs($user)->getJson('/api/v1/academy/mi-induccion');
        $delJefe = $this->actingAs($jefa->fresh())->getJson('/api/v1/supervisor/pendientes');

        $this->assertTrue($suyo->json('vencido'), 'a él se le acabó el plazo');
        $this->assertSame($suyo->json('plazo'), $delJefe->json('dias_de_plazo'));
        $this->assertTrue(
            collect($delJefe->json('induccion_pendiente'))->firstWhere('user_id', $user->id)['urge'],
            'y en el tablero de su jefa el caso se pinta rojo el mismo día'
        );
    }
}
