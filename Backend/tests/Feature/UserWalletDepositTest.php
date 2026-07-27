<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 (auditoría 2026-07-27): UserWallet::deposit era lectura-modificación-escritura SIN
 * transacción ni lock: dos depósitos concurrentes del mismo usuario (doble click, batch +
 * validación simultánea) podían pisarse y PERDER uno. El síntoma reproducible sin
 * concurrencia real: una instancia STALE del modelo (cargada antes de que otro request
 * depositara) escribía su balance viejo encima del nuevo.
 *
 * Regla de esta ronda: deposit() re-lee la fila con lockForUpdate dentro de una
 * transacción y suma sobre el estado FRESCO — nunca sobre el de memoria.
 */
class UserWalletDepositTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'DecorArte', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_deposito_sobre_instancia_stale_no_pisa_lo_depositado_por_otro(): void
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        // Instancia cargada ANTES: balance 0.
        $stale = UserWallet::getOrCreateForUser($user->id, 1);

        // "Otro request" deposita 5.00 / 50 XP por fuera de esta instancia.
        DB::table('user_wallets')->where('id', $stale->id)->update([
            'balance_coins' => 5.00,
            'total_earned_coins' => 5.00,
            'xp_points' => 50,
        ]);

        // La instancia stale deposita 1.00 / 10 XP.
        $stale->deposit(1.00, 10, 'earned_task', 'Prueba stale');

        $fresh = DB::table('user_wallets')->where('id', $stale->id)->first();
        // Con el RMW sin lock, el balance quedaba en 1.00 (pisaba los 5.00 del otro).
        $this->assertEquals(6.00, (float) $fresh->balance_coins);
        $this->assertEquals(6.00, (float) $fresh->total_earned_coins);
        $this->assertEquals(60, (int) $fresh->xp_points);
    }

    public function test_deposito_normal_acumula_y_registra_transaccion(): void
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        $wallet = UserWallet::getOrCreateForUser($user->id, 1);
        $wallet->deposit(2.50, 25, 'earned_task', 'Primera');
        $wallet->deposit(1.50, 15, 'earned_task', 'Segunda');

        $fresh = DB::table('user_wallets')->where('id', $wallet->id)->first();
        $this->assertEquals(4.00, (float) $fresh->balance_coins);
        $this->assertEquals(40, (int) $fresh->xp_points);
        $this->assertSame(2, DB::table('wallet_transactions')->where('user_id', $user->id)->count());

        // El nivel sube cada 500 XP (fórmula existente): con 40 XP sigue en 1.
        $this->assertEquals(1, (int) $fresh->level);
    }

    public function test_nivel_sube_con_el_xp_fresco(): void
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        $stale = UserWallet::getOrCreateForUser($user->id, 1);

        // Otro flujo ya dejó 495 XP.
        DB::table('user_wallets')->where('id', $stale->id)->update(['xp_points' => 495]);

        // +10 XP sobre la instancia stale → 505 XP = nivel 2 (no 1, que saldría del stale 0+10).
        $stale->deposit(0.10, 10, 'earned_task');

        $fresh = DB::table('user_wallets')->where('id', $stale->id)->first();
        $this->assertEquals(505, (int) $fresh->xp_points);
        $this->assertEquals(2, (int) $fresh->level);
    }
}
