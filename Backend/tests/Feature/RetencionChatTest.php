<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\RetencionChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bloque 2 / D3 (2026-08-13): retención del chat configurable por empresa (7 días por
 * defecto, tope 30), sobre la tabla VIVA (`internal_messages` — la purga vieja barría
 * `team_chat_messages`, que nada lee ni escribe). Los privados y el megáfono esperan la
 * decisión (c) de D3: no se tocan. Un mensaje conservado (📌) no se purga jamás.
 */
class RetencionChatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Chat QA', 'subdomain' => 'chatqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@chatqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function mensaje(array $attrs): int
    {
        return DB::table('internal_messages')->insertGetId(array_merge([
            'tenant_id' => $this->tenant->id,
            'sender_id' => $this->admin->id,
            'receiver_id' => null,
            'type' => 'general',
            'content' => 'hola equipo',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ], $attrs));
    }

    public function test_la_purga_respeta_privados_megafono_y_conservados(): void
    {
        $viejoDeEquipo = $this->mensaje([]);
        $privado = $this->mensaje(['receiver_id' => $this->admin->id, 'type' => 'private']);
        $megafono = $this->mensaje(['type' => 'announcement']);
        $conservado = $this->mensaje(['preserved_at' => now()]);
        $reciente = $this->mensaje(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $this->artisan('chat:clean-old-messages')->assertSuccessful();

        $vivos = DB::table('internal_messages')->pluck('id')->all();
        $this->assertNotContains($viejoDeEquipo, $vivos, 'el mensaje de equipo viejo se purga');
        $this->assertContains($privado, $vivos, 'los privados esperan la decisión (c) de D3');
        $this->assertContains($megafono, $vivos, 'el megáfono espera la decisión (c) de D3');
        $this->assertContains($conservado, $vivos, 'lo citado en un incidente se conserva SIEMPRE');
        $this->assertContains($reciente, $vivos, 'lo reciente no se toca');
    }

    public function test_la_retencion_es_por_empresa_y_con_tope_de_30(): void
    {
        // Esta empresa elige 3 días; una segunda se queda con el default (7).
        DB::table('system_settings')->insert([
            'tenant_id' => $this->tenant->id, 'key' => 'chatRetentionDays',
            'value' => json_encode(3), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otra = Tenant::create(['name' => 'Otra', 'subdomain' => 'otraqa', 'plan' => 'basic', 'is_active' => true]);
        $suAdmin = User::create([
            'tenant_id' => $otra->id, 'name' => 'Otro', 'email' => 'otro@otraqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $deCinco = $this->mensaje(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);
        $ajeno = DB::table('internal_messages')->insertGetId([
            'tenant_id' => $otra->id, 'sender_id' => $suAdmin->id, 'receiver_id' => null,
            'type' => 'general', 'content' => 'hola', 'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $this->artisan('chat:clean-old-messages')->assertSuccessful();

        $vivos = DB::table('internal_messages')->pluck('id')->all();
        $this->assertNotContains($deCinco, $vivos, 'con retención 3, lo de hace 5 días se va');
        $this->assertContains($ajeno, $vivos, 'la otra empresa sigue en 7: lo de hace 5 días se queda');

        // El tope: 90 configurados se tratan como 30.
        DB::table('system_settings')->where('tenant_id', $this->tenant->id)
            ->where('key', 'chatRetentionDays')->update(['value' => json_encode(90)]);
        $this->assertSame(30, RetencionChat::dias($this->tenant->id));
    }

    /**
     * Ronda adversarial: un JSON no numérico casteado a (int) daba 1 — la retención MÁS
     * agresiva, en silencio. Ante basura se retiene MÁS (default 7), y el endpoint de
     * settings rechaza el valor inválido en vez de guardarlo.
     */
    public function test_un_valor_basura_no_colapsa_la_retencion_a_un_dia(): void
    {
        foreach ([['days' => 14], true, -5, 0, 'chatarra'] as $basura) {
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $this->tenant->id, 'key' => 'chatRetentionDays'],
                ['value' => json_encode($basura), 'created_at' => now(), 'updated_at' => now()]
            );
            $this->assertSame(7, RetencionChat::dias($this->tenant->id),
                'con basura configurada (' . json_encode($basura) . ') se retiene el default, nunca 1 día');
        }

        $this->actingAs($this->admin)->postJson('/api/v1/sync/settings', [
            'key' => 'chatRetentionDays', 'value' => ['days' => 14],
        ])->assertStatus(422);

        $this->actingAs($this->admin)->postJson('/api/v1/sync/settings', [
            'key' => 'chatRetentionDays', 'value' => 14,
        ])->assertOk();
        $this->assertSame(14, RetencionChat::dias($this->tenant->id));
    }

    /** Ronda adversarial: el dial no puede fabricar megáfonos inmunes a la purga. */
    public function test_el_dial_no_puede_mandar_type_announcement(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/sync/message', [
            'content' => 'quiero ser eterno', 'type' => 'announcement',
        ])->assertStatus(422);
    }

    public function test_conservar_y_soltar_un_mensaje_desde_el_endpoint(): void
    {
        $id = $this->mensaje(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/dashboard/messages/{$id}/preserve")
            ->assertOk()->assertJsonPath('preserved', true);
        $this->assertNotNull(DB::table('internal_messages')->find($id)->preserved_at);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/dashboard/messages/{$id}/preserve")
            ->assertOk()->assertJsonPath('preserved', false);
        $this->assertNull(DB::table('internal_messages')->find($id)->preserved_at);
    }

    public function test_no_se_puede_conservar_un_mensaje_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Ajena', 'subdomain' => 'ajenaqa', 'plan' => 'basic', 'is_active' => true]);
        $suAdmin = User::create([
            'tenant_id' => $otra->id, 'name' => 'Ajeno', 'email' => 'ajeno@ajenaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $id = $this->mensaje([]);

        $this->actingAs($suAdmin)->postJson("/api/v1/admin/dashboard/messages/{$id}/preserve")
            ->assertStatus(404);
        $this->assertNull(DB::table('internal_messages')->find($id)->preserved_at);
    }

    public function test_el_monitor_dice_la_retencion_y_si_hay_ia(): void
    {
        // La config se fija explícita: el .env del entorno local trae llave y se colaba.
        config(['services.gemini.api_key' => null]);

        $respuesta = $this->actingAs($this->admin)->getJson('/api/v1/admin/dashboard/monitor')
            ->assertOk();

        $this->assertSame(7, $respuesta->json('data.chat_retention_days'));
        $this->assertFalse($respuesta->json('data.ia_disponible'), 'sin llave, el Plan IA no se ofrece');

        config(['services.gemini.api_key' => 'llave-de-prueba']);
        $this->assertTrue(\App\Services\GeminiAIService::disponible());
    }
}
