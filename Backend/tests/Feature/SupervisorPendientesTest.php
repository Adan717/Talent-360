<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\JobRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tablero de pendientes del encargado (decisión de producto 2026-08-05).
 *
 * El criterio fue **nada bloquea, todo avisa**: reprobar un examen no cierra el curso y traer la
 * inducción pendiente no impide fichar, pero el encargado tiene que enterarse para acercarse a la
 * persona. El aviso es una CONSULTA que el tablero pide, no mensajería: `internal_messages` es el
 * Chat Operativo del Monitor 360, un canal entre personas, y meterle avisos automáticos sería
 * ensuciar una conversación.
 *
 * A quién le toca cada caso sale del ORGANIGRAMA (`job_roles.reports_to_role_id`), porque la
 * figura de "encargado de área" no existe en el sistema: no hay `store_id` en `employees` ni un
 * rol por sucursal.
 */
class SupervisorPendientesTest extends TestCase
{
    use RefreshDatabase;

    private const QUIZ = [[
        'question' => '¿Cuál es la tolerancia?',
        'options' => ['10 minutos', 'No hay'],
        'correctAnswer' => 0,
    ]];

    private int $tenantId = 1;

    private function puesto(string $nombre, ?int $reportaA = null): JobRole
    {
        return JobRole::create([
            'name' => $nombre,
            'area' => 'Operaciones',
            'tenant_id' => $this->tenantId,
            'esAperturador' => false,
            'tiempoTolerancia' => 10,
            'reports_to_role_id' => $reportaA,
        ]);
    }

    private function persona(string $nombre, string $rol, ?int $puestoId = null, ?string $ingreso = null): User
    {
        $user = User::factory()->create(['name' => $nombre, 'role' => $rol]);
        DB::table('users')->where('id', $user->id)->update([
            'tenant_id' => $this->tenantId,
            'job_role_id' => $puestoId,
            'has_completed_induction' => false,
        ]);

        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'name' => $nombre,
            'email' => $user->email,
            'hire_date' => $ingreso,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function curso(string $titulo = 'NOM-035', string $tipo = 'training'): AcademyCourse
    {
        return AcademyCourse::create([
            'title' => $titulo,
            'description' => 'Curso del giro.',
            'course_type' => $tipo,
            'quiz_data' => self::QUIZ,
            'is_active' => true,
            'tenant_id' => $this->tenantId,
        ]);
    }

    private function reprobar(User $user, AcademyCourse $curso, int $veces = 1): void
    {
        for ($i = 0; $i < $veces; $i++) {
            $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
                'answers' => [1],
            ])->assertJson(['passed' => false]);
        }
    }

    // ---------------- cursos reprobados ----------------

    public function test_una_sola_reprobada_no_llega_al_tablero(): void
    {
        $jefe = $this->persona('Jefa', 'admin');
        $curso = $this->curso();
        $this->reprobar($this->persona('Ana', 'empleado'), $curso, 1);

        $pendientes = $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes');

        $pendientes->assertStatus(200);
        $this->assertSame([], $pendientes->json('cursos_reprobados'),
            'el criterio del jefe fue avisar a la SEGUNDA reprobada, no a la primera');
    }

    public function test_a_la_segunda_reprobada_el_caso_aparece(): void
    {
        $jefe = $this->persona('Jefa', 'admin');
        $curso = $this->curso();
        $ana = $this->persona('Ana', 'empleado');
        $this->reprobar($ana, $curso, 2);

        $caso = $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('cursos_reprobados.0');

        $this->assertSame($ana->id, $caso['user_id']);
        $this->assertSame('Ana', $caso['nombre']);
        $this->assertSame('NOM-035', $caso['curso']);
        $this->assertSame(2, $caso['intentos']);
        $this->assertFalse($caso['atendido']);
    }

    public function test_marcar_atendido_saca_el_caso_del_pendiente(): void
    {
        $jefe = $this->persona('Jefa', 'admin');
        $curso = $this->curso();
        $ana = $this->persona('Ana', 'empleado');
        $this->reprobar($ana, $curso, 2);

        $caso = $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('cursos_reprobados.0');

        $this->actingAs($jefe)
            ->postJson("/api/v1/supervisor/pendientes/{$caso['progress_id']}/atendido")
            ->assertStatus(200);

        $this->assertTrue(
            $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('cursos_reprobados.0.atendido'),
            'un tablero que acumula casos ya resueltos es ruido, no una herramienta'
        );
    }

    public function test_si_vuelve_a_reprobar_el_caso_reaparece(): void
    {
        $jefe = $this->persona('Jefa', 'admin');
        $curso = $this->curso();
        $ana = $this->persona('Ana', 'empleado');
        $this->reprobar($ana, $curso, 2);

        $caso = $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('cursos_reprobados.0');
        $this->actingAs($jefe)->postJson("/api/v1/supervisor/pendientes/{$caso['progress_id']}/atendido");

        // El encargado ya habló con ella... y vuelve a reprobar.
        $this->reprobar($ana, $curso, 1);

        $reabierto = $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('cursos_reprobados.0');

        $this->assertFalse($reabierto['atendido'], 'tiene que volver a enterarse');
        $this->assertSame(3, $reabierto['intentos']);
    }

    public function test_aprobar_cierra_el_caso(): void
    {
        $jefe = $this->persona('Jefa', 'admin');
        $curso = $this->curso();
        $ana = $this->persona('Ana', 'empleado');
        $this->reprobar($ana, $curso, 2);

        // Estaba en el tablero...
        $this->assertCount(1, $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')
            ->json('cursos_reprobados'));

        $this->actingAs($ana)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [0],
        ])->assertJson(['passed' => true]);

        // ...y al aprobar deja de estarlo. Sus dos intentos fallidos siguen en el historial,
        // pero el caso ya no es pendiente de nadie y no tiene por qué seguir dando lata.
        $this->assertSame([], $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')
            ->json('cursos_reprobados'));

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $ana->id,
            'course_id' => $curso->id,
            'failed_attempts' => 2,
            'status' => 'completed',
        ]);
    }

    // ---------------- inducción pendiente ----------------

    public function test_quien_no_ha_hecho_su_induccion_aparece_con_sus_dias(): void
    {
        $jefe = $this->persona('Jefa', 'admin');
        $this->curso('Inducción a la Empresa', 'induction');
        $nuevo = $this->persona('Juan', 'empleado', null, now()->subDays(3)->toDateString());

        $fila = collect($this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('induccion_pendiente'))
            ->firstWhere('user_id', $nuevo->id);

        $this->assertNotNull($fila, 'el encargado tiene que ver quién trae la inducción pendiente');
        $this->assertSame(3, $fila['dias_sin_induccion']);
        $this->assertTrue($fila['urge'], 'a los 2 días o más se pinta en rojo');
    }

    public function test_el_recien_ingresado_aparece_pero_no_urge(): void
    {
        $jefe = $this->persona('Jefa', 'admin');
        $this->curso('Inducción a la Empresa', 'induction');
        $hoy = $this->persona('Recién', 'empleado', null, now()->toDateString());

        $fila = collect($this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('induccion_pendiente'))
            ->firstWhere('user_id', $hoy->id);

        $this->assertSame(0, $fila['dias_sin_induccion']);
        $this->assertFalse($fila['urge'], 'no se persigue a alguien en su primer día');
    }

    public function test_los_administradores_no_salen_en_el_tablero(): void
    {
        // Decisión de producto (2026-08-06): "excluye a los admin del tablero, es ruido".
        // Medido en vivo: el tablero del primer día listaba a toda la plantilla incluida la
        // dueña, que no es alguien a quien su encargado tenga que perseguir. Los SUPERVISORES
        // sí siguen apareciendo: son personal como cualquier otro.
        $jefa = $this->persona('Jefa', 'admin', null, now()->subDays(9)->toDateString());
        $encargado = $this->persona('Encargada', 'supervisor', null, now()->subDays(9)->toDateString());
        $piso = $this->persona('De piso', 'empleado', null, now()->subDays(9)->toDateString());
        $this->curso('Inducción a la Empresa', 'induction');

        $nombres = collect($this->actingAs($jefa)->getJson('/api/v1/supervisor/pendientes')
            ->json('induccion_pendiente'))->pluck('nombre');

        $this->assertNotContains('Jefa', $nombres, 'la dueña no se persigue a sí misma');
        $this->assertContains('Encargada', $nombres, 'un supervisor sí es personal a seguir');
        $this->assertContains('De piso', $nombres);
    }

    public function test_el_rojo_entra_al_cumplirse_el_plazo_de_tres_dias_y_no_antes(): void
    {
        // El jefe fijó el plazo en 3 días: "a los 3 días sin completar, el caso se pone rojo en
        // mi tablero". Dentro del plazo el caso se ve, pero no urge — es el colaborador quien
        // tiene sus días, no el encargado quien tiene que perseguirlo desde el primer momento.
        $jefe = $this->persona('Jefa', 'admin');
        $this->curso('Inducción a la Empresa', 'induction');

        $enPlazo = $this->persona('Aún en plazo', 'empleado', null, now()->subDays(2)->toDateString());
        $vencido = $this->persona('Se le venció', 'empleado', null, now()->subDays(3)->toDateString());

        $lista = collect($this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('induccion_pendiente'));

        $this->assertFalse($lista->firstWhere('user_id', $enPlazo->id)['urge'],
            'al segundo día todavía está dentro de su plazo');
        $this->assertTrue($lista->firstWhere('user_id', $vencido->id)['urge'],
            'al tercero se le acabó: ahí entra el encargado');
    }

    public function test_quien_ya_la_completo_desaparece(): void
    {
        $jefe = $this->persona('Jefa', 'admin');
        $curso = $this->curso('Inducción a la Empresa', 'induction');
        $juan = $this->persona('Juan', 'empleado', null, now()->subDays(5)->toDateString());

        $this->actingAs($juan)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [0],
        ])->assertJson(['passed' => true]);

        $lista = collect($this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('induccion_pendiente'));

        $this->assertNull($lista->firstWhere('user_id', $juan->id));
    }

    // ---------------- a quién le toca ----------------

    public function test_el_encargado_solo_ve_a_su_equipo(): void
    {
        $gerencia = $this->puesto('Gerente');
        $piso = $this->puesto('Asesor de Ventas', $gerencia->id);
        $otraArea = $this->puesto('Almacén');

        $encargado = $this->persona('Encargada', 'supervisor', $gerencia->id);
        $mio = $this->persona('De mi equipo', 'empleado', $piso->id);
        $ajeno = $this->persona('De otra área', 'empleado', $otraArea->id);

        $curso = $this->curso();
        $this->reprobar($mio, $curso, 2);
        $this->reprobar($ajeno, $curso, 2);

        $nombres = collect($this->actingAs($encargado)->getJson('/api/v1/supervisor/pendientes')
            ->json('cursos_reprobados'))->pluck('nombre');

        $this->assertContains('De mi equipo', $nombres);
        $this->assertNotContains('De otra área', $nombres,
            'un encargado no tiene por qué ver los pendientes de otra área');
    }

    public function test_el_admin_ve_a_toda_la_empresa(): void
    {
        $gerencia = $this->puesto('Gerente');
        $piso = $this->puesto('Asesor de Ventas', $gerencia->id);
        $otraArea = $this->puesto('Almacén');

        $jefe = $this->persona('Jefa', 'admin');
        $curso = $this->curso();
        $this->reprobar($this->persona('De un área', 'empleado', $piso->id), $curso, 2);
        $this->reprobar($this->persona('De otra área', 'empleado', $otraArea->id), $curso, 2);

        $nombres = collect($this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')
            ->json('cursos_reprobados'))->pluck('nombre');

        // Los que no le reportan a nadie caen con el admin: ése es el fallback del organigrama.
        $this->assertContains('De un área', $nombres);
        $this->assertContains('De otra área', $nombres);
    }

    public function test_si_el_puesto_tiene_DOS_jefes_el_caso_les_llega_a_los_dos(): void
    {
        // El organigrama de Directorio > Puestos deja dibujar varias líneas punteadas desde un
        // mismo puesto: es "la jerarquía operativa real". El backend guarda esa lista en
        // `reports_to_role_ids` y deja en `reports_to_role_id` sólo el PRIMERO.
        //
        // Mirando únicamente el singular, el segundo jefe no se enteraba nunca de nada.
        $gerencia = $this->puesto('Gerente');
        $supervisionCajas = $this->puesto('Supervisor de Cajas');
        $cajero = $this->puesto('Cajero');

        DB::table('job_roles')->where('id', $cajero->id)->update([
            'reports_to_role_id' => $gerencia->id,
            'reports_to_role_ids' => json_encode([$gerencia->id, $supervisionCajas->id]),
        ]);

        $ana = $this->persona('Ana', 'empleado', $cajero->id);
        $this->reprobar($ana, $this->curso(), 2);

        $primerJefe = $this->persona('Gerenta', 'supervisor', $gerencia->id);
        $segundoJefe = $this->persona('Jefa de Cajas', 'supervisor', $supervisionCajas->id);

        foreach ([$primerJefe, $segundoJefe] as $jefe) {
            $nombres = collect($this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')
                ->json('cursos_reprobados'))->pluck('nombre');

            $this->assertContains('Ana', $nombres,
                "'{$jefe->name}' está en el organigrama de Ana y no vio su caso");
        }
    }

    public function test_un_encargado_no_puede_atender_un_caso_ajeno(): void
    {
        $gerencia = $this->puesto('Gerente');
        $otraArea = $this->puesto('Almacén');

        $encargado = $this->persona('Encargada', 'supervisor', $gerencia->id);
        $ajeno = $this->persona('De otra área', 'empleado', $otraArea->id);
        $jefe = $this->persona('Jefa', 'admin');

        $curso = $this->curso();
        $this->reprobar($ajeno, $curso, 2);

        $caso = $this->actingAs($jefe)->getJson('/api/v1/supervisor/pendientes')->json('cursos_reprobados.0');

        $this->actingAs($encargado)
            ->postJson("/api/v1/supervisor/pendientes/{$caso['progress_id']}/atendido")
            ->assertStatus(403);
    }

    public function test_el_colaborador_no_entra_al_tablero(): void
    {
        $ana = $this->persona('Ana', 'empleado');

        $this->actingAs($ana)->getJson('/api/v1/supervisor/pendientes')->assertStatus(403);
    }
}
