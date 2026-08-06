<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fuga de privacidad en `/sync/state` (encontrada y cerrada el 2026-08-06).
 *
 * La carga de datos del reloj devolvía **todos** los mensajes internos de la empresa a
 * **cualquier** colaborador, sin mirar el destinatario. Comprobado en vivo antes del arreglo:
 * el admin mandó un privado a un colaborador y OTRO colaborador lo recibió entero —remitente,
 * destinatario y contenido— en su propio `/sync/state`.
 *
 * Nadie los veía en pantalla porque la función quedó a medias (no hay dónde escribirlos ni
 * código que los lea), pero viajaban a todos los dispositivos igual. El día que alguien
 * construya la lectura, esto habría convertido un buzón en un tablón público.
 */
class SyncStateMensajesPrivadosTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 31;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Mensajes QA', 'subdomain' => 'msgqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function persona(string $rol = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        return $user->fresh();
    }

    private function mensaje(int $de, ?int $para, string $texto, string $tipo = 'private'): void
    {
        DB::table('internal_messages')->insert([
            'tenant_id' => $this->tenantId,
            'sender_id' => $de, 'receiver_id' => $para,
            'type' => $tipo, 'content' => $texto,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mensajesQueRecibe(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/v1/sync/state')->json('internal_messages'))
            ->pluck('content')->all();
    }

    public function test_un_colaborador_no_recibe_el_mensaje_privado_de_otro(): void
    {
        $jefa = $this->persona('admin');
        $destinatario = $this->persona();
        $tercero = $this->persona();

        $this->mensaje($jefa->id, $destinatario->id, 'Ven a mi oficina');

        $this->assertNotContains('Ven a mi oficina', $this->mensajesQueRecibe($tercero),
            'el mensaje iba dirigido a otra persona y le llegaba entero a este colaborador');
    }

    public function test_a_su_destinatario_si_le_llega(): void
    {
        $jefa = $this->persona('admin');
        $destinatario = $this->persona();

        $this->mensaje($jefa->id, $destinatario->id, 'Ven a mi oficina');

        $this->assertContains('Ven a mi oficina', $this->mensajesQueRecibe($destinatario));
    }

    public function test_quien_lo_mando_lo_sigue_viendo(): void
    {
        $jefa = $this->persona('admin');
        $destinatario = $this->persona();

        $this->mensaje($jefa->id, $destinatario->id, 'Ven a mi oficina');

        $this->assertContains('Ven a mi oficina', $this->mensajesQueRecibe($jefa),
            'quien escribe tiene que poder ver lo que escribió');
    }

    public function test_los_avisos_a_toda_la_empresa_le_llegan_a_todos(): void
    {
        // `receiver_id` nulo = difusión. Filtrar por destinatario no puede romper esto.
        $jefa = $this->persona('admin');
        $cualquiera = $this->persona();

        $this->mensaje($jefa->id, null, 'Mañana cerramos a las 6', 'broadcast');

        $this->assertContains('Mañana cerramos a las 6', $this->mensajesQueRecibe($cualquiera));
    }

    public function test_ni_siquiera_un_admin_lee_la_correspondencia_ajena(): void
    {
        // Un segundo admin no tiene por qué leer lo que el primero le escribió a alguien.
        $jefa = $this->persona('admin');
        $otroAdmin = $this->persona('admin');
        $destinatario = $this->persona();

        $this->mensaje($jefa->id, $destinatario->id, 'Tema delicado de nómina');

        $this->assertNotContains('Tema delicado de nómina', $this->mensajesQueRecibe($otroAdmin));
    }
}
