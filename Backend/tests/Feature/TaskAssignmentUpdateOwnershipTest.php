<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T2 (Tareas): ownership y anti-bypass en PUT /task-assignments/{id}.
 *
 * Es la puerta gemela de POST /sync/tasks (Ronda T1). Antes, cualquier empleado
 * del tenant podía editar CUALQUIER assignment (status→completed sin supervisor)
 * y fijar validated_by desde el cliente, burlando la validación jerárquica de
 * TaskValidationController. Reglas nuevas:
 *  - No privilegiado solo actualiza sus PROPIAS assignments (user_id = actor);
 *    ajena → 403. (El checklist de apertura de RelojVisual actúa sobre las propias.)
 *  - validated_by NUNCA es asignable por este endpoint (solo /validate lo fija).
 *  - completed→awaiting_validation cuando aplica, y awaiting_validation pegajoso:
 *    el PUT no puede saltarse la validación que el sync sí aplica.
 *  - admin/supervisor conservan el comportamiento actual dentro de su tenant.
 */
class TaskAssignmentUpdateOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'DecorArte 360',
            'subdomain' => 'decorarte360',
            'public_slug' => 'decorarte360',
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    /** Empleado con supervisor configurado + feature de validación activa. */
    private function makeEmployeeUnderSupervisor(): User
    {
        $supRole = JobRole::create(['name' => 'Sup ' . uniqid(), 'tenant_id' => 1, 'area' => 'Ops']);
        $empRole = JobRole::create([
            'name' => 'Emp ' . uniqid(),
            'tenant_id' => 1,
            'reports_to_role_id' => $supRole->id,
            'area' => 'Ops',
        ]);
        $user = $this->makeUser('empleado');
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'job_role_id' => $empRole->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Activar la feature supervisor_validation en el tenant.
        $tenant = Tenant::find(1);
        $tenant->plan = 'enterprise';
        $tenant->save();
        return $user->fresh();
    }

    private function makeTask(int $id, string $mode = 'forced', string $priority = 'normal'): Task
    {
        return Task::create([
            'id' => $id,
            'title' => 'Tarea T2 ' . $id,
            'tenant_id' => 1,
            'validation_mode' => $mode,
            'priority' => $priority,
        ]);
    }

    private function putAssignment(User $actor, string $id, array $body)
    {
        return $this->actingAs($actor)->putJson("/api/v1/task-assignments/{$id}", $body);
    }

    public function test_empleado_no_puede_actualizar_assignment_de_otro(): void
    {
        $victima = $this->makeUser();
        $atacante = $this->makeUser();
        $task = $this->makeTask(700);

        TaskAssignment::create([
            'id' => 'upd-ajena-1',
            'task_id' => $task->id,
            'user_id' => $victima->id,
            'status' => 'in_progress',
            'tenant_id' => 1,
        ]);

        $this->putAssignment($atacante, 'upd-ajena-1', ['status' => 'completed'])
            ->assertStatus(403);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-ajena-1',
            'status' => 'in_progress',
        ]);
    }

    public function test_empleado_no_puede_forjar_validated_by(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask(701, 'auto');

        TaskAssignment::create([
            'id' => 'upd-forge-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'in_progress',
            'tenant_id' => 1,
        ]);

        $this->putAssignment($empleado, 'upd-forge-1', [
            'status' => 'completed',
            'validated_by' => $empleado->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-forge-1',
            'validated_by' => null,
        ]);
    }

    public function test_empleado_puede_actualizar_su_propia_assignment(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask(702, 'auto');

        TaskAssignment::create([
            'id' => 'upd-propia-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'in_progress',
            'accumulated_mins' => 0,
            'tenant_id' => 1,
        ]);

        $this->putAssignment($empleado, 'upd-propia-1', [
            'status' => 'paused',
            'accumulated_mins' => 8,
        ])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-propia-1',
            'status' => 'paused',
            'accumulated_mins' => 8,
        ]);
    }

    public function test_completar_propia_va_a_awaiting_validation_si_requiere(): void
    {
        $empleado = $this->makeEmployeeUnderSupervisor();
        $task = $this->makeTask(703, 'forced');

        TaskAssignment::create([
            'id' => 'upd-forced-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'in_progress',
            'tenant_id' => 1,
        ]);

        // El empleado marca completed vía PUT (como el checklist de apertura).
        $this->putAssignment($empleado, 'upd-forced-1', ['status' => 'completed'])
            ->assertStatus(200);

        // No debe poder saltarse al supervisor: queda awaiting_validation.
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-forced-1',
            'status' => 'awaiting_validation',
        ]);
    }

    public function test_awaiting_validation_es_pegajoso_via_update(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask(704, 'auto');

        TaskAssignment::create([
            'id' => 'upd-sticky-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'awaiting_validation',
            'tenant_id' => 1,
        ]);

        // Intento de auto-sacarla de la cola de validación.
        $this->putAssignment($empleado, 'upd-sticky-1', ['status' => 'completed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-sticky-1',
            'status' => 'awaiting_validation',
        ]);
    }

    public function test_checklist_apertura_marca_completed_sin_supervisor(): void
    {
        // Tarea sin supervisor configurado (empleado sin reports_to) → no requiere
        // validación; el checklist de apertura debe poder marcar completed directo.
        $empleado = $this->makeUser();
        $task = $this->makeTask(705, 'forced');

        TaskAssignment::create([
            'id' => 'upd-apertura-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'pending',
            'tenant_id' => 1,
        ]);

        $this->putAssignment($empleado, 'upd-apertura-1', ['status' => 'completed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-apertura-1',
            'status' => 'completed',
        ]);
    }

    public function test_desmarcar_una_completada_vuelve_a_pending(): void
    {
        // Desmarcar el checkbox del checklist (completed → pending) debe funcionar:
        // deshacer no salta ninguna validación (al re-completar se re-evalúa).
        $empleado = $this->makeUser();
        $task = $this->makeTask(707, 'auto');

        TaskAssignment::create([
            'id' => 'upd-uncheck-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'completed',
            'tenant_id' => 1,
        ]);

        $this->putAssignment($empleado, 'upd-uncheck-1', ['status' => 'pending'])
            ->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-uncheck-1',
            'status' => 'pending',
        ]);
    }

    public function test_empleado_no_puede_sobrescribir_validation_feedback(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask(708, 'auto');

        // El supervisor dejó un motivo de rechazo.
        TaskAssignment::create([
            'id' => 'upd-fb-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'in_progress',
            'validation_feedback' => 'Rehacer: faltó firmar el acta.',
            'tenant_id' => 1,
        ]);

        // El empleado intenta borrar el feedback vía el update genérico.
        $this->putAssignment($empleado, 'upd-fb-1', [
            'status' => 'in_progress',
            'validation_feedback' => null,
        ])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-fb-1',
            'validation_feedback' => 'Rehacer: faltó firmar el acta.',
        ]);
    }

    public function test_admin_puede_actualizar_assignment_ajena(): void
    {
        $admin = $this->makeUser('admin');
        $empleado = $this->makeUser();
        $task = $this->makeTask(706, 'auto');

        TaskAssignment::create([
            'id' => 'upd-admin-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'in_progress',
            'tenant_id' => 1,
        ]);

        $this->putAssignment($admin, 'upd-admin-1', ['status' => 'completed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'upd-admin-1',
            'status' => 'completed',
        ]);
    }
}
