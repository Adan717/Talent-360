<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\FacturapiBillingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La puerta del sello fiscal está cerrada (2026-08-26).
 *
 * El CSD es el sello con el que se firma ante el SAT a nombre de una empresa: el equivalente
 * digital de su firma. Con el timbrado apagado, aceptarlo sería custodiar la firma fiscal de un
 * cliente **para no usarla nunca** — puro riesgo sin beneficio.
 */
class SelloFiscalCerradoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Nada debe salir hacia el PAC: si algo lo intenta, esto lo delata.
        Http::preventStrayRequests();

        $this->tenant = Tenant::create(['name' => 'Sello QA', 'subdomain' => 'selloqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@selloqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    // -------------------------------------------------------------- la puerta

    public function test_subir_un_sello_responde_503_explicado(): void
    {
        $r = $this->actingAs($this->admin)->postJson('/api/v1/billing/csd', [
            'certificate' => base64_encode('contenido-de-un-cer'),
            'private_key' => base64_encode('contenido-de-un-key'),
            'password' => 'la-clave-del-sello',
        ]);

        $r->assertStatus(503)->assertJsonPath('desactivado', true);
        $this->assertStringContainsString('No envíes tus archivos .cer ni .key', $r->json('error'));
    }

    /** Y lo más importante: NO se guarda nada de lo que venía en la petición. */
    public function test_el_sello_enviado_no_se_guarda_en_ninguna_parte(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/billing/csd', [
            'certificate' => base64_encode('contenido-de-un-cer'),
            'private_key' => base64_encode('contenido-de-un-key'),
            'password' => 'la-clave-del-sello',
        ])->assertStatus(503);

        $fila = DB::table('tenants')->where('id', $this->tenant->id)->first();

        $this->assertNull($fila->csd_certificate, 'el certificado no puede quedar guardado');
        $this->assertNull($fila->csd_private_key, 'la llave privada no puede quedar guardada');
        $this->assertNull($fila->csd_password, 'la contraseña del sello no puede quedar guardada');
    }

    /** El proveedor tampoco lo manda al PAC, ni aunque alguien lo llame por otra vía. */
    public function test_el_proveedor_no_envia_el_sello_al_pac(): void
    {
        $this->tenant->update([
            'csd_certificate' => base64_encode('cer'),
            'csd_private_key' => base64_encode('key'),
            'csd_password' => 'clave',
        ]);

        $enviado = app(FacturapiBillingProvider::class)
            ->forTenant($this->tenant->fresh())
            ->uploadCsd('org_de_prueba');

        $this->assertFalse($enviado, 'el sello no sale de este servidor con el timbrado apagado');
    }

    // -------------------------------------------------------------- la purga

    public function test_el_simulacro_no_borra_nada(): void
    {
        $this->tenant->update([
            'csd_certificate' => base64_encode('cer'),
            'csd_private_key' => base64_encode('key'),
            'csd_password' => 'clave',
        ]);

        $this->artisan('billing:purgar-sellos')->assertExitCode(0);

        $this->assertNotNull(DB::table('tenants')->where('id', $this->tenant->id)->value('csd_certificate'));
    }

    public function test_la_purga_retira_las_tres_piezas_del_sello(): void
    {
        $this->tenant->update([
            'csd_certificate' => base64_encode('cer'),
            'csd_private_key' => base64_encode('key'),
            'csd_password' => 'clave',
        ]);

        $this->artisan('billing:purgar-sellos --aplicar')->assertExitCode(0);

        $fila = DB::table('tenants')->where('id', $this->tenant->id)->first();
        $this->assertNull($fila->csd_certificate);
        $this->assertNull($fila->csd_private_key);
        $this->assertNull($fila->csd_password);
    }

    /** Lo que NO es la firma se queda: el RFC y la razón social son datos de la empresa. */
    public function test_la_purga_no_toca_los_datos_fiscales_que_no_son_el_sello(): void
    {
        $this->tenant->update([
            'csd_certificate' => base64_encode('cer'),
            'csd_private_key' => base64_encode('key'),
            'csd_password' => 'clave',
            'rfc' => 'XAXX010101000',
            'tax_name' => 'Sello QA SA de CV',
        ]);

        $this->artisan('billing:purgar-sellos --aplicar')->assertExitCode(0);

        $fresco = $this->tenant->fresh();
        $this->assertSame('XAXX010101000', $fresco->rfc);
        $this->assertSame('Sello QA SA de CV', $fresco->tax_name);
    }

    public function test_sin_sellos_guardados_lo_dice_y_no_hace_nada(): void
    {
        $this->artisan('billing:purgar-sellos --aplicar')
            ->expectsOutputToContain('Ninguna empresa tiene sellos digitales guardados')
            ->assertExitCode(0);
    }

    /** El comando nunca imprime el contenido del sello: es material criptográfico. */
    public function test_nunca_imprime_el_contenido_del_sello(): void
    {
        $this->tenant->update([
            'csd_certificate' => base64_encode('SECRETO-DEL-CERTIFICADO'),
            'csd_private_key' => base64_encode('SECRETO-DE-LA-LLAVE'),
            'csd_password' => 'CLAVE-SECRETA-DEL-SELLO',
        ]);

        $salida = $this->artisan('billing:purgar-sellos')->assertExitCode(0);
        $salida->doesntExpectOutputToContain('CLAVE-SECRETA-DEL-SELLO');
        $salida->doesntExpectOutputToContain(base64_encode('SECRETO-DEL-CERTIFICADO'));
        $salida->run();
    }
}
