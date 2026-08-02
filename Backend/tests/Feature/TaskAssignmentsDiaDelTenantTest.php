<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H25 — `GET /task-assignments` usaba el día del SERVIDOR en vez del día del TENANT.
 *
 * El listado filtra por fecha, y esa fecha salía de `Carbon::now()` —UTC en el servidor—, mientras
 * las asignaciones se guardan con la fecha de la zona del tenant. Con un tenant en UTC-6, desde
 * las 18:00 hora local (00:00 UTC del día siguiente) el filtro pregunta por MAÑANA y las
 * asignaciones de la jornada en curso están bajo HOY: **el listado devuelve vacío las últimas seis
 * horas de cada día**.
 *
 * Medido en vivo: con el servidor en `2026-08-02 01:41 UTC` (19:41 local) el endpoint devolvía
 * `[]`, y con `?date=2026-08-01` explícito devolvía las 3 asignaciones que sí existían.
 *
 * A quién le duele: el dial llena con esto el **checklist de apertura**
 * (`RelojVisual::fetchOpeningAssignments`). En esa ventana el modal sale vacío y, como el
 * "¿ya está todo hecho?" exige `length > 0`, el checklist tampoco llega a marcarse completo.
 * Una tienda de horario vespertino abre justo dentro de ese hueco.
 *
 * Es la MISMA familia que ya se cerró en A5/M5 (corte por tenant en el flag nocturno y en el
 * reagendado) y en H10 (el dial filtraba con la fecha del dispositivo). Aquí había quedado viva.
 */
class TaskAssignmentsDiaDelTenantTest extends TestCase
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

        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $this->tenantId, 'key' => 'timezone'],
            ['value' => json_encode('America/Mexico_City')]
        );
    }

    private function colaborador(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user->fresh();
    }

    private function asignarTareaEl(User $user, string $fecha): string
    {
        $taskId = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->tenantId, 'title' => 'Abrir cortina', 'points' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = "open_{$taskId}_{$user->id}_{$fecha}";
        DB::table('task_assignments')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'task_id' => $taskId,
            'user_id' => $user->id, 'status' => 'pending', 'date' => $fecha,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_a_las_19h_locales_el_colaborador_SIGUE_viendo_sus_tareas_del_dia(): void
    {
        // EL CASO DEL BUG: 01:41 UTC del día 2 son las 19:41 del día 1 en Ciudad de México.
        // La jornada en curso es la del día 1, y ahí están sus asignaciones.
        Carbon::setTestNow(Carbon::parse('2026-08-02 01:41:00', 'UTC'));

        $ana = $this->colaborador();
        $this->asignarTareaEl($ana, '2026-08-01');

        $res = $this->actingAs($ana)->getJson('/api/v1/task-assignments');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json(),
            'A las 19:41 locales el checklist del día no puede aparecer vacío.');

        Carbon::setTestNow();
    }

    public function test_a_mediodia_local_funciona_igual(): void
    {
        // Control: la ventana en la que el bug NO se manifestaba debe seguir bien.
        Carbon::setTestNow(Carbon::parse('2026-08-01 18:00:00', 'UTC')); // 12:00 local

        $ana = $this->colaborador();
        $this->asignarTareaEl($ana, '2026-08-01');

        $this->actingAs($ana)->getJson('/api/v1/task-assignments')
            ->assertStatus(200)
            ->assertJsonCount(1);

        Carbon::setTestNow();
    }

    public function test_no_se_cuelan_las_tareas_de_ayer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 01:41:00', 'UTC')); // 19:41 del día 1

        $ana = $this->colaborador();
        $this->asignarTareaEl($ana, '2026-08-01'); // hoy (local)
        $this->asignarTareaEl($ana, '2026-07-31'); // ayer

        $res = $this->actingAs($ana)->getJson('/api/v1/task-assignments');

        $this->assertCount(1, $res->json(), 'El listado es el del día, no un histórico.');

        Carbon::setTestNow();
    }

    public function test_el_parametro_date_explicito_se_respeta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 01:41:00', 'UTC'));

        $ana = $this->colaborador();
        $this->asignarTareaEl($ana, '2026-07-31');

        $this->actingAs($ana)->getJson('/api/v1/task-assignments?date=2026-07-31')
            ->assertStatus(200)
            ->assertJsonCount(1);

        Carbon::setTestNow();
    }
}
