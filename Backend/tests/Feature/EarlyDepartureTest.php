<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reloj R92 (Fase 4): Salida Anticipada enforceada.
 *
 * El teatro que se cierra: la "autorización" de una salida anticipada era un STRING en `details.note`
 * redactado por el cliente (y en free, el botón principal salía temprano SIN marca alguna). Ahora:
 *  - `POST /clock/authorize-early-departure` valida la credencial del supervisor SERVER-SIDE (QR
 *    dinámico o PIN de kiosko, espejo R81) y persiste `early_departure_authorizations`.
 *  - `processPunch` estampa en el check_out un registro ESTRUCTURADO computado por el servidor:
 *    `details.early_departure = {minutes_early, authorized}` — el string del cliente ya no es la verdad.
 *  - NO bloquea el check_out (bloquear una salida empuja al "me voy igual" físico → turno huérfano
 *    forzado por CRON, un dato peor; mismo criterio que la Salida Doble Llave R75).
 */
class EarlyDepartureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Tenant + empleado (shiftEnd 18:00 salvo override) + supervisor con PIN de kiosko. */
    private function makeSetup(?string $shiftEnd = '18:00:00'): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 15:00:00'));
        $tenant = Tenant::create([
            'name' => 'Empresa Salida Ant', 'subdomain' => 'sa' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode('UTC'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $jr = JobRole::create(['tenant_id' => $tenant->id, 'name' => 'Cajero', 'area' => 'Piso']);

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'sa' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado', 'is_active' => true,
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'base_salary' => 3000, 'shiftStart' => '09:00:00', 'shiftEnd' => $shiftEnd,
            'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true, 'job_role_id' => $jr->id,
        ]);

        $sup = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Superv', 'email' => 'sup' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'supervisor', 'is_active' => true,
        ]);
        $supEmp = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $sup->id, 'name' => 'Superv',
            'base_salary' => 5000, 'restDay' => 'Domingo', 'mealMinutes' => 60,
            'is_active_employee' => true, 'job_role_id' => $jr->id,
        ]);
        $supEmp->setKioskPin('135792');
        $supEmp->save();

        return [$tenant, $user, $sup];
    }

    private function makeQrToken(User $supervisor): string
    {
        $token = Str::random(32);
        DB::table('supervisor_qr_tokens')->insert([
            'supervisor_id' => $supervisor->id,
            'token' => $token,
            'expires_at' => now()->addSeconds(60),
            'is_used' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $token;
    }

    private function earlyDeparture(TimeEntry $entry): ?array
    {
        return json_decode($entry->details, true)['early_departure'] ?? null;
    }

    /**
     * ENMIENDA merge F3: la secuencia §15 reconciliada exige un turno ABIERTO para el check_out.
     * Se abre con un check_in directo en BD — lo que estos tests prueban es el ESTAMPADO de
     * `early_departure`, no la apertura del turno.
     */
    private function abrirTurno(User $user, string $time = '09:00:00'): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id,
            'date' => Carbon::now(\App\Helpers\TenantTimezone::for($user->tenant_id))->format('Y-m-d'),
            'type' => 'check_in', 'time' => $time,
            'is_late' => false, 'late_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- El endpoint de autorización (espejo R81) ------------------------------------

    public function test_grant_con_pin_crea_autorizacion(): void
    {
        [, $user] = $this->makeSetup();

        $res = $this->actingAs($user)->postJson('/api/v1/clock/authorize-early-departure', [
            'supervisor_pin' => '135792',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('early_departure_authorizations', [
            'user_id' => $user->id, 'date' => '2026-07-15', 'method' => 'pin',
        ]);
    }

    public function test_grant_con_qr_consume_el_token(): void
    {
        [, $user, $sup] = $this->makeSetup();
        $token = $this->makeQrToken($sup);

        $res = $this->actingAs($user)->postJson('/api/v1/clock/authorize-early-departure', [
            'qr_token' => $token,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('early_departure_authorizations', ['user_id' => $user->id, 'method' => 'qr']);
        $this->assertTrue((bool) DB::table('supervisor_qr_tokens')->where('token', $token)->value('is_used'));
    }

    public function test_no_auto_autorizacion(): void
    {
        [, , $sup] = $this->makeSetup();
        $token = $this->makeQrToken($sup);

        // El propio supervisor intenta autorizarse con SU QR → 403 (R75).
        $this->actingAs($sup)->postJson('/api/v1/clock/authorize-early-departure', ['qr_token' => $token])
            ->assertStatus(403);
        $this->assertDatabaseMissing('early_departure_authorizations', ['user_id' => $sup->id]);
    }

    public function test_pin_invalido_422_sin_fila(): void
    {
        [, $user] = $this->makeSetup();

        $this->actingAs($user)->postJson('/api/v1/clock/authorize-early-departure', [
            'supervisor_pin' => '999999',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('early_departure_authorizations', ['user_id' => $user->id]);
    }

    // ---- El estampado server-side en el check_out ------------------------------------

    public function test_checkout_temprano_sin_autorizacion(): void
    {
        [, $user] = $this->makeSetup(); // shiftEnd 18:00, now 15:00
        $this->abrirTurno($user);
        app(ClockService::class)->processPunch($user, 'check_out');

        $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first();
        $ed = $this->earlyDeparture($entry);
        $this->assertNotNull($ed, 'debe estampar early_departure');
        $this->assertSame(180, $ed['minutes_early']);
        $this->assertFalse($ed['authorized']);
    }

    public function test_checkout_temprano_con_autorizacion(): void
    {
        [$tenant, $user] = $this->makeSetup();
        DB::table('early_departure_authorizations')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-15',
            'authorized_by' => 999, 'method' => 'pin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->abrirTurno($user);
        app(ClockService::class)->processPunch($user, 'check_out');

        $ed = $this->earlyDeparture(TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first());
        $this->assertTrue($ed['authorized']);
    }

    public function test_checkout_puntual_sin_estampa(): void
    {
        [, $user] = $this->makeSetup();
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:05:00')); // después del shiftEnd

        $this->abrirTurno($user);
        app(ClockService::class)->processPunch($user, 'check_out');

        $ed = $this->earlyDeparture(TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first());
        $this->assertNull($ed, 'una salida puntual no lleva early_departure');
    }

    public function test_sin_shift_end_no_estampa(): void
    {
        [, $user] = $this->makeSetup(null); // sin shiftEnd → no hay concepto de "temprano"

        $this->abrirTurno($user);
        app(ClockService::class)->processPunch($user, 'check_out');

        $ed = $this->earlyDeparture(TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first());
        $this->assertNull($ed);
    }

    public function test_nota_falsificada_no_autoriza(): void
    {
        // Endpoint REAL: un cliente hostil redacta la nota "Autorizada por Supervisor" → el campo
        // estructurado authorized (computado por el servidor) sigue en false.
        [, $user] = $this->makeSetup();

        $this->abrirTurno($user);
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_out',
            'details' => ['note' => '{"note":"Salida Anticipada Autorizada por Supervisor: Enfermedad"}'],
        ]);

        $res->assertStatus(200);
        $ed = $this->earlyDeparture(TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first());
        $this->assertNotNull($ed);
        $this->assertFalse($ed['authorized'], 'la nota del cliente NO debe autorizar');
    }

    public function test_override_de_mando_no_autoriza(): void
    {
        // Review R92-M1 (R75): el supervisor_override deriva del ROL del emisor — un supervisor
        // saliendo temprano desde su propio teléfono se auto-autorizaría. SÓLO la fila (creada por
        // OTRA persona con autoridad) cuenta, como el consumer R81 del feriado.
        [, $user] = $this->makeSetup();

        $this->abrirTurno($user);
        app(ClockService::class)->processPunch($user, 'check_out', null, ['supervisor_override' => true]);

        $ed = $this->earlyDeparture(TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first());
        $this->assertFalse($ed['authorized'], 'el override de rol NO debe contar como autorización');
    }

    public function test_clave_forjada_se_stripea_en_salida_puntual(): void
    {
        // Review R92-L1: `early_departure` es clave RESERVADA del servidor. En una salida PUNTUAL el
        // bloque de estampado no corre — sin el unset, un cliente hostil persistía su versión forjada.
        [, $user] = $this->makeSetup();
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:05:00')); // después del shiftEnd

        $this->abrirTurno($user);
        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_out',
            'details' => ['early_departure' => ['minutes_early' => 300, 'authorized' => true]],
        ]);

        $res->assertStatus(200);
        $entry = TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->first();
        $this->assertNull($this->earlyDeparture($entry), 'la clave forjada debe stripearse');
    }
}
