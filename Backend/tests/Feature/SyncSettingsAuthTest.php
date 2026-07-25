<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POST /sync/settings (ClockController::syncSettings) escribe configuración de EMPRESA
 * (clockOpConfig, active_modules, storeSchedule, …). Vive en el grupo `role:empleado,…`,
 * así que sin gate de rol un empleado podía apagar la geocerca/IP-lock server-side de todo
 * el tenant (o tocar cualquier setting). Ahora solo admin/supervisor/platform_admin pueden.
 */
class SyncSettingsAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa Cfg', 'subdomain' => 'cfg-' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
    }

    private function makeUser(int $tenantId, string $role): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role),
            'email' => $role . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => $role,
        ]);
    }

    public function test_employee_cannot_write_settings(): void
    {
        $tenant = $this->makeTenant();
        $employee = $this->makeUser($tenant->id, 'empleado');

        // Intento de apagar la geocerca de todo el tenant.
        $response = $this->actingAs($employee)->postJson('/api/v1/sync/settings', [
            'key' => 'clockOpConfig',
            'value' => ['gpsValidationEnabled' => false],
        ]);

        $response->assertStatus(403);
        // El observer del Tenant siembra un clockOpConfig por defecto, así que la fila existe;
        // lo que importa es que el intento del empleado NO haya escrito su payload malicioso.
        $stored = DB::table('system_settings')
            ->where('tenant_id', $tenant->id)->where('key', 'clockOpConfig')->value('value');
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;
        $this->assertNotSame(false, $decoded['gpsValidationEnabled'] ?? null, 'el empleado no debe poder apagar la geocerca');
    }

    public function test_admin_can_write_settings(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant->id, 'admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/sync/settings', [
            'key' => 'clockOpConfig',
            'value' => ['gpsValidationEnabled' => true],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('system_settings', [
            'tenant_id' => $tenant->id, 'key' => 'clockOpConfig',
        ]);
    }

    public function test_supervisor_can_write_settings(): void
    {
        $tenant = $this->makeTenant();
        $supervisor = $this->makeUser($tenant->id, 'supervisor');

        $response = $this->actingAs($supervisor)->postJson('/api/v1/sync/settings', [
            'active_modules' => ['reloj', 'rrhh'],
        ]);

        $response->assertStatus(200);
    }
}
