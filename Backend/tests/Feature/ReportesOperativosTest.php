<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reportes operativos nuevos (2026-08-13): retardos y faltas, horas trabajadas, cumplimiento
 * de rutinas.
 *
 * Lo que estas pruebas cuidan es lo que ya nos costó caro: que un reporte NO invente una
 * segunda versión de una cifra que ya existe. Por eso la prueba central compara el CSV de
 * retardos contra `calculatePayrollForEmployee` —el motor de la nómina— en vez de contra un
 * número escrito a mano.
 */
class ReportesOperativosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;
    private Employee $expediente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reportes QA', 'subdomain' => 'reportesqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso', 'esAperturador' => false,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@reportesqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Tardón', 'email' => 'tardon@reportesqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $this->expediente = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'name' => 'Tardón', 'job_role_id' => $puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'restDay' => 'Domingo',
            'salary' => 3000, 'hire_date' => now()->subYear()->toDateString(),
        ]);

        DB::table('lft_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id],
            ['late_tolerance_minutes' => 10, 'lates_per_absence' => 3, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function fichaje(string $fecha, string $type, string $hora, array $extra = []): void
    {
        DB::table('time_entries')->insert(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => $fecha, 'type' => $type, 'time' => $hora,
            'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function descargar(string $reporte, array $params = []): string
    {
        $query = $params ? '?' . http_build_query($params) : '';
        $respuesta = $this->actingAs($this->admin)->get("/api/v1/admin/reports/{$reporte}.csv{$query}");
        $respuesta->assertOk();

        return $respuesta->streamedContent();
    }

    /**
     * LA PRUEBA QUE IMPORTA: el reporte de retardos dice lo MISMO que la nómina. Si alguien
     * cambia el motor (o el reporte empieza a contar por su cuenta), esto truena.
     */
    public function test_el_reporte_de_retardos_coincide_con_el_motor_de_nomina(): void
    {
        $hoy = now()->startOfWeek(); // lunes, para no toparse con el descanso dominical
        $lunes = $hoy->copy()->toDateString();
        $martes = $hoy->copy()->addDay()->toDateString();

        // Dos retardos reales, juzgados como los juzga el reloj.
        $this->fichaje($lunes, 'check_in', '09:25:00', ['is_late' => true, 'late_minutes' => 25]);
        $this->fichaje($lunes, 'check_out', '18:00:00');
        $this->fichaje($martes, 'check_in', '09:15:00', ['is_late' => true, 'late_minutes' => 15]);
        $this->fichaje($martes, 'check_out', '18:00:00');

        $desde = $lunes;
        $hasta = $hoy->copy()->addDays(2)->toDateString();

        $csv = $this->descargar('retardos', ['from' => $desde, 'to' => $hasta]);

        // La verdad de referencia: el motor de nómina, llamado igual que lo llama el recibo.
        $nomina = app(ClockService::class)->calculatePayrollForEmployee($this->expediente, $desde, $hasta);
        $inc = $nomina['incidents'];

        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Tardón'));
        $this->assertNotNull($renglon, 'el colaborador tiene que aparecer en el reporte');

        $campos = str_getcsv(trim($renglon));
        $this->assertSame((int) $inc['lates'], (int) $campos[2], 'retardos: el reporte y la nómina deben decir lo mismo');
        $this->assertSame((int) $inc['late_minutes'], (int) $campos[3], 'minutos de retardo');
        $this->assertSame((int) $inc['physical_absences'], (int) $campos[4], 'faltas físicas');
        $this->assertSame((int) $inc['total_absences'], (int) $campos[6], 'faltas totales');

        // Y trae 2 retardos de verdad (no es una prueba que compara dos ceros).
        $this->assertSame(2, (int) $campos[2]);
        $this->assertSame(40, (int) $campos[3]);
    }

    /** El CSV explica sus reglas: un reporte que no las dice obliga a adivinarlas. */
    public function test_el_reporte_de_retardos_explica_de_donde_salen_las_cifras(): void
    {
        $csv = $this->descargar('retardos');

        $this->assertStringContainsString('Cómo leer este reporte', $csv);
        $this->assertStringContainsString('justificante aprobado', $csv);
        $this->assertStringContainsString('3 retardos', $csv, 'debe decir la regla real de la empresa');
    }

    /** Horas: descuenta comida y descansos, y no inventa horas de una jornada sin salida. */
    public function test_las_horas_efectivas_descuentan_comida_y_descansos(): void
    {
        $dia = now()->startOfWeek()->toDateString();
        $this->fichaje($dia, 'check_in', '09:00:00');
        $this->fichaje($dia, 'meal_start', '14:00:00');
        $this->fichaje($dia, 'meal_end', '15:00:00');
        $this->fichaje($dia, 'check_out', '18:00:00');

        $csv = $this->descargar('horas', ['from' => $dia, 'to' => $dia]);
        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Tardón'));
        $campos = str_getcsv(trim($renglon));

        $this->assertSame('9:00', $campos[5], 'horas en sucursal');
        $this->assertSame('1:00', $campos[6], 'comida');
        $this->assertSame('8:00', $campos[8], 'horas efectivas = 9 − 1');
    }

    /** Un turno que cruza medianoche es UNA jornada, no dos ni un negativo. */
    public function test_el_turno_nocturno_cuenta_como_una_sola_jornada(): void
    {
        $this->expediente->update(['shiftStart' => '22:00', 'shiftEnd' => '02:00']);
        $dia = now()->startOfWeek()->toDateString();

        $this->fichaje($dia, 'check_in', '22:00:00');
        $this->fichaje($dia, 'check_out', '02:00:00'); // ya es el día siguiente

        $csv = $this->descargar('horas', ['from' => $dia, 'to' => $dia]);
        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Tardón'));
        $campos = str_getcsv(trim($renglon));

        $this->assertSame('4:00', $campos[5], 'de 22:00 a 02:00 son 4 horas, no -20');
    }

    /** Quien olvidó checar salida se DICE, no se le inventan horas. */
    public function test_una_jornada_sin_salida_no_inventa_horas(): void
    {
        $dia = now()->startOfWeek()->toDateString();
        $this->fichaje($dia, 'check_in', '09:00:00');

        $csv = $this->descargar('horas', ['from' => $dia, 'to' => $dia]);
        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Tardón'));
        $campos = str_getcsv(trim($renglon));

        $this->assertSame('0:00', $campos[8], 'sin salida no hay horas efectivas');
        $this->assertStringContainsString('Sin salida registrada', $campos[10]);
    }

    /** Cumplimiento: cuenta hechas/omitidas/sin cerrar y declara su definición. */
    public function test_cumplimiento_de_rutinas_cuenta_por_persona_y_por_tarea(): void
    {
        $tarea = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->tenant->id, 'title' => 'Limpiar barra', 'estimated_mins' => 20,
            'priority' => 'normal', 'category' => 'operativo', 'target_type' => 'role', 'target_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dia = now()->toDateString();

        foreach ([['a', 'completed'], ['b', 'awaiting_validation'], ['c', 'omitted'], ['d', 'pending']] as [$sufijo, $estado]) {
            DB::table('task_assignments')->insert([
                'id' => "qa_{$sufijo}", 'tenant_id' => $this->tenant->id, 'task_id' => $tarea,
                'user_id' => $this->colaborador->id, 'date' => $dia, 'status' => $estado,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $csv = $this->descargar('rutinas', ['from' => $dia, 'to' => $dia]);

        $renglonPersona = collect(explode("\n", $csv))->first(fn ($l) => str_starts_with($l, 'Colaborador,'));
        $this->assertNotNull($renglonPersona, "el reporte debe traer el renglón del colaborador. CSV:\n{$csv}");
        $campos = str_getcsv(trim($renglonPersona));

        $this->assertSame('Tardón', $campos[1]);
        $this->assertSame(4, (int) $campos[3], 'asignadas');
        $this->assertSame(2, (int) $campos[4], 'hechas = completada + espera firma');
        $this->assertSame(1, (int) $campos[5], 'omitida');
        $this->assertSame(1, (int) $campos[6], 'sin cerrar');
        $this->assertSame('50%', $campos[7]);

        // Y el CSV dice por qué NO trae "minutos reales" (serían ceros engañosos).
        $this->assertStringContainsString('sólo mide el tiempo real cuando la persona pausa', $csv);
    }

    /** Los tres respetan el tope de días y el aislamiento entre empresas. */
    public function test_los_reportes_nuevos_respetan_el_tope_y_el_rol(): void
    {
        foreach (['retardos', 'horas', 'rutinas'] as $reporte) {
            $this->actingAs($this->admin)
                ->getJson("/api/v1/admin/reports/{$reporte}.csv?from=1990-01-01&to=2026-08-13")
                ->assertStatus(422);

            $empleado = User::create([
                'tenant_id' => $this->tenant->id, 'name' => "Emp {$reporte}", 'email' => "emp{$reporte}@reportesqa.test",
                'password' => bcrypt('x'), 'role' => 'empleado',
            ]);
            $this->actingAs($empleado)->getJson("/api/v1/admin/reports/{$reporte}.csv")->assertStatus(403);
        }
    }

    /** El supervisor SÍ puede bajarlos: no traen un dato salarial. */
    public function test_el_supervisor_puede_descargar_los_reportes_operativos(): void
    {
        $supervisor = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sup', 'email' => 'sup@reportesqa.test',
            'password' => bcrypt('x'), 'role' => 'supervisor',
        ]);

        foreach (['retardos', 'horas', 'rutinas'] as $reporte) {
            $this->actingAs($supervisor)->get("/api/v1/admin/reports/{$reporte}.csv")->assertOk();
        }
    }
}
