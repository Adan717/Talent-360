<?php

namespace Tests\Feature;

use App\Support\Tarifario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UNA SOLA FUENTE DE VERDAD PARA LOS PRECIOS (2026-09-05).
 *
 * El producto tenía CUATRO tabuladores distintos y ninguno sabía de los otros. El peor síntoma:
 * el MISMO plan tenía DOS precios anuales según qué interruptor mirara el cliente en la landing
 * —$278.40 por colaborador al año con el interruptor en mensual, $288 con el interruptor en
 * anual— y la caja cobraba el segundo. Estas pruebas son el candado de eso.
 */
class TarifarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Tarifario::olvidar();
    }

    protected function tearDown(): void
    {
        Tarifario::olvidar();
        parent::tearDown();
    }

    // ---------- El tabulador sembrado es lo que el backend cobraba ----------

    public function test_el_tarifario_devuelve_las_tarifas_que_el_backend_cobraba(): void
    {
        $pro = Tarifario::plan('pro');
        $this->assertEquals(29.0, $pro['tarifa_mensual_por_colaborador']);
        $this->assertEquals(24.0, $pro['tarifa_anual_por_colaborador']);

        $enterprise = Tarifario::plan('enterprise');
        $this->assertEquals(69.0, $enterprise['tarifa_mensual_por_colaborador']);
        $this->assertEquals(55.0, $enterprise['tarifa_anual_por_colaborador']);

        $freemium = Tarifario::plan('freemium');
        $this->assertEquals(0.0, $freemium['tarifa_mensual_por_colaborador']);

        // Nacen marcados PROVISIONALES: son la foto de lo que el código cobraba, no el
        // tabulador oficial. El oficial lo declara el dueño.
        $this->assertTrue(Tarifario::esProvisional());
    }

    public function test_las_cotizaciones_son_por_colaborador_y_el_anual_son_doce_mensualidades(): void
    {
        // Lo que cobraba `SubscriptionController` antes de esta ronda, letra por letra.
        $this->assertEquals(290.0, Tarifario::totalACobrar('pro', 10, 'monthly'));   // 10 × 29
        $this->assertEquals(2880.0, Tarifario::totalACobrar('pro', 10, 'yearly'));   // 10 × 24 × 12
        $this->assertEquals(690.0, Tarifario::totalACobrar('enterprise', 10, 'monthly'));
        $this->assertEquals(6600.0, Tarifario::totalACobrar('enterprise', 10, 'yearly'));

        // En la CAJA, sin número de colaboradores se asumían 10 y se sigue asumiendo lo mismo:
        // nadie contrata para cero personas.
        $this->assertEquals(290.0, Tarifario::totalACobrar('pro', null, 'monthly'));
        $this->assertEquals(290.0, Tarifario::totalACobrar('pro', 0, 'monthly'));

        // Pero en una COTIZACIÓN, un cero explícito es un cero: una empresa sin nadie dentro no
        // factura. Confundir "no me dijeron" con "no hay nadie" hacía que el MRR del panel
        // cobrara 10 colaboradores por cada empresa vacía.
        $this->assertEquals(0.0, Tarifario::cotizar('pro', 0)['total_mensual']);
        $this->assertEquals(290.0, Tarifario::cotizar('pro', null)['total_mensual']);

        // El freemium no cobra, y un código que no es plan tampoco (el alta lo aprovisiona
        // gratis: cambiar esto en silencio cambiaría el registro).
        $this->assertEquals(0.0, Tarifario::totalACobrar('freemium', 10, 'monthly'));
        $this->assertEquals(0.0, Tarifario::totalACobrar('basic', 10, 'monthly'));
    }

    public function test_el_descuento_anual_se_deriva_de_las_dos_tarifas_y_no_es_el_veinte_por_ciento_escrito_a_mano(): void
    {
        // La landing anunciaba "Ahorra 20%" en los dos planes. Con las tarifas reales el
        // ahorro de PRO es 17.2%, no 20%: la insignia mentía.
        $this->assertEquals(17.2, Tarifario::plan('pro')['descuento_anual_pct']);
        $this->assertEquals(20.3, Tarifario::plan('enterprise')['descuento_anual_pct']);
        $this->assertEquals(20.3, Tarifario::descuentoAnualMaximo());
    }

    public function test_el_descuento_sigue_a_la_tarifa_cuando_el_dueno_cambia_el_tabulador(): void
    {
        // El tabulador oficial se cambia en BASE DE DATOS, sin recompilar. Y el porcentaje que
        // anuncia la pantalla se mueve solo, porque nunca estuvo escrito.
        DB::table('billing_plans')->where('code', 'pro')->update([
            'price_per_user_monthly' => 100,
            'price_per_user_yearly' => 75,
            'is_provisional' => false,
        ]);
        Tarifario::olvidar();

        $this->assertEquals(25.0, Tarifario::plan('pro')['descuento_anual_pct']);
        $this->assertEquals(900.0, Tarifario::totalACobrar('pro', 9, 'monthly'));
        $this->assertEquals(8100.0, Tarifario::totalACobrar('pro', 9, 'yearly'));
    }

    public function test_un_plan_de_pago_sin_fila_lanza_en_vez_de_cotizar_en_cero(): void
    {
        // El camino silencioso REGALA el producto: `createPreference` aprovisiona gratis
        // cuando el total sale en cero. Un tabulador incompleto tiene que doler.
        DB::table('billing_plans')->where('code', 'pro')->delete();
        Tarifario::olvidar();

        $this->expectException(\RuntimeException::class);
        Tarifario::totalACobrar('pro', 10, 'monthly');
    }

    // ---------- El endpoint público ----------

    public function test_el_endpoint_del_tarifario_responde_sin_sesion(): void
    {
        // La landing lo pide antes de que exista cuenta alguna.
        $res = $this->getJson('/api/v1/public/tarifario');

        $res->assertOk();
        $codigos = array_column($res->json('planes'), 'codigo');
        $this->assertEquals(['freemium', 'pro', 'enterprise'], $codigos);
        $res->assertJsonPath('es_provisional', true);
        $res->assertJsonPath('descuento_anual_maximo_pct', 20.3);
        $res->assertJsonPath('colaboradores_por_defecto', Tarifario::COLABORADORES_POR_DEFECTO);

        // Los precios no se derivan unos de otros en la pantalla: el servidor los da hechos.
        $pro = collect($res->json('planes'))->firstWhere('codigo', 'pro');
        $this->assertEquals(29.0, $pro['tarifa_mensual_por_colaborador']);
        $this->assertEquals(24.0, $pro['tarifa_anual_por_colaborador']);
        $this->assertEquals(290.0, $pro['total_mensual']);
        $this->assertEquals(2880.0, $pro['total_anual']);
    }

    public function test_el_endpoint_cotiza_para_los_colaboradores_que_se_le_piden(): void
    {
        $res = $this->getJson('/api/v1/public/tarifario?colaboradores=17');

        $res->assertOk();
        $pro = collect($res->json('planes'))->firstWhere('codigo', 'pro');
        $this->assertEquals(17 * 29, $pro['total_mensual']);
        $this->assertEquals(17 * 24 * 12, $pro['total_anual']);
        // El "Costo Equivalente" mensual del plan anual: la landing lo pintaba como
        // mensual × 0.8 = $23.20 por colaborador, cuando la tarifa anual real es $24.
        $this->assertEquals(17 * 24, $pro['equivalente_mensual_anual']);
    }

    // ---------- EL DEFECTO QUE SE CORRIGE ----------

    public function test_el_anual_que_pinta_la_pantalla_y_el_anual_que_cobra_la_caja_son_el_mismo_numero(): void
    {
        // Antes NO lo eran: la tarjeta de la landing anunciaba `mensual × 12 × 0.8` con el
        // interruptor en mensual (17 × 29 × 12 × 0.8 = $4,732.80) y la caja cobraba
        // `tarifa_anual × 12` (17 × 24 × 12 = $4,896). $163.20 de diferencia por el mismo plan.
        $anualDeLaPantalla = collect($this->getJson('/api/v1/public/tarifario?colaboradores=17')->json('planes'))
            ->firstWhere('codigo', 'pro')['total_anual'];

        $preferencia = $this->postJson('/api/v1/subscriptions/create-preference', [
            'subdomain' => 'empresaanual',
            'plan' => 'pro',
            'company_name' => 'Empresa Anual',
            'admin_name' => 'Admin Anual',
            'admin_email' => 'admin@empresaanual.test',
            'admin_password' => 'secreto123',
            'employees' => 17,
            'billing_cycle' => 'yearly',
            // Desde 2026-09-05 el alta de empresa exige la aceptación del aviso EN EL SERVIDOR
            // (antes la casilla era teatro: su valor nunca salía del navegador). Sin esto el
            // endpoint responde 422 y esta prueba fallaría por una razón que no es la que mide.
            'acepta_aviso' => true,
        ]);

        $preferencia->assertOk();
        $checkout = $this->get($preferencia->json('init_point'));
        $checkout->assertOk();

        // Lo que la caja le presenta al cliente para cobrarle.
        $checkout->assertSee('$' . $anualDeLaPantalla . ' MXN/año', false);
        $this->assertEquals(4896.0, $anualDeLaPantalla);
    }

    public function test_el_mensual_que_pinta_la_pantalla_y_el_que_cobra_la_caja_tambien_coinciden(): void
    {
        $mensualDeLaPantalla = collect($this->getJson('/api/v1/public/tarifario?colaboradores=7')->json('planes'))
            ->firstWhere('codigo', 'enterprise')['total_mensual'];

        $preferencia = $this->postJson('/api/v1/subscriptions/create-preference', [
            'subdomain' => 'empresamensual',
            'plan' => 'enterprise',
            'company_name' => 'Empresa Mensual',
            'admin_name' => 'Admin Mensual',
            'admin_email' => 'admin@empresamensual.test',
            'admin_password' => 'secreto123',
            'employees' => 7,
            'billing_cycle' => 'monthly',
            // Ver la nota de la prueba anterior: el alta exige la aceptación del aviso.
            'acepta_aviso' => true,
        ]);

        $preferencia->assertOk();
        $checkout = $this->get($preferencia->json('init_point'));
        $checkout->assertSee('$' . $mensualDeLaPantalla . ' MXN/mes', false);
        $this->assertEquals(483.0, $mensualDeLaPantalla);
    }

    // ---------- El panel de plataforma ----------

    public function test_el_mrr_del_panel_usa_la_tarifa_real_por_colaborador(): void
    {
        // El MRR sumaba $199 por empresa PRO y $499 por Enterprise, sin importar cuánta gente
        // tuviera cada una. El backend cobra por colaborador, así que ese número no tenía
        // relación con lo que se factura: dos empresas PRO, una de 3 personas y otra de 40,
        // aportaban lo mismo. Las cifras del panel CAMBIAN con esta ronda porque antes mentían.
        $pro = DB::table('tenants')->insertGetId([
            'name' => 'Empresa Pro', 'subdomain' => 'empresapro', 'plan' => 'pro',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ent = DB::table('tenants')->insertGetId([
            'name' => 'Empresa Ent', 'subdomain' => 'empresaent', 'plan' => 'enterprise',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $platformAdmin = \App\Models\User::factory()->create(['role' => 'platform_admin']);
        $mrrAntes = $this->actingAs($platformAdmin)->getJson('/api/v1/platform/stats')->json('mrr');

        // Una empresa sin nadie dentro no aporta nada: un tenant vacío no factura 10 licencias.
        $this->assertEquals(0.0, $mrrAntes);

        // Contratar gente sube la factura. Con el MRR plano de antes, estas 5 altas no movían
        // el número ni un peso: dos empresas PRO, una de 3 personas y otra de 40, aportaban lo
        // mismo. Es la prueba de que el MRR ya sigue a la tarifa por colaborador.
        \App\Models\User::factory()->count(3)->create(['tenant_id' => $pro, 'role' => 'empleado']);
        \App\Models\User::factory()->count(2)->create(['tenant_id' => $ent, 'role' => 'empleado']);

        $res = $this->actingAs($platformAdmin)->getJson('/api/v1/platform/stats');
        $res->assertOk();

        $this->assertEquals(3 * 29 + 2 * 69, $res->json('mrr') - $mrrAntes);
        $res->assertJsonPath('mrr_provisional', true);
    }

    // ---------- El cupo: avisa, no bloquea ----------

    public function test_el_cupo_del_plan_sale_del_mismo_tabulador(): void
    {
        // Antes había TRES números para el freemium: el backend caía a 5, la landing anunciaba
        // 10 y la pantalla del cliente caía también a 10.
        $this->assertEquals(10, Tarifario::topeDeColaboradores('freemium'));
        $this->assertNull(Tarifario::topeDeColaboradores('pro'));
        $this->assertNull(Tarifario::topeDeColaboradores('enterprise'));
        $this->assertEquals(10, \App\Models\Tenant::maxUsersForPlan('freemium'));
    }

    public function test_rebasar_el_cupo_del_freemium_no_bloquea_a_nadie(): void
    {
        // Criterio del dueño: "nada bloquea, todo avisa". Hay 4 empresas vivas; convertir este
        // número en candado dejaría gente sin poder checar su entrada.
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Empresa Llena', 'subdomain' => 'llena', 'plan' => 'freemium',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $tope = Tarifario::topeDeColaboradores('freemium');
        for ($i = 0; $i < $tope + 3; $i++) {
            \App\Models\User::factory()->create(['tenant_id' => $tenantId, 'role' => 'empleado']);
        }

        $usuarios = \App\Models\User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenantId)->count();

        // Se rebasó el tope y las altas se hicieron igual: nada lo impidió, ni debe impedirlo.
        $this->assertGreaterThan($tope, $usuarios);
    }
}
