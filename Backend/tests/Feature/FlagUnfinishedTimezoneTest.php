<?php

namespace Tests\Feature;

use App\Models\TaskAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A5 (auditoría 2026-07-27): tasks:flag-unfinished corre a las 00:30 del SERVIDOR (UTC en
 * Hetzner) y comparaba `date < Carbon::today()` (UTC). A las 00:30 UTC del día D+1, en
 * México todavía es D a las 18:30 con turnos ABIERTOS: toda tarea in_progress/paused
 * fechada D se forzaba a awaiting_validation + flagged_incomplete A MEDIA JORNADA.
 *
 * Regla de esta ronda: el corte es POR TENANT con su zona horaria de negocio
 * (TenantTimezone, default America/Mexico_City) — el mismo criterio que ya usa el Reloj
 * para todo lo fechado.
 */
class FlagUnfinishedTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'DecorArte', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAssignment(string $id, string $date, int $tenantId = 1): void
    {
        // Insert directo: el trait Tenantable pisa tenant_id con el del auth (aquí no hay)
        // y task_id es NOT NULL — se siembra una task del tenant por asignación.
        $taskId = crc32($id);
        DB::table('tasks')->insertOrIgnore([
            'id' => $taskId, 'title' => "T {$id}", 'tenant_id' => $tenantId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('task_assignments')->insert([
            'id' => $id,
            'task_id' => $taskId,
            'user_id' => null,
            'status' => 'in_progress',
            'flagged_incomplete' => false,
            'date' => $date,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_no_flaggea_el_turno_en_curso_del_tenant_aunque_utc_ya_cruzo_medianoche(): void
    {
        // Momento real del scheduler: 00:30 UTC del 28 = 18:30 del 27 en CDMX (turnos abiertos).
        Carbon::setTestNow(Carbon::parse('2026-07-28 00:30:00', 'UTC'));

        $this->makeAssignment('turno-en-curso', '2026-07-27'); // HOY del tenant (CDMX)
        $this->makeAssignment('ayer-real', '2026-07-26');      // ayer de verdad

        $this->artisan('tasks:flag-unfinished')->assertSuccessful();

        // La del turno en curso NO se toca…
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'turno-en-curso', 'status' => 'in_progress', 'flagged_incomplete' => false,
        ]);
        // …la de ayer sí queda para decisión gerencial.
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'ayer-real', 'status' => 'awaiting_validation', 'flagged_incomplete' => true,
        ]);
    }

    public function test_un_tenant_configurado_en_utc_corta_con_su_propia_medianoche(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 00:30:00', 'UTC'));

        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'UTC Corp', 'subdomain' => 't2', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => 2, 'key' => 'timezone', 'value' => json_encode('UTC'),
        ]);

        // Para el tenant UTC, el 27 YA es ayer a las 00:30 del 28.
        $this->makeAssignment('utc-ayer', '2026-07-27', 2);

        $this->artisan('tasks:flag-unfinished')->assertSuccessful();

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'utc-ayer', 'status' => 'awaiting_validation', 'flagged_incomplete' => true,
        ]);
    }

    public function test_la_opcion_date_manual_sigue_mandando_para_todos_los_tenants(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 00:30:00', 'UTC'));

        $this->makeAssignment('con-override', '2026-07-27');

        // Con el override manual, el corte es la fecha dada (uso de reproceso).
        $this->artisan('tasks:flag-unfinished', ['--date' => '2026-07-28'])->assertSuccessful();

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'con-override', 'status' => 'awaiting_validation', 'flagged_incomplete' => true,
        ]);
    }
}
