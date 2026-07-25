<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Seguridad (R54 follow-up): el PIN de kiosko rechaza los valores TRIVIALES.
 *
 * Un PIN de 6 dígitos ya es de baja entropía; si además el admin elige 000000 / 123456 / 111111, un
 * atacante lo adivina en los primeros intentos (aun con rate-limit). Se bloquean: dígitos repetidos
 * y secuencias rectas ascendentes/descendentes. El rate-limit (R54) sigue siendo la defensa contra
 * adivinación online; esto evita los peores PINs de raíz.
 */
class KioskPinBlocklistTest extends TestCase
{
    use RefreshDatabase;

    private function admin(int $tenantId = 7): User
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => $tenantId, 'name' => 'T', 'subdomain' => 't' . $tenantId, 'public_slug' => 't' . $tenantId,
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $u = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $u->id)->update(['tenant_id' => $tenantId]);
        return User::withoutGlobalScopes()->find($u->id);
    }

    private function employeeId(int $tenantId = 7): int
    {
        return DB::table('employees')->insertGetId([
            'tenant_id' => $tenantId, 'user_id' => null, 'name' => 'Emp',
            'email' => 'e' . uniqid() . '@t.local', 'is_active_employee' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_rechaza_pins_triviales(): void
    {
        $admin = $this->admin();
        $emp = $this->employeeId();

        // dígitos repetidos + secuencias rectas
        foreach (['000000', '111111', '999999', '123456', '654321', '012345', '234567'] as $trivial) {
            $this->actingAs($admin)->postJson("/api/v1/admin/employees/{$emp}/kiosk-pin", ['pin' => $trivial])
                ->assertStatus(422);
        }
        $this->assertNull(DB::table('employees')->where('id', $emp)->value('kiosk_pin_hash'));
    }

    public function test_acepta_un_pin_no_trivial(): void
    {
        $admin = $this->admin();
        $emp = $this->employeeId();

        $this->actingAs($admin)->postJson("/api/v1/admin/employees/{$emp}/kiosk-pin", ['pin' => '284917'])
            ->assertStatus(200);
        $this->assertNotNull(DB::table('employees')->where('id', $emp)->value('kiosk_pin_hash'));
    }
}
