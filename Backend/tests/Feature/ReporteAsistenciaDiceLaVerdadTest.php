<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El reporte de Asistencia dice la verdad (2026-08-22, fase 6).
 *
 * Salió al contrastarlo con el de Retardos: en el mismo día, la misma persona aparecía con su
 * puesto en unos renglones y como "Sin puesto" en otros (el puesto es una FOTO tomada al fichar,
 * y las vías que no la estampan dejaban la columna vacía); una RESERVA de comedor se colaba entre
 * las entradas y salidas como un movimiento llamado `meal_reservation`, en crudo; y la salida que
 * inventó el cierre automático se presentaba como una salida normal de la persona.
 */
class ReporteAsistenciaDiceLaVerdadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Asis QA', 'subdomain' => 'asisqa', 'plan' => 'enterprise', 'is_active' => true]);
        $puesto = JobRole::create(['tenant_id' => $this->tenant->id, 'name' => 'Cajero de Mostrador', 'area' => 'Operaciones']);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@asisqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador', 'email' => 'colab@asisqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'name' => 'Colaborador',
            'job_role_id' => $puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
    }

    private function punch(string $type, string $time, array $extra = []): void
    {
        DB::table('time_entries')->insert(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => $this->hoy(), 'type' => $type, 'time' => $time,
            'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function hoy(): string
    {
        return \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for($this->tenant->id))->toDateString();
    }

    private function csv(): string
    {
        $res = $this->actingAs($this->admin)->get('/api/v1/admin/reports/asistencia.csv?date=' . $this->hoy());
        $res->assertOk();

        return $res->streamedContent();
    }

    public function test_sin_la_foto_del_puesto_usa_el_puesto_actual_y_no_dice_sin_puesto(): void
    {
        // Vía que NO estampa el puesto (así entran las reservas, los cierres automáticos, etc.).
        $this->punch('check_in', '09:00:00');

        $csv = $this->csv();

        $this->assertStringContainsString('Cajero de Mostrador', $csv);
        $this->assertStringNotContainsString('Sin puesto', $csv);
    }

    public function test_una_reserva_de_comedor_no_es_un_movimiento_de_asistencia(): void
    {
        $this->punch('check_in', '09:00:00');
        $this->punch('meal_reservation', '14:00:00');

        $csv = $this->csv();

        $this->assertStringNotContainsString('meal_reservation', $csv);
    }

    public function test_la_salida_que_invento_el_sistema_lo_dice(): void
    {
        $this->punch('check_in', '09:00:00');
        $this->punch('check_out', '18:00:00', [
            'details' => json_encode(['auto_closed' => true, 'reason' => 'orphan_shift']),
        ]);

        $csv = $this->csv();

        $this->assertStringContainsString('cierre automático del sistema', $csv);
    }

    public function test_una_salida_de_verdad_no_se_marca_como_automatica(): void
    {
        $this->punch('check_in', '09:00:00');
        $this->punch('check_out', '18:00:00');

        $csv = $this->csv();

        $this->assertStringNotContainsString('cierre automático del sistema', $csv);
    }
}
