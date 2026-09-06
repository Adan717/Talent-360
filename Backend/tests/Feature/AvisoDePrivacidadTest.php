<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\PrivacyConsent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\AvisoDePrivacidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El bloque de privacidad (2026-09-05).
 *
 * LO QUE SE ESTABA ROMPIENDO: la casilla "Acepto el Aviso de Privacidad" del alta de empresa era
 * teatro —su valor no salía del navegador y no había ni tabla ni endpoint que lo guardara—, el
 * portal público de empleo recibía datos de candidatos sin casilla ninguna, y quien entra por el
 * kiosco con su PIN podía trabajar meses sin que ninguna pantalla le mostrara el aviso.
 *
 * Sin el arreglo fallan, entre otras: `test_una_cuenta_marcada_no_puede_usar_otra_ruta` (no habría
 * candado), `test_el_alta_de_empresa_exige_la_casilla` (el alta pasaba sin ella) y
 * `test_la_postulacion_publica_exige_la_casilla`.
 */
class AvisoDePrivacidadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $marcado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Privacidad QA', 'subdomain' => 'privacidadqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->marcado = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colaborador Nuevo',
            'email' => 'nuevo@privacidadqa.test', 'password' => bcrypt('UnaBuena#2026'),
            'role' => 'empleado', 'privacidad_pendiente' => true,
        ]);
    }

    // --- El candado (punto 2: primer ingreso) --------------------------------------------------

    public function test_una_cuenta_marcada_no_puede_usar_otra_ruta(): void
    {
        $this->actingAs($this->marcado)->getJson('/api/v1/me/rest-day-requests')
            ->assertStatus(403)
            ->assertJsonPath('code', 'privacidad_pendiente')
            ->assertJsonPath('version', AvisoDePrivacidad::VERSION);
    }

    /** El frontend necesita poder leerse a sí mismo para saber que hay que pintar la pantalla. */
    public function test_una_cuenta_marcada_si_puede_leer_su_propio_perfil_y_ahi_lo_ve(): void
    {
        $this->actingAs($this->marcado)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.privacidad_pendiente', true)
            ->assertJsonPath('user.privacidad_version', AvisoDePrivacidad::VERSION);
    }

    public function test_aceptar_levanta_el_candado_y_deja_constancia_de_quien_cuando_y_que_version(): void
    {
        $this->actingAs($this->marcado)
            ->withHeader('User-Agent', 'NavegadorDePrueba/1.0')
            ->postJson('/api/v1/me/consentimiento')
            ->assertOk()
            ->assertJsonPath('version', AvisoDePrivacidad::VERSION);

        $constancia = PrivacyConsent::where('user_id', $this->marcado->id)->first();
        $this->assertNotNull($constancia, 'la aceptación tiene que quedar registrada, no sólo desmarcar la cuenta');
        $this->assertSame(AvisoDePrivacidad::VERSION, $constancia->version);
        $this->assertSame(AvisoDePrivacidad::PUNTO_PRIMER_INGRESO, $constancia->punto);
        $this->assertSame($this->tenant->id, $constancia->tenant_id);
        $this->assertSame('nuevo@privacidadqa.test', $constancia->email);
        $this->assertSame('NavegadorDePrueba/1.0', $constancia->user_agent);
        $this->assertNotNull($constancia->ip);
        $this->assertNotNull($constancia->created_at);

        $this->assertFalse($this->marcado->fresh()->privacidad_pendiente);
        $this->assertSame(AvisoDePrivacidad::VERSION, $this->marcado->fresh()->privacidad_aceptada_version);

        $this->actingAs($this->marcado->fresh())->getJson('/api/v1/me/rest-day-requests')->assertOk();
    }

    /** Aceptar dos veces no fabrica dos constancias ni mueve la fecha de la primera. */
    public function test_aceptar_dos_veces_no_duplica_la_constancia(): void
    {
        $this->actingAs($this->marcado)->postJson('/api/v1/me/consentimiento')->assertOk();
        $primera = PrivacyConsent::where('user_id', $this->marcado->id)->first();

        $this->actingAs($this->marcado->fresh())->postJson('/api/v1/me/consentimiento')->assertOk();

        $this->assertSame(1, PrivacyConsent::where('user_id', $this->marcado->id)->count());
        $this->assertEquals(
            $primera->created_at->toDateTimeString(),
            PrivacyConsent::where('user_id', $this->marcado->id)->first()->created_at->toDateTimeString()
        );
    }

    public function test_una_cuenta_sin_marca_no_se_ve_afectada(): void
    {
        $alDia = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ya Aceptó',
            'email' => 'aldia@privacidadqa.test', 'password' => bcrypt('UnaBuena#2026'),
            'role' => 'empleado',
        ]);

        $this->actingAs($alDia)->getJson('/api/v1/me/rest-day-requests')->assertOk();
    }

    /** El personal de la plataforma no es titular de los datos laborales de un cliente. */
    public function test_el_personal_de_plataforma_no_queda_atrapado(): void
    {
        $platform = \App\Models\PlatformUser::create([
            'name' => 'Root', 'email' => 'root@plataforma.test',
            'password' => bcrypt('SoloMia#2026'), 'role' => 'platform_admin', 'is_active' => true,
        ]);

        $this->actingAs($platform)->getJson('/api/v1/me')->assertOk();
        $this->assertFalse(AvisoDePrivacidad::usuarioDebeAceptar($platform));
    }

    /**
     * Candado de convivencia con el otro gate: con las DOS marcas la persona tiene que poder
     * cambiar su contraseña. Sin `me/change-password` en las permitidas de este middleware, las dos
     * se bloquean mutuamente y la cuenta queda muerta.
     */
    public function test_con_las_dos_marcas_todavia_se_puede_cambiar_la_contrasena(): void
    {
        $this->marcado->update(['must_change_password' => true]);

        $this->actingAs($this->marcado)->postJson('/api/v1/me/change-password', [
            'current_password' => 'UnaBuena#2026',
            'new_password' => 'OtraBuena#2026',
            'new_password_confirmation' => 'OtraBuena#2026',
        ])->assertOk();

        $this->assertFalse($this->marcado->fresh()->must_change_password);
        $this->assertTrue($this->marcado->fresh()->privacidad_pendiente, 'el aviso sigue pendiente');
    }

    // --- El kiosco: quien nunca pasa por el login ----------------------------------------------

    public function test_el_kiosco_ficha_igual_y_ofrece_aceptar_en_el_acto(): void
    {
        [$ancla, $empleado, $cuenta] = $this->armarKiosco();

        $respuesta = $this->actingAs($ancla)->postJson('/api/v1/kiosk/punch', [
            'pin' => '481593', 'type' => 'check_in',
        ]);

        // El fichaje NO se bloquea por el consentimiento: la jornada trabajada no se pierde.
        $respuesta->assertOk()->assertJsonPath('success', true);
        $respuesta->assertJsonPath('privacidad.pendiente', true);
        $pase = $respuesta->json('privacidad.pase');
        $this->assertNotEmpty($pase);

        $this->actingAs($ancla)->postJson('/api/v1/kiosk/consentimiento', ['pase' => $pase])
            ->assertOk()
            ->assertJsonPath('version', AvisoDePrivacidad::VERSION);

        // La constancia es del EMPLEADO que tecleó su PIN, no del encargado que hospeda la tableta.
        $constancia = PrivacyConsent::where('user_id', $cuenta->id)->first();
        $this->assertNotNull($constancia);
        $this->assertSame($empleado->tenant_id, $constancia->tenant_id);
        $this->assertFalse($cuenta->fresh()->privacidad_pendiente);

        // Y el siguiente ponche ya no lo vuelve a pedir.
        $this->actingAs($ancla)->postJson('/api/v1/kiosk/punch', ['pin' => '481593', 'type' => 'check_out'])
            ->assertJsonMissingPath('privacidad');
    }

    /** El pase es de un solo uso: si no, quedaría un permiso reutilizable para firmar por otro. */
    public function test_el_pase_del_kiosco_no_se_puede_reusar_ni_inventar(): void
    {
        [$ancla] = $this->armarKiosco();

        $pase = $this->actingAs($ancla)->postJson('/api/v1/kiosk/punch', [
            'pin' => '481593', 'type' => 'check_in',
        ])->json('privacidad.pase');

        $this->actingAs($ancla)->postJson('/api/v1/kiosk/consentimiento', ['pase' => $pase])->assertOk();
        $this->actingAs($ancla)->postJson('/api/v1/kiosk/consentimiento', ['pase' => $pase])->assertStatus(422);
        $this->actingAs($ancla)->postJson('/api/v1/kiosk/consentimiento', ['pase' => 'inventado-por-el-cliente'])
            ->assertStatus(422);
    }

    /** Un pase emitido en una empresa no puede canjearse desde la tableta de otra. */
    public function test_el_pase_no_cruza_de_empresa(): void
    {
        [$ancla, , $cuenta] = $this->armarKiosco();

        $otra = Tenant::create([
            'name' => 'Vecina', 'subdomain' => 'vecinaqa', 'plan' => 'freemium', 'is_active' => true,
        ]);
        $anclaVecina = User::create([
            'tenant_id' => $otra->id, 'name' => 'Encargado Vecino',
            'email' => 'vecino@vecinaqa.test', 'password' => bcrypt('UnaBuena#2026'), 'role' => 'admin',
        ]);

        $pase = $this->actingAs($ancla)->postJson('/api/v1/kiosk/punch', [
            'pin' => '481593', 'type' => 'check_in',
        ])->json('privacidad.pase');

        $this->actingAs($anclaVecina)->postJson('/api/v1/kiosk/consentimiento', ['pase' => $pase])
            ->assertStatus(422);

        $this->assertTrue($cuenta->fresh()->privacidad_pendiente, 'nadie de otra empresa firmó por él');
    }

    // --- Punto 1: alta de empresa ---------------------------------------------------------------

    public function test_el_alta_de_empresa_exige_la_casilla(): void
    {
        $preRegistro = User::create([
            'name' => 'Dueña Nueva', 'email' => 'duena@nueva.test',
            'password' => bcrypt('UnaBuena#2026'), 'role' => 'admin', 'tenant_id' => null,
        ]);

        $this->actingAs($preRegistro, 'sanctum')
            ->postJson('/api/v1/subscriptions/create-preference', [
                'company_name' => 'Empresa Nueva', 'subdomain' => 'empresanueva',
                'plan' => 'freemium', 'billing_cycle' => 'monthly',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('acepta_aviso');

        $this->assertDatabaseMissing('tenants', ['subdomain' => 'empresanueva']);
        $this->assertSame(0, PrivacyConsent::count());
    }

    public function test_el_alta_de_empresa_con_la_casilla_deja_constancia_y_la_ancla_a_la_empresa(): void
    {
        $preRegistro = User::create([
            'name' => 'Dueña Nueva', 'email' => 'duena@nueva.test',
            'password' => bcrypt('UnaBuena#2026'), 'role' => 'admin', 'tenant_id' => null,
        ]);

        $this->actingAs($preRegistro, 'sanctum')
            ->postJson('/api/v1/subscriptions/create-preference', [
                'company_name' => 'Empresa Nueva', 'subdomain' => 'empresanueva',
                'plan' => 'freemium', 'billing_cycle' => 'monthly',
                'acepta_aviso' => true,
            ])
            ->assertOk()
            ->assertJsonPath('provisioned', true);

        $nueva = Tenant::where('subdomain', 'empresanueva')->first();
        $constancia = PrivacyConsent::where('user_id', $preRegistro->id)->first();

        $this->assertNotNull($constancia, 'la casilla del alta ya no puede quedarse en el navegador');
        $this->assertSame(AvisoDePrivacidad::PUNTO_ALTA_EMPRESA, $constancia->punto);
        $this->assertSame(AvisoDePrivacidad::VERSION, $constancia->version);
        $this->assertSame($nueva->id, $constancia->tenant_id, 'la constancia se ancla a la empresa recién creada');

        // Y a quien acaba de aceptar no se le vuelve a pedir al entrar.
        $this->assertFalse((bool) $preRegistro->fresh()->privacidad_pendiente);
    }

    // --- Punto 3: postulación pública -----------------------------------------------------------

    public function test_la_postulacion_publica_exige_la_casilla(): void
    {
        $vacante = $this->armarVacante();

        $this->postJson('/api/v1/public/candidates', [
            'name' => 'Ana Candidata', 'email' => 'ana@candidata.test',
            'applied_vacancy_id' => $vacante->id,
        ])->assertStatus(422)->assertJsonValidationErrors('acepta_aviso');

        $this->assertDatabaseCount('candidates', 0);
        $this->assertSame(0, PrivacyConsent::count());
    }

    public function test_la_postulacion_publica_con_la_casilla_entra_y_deja_constancia(): void
    {
        $vacante = $this->armarVacante();

        $this->postJson('/api/v1/public/candidates', [
            'name' => 'Ana Candidata', 'email' => 'ana@candidata.test',
            'applied_vacancy_id' => $vacante->id, 'acepta_aviso' => true,
        ])->assertStatus(201);

        $constancia = PrivacyConsent::whereNotNull('candidate_id')->first();
        $this->assertNotNull($constancia);
        $this->assertSame(AvisoDePrivacidad::PUNTO_POSTULACION, $constancia->punto);
        $this->assertSame($this->tenant->id, $constancia->tenant_id, 'la constancia es de la empresa dueña de la vacante');
        $this->assertSame('ana@candidata.test', $constancia->email);
    }

    /**
     * La captura CON SESIÓN (la rama `auth()->check()` del mismo endpoint: la usa quien ya está
     * dentro de la aplicación, porque el cliente HTTP manda su token en toda petición) no exige la
     * casilla: ahí quien teclea es personal de la empresa, no el titular, y registrar una
     * aceptación en su nombre sería fabricar una constancia falsa.
     */
    public function test_la_captura_con_sesion_no_exige_la_casilla_ni_inventa_constancia(): void
    {
        $vacante = $this->armarVacante();
        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Reclutadora',
            'email' => 'rrhh@privacidadqa.test', 'password' => bcrypt('UnaBuena#2026'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)->postJson('/api/v1/public/candidates', [
            'name' => 'Referido', 'email' => 'referido@x.test', 'applied_vacancy_id' => $vacante->id,
        ])->assertStatus(201);

        $this->assertSame(0, PrivacyConsent::count());
    }

    // --- La versión, en un solo sitio ------------------------------------------------------------

    public function test_la_version_vigente_se_publica_sin_sesion(): void
    {
        $this->getJson('/api/v1/privacidad/version')
            ->assertOk()
            ->assertJsonPath('version', AvisoDePrivacidad::VERSION)
            ->assertJsonPath('fecha', AvisoDePrivacidad::FECHA_LEGIBLE);
    }

    /**
     * CANDADO DE DERIVA: el texto legal (del abogado) vive en el frontend y declara su propia fecha
     * de última actualización; la constante que se GUARDA vive en PHP. Si las dos se separan, el
     * registro diría que alguien aceptó una versión que nadie mostró nunca — el defecto exacto que
     * este proyecto ya pagó tres veces con dos copias de lo mismo.
     */
    public function test_la_version_declarada_coincide_con_la_fecha_del_texto_legal(): void
    {
        $legal = base_path('../Frontend/src/components/LegalModal.tsx');

        if (!is_file($legal)) {
            $this->markTestSkipped('LegalModal.tsx no está en este despliegue (backend suelto).');
        }

        $this->assertStringContainsString(
            'Última actualización: ' . AvisoDePrivacidad::FECHA_LEGIBLE,
            file_get_contents($legal),
            'la fecha impresa en el aviso y AvisoDePrivacidad::FECHA_LEGIBLE se separaron: '
            . 'sube VERSION/FECHA_LEGIBLE al cambiar el texto (y corre privacidad:pedir-consentimiento)'
        );
    }

    // --- El comando de re-consentimiento ---------------------------------------------------------

    public function test_el_comando_es_simulacro_por_defecto_y_marca_con_aplicar(): void
    {
        $viejo = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Aceptó Una Vieja',
            'email' => 'viejo@privacidadqa.test', 'password' => bcrypt('UnaBuena#2026'),
            'role' => 'empleado', 'privacidad_aceptada_version' => '2020-01-01',
        ]);
        $alDia = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Al Día',
            'email' => 'aldia2@privacidadqa.test', 'password' => bcrypt('UnaBuena#2026'),
            'role' => 'empleado', 'privacidad_aceptada_version' => AvisoDePrivacidad::VERSION,
        ]);

        $this->artisan('privacidad:pedir-consentimiento')->assertSuccessful();
        $this->assertFalse((bool) $viejo->fresh()->privacidad_pendiente, 'sin --aplicar no se toca nada');

        $this->artisan('privacidad:pedir-consentimiento --aplicar')->assertSuccessful();
        $this->assertTrue((bool) $viejo->fresh()->privacidad_pendiente, 'quien aceptó una versión vieja vuelve a la fila');
        $this->assertFalse((bool) $alDia->fresh()->privacidad_pendiente, 'quien está al día no se molesta');
    }

    // --- Ayudantes -------------------------------------------------------------------------------

    /** Devuelve [ancla de la tableta, expediente del empleado, cuenta del empleado]. */
    private function armarKiosco(): array
    {
        $ancla = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Encargado',
            'email' => 'encargado@privacidadqa.test', 'password' => bcrypt('UnaBuena#2026'),
            'role' => 'admin',
        ]);

        // canClockIn() del expediente exige PUESTO y CUENTA vinculada; nada más.
        $puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Vendedor', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $expediente = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->marcado->id,
            'name' => $this->marcado->name, 'email' => $this->marcado->email,
            'job_role_id' => $puesto->id, 'is_active_employee' => true,
        ]);
        $expediente->setKioskPin('481593');
        $expediente->save();

        return [$ancla, $expediente, $this->marcado];
    }

    private function armarVacante(): Vacancy
    {
        $puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        return Vacancy::create([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $puesto->id,
            'title' => 'Vendedor de piso', 'description' => 'x', 'requirements' => '[]',
            'is_active' => true, 'is_hidden' => false,
        ]);
    }
}
