<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SalarioDiario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Periodicidad de nómina configurable (2026-08-03, aprobada por producto).
 *
 * El defecto raíz: `base_salary` no declaraba periodicidad y cada módulo lo interpretaba
 * distinto (nómina semanal, costo de tarea diario, CFDI quincenal). Estas pruebas fijan las
 * cuatro piezas del arreglo: conversión a diario en la captura, configuración por empresa,
 * candado del ciclo semanal, y que los expedientes legados NO cambien ni un peso.
 */
class PeriodicidadNominaTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 13;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Periodicidad QA', 'subdomain' => 'perqa',
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

    // ---------------- conversión (la unidad del dominio) ----------------

    public function test_los_divisores_son_los_de_la_practica_lft(): void
    {
        $this->assertSame(400.0, SalarioDiario::desde(12000, 'mensual'));
        $this->assertSame(400.0, SalarioDiario::desde(6000, 'quincenal'));
        $this->assertSame(200.0, SalarioDiario::desde(1400, 'semanal'));
        $this->assertSame(350.5, SalarioDiario::desde(350.50, 'diario'));
    }

    public function test_semanal_divide_entre_7_no_entre_6(): void
    {
        // El /6 histórico INFLABA el diario (pagaba el descanso dos veces). La conversión
        // declarada usa el divisor de la LFT: la semana pactada ya incluye el séptimo día.
        $this->assertSame(200.0, SalarioDiario::desde(1400, 'semanal'));
        $this->assertNotEquals(round(1400 / 6, 2), SalarioDiario::desde(1400, 'semanal'));
    }

    // ---------------- captura ----------------

    public function test_capturar_con_periodicidad_almacena_el_diario(): void
    {
        $r = $this->actingAs($this->admin())->postJson('/api/v1/employees', [
            'name' => 'Colaborador Mensual', 'email' => 'mensual@perqa.mx',
            'role' => 'empleado', 'base_salary' => 12000, 'salary_periodicity' => 'mensual',
        ]);

        $r->assertStatus(201);

        $e = DB::table('employees')->where('email', 'mensual@perqa.mx')->first();
        $this->assertSame(400.0, (float) $e->salario_diario, 'Mensual 12000 → diario 400.');
        $this->assertSame('mensual', $e->periodicidad_captura,
            'Debe recordarse CÓMO se capturó: la migración de datos viejos depende de ese registro.');
        $this->assertSame(12000.0, (float) $e->base_salary, 'El monto original se conserva.');
    }

    public function test_capturar_sin_periodicidad_deja_el_expediente_como_legado(): void
    {
        // Compatibilidad: el frontend viejo no manda periodicidad; NADA debe cambiar para él.
        $this->actingAs($this->admin())->postJson('/api/v1/employees', [
            'name' => 'Colaborador Legado', 'email' => 'legado@perqa.mx',
            'role' => 'empleado', 'base_salary' => 12000,
        ])->assertStatus(201);

        $e = DB::table('employees')->where('email', 'legado@perqa.mx')->first();
        $this->assertNull($e->salario_diario,
            'Sin periodicidad declarada NO se adivina: el expediente queda legado y la nómina '
            . 'usa la fórmula histórica. Migrar es una decisión, no un efecto colateral.');
    }

    public function test_una_periodicidad_inventada_se_rechaza(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/employees', [
            'name' => 'X', 'email' => 'x@perqa.mx', 'role' => 'empleado',
            'base_salary' => 9000, 'salary_periodicity' => 'catorcenal',
        ])->assertStatus(422);
    }

    // ---------------- configuración por empresa ----------------

    public function test_la_periodicidad_de_la_empresa_arranca_como_suposicion_sin_confirmar(): void
    {
        $r = $this->actingAs($this->admin())->getJson('/api/v1/company/payroll-settings');

        $r->assertStatus(200)
            ->assertJson(['periodicity' => 'semanal', 'periodicity_confirmed' => false]);
        // 'semanal' es el statu quo etiquetado, no un dato: la interfaz debe pedir confirmarlo.
    }

    public function test_declararla_la_confirma_y_persiste(): void
    {
        $this->actingAs($this->admin())->putJson('/api/v1/company/payroll-settings', [
            'periodicity' => 'quincenal',
        ])->assertStatus(200);

        $this->actingAs($this->admin())->getJson('/api/v1/company/payroll-settings')
            ->assertJson(['periodicity' => 'quincenal', 'periodicity_confirmed' => true]);
    }

    // ---------------- el candado del ciclo semanal ----------------

    public function test_el_ciclo_semanal_NO_genera_recibos_a_una_empresa_quincenal(): void
    {
        // EL CASO QUE EL JEFE LLAMÓ "problema de verdad": empresa quincenal recibiendo 4-5
        // recibos semanales al mes. El comando semanal debe omitirla por completo, no
        // calcularle "algo".
        $u = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $u->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $u->id, 'name' => $u->name,
            'email' => $u->email, 'base_salary' => 6000, 'is_active_employee' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenantId, 'key' => 'payroll_periodicity'],
            ['value' => json_encode('quincenal'), 'updated_at' => now(), 'created_at' => now()]
        );

        $this->artisan('payroll:calculate-weekly', ['--tenant_id' => $this->tenantId])
            ->expectsOutputToContain('quincenal')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('weekly_payrolls')->where('tenant_id', $this->tenantId)->count(),
            'A una empresa quincenal el ciclo semanal no debe generarle NINGÚN recibo.');
    }

    public function test_el_ciclo_semanal_sigue_generando_para_las_empresas_semanales(): void
    {
        $u = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $u->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $u->id, 'name' => $u->name,
            'email' => $u->email, 'base_salary' => 1400, 'is_active_employee' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('payroll:calculate-weekly', ['--tenant_id' => $this->tenantId])
            ->assertExitCode(0);

        $this->assertGreaterThan(0,
            DB::table('weekly_payrolls')->where('tenant_id', $this->tenantId)->count(),
            'El candado no debe frenar a las empresas semanales (el statu quo del piloto).');
    }

    // ---------------- el informe de impacto existe y corre ----------------

    public function test_el_informe_de_impacto_corre_y_compara(): void
    {
        $u = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $u->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $u->id, 'name' => 'Comparado',
            'email' => $u->email, 'base_salary' => 1400, 'is_active_employee' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 1400 capturado: hoy 1400/6=233.33 de diario; como semanal declarado sería 200.
        $this->artisan('nomina:informe-impacto', ['--tenant' => $this->tenantId])
            ->expectsOutputToContain('Comparado')
            ->assertExitCode(0);
    }
}
