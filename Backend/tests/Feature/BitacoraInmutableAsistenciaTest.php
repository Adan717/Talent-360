<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Scopes\ExcludeAnuladasScope;
use App\Support\BitacoraDeAsistencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BITÁCORA INMUTABLE DE ASISTENCIA — cimientos (2026-08-25).
 *
 * En México la carga de la prueba es del patrón (LFT 784 y 804): si la empresa no puede exhibir
 * sus controles de asistencia, se presumen ciertos los hechos que alega el trabajador. Estas
 * pruebas fijan las dos mitades del cimiento:
 *
 *   · **Póliza contable**: un fichaje corregido se ANULA, no se borra. Deja de contar para lo que
 *     calcula, y sigue ahí para lo que hay que probar.
 *   · **El trigger**: la base de datos registra todo cambio venga de donde venga. Esa parte sólo
 *     puede comprobarse contra Postgres —sqlite no tiene plpgsql—, así que se declara saltada en
 *     la suite rápida y se corre de verdad con `phpunit.postgres.xml`.
 */
class BitacoraInmutableAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $colaborador;
    private User $jefa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Bitacora QA', 'subdomain' => 'bitacoraqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->colaborador = $this->persona('Colaborador', 'empleado');
        $this->jefa = $this->persona('Jefa', 'admin');
    }

    private function persona(string $nombre, string $rol): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower($nombre) . '@bitacoraqa.test', 'password' => bcrypt('x'), 'role' => $rol,
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
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->colaborador->id,
            'date' => '2026-08-24',
            'type' => 'check_in',
            'time' => $hora,
            'is_late' => false,
            'late_minutes' => 0,
        ], $extra));
    }

    private function soloPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El trigger de la bitácora es de Postgres; sqlite no tiene plpgsql. Se verifica con phpunit.postgres.xml.');
        }
    }

    // ------------------------------------------------------- póliza contable

    public function test_un_fichaje_anulado_deja_de_contar_para_lo_que_calcula(): void
    {
        $vigente = $this->fichaje('09:00:00');
        $anulado = $this->fichaje('09:03:00', ['anulado_at' => now()]);

        $vistos = TimeEntry::where('user_id', $this->colaborador->id)->pluck('id')->all();

        $this->assertContains($vigente->id, $vistos);
        $this->assertNotContains($anulado->id, $vistos, 'un fichaje anulado no puede seguir contando');
    }

    public function test_pero_sigue_existiendo_para_lo_que_hay_que_probar(): void
    {
        $anulado = $this->fichaje('09:03:00', ['anulado_at' => now()]);

        $todos = TimeEntry::withoutGlobalScope(ExcludeAnuladasScope::class)
            ->where('user_id', $this->colaborador->id)
            ->pluck('id')
            ->all();

        $this->assertContains($anulado->id, $todos, 'la evidencia no se borra: se marca');
        $this->assertDatabaseHas('time_entries', ['id' => $anulado->id]);
    }

    public function test_el_motor_de_nomina_no_ve_los_fichajes_anulados(): void
    {
        // Jornada real de 09:00 a 18:00, más un check_out duplicado a las 13:00 que se anuló.
        $this->fichaje('09:00:00');
        $this->fichaje('13:00:00', ['type' => 'check_out', 'anulado_at' => now()]);
        $this->fichaje('18:00:00', ['type' => 'check_out']);

        $salidas = TimeEntry::where('user_id', $this->colaborador->id)
            ->where('type', 'check_out')
            ->pluck('time')
            ->map(fn ($t) => substr((string) $t, 0, 5))
            ->all();

        $this->assertSame(['18:00'], $salidas, 'la salida anulada no puede aparecer en la jornada');
    }

    /**
     * La prueba que de verdad importa: el motor de NÓMINA —que lee `time_entries` en crudo, no por
     * Eloquent— tampoco cuenta un fichaje anulado. Si contara, una corrección produciría la jornada
     * vieja y la nueva a la vez, que es peor que no haber corregido nada.
     */
    public function test_el_motor_de_nomina_lee_por_la_puerta_y_no_ve_lo_anulado(): void
    {
        $emp = \App\Models\Employee::where('user_id', $this->colaborador->id)->first();
        $emp->update(['salario_diario' => 400, 'restDay' => 'Domingo', 'hire_date' => '2026-01-01']);
        \App\Models\LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);

        // Lunes trabajado. El resto de la semana, ausente.
        $this->fichaje('09:00:00', ['date' => '2026-08-10']);
        $this->fichaje('18:00:00', ['date' => '2026-08-10', 'type' => 'check_out']);

        $conElAnulado = app(\App\Services\ClockService::class)
            ->calculatePayrollForEmployee($emp, '2026-08-10', '2026-08-16');

        // Ahora se ANULA la entrada del lunes: para el motor, ese día deja de estar trabajado.
        \Illuminate\Support\Facades\DB::table('time_entries')
            ->where('date', '2026-08-10')
            ->update(['anulado_at' => now()]);

        $sinEl = app(\App\Services\ClockService::class)
            ->calculatePayrollForEmployee($emp, '2026-08-10', '2026-08-16');

        $this->assertLessThan(
            (int) $sinEl['incidents']['physical_absences'],
            (int) $conElAnulado['incidents']['physical_absences'],
            'anular los fichajes del lunes tiene que sumar una falta: si no, el motor los sigue viendo'
        );
    }

    // ------------------------------------------------------- la capacidad aislada

    public function test_corregir_fichajes_es_una_capacidad_propia_y_no_viene_con_supervisor(): void
    {
        $this->assertArrayHasKey('manage_punch_corrections', \App\Support\PermissionCatalog::DELEGABLE);
        $this->assertNotContains(
            'manage_punch_corrections',
            \App\Support\PermissionCatalog::SUPERVISOR_DEFAULTS,
            'mover la evidencia con la que la empresa se defiende no se reparte por omisión'
        );
    }

    // ------------------------------------------------------- el trigger (Postgres)

    public function test_el_trigger_registra_el_alta_de_un_fichaje(): void
    {
        $this->soloPostgres();

        $entrada = $this->fichaje('09:03:00');

        $historial = DB::table('time_entries_historial')->where('time_entry_id', $entrada->id)->get();

        $this->assertCount(1, $historial);
        $this->assertSame('INSERT', $historial[0]->operacion);
        $this->assertNull($historial[0]->fila_antes);
        $this->assertStringContainsString('09:03', (string) $historial[0]->fila_despues);
    }

    public function test_el_trigger_guarda_el_antes_y_el_despues_de_una_edicion(): void
    {
        $this->soloPostgres();

        $entrada = $this->fichaje('09:03:00');
        DB::table('time_entries')->where('id', $entrada->id)->update(['time' => '09:00:00']);

        $edicion = DB::table('time_entries_historial')
            ->where('time_entry_id', $entrada->id)
            ->where('operacion', 'UPDATE')
            ->first();

        $this->assertNotNull($edicion, 'una edición sin rastro es una evidencia perdida');
        $this->assertStringContainsString('09:03', (string) $edicion->fila_antes);
        $this->assertStringContainsString('09:00', (string) $edicion->fila_despues);
    }

    /** El caso que más importa: que quede constancia de lo que se borró. */
    public function test_el_trigger_conserva_la_fila_de_un_fichaje_borrado(): void
    {
        $this->soloPostgres();

        $entrada = $this->fichaje('09:03:00');
        $id = $entrada->id;
        DB::table('time_entries')->where('id', $id)->delete();

        $borrado = DB::table('time_entries_historial')
            ->where('time_entry_id', $id)
            ->where('operacion', 'DELETE')
            ->first();

        $this->assertNotNull($borrado);
        $this->assertStringContainsString('09:03', (string) $borrado->fila_antes);
        $this->assertNull($borrado->fila_despues);
        $this->assertDatabaseMissing('time_entries', ['id' => $id]);
    }

    /** Un cambio hecho a mano, saltándose Eloquent, tampoco escapa. */
    public function test_ni_una_consulta_cruda_se_escapa_del_trigger(): void
    {
        $this->soloPostgres();

        $entrada = $this->fichaje('09:03:00');
        DB::statement("UPDATE time_entries SET time = '07:00:00' WHERE id = ?", [$entrada->id]);

        $this->assertSame(
            1,
            DB::table('time_entries_historial')
                ->where('time_entry_id', $entrada->id)
                ->where('operacion', 'UPDATE')
                ->count()
        );
    }

    public function test_la_aplicacion_puede_firmar_quien_y_por_que(): void
    {
        $this->soloPostgres();

        $entrada = BitacoraDeAsistencia::firmando(
            $this->jefa->id,
            'correccion_manual',
            null,
            fn () => $this->fichaje('09:00:00')
        );

        $fila = DB::table('time_entries_historial')->where('time_entry_id', $entrada->id)->first();

        $this->assertSame($this->jefa->id, (int) $fila->actor_id);
        $this->assertSame('correccion_manual', $fila->origen);
    }

    /** Sin firma queda nulo, y eso también es información: nadie declaró esa corrección. */
    public function test_un_cambio_sin_firmar_queda_registrado_con_actor_nulo(): void
    {
        $this->soloPostgres();

        $entrada = $this->fichaje('09:03:00');

        $fila = DB::table('time_entries_historial')->where('time_entry_id', $entrada->id)->first();

        $this->assertNull($fila->actor_id);
        $this->assertNotNull($fila->registrado_en, 'la hora la pone la base, no la aplicación');
    }
}
