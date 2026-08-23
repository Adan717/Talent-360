<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El chat del dial y el del Monitor son la MISMA conversación (2026-08-22, fase 12).
 *
 * Había dos chats con el mismo nombre: el Monitor 360 usaba `internal_messages` y el reloj usaba
 * `team_chat_messages`. Comprobado en vivo (tenant 4): el jefe mandó un mensaje de equipo desde el
 * Monitor y el colaborador no lo vio; el colaborador escribió desde su reloj y al Monitor no le
 * llegó nada. Las dos pantallas decían "enviado".
 */
class ChatDelDialYDelMonitorSonElMismoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $jefe;
    private User $miguel;
    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Chat QA', 'subdomain' => 'chatqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->jefe = $this->persona('Jefa', 'admin');
        $this->miguel = $this->persona('Miguel', 'empleado');
        $this->maria = $this->persona('Maria', 'supervisor');
    }

    private function persona(string $nombre, string $rol): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower($nombre) . '@chatqa.test', 'password' => bcrypt('x'), 'role' => $rol,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        return $user;
    }

    private function chatDelDial(User $quien): array
    {
        $res = $this->actingAs($quien)->getJson('/api/v1/chat/messages');
        $res->assertOk();

        return $res->json('messages');
    }

    private function chatDelMonitor(User $quien): array
    {
        $res = $this->actingAs($quien)->getJson('/api/v1/admin/dashboard/monitor');
        $res->assertOk();

        return $res->json('data.chat');
    }

    public function test_lo_que_el_jefe_manda_desde_el_monitor_llega_al_dial(): void
    {
        $this->actingAs($this->jefe)->postJson('/api/v1/admin/dashboard/send-message', [
            'content' => 'Junta a las 6, no falten',
            'type' => 'general',
        ])->assertOk();

        $this->assertSame(
            ['Junta a las 6, no falten'],
            collect($this->chatDelDial($this->miguel))->pluck('message')->all()
        );
    }

    public function test_lo_que_el_colaborador_escribe_en_su_reloj_llega_al_monitor(): void
    {
        $this->actingAs($this->miguel)->postJson('/api/v1/chat/messages', [
            'message' => 'Se acabó el papel en caja',
        ])->assertCreated();

        $this->assertContains(
            'Se acabó el papel en caja',
            collect($this->chatDelMonitor($this->jefe))->pluck('content')->all()
        );
    }

    public function test_el_privado_del_jefe_lo_ve_su_destinatario_y_nadie_mas(): void
    {
        $this->actingAs($this->jefe)->postJson('/api/v1/admin/dashboard/send-message', [
            'content' => 'Miguel, pasa a mi oficina',
            'type' => 'general',
            'receiver_id' => $this->miguel->id,
        ])->assertOk();

        $paraMiguel = collect($this->chatDelDial($this->miguel));
        $this->assertSame(['Miguel, pasa a mi oficina'], $paraMiguel->pluck('message')->all());
        $this->assertTrue($paraMiguel->first()['es_privado']);
        $this->assertTrue($paraMiguel->first()['para_mi'], 'el dial tiene que poder marcarlo como privado');

        $this->assertEmpty($this->chatDelDial($this->maria), 'la correspondencia de otro no se lee');
    }

    public function test_el_remitente_es_la_sesion_no_lo_que_manda_el_cliente(): void
    {
        $this->actingAs($this->miguel)->postJson('/api/v1/chat/messages', [
            'message' => 'Mañana no vengan',
            'sender_id' => $this->jefe->id,
            'user_id' => $this->jefe->id,
        ])->assertCreated();

        $fila = DB::table('internal_messages')->where('content', 'Mañana no vengan')->first();
        $this->assertSame($this->miguel->id, (int) $fila->sender_id, 'nadie firma con el nombre del jefe');
    }

    public function test_un_privado_a_alguien_de_otra_empresa_se_rechaza(): void
    {
        $otro = Tenant::create(['name' => 'Otra', 'subdomain' => 'otraqa', 'plan' => 'basic', 'is_active' => true]);
        $ajeno = User::create([
            'tenant_id' => $otro->id, 'name' => 'Ajeno', 'email' => 'ajeno@otraqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $this->actingAs($this->miguel)->postJson('/api/v1/chat/messages', [
            'message' => 'hola',
            'receiver_id' => $ajeno->id,
        ])->assertStatus(403);
    }

    public function test_el_dial_anuncia_la_retencion_de_su_empresa(): void
    {
        DB::table('system_settings')->insert([
            'tenant_id' => $this->tenant->id, 'key' => 'chatRetentionDays', 'value' => json_encode(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->miguel)->getJson('/api/v1/chat/messages')
            ->assertOk()
            ->assertJsonPath('retention_days', 30);
    }

    public function test_lo_que_caduco_ya_no_se_pinta_pero_lo_preservado_si(): void
    {
        DB::table('internal_messages')->insert([
            'tenant_id' => $this->tenant->id, 'sender_id' => $this->jefe->id, 'receiver_id' => null,
            'type' => 'general', 'content' => 'Viejo y caduco', 'preserved_at' => null,
            'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9),
        ]);
        DB::table('internal_messages')->insert([
            'tenant_id' => $this->tenant->id, 'sender_id' => $this->jefe->id, 'receiver_id' => null,
            'type' => 'general', 'content' => 'Viejo pero preservado', 'preserved_at' => now(),
            'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9),
        ]);

        $mensajes = collect($this->chatDelDial($this->miguel))->pluck('message')->all();

        $this->assertNotContains('Viejo y caduco', $mensajes);
        $this->assertContains('Viejo pero preservado', $mensajes);
    }

    public function test_los_mensajes_del_simulador_no_se_mezclan_con_los_reales(): void
    {
        $sesion = DB::table('simulator_sessions')->insertGetId([
            'tenant_id' => $this->tenant->id, 'started_by_user_id' => $this->jefe->id,
            'simulated_date' => now()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('internal_messages')->insert([
            'tenant_id' => $this->tenant->id, 'sender_id' => $this->jefe->id, 'receiver_id' => null,
            'type' => 'general', 'content' => 'Mensaje de la Matrix', 'simulation_session_id' => $sesion,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertEmpty($this->chatDelDial($this->miguel));
    }
}
