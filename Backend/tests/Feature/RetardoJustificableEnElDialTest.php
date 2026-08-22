<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El dial sabe qué retardo puede justificar la persona (2026-08-22, fase 6 del guion).
 *
 * El circuito del justificante estaba construido de punta a punta —endpoint, panel de aprobación
 * del jefe y exención en la nómina— pero NADIE podía pedir uno: la única puerta era un modal que
 * aparecía en el instante siguiente al fichaje tarde y que además exigía un rol inexistente
 * ('Encargado Titular', 'Supervisor' con mayúscula, cuando los roles son admin/supervisor/
 * empleado). Ahora /sync/state dice si hay un retardo justificable y en qué estado va, para que
 * el dial pueda ofrecer el trámite también DESPUÉS (que es cuando se trae el comprobante).
 */
class RetardoJustificableEnElDialTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Justif QA', 'subdomain' => 'justifqa', 'plan' => 'enterprise', 'is_active' => true]);
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador',
            'email' => 'colab@justifqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'name' => 'Colaborador',
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
    }

    private function hoy(): string
    {
        return Carbon::now(\App\Helpers\TenantTimezone::for($this->tenant->id))->format('Y-m-d');
    }

    private function fichajeTarde(int $minutos = 97, ?string $fecha = null): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'date' => $fecha ?? $this->hoy(), 'type' => 'check_in', 'time' => '10:37:00',
            'is_late' => true, 'late_minutes' => $minutos,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_sin_retardo_no_se_ofrece_el_tramite(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/sync/state')
            ->assertOk()
            ->assertJsonPath('mi_retardo_justificable', null);
    }

    public function test_con_retardo_el_dial_sabe_cuantos_minutos_y_que_no_hay_justificante(): void
    {
        $this->fichajeTarde(97);

        $this->actingAs($this->user)->getJson('/api/v1/sync/state')
            ->assertOk()
            ->assertJsonPath('mi_retardo_justificable.minutes', 97)
            ->assertJsonPath('mi_retardo_justificable.date', $this->hoy())
            ->assertJsonPath('mi_retardo_justificable.justificante', null);
    }

    /** El trámite sigue disponible al día siguiente: es cuando se trae el comprobante del médico. */
    public function test_el_retardo_de_ayer_todavia_se_puede_justificar(): void
    {
        $ayer = Carbon::parse($this->hoy())->subDay()->format('Y-m-d');
        $this->fichajeTarde(45, $ayer);

        $this->actingAs($this->user)->getJson('/api/v1/sync/state')
            ->assertOk()
            ->assertJsonPath('mi_retardo_justificable.date', $ayer)
            ->assertJsonPath('mi_retardo_justificable.minutes', 45);
    }

    public function test_una_vez_pedido_el_dial_ve_su_estado(): void
    {
        $this->fichajeTarde(97);

        $this->actingAs($this->user)->postJson('/api/v1/clock/request-late-justification', [
            'reason' => 'Se me ponchó una llanta camino a la sucursal y traigo el recibo del taller.',
        ])->assertStatus(201);

        $this->actingAs($this->user)->getJson('/api/v1/sync/state')
            ->assertOk()
            ->assertJsonPath('mi_retardo_justificable.justificante', 'pending');
    }

    /** Es de cada quien: nadie ve el retardo de otro por esta vía. */
    public function test_el_retardo_de_otro_no_se_asoma(): void
    {
        $this->fichajeTarde(97);

        $otro = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Otro',
            'email' => 'otro@justifqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $otro->id, 'name' => 'Otro',
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        $this->actingAs($otro)->getJson('/api/v1/sync/state')
            ->assertOk()
            ->assertJsonPath('mi_retardo_justificable', null);
    }
}
