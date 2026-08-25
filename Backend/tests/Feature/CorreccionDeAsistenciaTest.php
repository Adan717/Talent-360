<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Scopes\ExcludeAnuladasScope;
use App\Services\CorreccionDeAsistencia;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Corregir un fichaje: se anula y se sustituye, nunca se sobrescribe (2026-08-25).
 *
 * La regla de la póliza contable. El fichaje equivocado se queda donde está, marcado, y otro lo
 * reemplaza: para la nómina cuenta el nuevo, para quien tenga que probar qué pasó están los dos y
 * el motivo. Y tres garantías que viven en el servicio, no en la pantalla, para que ninguna vía
 * pueda saltárselas: motivo obligatorio, rastro firmado y aviso al colaborador.
 */
class CorreccionDeAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $colaborador;
    private User $jefa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00'));
        $this->tenant = Tenant::create(['name' => 'Correccion QA', 'subdomain' => 'correccionqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->colaborador = $this->persona('Miguel', 'empleado');
        $this->jefa = $this->persona('Jefa', 'admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function persona(string $nombre, string $rol): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower($nombre) . '@correccionqa.test', 'password' => bcrypt('x'), 'role' => $rol,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        return $user;
    }

    private function fichaje(string $hora = '09:03:00', array $extra = []): TimeEntry
    {
        return TimeEntry::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => '2026-08-24', 'type' => 'check_in', 'time' => $hora,
            'is_late' => true, 'late_minutes' => 3,
        ], $extra));
    }

    private function servicio(): CorreccionDeAsistencia
    {
        return app(CorreccionDeAsistencia::class);
    }

    // ------------------------------------------------------- póliza contable

    public function test_corregir_deja_los_dos_registros_el_viejo_anulado_y_el_nuevo_vigente(): void
    {
        $original = $this->fichaje('09:03:00');

        $r = $this->servicio()->corregir(
            $original,
            ['time' => '09:00:00', 'is_late' => false, 'late_minutes' => 0],
            'El reloj de la sucursal iba 3 minutos adelantado.',
            $this->jefa
        );

        // El nuevo es el único que cuenta.
        $vigentes = TimeEntry::where('user_id', $this->colaborador->id)->get();
        $this->assertCount(1, $vigentes);
        $this->assertSame('09:00:00', substr((string) $vigentes[0]->time, 0, 8));
        $this->assertSame($r['nuevo_id'], $vigentes[0]->id);

        // El viejo sigue existiendo, anulado y ligado a su corrección.
        $viejo = TimeEntry::withoutGlobalScope(ExcludeAnuladasScope::class)->find($original->id);
        $this->assertNotNull($viejo, 'la evidencia no se borra');
        $this->assertNotNull($viejo->anulado_at);
        $this->assertSame($r['correccion_id'], (int) $viejo->anulado_por_correccion_id);
    }

    public function test_el_sustituto_hereda_lo_que_no_se_corrige(): void
    {
        $original = $this->fichaje('09:03:00', [
            'photo_url' => 'evidencia/foto.jpg',
            'job_role_title_at_time' => 'Cajero de Mostrador',
        ]);

        $r = $this->servicio()->corregir($original, ['time' => '09:00:00'], 'Reloj adelantado.', $this->jefa);

        $nuevo = TimeEntry::find($r['nuevo_id']);
        $this->assertSame('evidencia/foto.jpg', $nuevo->photo_url, 'la foto del fichaje también es evidencia');
        $this->assertSame('Cajero de Mostrador', $nuevo->job_role_title_at_time);
    }

    /** Sin datos nuevos sólo se anula: es el caso del fichaje duplicado que no debió existir. */
    public function test_se_puede_anular_sin_sustituir(): void
    {
        $original = $this->fichaje('09:03:00');

        $r = $this->servicio()->corregir($original, [], 'Doble clic: este fichaje está duplicado.', $this->jefa);

        $this->assertNull($r['nuevo_id']);
        $this->assertCount(0, TimeEntry::where('user_id', $this->colaborador->id)->get());
        $this->assertDatabaseHas('asistencia_correcciones', ['id' => $r['correccion_id'], 'tipo' => 'anulacion']);
    }

    public function test_dar_de_alta_un_fichaje_olvidado_no_anula_nada(): void
    {
        $r = $this->servicio()->darDeAlta([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => '2026-08-24', 'type' => 'check_out', 'time' => '18:00:00',
            'is_late' => false, 'late_minutes' => 0,
        ], 'Olvidó checar salida; el encargado lo vio irse a las 18:00.', $this->jefa);

        $this->assertNull($r['anulado_id']);
        $this->assertDatabaseHas('asistencia_correcciones', ['id' => $r['correccion_id'], 'tipo' => 'alta']);
        $this->assertNotNull(TimeEntry::find($r['nuevo_id']));
    }

    // ------------------------------------------------------- las tres garantías

    public function test_sin_motivo_no_hay_correccion(): void
    {
        $original = $this->fichaje();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('motivo escrito');

        $this->servicio()->corregir($original, ['time' => '09:00:00'], '   ', $this->jefa);
    }

    public function test_un_motivo_vacio_no_deja_rastro_a_medias(): void
    {
        $original = $this->fichaje();

        try {
            $this->servicio()->corregir($original, ['time' => '09:00:00'], '', $this->jefa);
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertDatabaseCount('asistencia_correcciones', 0);
        $this->assertNull(TimeEntry::find($original->id)->anulado_at, 'el original no puede quedar anulado a medias');
    }

    public function test_al_colaborador_se_le_avisa_siempre(): void
    {
        $original = $this->fichaje('09:03:00');

        $this->servicio()->corregir($original, ['time' => '09:00:00'], 'El reloj iba adelantado.', $this->jefa);

        $aviso = DB::table('internal_messages')
            ->where('receiver_id', $this->colaborador->id)
            ->first();

        $this->assertNotNull($aviso, 'un ajuste que la persona nunca supo es lo que se ve mal en un juicio');
        $this->assertSame('private', $aviso->type);
        $this->assertStringContainsString('El reloj iba adelantado', $aviso->content);
        $this->assertStringContainsString('Jefa', $aviso->content, 'tiene que saber quién lo autorizó');
    }

    /**
     * Salio corrigiendo un fichaje REAL en produccion: el aviso llegaba al reloj del colaborador
     * pero `notificado_at` se quedaba nulo, o sea que el expediente de la correccion decia "sin
     * avisar" mientras el aviso ya estaba entregado. Un registro que afirma algo que no coincide
     * con lo que el sistema hizo es justo lo que esta bitacora existe para evitar.
     */
    public function test_el_expediente_registra_que_el_aviso_salio(): void
    {
        $original = $this->fichaje('09:03:00');

        $r = $this->servicio()->corregir($original, ['time' => '09:00:00'], 'Reloj adelantado.', $this->jefa);

        $c = DB::table('asistencia_correcciones')->find($r['correccion_id']);
        $this->assertNotNull($c->notificado_at, 'si el aviso salio, el expediente tiene que decirlo');

        $avisos = DB::table('internal_messages')->where('receiver_id', $this->colaborador->id)->count();
        $this->assertSame(1, $avisos, 'un aviso por correccion, ni cero ni dos');
    }

    public function test_el_alta_manual_tambien_sella_el_aviso(): void
    {
        $r = $this->servicio()->darDeAlta([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => '2026-08-24', 'type' => 'check_out', 'time' => '18:00:00',
            'is_late' => false, 'late_minutes' => 0,
        ], 'Olvido checar salida.', $this->jefa);

        $this->assertNotNull(DB::table('asistencia_correcciones')->find($r['correccion_id'])->notificado_at);
    }

    public function test_la_correccion_guarda_quien_que_y_por_que(): void
    {
        $original = $this->fichaje('09:03:00');

        $r = $this->servicio()->corregir($original, ['time' => '09:00:00'], 'Reloj adelantado 3 min.', $this->jefa);

        $c = DB::table('asistencia_correcciones')->find($r['correccion_id']);

        $this->assertSame($this->jefa->id, (int) $c->autorizado_por);
        $this->assertSame($this->colaborador->id, (int) $c->empleado_user_id);
        $this->assertSame('Reloj adelantado 3 min.', $c->motivo);
        $this->assertStringContainsString('09:03', (string) $c->valor_anterior);
        $this->assertStringContainsString('09:00', (string) $c->valor_nuevo);
    }

    public function test_no_se_puede_corregir_dos_veces_el_mismo_fichaje(): void
    {
        $original = $this->fichaje('09:03:00');
        $this->servicio()->corregir($original, ['time' => '09:00:00'], 'Primera corrección.', $this->jefa);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ya está anulado');

        $this->servicio()->corregir($original->fresh(), ['time' => '08:55:00'], 'Segunda.', $this->jefa);
    }

    /** Pero el sustituto sí se puede volver a corregir: se encadena, y la historia queda entera. */
    public function test_el_sustituto_se_puede_corregir_y_la_historia_queda_completa(): void
    {
        $original = $this->fichaje('09:03:00');
        $r1 = $this->servicio()->corregir($original, ['time' => '09:00:00'], 'Reloj adelantado.', $this->jefa);
        $r2 = $this->servicio()->corregir(
            TimeEntry::find($r1['nuevo_id']),
            ['time' => '08:55:00'],
            'La cámara lo muestra entrando a las 8:55.',
            $this->jefa
        );

        $historia = $this->servicio()->historiaDelDia($this->tenant->id, $this->colaborador->id, '2026-08-24');

        $this->assertCount(3, $historia, 'los tres estados por los que pasó ese fichaje');
        $this->assertSame(
            ['09:03', '09:00', '08:55'],
            $historia->map(fn ($e) => substr((string) $e->time, 0, 5))->all()
        );

        // Y sólo uno cuenta.
        $this->assertCount(1, TimeEntry::where('user_id', $this->colaborador->id)->get());
        $this->assertSame($r2['nuevo_id'], TimeEntry::where('user_id', $this->colaborador->id)->first()->id);
    }
}
