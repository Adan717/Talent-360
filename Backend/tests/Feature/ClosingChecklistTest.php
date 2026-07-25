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
 * Reloj R88 (Fase 3 / T3.3): Checklist Exprés de Cierre. Con `require_closing_checklist` activo, el
 * `check_out` del empleado exige los 3 ticks (luces/caja/alarma) o se rechaza server-side (anti-teatro:
 * el gate del cliente es bypasseable). El flag es por-tenant y default false (comportamiento previo
 * intacto). El checklist se REGISTRA siempre en `time_entries.details` + `audit_logs.details`.
 *
 * Exentos del bloqueo: el override de admin/supervisor (fuerza cierres) y los ponches OFFLINE (no se
 * puede exigir un checklist retroactivo a una cola sincronizada; el cierre forzado por CRON ni pasa
 * por processPunch). Mismo patrón de flag que CheckoutApprovalTest (R75).
 */
class ClosingChecklistTest extends TestCase
{
    use RefreshDatabase;

    private const FULL = ['closing_checklist' => ['luces' => true, 'caja' => true, 'alarma' => true]];

    private function makeSetup(bool $require): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Cierre', 'subdomain' => 'cierre' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colaborador',
            'email' => 'colab' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colaborador',
            'base_salary' => 3000.00, 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode('UTC'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        LftSetting::create(['tenant_id' => $tenant->id, 'require_closing_checklist' => $require]);
        // ENMIENDA merge F3: la secuencia §15 reconciliada exige turno ABIERTO para el check_out;
        // se abre con un check_in directo en BD (no cambia lo que estos tests afirman: el gate del
        // checklist de cierre y sus exenciones).
        DB::table('time_entries')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'date' => Carbon::now()->format('Y-m-d'), 'type' => 'check_in', 'time' => '09:00:00',
            'is_late' => false, 'late_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$tenant, $user];
    }

    /** Intenta un check_out; devuelve ['ok'=>bool, 'msg'=>string]. processPunch lanza al rechazar. */
    private function attempt(User $user, array $details = [], $occurredAt = null): array
    {
        try {
            app(ClockService::class)->processPunch($user, 'check_out', null, $details, $occurredAt);
            return ['ok' => true, 'msg' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    public function test_bloquea_checkout_sin_checklist_cuando_requerido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);
            $res = $this->attempt($user, []);

            $this->assertFalse($res['ok']);
            $this->assertStringContainsStringIgnoringCase('checklist', $res['msg']);
            // No se creó el check_out.
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_permite_checkout_con_checklist_completo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);
            $res = $this->attempt($user, self::FULL);

            $this->assertTrue($res['ok'], $res['msg']);
            $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first();
            $this->assertNotNull($entry);
            $decoded = json_decode($entry->details, true);
            $this->assertTrue($decoded['closing_checklist']['luces']);
            $this->assertTrue($decoded['closing_checklist']['caja']);
            $this->assertTrue($decoded['closing_checklist']['alarma']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_bloquea_con_checklist_incompleto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);
            $res = $this->attempt($user, ['closing_checklist' => ['luces' => true, 'caja' => true, 'alarma' => false]]);

            $this->assertFalse($res['ok']);
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_flag_off_permite_sin_checklist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(false); // default: sin requerimiento
            $res = $this->attempt($user, []);

            $this->assertTrue($res['ok'], $res['msg']);
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_registra_checklist_en_details_y_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [$tenant, $user] = $this->makeSetup(true);
            $this->attempt($user, self::FULL);

            // audit_logs guarda el mismo detailsMerge (columna text, sin truncar).
            $audit = DB::table('audit_logs')->where('user_id', $user->id)->where('type', 'check_out')->first();
            $this->assertNotNull($audit);
            $this->assertStringContainsString('closing_checklist', $audit->details);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_override_supervisor_exento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);
            // El override lo fija el controller según el rol del emisor (admin/supervisor).
            $res = $this->attempt($user, ['supervisor_override' => true]);

            $this->assertTrue($res['ok'], $res['msg']);
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_offline_exento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);
            // Ponche offline (occurredAt seteado): no se exige checklist retroactivo.
            $res = $this->attempt($user, [], '2026-07-10T17:00:00Z');

            $this->assertTrue($res['ok'], $res['msg']);
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_kiosk_exento(): void
    {
        // La tablet compartida (KioskController marca via_kiosk) no tiene UI de checklist → exenta,
        // para no brickear la salida. Si no, el empleado no podría fichar salida en el kiosko.
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);
            $res = $this->attempt($user, ['via_kiosk' => true]);

            $this->assertTrue($res['ok'], $res['msg']);
            $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
            // via_kiosk es marcador de control: NO se persiste en el registro.
            $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first();
            $this->assertStringNotContainsString('via_kiosk', (string) $entry->details);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_via_kiosk_no_es_falsificable_por_clock_punch(): void
    {
        // Un empleado NO puede mandar via_kiosk=true a /clock/punch para saltarse el checklist en su
        // reloj personal: el endpoint lo stripea del input.
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);

            $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id,
                'type' => 'check_out',
                'details' => ['via_kiosk' => true, 'gps' => null],
            ]);

            $res->assertStatus(400);
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Contrato FE↔BE por el ENDPOINT REAL (POST /clock/punch). El FE manda
     * `details: {note, gps, closing_checklist}` (top-level). Este test lo ejercita tal cual: sin él,
     * un test que sólo llama a processPunch directo NO caza que el FE entierre el checklist dentro de
     * `note` (bug clase R82, cazado en review). El empleado va con override=false → se enforcea.
     */
    public function test_endpoint_real_bloquea_sin_checklist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);

            $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id,
                'type' => 'check_out',
                'details' => ['note' => '{"evalStars":null}', 'gps' => null],
            ]);

            $res->assertStatus(400);
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_endpoint_real_permite_con_checklist_top_level(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            [, $user] = $this->makeSetup(true);

            $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id,
                'type' => 'check_out',
                'details' => [
                    'note' => '{"evalStars":null}',
                    'gps' => null,
                    'closing_checklist' => ['luces' => true, 'caja' => true, 'alarma' => true],
                ],
            ]);

            $res->assertStatus(200);
            $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first();
            $this->assertNotNull($entry);
            $decoded = json_decode($entry->details, true);
            $this->assertTrue($decoded['closing_checklist']['luces']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
