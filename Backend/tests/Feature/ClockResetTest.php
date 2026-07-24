<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClockResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant(1, 'Tenant Uno');
        $this->seedTenant(2, 'Tenant Dos');
    }

    private function seedTenant(int $id, string $name): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => $id,
            'name' => $name,
            'subdomain' => 'tenant' . $id,
            'public_slug' => 'tenant' . $id,
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makePlatformAdmin(): User
    {
        // role platform_admin => Tenantable deja tenant_id en null (sin tenant propio).
        return User::factory()->create(['role' => 'platform_admin']);
    }

    private function seedTimeEntry(int $tenantId, int $userId): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'date' => now()->format('Y-m-d'),
            'type' => 'check_in',
            'time' => '09:00:00',
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Fix de scope por tenant: el reset acotado solo borra los datos del tenant
     * indicado y deja intactos los de otros tenants (antes truncaba globalmente).
     */
    /**
     * ENMIENDA merge F3: /sync/reset ya no es un wipe de datos del tenant — es la PURGA del
     * Simulador Matrix (§13): borra SOLO filas con simulation_session_id (nunca datos reales),
     * un contrato estrictamente más seguro. La propiedad que este test protegía (aislamiento
     * cross-tenant del borrado) se conserva: la purga del tenant 1 no toca las filas simuladas
     * del tenant 2 ni los datos REALES de nadie. La resolución estricta de tenant (422 sin
     * tenant resoluble, sin fallback `?? 1`) también se conserva — ver los 2 tests de abajo.
     */
    public function test_reset_db_only_purges_target_tenant_simulated_data(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        $u1 = $this->makeTenantUser(1);
        $u2 = $this->makeTenantUser(2);

        // Dato REAL del tenant 1 (debe sobrevivir la purga).
        $this->seedTimeEntry(1, $u1);

        // Sesiones + filas SIMULADAS de ambos tenants.
        $s1 = DB::table('simulator_sessions')->insertGetId([
            'tenant_id' => 1, 'started_by_user_id' => $u1, 'simulated_date' => now()->format('Y-m-d'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $s2 = DB::table('simulator_sessions')->insertGetId([
            'tenant_id' => 2, 'started_by_user_id' => $u2, 'simulated_date' => now()->format('Y-m-d'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('time_entries')->insert([
            ['tenant_id' => 1, 'user_id' => $u1, 'date' => now()->format('Y-m-d'), 'type' => 'check_out',
             'time' => '18:00:00', 'is_late' => false, 'late_minutes' => 0,
             'simulation_session_id' => $s1, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => 2, 'user_id' => $u2, 'date' => now()->format('Y-m-d'), 'type' => 'check_out',
             'time' => '18:00:00', 'is_late' => false, 'late_minutes' => 0,
             'simulation_session_id' => $s2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($platformAdmin)->postJson('/api/v1/sync/reset', [
            'tenant_id' => 1,
        ]);

        $response->assertStatus(200);
        // Purgó SOLO lo simulado del tenant 1:
        $this->assertDatabaseMissing('time_entries', ['tenant_id' => 1, 'simulation_session_id' => $s1]);
        // El dato REAL del tenant 1 sobrevive:
        $this->assertDatabaseHas('time_entries', ['tenant_id' => 1, 'type' => 'check_in']);
        // Lo simulado del tenant 2 queda intacto:
        $this->assertDatabaseHas('time_entries', ['tenant_id' => 2, 'simulation_session_id' => $s2]);
    }

    /**
     * Un platform_admin sin tenant propio debe indicar explícitamente el tenant;
     * si no, se responde 422 en vez de borrar datos globalmente.
     */
    public function test_reset_db_requires_tenant_when_unresolvable(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        $response = $this->actingAs($platformAdmin)->postJson('/api/v1/sync/reset', []);

        $response->assertStatus(422);
    }

    /**
     * tenant_id inválido (cadena vacía / cero / no numérico) no debe pasar como
     * "resuelto": se responde 422 en vez de un falso 200 borrando where tenant_id=0.
     */
    public function test_reset_db_rejects_invalid_tenant_id(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        foreach (['', '0', 'abc'] as $bad) {
            $this->actingAs($platformAdmin)
                ->postJson('/api/v1/sync/reset', ['tenant_id' => $bad])
                ->assertStatus(422);
        }
    }

    private function makeTenantUser(int $tenantId): int
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        return $user->id;
    }
}
