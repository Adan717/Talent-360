<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H6 (prueba en vivo 2026-07-29): `ClockService` ya dejaba fichar pese al Retardo Extremo
 * cuando existe una solicitud `approved` para el usuario y la fecha, pero el dial no conocía
 * ese estado y seguía mostrando "ACCESO BLOQUEADO / TOLERANCIA VENCIDA" — el colaborador
 * autorizado no podía registrar su entrada aunque el servidor sí se lo permitía.
 *
 * `/sync/state` expone ahora `late_authorized_user_ids` para que el dial levante el bloqueo.
 * El corte del día usa la zona horaria del TENANT (mismo criterio que el resto de lo fechado).
 */
class LateAuthorizedUserIdsInStateTest extends TestCase
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

    private function makeUser(int $tenantId = 1, string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        return $user->fresh();
    }

    private function solicitud(int $userId, string $status, string $date, int $tenantId = 1): void
    {
        DB::table('late_authorization_requests')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'date' => $date,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hoyDelTenant(int $tenantId = 1): string
    {
        return Carbon::now(\App\Helpers\TenantTimezone::for($tenantId))->toDateString();
    }

    public function test_expone_los_ids_con_autorizacion_aprobada_de_hoy(): void
    {
        $actor = $this->makeUser();
        $autorizado = $this->makeUser();
        $this->solicitud($autorizado->id, 'approved', $this->hoyDelTenant());

        $res = $this->actingAs($actor)->getJson('/api/v1/sync/state');

        $res->assertStatus(200);
        $this->assertContains($autorizado->id, $res->json('late_authorized_user_ids'));
    }

    public function test_no_expone_las_pendientes_ni_las_rechazadas(): void
    {
        $actor = $this->makeUser();
        $pendiente = $this->makeUser();
        $rechazado = $this->makeUser();
        $this->solicitud($pendiente->id, 'pending', $this->hoyDelTenant());
        $this->solicitud($rechazado->id, 'rejected', $this->hoyDelTenant());

        $ids = $this->actingAs($actor)->getJson('/api/v1/sync/state')->json('late_authorized_user_ids');

        $this->assertNotContains($pendiente->id, $ids);
        $this->assertNotContains($rechazado->id, $ids);
    }

    public function test_no_arrastra_la_autorizacion_de_ayer(): void
    {
        $actor = $this->makeUser();
        $ayer = $this->makeUser();
        $this->solicitud($ayer->id, 'approved', Carbon::parse($this->hoyDelTenant())->subDay()->toDateString());

        $ids = $this->actingAs($actor)->getJson('/api/v1/sync/state')->json('late_authorized_user_ids');

        $this->assertNotContains($ayer->id, $ids);
    }

    public function test_no_filtra_autorizaciones_de_otro_tenant(): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'Otra', 'subdomain' => 't2', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $actor = $this->makeUser();
        $ajeno = $this->makeUser(2);
        $this->solicitud($ajeno->id, 'approved', $this->hoyDelTenant(), 2);

        $ids = $this->actingAs($actor)->getJson('/api/v1/sync/state')->json('late_authorized_user_ids');

        $this->assertNotContains($ajeno->id, $ids);
    }
}
