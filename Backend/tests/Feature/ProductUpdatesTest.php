<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Producto',
            'email' => 'producto@talent360.test',
            'password' => bcrypt('secret'),
            'role' => 'platform_admin',
            'is_active' => true,
        ]);
    }

    public function test_solo_plataforma_puede_publicar_novedades(): void
    {
        $tenantAdmin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($tenantAdmin)
            ->putJson('/api/v1/platform/product-updates', ['updates' => []])
            ->assertForbidden();

        $this->actingAs($this->platformAdmin())
            ->putJson('/api/v1/platform/product-updates', ['updates' => []])
            ->assertOk()
            ->assertJsonPath('updates', []);
    }

    public function test_cliente_solo_recibe_avisos_activos_y_ya_publicados(): void
    {
        $admin = $this->platformAdmin();
        $payload = [
            [
                'id' => 'visible',
                'title' => 'Nueva bandeja de incidencias',
                'summary' => 'La bandeja ya está disponible en el Reloj Checador.',
                'published_at' => now()->subMinute()->toIso8601String(),
                'is_active' => true,
                'target_module' => 'reloj',
            ],
            [
                'id' => 'borrador',
                'title' => 'Todavía no',
                'summary' => 'Este texto no debe llegar a los clientes.',
                'published_at' => now()->subMinute()->toIso8601String(),
                'is_active' => false,
                'target_module' => null,
            ],
            [
                'id' => 'futuro',
                'title' => 'Programado',
                'summary' => 'Este aviso se publicará después.',
                'published_at' => now()->addDay()->toIso8601String(),
                'is_active' => true,
                'target_module' => 'dashboard',
            ],
        ];

        $this->actingAs($admin)
            ->putJson('/api/v1/platform/product-updates', ['updates' => $payload])
            ->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/v1/product-updates')
            ->assertOk()
            ->assertJsonCount(1, 'updates')
            ->assertJsonPath('updates.0.id', 'visible')
            ->assertJsonMissing(['id' => 'borrador'])
            ->assertJsonMissing(['id' => 'futuro']);
    }

    public function test_novedades_requieren_sesion(): void
    {
        $this->getJson('/api/v1/product-updates')->assertUnauthorized();
    }
}
