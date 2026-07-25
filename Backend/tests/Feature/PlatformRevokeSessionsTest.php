<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformRevokeSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoking_all_platform_sessions_deletes_only_platform_tokens(): void
    {
        $platformAdmin = User::factory()->create(['role' => 'platform_admin']);

        // Dos cuentas de plataforma con sesiones activas.
        $p1 = PlatformUser::create(['name' => 'P1', 'email' => 'p1@talent360.mx', 'password' => 'x', 'role' => 'platform_admin', 'is_active' => true]);
        $p2 = PlatformUser::create(['name' => 'P2', 'email' => 'p2@talent360.mx', 'password' => 'x', 'role' => 'support_agent', 'is_active' => true]);
        $p1->createToken('sesion');
        $p2->createToken('sesion');

        // Un usuario normal de empresa con su token — NO debe verse afectado.
        $tenantUser = User::factory()->create(['role' => 'admin']);
        $tenantUser->createToken('sesion');

        $this->assertEquals(2, DB::table('personal_access_tokens')->where('tokenable_type', PlatformUser::class)->count());

        $response = $this->actingAs($platformAdmin)->postJson('/api/v1/platform/security/revoke-all-sessions');

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'revoked_count' => 2]);

        // Se borraron las 2 de plataforma; la del usuario de empresa sigue viva.
        $this->assertEquals(0, DB::table('personal_access_tokens')->where('tokenable_type', PlatformUser::class)->count());
        $this->assertEquals(1, DB::table('personal_access_tokens')->where('tokenable_type', User::class)->count());
    }

    public function test_a_non_platform_admin_cannot_revoke_platform_sessions(): void
    {
        $tenantAdmin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($tenantAdmin)->postJson('/api/v1/platform/security/revoke-all-sessions');

        // El middleware role:platform_admin lo bloquea antes de llegar al controlador.
        $response->assertStatus(403);
    }
}
