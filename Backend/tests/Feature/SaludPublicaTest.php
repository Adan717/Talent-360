<?php

namespace Tests\Feature;

use App\Support\EstadoDelRespaldo;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/health`, la única señal que mira el vigilante externo (docs/VIGILANTE_DEL_SERVIDOR.md).
 *
 * Lo que se fija aquí es el CONTRATO con ese vigilante, que es el código HTTP y nada más: si
 * alguien vuelve a hacer que devuelva 200 pase lo que pase —como hacía el cierre inline que esto
 * reemplazó—, el vigilante se queda en verde con la nómina caída y nadie se entera hasta que un
 * cliente llama.
 *
 * Sin RefreshDatabase a propósito: la prueba candado necesita FINGIR que la base no responde, y
 * ese trait tendría que hablar con la base al terminar para deshacer su transacción.
 */
class SaludPublicaTest extends TestCase
{
    /** Contenido real de la marca en esta máquina, si lo hubiera, para devolverlo al terminar. */
    private ?string $marcaPrevia = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marcaPrevia = is_file($this->ruta()) ? (string) file_get_contents($this->ruta()) : null;
    }

    protected function tearDown(): void
    {
        $this->borrarMarca();
        if ($this->marcaPrevia !== null) {
            $this->escribirMarca($this->marcaPrevia);
        }
        parent::tearDown();
    }

    private function ruta(): string
    {
        return EstadoDelRespaldo::ruta();
    }

    private function escribirMarca(string $contenido): void
    {
        $dir = dirname($this->ruta());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->ruta(), $contenido);
    }

    /** Marca escrita hace $horas horas, relativa a AHORA: sin fechas literales. */
    private function marcaDeHace(float $horas): void
    {
        $this->escribirMarca(json_encode([
            'instancia' => 'v2',
            'terminado_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - (int) round($horas * 3600)),
            'dump_bytes' => 8675309,
        ]));
    }

    private function borrarMarca(): void
    {
        if (is_file($this->ruta())) {
            unlink($this->ruta());
        }
        @rmdir(dirname($this->ruta()));
    }

    /**
     * Finge una base caída con el aspecto REAL que tiene el fallo en el servidor: el mensaje
     * lleva SQLSTATE, el nombre del contenedor de Postgres y su IP interna. Es exactamente lo
     * que el endpoint viejo concatenaba al JSON público.
     */
    private const PDO_REAL = 'SQLSTATE[08006] [7] connection to server at "talent360_v2_postgres" '
        . '(172.18.0.2), port 5432 failed: Connection refused';

    private function conLaBaseCaida(): void
    {
        DB::shouldReceive('connection')->andThrow(new \PDOException(self::PDO_REAL));
    }

    // ---------- verde ----------

    public function test_base_viva_y_respaldo_fresco_dan_200(): void
    {
        $this->marcaDeHace(2);

        $res = $this->getJson('/api/health');

        $res->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('db', 'ok')
            ->assertJsonPath('respaldo.ok', true)
            ->assertJsonPath('respaldo.motivo', null);

        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_ya_no_anuncia_una_version_que_nadie_actualizaba(): void
    {
        $this->marcaDeHace(2);

        $cuerpo = $this->getJson('/api/health')->json();

        $this->assertArrayNotHasKey('version', $cuerpo);
        $this->assertArrayHasKey('timestamp', $cuerpo);
    }

    // ---------- la prueba candado: nunca filtrar el error de PDO ----------

    /**
     * CANDADO. El endpoint viejo hacía `'fail: ' . $e->getMessage()` y lo devolvía en el cuerpo
     * de una ruta PÚBLICA y sin sesión: SQLSTATE, el nombre del host de Postgres y su IP, a
     * cualquiera que pasara por ahí. Si alguien vuelve a concatenar la excepción, esto revienta.
     */
    public function test_con_la_base_caida_responde_503_y_no_filtra_una_letra_del_error_de_pdo(): void
    {
        $this->marcaDeHace(2);
        $this->conLaBaseCaida();

        $res = $this->getJson('/api/health');

        // Primero lo que no debe estar, y sobre el cuerpo ENTERO: si se afirmara antes
        // `db == 'fail'`, un `'fail: ' . $e->getMessage()` reventaría en esa línea y esta
        // prueba dejaría de demostrar lo que dice demostrar.
        $cuerpo = (string) $res->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $cuerpo);
        $this->assertStringNotContainsString('talent360_v2_postgres', $cuerpo);
        $this->assertStringNotContainsString('172.18.0.2', $cuerpo);
        $this->assertStringNotContainsString('5432', $cuerpo);
        $this->assertStringNotContainsString('fail:', $cuerpo);
        $this->assertStringNotContainsString('Connection refused', $cuerpo);

        $res->assertStatus(503)
            ->assertJsonPath('status', 'degradado')
            ->assertJsonPath('db', 'fail');
    }

    public function test_con_la_base_caida_el_respaldo_se_sigue_pudiendo_leer(): void
    {
        // Es la razón de que EstadoDelRespaldo no toque la base: el informe tiene que seguir
        // siendo completo justamente cuando Postgres es el que falla.
        $this->marcaDeHace(2);
        $this->conLaBaseCaida();

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('respaldo.ok', true);
    }

    // ---------- el respaldo también tumba el semáforo ----------

    public function test_un_respaldo_de_30_horas_da_503_por_viejo(): void
    {
        $this->marcaDeHace(30);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degradado')
            ->assertJsonPath('db', 'ok')            // la base está bien: el problema es otro
            ->assertJsonPath('respaldo.ok', false)
            ->assertJsonPath('respaldo.motivo', 'viejo');
    }

    public function test_sin_marca_de_respaldo_da_503(): void
    {
        $this->borrarMarca();

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('respaldo.motivo', 'sin_marca')
            ->assertJsonPath('respaldo.ultimo_utc', null);
    }

    public function test_una_marca_corrupta_da_503_por_ilegible(): void
    {
        $this->escribirMarca('{"instancia":"v2","termi');

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('respaldo.motivo', 'ilegible');
    }

    // ---------- el vigilante externo no manda cabeceras de navegador ----------

    public function test_responde_json_aunque_no_pidan_json(): void
    {
        // UptimeRobot y compañía piden con Accept de navegador. Sin esto no se sabría si el
        // 503 llega como JSON o como una página de error de Laravel.
        $this->marcaDeHace(2);

        $res = $this->call('GET', '/api/health');

        $res->assertStatus(200);
        $this->assertStringContainsString('application/json', (string) $res->headers->get('Content-Type'));
        $this->assertSame('ok', $res->json('status'));
    }

    public function test_la_ruta_no_pide_sesion_ni_cabeceras_de_dispositivo(): void
    {
        // Sin token y sin X-Device-Fingerprint: un vigilante externo no tiene ninguno de los dos.
        $this->borrarMarca();

        $this->call('GET', '/api/health')->assertStatus(503);
    }
}
