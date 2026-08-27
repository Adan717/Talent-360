<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\FacturapiBillingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El timbrado de nómina está APAGADO, y no se enciende con una llave (2026-08-26).
 *
 * El circuito está construido a medias a propósito: el sistema no calcula ISR, ni IMSS, ni
 * subsidio al empleo, y el payload viaja con RFC genérico, CURP de relleno, banco y clase de
 * riesgo fijos. Con una `FACTURAPI_KEY` real eso NO falla — **timbra**, y lo que sale es un
 * documento fiscal presentado ante el SAT a nombre del cliente con datos falsos.
 *
 * Lo que estas pruebas fijan es que el interruptor **no dependa del entorno**: el riesgo concreto
 * era que alguien pusiera una llave y el timbrado se encendiera solo.
 */
class TimbradoDesactivadoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Ninguna petición debe salir hacia el PAC: si alguna lo intenta, esto lo delata.
        Http::preventStrayRequests();

        $this->tenant = Tenant::create(['name' => 'Timbrado QA', 'subdomain' => 'timbradoqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@timbradoqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    public function test_el_interruptor_esta_puesto(): void
    {
        $this->assertTrue(
            FacturapiBillingProvider::TIMBRADO_DESACTIVADO,
            'si esto se apaga, antes hay que cerrar el cálculo de retenciones y los datos inventados'
        );
    }

    /** El cortafuegos vive en el proveedor: cualquier vía futura choca con él. */
    public function test_el_proveedor_se_niega_a_timbrar(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('desactivado por decisión estratégica');

        app(FacturapiBillingProvider::class)->createPayrollReceipt(['lo que sea' => true]);
    }

    /**
     * LA PRUEBA QUE IMPORTA: poner una llave del PAC no lo enciende. Ése era el riesgo real —
     * alguien configura `FACTURAPI_KEY` sin saber lo que está encendiendo.
     */
    public function test_poner_una_llave_real_no_lo_enciende(): void
    {
        config(['services.facturapi.key' => 'sk_live_una_llave_que_parece_de_verdad']);

        $this->expectException(\RuntimeException::class);

        app(FacturapiBillingProvider::class)->createPayrollReceipt([]);
    }

    /** El endpoint responde 503 EXPLICADO, no un 500: un apagado deliberado no es una avería. */
    public function test_el_endpoint_responde_explicando_en_vez_de_reventar(): void
    {
        $r = $this->actingAs($this->admin)->postJson('/api/v1/billing/payroll/timbrar', [
            'employee_id' => 1,
            'period_start' => '2026-08-10',
            'period_end' => '2026-08-16',
        ]);

        $r->assertStatus(503)
            ->assertJsonPath('desactivado', true);
        $this->assertStringContainsString('desactivado por decisión estratégica', $r->json('error'));
        $this->assertStringContainsString('pre-nómina', $r->json('error'));
    }

    /** Y no deja trabajo a medias: se corta antes de tocar nada. */
    public function test_no_marca_nada_como_timbrado(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/billing/payroll/timbrar', [
            'employee_id' => 1,
            'period_start' => '2026-08-10',
            'period_end' => '2026-08-16',
        ])->assertStatus(503);

        $this->assertSame(0, DB::table('weekly_payrolls')->whereNotNull('cfdi_uuid')->count());
    }

    /** La pantalla se entera por el servidor, para no ofrecer un botón que responde 503. */
    public function test_la_pantalla_puede_saber_que_esta_apagado(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/billing/estado-timbrado')
            ->assertOk()
            ->assertJsonPath('timbrado_desactivado', true)
            ->assertJsonPath('timbrado_automatico', false);
    }

    /** Lo demás de nómina sigue funcionando: apagar el timbre no apaga el sueldo. */
    public function test_el_resto_de_la_nomina_sigue_viva(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador',
            'email' => 'colab@timbradoqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => 'Colaborador',
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'salario_diario' => 400, 'restDay' => 'Domingo', 'hire_date' => '2026-01-01',
        ]);
        \App\Models\LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);

        $nomina = app(\App\Services\ClockService::class)
            ->calculatePayrollForEmployee($emp, '2026-08-10', '2026-08-16');

        $this->assertSame(400.0 * 7, round((float) $nomina['salary']['gross'], 2));
    }
}
