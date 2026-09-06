<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EL CANDADO DE LA BITÁCORA — paso 3 del RFC (2026-09-05).
 *
 * El RFC dice que el historial de asistencia es *inmutable*. Hasta ahora eso era una intención:
 * la aplicación entra a Postgres como superusuario y dueño de las tablas, y a un superusuario no
 * se le revoca nada. Estas pruebas comprueban el hecho, no la intención — y comprueban también lo
 * que casi se me escapa al diseñarlo:
 *
 *   **El trigger escribe con los permisos de quien fichó.** Si a la aplicación se le quita el
 *   INSERT sobre el historial y la función NO es `SECURITY DEFINER`, el trigger también se lo
 *   come, la transacción del fichaje falla y **la plantilla se queda sin poder checar**. Es decir:
 *   el candado mal puesto no protege evidencia, apaga el reloj checador. Por eso la primera
 *   prueba de este archivo no es sobre permisos, es sobre `SECURITY DEFINER`.
 *
 * Todo esto es de Postgres: en sqlite no hay roles ni permisos por tabla y se salta declarándolo.
 * La corrida de verdad es con `phpunit.postgres.xml` (y SÓLO con la invocación segura que
 * documenta su cabecera).
 */
class CandadoDeLaBitacoraTest extends TestCase
{
    use RefreshDatabase;

    /** Rol de mentira, creado y destruido por la propia prueba. Nunca toca al de producción. */
    private const ROL = 'talent360_candado_test';

    private Tenant $tenant;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->tenant = Tenant::create([
            'name' => 'Candado QA', 'subdomain' => 'candadoqa', 'plan' => 'enterprise', 'is_active' => true,
        ]);
        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador', 'email' => 'colab@candadoqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'name' => 'Colaborador',
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->borrarRol();
        }

        parent::tearDown();
    }

    private function soloPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El candado son permisos de Postgres; sqlite no tiene roles por tabla. Se verifica con phpunit.postgres.xml.');
        }
    }

    // ----------------------------------------------------------------- el requisito previo

    public function test_la_funcion_del_trigger_es_security_definer(): void
    {
        $this->soloPostgres();

        $fila = DB::selectOne("SELECT prosecdef FROM pg_proc WHERE proname = 'registrar_historial_time_entries'");

        $this->assertNotNull($fila, 'La función del trigger no existe.');
        $this->assertTrue(
            (bool) $fila->prosecdef,
            'La función del trigger NO es SECURITY DEFINER. Con el candado puesto, el trigger se '
            . 'quedaría sin permiso de escribir en el historial y CADA FICHAJE FALLARÍA. '
            . 'Ver la migración 2026_09_05_090000.'
        );
    }

    // ----------------------------------------------------------------- las tres cerraduras

    public function test_con_el_candado_la_aplicacion_no_puede_tocar_el_historial(): void
    {
        $this->soloPostgres();
        $this->fichaje();
        $this->crearRolYAplicarCandado();

        $id = DB::table('time_entries_historial')->value('id');
        $this->assertNotNull($id, 'El trigger no dejó ninguna fila que intentar modificar.');

        $this->assertSeNiega(
            "UPDATE time_entries_historial SET origen = 'reescrito' WHERE id = {$id}",
            'la aplicación pudo REESCRIBIR una fila del historial'
        );
        $this->assertSeNiega(
            "DELETE FROM time_entries_historial WHERE id = {$id}",
            'la aplicación pudo BORRAR una fila del historial'
        );
        $this->assertSeNiega(
            'INSERT INTO time_entries_historial (time_entry_id, tenant_id, operacion, registrado_en) '
            . "VALUES (1, 1, 'INSERT', now())",
            'la aplicación pudo FABRICAR una fila del historial'
        );

        // Y lo que sí debe poder: leerlo. Un historial que la aplicación no puede consultar no
        // sirve para enseñárselo a nadie.
        $this->assertNotEmpty(
            $this->comoLaApp('SELECT id FROM time_entries_historial'),
            'La aplicación se quedó sin poder LEER el historial: el candado se pasó de vuelta.'
        );
    }

    public function test_con_el_candado_una_correccion_no_se_puede_borrar_ni_reescribir(): void
    {
        $this->soloPostgres();
        $this->crearRolYAplicarCandado();

        // La póliza se puede escribir…
        $this->comoLaApp(
            'INSERT INTO asistencia_correcciones (tenant_id, empleado_user_id, autorizado_por, motivo, created_at, updated_at) '
            . "VALUES ({$this->tenant->id}, {$this->colaborador->id}, {$this->colaborador->id}, 'prueba', now(), now())"
        );

        // …y ya no se puede cancelar a escondidas. Una póliza contable no se borra: se cancela
        // con otra, y las dos se conservan.
        $this->assertSeNiega(
            "UPDATE asistencia_correcciones SET motivo = 'otro'",
            'la aplicación pudo REESCRIBIR el motivo de una corrección'
        );
        $this->assertSeNiega(
            'DELETE FROM asistencia_correcciones',
            'la aplicación pudo BORRAR una corrección'
        );
    }

    public function test_con_el_candado_no_se_puede_truncar_la_asistencia(): void
    {
        $this->soloPostgres();
        $this->fichaje();
        $this->crearRolYAplicarCandado();

        // El trigger es FOR EACH ROW: un TRUNCATE vaciaría la asistencia de TODAS las empresas
        // sin dejar una sola fila en el historial. Era el agujero por el que se colaba justo el
        // escenario que la bitácora existe para impedir.
        $this->assertSeNiega(
            'TRUNCATE time_entries',
            'la aplicación pudo TRUNCAR la asistencia, que vacía la tabla SIN pasar por el trigger'
        );
    }

    // ----------------------------------------------------------------- y el reloj sigue vivo

    public function test_con_el_candado_puesto_el_fichaje_sigue_escribiendo_en_el_historial(): void
    {
        $this->soloPostgres();
        $this->crearRolYAplicarCandado();

        $antes = (int) DB::table('time_entries_historial')->count();

        // Un fichaje hecho POR EL ROL DE LA APLICACIÓN, que no tiene INSERT sobre el historial.
        // Si esto falla, el candado apagó el reloj checador.
        $this->comoLaApp(
            'INSERT INTO time_entries (tenant_id, user_id, date, type, time, is_late, late_minutes, created_at, updated_at) '
            . "VALUES ({$this->tenant->id}, {$this->colaborador->id}, CURRENT_DATE, 'check_in', '09:00:00', false, 0, now(), now())"
        );

        $this->assertSame(
            $antes + 1,
            (int) DB::table('time_entries_historial')->count(),
            'El fichaje pasó pero el trigger no escribió en el historial: el candado dejó la '
            . 'bitácora ciega, que es peor que no tenerla.'
        );
    }

    // ----------------------------------------------------------------- el comando se planta

    public function test_el_comando_se_niega_si_el_rol_no_existe_y_no_inventa_contrasenas(): void
    {
        $this->soloPostgres();

        $this->artisan('bitacora:candado', ['--rol' => 'rol_que_no_existe_jamas', '--aplicar' => true])
            ->assertExitCode(1);

        $this->assertNull(
            DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', ['rol_que_no_existe_jamas']),
            'El comando CREÓ un rol. No debe: una credencial de producción la pone una persona.'
        );
    }

    public function test_el_simulacro_no_cambia_ningun_permiso(): void
    {
        $this->soloPostgres();
        $this->crearRol();

        $this->artisan('bitacora:candado', ['--rol' => self::ROL])->assertExitCode(0);

        // Sin --aplicar no se concedió ni se retiró nada: el rol recién creado sigue sin ver la
        // tabla, exactamente como estaba.
        $this->assertFalse(
            (bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS p', [self::ROL, 'time_entries', 'SELECT'])->p,
            'El simulacro concedió permisos.'
        );
    }

    // ----------------------------------------------------------------- utilería

    private function fichaje(): TimeEntry
    {
        return TimeEntry::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->colaborador->id,
            'date' => now()->toDateString(),
            'type' => 'check_in',
            'time' => '09:00:00',
            'is_late' => false,
            'late_minutes' => 0,
        ]);
    }

    private function crearRol(): void
    {
        $this->borrarRol();
        DB::statement('CREATE ROLE ' . self::ROL . ' NOLOGIN');
    }

    private function borrarRol(): void
    {
        if (DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [self::ROL]) === null) {
            return;
        }

        // Un rol al que se le concedieron permisos no se puede eliminar hasta soltarlos.
        DB::statement('DROP OWNED BY ' . self::ROL);
        DB::statement('DROP ROLE IF EXISTS ' . self::ROL);
    }

    private function crearRolYAplicarCandado(): void
    {
        $this->crearRol();
        $this->artisan('bitacora:candado', ['--rol' => self::ROL, '--aplicar' => true])->assertExitCode(0);
    }

    /**
     * Ejecuta una orden CON LOS PERMISOS DEL ROL DE LA APLICACIÓN, sin abrir otra conexión:
     * `SET LOCAL ROLE` cambia el rol efectivo dentro de la transacción y `RESET ROLE` lo devuelve.
     * Así se comprueban permisos de verdad sin salirse del `RefreshDatabase`.
     */
    private function comoLaApp(string $sql): array
    {
        DB::statement('SET LOCAL ROLE ' . self::ROL);
        try {
            // `DB::select` hace fetch del resultado: sobre un INSERT o un TRUNCATE, que no
            // devuelven filas, el driver protesta por una razón que no es la que se investiga.
            if (preg_match('/^\s*select\b/i', $sql) === 1) {
                return DB::select($sql);
            }

            DB::statement($sql);

            return [];
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    /**
     * En Postgres, **una orden que falla aborta la transacción entera**: todo lo que venga después
     * revienta con "current transaction is aborted" y la prueba se cae por una razón que no es la
     * que investiga (y arrastra a las siguientes, porque `RefreshDatabase` envuelve el test en esa
     * misma transacción). Por eso cada intento denegado va dentro de su propio SAVEPOINT, que se
     * deshace al fallar: la transacción sigue sana y se pueden encadenar varios intentos.
     */
    private function assertSeNiega(string $sql, string $queSePudo): void
    {
        DB::statement('SAVEPOINT intento_candado');

        try {
            $this->comoLaApp($sql);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::statement('ROLLBACK TO SAVEPOINT intento_candado');
            DB::statement('RESET ROLE');

            $this->assertStringContainsStringIgnoringCase(
                'permission denied',
                $e->getMessage(),
                "Falló, pero no por permisos: {$e->getMessage()}"
            );

            return;
        }

        DB::statement('ROLLBACK TO SAVEPOINT intento_candado');
        $this->fail("EL CANDADO NO SIRVE: {$queSePudo}.");
    }
}
