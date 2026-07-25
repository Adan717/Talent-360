<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\StoreOpeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Multi-tenancy de `system_settings`: la `key` debe ser única POR TENANT, no global.
 *
 * Antes, `key` era PRIMARY KEY global → dos tenants no podían tener el mismo ajuste
 * (p.ej. `time_mode`, `timezone`, `storeSchedule`): el segundo tenant en guardarlo
 * chocaba con el PK. Y las lecturas sin scope de tenant colapsaban la config entre
 * tenants (un tenant leía el `time_mode` de otro).
 */
class SystemSettingsTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $subdomain): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $subdomain,
            'subdomain' => $subdomain,
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
    }

    /**
     * Escribe un ajuste con el MISMO patrón que producción
     * (ClockController::syncSettings, TenantInitializationService): match compuesto
     * por (key, tenant_id).
     */
    private function putSetting(int $tenantId, string $key, string $jsonValue): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key, 'tenant_id' => $tenantId],
            ['value' => $jsonValue, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function test_two_tenants_can_hold_the_same_setting_key(): void
    {
        $a = $this->makeTenant('empresa-a');
        $b = $this->makeTenant('empresa-b');

        $this->putSetting($a->id, 'time_mode', '"simulated"');
        $this->putSetting($b->id, 'time_mode', '"real"');

        // Ambas filas coexisten con su propio valor (antes: violación de PK sobre `key`
        // al insertar la segunda).
        $this->assertSame('"simulated"', DB::table('system_settings')
            ->where('tenant_id', $a->id)->where('key', 'time_mode')->value('value'));
        $this->assertSame('"real"', DB::table('system_settings')
            ->where('tenant_id', $b->id)->where('key', 'time_mode')->value('value'));
        $this->assertSame(2, DB::table('system_settings')->where('key', 'time_mode')->count());
    }

    public function test_store_opening_reads_only_its_own_tenant_time_mode(): void
    {
        // Hora real fija = 15:00:00 (tz app = UTC), distinta de la hora simulada 10:00:00
        // para que el assert sea determinista.
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $a = $this->makeTenant('empresa-sim');
            $b = $this->makeTenant('empresa-real');

            // Solo el tenant A está en modo simulado. B no tiene ajuste propio.
            $this->putSetting($a->id, 'time_mode', '"simulated"');

            $service = app(StoreOpeningService::class);
            $simTime = '10:00:00';

            // A (simulado) honra la hora simulada.
            $this->assertSame($simTime, $service->getCurrentTimeStr($simTime, $a->id));

            // B NO hereda el modo simulado de A: usa la hora real, no la simulada
            // (10:00:00). Sin el scope por tenant, el pluck global veía el `time_mode` de A
            // y filtraba config entre tenants. La hora real se calcula en la tz del tenant
            // (R9): B sin timezone propia cae al default America/Mexico_City (UTC-6), así
            // que 15:00 UTC = 09:00 local.
            $this->assertSame('09:00:00', $service->getCurrentTimeStr($simTime, $b->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_platform_settings_stay_unique_when_tenant_is_null(): void
    {
        // La config de PLATAFORMA (tenant_id NULL) sigue siendo única por key. El UNIQUE
        // compuesto (tenant_id, key) no lo garantiza (los NULL son distintos), lo respalda
        // el índice único parcial `... WHERE tenant_id IS NULL`. Antes lo daba el PK global.
        DB::table('system_settings')->insert([
            'tenant_id' => null,
            'key' => 'global_trial_days',
            'value' => '14',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('system_settings')->insert([
            'tenant_id' => null,
            'key' => 'global_trial_days',
            'value' => '30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
