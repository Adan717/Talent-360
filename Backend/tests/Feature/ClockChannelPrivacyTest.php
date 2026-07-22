<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClockChannelPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'Tenant A', 'subdomain' => 'a', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'Tenant B', 'subdomain' => 'b', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(int $tenantId): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        $user->refresh();

        return $user;
    }

    /**
     * El driver de broadcasting en testing es 'null' (phpunit.xml) — no monta un
     * servidor Reverb real ni expone /broadcasting/auth de forma fiel (esa ruta vive
     * bajo el grupo 'web' con CSRF, no bajo 'api'/Sanctum, así que probarla por HTTP
     * daría falsos positivos por CSRF, no por la regla de autorización real). En vez
     * de eso, se resuelve el callback registrado en routes/channels.php para
     * 'tenant.{tenantId}.clock' y se invoca directamente — es exactamente la misma
     * función que Laravel ejecuta internamente al autorizar una suscripción.
     */
    private function resolveClockChannelCallback(): callable
    {
        $broadcaster = Broadcast::connection();

        $reflection = new \ReflectionClass($broadcaster);
        $property = $reflection->getProperty('channels');
        $property->setAccessible(true);
        $channels = $property->getValue($broadcaster);

        $this->assertArrayHasKey('tenant.{tenantId}.clock', $channels, 'La ruta tenant.{tenantId}.clock no está registrada en routes/channels.php.');

        return $channels['tenant.{tenantId}.clock'];
    }

    public function test_clock_channel_rejects_a_user_from_another_tenant(): void
    {
        $userA = $this->makeUser(1);
        $callback = $this->resolveClockChannelCallback();

        $this->assertFalse((bool) $callback($userA, '2'));
    }

    public function test_clock_channel_authorizes_a_user_from_the_matching_tenant(): void
    {
        $userA = $this->makeUser(1);
        $callback = $this->resolveClockChannelCallback();

        $this->assertTrue((bool) $callback($userA, '1'));
    }
}
