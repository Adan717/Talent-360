<?php

namespace Tests\Feature;

use App\Helpers\TenantStore;
use App\Models\StoreDailyOpeningStatus;
use App\Models\Tenant;
use App\Services\StoreOpeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * store_daily_opening_statuses: a lo más UN status por (tenant_id, store_id, date).
 *
 * Antes no había constraint único → bajo carrera (o el bug de fecha pre-whereDate de la
 * Ronda 2) se creaban statuses duplicados del mismo día y `getTodayOpeningStatus` elegía
 * uno arbitrario con `->first()`, corrompiendo el estado de apertura (responsable,
 * deadline, opened_at) de forma no determinista.
 */
class StoreOpeningDailyStatusTest extends TestCase
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

    private function insertStatus(int $tenantId, string $date): void
    {
        DB::table('store_daily_opening_statuses')->insert([
            'tenant_id' => $tenantId,
            'company_id' => 1,
            // R52: era `1` hardcodeado. Con la entidad `stores` los ids son globales y cada tenant
            // tiene la suya, así que sembrar un 1 aquí ya no representa "la tienda de este tenant":
            // el servicio resolvería OTRA sucursal, no encontraría esta fila y crearía una segunda.
            'store_id' => TenantStore::defaultIdFor($tenantId),
            // Mismo formato (Y-m-d H:i:s) que escribe el servicio (cast 'date' del modelo /
            // insertOrIgnore), para probar el constraint contra la representación real.
            'date' => Carbon::parse($date)->format('Y-m-d H:i:s'),
            'scheduled_opening_time' => '08:30:00',
            'pre_opening_window_start' => '08:15:00',
            'report_deadline' => '08:20:00',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_duplicate_daily_status_is_rejected(): void
    {
        $tenant = $this->makeTenant('dup-status');
        $this->insertStatus($tenant->id, '2026-07-10');

        // Segunda fila para el mismo (tenant, store, date) → viola el unique.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->insertStatus($tenant->id, '2026-07-10');
    }

    public function test_different_days_and_tenants_are_allowed(): void
    {
        $a = $this->makeTenant('multi-a');
        $b = $this->makeTenant('multi-b');

        // Mismo tenant, días distintos: OK. Distinto tenant, mismo día: OK.
        $this->insertStatus($a->id, '2026-07-10');
        $this->insertStatus($a->id, '2026-07-11');
        $this->insertStatus($b->id, '2026-07-10');

        $this->assertSame(3, DB::table('store_daily_opening_statuses')->count());
    }

    public function test_get_today_status_is_idempotent(): void
    {
        $tenant = $this->makeTenant('idem-status');
        $service = app(StoreOpeningService::class);

        // Dos lecturas del día no deben crear dos filas.
        $first = $service->getTodayOpeningStatus($tenant->id);
        $second = $service->getTodayOpeningStatus($tenant->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StoreDailyOpeningStatus::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->count());
    }

    public function test_get_today_status_reuses_a_preexisting_row(): void
    {
        // Instante fijo, lejos de la medianoche local, para no depender del reloj de CI.
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        try {
            // Fila del día pre-existente. El servicio calcula "hoy" en la tz del tenant
            // (sin timezone propia → default America/Mexico_City), así que sembramos con
            // ESA misma tz para que el whereDate la encuentre y NO cree otra (ni vía
            // insertOrIgnore) — prueba que la relectura matchea la representación real.
            $tenant = $this->makeTenant('preexisting-status');
            $this->insertStatus($tenant->id, Carbon::now('America/Mexico_City')->format('Y-m-d'));

            $status = app(StoreOpeningService::class)->getTodayOpeningStatus($tenant->id);

            $this->assertNotNull($status);
            $this->assertSame($tenant->id, (int) $status->tenant_id);
            $this->assertSame(1, StoreDailyOpeningStatus::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }
}
