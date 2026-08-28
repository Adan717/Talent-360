<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R94 (Fase 5 / T5.1 · N6): Bono de Cumplimiento server-side.
 *
 * Antes era 100% cosmético (badges client-side en DashboardTalent360, un string en el saludo). Ahora
 * `calculatePayrollForEmployee` computa un bloque `bonus` con DOS componentes independientes, cada uno
 * opt-in por su monto (0 = apagado): puntualidad (bono fijo del periodo sin retardos/faltas) y apertura
 * (por cada apertura de tienda a tiempo). Suma al neto. Con ambos en 0 (default) el neto no cambia.
 */
class ComplianceBonusTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-06-01'; // lunes

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:Tenant,1:User,2:Employee} */
    private function make(array $lftBonus = [], string $tz = 'UTC', bool $createLft = true): array
    {
        $tenant = Tenant::create(['name' => 'B', 'subdomain' => 'b' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode($tz), 'created_at' => now(), 'updated_at' => now()]
            );
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'b' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000.00, 'shiftStart' => '09:00:00', 'shiftEnd' => '18:00:00',
            'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        if ($createLft) {
            LftSetting::create(array_merge([
                'tenant_id' => $tenant->id, 'lates_per_absence' => 3, 'late_tolerance_minutes' => 10,
                'late_action_mode' => 'deduct', 'late_penalty_per_minute' => 2.00,
            ], $lftBonus));
        }
        return [$tenant, $user, $employee];
    }

    private function checkIn(User $user, Tenant $tenant, bool $late): void
    {
        TimeEntry::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'date' => self::DAY,
            'type' => 'check_in', 'time' => $late ? '09:30:00' : '08:55:00',
            'is_late' => $late, 'late_minutes' => $late ? 30 : 0,
        ]);
    }

    private function payroll(Employee $employee): array
    {
        return app(ClockService::class)->calculatePayrollForEmployee($employee, self::DAY, self::DAY);
    }

    public function test_default_off_sin_bono(): void
    {
        // Ambos montos en 0 (default) → sin bono; el neto = bruto - deducciones (comportamiento previo).
        [$tenant, $user, $employee] = $this->make();
        $this->checkIn($user, $tenant, false);

        $p = $this->payroll($employee);

        $this->assertSame(0.0, $p['bonus']['total']);
        $this->assertSame(0.0, $p['salary']['compliance_bonus']);
        $this->assertEqualsWithDelta($p['salary']['gross'] - $p['deductions_breakdown']['total'], $p['salary']['net'], 0.001);
    }

    public function test_puntualidad_bono_ganado_suma_al_neto(): void
    {
        [$tenant, $user, $employee] = $this->make(['punctuality_bonus_amount' => 500.00, 'punctuality_bonus_max_lates' => 0]);
        $this->checkIn($user, $tenant, false); // puntual

        $p = $this->payroll($employee);

        $this->assertTrue($p['bonus']['punctuality_earned']);
        $this->assertSame(500.0, $p['bonus']['punctuality_amount']);
        $this->assertSame(500.0, $p['bonus']['total']);
        // El bono SUMA sobre el neto.
        $this->assertEqualsWithDelta(
            $p['salary']['gross'] - $p['deductions_breakdown']['total'] + 500.0,
            $p['salary']['net'], 0.001
        );
    }

    public function test_puntualidad_no_ganado_con_retardo(): void
    {
        [$tenant, $user, $employee] = $this->make(['punctuality_bonus_amount' => 500.00, 'punctuality_bonus_max_lates' => 0]);
        $this->checkIn($user, $tenant, true); // retardo

        $p = $this->payroll($employee);

        $this->assertFalse($p['bonus']['punctuality_earned']);
        $this->assertSame(0.0, $p['bonus']['punctuality_amount']);
    }

    public function test_puntualidad_umbral_permite_algunos_retardos(): void
    {
        // umbral 2: un retardo sigue ganando el bono.
        [$tenant, $user, $employee] = $this->make(['punctuality_bonus_amount' => 500.00, 'punctuality_bonus_max_lates' => 2]);
        $this->checkIn($user, $tenant, true);

        $p = $this->payroll($employee);

        $this->assertTrue($p['bonus']['punctuality_earned']);
        $this->assertSame(500.0, $p['bonus']['punctuality_amount']);
    }

    public function test_apertura_bono_por_apertura_a_tiempo(): void
    {
        [$tenant, $user, $employee] = $this->make(['opening_bonus_per_open' => 100.00]);
        $this->openingRow($tenant, $user, '08:58:00'); // a tiempo (antes de 09:00)

        $p = $this->payroll($employee);

        $this->assertSame(1, $p['bonus']['on_time_opens']);
        $this->assertSame(100.0, $p['bonus']['opening_amount']);
        $this->assertSame(100.0, $p['bonus']['total']);
    }

    public function test_apertura_tarde_no_cuenta(): void
    {
        [$tenant, $user, $employee] = $this->make(['opening_bonus_per_open' => 100.00]);
        $this->openingRow($tenant, $user, '09:30:00'); // 30 min tarde (> 09:00 + 10 tol)

        $p = $this->payroll($employee);

        $this->assertSame(0, $p['bonus']['on_time_opens']);
        $this->assertSame(0.0, $p['bonus']['opening_amount']);
    }

    public function test_apertura_de_otro_no_cuenta(): void
    {
        // La apertura la hizo OTRO usuario → no cuenta para este empleado.
        [$tenant, $user, $employee] = $this->make(['opening_bonus_per_open' => 100.00]);
        $otro = User::create(['tenant_id' => $tenant->id, 'name' => 'O', 'email' => 'o' . uniqid() . '@t.local', 'password' => bcrypt('x'), 'role' => 'empleado']);
        $this->openingRow($tenant, $otro, '08:58:00');

        $p = $this->payroll($employee);

        $this->assertSame(0, $p['bonus']['on_time_opens']);
    }

    public function test_configured_flags_distinguen_no_config_de_no_ganado(): void
    {
        // Puntualidad CONFIGURADA (monto>0) pero NO ganada (retardo); apertura NO configurada.
        [$tenant, $user, $employee] = $this->make(['punctuality_bonus_amount' => 500.00, 'punctuality_bonus_max_lates' => 0]);
        $this->checkIn($user, $tenant, true); // retardo → no gana

        $p = $this->payroll($employee);

        $this->assertTrue($p['bonus']['punctuality_configured'], 'el tenant SÍ tiene el bono configurado');
        $this->assertFalse($p['bonus']['punctuality_earned'], 'pero el empleado no lo alcanzó');
        $this->assertFalse($p['bonus']['opening_configured'], 'apertura no configurada');
    }

    public function test_default_off_configured_flags_false(): void
    {
        [$tenant, $user, $employee] = $this->make();
        $this->checkIn($user, $tenant, false);

        $p = $this->payroll($employee);

        $this->assertFalse($p['bonus']['punctuality_configured']);
        $this->assertFalse($p['bonus']['opening_configured']);
    }

    public function test_apertura_respeta_tz_no_utc(): void
    {
        // Tenant en America/Mexico_City (UTC-6): opened_at se guarda en UTC. Abrió 08:58 LOCAL (a tiempo,
        // antes de 09:00 local) = 14:58 UTC. El on-time debe evaluarse en hora local (review R94-#2/#7).
        [$tenant, $user, $employee] = $this->make(['opening_bonus_per_open' => 100.00], 'America/Mexico_City');
        DB::table('store_daily_opening_statuses')->insert([
            'tenant_id' => $tenant->id, 'company_id' => 1, 'store_id' => 1, 'date' => self::DAY,
            'scheduled_opening_time' => '09:00:00', 'pre_opening_window_start' => '08:45:00',
            'report_deadline' => '09:15:00', 'status' => 'opened',
            'opened_by_employee_id' => $user->id, 'opened_at' => self::DAY . ' 14:58:00', // 08:58 local
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $p = $this->payroll($employee);

        $this->assertSame(1, $p['bonus']['on_time_opens'], 'debe evaluar el on-time en hora local, no UTC');
        $this->assertSame(100.0, $p['bonus']['opening_amount']);
    }

    public function test_semana_con_falta_pierde_puntualidad(): void
    {
        // Periodo de 2 días laborales (lun+mar): asiste lunes pero FALTA martes → 1 falta física →
        // no gana el bono de puntualidad aunque el lunes fuera puntual (review R94-#7).
        [$tenant, $user, $employee] = $this->make(['punctuality_bonus_amount' => 500.00, 'punctuality_bonus_max_lates' => 0]);
        $this->checkIn($user, $tenant, false); // lunes 2026-06-01, puntual; martes sin ponche = falta

        $p = app(ClockService::class)->calculatePayrollForEmployee($employee, self::DAY, '2026-06-02');

        $this->assertGreaterThan(0, $p['incidents']['physical_absences']);
        $this->assertFalse($p['bonus']['punctuality_earned']);
        $this->assertSame(0.0, $p['bonus']['total']);
    }

    public function test_sin_lft_row_bono_cero(): void
    {
        // Sin fila LftSetting: calculatePayrollForEmployee la auto-crea con defaults (bono en 0) →
        // el `?? 0` garantiza retrocompatibilidad (review R94-#1/#7).
        [$tenant, $user, $employee] = $this->make([], 'UTC', false);
        $this->checkIn($user, $tenant, false);

        $p = $this->payroll($employee);

        $this->assertSame(0.0, $p['bonus']['total']);
        $this->assertSame(0.0, $p['salary']['compliance_bonus']);
    }

    public function test_bono_llega_por_el_endpoint_real(): void
    {
        // Contrato por el ENDPOINT HTTP real (/employee/payroll-weekly), no sólo el servicio directo
        // (lección R88). getCurrentWeekRange usa Carbon::now → setTestNow al lunes de la semana.
        Carbon::setTestNow(Carbon::parse(self::DAY . ' 12:00:00')); // lunes 2026-06-01
        [$tenant, $user, $employee] = $this->make(['opening_bonus_per_open' => 100.00]);
        $this->openingRow($tenant, $user, '08:58:00'); // a tiempo, dentro de la semana actual

        $res = $this->actingAs($user)->getJson('/api/v1/employee/payroll-weekly');

        $res->assertStatus(200);
        $this->assertSame(1, $res->json('data.bonus.on_time_opens'));
        // JSON no distingue 100.0 de 100 → assertEquals (type-loose) para el número serializado.
        $this->assertEquals(100.0, $res->json('data.salary.compliance_bonus'));
    }

    private function openingRow(Tenant $tenant, User $opener, string $openedTime): void
    {
        DB::table('store_daily_opening_statuses')->insert([
            'tenant_id' => $tenant->id, 'company_id' => 1, 'store_id' => 1, 'date' => self::DAY,
            'scheduled_opening_time' => '09:00:00', 'pre_opening_window_start' => '08:45:00',
            'report_deadline' => '09:15:00', 'status' => 'opened',
            'opened_by_employee_id' => $opener->id, 'opened_at' => self::DAY . ' ' . $openedTime,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
