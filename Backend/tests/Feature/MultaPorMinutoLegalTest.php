<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * N4/N5 — decisión del jefe (2026-08-07, opción A): el art. 107 de la LFT prohíbe las multas
 * al salario, y el propio curso de la Academia lo enseña. Reglas:
 *
 *  1. El descuento por minuto arranca en $0 de fábrica (era $2/min en silencio).
 *  2. Los retardos se sancionan por ACUMULACIÓN (N retardos = 1 falta, reglamento interior):
 *     esa falta descuenta el día y baja el séptimo — el mismo criterio que una falta real.
 *  3. Cobro ÚNICO: si una empresa activa el por-minuto bajo su responsabilidad, los retardos
 *     ya convertidos en falta NO cobran además por minuto; sólo el residuo.
 *  4. Activarlo queda documentado (quién y cuándo) y el API lo avisa (art. 107).
 *  5. Migración de datos: sólo el 2.00 heredado en silencio baja a $0; un valor capturado a
 *     propósito (1.50, 3.00…) se respeta.
 */
class MultaPorMinutoLegalTest extends TestCase
{
    use RefreshDatabase;

    private function armarEmpresa(array $lft = []): array
    {
        $tenant = Tenant::create(['name' => 'Empresa L', 'subdomain' => 'l' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'C', 'email' => 'c' . uniqid() . '@t.local', 'password' => bcrypt('x'), 'role' => 'empleado']);
        $emp = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'C',
            'base_salary' => 3000.00, 'restDay' => 'Domingo', 'mealMinutes' => 60,
            'is_active_employee' => true,
        ]);
        if ($lft !== []) {
            LftSetting::create(array_merge(['tenant_id' => $tenant->id], $lft));
        }
        return [$tenant, $user, $emp];
    }

    private function retardo(Tenant $tenant, User $user, string $date, int $mins): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => $date,
            'type' => 'check_in', 'time' => '09:30:00', 'is_late' => true, 'late_minutes' => $mins,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** El escenario de aceptación del jefe: default $0 + 3 retardos → 1 día + séptimo, $0 por minuto. */
    public function test_default_cero_tres_retardos_pierden_dia_y_septimo_pero_ni_un_peso_por_minuto(): void
    {
        // SIN fila LftSetting: el servicio la auto-crea con los defaults de fábrica.
        [$tenant, $user, $emp] = $this->armarEmpresa();
        foreach (['2026-07-06', '2026-07-07', '2026-07-08'] as $d) {
            $this->retardo($tenant, $user, $d, 15);
        }
        // Asistencia el resto de la semana para no mezclar faltas físicas.
        foreach (['2026-07-09', '2026-07-10', '2026-07-11'] as $d) {
            DB::table('time_entries')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => $d,
                'type' => 'check_in', 'time' => '08:55:00', 'is_late' => false, 'late_minutes' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $p = app(ClockService::class)->calculatePayrollForEmployee($emp, '2026-07-06', '2026-07-12');

        $this->assertSame(0.0, (float) LftSetting::where('tenant_id', $tenant->id)->value('late_penalty_per_minute'),
            'El default de fábrica es $0 (art. 107 LFT).');
        $this->assertSame(1, $p['incidents']['absences_from_lates'], '3 retardos = 1 falta acumulada.');
        // Pierde el día ($500) y el séptimo baja a 5/6 (descuento $83.33) — y NADA por minuto.
        $this->assertEquals(500.00, round($p['deductions_breakdown']['absences'], 2));
        $this->assertEquals(83.33, round($p['deductions_breakdown']['rest_day'], 2));
        $this->assertSame(0.0, (float) $p['deductions_breakdown']['lates'],
            'Con el default $0 no se descuenta ni un peso por minuto de retardo.');
    }

    /** Cobro único cuando el por-minuto SÍ está activo: los consumidos por la falta no cobran minuto. */
    public function test_retardo_convertido_en_falta_no_cobra_ademas_por_minuto(): void
    {
        [$tenant, $user, $emp] = $this->armarEmpresa([
            'late_action_mode' => 'deduct', 'late_penalty_per_minute' => 5.00,
            'lates_per_absence' => 3, 'deduct_absence_day' => true, 'proportional_rest_day' => true,
            'paid_rest_day' => true, 'late_tolerance_minutes' => 10,
        ]);
        // 4 retardos de 10 min: los 3 PRIMEROS (cronológicos) se consumen en la falta; el 4º cobra.
        foreach (['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09'] as $d) {
            $this->retardo($tenant, $user, $d, 10);
        }

        $p = app(ClockService::class)->calculatePayrollForEmployee($emp, '2026-07-06', '2026-07-12');

        $this->assertSame(1, $p['incidents']['absences_from_lates']);
        $this->assertEquals(50.00, round($p['deductions_breakdown']['lates'], 2),
            'Sólo el retardo residual (10 min × $5) cobra por minuto; los 3 consumidos ya costaron la falta.');
        $this->assertSame(40, $p['incidents']['late_minutes'], 'El TOTAL de minutos tarde se sigue informando.');
    }

    /** Activarlo avisa (art. 107) y queda documentado; volver a $0 limpia la constancia. */
    public function test_activar_avisa_y_documenta_quien_y_cuando(): void
    {
        [, $user] = $this->armarEmpresa();
        DB::table('users')->where('id', $user->id)->update(['role' => 'admin']);
        $base = [
            'lates_per_absence' => 3, 'deduct_absence_day' => true, 'absences_for_warning' => 3,
            'absences_for_suspension' => 4, 'proportional_rest_day' => true,
            'late_tolerance_minutes' => 10, 'meal_tolerance_minutes' => 15,
            'rest_tolerance_minutes' => 10, 'late_action_mode' => 'deduct', 'paid_rest_day' => true,
        ];

        $res = $this->actingAs($user->fresh())
            ->postJson('/api/v1/admin/lft-settings', $base + ['late_penalty_per_minute' => 2]);

        $res->assertStatus(200);
        $this->assertStringContainsString('107', $res->json('warning'), 'Debe avisar del art. 107 al activarlo.');
        $fila = DB::table('lft_settings')->where('tenant_id', $user->tenant_id)->first();
        $this->assertSame((int) $user->id, (int) $fila->late_penalty_set_by, 'Queda documentado QUIÉN.');
        $this->assertNotNull($fila->late_penalty_set_at, 'Y CUÁNDO.');

        // Desactivar: sin aviso y constancia limpia.
        $res2 = $this->actingAs($user->fresh())
            ->postJson('/api/v1/admin/lft-settings', $base + ['late_penalty_per_minute' => 0]);
        $res2->assertStatus(200);
        $this->assertNull($res2->json('warning'));
        $fila2 = DB::table('lft_settings')->where('tenant_id', $user->tenant_id)->first();
        $this->assertNull($fila2->late_penalty_set_by);
        $this->assertNull($fila2->late_penalty_set_at);
    }

    /** La migración de datos baja a $0 sólo el 2.00 heredado; lo capturado a propósito se respeta. */
    public function test_migracion_solo_cera_el_default_heredado(): void
    {
        $t1 = Tenant::create(['name' => 'Heredado', 'subdomain' => 'h' . uniqid(), 'plan' => 'basic', 'is_active' => true]);
        $t2 = Tenant::create(['name' => 'Explicito', 'subdomain' => 'e' . uniqid(), 'plan' => 'basic', 'is_active' => true]);
        LftSetting::create(['tenant_id' => $t1->id, 'late_penalty_per_minute' => 2.00]);
        LftSetting::create(['tenant_id' => $t2->id, 'late_penalty_per_minute' => 1.50]);

        // Réplica exacta del UPDATE de la migración 2026_08_07_100000.
        DB::table('lft_settings')->where('late_penalty_per_minute', 2.00)
            ->update(['late_penalty_per_minute' => 0]);

        $this->assertSame(0.0, (float) LftSetting::where('tenant_id', $t1->id)->value('late_penalty_per_minute'),
            'El 2.00 recibido en silencio baja a $0.');
        $this->assertSame(1.5, (float) LftSetting::where('tenant_id', $t2->id)->value('late_penalty_per_minute'),
            'Un valor capturado a propósito NO se pisa.');
    }
}
