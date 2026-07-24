<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\StoreOpeningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T6 (Tareas): el enganche reloj→tareas triggerOpeningChecklist.
 *
 * Único enganche server-side entre el Reloj y Tareas: al abrir la tienda
 * (openStoreAndClockIn), asigna al encargado las tareas de la(s) rutina(s) con
 * trigger='apertura'. Bugs cerrados:
 *  - Race/duplicados: check-then-insert sin protección → dos aperturas concurrentes
 *    duplicaban asignaciones. Ahora id determinista + insertOrIgnore (idempotente).
 *  - Solo tomaba la PRIMERA rutina de apertura (->first()) → ahora todas.
 *  - catch{} vacío tragaba errores → ahora se loguea (verificado por lectura).
 */
class OpeningChecklistAssignmentTest extends TestCase
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

    private function makeUser(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    private function makeRoutine(int $id, string $title = 'Apertura'): int
    {
        DB::table('routines')->insert([
            'id' => $id,
            'title' => $title,
            'trigger' => 'apertura',
            'assign_mode' => 'equitativo',
            'tenant_id' => 1,
        ]);
        return $id;
    }

    private function attachTask(int $routineId, int $taskId): void
    {
        Task::firstOrCreate(['id' => $taskId], [
            'title' => "Tarea {$taskId}",
            'tenant_id' => 1,
        ]);
        DB::table('routine_task')->insert(['routine_id' => $routineId, 'task_id' => $taskId]);
    }

    private function runChecklist(User $user): void
    {
        $service = app(StoreOpeningService::class);
        $ref = new \ReflectionMethod($service, 'triggerOpeningChecklist');
        $ref->setAccessible(true);
        $ref->invoke($service, 1, $user);
    }

    public function test_asigna_las_tareas_de_la_rutina_de_apertura(): void
    {
        $user = $this->makeUser();
        $routine = $this->makeRoutine(10);
        $this->attachTask($routine, 101);
        $this->attachTask($routine, 102);

        $this->runChecklist($user);

        $date = Carbon::now(app(StoreOpeningService::class)->tenantTimezone(1))->format('Y-m-d');
        $this->assertDatabaseHas('task_assignments', ['task_id' => 101, 'user_id' => $user->id, 'date' => $date, 'status' => 'pending']);
        $this->assertDatabaseHas('task_assignments', ['task_id' => 102, 'user_id' => $user->id, 'date' => $date, 'status' => 'pending']);
    }

    public function test_es_idempotente_no_duplica_en_aperturas_repetidas(): void
    {
        $user = $this->makeUser();
        $routine = $this->makeRoutine(11);
        $this->attachTask($routine, 111);

        // Simula dos aperturas (doble-click / reintento).
        $this->runChecklist($user);
        $this->runChecklist($user);

        $count = DB::table('task_assignments')
            ->where('task_id', 111)
            ->where('user_id', $user->id)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_toma_todas_las_rutinas_de_apertura_no_solo_la_primera(): void
    {
        $user = $this->makeUser();
        $r1 = $this->makeRoutine(12, 'Apertura Piso');
        $r2 = $this->makeRoutine(13, 'Apertura Caja');
        $this->attachTask($r1, 121);
        $this->attachTask($r2, 131);

        $this->runChecklist($user);

        $this->assertDatabaseHas('task_assignments', ['task_id' => 121, 'user_id' => $user->id]);
        $this->assertDatabaseHas('task_assignments', ['task_id' => 131, 'user_id' => $user->id]);
    }

    public function test_no_asigna_tarea_de_otro_tenant_referenciada_en_el_pivot(): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'Otro', 'subdomain' => 't2', 'public_slug' => 't2',
            'plan' => 'free', 'max_users' => 5, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = $this->makeUser();
        $routine = $this->makeRoutine(16);
        // Tarea legítima del tenant 1 + tarea ajena (tenant 2) colada en el pivot.
        $this->attachTask($routine, 161);
        // Forzar tenant_id=2 vía DB directo: el hook de Tenantable sin auth cae a
        // tenant 1 y no respetaría el atributo.
        Task::create(['id' => 999, 'title' => 'Ajena']);
        DB::table('tasks')->where('id', 999)->update(['tenant_id' => 2]);
        DB::table('routine_task')->insert(['routine_id' => $routine, 'task_id' => 999]);

        $this->runChecklist($user);

        $this->assertDatabaseHas('task_assignments', ['task_id' => 161, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('task_assignments', ['task_id' => 999]);
    }

    public function test_una_tarea_en_dos_rutinas_de_apertura_se_asigna_una_vez(): void
    {
        $user = $this->makeUser();
        $r1 = $this->makeRoutine(14, 'Apertura A');
        $r2 = $this->makeRoutine(15, 'Apertura B');
        // Misma tarea compartida por ambas rutinas de apertura.
        $this->attachTask($r1, 141);
        $this->attachTask($r2, 141);

        $this->runChecklist($user);

        $count = DB::table('task_assignments')
            ->where('task_id', 141)
            ->where('user_id', $user->id)
            ->count();
        $this->assertEquals(1, $count);
    }
}
