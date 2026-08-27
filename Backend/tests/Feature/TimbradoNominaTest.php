<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WeeklyPayroll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auditoría N1 (2026-08-06) — el timbrado FINGÍA éxito: si el proveedor fiscal fallaba con un
 * error que contuviera la palabra "key", devolvía success:true con un UUID inventado
 * (`SAT-CFDI-UUID-…`) indistinguible de un timbre real, que además no se guardaba en ninguna
 * tabla. Y el MONTO venía del cliente (net_salary editable desde el navegador).
 *
 * Reglas de esta ronda (el timbrado era el único flujo de dinero con CERO pruebas):
 *  - Sólo se timbra una nómina AUTORIZADA por la empresa (status approved_by_admin).
 *  - El monto sale de la fila autorizada (net_pay), nunca del request.
 *  - Un fallo del proveedor se propaga tal cual (502) y NO sella nada.
 *  - El éxito sella cfdi_uuid / cfdi_receipt_id / timbrada_at en la fila (auditable).
 *  - Re-timbrar una nómina sellada da 409 con su folio (idempotencia).
 *  - N8: getInvoices ya no inventa facturas cuando el proveedor falla.
 */
class TimbradoNominaTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        // (2026-08-26) El timbrado está APAGADO por decisión del dueño (ver
        // `FacturapiBillingProvider::TIMBRADO_DESACTIVADO`). Estas pruebas NO se borran ni se
        // reescriben para esperar el 503: son el mapa de cómo debe comportarse el circuito y
        // hacen falta el día que se rescate. Atadas al interruptor, vuelven solas en cuanto
        // alguien lo apague — que es justo cuando más se necesitan.
        if (\App\Services\Billing\FacturapiBillingProvider::TIMBRADO_DESACTIVADO) {
            $this->markTestSkipped('Timbrado CFDI desactivado por decisión estratégica (2026-08-26).');
        }


        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Empresa', 'subdomain' => 't2',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        return $user->fresh();
    }

    private function empleadoConNomina(string $status = 'approved_by_admin', float $net = 1234.56): int
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => 'Colab Timbre',
            'email' => $user->email, 'base_salary' => 2400, 'rfc' => 'CUHA9001015A1',
            'is_active_employee' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        WeeklyPayroll::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employeeId,
            'start_date' => '2026-07-27', 'end_date' => '2026-08-02',
            'base_salary_paid' => 2400, 'net_pay' => $net, 'status' => $status,
            'employee_approved_at' => $status === 'draft' ? null : now(),
        ]);
        return $employeeId;
    }

    private function timbrar(int $employeeId)
    {
        return $this->actingAs($this->admin())->postJson('/api/v1/billing/payroll/timbrar', [
            'employee_id' => $employeeId,
            'period_start' => '2026-07-27',
            'period_end' => '2026-08-02',
            'net_salary' => 99999.99, // debe IGNORARSE: el monto es de la nómina autorizada
        ]);
    }

    public function test_sin_nomina_autorizada_no_hay_timbre(): void
    {
        Http::fake(); // nada debe llegar al proveedor
        $employeeId = $this->empleadoConNomina('draft');

        $res = $this->timbrar($employeeId);

        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
        Http::assertNothingSent();
    }

    public function test_el_fallo_del_proveedor_ya_no_se_disfraza_de_exito(): void
    {
        // EL CASO EXACTO DEL BUG: el error del proveedor contiene la palabra "key" — antes
        // eso disparaba el "Modo Simulador SAT" con success:true y UUID inventado.
        Http::fake(['api.facturapi.com/*' => Http::response(['message' => 'Invalid Api Key'], 401)]);
        $employeeId = $this->empleadoConNomina();

        $res = $this->timbrar($employeeId);

        $res->assertStatus(502);
        $this->assertFalse($res->json('success'));
        $this->assertStringNotContainsString('SAT-CFDI-UUID', $res->getContent());
        $fila = WeeklyPayroll::where('employee_id', $employeeId)->first();
        $this->assertNull($fila->timbrada_at, 'Un timbre que no ocurrió no se sella.');
    }

    public function test_el_exito_sella_el_folio_y_usa_el_monto_autorizado(): void
    {
        Http::fake(['api.facturapi.com/*' => Http::response([
            'id' => 'rec_real_1', 'uuid' => 'AAA-BBB-CCC-DDD', 'status' => 'valid',
        ], 200)]);
        $employeeId = $this->empleadoConNomina('approved_by_admin', 1234.56);

        $res = $this->timbrar($employeeId);

        $res->assertStatus(200);
        $this->assertSame('AAA-BBB-CCC-DDD', $res->json('receipt.uuid'));

        $fila = WeeklyPayroll::where('employee_id', $employeeId)->first();
        $this->assertSame('AAA-BBB-CCC-DDD', $fila->cfdi_uuid);
        $this->assertSame('rec_real_1', $fila->cfdi_receipt_id);
        $this->assertNotNull($fila->timbrada_at);

        // El monto que viajó al proveedor sale de la nómina (1234.56/7), no del request (99999.99).
        Http::assertSent(function ($request) {
            $rate = $request['payroll']['employee']['salary_rate'] ?? null;
            return $rate === round(1234.56 / 7, 2);
        });
    }

    public function test_retimbrar_una_nomina_sellada_da_409_con_su_folio(): void
    {
        Http::fake(['api.facturapi.com/*' => Http::response([
            'id' => 'rec_real_1', 'uuid' => 'AAA-BBB-CCC-DDD',
        ], 200)]);
        $employeeId = $this->empleadoConNomina();

        $this->timbrar($employeeId)->assertStatus(200);
        $res = $this->timbrar($employeeId);

        $res->assertStatus(409);
        $this->assertSame('AAA-BBB-CCC-DDD', $res->json('receipt.uuid'));
        Http::assertSentCount(1); // el segundo intento NO llegó al proveedor
    }

    public function test_no_se_timbra_la_nomina_de_otro_tenant(): void
    {
        Http::fake();
        DB::table('tenants')->insertOrIgnore([
            'id' => 3, 'name' => 'Otra', 'subdomain' => 't3', 'plan' => 'basic',
            'max_users' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ajenoId = DB::table('employees')->insertGetId([
            'tenant_id' => 3, 'name' => 'Ajeno', 'email' => 'ajeno@x.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->timbrar($ajenoId)->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_getInvoices_ya_no_inventa_facturas(): void
    {
        // N8: sin credenciales el historial decía tener 3 facturas vigentes de personas
        // inventadas ("JUAN PEREZ LOPEZ", RFC PELJ8001011A0).
        Http::fake(['api.facturapi.com/*' => Http::response(['message' => 'Invalid Api Key'], 401)]);

        $res = $this->actingAs($this->admin())->getJson('/api/v1/billing/invoices');

        $res->assertStatus(200);
        $this->assertFalse($res->json('success'));
        $this->assertStringNotContainsString('PELJ8001011A0', $res->getContent());
        $this->assertStringNotContainsString('JUAN PEREZ LOPEZ', $res->getContent());
    }
}
