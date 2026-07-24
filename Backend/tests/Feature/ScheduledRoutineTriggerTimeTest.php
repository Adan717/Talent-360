<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T15 (Tareas): persistencia de trigger_time para rutinas 'scheduled'.
 *
 * Las rutinas "Horario Fijo" necesitan una hora de disparo; la columna trigger_time
 * no existía y el sync no la mapeaba. Este test blinda que POST /sync/tasks persista
 * triggerTime (camelCase del FE) en la columna trigger_time.
 */
class ScheduledRoutineTriggerTimeTest extends TestCase
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

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    public function test_sync_persiste_trigger_time_de_rutina_scheduled(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/sync/tasks', [
            'routines' => [[
                'id' => 500,
                'title' => 'Revisión de caja 3pm',
                'trigger' => 'scheduled',
                'triggerTime' => '15:00',
                'assignMode' => 'equitativo',
            ]],
        ])->assertStatus(200);

        $this->assertDatabaseHas('routines', [
            'id' => 500,
            'trigger' => 'scheduled',
            'trigger_time' => '15:00',
        ]);
    }

    public function test_rutina_on_checkin_no_setea_trigger_time(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/sync/tasks', [
            'routines' => [[
                'id' => 501,
                'title' => 'Rutina al fichar',
                'trigger' => 'on_checkin',
                'assignMode' => 'checklist',
            ]],
        ])->assertStatus(200);

        $this->assertDatabaseHas('routines', [
            'id' => 501,
            'trigger' => 'on_checkin',
            'trigger_time' => null,
        ]);
    }
}
