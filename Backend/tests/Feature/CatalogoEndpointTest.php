<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /admin/onboarding/catalogo — la mitad "de ida" del contrato con el wizard.
 *
 * Lo que estas pruebas fijan es el CONTRATO (`CONTRATO_API_CATALOGO_2026-08-03.md`), no un
 * detalle de implementación: el wizard del jefe va a sustituir su `PRESET_DATA` por esta
 * respuesta, así que cambiar aquí un nombre de campo rompe su pantalla de alta. Si alguna de
 * estas pruebas estorba, lo que hay que renegociar es el contrato, no la prueba.
 */
class CatalogoEndpointTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 9;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Catalogo QA', 'subdomain' => 'catqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        return $user->fresh();
    }

    private function pedir(string $nicho): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->usuario('admin'))
            ->getJson('/api/v1/admin/onboarding/catalogo?nicho=' . urlencode($nicho));
    }

    // ---------------- acceso ----------------

    public function test_sin_sesion_no_hay_catalogo(): void
    {
        $this->getJson('/api/v1/admin/onboarding/catalogo?nicho=retail')->assertStatus(401);
    }

    public function test_un_empleado_no_puede_leer_el_catalogo(): void
    {
        // La ruta vive en el grupo role:admin,supervisor — el catálogo es parte del alta de la
        // empresa, no algo que el colaborador de piso consulte.
        $this->actingAs($this->usuario('empleado'))
            ->getJson('/api/v1/admin/onboarding/catalogo?nicho=retail')
            ->assertStatus(403);
    }

    public function test_el_giro_es_obligatorio(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->getJson('/api/v1/admin/onboarding/catalogo')
            ->assertStatus(422);
    }

    // ---------------- el contrato ----------------

    public function test_devuelve_puestos_y_tareas_del_giro(): void
    {
        $this->pedir('materias_primas')
            ->assertStatus(200)
            ->assertJson(['success' => true, 'nicho' => 'materias_primas'])
            ->assertJsonCount(7, 'puestos')
            ->assertJsonCount(92, 'tareas');
    }

    public function test_los_puestos_traen_los_campos_que_el_wizard_ya_usa(): void
    {
        // Los nombres son los del PRESET_DATA a propósito: el filtrado del wizard
        // (`p.name`, `jerarquiaLlaves`) debe funcionar sin tocarse.
        $respuesta = $this->pedir('restaurante')->assertStatus(200);

        foreach ($respuesta->json('puestos') as $p) {
            foreach (['name', 'area', 'esAperturador', 'jerarquiaLlaves'] as $campo) {
                $this->assertArrayHasKey($campo, $p, "El puesto '{$p['name']}' no trae '{$campo}'.");
            }
        }
    }

    public function test_las_tareas_traen_los_dos_campos_que_el_preset_del_frontend_no_tiene(): void
    {
        // LA RAZÓN DE SER DEL ENDPOINT: estimated_mins (costo en pesos) y momento (rutina de
        // apertura). Si esta prueba falla, el wizard vuelve a mandar tareas cojas y el defecto
        // regresa en silencio.
        $tareas = $this->pedir('materias_primas')->json('tareas');

        foreach ($tareas as $t) {
            $this->assertArrayHasKey('estimated_mins', $t, "'{$t['title']}' sin estimated_mins.");
        }

        $conMomento = array_filter($tareas, fn ($t) => isset($t['momento']));
        $this->assertNotEmpty($conMomento,
            'Ninguna tarea trae momento: la rutina de apertura no podría armarse.');

        // Y los demás campos que el wizard ya consume hoy:
        foreach (['title', 'category', 'priority', 'assistant_type', 'assistant_prompt', 'target_role_name'] as $campo) {
            $this->assertArrayHasKey($campo, $tareas[0], "Las tareas no traen '{$campo}'.");
        }
    }

    public function test_reposteria_es_el_mismo_catalogo_que_materias_primas(): void
    {
        $this->assertSame(
            $this->pedir('materias_primas')->json('tareas'),
            $this->pedir('reposteria')->json('tareas')
        );
    }

    public function test_un_giro_desconocido_devuelve_retail_como_el_wizard(): void
    {
        // Replica el `|| PRESET_DATA.retail` del frontend para que el caso 'custom' siga igual.
        $this->pedir('custom')
            ->assertStatus(200)
            ->assertJson(['success' => true, 'nicho' => 'retail']);
    }

    public function test_las_mayusculas_del_giro_no_importan(): void
    {
        $this->pedir('RESTAURANTE')->assertStatus(200)->assertJson(['nicho' => 'restaurante']);
    }

    public function test_un_giro_malicioso_no_lee_archivos_del_servidor(): void
    {
        // `nicho` llega de la petición; sin la criba de CatalogoOnboarding, `../../.env` sería
        // un "catálogo". Debe caer al retail por defecto, jamás resolver la ruta.
        $this->pedir('../../.env')
            ->assertStatus(200)
            ->assertJson(['nicho' => 'retail']);
    }

    public function test_el_catalogo_servido_es_el_que_configure_nicho_aplica(): void
    {
        // El cierre del círculo: lo que el endpoint sirve y lo que el wizard reenvía tal cual
        // debe dejar los mismos puestos que si el backend hubiera usado sus defaults. Si las dos
        // rutas divergen, volvemos a tener dos catálogos.
        $catalogo = $this->pedir('restaurante')->json();

        $this->actingAs($this->usuario('admin'))
            ->postJson('/api/v1/admin/onboarding/configure-nicho', [
                'nicho' => 'restaurante',
                'selected_puestos' => $catalogo['puestos'],
                'selected_tareas' => $catalogo['tareas'],
            ])
            ->assertStatus(200);

        $puestos = DB::table('job_roles')->where('tenant_id', $this->tenantId)->pluck('name');

        foreach (array_column($catalogo['puestos'], 'name') as $nombre) {
            $this->assertContains($nombre, $puestos->all(),
                "El puesto '{$nombre}' servido por el catálogo no quedó creado al aplicarlo.");
        }

        $this->assertSame(
            count($catalogo['tareas']),
            DB::table('tasks')->where('tenant_id', $this->tenantId)->count(),
            'Aplicar exactamente lo servido debe crear exactamente esas tareas.'
        );
    }
}
