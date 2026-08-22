<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreOpeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una sucursal que cerró se puede volver a abrir el mismo día (2026-08-22, prueba en vivo).
 *
 * El día abrió a las 02:47 y el encargado declaró el cierre a las 04:00. A partir de ahí:
 * la pantalla decía SUCURSAL CERRADA (mira el último store_log) y no dejaba fichar a nadie,
 * mientras el servidor se negaba a reabrir con "La tienda ya se encuentra abierta" (miraba
 * status='opened', que el cierre no cambia). La empresa quedaba trabada el resto del día
 * entre dos versiones de la verdad.
 */
class ReaperturaDespuesDelCierreTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $encargado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Reapertura QA', 'subdomain' => 'reaperturaqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->encargado = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Encargado', 'email' => 'encargado@reaperturaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->encargado->id, 'name' => 'Encargado',
            'is_active_employee' => true, 'shiftStart' => '08:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => $this->tenant->id, 'company_id' => 1, 'store_id' => 1,
            'employee_id' => $emp->id, 'priority_order' => 1, 'can_open_store' => true,
            'has_keys' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_se_puede_reabrir_despues_de_declarar_el_cierre(): void
    {
        $servicio = app(StoreOpeningService::class);
        $servicio->openStoreAndClockIn($this->encargado->id);

        $servicio->closeStore($this->encargado->id);
        $status = DB::table('store_daily_opening_statuses')->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($status->closed_at, 'la premisa es que el día quedó cerrado');

        // Y ahora se puede volver a abrir: sin esto la empresa queda trabada el resto del día.
        $servicio->openStoreAndClockIn($this->encargado->id);

        $status = DB::table('store_daily_opening_statuses')->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('opened', $status->status);
        $this->assertNull($status->closed_at, 'la reapertura tiene que limpiar el cierre anterior');
    }

    public function test_sin_cierre_previo_sigue_sin_poder_abrirse_dos_veces(): void
    {
        $servicio = app(StoreOpeningService::class);
        $servicio->openStoreAndClockIn($this->encargado->id);

        $this->expectExceptionMessage('La tienda ya se encuentra abierta.');
        $servicio->openStoreAndClockIn($this->encargado->id);
    }
}
