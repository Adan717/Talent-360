<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OrganigramaSugerido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regla del jefe (2026-08-06): *"Ningún puesto se da de alta sin que el admin confirme a quién
 * reporta. El asistente puede sugerir, pero el admin debe aceptar o arrastrar la línea. No más
 * organigramas vacíos que reparamos después con comandos."*
 *
 * La convención automática —cada puesto cuelga del primero del nivel de arriba— produce líneas
 * que en operación real no cuadran: en la empresa de pruebas dejó al **Asesor de Ventas colgando
 * de Supervisor de Compras**. Eso importa porque del organigrama dependen la firma del supervisor
 * y a quién le llegan los pendientes de su equipo.
 *
 * Aquí se fija que el servidor **aplica lo que el admin confirmó**, y que un cliente viejo —que
 * no manda nada— sigue funcionando con la convención de siempre.
 */
class OrganigramaConfirmadoTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 41;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Organigrama QA', 'subdomain' => 'orgqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        return $user->fresh();
    }

    private function puestos(): array
    {
        return [
            ['name' => 'Gerente',    'area' => 'Gerencia', 'jerarquiaLlaves' => 1, 'esAperturador' => true],
            ['name' => 'Sup. Compras', 'area' => 'Compras', 'jerarquiaLlaves' => 2, 'esAperturador' => false],
            ['name' => 'Sup. Ventas',  'area' => 'Ventas',  'jerarquiaLlaves' => 2, 'esAperturador' => false],
            ['name' => 'Asesor',       'area' => 'Piso',    'jerarquiaLlaves' => 3, 'esAperturador' => false],
        ];
    }

    private function aplicar(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->postJson('/api/v1/admin/onboarding/configure-nicho',
            array_merge(['nicho' => 'retail'], $extra));
    }

    private function jefeDe(string $puesto): ?string
    {
        $fila = DB::table('job_roles')->where('tenant_id', $this->tenantId)->where('name', $puesto)->first();

        if (!$fila || !$fila->reports_to_role_id) {
            return null;
        }

        return DB::table('job_roles')->where('id', $fila->reports_to_role_id)->value('name');
    }

    // ---------------- la sugerencia ----------------

    public function test_el_catalogo_sugiere_a_quien_reporta_cada_puesto(): void
    {
        $respuesta = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/onboarding/catalogo?nicho=retail');

        $respuesta->assertStatus(200);

        foreach ($respuesta->json('puestos') as $p) {
            $this->assertArrayHasKey('reporta_a', $p,
                "'{$p['name']}' no trae sugerencia: el asistente no tendría qué pintar");
        }

        // La cabeza del giro no reporta a nadie.
        $mando = collect($respuesta->json('puestos'))->firstWhere('jerarquiaLlaves', 1);
        $this->assertNull($mando['reporta_a']);
    }

    public function test_la_convencion_es_una_sola_implementacion(): void
    {
        // Si el frontend la recalculara por su cuenta habría dos versiones de la misma regla.
        $sugerido = OrganigramaSugerido::para($this->puestos());

        $this->assertNull($sugerido['Gerente']);
        $this->assertSame('Gerente', $sugerido['Sup. Compras']);
        $this->assertSame('Gerente', $sugerido['Sup. Ventas']);
        // El defecto que el jefe corrigió a mano: el asesor cuelga del PRIMER supervisor.
        $this->assertSame('Sup. Compras', $sugerido['Asesor']);
    }

    public function test_los_puestos_sin_nivel_declarado_no_participan(): void
    {
        // `jerarquiaLlaves` 0 = sembrado al crear la empresa. Tomarlo como el nivel más alto
        // hacía que la cabeza real terminara reportándole a un puesto viejo.
        $sugerido = OrganigramaSugerido::para(array_merge(
            [['name' => 'Puesto viejo', 'jerarquiaLlaves' => 0]],
            $this->puestos()
        ));

        $this->assertNull($sugerido['Gerente'], 'la cabeza no cuelga de un puesto sin nivel');
        $this->assertNull($sugerido['Puesto viejo']);
    }

    // ---------------- lo confirmado manda ----------------

    public function test_el_servidor_aplica_lo_que_el_admin_confirmo(): void
    {
        $puestos = $this->puestos();
        // El admin arrastra la línea: el asesor pasa de Compras (la sugerencia) a Ventas.
        $puestos[0]['reporta_a'] = null;
        $puestos[1]['reporta_a'] = 'Gerente';
        $puestos[2]['reporta_a'] = 'Gerente';
        $puestos[3]['reporta_a'] = 'Sup. Ventas';

        $this->aplicar(['selected_puestos' => $puestos, 'organigrama_confirmado' => true])
            ->assertStatus(200);

        $this->assertSame('Sup. Ventas', $this->jefeDe('Asesor'),
            'se aplicó la convención en vez de lo que la persona confirmó');
        $this->assertNull($this->jefeDe('Gerente'));
    }

    public function test_lo_confirmado_queda_en_las_dos_representaciones(): void
    {
        // El árbol se dibuja con la línea sólida y el tablero de pendientes lee la punteada:
        // escribir sólo una dejaba la otra vacía.
        $puestos = $this->puestos();
        $puestos[3]['reporta_a'] = 'Sup. Ventas';

        $this->aplicar(['selected_puestos' => $puestos, 'organigrama_confirmado' => true]);

        $asesor = DB::table('job_roles')->where('tenant_id', $this->tenantId)->where('name', 'Asesor')->first();
        $ventasId = DB::table('job_roles')->where('tenant_id', $this->tenantId)->where('name', 'Sup. Ventas')->value('id');

        $this->assertSame($ventasId, $asesor->org_parent_role_id, 'línea sólida del árbol');
        $this->assertSame([$ventasId], json_decode($asesor->reports_to_role_ids, true), 'línea punteada');
        $this->assertSame($ventasId, $asesor->reports_to_role_id);
    }

    public function test_queda_registrado_que_una_persona_lo_reviso(): void
    {
        $this->aplicar(['selected_puestos' => $this->puestos(), 'organigrama_confirmado' => true]);

        $this->assertTrue(json_decode(DB::table('system_settings')
            ->where('tenant_id', $this->tenantId)->where('key', 'organigrama_confirmado')->value('value'), true));
    }

    // ---------------- compatibilidad ----------------

    public function test_un_cliente_viejo_que_no_manda_nada_sigue_funcionando(): void
    {
        // Sin `reporta_a` se cae a la convención de siempre: nada de lo desplegado se rompe.
        $this->aplicar(['selected_puestos' => $this->puestos()])->assertStatus(200);

        $this->assertSame('Gerente', $this->jefeDe('Sup. Compras'));
        $this->assertSame('Sup. Compras', $this->jefeDe('Asesor'));

        $this->assertFalse(json_decode(DB::table('system_settings')
            ->where('tenant_id', $this->tenantId)->where('key', 'organigrama_confirmado')->value('value'), true),
            'no se marca como revisado lo que nadie revisó');
    }

    public function test_un_jefe_inventado_deja_al_puesto_sin_jefe_en_vez_de_reventar(): void
    {
        $puestos = $this->puestos();
        $puestos[3]['reporta_a'] = 'Puesto Que No Existe';

        $this->aplicar(['selected_puestos' => $puestos, 'organigrama_confirmado' => true])
            ->assertStatus(200);

        $this->assertNull($this->jefeDe('Asesor'));
    }
}
