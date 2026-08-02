<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskValidationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H26 — ¿se exige de verdad la firma del supervisor?
 *
 * `TaskValidationPolicy::requiresValidation` resuelve el puesto del colaborador con
 * `JobRole::find($id)`, **sin** `withoutGlobalScopes()`. `JobRole` lleva `TenantScope`, así que
 * fuera de una petición HTTP autenticada —comandos de consola, jobs en cola— ese `find` devuelve
 * `null`, la política concluye que el colaborador "no reporta a nadie" y **deja pasar la tarea sin
 * validación**.
 *
 * Comprobado en el servidor:
 *
 *     find(4) normal     : NULL
 *     find(4) sin scopes : 'Asesor de Ventas'
 *
 * El resto del módulo ya usa el patrón contrario a propósito ("lectura directa con filtro
 * explícito de tenant, no depende del scope"); aquí se había quedado la versión frágil.
 *
 * Estos tests fijan las dos direcciones: que la validación se EXIJA cuando toca, y que un puesto
 * sin supervisor siga sin exigirla (que es el comportamiento correcto: no hay a quién pedirle la
 * firma).
 */
class ValidacionSupervisorSeExigeTest extends TestCase
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

    private function puesto(string $nombre, ?int $reportaA = null): int
    {
        return DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenantId, 'name' => $nombre, 'area' => 'Operaciones',
            'reports_to_role_id' => $reportaA,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function colaborador(int $puestoId): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'job_role_id' => $puestoId, 'base_salary' => 9000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user->fresh();
    }

    private function tareaConFirma(): Task
    {
        $id = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->tenantId, 'title' => 'Arqueo de caja fuerte',
            'points' => 30, 'validation_mode' => 'forced', 'priority' => 'normal',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Task::withoutGlobalScopes()->find($id);
    }

    public function test_un_puesto_CON_supervisor_exige_la_firma(): void
    {
        $jefe = $this->puesto('Gerente');
        $ana = $this->colaborador($this->puesto('Cajera', $jefe));

        $this->assertTrue(
            TaskValidationPolicy::requiresValidation($this->tenantId, $ana->id, $this->tareaConFirma()),
            'Con supervisor asignado y modo forzado, la tarea debe esperar firma.'
        );
    }

    public function test_un_puesto_SIN_supervisor_no_la_exige(): void
    {
        // Correcto por diseño: no hay a quién pedirle la firma.
        $ana = $this->colaborador($this->puesto('Gerente General'));

        $this->assertFalse(
            TaskValidationPolicy::requiresValidation($this->tenantId, $ana->id, $this->tareaConFirma())
        );
    }

    public function test_la_regla_NO_depende_del_contexto_de_ejecucion(): void
    {
        // EL CASO DEL BUG: la política se consulta también desde comandos y jobs en cola, donde
        // no hay sesión y `JobRole::find()` cae al TenantScope y devuelve null. La respuesta debe
        // ser la misma que dentro de una petición.
        $jefe = $this->puesto('Gerente');
        $ana = $this->colaborador($this->puesto('Cajera', $jefe));
        $tarea = $this->tareaConFirma();

        $enConsola = TaskValidationPolicy::requiresValidation($this->tenantId, $ana->id, $tarea);

        $this->actingAs($ana);
        $enPeticion = TaskValidationPolicy::requiresValidation($this->tenantId, $ana->id, $tarea);

        $this->assertSame($enPeticion, $enConsola,
            'La misma tarea y el mismo colaborador no pueden exigir firma o no según quién pregunte.');
        $this->assertTrue($enConsola);
    }

    public function test_el_puesto_se_resuelve_aunque_el_scope_no_ayude(): void
    {
        // Prueba directa de la causa: el lookup debe encontrar el puesto sin depender del scope.
        $jefe = $this->puesto('Gerente');
        $puestoId = $this->puesto('Cajera', $jefe);

        $this->assertNotNull(
            JobRole::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($puestoId),
            'El puesto existe; si la política no lo ve, concluirá que nadie tiene supervisor.'
        );
    }
}
