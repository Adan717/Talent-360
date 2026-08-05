<?php

namespace Tests\Feature;

use App\Models\JobRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `construirOrganigrama` nació el 2026-08-01 (H27). Las empresas que aplicaron su giro antes
 * tienen los puestos huérfanos: los tres campos de jerarquía en nulo. No seguían otra
 * convención — no había ninguna. Medido en la V2: los 7 puestos que el asistente creó en el
 * tenant 2 (configurado el 29 de julio) están vacíos, y los 4 sembrados de origen sí tienen
 * jerarquía.
 *
 * Sin organigrama, el tablero de pendientes no encuentra a quién le toca cada caso y TODO cae
 * al admin — lo contrario de lo que decidió el jefe.
 */
class RepararOrganigramaTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function puesto(string $nombre, int $nivel, array $extra = []): JobRole
    {
        return JobRole::create(array_merge([
            'name' => $nombre,
            'area' => 'Operaciones',
            'tenant_id' => $this->tenantId,
            'jerarquiaLlaves' => $nivel,
            'esAperturador' => false,
            'tiempoTolerancia' => 10,
            'is_active' => true,
        ], $extra));
    }

    private function reparar(bool $dryRun = false): void
    {
        $this->artisan('reloj:reparar-organigrama' . ($dryRun ? ' --dry-run' : ''))
            ->assertSuccessful();
    }

    public function test_conecta_los_puestos_huerfanos_con_la_convencion_del_asistente(): void
    {
        $jefe = $this->puesto('Gerente', 1);
        $supervisor = $this->puesto('Supervisor de Ventas', 2);
        $piso = $this->puesto('Asesor', 3);

        $this->reparar();

        // Cada quien reporta al primer puesto del nivel inmediatamente superior que exista.
        $this->assertSame($jefe->id, $supervisor->fresh()->reports_to_role_id);
        $this->assertSame($supervisor->id, $piso->fresh()->reports_to_role_id);
        $this->assertNull($jefe->fresh()->reports_to_role_id, 'la cabeza no reporta a nadie');
    }

    public function test_deja_dibujada_tambien_la_linea_punteada(): void
    {
        $jefe = $this->puesto('Gerente', 1);
        $piso = $this->puesto('Asesor', 3);

        $this->reparar();

        // El organigrama dibuja la jerarquía operativa leyendo el ARREGLO; sin él la relación
        // existe en la base pero no se ve en pantalla ni la hereda el tablero.
        $this->assertSame([$jefe->id], $piso->fresh()->reports_to_role_ids);
    }

    public function test_al_que_solo_le_falta_el_arreglo_no_se_le_recalcula_el_jefe(): void
    {
        $jefe = $this->puesto('Gerente', 1);
        $otro = $this->puesto('Supervisor', 2);
        // Un puesto al que ALGUIEN puso su jefe a mano, distinto del que daría la convención.
        $piso = $this->puesto('Asesor', 3, ['reports_to_role_id' => $otro->id]);

        $this->reparar();

        $this->assertSame($otro->id, $piso->fresh()->reports_to_role_id,
            'el jefe puesto a mano no se toca: sólo le faltaba la línea punteada');
        $this->assertSame([$otro->id], $piso->fresh()->reports_to_role_ids);
    }

    public function test_no_toca_a_quien_ya_esta_conectado(): void
    {
        $jefe = $this->puesto('Gerente', 1);
        $piso = $this->puesto('Asesor', 3, [
            'reports_to_role_id' => $jefe->id,
            'reports_to_role_ids' => [$jefe->id],
        ]);

        $antes = $piso->fresh()->updated_at;

        $this->reparar();

        $this->assertEquals($antes, $piso->fresh()->updated_at, 'ya estaba completo: no debe tocarse');
    }

    public function test_el_dry_run_no_escribe_nada(): void
    {
        $this->puesto('Gerente', 1);
        $huerfano = $this->puesto('Asesor', 3);

        $this->reparar(dryRun: true);

        $this->assertNull($huerfano->fresh()->reports_to_role_id,
            'el --dry-run es para enseñarle el árbol al jefe antes de aplicarlo');
    }

    public function test_es_idempotente(): void
    {
        $this->puesto('Gerente', 1);
        $piso = $this->puesto('Asesor', 3);

        $this->reparar();
        $primera = $piso->fresh()->reports_to_role_id;

        $this->reparar();

        $this->assertSame($primera, $piso->fresh()->reports_to_role_id);
    }

    public function test_el_tablero_encuentra_al_encargado_despues_de_reparar(): void
    {
        // La razón de ser del comando: sin organigrama, el encargado no ve a su equipo.
        $gerencia = $this->puesto('Gerente', 1);
        $piso = $this->puesto('Asesor', 3);

        $encargado = \App\Models\User::factory()->create(['role' => 'supervisor']);
        DB::table('users')->where('id', $encargado->id)
            ->update(['tenant_id' => $this->tenantId, 'job_role_id' => $gerencia->id]);

        $colaborador = \App\Models\User::factory()->create(['role' => 'empleado', 'name' => 'De piso']);
        DB::table('users')->where('id', $colaborador->id)
            ->update(['tenant_id' => $this->tenantId, 'job_role_id' => $piso->id, 'has_completed_induction' => false]);

        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $colaborador->id, 'name' => 'De piso',
            'email' => $colaborador->email, 'hire_date' => now()->subDays(4)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \App\Models\AcademyCourse::create([
            'title' => 'Inducción', 'course_type' => 'induction', 'quiz_data' => [],
            'is_active' => true, 'tenant_id' => $this->tenantId,
        ]);

        // Antes de reparar: el encargado no ve a nadie de su equipo.
        $antes = $this->actingAs($encargado->fresh())->getJson('/api/v1/supervisor/pendientes')
            ->json('induccion_pendiente');
        $this->assertEmpty(collect($antes)->firstWhere('user_id', $colaborador->id));

        $this->reparar();

        $despues = $this->actingAs($encargado->fresh())->getJson('/api/v1/supervisor/pendientes')
            ->json('induccion_pendiente');
        $this->assertNotNull(collect($despues)->firstWhere('user_id', $colaborador->id),
            'reparado el organigrama, el caso le llega al encargado y no sólo al admin');
    }
}
