<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T12 (Tareas): consistencia del enum de prioridad (normal|bloqueante).
 *
 * El FE solo reconoce 'normal' y 'bloqueante' (esta bloquea el checkout). El
 * backend producía/aceptaba 'high'/'medium'/'low' que el FE nunca trataba como
 * críticas. Ahora parseVoiceTask solo devuelve normal|bloqueante (decisión de
 * negocio: 'alta'/'media' → normal; solo 'urgente'/'bloqueante' escalan), y
 * create-task rechaza cualquier valor fuera del enum.
 */
class PriorityEnumConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'public_slug' => 'default',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'tenant_id' => 1]);
    }

    private function parse(User $u, string $text)
    {
        return $this->actingAs($u)->postJson('/api/v1/admin/dashboard/parse-voice-task', ['text' => $text]);
    }

    public function test_parse_urgente_escala_a_bloqueante(): void
    {
        $this->parse($this->admin(), 'Tarea urgente de revisar la caja')
            ->assertStatus(200)
            ->assertJsonPath('data.priority', 'bloqueante');
    }

    public function test_parse_alta_se_normaliza_a_normal(): void
    {
        $this->parse($this->admin(), 'Tarea de prioridad alta para limpiar')
            ->assertStatus(200)
            ->assertJsonPath('data.priority', 'normal');
    }

    public function test_parse_media_se_normaliza_a_normal(): void
    {
        $this->parse($this->admin(), 'Tarea de prioridad media para ordenar')
            ->assertStatus(200)
            ->assertJsonPath('data.priority', 'normal');
    }

    public function test_create_task_rechaza_prioridad_fuera_del_enum(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/admin/dashboard/create-task', [
            'title' => 'Tarea prio inválida',
            'estimated_mins' => 15,
            'points' => 10,
            'priority' => 'high',
            'target_type' => 'role',
            'target_id' => null,
        ])->assertStatus(422);
    }

    public function test_create_task_acepta_bloqueante(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/admin/dashboard/create-task', [
            'title' => 'Tarea bloqueante',
            'estimated_mins' => 15,
            'points' => 10,
            'priority' => 'bloqueante',
            'target_type' => 'role',
            'target_id' => null,
        ])->assertStatus(200);

        $this->assertDatabaseHas('tasks', ['title' => 'Tarea bloqueante', 'priority' => 'bloqueante']);
    }
}
