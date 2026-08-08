<?php

namespace Tests\Feature;

use App\Models\MealPhotoEvidence;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Evidencia de comedor: deja de ser un archivo público (ronda 2026-08-08).
 *
 * La foto de la cara de un colaborador se guardaba en `public_path('uploads/meal-evidence')`,
 * o sea servida por nginx como estático SIN autenticación. Comprobado en el servidor de
 * pruebas antes del arreglo: `GET /uploads/meal-evidence/1/meal_meal_start_1_...jpg`
 * respondía **200 image/jpeg** a cualquiera, y el nombre era medio adivinable
 * (tipo + user_id + fecha + 6 al azar). Encima la purga a 90 días borraba la FILA y dejaba
 * el archivo público para siempre: en el servidor había un biométrico huérfano de julio.
 */
class EvidenciaComedorPrivadaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::create([
            'name' => 'Comedor QA', 'subdomain' => 'comedorqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador',
            'email' => 'colab@comedorqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
    }

    /** 1x1 png en base64, como lo manda la cámara del reloj. */
    private function fotoBase64(): string
    {
        return 'data:image/png;base64,' . base64_encode(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
    }

    private function subirFoto(?User $quien = null): string
    {
        $res = $this->actingAs($quien ?? $this->colaborador)
            ->postJson('/api/v1/clock/meal-photo', [
                'type' => 'meal_start',
                'date' => now()->toDateString(),
                'image' => $this->fotoBase64(),
            ]);

        $res->assertOk();

        return $res->json('url');
    }

    public function test_la_foto_no_queda_en_la_carpeta_publica(): void
    {
        $url = $this->subirFoto();

        $fila = MealPhotoEvidence::withoutGlobalScopes()->first();

        $this->assertStringNotContainsString('uploads/', $fila->path,
            'la evidencia no puede vivir donde nginx sirve estáticos');
        $this->assertStringStartsWith('meal-evidence/', $fila->path);
        Storage::disk('local')->assertExists($fila->path);
        $this->assertFileDoesNotExist(public_path($fila->path));

        // La URL ya no es un archivo: es el endpoint que valida quién pregunta.
        $this->assertStringStartsWith('/api/v1/clock/meal-evidence/', $url);
    }

    public function test_el_nombre_en_disco_es_uuid_y_no_delata_a_la_persona(): void
    {
        $this->subirFoto();

        $path = MealPhotoEvidence::withoutGlobalScopes()->value('path');

        // Antes: "meal_meal_start_{user_id}_{YmdHis}_{6 al azar}.jpg" — adivinable a fuerza
        // bruta sabiendo el id y el día. Ahora el nombre no dice de quién es ni de cuándo.
        $this->assertMatchesRegularExpression(
            '#^meal-evidence/' . $this->tenant->id . '/[0-9a-f-]{36}\.\w+$#',
            $path
        );
        $this->assertStringNotContainsString('meal_start', basename($path));
        $this->assertStringNotContainsString(now()->format('Ymd'), basename($path));
    }

    public function test_el_dueno_de_la_cara_puede_verla(): void
    {
        $url = $this->subirFoto();

        $this->actingAs($this->colaborador)
            ->get(str_replace('/api/v1', '/api/v1', $url))
            ->assertOk();
    }

    public function test_un_mando_de_la_misma_empresa_puede_verla(): void
    {
        $url = $this->subirFoto();

        $jefa = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@comedorqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($jefa)->get($url)->assertOk();
    }

    public function test_un_companero_no_puede_ver_la_cara_de_otro(): void
    {
        $url = $this->subirFoto();

        $otro = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Otro', 'email' => 'otro@comedorqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $this->actingAs($otro)->get($url)->assertForbidden();
    }

    public function test_nadie_de_otra_empresa_la_ve(): void
    {
        $url = $this->subirFoto();

        $otroTenant = Tenant::create([
            'name' => 'Ajena', 'subdomain' => 'ajena', 'plan' => 'enterprise', 'is_active' => true,
        ]);
        $ajeno = User::create([
            'tenant_id' => $otroTenant->id, 'name' => 'Ajeno', 'email' => 'ajeno@ajena.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($ajeno)->get($url)->assertNotFound();
    }

    public function test_sin_sesion_no_se_entrega(): void
    {
        $url = $this->subirFoto();

        // Subir exigió sesión; hay que soltarla para preguntar como un desconocido (que es
        // exactamente lo que antes bastaba para bajar la foto del servidor estático).
        $this->app['auth']->forgetGuards();

        $this->getJson($url)->assertUnauthorized();
    }

    public function test_un_uuid_inventado_no_filtra_nada(): void
    {
        $this->subirFoto();

        // Formato válido pero inexistente: 404, sin pistas.
        $this->actingAs($this->colaborador)
            ->getJson('/api/v1/clock/meal-evidence/11111111-2222-3333-4444-555555555555')
            ->assertNotFound();

        // Y nada de colar comodines en el LIKE de la consulta.
        $this->actingAs($this->colaborador)
            ->getJson('/api/v1/clock/meal-evidence/%')
            ->assertNotFound();
    }

    public function test_la_purga_borra_el_archivo_privado_y_no_deja_huerfanos(): void
    {
        $this->subirFoto();

        $fila = MealPhotoEvidence::withoutGlobalScopes()->first();
        // Se envejece la fila más allá de la retención.
        MealPhotoEvidence::withoutGlobalScopes()
            ->where('id', $fila->id)
            ->update(['date' => now()->subDays(120)->toDateString()]);

        $this->artisan('meal-evidence:purge')->assertSuccessful();

        $this->assertDatabaseCount('meal_photo_evidences', 0);
        Storage::disk('local')->assertMissing($fila->path);
    }
}
