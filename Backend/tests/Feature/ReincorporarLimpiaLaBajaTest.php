<?php

namespace Tests\Feature;

use App\Helpers\TenantTimezone;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reincorporar a alguien le quita la fecha de baja (2026-09-05).
 *
 * `destroy()` estampa `termination_date` al dar de baja, pero volver a activar —el botón
 * "Re-activar Colaborador" de la pestaña de inactivos, que manda un PUT con
 * `is_active_employee: true`— NO la quitaba. La persona volvía a la plantilla arrastrando una
 * fecha de baja vieja; sólo el reingreso por el ATS la limpiaba, que es el camino que casi nadie
 * usa.
 *
 * Con el reporte de rotación eso ya mentía. Con la purga de retención es una bomba: se selecciona
 * POR FECHA DE BAJA, así que a los cinco años se habría borrado el expediente completo de alguien
 * que está EN NÓMINA — sus fichajes, sus recibos, su expediente digital.
 */
class ReincorporarLimpiaLaBajaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reingreso QA', 'subdomain' => 'reingresoqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@reingresoqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id,
            'name' => 'Jefa', 'is_active_employee' => true,
        ]);
    }

    private function colaboradorDadoDeBaja(): Employee
    {
        $tz = TenantTimezone::for($this->tenant->id);

        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Regresado', 'email' => 'regresado@reingresoqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true,
        ]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => 'Regresado',
            'is_active_employee' => false,
            'termination_date' => Carbon::now($tz)->subMonths(8)->toDateString(),
            'termination_reason' => 'Renuncia voluntaria',
        ]);
    }

    public function test_reactivar_le_quita_la_fecha_y_el_motivo_de_baja(): void
    {
        $emp = $this->colaboradorDadoDeBaja();

        // Es literalmente lo que manda el botón "Re-activar Colaborador" de RRHH.
        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$emp->id}", ['is_active_employee' => true])
            ->assertOk();

        $emp->refresh();
        $this->assertTrue((bool) $emp->is_active_employee);
        $this->assertNull($emp->termination_date, 'quien está en plantilla no puede llevar fecha de baja encima');
        $this->assertNull($emp->termination_reason);
    }

    public function test_guardar_la_ficha_de_alguien_activo_le_limpia_la_baja_pegada_de_antes(): void
    {
        // La pantalla manda el expediente ENTERO en cada guardado, con `is_active_employee: true`.
        // Quien ya volvió pero arrastra la fecha vieja (por el defecto de antes) queda limpio al
        // primer guardado, sin que nadie tenga que salir a buscarlos.
        $emp = $this->colaboradorDadoDeBaja();
        $emp->update(['is_active_employee' => true]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$emp->id}", [
                'name' => 'Regresado',
                'phone' => '5511223344',
                'is_active_employee' => true,
            ])
            ->assertOk();

        $this->assertNull($emp->refresh()->termination_date);
    }

    public function test_dar_de_baja_sigue_estampando_la_fecha(): void
    {
        // El arreglo no puede volverse contra el caso normal: la baja tiene que seguir dejando
        // fecha, o la rotación y la purga se quedan sin el dato del que dependen.
        $tz = TenantTimezone::for($this->tenant->id);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Saliente', 'email' => 'saliente@reingresoqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true,
        ]);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => 'Saliente',
            'is_active_employee' => true,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$emp->id}", ['motivo' => 'Fin de contrato'])
            ->assertOk();

        $emp->refresh();
        $this->assertFalse((bool) $emp->is_active_employee);
        $this->assertSame(Carbon::now($tz)->toDateString(), Carbon::parse($emp->termination_date)->toDateString());
        $this->assertSame('Fin de contrato', $emp->termination_reason);
    }

    public function test_la_baja_de_noche_se_fecha_en_el_dia_local_no_en_utc(): void
    {
        // La app corre en UTC (`app.timezone`) y las tres vías de baja usaban `now()->toDateString()`:
        // a las 23:00 de México ya son las 05:00 del día siguiente en UTC, y la baja quedaba
        // fechada MAÑANA. De esa fecha dependen la rotación y la purga de retención.
        $tz = TenantTimezone::for($this->tenant->id);
        Carbon::setTestNow(Carbon::create(2026, 9, 7, 23, 0, 0, $tz));
        $this->assertSame('2026-09-08', now()->toDateString(), 'el reloj congelado debe cruzar la medianoche UTC para que la prueba discrimine');

        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Nocturno', 'email' => 'nocturno@reingresoqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => true,
        ]);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => 'Nocturno',
            'is_active_employee' => true,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$emp->id}", ['motivo' => 'Renuncia'])
            ->assertOk();

        $this->assertSame('2026-09-07', Carbon::parse($emp->refresh()->termination_date)->toDateString());
    }
}
