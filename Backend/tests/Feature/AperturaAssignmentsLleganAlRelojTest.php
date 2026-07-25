<?php

namespace Tests\Feature;

use App\Helpers\TenantStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda 50 (Reloj): las asignaciones de apertura deben LLEGAR al empleado.
 *
 * Hermano de R47 (ajustes) y del mismo patrón: "config que el admin edita y no llega a la lógica".
 *
 * `useClockEngine.tsx:3545` SÍ hace `GET /store-opening/assignments` en la rama de producción, pero
 * esa ruta vive en el grupo `role:admin,supervisor` (api.php:134 envolviendo la 226) → el empleado
 * recibe **403** y el `catch` sólo hace `console.error`, así que la llave de localStorage
 * `store_opening_assignments` **nunca se escribe** para un empleado. Verificado en vivo contra
 * Postgres con el Cajero del tenant 3: `assignments` → 403, `today` → 200.
 *
 * Consumidores de producción que se quedaban sin datos:
 *  - `RelojVisual.tsx:1414` → el empleado ve "Apertura por: Encargado" en vez del nombre real.
 *  - `RelojVisual.tsx:1588` (`getUserKeysIcon`) → NO está gateado por sandbox, así que caía a una
 *    lista FALSA hardcodeada (ids 11/12/13): el responsable real no mostraba 🔑.
 *  - `useClockEngine.tsx:2127` (`isSuplente`) → no podía derivarse de la prioridad.
 *
 * Fix (mismo precedente que R47): servirlas ya scrubbeadas en `GET /store-opening/today`, el endpoint
 * que el motor YA consulta cada 5s y que SÍ es accesible al empleado (grupo `role:empleado,…`,
 * api.php:255). La ruta admin se queda admin.
 *
 * OJO con los dos espacios de ID (la trampa que documenta `StoreOpeningService:107-108`):
 * `assignments.employee_id` es FK a **employees**, mientras `current_responsible_employee_id` es FK a
 * **users**. El front compara ambos entre sí (`RelojVisual:1416`) y llama a `getUserKeysIcon` con un
 * `users.id` (`RelojVisual:2023,3189`), así que el payload DEBE traer `user_id` o el casamiento
 * seguiría fallando aunque los datos llegaran.
 */
class AperturaAssignmentsLleganAlRelojTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant(1, 'tenant-assignments');
        // Desalinea users.id vs employees.id (como en producción): si ambos espacios de ID
        // coincidieran, un casamiento incorrecto pasaría el test por accidente.
        User::factory()->create(['role' => 'empleado']);
        User::factory()->create(['role' => 'empleado']);
    }

    private function seedTenant(int $id, string $slug): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => $id,
            'name' => 'Tenant ' . $id,
            'subdomain' => $slug,
            'public_slug' => $slug,
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEmployeeWithUser(string $name, int $tenantId = 1): array
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . $user->id . '@t.local',
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [
            'user' => User::withoutGlobalScopes()->find($user->id),
            'employee_id' => $employeeId,
        ];
    }

    private function makeAssignment(int $employeeId, int $priority, bool $canOpen = true, int $tenantId = 1): void
    {
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => $tenantId,
            'company_id' => 1,
            // R52: la sucursal debe ser la del tenant de la fila. Un `1` fijo apuntaría a la del
            // tenant 1 desde cualquier otro tenant (la FK lo aceptaría: el id existe).
            'store_id' => TenantStore::defaultIdFor($tenantId),
            'employee_id' => $employeeId,
            'priority_order' => $priority,
            'can_open_store' => $canOpen,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El caso real del tenant 3: el Cajero abre el reloj con la tienda cerrada y debe poder ver
     * QUIÉN abre. Antes recibía 403 en `/store-opening/assignments` y se quedaba sin nada.
     */
    public function test_today_devuelve_las_asignaciones_al_empleado(): void
    {
        $gerente = $this->makeEmployeeWithUser('Gerente Sucursal E2E');
        $cajero = $this->makeEmployeeWithUser('Cajero E2E');
        $this->makeAssignment($gerente['employee_id'], 1);

        $response = $this->actingAs($cajero['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $assignments = $response->json('assignments');

        $this->assertIsArray($assignments, 'el reloj debe recibir las asignaciones; antes el empleado tenía 403');
        $this->assertCount(1, $assignments);
        $this->assertSame('Gerente Sucursal E2E', $assignments[0]['name'], 'el nombre debe venir APLANADO: el front lee `a.name`, no `a.employee.name`');
        $this->assertSame(1, (int) $assignments[0]['priority_order']);
        $this->assertTrue((bool) $assignments[0]['can_open_store']);
        $this->assertTrue((bool) $assignments[0]['is_active']);
        // `has_keys` se dropeó en R98 (columna-lápida); el payload del reloj no debe reintroducirla.
        $this->assertArrayNotHasKey('has_keys', $assignments[0]);
    }

    /**
     * Empleado HUÉRFANO (expediente sin usuario vinculado; `employees.user_id` es nullable con
     * onDelete('set null'), ver R40). Debe viajar con `user_id => null` y NO omitirse la llave: el
     * front distingue "null" (huérfano, no casa con nadie) de "la llave no viene" (mock del sandbox,
     * cae a employee_id). Si aquí se omitiera, el front trataría su employee_id como un users.id.
     */
    public function test_el_empleado_huerfano_viaja_con_user_id_null(): void
    {
        $cajero = $this->makeEmployeeWithUser('Cajero Testigo');
        $huerfanoId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'user_id' => null, // sin usuario vinculado
            'name' => 'Huérfano Sin Usuario',
            'email' => 'huerfano@t.local',
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->makeAssignment($huerfanoId, 1);

        $response = $this->actingAs($cajero['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $huerfano = collect($response->json('assignments'))->firstWhere('employee_id', $huerfanoId);

        $this->assertNotNull($huerfano, 'la asignación del huérfano debe seguir viajando');
        $this->assertArrayHasKey('user_id', $huerfano, 'la llave DEBE existir aunque valga null: su ausencia significa otra cosa');
        $this->assertNull($huerfano['user_id']);
    }

    /**
     * La regresión que importa: el front casa las asignaciones contra
     * `current_responsible_employee_id`, que es un **users.id**. Sin `user_id` en el payload el
     * casamiento falla aunque los datos lleguen (y `employee_id` NO sirve: es otro espacio de ID).
     */
    public function test_el_user_id_permite_casar_con_el_responsable_del_dia(): void
    {
        $gerente = $this->makeEmployeeWithUser('Gerente Con Llave');
        $cajero = $this->makeEmployeeWithUser('Cajero Mirón');
        $this->makeAssignment($gerente['employee_id'], 1);

        $response = $this->actingAs($cajero['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $responsibleId = $response->json('status.current_responsible_employee_id');
        $assignments = $response->json('assignments');

        $this->assertSame((int) $gerente['user']->id, (int) $responsibleId);
        $this->assertSame(
            (int) $gerente['user']->id,
            (int) $assignments[0]['user_id'],
            'el payload debe traer el users.id para poder casar con current_responsible_employee_id'
        );
        $this->assertNotSame(
            (int) $assignments[0]['user_id'],
            (int) $assignments[0]['employee_id'],
            'los dos espacios de ID deben estar desalineados en este test, o no prueba nada'
        );
    }

    /**
     * Se sirve a TODO empleado, así que sólo debe viajar lo que el reloj pinta (nombre + prioridad +
     * llaves). Nada de email ni de fontanería interna del tenant.
     */
    public function test_las_asignaciones_no_exponen_datos_sensibles(): void
    {
        $gerente = $this->makeEmployeeWithUser('Gerente Privado');
        $cajero = $this->makeEmployeeWithUser('Cajero Curioso');
        $this->makeAssignment($gerente['employee_id'], 1);

        $response = $this->actingAs($cajero['user'])->getJson('/api/v1/store-opening/today');

        $assignment = $response->json('assignments')[0];

        $this->assertArrayNotHasKey('email', $assignment);
        $this->assertArrayNotHasKey('employee', $assignment, 'no debe viajar el modelo anidado con el email dentro');
        $this->assertArrayNotHasKey('tenant_id', $assignment);
        $this->assertArrayNotHasKey('company_id', $assignment);
        $this->assertStringNotContainsString('@', json_encode($assignment), 'ningún correo debe filtrarse al reloj');
    }

    /**
     * Aislamiento: las asignaciones de otro tenant no deben filtrarse.
     */
    public function test_las_asignaciones_son_del_tenant_del_usuario(): void
    {
        $this->seedTenant(2, 'otro-assignments');
        $ajeno = $this->makeEmployeeWithUser('Gerente Ajeno', 2);
        $this->makeAssignment($ajeno['employee_id'], 1, true, 2);

        $gerente = $this->makeEmployeeWithUser('Gerente Propio');
        $cajero = $this->makeEmployeeWithUser('Cajero Propio');
        $this->makeAssignment($gerente['employee_id'], 1);

        $response = $this->actingAs($cajero['user'])->getJson('/api/v1/store-opening/today');

        $assignments = $response->json('assignments');
        $this->assertCount(1, $assignments);
        $this->assertSame('Gerente Propio', $assignments[0]['name']);
    }

    /**
     * Sin asignaciones el reloj debe recibir una lista VACÍA, no `null` ni la ausencia de la llave:
     * el front hace `.filter`/`.find` sobre esto.
     */
    public function test_sin_asignaciones_devuelve_lista_vacia(): void
    {
        $cajero = $this->makeEmployeeWithUser('Cajero Solo');

        $response = $this->actingAs($cajero['user'])->getJson('/api/v1/store-opening/today');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('assignments'));
        $this->assertCount(0, $response->json('assignments'));
    }
}
