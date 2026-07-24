<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Reloj R64 (follow-up de la prueba a ESCALA de R63): el throttle de login era `throttle:5,1`
 * PER-IP y contaba TODOS los requests, incluidos los exitosos. En una oficina compartida (todos
 * detrás de la misma IP pública) el chorro de la mañana — 18 empleados logueándose en el mismo
 * minuto — agotaba el cupo de la IP y sólo pasaban 5; el resto recibía 429.
 *
 * El fix calca el patrón del Kiosko (R54): rate-limit manual que cuenta SÓLO los fallos, con dos
 * llaves — una por (cuenta+IP) que protege una cuenta de la fuerza bruta y se limpia al acertar, y
 * un backstop de enumeración por IP que SÓLO cuenta y bloquea intentos a correos INEXISTENTES. Los
 * logins exitosos ya no consumen presupuesto → la oficina no se estrangula.
 *
 * El review adversarial destapó que nginx no propaga la IP del cliente → `$request->ip()` colapsa a
 * una sola IP y un backstop por-IP clásico habría bloqueado logins válidos de toda la plataforma. Por
 * eso el backstop se ata a correos inexistentes: un usuario real siempre usa su correo real, así que
 * NUNCA queda bloqueado por él, aunque la IP colapse. `test_el_backstop_nunca_bloquea_a_una_cuenta_real`
 * es el guardián de esa propiedad.
 */
class LoginThrottlePorCuentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // El rate limiter usa el cache (array en tests): vaciarlo para no arrastrar estado entre tests.
        Cache::flush();
    }

    private function makeUser(string $email, string $password = 'password'): User
    {
        $tenant = Tenant::create([
            'name' => 'Empresa L', 'subdomain' => 'l-' . uniqid(),
            'plan' => 'enterprise', 'is_active' => true,
        ]);
        return User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab',
            'email' => strtolower($email), 'password' => bcrypt($password), 'role' => 'empleado',
        ]);
    }

    /** @return array<string,string> cabeceras para fijar la IP de "oficina" del request. */
    private function fromIp(string $ip): array
    {
        return ['REMOTE_ADDR' => $ip];
    }

    private function login(string $email, string $password, string $ip)
    {
        return $this->withServerVariables($this->fromIp($ip))
            ->postJson('/api/v1/login', ['email' => $email, 'password' => $password]);
    }

    /** El caso reportado: muchos logins EXITOSOS desde la misma IP no se estrangulan entre sí. */
    public function test_muchos_logins_exitosos_desde_la_misma_ip_no_se_estrangulan(): void
    {
        $ip = '203.0.113.10'; // IP pública de la oficina
        for ($i = 0; $i < 8; $i++) {
            $this->makeUser("empleado{$i}@oficina.local", 'secret123');
        }
        for ($i = 0; $i < 8; $i++) {
            $res = $this->login("empleado{$i}@oficina.local", 'secret123', $ip);
            $res->assertStatus(200); // con el viejo throttle:5,1 el 6º daría 429
        }
    }

    /** Fuerza bruta a UNA cuenta: tras 5 fallos, el 6º intento se bloquea (429). */
    public function test_fuerza_bruta_a_una_cuenta_se_bloquea_tras_5_fallos(): void
    {
        $ip = '203.0.113.11';
        $this->makeUser('victima@oficina.local', 'la-buena');

        for ($i = 0; $i < 5; $i++) {
            $this->login('victima@oficina.local', 'mala', $ip)->assertStatus(401);
        }
        $this->login('victima@oficina.local', 'mala', $ip)->assertStatus(429);
        // Y el bloqueo cubre incluso la contraseña correcta (defensa real anti-fuerza-bruta).
        $this->login('victima@oficina.local', 'la-buena', $ip)->assertStatus(429);
    }

    /** El bloqueo de una cuenta NO estrangula a otra cuenta desde la misma IP. */
    public function test_el_bloqueo_de_una_cuenta_no_estrangula_a_otra_en_la_misma_ip(): void
    {
        $ip = '203.0.113.12';
        $this->makeUser('a@oficina.local', 'clave-a');
        $this->makeUser('b@oficina.local', 'clave-b');

        for ($i = 0; $i < 6; $i++) {
            $this->login('a@oficina.local', 'mala', $ip); // A se auto-bloquea
        }
        $this->login('a@oficina.local', 'clave-a', $ip)->assertStatus(429);
        // B, desde la MISMA IP y con su clave correcta, entra sin problema.
        $this->login('b@oficina.local', 'clave-b', $ip)->assertStatus(200);
    }

    /** Un login exitoso LIMPIA el contador de la cuenta (buen UX: los typos previos no se acumulan). */
    public function test_un_login_exitoso_limpia_el_contador_de_la_cuenta(): void
    {
        $ip = '203.0.113.13';
        $this->makeUser('typos@oficina.local', 'la-buena');

        for ($i = 0; $i < 4; $i++) {
            $this->login('typos@oficina.local', 'mala', $ip)->assertStatus(401);
        }
        $this->login('typos@oficina.local', 'la-buena', $ip)->assertStatus(200); // limpia
        // Si NO se hubiera limpiado, 4+4=8 fallos → el último sería 429; con la limpieza sigue 401.
        for ($i = 0; $i < 4; $i++) {
            $this->login('typos@oficina.local', 'mala', $ip)->assertStatus(401);
        }
    }

    /** Backstop de enumeración: barrido de muchos correos INEXISTENTES desde una IP se frena. */
    public function test_backstop_de_enumeracion_frena_el_barrido_de_correos_inexistentes(): void
    {
        $ip = '203.0.113.14';
        // Correos inexistentes → 401 sin coste de bcrypt (el usuario no existe). Cada uno falla 1 vez,
        // así que la llave por-cuenta nunca llega a 5; sólo el backstop de enumeración los suma.
        for ($i = 0; $i < 50; $i++) {
            $this->login("noexiste{$i}@atacante.local", 'x', $ip)->assertStatus(401);
        }
        $this->login('noexiste-final@atacante.local', 'x', $ip)->assertStatus(429);
    }

    /**
     * LA PROPIEDAD DE SEGURIDAD (Hallazgo 1 del review): aunque el backstop de enumeración esté
     * DISPARADO desde una IP (que puede ser la de toda la plataforma si el proxy colapsa la IP), un
     * usuario REAL desde esa misma IP NUNCA queda bloqueado por él — ni con su clave correcta ni con
     * un typo. Sólo su propia llave por-cuenta podría bloquearlo, y sólo tras 5 fallos suyos.
     */
    public function test_el_backstop_nunca_bloquea_a_una_cuenta_real(): void
    {
        $ip = '203.0.113.15';
        $this->makeUser('real@oficina.local', 'la-buena');

        // Dispara el backstop con 51 intentos a correos inexistentes desde esta IP.
        for ($i = 0; $i < 51; $i++) {
            $this->login("basura{$i}@atacante.local", 'x', $ip);
        }
        // El correo inexistente ya está bloqueado (backstop disparado)...
        $this->login('otro-basura@atacante.local', 'x', $ip)->assertStatus(429);
        // ...pero el usuario REAL con su clave correcta entra igual (no lo toca el backstop).
        $this->login('real@oficina.local', 'la-buena', $ip)->assertStatus(200);
        // ...e incluso un typo suyo devuelve 401 (credenciales), NO 429 (no lo gatea el backstop).
        $this->login('real@oficina.local', 'mala', $ip)->assertStatus(401);
    }
}
