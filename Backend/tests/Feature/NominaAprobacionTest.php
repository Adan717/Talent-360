<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H22 y H23 — el ciclo de aprobación de nómina, que es donde se autoriza el dinero.
 *
 * H22: `approvePayrollWeekly` hacía un `updateOrCreate` que RECALCULABA y sobrescribía todos los
 * campos —importe incluido— y forzaba `status = 'approved_by_employee'`, sin mirar en qué estado
 * estaba la fila. Consecuencias medidas en vivo:
 *   - firmar dos veces PISABA la fecha de la firma original (la constancia de conformidad del
 *     trabajador pasaba a decir la fecha de la última pulsación);
 *   - una nómina ya aprobada por el administrador podía revertirse a "firmada por el empleado"
 *     con un importe recalculado, sin que nadie se enterara.
 *
 * H23: `approvePayroll` (la aprobación del ADMINISTRADOR) era un stub que devolvía
 * `"Nómina aprobada y lista para timbrar."` **sin tocar la base de datos**. La interfaz confirmaba
 * una autorización de pago que no existía: sin estado, sin quién, sin cuándo.
 */
class NominaAprobacionTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Empresa', 'subdomain' => 't2',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function colaborador(string $rol = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'base_salary' => 9000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user->fresh();
    }

    private function empleadoId(User $user): int
    {
        return (int) DB::table('employees')->where('user_id', $user->id)->value('id');
    }

    private function filaDe(User $user): ?object
    {
        return DB::table('weekly_payrolls')
            ->where('tenant_id', $this->tenantId)
            ->where('employee_id', $this->empleadoId($user))
            ->first();
    }

    // ---------------- H22: la firma del trabajador ----------------

    public function test_el_trabajador_firma_su_nomina(): void
    {
        $ana = $this->colaborador();

        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])
            ->assertStatus(200);

        $fila = $this->filaDe($ana);
        $this->assertSame('approved_by_employee', $fila->status);
        $this->assertNotNull($fila->employee_approved_at);
    }

    public function test_firmar_dos_veces_NO_pisa_la_fecha_de_la_primera_firma(): void
    {
        $ana = $this->colaborador();

        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);
        $primeraFirma = $this->filaDe($ana)->employee_approved_at;

        // Un reintento del cliente, o el trabajador volviendo a entrar a su recibo.
        $this->travel(2)->minutes();
        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);

        $this->assertSame($primeraFirma, $this->filaDe($ana)->employee_approved_at,
            'La constancia de conformidad debe conservar la fecha de la PRIMERA firma.');
    }

    public function test_el_trabajador_no_puede_revertir_una_nomina_ya_aprobada_por_el_admin(): void
    {
        $ana = $this->colaborador();
        $jefa = $this->colaborador('admin');

        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);
        $this->actingAs($jefa)->postJson('/api/v1/admin/payroll/approve', [
            'employee_id' => $this->empleadoId($ana),
        ])->assertStatus(200);

        $this->assertSame('approved_by_admin', $this->filaDe($ana)->status);

        // EL CASO DEL BUG: esto devolvía 200, recalculaba el importe y devolvía el estado atrás.
        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])
            ->assertStatus(409);

        $this->assertSame('approved_by_admin', $this->filaDe($ana)->status,
            'Una nómina ya autorizada por el administrador no puede volver atrás.');
    }

    public function test_el_importe_firmado_no_cambia_al_reintentar(): void
    {
        $ana = $this->colaborador();

        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);
        $importeFirmado = $this->filaDe($ana)->net_pay;

        // Aparece una incidencia DESPUÉS de firmar: el importe firmado no debe moverse solo.
        DB::table('weekly_payrolls')
            ->where('employee_id', $this->empleadoId($ana))
            ->update(['absences_count' => 6]);

        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);

        $this->assertSame($importeFirmado, $this->filaDe($ana)->net_pay,
            'Lo que el trabajador aceptó de conformidad no se recalcula por volver a pulsar.');
    }

    // ---------------- H23: la aprobación del administrador ----------------

    public function test_la_aprobacion_del_admin_queda_REGISTRADA(): void
    {
        $ana = $this->colaborador();
        $jefa = $this->colaborador('admin');

        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);

        // EL CASO DEL BUG: esto devolvía "aprobada y lista para timbrar" sin tocar la base.
        $this->actingAs($jefa)->postJson('/api/v1/admin/payroll/approve', [
            'employee_id' => $this->empleadoId($ana),
        ])->assertStatus(200);

        $fila = $this->filaDe($ana);
        $this->assertSame('approved_by_admin', $fila->status);
        $this->assertNotNull($fila->admin_approved_at, 'Debe constar CUÁNDO se autorizó el pago.');
        $this->assertSame($jefa->id, (int) $fila->admin_approved_by, 'Y QUIÉN lo autorizó.');
    }

    public function test_un_empleado_no_puede_aprobar_nominas(): void
    {
        $ana = $this->colaborador();
        $beto = $this->colaborador();

        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);

        $this->actingAs($beto)->postJson('/api/v1/admin/payroll/approve', [
            'employee_id' => $this->empleadoId($ana),
        ])->assertStatus(403);
    }

    public function test_nadie_autoriza_el_pago_de_su_propia_nomina(): void
    {
        $jefa = $this->colaborador('admin');

        $this->actingAs($jefa)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);

        $this->actingAs($jefa)->postJson('/api/v1/admin/payroll/approve', [
            'employee_id' => $this->empleadoId($jefa),
        ])->assertStatus(403);
    }

    public function test_no_se_aprueba_una_nomina_que_el_trabajador_no_ha_firmado(): void
    {
        $ana = $this->colaborador();
        $jefa = $this->colaborador('admin');

        // Sin firma previa no hay nada que autorizar: el trabajador no ha visto el cálculo.
        $this->actingAs($jefa)->postJson('/api/v1/admin/payroll/approve', [
            'employee_id' => $this->empleadoId($ana),
        ])->assertStatus(422);
    }

    // ---------------- El botón real del panel: aprobar el periodo completo ----------------

    public function test_sin_employee_id_se_autoriza_el_periodo_completo(): void
    {
        // El botón "Aprobar y Timbrar (CFDI)" de ReportesManager llama SIN employee_id. Exigirlo
        // habría roto ese botón, que es el camino que de verdad usa la empresa.
        $ana = $this->colaborador();
        $beto = $this->colaborador();
        $jefa = $this->colaborador('admin');

        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);
        $this->actingAs($beto)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);

        $res = $this->actingAs($jefa)->postJson('/api/v1/admin/payroll/approve', []);

        $res->assertStatus(200);
        $this->assertSame(2, $res->json('approved'));
        $this->assertSame('approved_by_admin', $this->filaDe($ana)->status);
        $this->assertSame('approved_by_admin', $this->filaDe($beto)->status);
    }

    public function test_el_modo_masivo_salta_las_que_nadie_ha_firmado_y_lo_dice(): void
    {
        $firmante = $this->colaborador();
        $silencioso = $this->colaborador();
        $jefa = $this->colaborador('admin');

        $this->actingAs($firmante)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);
        // `silencioso` no firma; se le crea la fila por el cálculo, pero sin conformidad.
        DB::table('weekly_payrolls')->insert([
            'tenant_id' => $this->tenantId, 'employee_id' => $this->empleadoId($silencioso),
            'start_date' => $this->filaDe($firmante)->start_date,
            'end_date' => $this->filaDe($firmante)->end_date,
            'base_salary_paid' => 9000, 'net_pay' => 1000, 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($jefa)->postJson('/api/v1/admin/payroll/approve', []);

        $this->assertSame(1, $res->json('approved'));
        $this->assertSame(1, $res->json('pending_employee_signature'),
            'Debe avisar de cuántas quedaron fuera, no dar un "listo" que lo tape.');
        $this->assertSame('draft', $this->filaDe($silencioso)->status);
    }

    public function test_el_modo_masivo_tampoco_autoriza_la_del_propio_actor(): void
    {
        $jefa = $this->colaborador('admin');
        $ana = $this->colaborador();

        $this->actingAs($jefa)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);
        $this->actingAs($ana)->postJson('/api/v1/employee/payroll-weekly/approve', [])->assertStatus(200);

        $res = $this->actingAs($jefa)->postJson('/api/v1/admin/payroll/approve', []);

        $this->assertSame(1, $res->json('approved'), 'Sólo la de Ana.');
        $this->assertSame('approved_by_employee', $this->filaDe($jefa)->status,
            'La suya sigue esperando a otro administrador.');
    }

    public function test_no_se_aprueba_la_nomina_de_otra_empresa(): void
    {
        $jefa = $this->colaborador('admin');

        DB::table('tenants')->insertOrIgnore([
            'id' => 3, 'name' => 'Otra', 'subdomain' => 't3', 'plan' => 'basic',
            'max_users' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ajenoId = DB::table('employees')->insertGetId([
            'tenant_id' => 3, 'name' => 'Ajeno', 'email' => 'ajeno@x.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($jefa)->postJson('/api/v1/admin/payroll/approve', [
            'employee_id' => $ajenoId,
        ])->assertStatus(404);
    }
}
