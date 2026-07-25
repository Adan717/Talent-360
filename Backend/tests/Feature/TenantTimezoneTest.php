<?php

namespace Tests\Feature;

use App\Helpers\TenantTimezone;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Resolver de tz compartido. Unifica lo que estaba triplicado en ClockService /
 * CloseOrphanShifts / StoreOpeningService con robustez desigual. Casos clave: acepta el
 * valor json-encoded (barra escapada) —que la versión ingenua de ClockService dejaba caer
 * al default— y valida contra DateTimeZone (lo que a CloseOrphanShifts le faltaba).
 */
class TenantTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_settings_accepts_raw_value(): void
    {
        // ClockController::syncSettings guarda strings crudos (sin json_encode).
        $this->assertSame('America/New_York', TenantTimezone::fromSettings(['timezone' => 'America/New_York']));
    }

    public function test_from_settings_accepts_json_encoded_value(): void
    {
        // json_encode escapa la barra: "America\/New_York". La versión ingenua (trim de comillas)
        // devolvía "America\/New_York" (inválida) y caía al default; aquí se decodifica bien.
        $encoded = json_encode('America/New_York'); // => "\"America\\/New_York\""
        $this->assertSame('America/New_York', TenantTimezone::fromSettings(['timezone' => $encoded]));
    }

    public function test_from_settings_falls_back_on_invalid_zone(): void
    {
        $this->assertSame(TenantTimezone::DEFAULT, TenantTimezone::fromSettings(['timezone' => 'Foo/Bar']));
    }

    public function test_from_settings_falls_back_when_missing_or_empty(): void
    {
        $this->assertSame(TenantTimezone::DEFAULT, TenantTimezone::fromSettings([]));
        $this->assertSame(TenantTimezone::DEFAULT, TenantTimezone::fromSettings(['timezone' => '']));
    }

    public function test_for_reads_only_its_own_tenant(): void
    {
        $a = Tenant::create(['name' => 'A', 'subdomain' => 'tz-a', 'plan' => 'enterprise', 'is_active' => true]);
        $b = Tenant::create(['name' => 'B', 'subdomain' => 'tz-b', 'plan' => 'enterprise', 'is_active' => true]);

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'timezone', 'tenant_id' => $a->id],
            ['value' => 'America/Cancun', 'updated_at' => now(), 'created_at' => now()]
        );

        // A tiene su tz; B no la definió → cada uno resuelve lo suyo (B al default, sin heredar A).
        $this->assertSame('America/Cancun', TenantTimezone::for($a->id));
        $this->assertSame(TenantTimezone::DEFAULT, TenantTimezone::for($b->id));
    }
}
