<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUT /sync/roles/{id} (ClockController::updateJobRole) escribía campos crudos del request sin
 * validar: un payload parcial (p.ej. solo un toggle del reloj checador) pisaba name/area con null
 * — dato corrupto o violación NOT NULL (500). Ahora delega en el CRUD canónico
 * (JobRoleController::update), que valida entradas y respeta `sometimes` (no toca lo ausente).
 */
class SyncRoleUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $sub): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $sub, 'subdomain' => $sub,
            'plan' => 'enterprise', 'is_active' => true,
        ]);
    }

    private function makeAdmin(int $tenantId): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'Admin', 'email' => 'admin' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);
    }

    private function makeJobRole(int $tenantId, string $name): JobRole
    {
        return JobRole::create([
            'tenant_id' => $tenantId, 'name' => $name, 'area' => 'Operaciones',
            'aplicaLeySilla' => false, 'tiempoTolerancia' => 10,
        ]);
    }

    public function test_partial_payload_does_not_wipe_name_and_area(): void
    {
        $tenant = $this->makeTenant('sr-partial');
        $admin = $this->makeAdmin($tenant->id);
        $role = $this->makeJobRole($tenant->id, 'Cajero');

        // Payload parcial: solo togglea un campo del reloj checador, sin name/area.
        $response = $this->actingAs($admin)->putJson("/api/v1/sync/roles/{$role->id}", [
            'aplicaLeySilla' => true,
        ]);

        $response->assertStatus(200);
        $role->refresh();
        // name/area se PRESERVAN (no se pisan a null); el toggle sí se aplica.
        $this->assertSame('Cajero', $role->name);
        $this->assertSame('Operaciones', $role->area);
        $this->assertTrue((bool) $role->aplicaLeySilla);
    }

    public function test_invalid_portador_llaves_is_rejected(): void
    {
        $tenant = $this->makeTenant('sr-enum');
        $admin = $this->makeAdmin($tenant->id);
        $role = $this->makeJobRole($tenant->id, 'Cajero');

        $response = $this->actingAs($admin)->putJson("/api/v1/sync/roles/{$role->id}", [
            'portadorLlaves' => 'valor-invalido',
        ]);

        $response->assertStatus(422);
    }

    public function test_reloj_checador_fields_update(): void
    {
        $tenant = $this->makeTenant('sr-fields');
        $admin = $this->makeAdmin($tenant->id);
        $role = $this->makeJobRole($tenant->id, 'Supervisor');

        $response = $this->actingAs($admin)->putJson("/api/v1/sync/roles/{$role->id}", [
            'tiempoTolerancia' => 25,
            'portadorLlaves' => 'ambos',
            'aplicaLeySilla' => true,
        ]);

        $response->assertStatus(200);
        $role->refresh();
        $this->assertSame(25, (int) $role->tiempoTolerancia);
        $this->assertSame('ambos', $role->portadorLlaves);
        $this->assertTrue((bool) $role->aplicaLeySilla);
    }

    public function test_cannot_report_to_itself(): void
    {
        $tenant = $this->makeTenant('sr-self');
        $admin = $this->makeAdmin($tenant->id);
        $role = $this->makeJobRole($tenant->id, 'Gerente');

        $response = $this->actingAs($admin)->putJson("/api/v1/sync/roles/{$role->id}", [
            'reports_to_role_id' => $role->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_reports_to_ids_array_is_synced(): void
    {
        $tenant = $this->makeTenant('sr-reports');
        $admin = $this->makeAdmin($tenant->id);
        $boss = $this->makeJobRole($tenant->id, 'Jefe');
        $role = $this->makeJobRole($tenant->id, 'Analista');

        $response = $this->actingAs($admin)->putJson("/api/v1/sync/roles/{$role->id}", [
            'reports_to_role_ids' => [$boss->id],
        ]);

        $response->assertStatus(200);
        $role->refresh();
        // El canónico deriva reports_to_role_id del primer elemento del array.
        $this->assertSame($boss->id, (int) $role->reports_to_role_id);
        $this->assertSame([$boss->id], array_map('intval', (array) $role->reports_to_role_ids));
    }

    public function test_response_returns_role_json_not_message(): void
    {
        $tenant = $this->makeTenant('sr-shape');
        $admin = $this->makeAdmin($tenant->id);
        $role = $this->makeJobRole($tenant->id, 'Cajero');

        // Contrato tras delegar: devuelve el rol actualizado (no {message}). Sin consumidores
        // del shape viejo, pero lo fijamos para que un cambio futuro sea deliberado.
        $response = $this->actingAs($admin)->putJson("/api/v1/sync/roles/{$role->id}", [
            'tiempoTolerancia' => 15,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('id', $role->id)
            ->assertJsonPath('tiempoTolerancia', 15);
    }
}
