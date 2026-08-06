<?php

namespace Tests\Feature;

use App\Events\NewChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Modo privado del Chat Operativo (Monitor 360).
 *
 * Viene de un diagnóstico: el "mensaje privado" del Reloj **no existía** —no había dónde
 * escribirlo ni código que lo leyera—, y el jefe decidió no resucitar ese camino sino darle modo
 * privado al chat que sí funciona: *"mantener dos canales de mensajería es duplicar el problema"*.
 *
 * Lo que se cuida aquí es que el modo privado no repita la fuga que se acaba de cerrar en
 * `/sync/state`: un mensaje dirigido a una persona no puede acabar en la pantalla de otra, ni por
 * la consulta ni por el canal de tiempo real (que escucha toda la empresa).
 */
class ChatOperativoModoPrivadoTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 51;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Chat QA', 'subdomain' => 'chatqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function persona(string $rol = 'admin'): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        return $user->fresh();
    }

    private function mandar(User $quien, string $texto, ?int $para = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($quien)->postJson('/api/v1/admin/dashboard/send-message', array_filter([
            'content' => $texto,
            'receiver_id' => $para,
        ]));
    }

    private function chatDe(User $quien): array
    {
        return collect($this->actingAs($quien)->getJson('/api/v1/admin/dashboard/monitor')->json('data.chat'))
            ->pluck('content')->all();
    }

    /**
     * Lo que le llega al colaborador en su reloj. El Monitor 360 es tablero de mando —exige
     * permisos de operación—, así que el colaborador no lo abre: sus mensajes viajan en la carga
     * del reloj.
     */
    private function relojDe(User $quien): array
    {
        return collect($this->actingAs($quien)->getJson('/api/v1/sync/state')->json('internal_messages'))
            ->pluck('content')->all();
    }

    public function test_el_mensaje_de_equipo_lo_ve_todo_el_mundo(): void
    {
        // Sin destinatario sigue siendo lo de siempre: el chat operativo del turno.
        $jefa = $this->persona();
        $otroMando = $this->persona();
        $colaborador = $this->persona('empleado');

        $this->mandar($jefa, 'Llegó el proveedor')->assertStatus(200);

        $this->assertContains('Llegó el proveedor', $this->chatDe($otroMando), 'en el tablero');
        $this->assertContains('Llegó el proveedor', $this->relojDe($colaborador), 'y en el reloj');
    }

    public function test_el_mensaje_privado_solo_lo_ven_los_dos(): void
    {
        $jefa = $this->persona();
        $destinatario = $this->persona('empleado');
        $tercero = $this->persona('empleado');

        $this->mandar($jefa, 'Ven a mi oficina', $destinatario->id)->assertStatus(200);

        $this->assertContains('Ven a mi oficina', $this->relojDe($destinatario), 'a quien va dirigido');
        $this->assertContains('Ven a mi oficina', $this->chatDe($jefa), 'a quien lo escribió');
        $this->assertNotContains('Ven a mi oficina', $this->relojDe($tercero),
            'un tercero no tiene por qué leer la conversación de otros dos');
    }

    public function test_ni_otro_admin_lee_la_conversacion_ajena(): void
    {
        $jefa = $this->persona();
        $otroAdmin = $this->persona();
        $destinatario = $this->persona('empleado');

        $this->mandar($jefa, 'Tema delicado de nómina', $destinatario->id);

        $this->assertNotContains('Tema delicado de nómina', $this->chatDe($otroAdmin));
    }

    public function test_el_privado_no_sale_por_el_canal_de_toda_la_empresa(): void
    {
        // `NewChatMessage` emite a `tenant.{id}`, que escucha la empresa entera: un privado por
        // ahí llegaría en tiempo real a los dispositivos de todos.
        Event::fake([NewChatMessage::class]);

        $jefa = $this->persona();
        $destinatario = $this->persona('empleado');

        $this->mandar($jefa, 'Privado', $destinatario->id);
        Event::assertNotDispatched(NewChatMessage::class);

        $this->mandar($jefa, 'Para el equipo');
        Event::assertDispatched(NewChatMessage::class);
    }

    public function test_no_se_puede_escribir_a_alguien_de_otra_empresa(): void
    {
        $jefa = $this->persona();

        DB::table('tenants')->insertOrIgnore([
            'id' => 52, 'name' => 'Otra Empresa', 'subdomain' => 'otraqa',
            'plan' => 'free', 'max_users' => 5, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ajeno = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $ajeno->id)->update(['tenant_id' => 52]);

        $this->mandar($jefa, 'Hola', $ajeno->id)->assertStatus(403);

        $this->assertDatabaseMissing('internal_messages', ['content' => 'Hola']);
    }

    public function test_el_privado_dice_para_quien_es(): void
    {
        $jefa = $this->persona();
        $destinatario = $this->persona('empleado');

        $respuesta = $this->mandar($jefa, 'Ven a mi oficina', $destinatario->id);

        $respuesta->assertJsonPath('data.receiver_id', $destinatario->id);
        $respuesta->assertJsonPath('data.receiver_name', $destinatario->name);
        $respuesta->assertJsonPath('data.type', 'private');
    }

    public function test_el_colaborador_lo_recibe_en_su_reloj(): void
    {
        // La razón de ser de todo esto: que el mensaje LLEGUE. El reloj lo lee de /sync/state.
        $jefa = $this->persona();
        $colaborador = $this->persona('empleado');

        $this->mandar($jefa, 'Pasa a la oficina antes de irte', $colaborador->id);

        $suyos = collect($this->actingAs($colaborador)->getJson('/api/v1/sync/state')->json('internal_messages'))
            ->pluck('content');

        $this->assertContains('Pasa a la oficina antes de irte', $suyos);
    }
}
