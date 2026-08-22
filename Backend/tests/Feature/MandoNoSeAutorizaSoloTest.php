<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un mando NO se autoriza a sí mismo por el mero hecho de serlo (2026-08-22).
 *
 * `POST /clock/punch` ponía `supervisor_override = true` en TODO ponche de un admin/supervisor,
 * incluido el suyo propio: el candado de retardo extremo del servidor no existía para ellos. Así
 * pasó un check_in con 863 minutos de retardo sin que nadie lo autorizara. El override sigue
 * existiendo para cuando un mando ficha a OTRA persona (kiosco, corrección); para sí mismo, las
 * mismas reglas que todos: autorización aprobada o PIN de otro mando.
 */
class MandoNoSeAutorizaSoloTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Mando QA', 'subdomain' => 'mandoqa', 'plan' => 'enterprise', 'is_active' => true]);
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10, 'max_late_block_minutes' => 10]);
    }

    private function persona(string $rol, string $nombre): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)) . '@mandoqa.test',
            'password' => bcrypt('x'), 'role' => $rol,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        return $user;
    }

    public function test_una_supervisora_dos_horas_tarde_queda_bloqueada_como_cualquiera(): void
    {
        $supervisora = $this->persona('supervisor', 'Maria Sup');
        $tz = \App\Helpers\TenantTimezone::for($this->tenant->id);
        Carbon::setTestNow(Carbon::parse(Carbon::now($tz)->toDateString() . ' 11:00:00', $tz));

        try {
            $r = $this->actingAs($supervisora)
                ->postJson('/api/v1/clock/punch', ['user_id' => $supervisora->id, 'type' => 'check_in']);

            $this->assertNotEquals(200, $r->status(), 'el servidor aceptó el fichaje de una supervisora 2 h tarde sin autorización');
            $this->assertStringContainsString('Bloqueado', (string) $r->json('message'));
            $this->assertDatabaseMissing('time_entries', ['user_id' => $supervisora->id, 'type' => 'check_in']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** El override sigue sirviendo para lo que era: un mando fichando a OTRA persona. */
    public function test_el_override_sigue_valiendo_cuando_el_mando_ficha_a_otro(): void
    {
        $supervisora = $this->persona('supervisor', 'Maria Sup');
        $empleado = $this->persona('empleado', 'Miguel Emp');
        $tz = \App\Helpers\TenantTimezone::for($this->tenant->id);
        Carbon::setTestNow(Carbon::parse(Carbon::now($tz)->toDateString() . ' 11:00:00', $tz));

        try {
            $this->actingAs($supervisora)
                ->postJson('/api/v1/clock/punch', ['user_id' => $empleado->id, 'type' => 'check_in'])
                ->assertOk();

            $this->assertDatabaseHas('time_entries', ['user_id' => $empleado->id, 'type' => 'check_in', 'is_late' => true]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
