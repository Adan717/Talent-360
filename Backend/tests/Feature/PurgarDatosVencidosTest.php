<?php

namespace Tests\Feature;

use App\Helpers\TenantTimezone;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PURGA DE RETENCIÓN A CINCO AÑOS — `datos:purgar-vencidos` (2026-09-05).
 *
 * Todo aquí se prueba con casos SEMBRADOS. En la V2 no hay hoy nadie con cinco años desde su baja
 * —el sistema tiene meses— así que este comando no va a borrar nada en producción, y está bien:
 * se construye para que el día que aplique, aplique.
 */
class PurgarDatosVencidosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Purga QA', 'subdomain' => 'purgaqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $this->tz = TenantTimezone::for($this->tenant->id);

        // Tres usuarios de relleno ANTES del primer expediente: así `employees.id` y `users.id`
        // dejan de coincidir, que es la condición sin la cual la prueba de las columnas
        // engañosas (test_no_se_lleva_por_delante_las_filas_de_otra_persona) no probaría nada.
        foreach (['relleno1', 'relleno2', 'relleno3'] as $nombre) {
            User::create([
                'tenant_id' => $this->tenant->id, 'name' => $nombre,
                'email' => $nombre . '@purgaqa.test', 'password' => bcrypt('x'),
                'role' => 'empleado', 'is_active' => true,
            ]);
        }
    }

    /**
     * Alguien que se fue hace `$aniosDesdeLaBaja` años y dejó rastro por todas partes.
     *
     * @return array{user:User,employee:Employee}
     */
    private function exColaborador(string $nombre, float $aniosDesdeLaBaja = 6, array $extra = []): array
    {
        $baja = Carbon::now($this->tz)->subMonths((int) round($aniosDesdeLaBaja * 12));

        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower($nombre) . '@purgaqa.test', 'password' => bcrypt('x'),
            'role' => 'empleado', 'is_active' => false,
        ]);

        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'email' => strtolower($nombre) . '@purgaqa.test',
            'phone' => '5511223344', 'curp' => 'CUPU800825HDFRR00', 'rfc' => 'CUPU800825AB1',
            'nss' => '12345678901', 'address' => 'Calle Falsa 123',
            'emergency_contact_name' => 'Su mamá', 'emergency_contact_phone' => '5599887766',
            'base_salary' => 3000, 'salary' => 3000,
            'hire_date' => $baja->copy()->subYears(2)->toDateString(),
            'termination_date' => $baja->toDateString(),
            'termination_reason' => 'Renuncia voluntaria',
            'is_active_employee' => false,
        ], $extra));

        return ['user' => $user, 'employee' => $employee];
    }

    private function conRastro(User $user, Employee $employee, int $fichajes = 3): array
    {
        $baja = Carbon::parse($employee->termination_date, $this->tz);
        $ids = [];

        for ($i = 1; $i <= $fichajes; $i++) {
            $ids[] = DB::table('time_entries')->insertGetId([
                'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
                'date' => $baja->copy()->subDays($i)->toDateString(), 'type' => 'check_in',
                'time' => '09:00:00', 'is_late' => false, 'late_minutes' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // El historial que en Postgres escribiría el trigger. En sqlite no hay trigger, así que se
        // siembra a mano: lo que se prueba es que la purga NO deje el dato personal ahí dentro.
        foreach ($ids as $id) {
            DB::table('time_entries_historial')->insert([
                'tenant_id' => $this->tenant->id, 'time_entry_id' => $id, 'operacion' => 'INSERT',
                'fila_antes' => null,
                'fila_despues' => json_encode(['id' => $id, 'user_id' => $user->id, 'employee_name_at_time' => $employee->name]),
                'registrado_en' => now(),
            ]);
        }

        DB::table('weekly_payrolls')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'start_date' => $baja->copy()->subDays(7)->toDateString(), 'end_date' => $baja->toDateString(),
            'base_salary_paid' => 3000, 'net_pay' => 2800, 'deductions' => 200,
            'status' => 'approved_by_employee', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'date' => $baja->toDateString(), 'type' => 'late_arrival', 'timestamp_str' => '09:15',
            'reason' => 'Retardo de 15 minutos', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Columna engañosa: en silla_requests `employee_id` guarda un users.id.
        DB::table('silla_requests')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $user->id,
            'requested_at' => now(), 'status' => 'finished',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('daily_approvals')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'date' => $baja->toDateString(), 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $ids;
    }

    private function correr(array $opciones = []): int
    {
        return $this->artisan('datos:purgar-vencidos', $opciones)->run();
    }

    // ------------------------------------------------------------------ el piso legal

    public function test_no_se_puede_pedir_un_plazo_menor_al_minimo_legal(): void
    {
        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);

        $this->artisan('datos:purgar-vencidos', ['--anios' => 3, '--aplicar' => true])
            ->expectsOutputToContain('art. 804 LFT')
            ->assertExitCode(1);

        $this->assertDatabaseHas('employees', ['id' => $victor['employee']->id, 'name' => 'Victor']);
    }

    // ------------------------------------------------------------------ simulacro

    public function test_el_simulacro_no_borra_nada(): void
    {
        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);

        $this->correr();

        $this->assertDatabaseHas('employees', ['id' => $victor['employee']->id, 'name' => 'Victor', 'curp' => 'CUPU800825HDFRR00']);
        $this->assertSame(3, DB::table('time_entries')->where('user_id', $victor['user']->id)->count());
        $this->assertNull($victor['employee']->refresh()->purged_at);
    }

    // ------------------------------------------------------------------ el borrado

    public function test_al_aplicar_borra_todo_el_rastro_de_la_persona(): void
    {
        $victor = $this->exColaborador('Victor');
        $ids = $this->conRastro($victor['user'], $victor['employee']);

        $this->correr(['--aplicar' => true]);

        $userId = $victor['user']->id;
        $empId = $victor['employee']->id;

        $this->assertSame(0, DB::table('time_entries')->where('user_id', $userId)->count());
        // Y el historial. Sin este renglón la purga no purga: el trigger de Postgres copia cada
        // fila borrada al historial, así que borrar sólo `time_entries` mueve el dato personal de
        // una tabla a otra y deja al dueño creyendo que cumplió.
        $this->assertSame(0, DB::table('time_entries_historial')->whereIn('time_entry_id', $ids)->count());
        $this->assertSame(0, DB::table('weekly_payrolls')->where('employee_id', $empId)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('user_id', $userId)->count());
        $this->assertSame(0, DB::table('silla_requests')->where('employee_id', $userId)->count());
        $this->assertSame(0, DB::table('daily_approvals')->where('employee_id', $empId)->count());
    }

    public function test_el_expediente_y_la_cuenta_se_anonimizan_pero_no_desaparecen(): void
    {
        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);
        $alta = $victor['employee']->hire_date;
        $baja = $victor['employee']->termination_date;

        $this->correr(['--aplicar' => true]);

        // La fila SIGUE: hay llaves foráneas colgando y el reporte de rotación necesita contarla.
        $emp = Employee::withoutGlobalScopes()->withTrashed()->find($victor['employee']->id);
        $this->assertNotNull($emp, 'el expediente no se borra: se vacía');

        // Lo que identifica a la persona, fuera.
        $this->assertNull($emp->curp);
        $this->assertNull($emp->rfc);
        $this->assertNull($emp->nss);
        $this->assertNull($emp->email);
        $this->assertNull($emp->phone);
        $this->assertNull($emp->address);
        $this->assertNull($emp->emergency_contact_name);
        $this->assertNull($emp->emergency_contact_phone);
        $this->assertNull($emp->base_salary);
        $this->assertNotSame('Victor', $emp->name);

        // El esqueleto que la rotación necesita, dentro.
        $this->assertSame($alta, $emp->hire_date);
        $this->assertSame($baja, $emp->termination_date);
        $this->assertSame('Renuncia voluntaria', $emp->termination_reason);
        $this->assertNotNull($emp->purged_at);

        $cuenta = User::withoutGlobalScopes()->withTrashed()->find($victor['user']->id);
        $this->assertNotNull($cuenta, 'borrar users arrastraría media base por cascade');
        $this->assertNull($cuenta->email);
        $this->assertNotSame('Victor', $cuenta->name);
        $this->assertFalse((bool) $cuenta->is_active);
    }

    public function test_se_le_revocan_los_tokens_de_sesion(): void
    {
        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);
        $victor['user']->createToken('movil');

        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_id', $victor['user']->id)->count());

        $this->correr(['--aplicar' => true]);

        // Una sesión viva sobre una cuenta anonimizada seguiría entrando a la aplicación.
        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_id', $victor['user']->id)->count());
    }

    public function test_no_se_lleva_por_delante_las_filas_de_otra_persona(): void
    {
        // ÉSTE es el candado del defecto más fácil de cometer aquí. En media docena de tablas la
        // columna se llama `employee_id` pero guarda un `users.id` (silla_requests,
        // meal_photo_evidences, pase_lista_ratings, meal_queue_entries, store_opening_events).
        // Borrar ahí por `employees.id` no habría fallado ruidosamente: habría borrado los
        // reposos de OTRA persona —la que tuviera ese número en users— y dejado intactos los del
        // purgado. Silencioso, y en producción.
        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);

        $empId = $victor['employee']->id;
        $this->assertNotSame($empId, $victor['user']->id, 'la prueba exige que los dos ids difieran');

        // Un reposo de OTRO usuario, cuyo users.id coincide con el employees.id del purgado.
        DB::table('silla_requests')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $empId,
            'requested_at' => now(), 'status' => 'finished',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->correr(['--aplicar' => true]);

        $this->assertSame(0, DB::table('silla_requests')->where('employee_id', $victor['user']->id)->count(),
            'los reposos del purgado sí se borran');
        $this->assertSame(1, DB::table('silla_requests')->where('employee_id', $empId)->count(),
            'los de la persona que sólo comparte número NO se tocan');
    }

    public function test_borra_los_archivos_del_expediente_y_del_comedor(): void
    {
        Storage::fake('local');

        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);

        $rutaDoc = 'expedientes/' . $this->tenant->id . '/' . $victor['employee']->id . '/ine.pdf';
        $rutaEvidencia = 'meal-evidence/' . $this->tenant->id . '/comida.jpg';
        Storage::disk('local')->put($rutaDoc, 'INE escaneada');
        Storage::disk('local')->put($rutaEvidencia, 'foto');

        DB::table('employee_documents')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $victor['employee']->id,
            'doc_type' => 'ine', 'original_name' => 'ine.pdf', 'path' => $rutaDoc,
            'mime' => 'application/pdf', 'size_bytes' => 13, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // OJO: aquí `employee_id` es un users.id.
        DB::table('meal_photo_evidences')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $victor['user']->id,
            'date' => Carbon::parse($victor['employee']->termination_date)->toDateString(),
            'type' => 'meal_start', 'url' => '/api/v1/clock/meal-evidence/x', 'path' => $rutaEvidencia,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->correr(['--aplicar' => true]);

        Storage::disk('local')->assertMissing($rutaDoc);
        Storage::disk('local')->assertMissing($rutaEvidencia);
        $this->assertSame(0, DB::table('employee_documents')->where('employee_id', $victor['employee']->id)->count());
    }

    public function test_nunca_borra_un_archivo_fuera_de_su_carpeta(): void
    {
        Storage::fake('local');

        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);

        // Una ruta que se escapa de la carpeta del expediente. En agosto un `photo_url` con
        // "../.env" llegó a un @unlink() y borraba el .env del servidor: aquí las rutas las genera
        // el servidor, pero la comprobación no depende de eso.
        Storage::disk('local')->put('secreto.txt', 'no me toques');
        DB::table('employee_documents')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $victor['employee']->id,
            'doc_type' => 'ine', 'original_name' => 'ine.pdf',
            'path' => 'expedientes/' . $this->tenant->id . '/' . $victor['employee']->id . '/../../../secreto.txt',
            'mime' => 'application/pdf', 'size_bytes' => 3, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->correr(['--aplicar' => true]);

        Storage::disk('local')->assertExists('secreto.txt');
    }

    public function test_es_idempotente_la_segunda_corrida_ya_no_lo_encuentra(): void
    {
        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);

        $this->correr(['--aplicar' => true]);
        $marca = Employee::withoutGlobalScopes()->withTrashed()->find($victor['employee']->id)->purged_at;

        $this->artisan('datos:purgar-vencidos')
            ->expectsOutputToContain('No hay nada que purgar')
            ->assertExitCode(0);

        $this->assertEquals(
            $marca,
            Employee::withoutGlobalScopes()->withTrashed()->find($victor['employee']->id)->purged_at
        );
    }

    // ------------------------------------------------------------------ lo que NO se purga

    public function test_la_reserva_legal_lo_deja_fuera_pase_el_tiempo_que_pase(): void
    {
        $victor = $this->exColaborador('Victor', 12);
        $this->conRastro($victor['user'], $victor['employee']);
        $victor['employee']->forceFill([
            'legal_hold_at' => now()->subYear(),
            'legal_hold_reason' => 'Demanda laboral 421/2026',
        ])->save();

        $this->artisan('datos:purgar-vencidos', ['--aplicar' => true])
            ->expectsOutputToContain('RESERVA LEGAL')
            ->assertExitCode(0);

        $this->assertDatabaseHas('employees', ['id' => $victor['employee']->id, 'name' => 'Victor']);
        $this->assertSame(3, DB::table('time_entries')->where('user_id', $victor['user']->id)->count());
    }

    public function test_una_baja_sin_fecha_se_lista_y_no_se_purga(): void
    {
        // Las bajas anteriores al 2026-08-16 no tienen fecha: la columna no existía. Sin fecha no
        // hay plazo que contar, y no se inventa uno.
        $antiguo = $this->exColaborador('Antiguo', 8, ['termination_date' => null, 'termination_reason' => null]);
        $this->conRastro($antiguo['user'], $antiguo['employee']);

        $this->artisan('datos:purgar-vencidos', ['--aplicar' => true])
            ->expectsOutputToContain('SIN FECHA')
            ->assertExitCode(0);

        $this->assertDatabaseHas('employees', ['id' => $antiguo['employee']->id, 'name' => 'Antiguo']);
    }

    public function test_quien_figura_activo_no_se_purga_aunque_arrastre_una_fecha_vencida(): void
    {
        $regresado = $this->exColaborador('Regresado', 7, ['is_active_employee' => true]);
        $this->conRastro($regresado['user'], $regresado['employee']);

        $this->artisan('datos:purgar-vencidos', ['--aplicar' => true])
            ->expectsOutputToContain('figura ACTIVO')
            ->assertExitCode(0);

        $this->assertDatabaseHas('employees', ['id' => $regresado['employee']->id, 'name' => 'Regresado']);
    }

    public function test_si_ficho_despues_de_su_baja_la_baja_no_es_real(): void
    {
        $dudoso = $this->exColaborador('Dudoso', 7);
        $this->conRastro($dudoso['user'], $dudoso['employee']);

        // Un fichaje POSTERIOR a la fecha de baja: o la baja no fue real, o la fecha está mal.
        DB::table('time_entries')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $dudoso['user']->id,
            'date' => Carbon::parse($dudoso['employee']->termination_date)->addMonths(3)->toDateString(),
            'type' => 'check_in', 'time' => '09:00:00', 'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('datos:purgar-vencidos', ['--aplicar' => true])
            ->expectsOutputToContain('DESPUÉS de su baja')
            ->assertExitCode(0);

        $this->assertDatabaseHas('employees', ['id' => $dudoso['employee']->id, 'name' => 'Dudoso']);
    }

    public function test_quien_todavia_no_cumple_el_plazo_no_se_toca(): void
    {
        $reciente = $this->exColaborador('Reciente', 2);
        $this->conRastro($reciente['user'], $reciente['employee']);

        $this->correr(['--aplicar' => true]);

        $this->assertDatabaseHas('employees', ['id' => $reciente['employee']->id, 'name' => 'Reciente']);
        $this->assertSame(3, DB::table('time_entries')->where('user_id', $reciente['user']->id)->count());
    }

    public function test_el_filtro_por_empresa_no_alcanza_a_las_demas(): void
    {
        $otra = Tenant::create([
            'name' => 'Otra', 'subdomain' => 'otrapurga', 'plan' => 'enterprise', 'is_active' => true,
        ]);

        $mio = $this->exColaborador('Mio');
        $this->conRastro($mio['user'], $mio['employee']);

        $ajenoUser = User::create([
            'tenant_id' => $otra->id, 'name' => 'Ajeno', 'email' => 'ajeno@otra.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => false,
        ]);
        $ajeno = Employee::create([
            'tenant_id' => $otra->id, 'user_id' => $ajenoUser->id, 'name' => 'Ajeno',
            'is_active_employee' => false,
            'termination_date' => Carbon::now($this->tz)->subYears(9)->toDateString(),
        ]);

        $this->correr(['--aplicar' => true, '--tenant' => $this->tenant->id]);

        $this->assertNotNull(Employee::withoutGlobalScopes()->find($mio['employee']->id)->purged_at);
        $this->assertNull(Employee::withoutGlobalScopes()->find($ajeno->id)->purged_at, 'otra empresa no se toca');
        $this->assertSame('Ajeno', Employee::withoutGlobalScopes()->find($ajeno->id)->name);
    }

    public function test_el_filtro_por_expediente_purga_solo_a_esa_persona(): void
    {
        $uno = $this->exColaborador('Uno');
        $this->conRastro($uno['user'], $uno['employee']);
        $dos = $this->exColaborador('Dos');
        $this->conRastro($dos['user'], $dos['employee']);

        $this->correr(['--aplicar' => true, '--empleado' => $uno['employee']->id]);

        $this->assertNotNull(Employee::withoutGlobalScopes()->find($uno['employee']->id)->purged_at);
        $this->assertNull(Employee::withoutGlobalScopes()->find($dos['employee']->id)->purged_at);
    }

    public function test_no_toca_las_tablas_cuyo_user_id_apunta_a_otra_identidad(): void
    {
        // La segunda trampa del esquema, hermana de la anterior: `user_id` tampoco apunta siempre
        // a `users`. En la Wiki apunta a `obsidian_users` (tabla de cuentas propia) y en las notas
        // de soporte a `platform_users` (el personal de Talent 360). Purgar ahí por el id del
        // colaborador borraría los datos de un tercero y dejaría intactos los suyos.
        $tablas = array_column(\App\Support\HuellaDelColaborador::plan(), 'tabla');

        foreach (['obsidian_exams', 'obsidian_exam_attempts', 'obsidian_read_progress', 'support_ticket_notes'] as $ajena) {
            $this->assertNotContains($ajena, $tablas, "{$ajena} no referencia a `users`: la purga no puede tocarla");
            $this->assertArrayHasKey($ajena, \App\Support\HuellaDelColaborador::FUERA, 'y la exclusión tiene que estar escrita');
        }
    }

    public function test_avisa_de_la_cuenta_de_la_wiki_en_vez_de_adivinar(): void
    {
        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);

        // `obsidian_users` no tiene ninguna llave hacia el expediente: lo único en común es el
        // correo. Emparejar por correo sería adivinar (y equivocarse borraría la cuenta de otra
        // persona), así que no se purga — pero tampoco se calla.
        DB::table('obsidian_users')->insert([
            'tenant_id' => $this->tenant->id, 'name' => 'Victor', 'email' => 'victor@purgaqa.test',
            'password' => bcrypt('x'), 'role' => 'lector', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('datos:purgar-vencidos')
            ->expectsOutputToContain('LA WIKI QUEDA FUERA')
            ->expectsOutputToContain('victor@purgaqa.test')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('obsidian_users')->where('email', 'victor@purgaqa.test')->count());
    }

    // ------------------------------------------------------------------ Postgres

    public function test_en_postgres_el_historial_lo_borra_la_funcion_security_definer(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'La función purgar_historial_de_persona es de Postgres (plpgsql) y el trigger que llena '
                . 'el historial también. En sqlite se prueba el orden y el predicado; el camino real se '
                . 'verifica con phpunit.postgres.xml.'
            );
        }

        $victor = $this->exColaborador('Victor');
        $this->conRastro($victor['user'], $victor['employee']);

        $this->correr(['--aplicar' => true]);

        $quedan = DB::table('time_entries_historial')
            ->where('tenant_id', $this->tenant->id)
            ->whereRaw("COALESCE(NULLIF(fila_antes->>'user_id',''), NULLIF(fila_despues->>'user_id',''))::bigint = ?", [$victor['user']->id])
            ->count();

        $this->assertSame(0, $quedan, 'ni las filas DELETE que el propio trigger acaba de escribir');
    }

    public function test_en_postgres_la_funcion_se_niega_a_borrar_a_quien_no_vencio(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sólo Postgres: la función y su guarda viven en plpgsql.');
        }

        // La función no acepta que le digan qué borrar: comprueba ella misma el plazo y la
        // reserva. Aunque cualquiera pueda invocarla, no hay forma de pedirle "bórrame la
        // evidencia de este juicio".
        $reciente = $this->exColaborador('Reciente', 1);
        $this->conRastro($reciente['user'], $reciente['employee']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::selectOne('SELECT purgar_historial_de_persona(?, ?, ?::date) AS borradas', [
            $this->tenant->id,
            $reciente['user']->id,
            Carbon::now($this->tz)->toDateString(),
        ]);
    }
}
