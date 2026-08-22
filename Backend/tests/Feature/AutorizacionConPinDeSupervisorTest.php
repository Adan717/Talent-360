<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El supervisor PRESENTE autoriza con su PIN de kiosco (2026-08-21, prueba del dueño).
 *
 * El modal de "Acceso Bloqueado" traía un botón "[Escaneo Sim.]" que generaba el "QR dinámico"
 * con la sesión del propio usuario: un admin se autorizaba a sí mismo con un clic, y a un
 * empleado le daba error frente a una caja `qr_...` sin nada que escanear. La variante con PIN
 * sólo revisaba el largo en el navegador. Ahora hay UNA cerradura, en el servidor: el PIN de
 * kiosco de un mando distinto al colaborador, y para la entrada tardía deja la misma fila
 * aprobada que la autorización remota del Monitor — con quién autorizó.
 */
class AutorizacionConPinDeSupervisorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'PIN QA', 'subdomain' => 'pinqa', 'plan' => 'enterprise', 'is_active' => true,
        ]);
        // El servidor bloquea a partir de 10 minutos de retardo (mismo umbral que el dial).
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10, 'max_late_block_minutes' => 10]);
    }

    private function persona(string $rol, string $nombre, ?string $pin = null): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)) . '@pinqa.test',
            'password' => bcrypt('x'), 'role' => $rol,
        ]);
        $emp = new Employee([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
        if ($pin) {
            $emp->setKioskPin($pin);
        }
        $emp->save();

        return $user;
    }

    public function test_el_pin_del_supervisor_deja_la_autorizacion_aprobada_a_su_nombre(): void
    {
        $supervisora = $this->persona('supervisor', 'Maria Sup', '246810');
        $empleado = $this->persona('empleado', 'Miguel Emp');

        $this->actingAs($empleado)
            ->postJson('/api/v1/clock/supervisor-pin/authorize', ['pin' => '246810', 'purpose' => 'late_entry'])
            ->assertOk()
            ->assertJson(['success' => true, 'authorized_by' => 'Maria Sup']);

        $tz = \App\Helpers\TenantTimezone::for($this->tenant->id);
        $this->assertDatabaseHas('late_authorization_requests', [
            'tenant_id' => $this->tenant->id, 'user_id' => $empleado->id,
            'date' => Carbon::now($tz)->toDateString(), 'status' => 'approved', 'resolved_by' => $supervisora->id,
        ]);
    }

    /** La autorización deja pasar el fichaje que el servidor bloqueaba: es la misma fila que la remota. */
    public function test_con_la_autorizacion_el_fichaje_bloqueado_ya_pasa(): void
    {
        $this->persona('supervisor', 'Maria Sup', '246810');
        $empleado = $this->persona('empleado', 'Miguel Emp');

        $tz = \App\Helpers\TenantTimezone::for($this->tenant->id);
        Carbon::setTestNow(Carbon::parse(Carbon::now($tz)->toDateString() . ' 11:00:00', $tz)); // 2 h tarde

        try {
            $bloqueado = false;
            try {
                app(ClockService::class)->processPunch($empleado->fresh(), 'check_in');
            } catch (\Exception $e) {
                $bloqueado = str_contains($e->getMessage(), 'Bloqueado');
            }
            $this->assertTrue($bloqueado, 'sin autorización, 2 h de retardo deben bloquearse en el servidor');

            $this->actingAs($empleado)
                ->postJson('/api/v1/clock/supervisor-pin/authorize', ['pin' => '246810', 'purpose' => 'late_entry'])
                ->assertOk();

            $r = app(ClockService::class)->processPunch($empleado->fresh(), 'check_in');
            $this->assertTrue($r['success']);
            $this->assertDatabaseHas('time_entries', ['user_id' => $empleado->id, 'type' => 'check_in', 'is_late' => true]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_un_pin_equivocado_o_de_un_empleado_no_autoriza(): void
    {
        $this->persona('supervisor', 'Maria Sup', '246810');
        $this->persona('empleado', 'Otro Emp', '111111'); // tiene PIN, pero NO es mando
        $empleado = $this->persona('empleado', 'Miguel Emp');

        $this->actingAs($empleado)
            ->postJson('/api/v1/clock/supervisor-pin/authorize', ['pin' => '999999', 'purpose' => 'late_entry'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'PIN de supervisor no válido.']);

        $this->actingAs($empleado)
            ->postJson('/api/v1/clock/supervisor-pin/authorize', ['pin' => '111111', 'purpose' => 'late_entry'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('late_authorization_requests', ['user_id' => $empleado->id]);
    }

    /** Nadie se autoriza a sí mismo: era exactamente lo que hacía el botón "[Escaneo Sim.]" con un admin. */
    public function test_un_admin_no_puede_autorizarse_con_su_propio_pin(): void
    {
        $admin = $this->persona('admin', 'Adan Admin', '135790');

        $this->actingAs($admin)
            ->postJson('/api/v1/clock/supervisor-pin/authorize', ['pin' => '135790', 'purpose' => 'late_entry'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('late_authorization_requests', ['user_id' => $admin->id]);
    }

    /** Para los otros propósitos (horas extra, salida anticipada, tareas) valida el PIN pero no inventa una autorización de entrada. */
    public function test_otros_propositos_validan_el_pin_sin_registrar_entrada_tardia(): void
    {
        $this->persona('supervisor', 'Maria Sup', '246810');
        $empleado = $this->persona('empleado', 'Miguel Emp');

        $this->actingAs($empleado)
            ->postJson('/api/v1/clock/supervisor-pin/authorize', ['pin' => '246810', 'purpose' => 'pending_tasks'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('late_authorization_requests', ['user_id' => $empleado->id]);
    }
}
