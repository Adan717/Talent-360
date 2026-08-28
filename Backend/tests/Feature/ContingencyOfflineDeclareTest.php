<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R101 (cierre del spec 2/3): Declarar Eventualidad estando OFFLINE (spec §4 paso 4).
 *
 * El follow-up abierto de R83: la declaración de contingencia exigía red — con la sucursal sin luz
 * ni internet (el caso EXACTO del spec), el POST fallaba y la declaración se perdía. Ahora el FE la
 * encola localmente y la reenvía al reconectar, mandando `declared_at_client` (cuándo se declaró de
 * verdad). El backend deriva la FECHA de la contingencia de ese momento — sin él, una declaración
 * de las 23:50 sincronizada a las 00:10 caería en el día EQUIVOCADO (la jornada sin luz fue AYER).
 *
 * Anti-forjado: `declared_at_client` se CLAMPEA server-side — sólo se acepta hacia atrás (nunca
 * fechas futuras) y máximo 48h; fuera de eso, hoy. Y el efecto de nómina sigue detrás del gate
 * humano de R83: un admin debe APROBAR la contingencia para que pague al 100%.
 */
class ContingencyOfflineDeclareTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(string $tz = 'UTC'): array
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Offline', 'subdomain' => 'offline' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colaborador Offline',
            'email' => 'off' . uniqid() . '@t.local', 'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colaborador Offline',
            'base_salary' => 3000.00, 'shiftStart' => '09:00:00', 'restDay' => 'Domingo',
            'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        // updateOrInsert: desde 2026-08-27 toda empresa NACE con su zona horaria escrita
        // (punto 1 de la revisión externa), así que un insert plano choca con el índice único.
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'key' => 'timezone'],
                ['value' => json_encode($tz), 'created_at' => now(), 'updated_at' => now()]
            );
        return [$tenant, $user];
    }

    private function declarar(User $user, array $payload)
    {
        return $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', array_merge([
            'reason' => 'Corte de energía en toda la plaza (CFE), sin red.',
        ], $payload));
    }

    public function test_sin_declared_at_client_usa_hoy_como_siempre(): void
    {
        [$tenant, $user] = $this->makeSetup();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        try {
            $this->declarar($user, [])->assertStatus(201);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('contingency_days', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'date' => '2026-07-15', 'status' => 'pending',
        ]);
    }

    public function test_declaracion_offline_de_ayer_cae_en_la_fecha_de_ayer(): void
    {
        [$tenant, $user] = $this->makeSetup();
        // La luz se fue ayer 18:40; el empleado declaró en su celular sin red; la cola drenó hoy.
        Carbon::setTestNow(Carbon::parse('2026-07-15 09:00:00'));
        try {
            $this->declarar($user, ['declared_at_client' => '2026-07-14T18:40:00Z'])->assertStatus(201);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('contingency_days', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'date' => '2026-07-14', 'status' => 'pending',
        ]);
    }

    public function test_fecha_futura_se_clampea_a_hoy(): void
    {
        [$tenant, $user] = $this->makeSetup();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        try {
            $this->declarar($user, ['declared_at_client' => '2026-07-20T09:00:00Z'])->assertStatus(201);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('contingency_days', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-15',
        ]);
    }

    public function test_mas_viejo_que_48h_se_clampea_a_hoy(): void
    {
        [$tenant, $user] = $this->makeSetup();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        try {
            $this->declarar($user, ['declared_at_client' => '2026-07-01T09:00:00Z'])->assertStatus(201);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('contingency_days', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-15',
        ]);
        $this->assertDatabaseMissing('contingency_days', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-01',
        ]);
    }

    public function test_la_fecha_es_en_tz_del_tenant_no_utc(): void
    {
        // 2026-07-15 03:00 UTC = 2026-07-14 21:00 en CDMX → la contingencia es del 14 local.
        [$tenant, $user] = $this->makeSetup('America/Mexico_City');
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        try {
            $this->declarar($user, ['declared_at_client' => '2026-07-15T03:00:00Z'])->assertStatus(201);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('contingency_days', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'date' => '2026-07-14',
        ]);
    }

    public function test_declared_at_client_invalido_da_422(): void
    {
        [, $user] = $this->makeSetup();
        $this->declarar($user, ['declared_at_client' => 'no-es-fecha'])->assertStatus(422);
    }
}
