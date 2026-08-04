<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guardarraíl de `configureNicho`: toda tarea recibida debe declarar `estimated_mins`.
 *
 * Antes existía un default silencioso de 15 min. Ese default hizo que el costo en pesos de cada
 * tarea saliera mal durante meses sin un solo error visible — la familia de defectos que toda
 * esta auditoría vino a cerrar. La regla ahora es: mejor un 422 claro que un dato financiero
 * incorrecto que "funciona".
 */
class GuardarrailMinutosTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 11;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Guardarrail QA', 'subdomain' => 'guardqa',
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

    private function aplicar(array $tareas): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->postJson('/api/v1/admin/onboarding/configure-nicho', [
            'nicho' => 'restaurante',
            'selected_puestos' => [
                ['name' => 'Gerente de Restaurante', 'esAperturador' => true, 'jerarquiaLlaves' => 1, 'area' => 'Administración'],
            ],
            'selected_tareas' => $tareas,
        ]);
    }

    private function tarea(array $extra = []): array
    {
        return array_merge([
            'title' => 'Tarea de prueba', 'estimated_mins' => 20, 'priority' => 'normal',
            'category' => 'operativo', 'target_role_name' => 'Gerente de Restaurante',
            'assistant_type' => 'ninguno', 'assistant_prompt' => '',
        ], $extra);
    }

    public function test_una_tarea_sin_minutos_se_rechaza_con_error_claro(): void
    {
        // EL CASO DEL GUARDARRAÍL: antes esto pasaba en silencio con 15 min inventados.
        $r = $this->aplicar([
            $this->tarea(['title' => 'Tarea completa']),
            (function ($t) { unset($t['estimated_mins']); return $t; })($this->tarea(['title' => 'Tarea coja'])),
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('Tarea coja', $r->json('message'));
        $this->assertStringNotContainsString('Tarea completa', $r->json('message'),
            'El error debe señalar SOLO a las tareas que vienen mal.');
    }

    public function test_no_se_crea_nada_cuando_una_tarea_viene_coja(): void
    {
        // El rechazo debe ser atómico: o entra todo el catálogo o no entra nada. Una empresa
        // configurada a medias es el mismo H27 por otra puerta.
        $coja = $this->tarea(['title' => 'Tarea coja']);
        unset($coja['estimated_mins']);

        $this->aplicar([$this->tarea(), $coja])->assertStatus(422);

        $this->assertSame(0, DB::table('tasks')->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(0, DB::table('routines')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_minutos_en_cero_tampoco_pasan(): void
    {
        // 0 minutos = costo $0: igual de silencioso y falso que el default de 15.
        $this->aplicar([$this->tarea(['estimated_mins' => 0])])->assertStatus(422);
    }

    public function test_las_tareas_completas_siguen_entrando_con_sus_minutos_exactos(): void
    {
        $this->aplicar([$this->tarea(['title' => 'Arqueo', 'estimated_mins' => 37])])->assertStatus(200);

        $this->assertSame(37, (int) DB::table('tasks')->where('tenant_id', $this->tenantId)
            ->where('title', 'Arqueo')->value('estimated_mins'),
            'Los minutos deben guardarse tal cual, no un default.');
    }

    public function test_los_defaults_del_catalogo_JSON_pasan_el_guardarrail(): void
    {
        // La otra ruta de entrada (sin selección → catálogo del servidor) también atraviesa el
        // guardarraíl; los catálogos ya están validados por CatalogoOnboardingValidoTest, así
        // que deben pasar siempre. Si esto falla, el catálogo tiene una tarea coja de verdad.
        $this->actingAs($this->admin())->postJson('/api/v1/admin/onboarding/configure-nicho', [
            'nicho' => 'restaurante',
        ])->assertStatus(200);

        $this->assertGreaterThan(0, DB::table('tasks')->where('tenant_id', $this->tenantId)->count());
    }
}
