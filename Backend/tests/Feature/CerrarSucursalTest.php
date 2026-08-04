<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Botón "Cerrar sucursal" (decisión de producto P1-P3, 2026-08-03).
 *
 * Lo que el jefe decidió, textual: el cierre se reparte solo (1), lo dispara un BOTÓN del
 * encargado —no la hora— (2), y NO bloquea nada: incidencia de seguimiento, no candado a la
 * salida, PERO con registro formal de quién cerró y cuándo (3).
 *
 * La prueba más importante del archivo es la del no-bloqueo: `status` debe seguir en 'opened'
 * después de declarar el cierre, porque el gate de tienda-cerrada lee esa columna y cambiarla
 * dejaría a los colaboradores de adentro sin poder fichar su salida.
 */
class CerrarSucursalTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 17;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Cierre QA', 'subdomain' => 'cierreqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        return $user->fresh();
    }

    /** Deja la sucursal ABIERTA hoy con $responsable como encargado del día. */
    private function abrirHoy(User $responsable): int
    {
        // Regla del codebase: NUNCA hardcodear store_id — la sucursal del tenant la resuelve
        // TenantStore::defaultIdFor (H15: una empresa escribía su apertura en la sucursal de OTRA).
        $storeId = \App\Helpers\TenantStore::defaultIdFor($this->tenantId);

        return DB::table('store_daily_opening_statuses')->insertGetId([
            'tenant_id' => $this->tenantId, 'company_id' => 1, 'store_id' => $storeId,
            'date' => now()->format('Y-m-d'),
            'scheduled_opening_time' => '09:00', 'pre_opening_window_start' => '08:30',
            'report_deadline' => '09:30',
            'current_responsible_employee_id' => $responsable->id,
            'status' => 'opened', 'opened_by_employee_id' => $responsable->id,
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Crea una rutina de cierre con una tarea, como las que deja el wizard. */
    private function sembrarRutinaDeCierre(): int
    {
        $taskId = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->tenantId, 'title' => 'Activar alarma', 'points' => 10,
            'priority' => 'bloqueante', 'estimated_mins' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rutinaId = DB::table('routines')->insertGetId([
            'tenant_id' => $this->tenantId, 'title' => 'Checklist Diario de Cierre',
            'trigger' => 'cierre',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('routine_task')->insert(['routine_id' => $rutinaId, 'task_id' => $taskId]);

        return $taskId;
    }

    public function test_el_responsable_declara_el_cierre_y_queda_registrado_quien_y_cuando(): void
    {
        $encargado = $this->usuario('empleado');
        $this->abrirHoy($encargado);

        $this->actingAs($encargado)->postJson('/api/v1/store-opening/close')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $fila = DB::table('store_daily_opening_statuses')
            ->where('tenant_id', $this->tenantId)->whereDate('date', now())->first();

        $this->assertSame($encargado->id, (int) $fila->closed_by_employee_id,
            'El registro formal del QUIÉN es la razón de ser de la P3.');
        $this->assertNotNull($fila->closed_at, 'Y el CUÁNDO.');
    }

    public function test_declarar_el_cierre_NO_cambia_el_status_ni_bloquea_a_nadie(): void
    {
        // LA PRUEBA MÁS IMPORTANTE: el gate de tienda-cerrada lee `status`. Si el cierre lo
        // tocara, quien sigue dentro no podría fichar su salida — el candado laboral que el
        // jefe rechazó explícitamente.
        $encargado = $this->usuario('empleado');
        $this->abrirHoy($encargado);

        $this->actingAs($encargado)->postJson('/api/v1/store-opening/close')->assertStatus(200);

        $fila = DB::table('store_daily_opening_statuses')
            ->where('tenant_id', $this->tenantId)->whereDate('date', now())->first();

        $this->assertSame('opened', $fila->status,
            'El cierre registra, no bloquea: status intacto para que la salida siga libre.');
    }

    public function test_declarar_el_cierre_reparte_el_checklist_de_cierre(): void
    {
        $encargado = $this->usuario('empleado');
        $this->abrirHoy($encargado);
        $taskId = $this->sembrarRutinaDeCierre();

        $this->actingAs($encargado)->postJson('/api/v1/store-opening/close')->assertStatus(200);

        $assignment = DB::table('task_assignments')
            ->where('tenant_id', $this->tenantId)->where('task_id', $taskId)->first();

        $this->assertNotNull($assignment, 'El botón debe repartir las rutinas trigger=cierre.');
        $this->assertSame('pending', $assignment->status);
        $this->assertStringStartsWith('close_', $assignment->id,
            'Prefijo propio: idempotente y sin chocar con los open_ de la apertura.');
    }

    public function test_un_empleado_que_no_es_el_responsable_no_puede_cerrar(): void
    {
        $encargado = $this->usuario('empleado');
        $otro = $this->usuario('empleado');
        $this->abrirHoy($encargado);

        $this->actingAs($otro)->postJson('/api/v1/store-opening/close')->assertStatus(422);

        $this->assertNull(
            DB::table('store_daily_opening_statuses')
                ->where('tenant_id', $this->tenantId)->whereDate('date', now())->value('closed_at'),
            'El mismo candado que la apertura: solo el responsable o un mando.'
        );
    }

    public function test_un_mando_puede_cerrar_como_override(): void
    {
        $encargado = $this->usuario('empleado');
        $admin = $this->usuario('admin');
        $this->abrirHoy($encargado);

        $this->actingAs($admin)->postJson('/api/v1/store-opening/close')->assertStatus(200);

        $this->assertSame($admin->id, (int) DB::table('store_daily_opening_statuses')
            ->where('tenant_id', $this->tenantId)->whereDate('date', now())->value('closed_by_employee_id'));
    }

    public function test_no_se_puede_cerrar_dos_veces_el_mismo_dia(): void
    {
        $encargado = $this->usuario('empleado');
        $this->abrirHoy($encargado);
        $this->sembrarRutinaDeCierre();

        $this->actingAs($encargado)->postJson('/api/v1/store-opening/close')->assertStatus(200);
        $respuesta = $this->actingAs($encargado)->postJson('/api/v1/store-opening/close');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya fue cerrada', $respuesta->json('message'));

        $this->assertSame(1,
            DB::table('task_assignments')->where('tenant_id', $this->tenantId)->count(),
            'Reintentar no debe duplicar el checklist.');
    }

    public function test_sin_sucursal_abierta_no_hay_nada_que_cerrar(): void
    {
        $encargado = $this->usuario('empleado');
        // Sin fila del día: getTodayOpeningStatus creará una en 'pending'; cerrar debe fallar.
        $respuesta = $this->actingAs($encargado)->postJson('/api/v1/store-opening/close');

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no está abierta', $respuesta->json('message'));
    }
}
