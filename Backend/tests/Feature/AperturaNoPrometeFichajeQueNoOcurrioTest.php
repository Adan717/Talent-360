<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreOpeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Abrir la tienda no promete un fichaje que no ocurrió (2026-08-22, fase 12).
 *
 * `openStoreAndClockIn` ficha en modo best-effort a propósito: la apertura es el objetivo
 * primario y no debe caerse porque una regla del reloj rechace el ponche. Pero la respuesta decía
 * SIEMPRE "Tienda abierta con éxito y entrada registrada". Visto en vivo: el encargado abrió a las
 * 13:04 con turno de 02:49, el candado de retardo extremo rechazó su entrada, la pantalla dijo que
 * había quedado registrada y el Monitor lo mostró fuera de turno el resto del día.
 */
class AperturaNoPrometeFichajeQueNoOcurrioTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $encargado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Apertura QA', 'subdomain' => 'aperturaqa', 'plan' => 'enterprise', 'is_active' => true]);
        // Candado de retardo extremo a 10 minutos.
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10, 'max_late_block_minutes' => 10]);

        $this->encargado = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Encargado', 'email' => 'enc@aperturaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->encargado->id, 'name' => 'Encargado',
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => $this->tenant->id, 'company_id' => 1, 'store_id' => 1,
            'employee_id' => $emp->id, 'priority_order' => 1, 'can_open_store' => true,
            'has_keys' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_si_el_reloj_rechaza_el_fichaje_la_respuesta_lo_dice(): void
    {
        $tz = \App\Helpers\TenantTimezone::for($this->tenant->id);
        // Cuatro horas tarde: muy por encima del candado de retardo extremo.
        Carbon::setTestNow(Carbon::parse(Carbon::now($tz)->toDateString() . ' 13:00:00', $tz));

        $r = app(StoreOpeningService::class)->openStoreAndClockIn($this->encargado->id);

        $this->assertTrue($r['success'], 'la tienda sí se abre: ese es el objetivo primario');
        $this->assertFalse($r['clock_in_registered'], 'pero el fichaje no ocurrió');
        $this->assertStringContainsString('NO quedó registrada', $r['message']);
        $this->assertDatabaseMissing('time_entries', [
            'user_id' => $this->encargado->id, 'type' => 'check_in',
        ]);
    }

    public function test_a_tiempo_abre_y_ficha_y_lo_dice(): void
    {
        $tz = \App\Helpers\TenantTimezone::for($this->tenant->id);
        Carbon::setTestNow(Carbon::parse(Carbon::now($tz)->toDateString() . ' 09:05:00', $tz));

        $r = app(StoreOpeningService::class)->openStoreAndClockIn($this->encargado->id);

        $this->assertTrue($r['success']);
        $this->assertTrue($r['clock_in_registered']);
        $this->assertStringContainsString('entrada registrada', $r['message']);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $this->encargado->id, 'type' => 'check_in',
        ]);
    }
}
