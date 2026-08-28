<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El throttle del kiosco cuenta por EMPLEADO, no por tablet (2026-08-28, revisión externa r2-b).
 *
 * El kiosco tenía `throttle:5,1` de ruta, que en un endpoint sin sesión cuenta POR IP — y la
 * tablet de la sucursal es UNA sola IP. A las 8:00 llegan veinte personas: al sexto fichaje de
 * la mañana el kiosco entero se cerraba, contando además los logins EXITOSOS. El remedio es el
 * mismo que ya usaba /login: contar sólo intentos FALLIDOS, por empleado, con un backstop por IP
 * que sólo cuenta la ENUMERACIÓN (empleados inexistentes o sin PIN) — el typo de una persona
 * real nunca cierra la tablet a los demás.
 */
class KioscoThrottlePorEmpleadoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Kiosco QA', 'subdomain' => 'kioscoqa', 'plan' => 'enterprise', 'is_active' => true]);
    }

    /** Da de alta a una persona con PIN de kiosco y devuelve el id de su expediente. */
    private function persona(string $nombre, string $pin): int
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@kioscoqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'salario_diario' => 400, 'restDay' => 'Domingo', 'hire_date' => '2026-01-01',
            'security_pin' => Hash::make($pin),
        ]);

        return $employee->id;
    }

    private function intento(int $employeeId, string $pin)
    {
        return $this->postJson('/api/v1/clock/kiosk-login', ['employee_id' => $employeeId, 'pin' => $pin]);
    }

    /** LA MAÑANA DE LA TABLET: seis personas fichan seguidas desde la misma IP, todas entran. */
    public function test_seis_logins_exitosos_seguidos_desde_la_misma_ip_pasan_todos(): void
    {
        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $ids[] = $this->persona("Persona {$i}", '246810');
        }

        foreach ($ids as $i => $id) {
            $this->intento($id, '246810')->assertStatus(200)->assertJsonPath('success', true);
        }
    }

    /** Cinco PIN equivocados bloquean A ESA persona (el sexto intento es 429). */
    public function test_cinco_fallos_bloquean_al_empleado(): void
    {
        $id = $this->persona('Torpe Con Su Pin', '246810');

        for ($i = 0; $i < 5; $i++) {
            $this->intento($id, '000000')->assertStatus(422);
        }
        $this->intento($id, '246810')->assertStatus(429); // ya ni el PIN correcto entra: a esperar
    }

    /** …pero NO cierran la tablet: la persona de al lado entra con su PIN como si nada. */
    public function test_el_bloqueo_de_uno_no_alcanza_a_los_demas(): void
    {
        $bloqueado = $this->persona('Bloqueado', '246810');
        $companera = $this->persona('Companera', '135790');

        for ($i = 0; $i < 6; $i++) {
            $this->intento($bloqueado, '000000');
        }
        $this->intento($bloqueado, '000000')->assertStatus(429);

        $this->intento($companera, '135790')->assertStatus(200)->assertJsonPath('success', true);
    }

    /** Acertar limpia el contador: el typo de ayer no le cobra el fichaje de hoy. */
    public function test_acertar_limpia_el_contador_de_fallos(): void
    {
        $id = $this->persona('Se Equivoca Y Acierta', '246810');

        for ($i = 0; $i < 4; $i++) {
            $this->intento($id, '999999')->assertStatus(422);
        }
        $this->intento($id, '246810')->assertStatus(200);

        // Contador limpio: puede volver a equivocarse 4 veces sin caer en 429.
        for ($i = 0; $i < 4; $i++) {
            $this->intento($id, '999999')->assertStatus(422);
        }
        $this->intento($id, '246810')->assertStatus(200);
    }

    /** La ENUMERACIÓN sí se frena por IP: 50 ids inexistentes y la IP queda estrangulada. */
    public function test_la_enumeracion_de_empleados_se_frena_por_ip(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->intento(900000 + $i, '123456')->assertStatus(422);
        }
        $this->intento(999999, '123456')->assertStatus(429);
    }
}
