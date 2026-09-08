<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\AvisoDeCobranza;
use App\Support\EstadoDeCobranza;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Plan C4 — la consecuencia completa de no pagar: aviso, apagón y reactivación.
 *
 * El ciclo que fijan estas pruebas es el que promete el contrato:
 *   pago falla → la empresa entra en mora y ve el banner (SIGUE trabajando) → se agotan los días
 *   de gracia → el barrido diario apaga el registro de asistencia → entra el pago → se reactiva.
 *
 * Dos decisiones del dueño (2026-09-08) viven aquí y por eso llevan candado:
 *   1. El apagón es AUTOMÁTICO: la agenda ya no corre con `--sin-suspender`. Antes el apagón era
 *      un acto humano, y eso convertía la cobranza entera en una lista que nadie apretaba.
 *   2. La gracia se queda en los 5 días naturales que promete el contrato (no se subió a 7).
 */
class AvisoYApagonPorFaltaDePagoTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $ahora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahora = Carbon::now();
        Carbon::setTestNow($this->ahora);
    }

    private function empresa(array $atributos = []): Tenant
    {
        $sufijo = str_replace('.', '', uniqid('', true));
        $empresa = Tenant::create([
            'name' => 'Empresa ' . $sufijo,
            'subdomain' => 'e' . $sufijo,
            'plan' => 'pro',
            'subscription_status' => $atributos['subscription_status'] ?? EstadoDeCobranza::ACTIVA,
            'stripe_customer_id' => $atributos['stripe_customer_id'] ?? null,
        ]);

        foreach (['is_active', 'billing_exempt', 'suspension_reason'] as $columna) {
            if (array_key_exists($columna, $atributos)) {
                $empresa->{$columna} = $atributos[$columna];
            }
        }
        if (array_key_exists('current_period_end', $atributos)) {
            $empresa->current_period_end = $atributos['current_period_end'];
        }
        $empresa->save();

        return $empresa->fresh();
    }

    // --- 1. La agenda: el apagón es automático --------------------------------------------------

    public function test_la_agenda_diaria_corre_el_barrido_en_modo_que_si_suspende(): void
    {
        // Forzar la carga del kernel de consola: en un feature test es perezoso y sin esto la
        // agenda sale vacía (mismo truco que `WeeklyPayrollCommandTest`).
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();

        $comandos = array_map(
            fn ($evento) => (string) $evento->command,
            app(Schedule::class)->events()
        );

        $barrido = array_values(array_filter(
            $comandos,
            fn (string $c) => str_contains($c, 'suscripciones:revisar-vencidas')
        ));

        $this->assertCount(1, $barrido, 'El barrido de mora tiene que estar agendado exactamente una vez.');
        $this->assertStringContainsString('--aplicar', $barrido[0]);
        $this->assertStringNotContainsString('--sin-suspender', $barrido[0],
            'Decisión del dueño (2026-09-08): pasada la gracia, el apagón es automático.');
    }

    public function test_la_gracia_es_la_que_promete_el_contrato(): void
    {
        // Si este número cambia, el texto de los Términos y Condiciones tiene que cambiar con él:
        // el contrato promete "un periodo de gracia de 5 días naturales".
        $this->assertSame(5, EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO);
    }

    // --- 2. El banner dice lo mismo que decide el motor -----------------------------------------

    public function test_una_empresa_al_corriente_no_ve_ningun_banner(): void
    {
        $empresa = $this->empresa(['current_period_end' => $this->ahora->copy()->addDays(10)]);

        $this->assertNull(AvisoDeCobranza::para($empresa, $this->ahora));
    }

    public function test_una_empresa_sin_fecha_de_corte_no_ve_ningun_banner(): void
    {
        // Es el candado del motor: sin fecha de corte no hay mora que calcular, y el banner no
        // puede afirmar lo que el barrido no va a hacer.
        $this->assertNull(AvisoDeCobranza::para($this->empresa(), $this->ahora));
    }

    public function test_en_gracia_el_banner_avisa_y_dice_cuantos_dias_quedan(): void
    {
        $empresa = $this->empresa([
            'subscription_status' => EstadoDeCobranza::MORA,
            'current_period_end' => $this->ahora->copy()->subDays(2),
        ]);

        $aviso = AvisoDeCobranza::para($empresa, $this->ahora);

        $this->assertNotNull($aviso);
        $this->assertSame(AvisoDeCobranza::TONO_AVISO, $aviso['tono']);
        $this->assertFalse($aviso['bloquea'], 'Dentro de la gracia la empresa sigue trabajando.');
        $this->assertSame(EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO - 2, $aviso['dias_restantes']);
        $this->assertSame(
            $this->ahora->copy()->subDays(2)->addDays(EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO)->toDateString(),
            $aviso['fecha_limite']
        );
    }

    public function test_agotada_la_gracia_el_banner_avisa_del_apagon(): void
    {
        $empresa = $this->empresa([
            'subscription_status' => EstadoDeCobranza::MORA,
            'current_period_end' => $this->ahora->copy()->subDays(EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO + 1),
        ]);

        $aviso = AvisoDeCobranza::para($empresa, $this->ahora);

        $this->assertSame(AvisoDeCobranza::TONO_APAGON, $aviso['tono']);
        $this->assertSame(0, $aviso['dias_restantes']);
    }

    public function test_una_empresa_exenta_nunca_ve_banner_de_cobro(): void
    {
        $empresa = $this->empresa([
            'billing_exempt' => true,
            'current_period_end' => $this->ahora->copy()->subDays(60),
        ]);

        $this->assertNull(AvisoDeCobranza::para($empresa, $this->ahora));
    }

    /** Una suspensión manual (por cualquier otro motivo) no puede disfrazarse de deuda. */
    public function test_una_suspension_que_no_es_por_pago_no_habla_de_pagos(): void
    {
        $empresa = $this->empresa([
            'is_active' => false,
            'subscription_status' => EstadoDeCobranza::CANCELADA,
            'current_period_end' => $this->ahora->copy()->subDays(30),
        ]);

        $this->assertNull(AvisoDeCobranza::para($empresa, $this->ahora));
    }

    public function test_apagada_por_deuda_el_banner_lo_dice(): void
    {
        $empresa = $this->empresa([
            'is_active' => false,
            'subscription_status' => EstadoDeCobranza::MORA,
            'current_period_end' => $this->ahora->copy()->subDays(30),
        ]);

        $aviso = AvisoDeCobranza::para($empresa, $this->ahora);

        $this->assertSame(AvisoDeCobranza::TONO_APAGON, $aviso['tono']);
        $this->assertStringContainsString('falta de pago', strtolower($aviso['titulo']));
    }

    // --- 3. Quién ve el banner ------------------------------------------------------------------

    private function usuario(Tenant $empresa, string $rol): User
    {
        return User::factory()->create(['tenant_id' => $empresa->id, 'role' => $rol]);
    }

    public function test_el_admin_recibe_el_aviso_en_me(): void
    {
        $empresa = $this->empresa([
            'subscription_status' => EstadoDeCobranza::MORA,
            'current_period_end' => $this->ahora->copy()->subDays(2),
        ]);

        $this->actingAs($this->usuario($empresa, 'admin'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('cobranza.tono', AvisoDeCobranza::TONO_AVISO);
    }

    /** El estado de cobranza de la empresa no es asunto de la plantilla. */
    public function test_un_colaborador_no_recibe_el_estado_de_cobranza(): void
    {
        $empresa = $this->empresa([
            'subscription_status' => EstadoDeCobranza::MORA,
            'current_period_end' => $this->ahora->copy()->subDays(2),
        ]);

        $this->actingAs($this->usuario($empresa, 'empleado'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('cobranza', null);
    }

    // --- 4. El ciclo completo -------------------------------------------------------------------

    public function test_el_ciclo_completo_mora_apagon_y_reactivacion_al_pagar(): void
    {
        config(['cashier.webhook.secret' => 'whsec_prueba']);
        Http::fake(['*' => Http::response(['id' => 'inv_1', 'uuid' => 'U-1', 'status' => 'valid'], 200)]);

        $empresa = $this->empresa([
            'subscription_status' => EstadoDeCobranza::MORA,
            'stripe_customer_id' => 'cus_ciclo',
            'current_period_end' => $this->ahora->copy()->subDays(EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO + 1),
        ]);

        // Dentro de la gracia seguía trabajando; agotada, el barrido apaga.
        $this->artisan('suscripciones:revisar-vencidas --aplicar')->assertExitCode(0);

        $empresa->refresh();
        $this->assertFalse((bool) $empresa->is_active, 'Agotada la gracia, el barrido apaga la asistencia.');
        $this->assertSame(EstadoDeCobranza::MOTIVO_DE_SUSPENSION, $empresa->suspension_reason);

        // Entra el pago: Stripe avisa y la empresa vuelve a marcar asistencia.
        $payload = json_encode([
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => [
                'customer' => 'cus_ciclo',
                'subscription' => 'sub_ciclo',
                'amount_paid' => 199900,
                'currency' => 'mxn',
                'period_end' => $this->ahora->copy()->addMonth()->timestamp,
            ]],
        ]);
        $instante = time();

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$instante},v1=" . hash_hmac('sha256', "{$instante}.{$payload}", 'whsec_prueba'),
        ], $payload)->assertStatus(200);

        $empresa->refresh();
        $this->assertTrue((bool) $empresa->is_active, 'Si apagar es automático, encender al cobrar también.');
        $this->assertSame(EstadoDeCobranza::ACTIVA, $empresa->subscription_status);
        $this->assertNull($empresa->suspension_reason);
        $this->assertNull(AvisoDeCobranza::para($empresa->fresh(), $this->ahora), 'Pagada: sin banner.');
    }

    /** Una empresa suspendida a mano por otro motivo NO vuelve sola porque entre un pago. */
    public function test_un_pago_no_reactiva_una_suspension_ajena_a_la_deuda(): void
    {
        config(['cashier.webhook.secret' => 'whsec_prueba']);
        Http::fake(['*' => Http::response(['id' => 'inv_1', 'uuid' => 'U-1', 'status' => 'valid'], 200)]);

        $empresa = $this->empresa([
            'is_active' => false,
            'subscription_status' => EstadoDeCobranza::CANCELADA,
            'suspension_reason' => 'Uso indebido de la plataforma',
            'stripe_customer_id' => 'cus_vetada',
        ]);

        $payload = json_encode([
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => ['customer' => 'cus_vetada', 'amount_paid' => 100, 'currency' => 'mxn']],
        ]);
        $instante = time();

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$instante},v1=" . hash_hmac('sha256', "{$instante}.{$payload}", 'whsec_prueba'),
        ], $payload)->assertStatus(200);

        $empresa->refresh();
        $this->assertFalse((bool) $empresa->is_active);
        $this->assertSame('Uso indebido de la plataforma', $empresa->suspension_reason);
    }
}
