<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La tarea "sentado" nace con cada empresa nueva (2026-08-22).
 *
 * La migración 2026_07_22_000003 la sembró en las empresas que existían ese día; las nuevas
 * no la tenían, y el modal de Ley Silla se quedaba en "No hay tareas configuradas para tomar
 * sentado todavía" con un único botón: "Cancelar descanso". El descanso que la ley garantiza
 * era imposible en toda empresa creada después del 22 de julio.
 */
class TareaSentadaNaceConLaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_empresa_nueva_tiene_una_tarea_para_hacer_sentado(): void
    {
        $tenant = Tenant::create(['name' => 'Silla QA', 'subdomain' => 'sillaqa', 'plan' => 'enterprise', 'is_active' => true]);

        $this->assertDatabaseHas('tasks', [
            'tenant_id' => $tenant->id,
            'title' => 'Monitoreo de seguridad desde silla',
            'can_be_done_sitting' => true,
        ]);

        // Y no se duplica si la inicialización corre dos veces.
        app(\App\Services\TenantInitializationService::class)->seedSeatedTask($tenant->id);
        $this->assertSame(1, DB::table('tasks')->where('tenant_id', $tenant->id)->where('can_be_done_sitting', true)->count());
    }
}
