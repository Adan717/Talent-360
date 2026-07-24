<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Seguridad (production-readiness) — endurecer los endpoints de ponche.
 *
 * 1) `type` SIN whitelist (`required|string`): un cliente podía inyectar cualquier string en
 *    `time_entries.type`/`audit_logs.type` — basura que contamina asistencia/nómina, o tipos con
 *    semántica que el motor interpreta. Fix: whitelist (`ClockService::PUNCH_TYPES`).
 * 2) `details.sandbox_bypass`: `ClockService:128` salta el IP-lock de sucursal si el ponche trae
 *    `details['sandbox_bypass']`. `details` se acepta como array crudo, así que un empleado mandando
 *    `{"sandbox_bypass":1}` fichaba desde casa. El FE NUNCA lo manda (sólo los tests, por llamada
 *    directa a processPunch). Fix: se elimina el flag del `details` del cliente en el controller.
 */
class PunchHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `enterprise` por defecto (sin el bloqueo de tienda-cerrada del plan gratuito, para que un
     * check_in de prueba pase). El IP-lock es freemium-only, así que ese caso usa `plan='freemium'`.
     */
    private function setupEmp(string $plan = 'enterprise', bool $ipLock = false): array
    {
        $tenant = Tenant::create(['name' => 'Empresa H', 'subdomain' => 'h' . uniqid(), 'plan' => $plan, 'is_active' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'h' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'shiftStart' => '09:00:00', 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        if ($ipLock) {
            // updateOrInsert: el hook `created` del Tenant puede sembrar ya un clockOpConfig (unique
            // en tenant_id+key), así que no se puede insertar a ciegas.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'clockOpConfig'],
                [
                    'value' => json_encode(['ip_lock_enabled' => true, 'store_ip_address' => '10.0.0.1']),
                    'created_at' => now(), 'updated_at' => now(),
                ]
            );
        }
        return [$tenant, $user];
    }

    public function test_un_type_basura_es_rechazado(): void
    {
        [, $user] = $this->setupEmp();

        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'hackeado_lol', 'time' => '09:05:00',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $user->id)->count());
    }

    public function test_un_type_valido_se_acepta(): void
    {
        [, $user] = $this->setupEmp();

        $res = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in', 'time' => '09:05:00',
        ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('time_entries', ['user_id' => $user->id, 'type' => 'check_in']);
    }

    /** Los tipos auxiliares y de pausa también son válidos (no romper el vocabulario real). */
    public function test_los_tipos_del_vocabulario_real_se_aceptan(): void
    {
        [, $user] = $this->setupEmp();

        foreach (['waiting', 'meal_start', 'break_start', 'temp_exit_start', 'meal_reservation'] as $tipo) {
            $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id, 'type' => $tipo, 'time' => '10:00:00',
            ])->assertStatus(200);
        }
    }

    /**
     * `details.sandbox_bypass` del cliente se IGNORA: con IP-lock activo y una IP distinta a la de
     * la sucursal, el ponche se BLOQUEA aunque el cliente mande el flag.
     */
    public function test_sandbox_bypass_del_cliente_no_salta_el_ip_lock(): void
    {
        [, $user] = $this->setupEmp(plan: 'freemium', ipLock: true);

        $res = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']) // IP distinta a 10.0.0.1
            ->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id, 'type' => 'check_in', 'time' => '09:05:00',
                'details' => ['sandbox_bypass' => true],
            ]);

        $res->assertStatus(400); // bloqueado por IP-lock; el flag del cliente no lo salta
        $this->assertStringContainsString('red Wi-Fi', (string) $res->json('message'));
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $user->id)->count());
    }

    /**
     * R67: el mismo flag por la TERCERA ruta a processPunch — `/sync/clock` (ClockController::sync).
     * El scrub de `sandbox_bypass` sólo estaba en /clock/punch y /kiosk/punch; esta ruta quedó sin
     * cubrir → un empleado de plan free con IP-lock fichaba desde casa. Ahora también se bloquea.
     */
    public function test_sandbox_bypass_no_salta_el_ip_lock_por_sync_clock(): void
    {
        [, $user] = $this->setupEmp(plan: 'freemium', ipLock: true);

        $res = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']) // IP distinta a 10.0.0.1
            ->actingAs($user)->postJson('/api/v1/sync/clock', [
                'user_id' => $user->id, 'type' => 'check_in', 'time' => '09:05:00',
                'date' => '2026-07-10',
                'details' => ['sandbox_bypass' => true],
            ]);

        $res->assertStatus(400); // bloqueado por IP-lock; el flag del cliente no lo salta
        $this->assertStringContainsString('red Wi-Fi', (string) $res->json('message'));
        $this->assertSame(0, DB::table('time_entries')->where('user_id', $user->id)->count());
    }
}
