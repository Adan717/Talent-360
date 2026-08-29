<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CorreccionDeAsistencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Registro Electrónico de Jornada — Art. 132 fr. XXXIV LFT (2026-08-28).
 *
 * Lo que la ley pide conservar es la hora de entrada y de salida de cada trabajador. Lo que vuelve
 * a ese registro defendible ante una inspección —y lo que este reporte tiene que demostrar— es que
 * las horas NO se sobrescriben: una corrección retira el movimiento anterior y registra el nuevo,
 * y el reporte enseña las dos cosas con el motivo y la firma de quien la hizo.
 */
class RegistroDeJornadaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Jornada QA', 'subdomain' => 'jornadaqa', 'plan' => 'enterprise', 'is_active' => true]);
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'key' => 'timezone'],
            ['value' => json_encode('UTC'), 'created_at' => now(), 'updated_at' => now()]
        );

        $puesto = JobRole::create(['tenant_id' => $this->tenant->id, 'name' => 'Cajera', 'area' => 'Piso de venta']);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gerente Que Corrige', 'email' => 'gerente@jornadaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Rosa Martinez', 'email' => 'rosa@jornadaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'name' => 'Rosa Martinez',
            'job_role_id' => $puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'salario_diario' => 400, 'restDay' => 'Domingo', 'hire_date' => '2026-01-01',
        ]);
    }

    private function hoy(): string
    {
        return now()->toDateString();
    }

    private function marca(string $tipo, string $hora, array $extra = []): TimeEntry
    {
        return TimeEntry::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => $this->hoy(), 'type' => $tipo, 'time' => $hora,
            'employee_name_at_time' => 'Rosa Martinez', 'job_role_title_at_time' => 'Cajera',
        ], $extra));
    }

    private function reporte(): string
    {
        return $this->actingAs($this->admin)
            ->get('/api/v1/admin/reports/registro_jornada.csv?from=' . $this->hoy() . '&to=' . $this->hoy())
            ->streamedContent();
    }

    /** LO BÁSICO QUE PIDE LA LEY: la entrada y la salida de cada quien, por día. */
    public function test_entrega_la_entrada_y_la_salida_de_cada_persona(): void
    {
        $this->marca('check_in', '09:02:00');
        $this->marca('check_out', '18:05:00');

        $csv = $this->reporte();

        $this->assertStringContainsString('Rosa Martinez', $csv);
        $this->assertStringContainsString('Cajera', $csv);
        $this->assertStringContainsString('09:02:00', $csv);
        $this->assertStringContainsString('18:05:00', $csv);
        $this->assertStringContainsString('Original', $csv);
        $this->assertStringContainsString('132', $csv, 'el reporte se nombra a sí mismo con el artículo que cumple');
    }

    /** LO QUE LO VUELVE DEFENDIBLE: una hora corregida enseña la vieja, la nueva y el porqué. */
    public function test_una_correccion_queda_a_la_vista_con_su_motivo_y_su_firma(): void
    {
        $entrada = $this->marca('check_in', '09:45:00');
        $this->marca('check_out', '18:00:00');

        app(CorreccionDeAsistencia::class)->corregir(
            $entrada,
            ['time' => '09:00:00'],
            'La camara de la sucursal la muestra entrando a las 09:00; el reloj iba adelantado.',
            $this->admin
        );

        $csv = $this->reporte();

        $this->assertStringContainsString('Corregido', $csv);
        $this->assertStringContainsString('09:00:00', $csv, 'la hora que quedó');
        $this->assertStringContainsString('09:45', $csv, 'y la que se retiró: el registro no se sobrescribe');
        $this->assertStringContainsString('camara de la sucursal', $csv, 'el motivo escrito viaja al reporte');
        $this->assertStringContainsString('Gerente Que Corrige', $csv, 'y quién la autorizó');
    }

    /** Un movimiento retirado SIN sustituto también se ve: el hueco queda declarado. */
    public function test_un_movimiento_retirado_se_cuenta_y_se_explica(): void
    {
        $this->marca('check_in', '09:00:00');
        $duplicado = $this->marca('check_out', '17:00:00');
        $this->marca('check_out', '18:00:00');

        app(CorreccionDeAsistencia::class)->corregir(
            $duplicado,
            [],
            'Salida duplicada por doble toque en la tablet.',
            $this->admin
        );

        $csv = $this->reporte();

        $this->assertStringContainsString('Salida duplicada', $csv);
        $this->assertStringContainsString('se retir', $csv, 'dice explícitamente que se retiró un movimiento');
    }

    /** La salida que inventó el cierre automático NO se presenta como hora observada. */
    public function test_la_salida_automatica_se_declara_como_tal(): void
    {
        $this->marca('check_in', '09:00:00');
        $this->marca('check_out', '18:00:00', ['details' => json_encode(['auto_closed' => true, 'reason' => 'orphan_shift'])]);

        $this->assertStringContainsString('Salida puesta por el sistema', $this->reporte());
    }

    /** El día sin salida se declara incompleto en vez de fingir una jornada cerrada. */
    public function test_el_dia_sin_salida_se_declara_incompleto(): void
    {
        $this->marca('check_in', '09:00:00');

        $this->assertStringContainsString('Incompleto', $this->reporte());
    }

    /** Los fichajes del Simulador Matrix nunca se cuelan a un documento legal. */
    public function test_el_simulador_no_entra_en_el_registro_legal(): void
    {
        $this->marca('check_in', '09:00:00');

        $sesion = DB::table('simulator_sessions')->insertGetId([
            'tenant_id' => $this->tenant->id, 'status' => 'active',
            'simulated_date' => $this->hoy(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marca('check_out', '23:59:00', ['simulation_session_id' => $sesion]);

        $csv = $this->reporte();

        $this->assertStringNotContainsString('23:59', $csv, 'un fichaje simulado no es un hecho registrable');
    }

    /** Y no se cuela la jornada de otra empresa. */
    public function test_no_filtra_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Ajena', 'subdomain' => 'ajenajornada', 'plan' => 'pro', 'is_active' => true]);
        $ajeno = User::create([
            'tenant_id' => $otra->id, 'name' => 'PERSONA AJENA', 'email' => 'ajena@ajenajornada.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $otra->id, 'user_id' => $ajeno->id, 'name' => 'PERSONA AJENA',
            'is_active_employee' => true, 'hire_date' => '2026-01-01',
        ]);
        TimeEntry::create([
            'tenant_id' => $otra->id, 'user_id' => $ajeno->id, 'date' => $this->hoy(),
            'type' => 'check_in', 'time' => '08:00:00', 'employee_name_at_time' => 'PERSONA AJENA',
        ]);

        $this->assertStringNotContainsString('PERSONA AJENA', $this->reporte());
    }
}
