<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La activación pública por PIN no puede entregar el expediente (2026-08-08).
 *
 * `completeActivation` respondía `$employee->user ?? $employee`. En una petición PÚBLICA no
 * hay sesión, así que el TenantScope hacía que `$employee->user` fuera SIEMPRE null y salía
 * el modelo completo del expediente: CURP, RFC, NSS, domicilio, teléfono, salario y contacto
 * de emergencia — a cambio de acertar un PIN de 6 dígitos, sin límite de intentos.
 *
 * De paso, ese mismo `if ($employee->user)` nunca se cumplía: la activación no activaba la
 * cuenta que promete activar.
 */
class OnboardingPublicoNoFiltraTest extends TestCase
{
    use RefreshDatabase;

    private Employee $ficha;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Onboarding QA', 'subdomain' => 'onbqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $cuenta = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Nuevo', 'email' => 'nuevo@onbqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado', 'is_active' => false,
        ]);

        $this->ficha = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $cuenta->id, 'name' => 'Nuevo',
            'email' => 'nuevo@onbqa.test', 'is_active_employee' => true,
            'pin_code' => '123456',
            'curp' => 'AACQ900101HDFLLN09',
            'rfc' => 'AACQ900101AB1',
            'nss' => '12345678901',
            'address' => 'Av. Siempre Viva 742',
            'phone' => '5599887766',
            'base_salary' => 3000,
            'emergency_contact_name' => 'Rosa',
            'emergency_contact_phone' => '5511223344',
        ]);
    }

    public function test_la_activacion_no_devuelve_datos_personales(): void
    {
        $cuerpo = $this->postJson('/api/v1/public/onboarding/complete', [
            'user_id' => $this->ficha->user_id,
            'pin' => '123456',
            'name' => 'Nuevo Nombre',
        ])->assertOk()->content();

        foreach ([
            'AACQ900101HDFLLN09' => 'CURP',
            'AACQ900101AB1' => 'RFC',
            '12345678901' => 'NSS',
            'Siempre Viva' => 'domicilio',
            '5599887766' => 'teléfono',
            '3000' => 'salario',
            'Rosa' => 'contacto de emergencia',
            '5511223344' => 'teléfono de emergencia',
        ] as $dato => $queEs) {
            $this->assertStringNotContainsString((string) $dato, $cuerpo,
                "una ruta pública no puede devolver el {$queEs} de nadie");
        }
    }

    public function test_la_activacion_si_activa_la_cuenta(): void
    {
        $this->postJson('/api/v1/public/onboarding/complete', [
            'user_id' => $this->ficha->user_id,
            'pin' => '123456',
            'name' => 'Nuevo Nombre',
        ])->assertOk();

        $this->assertTrue(
            (bool) DB::table('users')->where('id', $this->ficha->user_id)->value('is_active'),
            'el flujo promete activar la cuenta: antes nunca la tocaba'
        );
        $this->assertSame('Nuevo Nombre',
            DB::table('users')->where('id', $this->ficha->user_id)->value('name'));
    }

    public function test_el_pin_ya_usado_no_sirve_dos_veces(): void
    {
        $datos = ['user_id' => $this->ficha->user_id, 'pin' => '123456', 'name' => 'Nuevo'];

        $this->postJson('/api/v1/public/onboarding/complete', $datos)->assertOk();
        $this->postJson('/api/v1/public/onboarding/complete', $datos)->assertStatus(403);
    }

    public function test_hay_freno_para_adivinar_el_pin_a_lo_bruto(): void
    {
        // 6 dígitos = un millón de combinaciones: sin freno se barren en minutos.
        $ultimo = null;
        for ($i = 0; $i < 14; $i++) {
            $ultimo = $this->postJson('/api/v1/public/onboarding/verify', [
                'pin' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            ]);
        }

        $this->assertSame(429, $ultimo->status(),
            'la ruta pública tiene que cortar el barrido de PINs');
    }
}
