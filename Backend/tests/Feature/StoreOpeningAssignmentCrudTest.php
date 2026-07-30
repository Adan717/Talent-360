<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H19 (tercera jornada de regresión 2026-07-30): **asignar un portador de llaves siempre
 * devolvía 500**, así que la jerarquía de apertura NUNCA se pudo configurar por la API.
 *
 * Los tres endpoints de gestión hacían `('employee:id,name,email,role,user_id')`, pero
 * `employees` no tiene columna `role` — tiene `job_role_id`. Postgres respondía
 * `SQLSTATE[42703]: Undefined column: role` y el controlador moría con "Server Error".
 *
 * Consecuencia en cadena, que explica un patrón que llevaba tres jornadas apareciendo sin que se
 * entendiera: sin asignaciones, la apertura del día se crea SIN responsable y el sistema estampa
 * `failed_no_responsibles`. Con eso muerto quedaban también:
 *   - toda la cascada de delegación de llaves (reportar falta/retardo, traspaso al suplente),
 *   - el botón "Llamar a Encargado de Llaves" (R100), que nunca tenía a quién llamar,
 *   - el checklist y el pase de lista de apertura, que sólo se activan para el responsable.
 *
 * `getAssignments` no saltaba con la lista vacía porque Eloquent no ejecuta el eager-load si no
 * hay filas — por eso el listado "funcionaba" mientras el módulo estaba roto.
 */
class StoreOpeningAssignmentCrudTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Empresa', 'subdomain' => 't2',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function colaborador(string $rol = 'empleado', string $puesto = 'Cajera'): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        $puestoId = DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenantId, 'name' => $puesto, 'area' => 'Operaciones',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'job_role_id' => $puestoId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    public function test_asignar_un_portador_de_llaves_funciona(): void
    {
        $admin = $this->colaborador('admin');
        $encargado = $this->colaborador('supervisor', 'Supervisor');

        // EL CASO DEL BUG: esto devolvía 500 por una columna inexistente en el eager-load.
        $res = $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'user_id' => $encargado->id,
            'priority_order' => 1,
            'can_open_store' => true,
            'has_keys' => true,
            'is_active' => true,
        ]);

        $res->assertStatus(200);

        $empleadoId = DB::table('employees')->where('user_id', $encargado->id)->value('id');
        $this->assertDatabaseHas('store_opening_assignments', [
            'tenant_id' => $this->tenantId,
            'employee_id' => $empleadoId,
            'priority_order' => 1,
            'can_open_store' => true,
        ]);
    }

    public function test_el_listado_no_revienta_cuando_YA_hay_asignaciones(): void
    {
        // El listado "funcionaba" sólo con la lista vacía: sin filas, Eloquent ni ejecuta el
        // eager-load roto. Con una asignación de verdad es cuando salta.
        $admin = $this->colaborador('admin');
        $encargado = $this->colaborador('supervisor', 'Supervisor');

        $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'user_id' => $encargado->id, 'priority_order' => 1, 'can_open_store' => true,
        ])->assertStatus(200);

        $res = $this->actingAs($admin)->getJson('/api/v1/store-opening/assignments');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json());
    }

    public function test_la_respuesta_trae_los_datos_del_colaborador(): void
    {
        $admin = $this->colaborador('admin');
        $encargado = $this->colaborador('supervisor', 'Jefe de Piso');

        $res = $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'user_id' => $encargado->id, 'priority_order' => 1, 'can_open_store' => true,
        ]);

        // El panel necesita saber A QUIÉN asignó, no sólo que se guardó.
        $res->assertStatus(200);
        $this->assertSame($encargado->name, $res->json('assignment.employee.name'));
        $this->assertSame($encargado->email, $res->json('assignment.employee.email'));

        // Y con qué PUESTO: el panel lo pinta debajo del nombre. Pedía `employee.role`, que no
        // existe; en sqlite eso devolvía la cadena literal "role" y en Postgres reventaba.
        // Ojo con la clave: la relación se carga como `jobRole` pero Eloquent la SERIALIZA en
        // snake_case, así que al frontend le llega `job_role`.
        $this->assertSame('Jefe de Piso', $res->json('assignment.employee.job_role.name'));
    }

    public function test_actualizar_una_asignacion_funciona(): void
    {
        $admin = $this->colaborador('admin');
        $encargado = $this->colaborador('supervisor', 'Supervisor');

        $id = $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'user_id' => $encargado->id, 'priority_order' => 1, 'can_open_store' => true,
        ])->json('assignment.id');

        // Quitarle el permiso de abrir es la acción que R46 hizo REAL: antes era decorativa.
        $res = $this->actingAs($admin)->putJson("/api/v1/store-opening/assignments/{$id}", [
            'can_open_store' => false,
        ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('store_opening_assignments', [
            'id' => $id, 'can_open_store' => false,
        ]);
    }

    public function test_no_se_puede_asignar_a_alguien_de_otra_empresa(): void
    {
        $admin = $this->colaborador('admin');

        DB::table('tenants')->insertOrIgnore([
            'id' => 3, 'name' => 'Otra', 'subdomain' => 't3', 'plan' => 'basic',
            'max_users' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ajeno = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $ajeno->id)->update(['tenant_id' => 3]);
        DB::table('employees')->insert([
            'tenant_id' => 3, 'user_id' => $ajeno->id, 'name' => $ajeno->name,
            'email' => $ajeno->email, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($admin)->postJson('/api/v1/store-opening/assignments', [
            'user_id' => $ajeno->id, 'priority_order' => 1, 'can_open_store' => true,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('store_opening_assignments', ['tenant_id' => $this->tenantId, 'priority_order' => 1]);
    }
}
