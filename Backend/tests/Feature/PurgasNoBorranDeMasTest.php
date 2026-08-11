<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lo que destruye datos sólo destruye lo que se le dice (2026-08-11).
 *
 * Ninguna de estas operaciones tenía prueba. Los defectos que cubren:
 *
 *  - `tenant:purge-test-tenants` consideraba "de prueba" a TODA empresa con id > 1 —no existe
 *    ninguna bandera que las distinga— y las borraba FÍSICAMENTE con sus fichajes y sus recibos
 *    de nómina. Y `deploy_to_hetzner.py` lo ejecutaba con `--force` en CADA despliegue, dentro de
 *    un paso rotulado "(non-destructive)".
 *  - `purgeArchive` sin `tenant_id` borraba el archivo histórico de TODAS las empresas, que es
 *    justo el caso del platform_admin (no tiene empresa propia). Su guardia de rol comparaba
 *    `system_role`, un campo que el backend no tiene: respondía 403 a todo el mundo.
 */
class PurgasNoBorranDeMasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresaA;
    private Tenant $empresaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresaA = Tenant::create([
            'name' => 'Empresa A', 'subdomain' => 'empresa-a', 'plan' => 'pro', 'is_active' => true,
        ]);
        $this->empresaB = Tenant::create([
            'name' => 'Empresa B', 'subdomain' => 'empresa-b', 'plan' => 'pro', 'is_active' => true,
        ]);
    }

    private static int $siguienteOriginal = 1000;

    private function archivo(int $tenantId, string $fecha = '2026-08-01'): void
    {
        $user = User::factory()->create(['tenant_id' => $tenantId]);

        DB::table('archived_time_entries')->insert([
            'original_id' => static::$siguienteOriginal++, 'user_id' => $user->id, 'tenant_id' => $tenantId,
            'date' => $fecha, 'type' => 'check_in', 'time' => '09:00',
            'archived_reason' => 'prueba', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --- Purga del archivo histórico -----------------------------------------------------

    public function test_purgar_el_archivo_sin_decir_la_empresa_no_borra_nada(): void
    {
        $this->archivo($this->empresaA->id);
        $this->archivo($this->empresaB->id);

        $platformAdmin = User::factory()->create(['role' => 'platform_admin', 'tenant_id' => null]);

        $this->actingAs($platformAdmin)
            ->postJson('/api/v1/sync/purge_archive', [])
            ->assertStatus(422);

        $this->assertSame(2, DB::table('archived_time_entries')->count(),
            'sin empresa el DELETE iba contra la tabla entera: el archivo de TODOS los clientes');
    }

    public function test_purgar_el_archivo_de_una_empresa_no_toca_el_de_la_otra(): void
    {
        $this->archivo($this->empresaA->id);
        $this->archivo($this->empresaB->id);

        $platformAdmin = User::factory()->create(['role' => 'platform_admin', 'tenant_id' => null]);

        $this->actingAs($platformAdmin)
            ->postJson('/api/v1/sync/purge_archive', ['tenant_id' => $this->empresaA->id])
            // Y de paso: este endpoint respondía 403 a TODO EL MUNDO por una guardia que
            // comparaba un campo inexistente en el servidor. El botón nunca pudo funcionar.
            ->assertStatus(200);

        $this->assertSame(0, DB::table('archived_time_entries')->where('tenant_id', $this->empresaA->id)->count());
        $this->assertSame(1, DB::table('archived_time_entries')->where('tenant_id', $this->empresaB->id)->count());
    }

    // --- Borrado de empresas ----------------------------------------------------------------

    public function test_el_comando_de_purga_no_borra_nada_si_no_se_le_dicen_los_ids(): void
    {
        $salida = Artisan::call('tenant:purge-test-tenants', ['--force' => true]);

        $this->assertSame(1, $salida, 'sin --tenants tiene que negarse');
        $this->assertDatabaseHas('tenants', ['id' => $this->empresaA->id]);
        $this->assertDatabaseHas('tenants', ['id' => $this->empresaB->id]);
    }

    public function test_el_comando_de_purga_borra_solo_la_empresa_indicada(): void
    {
        Artisan::call('tenant:purge-test-tenants', [
            '--tenants' => (string) $this->empresaB->id,
            '--force' => true,
        ]);

        $this->assertDatabaseHas('tenants', ['id' => $this->empresaA->id]);
        $this->assertDatabaseMissing('tenants', ['id' => $this->empresaB->id]);
    }

    public function test_el_comando_de_purga_nunca_borra_la_empresa_uno(): void
    {
        $salida = Artisan::call('tenant:purge-test-tenants', ['--tenants' => '1', '--force' => true]);

        $this->assertSame(1, $salida);
    }
}
