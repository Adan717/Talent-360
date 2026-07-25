<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\StoreOpeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El state machine de apertura debe calcular la fecha y la hora en la ZONA HORARIA del
 * tenant (system_settings.timezone), no en UTC del servidor.
 *
 * config/app.php usa UTC; antes `getCurrentTimeStr` devolvía `Carbon::now()->format('H:i:s')`
 * y `getTodayOpeningStatus` calculaba "hoy" con `Carbon::now()->format('Y-m-d')`, ambos en
 * UTC. En producción (tenant en América/México, UTC-6) esto desfasa ~6h las ventanas de
 * apertura, el `report_deadline`, el auto-handoff y la fecha del status — misma clase de
 * bug HIGH que la Ronda 6. ClockService (R4) y CloseOrphanShifts (R6) ya usan la tz del
 * tenant; esta ronda alinea el módulo de apertura.
 */
class StoreOpeningTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $sub): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $sub,
            'subdomain' => $sub,
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
    }

    private function setTimezone(int $tenantId, string $tz): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'timezone', 'tenant_id' => $tenantId],
            ['value' => json_encode($tz), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function test_get_current_time_str_uses_tenant_timezone(): void
    {
        // 15:00 UTC. México (UTC-6, sin DST desde 2022) = 09:00 local.
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $tenant = $this->makeTenant('tz-hora');
            $this->setTimezone($tenant->id, 'America/Mexico_City');

            // Modo real (sin time_mode): debe devolver la hora LOCAL, no la de UTC.
            $this->assertSame('09:00:00', app(StoreOpeningService::class)
                ->getCurrentTimeStr(null, $tenant->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_honors_non_default_tenant_timezone(): void
    {
        // Prueba que la tz se LEE de system_settings (no está hardcodeada al default
        // México): 15:00 UTC = 11:00 en Nueva York (EDT, UTC-4 en julio).
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $tenant = $this->makeTenant('tz-ny');
            $this->setTimezone($tenant->id, 'America/New_York');

            $this->assertSame('11:00:00', app(StoreOpeningService::class)
                ->getCurrentTimeStr(null, $tenant->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_get_today_status_date_uses_tenant_timezone(): void
    {
        // 05:30 UTC del 11-jul. México (UTC-6) = 23:30 del 10-jul → la fecha de negocio
        // aún es el 10. Se elige cerca de la medianoche local para que una tz equivocada
        // (p.ej. UTC o -5) diera el 11 y fallara: fija el offset, no solo "una tz oeste".
        Carbon::setTestNow(Carbon::parse('2026-07-11 05:30:00'));
        try {
            $tenant = $this->makeTenant('tz-fecha');
            $this->setTimezone($tenant->id, 'America/Mexico_City');

            $status = app(StoreOpeningService::class)->getTodayOpeningStatus($tenant->id);

            $this->assertSame('2026-07-10', $status->date->format('Y-m-d'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
