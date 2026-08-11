<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La bolsa de trabajo pública dice la verdad (2026-08-11).
 *
 * Son las únicas rutas del ATS sin sesión, y las que ve gente ajena a la empresa. Tenían:
 *
 *  - El interruptor "Web Pública" del gestor de vacantes escribe `is_active`, y el portal NO lo
 *    miraba: apagar una vacante la dejaba publicada y recibiendo postulaciones para siempre.
 *  - `withoutGlobalScopes()` a secas apaga también el borrado lógico: las vacantes borradas
 *    seguían en la bolsa.
 *  - Apagar el portal entero no apagaba nada: la API seguía entregando vacantes y contactos.
 *  - Las alertas de vacante se archivaban en la empresa 1, porque el portal manda `tenant_id: 1`
 *    (no hay sesión de la que sacarlo) y el servidor le hacía caso.
 *  - La dirección pública se validaba sólo contra `public_slug`, pero se resuelve también contra
 *    `subdomain`: una empresa podía quedarse con la bolsa de otra.
 */
class AtsPortalPublicoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private JobRole $puesto;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Bolsa QA', 'subdomain' => 'bolsaqa', 'public_slug' => 'bolsaqa',
            'plan' => 'enterprise', 'is_active' => true, 'public_portal_enabled' => true,
        ]);

        $this->puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@bolsaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function vacante(array $cambios = []): Vacancy
    {
        return Vacancy::create(array_merge([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $this->puesto->id,
            'title' => 'Cajero de mostrador', 'description' => 'x', 'requirements' => '[]',
            'is_active' => true, 'is_hidden' => false,
        ], $cambios));
    }

    private function bolsa(?string $slug = null): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/public/vacancies/' . ($slug ?? $this->tenant->public_slug));
    }

    // --- Qué se publica -----------------------------------------------------------------

    public function test_una_vacante_apagada_no_sale_en_la_bolsa(): void
    {
        $viva = $this->vacante(['title' => 'Sigue abierta']);
        $this->vacante(['title' => 'Ya la cubrimos', 'is_active' => false]);

        $titulos = collect($this->bolsa()->assertStatus(200)->json('vacancies'))->pluck('title');

        $this->assertContains('Sigue abierta', $titulos);
        $this->assertNotContains('Ya la cubrimos', $titulos,
            'el interruptor del gestor dice "Web Pública": si no despublica, miente');
        $this->assertCount(1, $titulos);
        $this->assertNotNull($viva->id);
    }

    public function test_una_vacante_borrada_no_sale_en_la_bolsa(): void
    {
        $borrada = $this->vacante(['title' => 'Borrada']);
        $borrada->delete();

        $this->assertEmpty($this->bolsa()->assertStatus(200)->json('vacancies'));
    }

    public function test_con_el_portal_apagado_no_se_entrega_nada(): void
    {
        $this->vacante();
        $this->tenant->update([
            'public_portal_enabled' => false,
            'portal_custom_settings_json' => json_encode(['contact_email' => 'rh@bolsaqa.test']),
        ]);

        $r = $this->bolsa()->assertStatus(200);

        $this->assertFalse($r->json('tenant.public_portal_enabled'));
        $this->assertEmpty($r->json('vacancies'));
        $this->assertSame('', $r->json('tenant.custom_settings.contact_email'),
            'con el portal apagado tampoco se reparten los datos de contacto');
    }

    // --- Postularse ---------------------------------------------------------------------

    public function test_no_se_puede_postular_a_una_vacante_apagada(): void
    {
        $apagada = $this->vacante(['is_active' => false]);

        $this->postJson('/api/v1/public/candidates', [
            'name' => 'Colado', 'email' => 'colado@x.test', 'applied_vacancy_id' => $apagada->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_no_se_puede_postular_a_una_vacante_borrada(): void
    {
        $borrada = $this->vacante();
        $borrada->delete();

        $this->postJson('/api/v1/public/candidates', [
            'name' => 'Colado', 'email' => 'colado@x.test', 'applied_vacancy_id' => $borrada->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_una_postulacion_normal_si_entra_y_cae_en_su_empresa(): void
    {
        $v = $this->vacante();

        $this->postJson('/api/v1/public/candidates', [
            'name' => 'Ana', 'email' => 'ana@x.test', 'applied_vacancy_id' => $v->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('candidates', [
            'email' => 'ana@x.test', 'tenant_id' => $this->tenant->id, 'status' => 'prospect',
        ]);
    }

    // --- Fast-track: lo decide el servidor ----------------------------------------------

    public function test_el_fast_track_lo_calcula_el_servidor_y_no_lo_puede_forzar_el_cliente(): void
    {
        $v = $this->vacante();

        // Un desconocido no puede auto-marcarse como ex-colaborador.
        $this->postJson('/api/v1/public/candidates', [
            'name' => 'Desconocido', 'email' => 'nuevo@x.test', 'applied_vacancy_id' => $v->id,
            'is_ex_employee_fast_track' => true,
        ])->assertStatus(201);

        $this->assertFalse((bool) DB::table('candidates')->where('email', 'nuevo@x.test')->value('is_ex_employee_fast_track'));

        // Quien SÍ tiene expediente en la empresa (aunque esté archivado) queda marcado.
        $exUser = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ex Colaborador', 'email' => 'ex@bolsaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => false,
        ]);
        $ex = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $exUser->id,
            'name' => 'Ex Colaborador', 'email' => 'ex@bolsaqa.test',
            'job_role_id' => $this->puesto->id, 'is_active_employee' => false,
            'salary' => 3000, 'base_salary' => 3000,
        ]);
        $ex->delete();

        $this->postJson('/api/v1/public/candidates', [
            'name' => 'Ex Colaborador', 'email' => 'ex@bolsaqa.test', 'applied_vacancy_id' => $v->id,
        ])->assertStatus(201);

        $this->assertTrue((bool) DB::table('candidates')->where('email', 'ex@bolsaqa.test')->value('is_ex_employee_fast_track'),
            'la etiqueta "Fast-Track" existía en pantalla y nadie la encendía jamás');
    }

    // --- Alertas de vacante ---------------------------------------------------------------

    public function test_la_alerta_se_archiva_en_la_empresa_del_portal_aunque_el_cliente_diga_otra(): void
    {
        $otra = Tenant::create([
            'name' => 'La Otra', 'subdomain' => 'laotra', 'public_slug' => 'laotra',
            'plan' => 'pro', 'is_active' => true,
        ]);

        $this->postJson('/api/v1/public/vacancy-alerts', [
            'slug' => $this->tenant->public_slug,
            'email' => 'interesado@x.test',
            'job_role_name' => 'Cajero',
            // Es justo lo que manda el portal público hoy: el tenant 1 por defecto.
            'tenant_id' => $otra->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('vacancy_alerts', [
            'email' => 'interesado@x.test', 'tenant_id' => $this->tenant->id,
        ]);
        $this->assertDatabaseMissing('vacancy_alerts', ['tenant_id' => $otra->id]);
    }

    public function test_sin_slug_no_se_puede_elegir_en_que_empresa_se_archiva_el_correo(): void
    {
        $otra = Tenant::create([
            'name' => 'La Otra 4', 'subdomain' => 'laotra4', 'public_slug' => 'laotra4',
            'plan' => 'pro', 'is_active' => true,
        ]);

        $this->postJson('/api/v1/public/vacancy-alerts', [
            'email' => 'colado@x.test', 'job_role_name' => 'Cajero', 'tenant_id' => $otra->id,
        ])->assertStatus(422)->assertJsonValidationErrors('slug');

        $this->assertDatabaseCount('vacancy_alerts', 0);
    }

    public function test_la_empresa_por_fin_puede_ver_quien_pidio_aviso(): void
    {
        $otra = Tenant::create([
            'name' => 'La Otra 2', 'subdomain' => 'laotra2', 'public_slug' => 'laotra2',
            'plan' => 'pro', 'is_active' => true,
        ]);
        DB::table('vacancy_alerts')->insert([
            ['tenant_id' => $this->tenant->id, 'email' => 'mio@x.test', 'job_role_name' => 'Cajero', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $otra->id, 'email' => 'ajeno@x.test', 'job_role_name' => 'Cajero', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $lista = $this->actingAs($this->admin)->getJson('/api/v1/admin/vacancy-alerts')
            ->assertStatus(200)->json();

        $correos = collect($lista)->pluck('email');
        $this->assertContains('mio@x.test', $correos);
        $this->assertNotContains('ajeno@x.test', $correos);
    }

    // --- La dirección pública ---------------------------------------------------------------

    /**
     * El hueco NO era el choque de `public_slug` contra `public_slug` —eso ya lo rechazaba la
     * regla `unique`—, sino el SUBDOMINIO: el portal resuelve por las dos columnas y la
     * validación sólo miraba una. La víctima de esta prueba tiene su bolsa publicada en su
     * subdominio, con un `public_slug` distinto.
     */
    public function test_una_empresa_no_puede_quedarse_con_el_subdominio_de_otra(): void
    {
        $victima = Tenant::create([
            'name' => 'La Codiciada', 'subdomain' => 'codiciada', 'public_slug' => 'otra-direccion',
            'plan' => 'pro', 'is_active' => true,
        ]);
        $puestoVictima = JobRole::create([
            'tenant_id' => $victima->id, 'name' => 'Mesero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);
        Vacancy::create([
            'tenant_id' => $victima->id, 'job_role_id' => $puestoVictima->id,
            'title' => 'De la Codiciada', 'description' => 'x', 'requirements' => '[]',
            'is_active' => true, 'is_hidden' => false,
        ]);

        // Su bolsa responde en /codiciada antes del intento.
        $this->assertSame(['De la Codiciada'],
            collect($this->bolsa('codiciada')->json('vacancies'))->pluck('title')->all());

        $this->actingAs($this->admin)
            ->putJson('/api/v1/admin/tenant/portal-settings', [
                'public_slug' => 'codiciada',
                'public_portal_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('public_slug');

        // Y sigue siendo suya: nadie se quedó con su dirección ni con sus postulaciones.
        $this->assertSame(['De la Codiciada'],
            collect($this->bolsa('codiciada')->json('vacancies'))->pluck('title')->all());
    }

    public function test_la_bolsa_de_cada_empresa_resuelve_a_la_suya(): void
    {
        $this->vacante(['title' => 'De Bolsa QA']);

        $otra = Tenant::create([
            'name' => 'La Otra 3', 'subdomain' => 'laotra3', 'public_slug' => 'laotra3',
            'plan' => 'pro', 'is_active' => true,
        ]);
        $puestoOtra = JobRole::create([
            'tenant_id' => $otra->id, 'name' => 'Mesero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);
        Vacancy::create([
            'tenant_id' => $otra->id, 'job_role_id' => $puestoOtra->id,
            'title' => 'De La Otra', 'description' => 'x', 'requirements' => '[]',
            'is_active' => true, 'is_hidden' => false,
        ]);

        $titulos = collect($this->bolsa('laotra3')->assertStatus(200)->json('vacancies'))->pluck('title');

        $this->assertSame(['De La Otra'], $titulos->all());
    }
}
