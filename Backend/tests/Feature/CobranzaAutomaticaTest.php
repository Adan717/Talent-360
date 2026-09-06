<?php

namespace Tests\Feature;

use App\Models\SaasAuditLog;
use App\Models\Tenant;
use App\Support\EstadoDeCobranza;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cobranza automática: `suscripciones:revisar-vencidas`.
 *
 * Antes del 2026-09-05 dejar de pagar no tenía consecuencia: la suspensión era un interruptor
 * manual y 'past_due' no lo escribía nadie. Estas pruebas fijan las dos mitades del trato:
 * que la mora SÍ tenga consecuencia, y que la consecuencia no le caiga a quien no debe.
 */
class CobranzaAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $ahora;

    protected function setUp(): void
    {
        parent::setUp();
        // Sin fechas literales: todo se siembra relativo a este instante.
        $this->ahora = Carbon::now();
        Carbon::setTestNow($this->ahora);
    }

    /**
     * Crea una empresa. `is_active`, `current_period_end` y las columnas de cobranza NO están
     * en $fillable del modelo (a propósito: no se asignan en masa desde una petición), así que
     * se ponen a mano igual que hace el código de producción.
     */
    private function empresa(array $atributos = []): Tenant
    {
        $sufijo = str_replace('.', '', uniqid('', true));
        $empresa = Tenant::create([
            'name' => $atributos['name'] ?? ('Empresa ' . $sufijo),
            'subdomain' => $atributos['subdomain'] ?? ('e' . $sufijo),
            'plan' => $atributos['plan'] ?? 'enterprise',
            'subscription_status' => $atributos['subscription_status'] ?? EstadoDeCobranza::ACTIVA,
        ]);

        foreach (['is_active', 'current_period_end', 'trial_ends_at', 'billing_exempt',
                  'billing_exempt_reason', 'payment_warning_sent_at'] as $columna) {
            if (array_key_exists($columna, $atributos)) {
                $empresa->{$columna} = $atributos[$columna];
            }
        }
        $empresa->save();

        return $empresa->fresh();
    }

    /**
     * El tenant 1 ("DecorArte 360") NO lo crean estas pruebas: lo siembra la migración
     * 2026_06_28_030000_create_store_opening_tables, igual que en la base viva. Es el inquilino
     * principal de la plataforma y el barrido no lo toca nunca.
     */
    private function inquilinoPrincipal(): Tenant
    {
        return Tenant::findOrFail(1);
    }

    private function eventos(string $tipo): int
    {
        return SaasAuditLog::where('event_type', $tipo)->count();
    }

    // ---------------------------------------------------------------------------------------
    // EL CANDADO
    // ---------------------------------------------------------------------------------------

    /**
     * CANDADO. Sin fecha de corte NO se suspende NUNCA, aunque el barrido corra con todo el
     * poder (--aplicar sin --sin-suspender).
     *
     * Éste es el caso de las 4 empresas vivas de la V2 al 2026-09-05: ninguna tiene
     * `current_period_end`, y tres de ellas además arrastran un `trial_ends_at` ya vencido con
     * el estado en 'active'. Si el barrido dedujera mora de la falta de dato —o del periodo de
     * prueba vencido— apagaría hoy mismo el reloj checador de tres clientes reales.
     */
    public function test_sin_fecha_de_corte_nunca_se_suspende(): void
    {
        $vivas = [
            // #1 DecorArte 360 (plan pro, en 'trial', sin trial_ends_at) ya viene de la migración.
            $this->inquilinoPrincipal(),
            $this->empresa(['name' => 'DecorArte S.A. de C.V.',
                'trial_ends_at' => $this->ahora->copy()->subDays(24)]),
            $this->empresa(['name' => 'Panaderia La Espiga QA',
                'trial_ends_at' => $this->ahora->copy()->subDays(23)]),
            $this->empresa(['name' => 'DecorArte S.A.C.V',
                'trial_ends_at' => $this->ahora->copy()->subDays(2)]),
        ];

        $this->artisan('suscripciones:revisar-vencidas --aplicar')
            ->expectsOutputToContain('SIN FECHA DE CORTE')
            ->assertExitCode(0);

        foreach ($vivas as $empresa) {
            $despues = $empresa->fresh();
            $this->assertTrue((bool) $despues->is_active, "{$despues->name} fue suspendida sin fecha de corte");
            $this->assertNull($despues->suspended_at);
            $this->assertSame($empresa->subscription_status, $despues->subscription_status);
        }

        // Ni siquiera se marca mora: sin dato no hay nada que afirmar.
        $this->assertSame(0, SaasAuditLog::count());
    }

    // ---------------------------------------------------------------------------------------
    // GRACIA Y SUSPENSIÓN
    // ---------------------------------------------------------------------------------------

    public function test_dentro_de_la_gracia_se_marca_la_mora_pero_no_se_suspende(): void
    {
        $empresa = $this->empresa(['current_period_end' => $this->ahora->copy()->subDay()]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);

        $despues = $empresa->fresh();
        $this->assertTrue((bool) $despues->is_active, 'Se suspendió dentro del periodo de gracia');
        $this->assertSame(EstadoDeCobranza::MORA, $despues->subscription_status);
        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_MORA));
        $this->assertSame(0, $this->eventos(EstadoDeCobranza::EVENTO_SUSPENSION));
    }

    /** El último día de gracia todavía es gracia: se suspende al pasarla, no al llegar a ella. */
    public function test_el_ultimo_dia_de_gracia_todavia_no_suspende(): void
    {
        $empresa = $this->empresa([
            'current_period_end' => $this->ahora->copy()->subDays(EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO),
        ]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);

        $this->assertTrue((bool) $empresa->fresh()->is_active);
    }

    public function test_pasada_la_gracia_si_se_suspende(): void
    {
        $empresa = $this->empresa([
            'current_period_end' => $this->ahora->copy()->subDays(EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO + 1),
        ]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);

        $despues = $empresa->fresh();
        $this->assertFalse((bool) $despues->is_active);
        $this->assertSame(EstadoDeCobranza::MOTIVO_DE_SUSPENSION, $despues->suspension_reason);
        $this->assertNotNull($despues->suspended_at);
        // La deuda no es una baja: el estado queda en mora, no en 'cancelled'.
        $this->assertSame(EstadoDeCobranza::MORA, $despues->subscription_status);
        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_SUSPENSION));
    }

    public function test_la_empresa_exenta_no_se_toca_nunca(): void
    {
        $empresa = $this->empresa([
            'current_period_end' => $this->ahora->copy()->subDays(60),
            'billing_exempt' => true,
            'billing_exempt_reason' => 'Piloto sin cobro',
        ]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')
            ->expectsOutputToContain('EXENTA')
            ->assertExitCode(0);

        $despues = $empresa->fresh();
        $this->assertTrue((bool) $despues->is_active);
        $this->assertSame(EstadoDeCobranza::ACTIVA, $despues->subscription_status);
        $this->assertSame(0, SaasAuditLog::count());
    }

    /**
     * Suspender al inquilino principal apagaría el panel desde el que se administra todo lo
     * demás. Se protege por las dos vías del guardarraíl: el id 1 y el subdominio 'talent360'.
     */
    public function test_no_toca_al_inquilino_principal_aunque_deba(): void
    {
        $principal = $this->inquilinoPrincipal();
        $principal->current_period_end = $this->ahora->copy()->subDays(90);
        $principal->save();
        $estadoPrevio = $principal->subscription_status;

        $porSubdominio = $this->empresa([
            'subdomain' => 'talent360',
            'current_period_end' => $this->ahora->copy()->subDays(90),
        ]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')
            ->expectsOutputToContain('INTOCABLE')
            ->assertExitCode(0);

        foreach ([$principal, $porSubdominio] as $empresa) {
            $despues = $empresa->fresh();
            $this->assertTrue((bool) $despues->is_active, "Se suspendió al inquilino principal (#{$despues->id})");
            $this->assertNull($despues->suspended_at);
        }
        $this->assertSame($estadoPrevio, $principal->fresh()->subscription_status);
    }

    // ---------------------------------------------------------------------------------------
    // SIMULACRO E IDEMPOTENCIA
    // ---------------------------------------------------------------------------------------

    public function test_el_simulacro_no_escribe_nada(): void
    {
        $empresa = $this->empresa(['current_period_end' => $this->ahora->copy()->subDays(60)]);

        $this->artisan('suscripciones:revisar-vencidas')
            ->expectsOutputToContain('SIMULACRO')
            ->assertExitCode(0);

        $despues = $empresa->fresh();
        $this->assertTrue((bool) $despues->is_active);
        $this->assertSame(EstadoDeCobranza::ACTIVA, $despues->subscription_status);
        $this->assertNull($despues->payment_warning_sent_at);
        $this->assertSame(0, SaasAuditLog::count());
    }

    /** Correrlo dos veces no vuelve a suspender lo suspendido ni duplica el rastro. */
    public function test_es_idempotente(): void
    {
        $empresa = $this->empresa(['current_period_end' => $this->ahora->copy()->subDays(60)]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);
        $primera = $empresa->fresh()->suspended_at;

        Carbon::setTestNow($this->ahora->copy()->addDay());
        $this->artisan('suscripciones:revisar-vencidas --aplicar')
            ->expectsOutputToContain('YA SUSPENDIDA')
            ->assertExitCode(0);

        $this->assertEquals($primera, $empresa->fresh()->suspended_at, 'La segunda corrida movió la fecha de suspensión');
        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_SUSPENSION));
    }

    public function test_el_aviso_se_manda_una_sola_vez_por_ciclo(): void
    {
        $empresa = $this->empresa([
            'current_period_end' => $this->ahora->copy()->subDays(EstadoDeCobranza::DIA_DE_AVISO_POR_DEFECTO),
        ]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);
        $this->assertNotNull($empresa->fresh()->payment_warning_sent_at);
        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_AVISO));

        Carbon::setTestNow($this->ahora->copy()->addDay());
        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);

        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_AVISO), 'Se avisó dos veces en el mismo ciclo');
    }

    // ---------------------------------------------------------------------------------------
    // EL MODO QUE CORRE AGENDADO
    // ---------------------------------------------------------------------------------------

    /**
     * El modo agendado marca y escala, pero NO apaga a nadie: suspender deja a una empresa
     * entera sin reloj checador y eso lo decide una persona.
     */
    public function test_el_modo_agendado_no_suspende_pero_escala_una_sola_vez(): void
    {
        $empresa = $this->empresa(['current_period_end' => $this->ahora->copy()->subDays(30)]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar --sin-suspender')
            ->expectsOutputToContain('NO las suspende')
            ->assertExitCode(0);

        $despues = $empresa->fresh();
        $this->assertTrue((bool) $despues->is_active, 'El modo agendado suspendió a una empresa');
        $this->assertSame(EstadoDeCobranza::MORA, $despues->subscription_status);
        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_LISTA_PARA_SUSPENDER));

        Carbon::setTestNow($this->ahora->copy()->addDays(3));
        $this->artisan('suscripciones:revisar-vencidas --aplicar --sin-suspender')->assertExitCode(0);

        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_LISTA_PARA_SUSPENDER),
            'La alerta se repitió: la bitácora se llenaría con la misma línea todos los días');
    }

    /** Sin esto, todo lo anterior es código muerto (le pasó a shifts:close-orphans). */
    public function test_la_tarea_esta_agendada(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('suscripciones:revisar-vencidas')
            ->assertExitCode(0);
    }

    // ---------------------------------------------------------------------------------------
    // VUELTA A LA NORMALIDAD
    // ---------------------------------------------------------------------------------------

    public function test_el_pago_saca_de_la_mora(): void
    {
        $empresa = $this->empresa([
            'subscription_status' => EstadoDeCobranza::MORA,
            'current_period_end' => $this->ahora->copy()->addDays(20),
        ]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);

        $this->assertSame(EstadoDeCobranza::ACTIVA, $empresa->fresh()->subscription_status);
        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_REACTIVACION));
    }

    /** El barrido apaga, pero no enciende: reactivar el acceso es siempre un acto humano. */
    public function test_una_empresa_suspendida_no_se_reactiva_sola(): void
    {
        $empresa = $this->empresa([
            'is_active' => false,
            'subscription_status' => EstadoDeCobranza::MORA,
            'current_period_end' => $this->ahora->copy()->addDays(20),
        ]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);

        $this->assertFalse((bool) $empresa->fresh()->is_active);
    }

    // ---------------------------------------------------------------------------------------
    // CONFIGURACIÓN Y EXENCIÓN
    // ---------------------------------------------------------------------------------------

    /** Los 5 días de gracia son los que el contrato promete, pero el dueño puede cambiarlos. */
    public function test_el_periodo_de_gracia_es_configurable(): void
    {
        $empresa = $this->empresa([
            'current_period_end' => $this->ahora->copy()->subDays(EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO + 1),
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => null,
            'key' => EstadoDeCobranza::LLAVE_DIAS_DE_GRACIA,
            'value' => json_encode(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);

        $this->assertTrue((bool) $empresa->fresh()->is_active, 'Ignoró los 30 días de gracia configurados');
    }

    public function test_la_exencion_simula_por_defecto_y_exige_motivo(): void
    {
        $empresa = $this->empresa();

        $this->artisan('suscripciones:exentar', ['empresa' => $empresa->id])
            ->assertExitCode(1);
        $this->assertFalse((bool) $empresa->fresh()->billing_exempt);

        $this->artisan('suscripciones:exentar', ['empresa' => $empresa->id, '--motivo' => 'Cortesía'])
            ->expectsOutputToContain('SIMULACRO')
            ->assertExitCode(0);
        $this->assertFalse((bool) $empresa->fresh()->billing_exempt, 'El simulacro escribió la exención');

        $this->artisan('suscripciones:exentar', [
            'empresa' => $empresa->id, '--motivo' => 'Cortesía', '--aplicar' => true,
        ])->assertExitCode(0);

        $despues = $empresa->fresh();
        $this->assertTrue((bool) $despues->billing_exempt);
        $this->assertSame('Cortesía', $despues->billing_exempt_reason);
        $this->assertSame(1, $this->eventos(EstadoDeCobranza::EVENTO_EXENCION));
    }
}
