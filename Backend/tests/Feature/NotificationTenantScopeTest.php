<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Reloj R69 (fuga cross-tenant cazada por auditoría): `NotificationService::sendToRole` seleccionaba
 * destinatarios SÓLO por `role`, sin `tenant_id` → las alertas de cesión/fallo de apertura
 * (StoreOpeningHandoffService) se difundían a los admin/supervisor de TODAS las empresas (estado
 * operativo + nombre del empleado = PII). Ahora `sendToRole` exige `tenant_id` y filtra por él.
 */
class NotificationTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(int $tenantId, string $token): void
    {
        $u = User::create([
            'tenant_id' => $tenantId, 'name' => 'Admin', 'email' => 'a' . uniqid() . '@t.local',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        // fcm_token no es fillable → set directo.
        DB::table('users')->where('id', $u->id)->update(['fcm_token' => $token]);
    }

    public function test_send_to_role_no_cruza_tenants(): void
    {
        $tA = Tenant::create(['name' => 'A', 'subdomain' => 'a' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $tB = Tenant::create(['name' => 'B', 'subdomain' => 'b' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);

        $this->makeAdmin($tA->id, 'tok-A');        // 1 admin en A
        $this->makeAdmin($tB->id, 'tok-B1');       // 2 admins en B
        $this->makeAdmin($tB->id, 'tok-B2');

        // El paquete de Firebase no está en este entorno → FCM se simula y cada destinatario deja un
        // log "Sending FCM Notification to token [X]". Se cuenta cuántos y a quién.
        Log::spy();

        (new NotificationService())->sendToRole($tA->id, 'admin', 'Título', 'Cuerpo');

        // Exactamente UN destinatario (el admin del tenant A); sin el filtro serían 3 (con los 2 de B).
        Log::shouldHaveReceived('info')
            ->withArgs(fn($m, $c = []) => is_string($m) && str_starts_with($m, 'Sending FCM Notification'))
            ->once();
        // Y ese destinatario es el token del tenant A, no los de B.
        Log::shouldHaveReceived('info')
            ->withArgs(fn($m, $c = []) => is_string($m) && str_contains($m, '[tok-A]'))
            ->once();
        Log::shouldNotHaveReceived('info',
            [\Mockery::on(fn($m) => is_string($m) && (str_contains($m, '[tok-B1]') || str_contains($m, '[tok-B2]')))]);
    }
}
