<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CANDADO: ninguna pantalla ni ningún controlador vuelve a traer un precio escrito a mano.
 *
 * El defecto que esta prueba impide que regrese es concreto: el mismo plan llegó a tener CUATRO
 * precios distintos según dónde se mirara, y ninguno sabía de los otros.
 *
 *   - `SubscriptionController`     $29/$24 y $69/$55 por colaborador (lo que se COBRA), y el
 *                                  bloque estaba duplicado letra por letra en dos métodos.
 *   - `SaaSLandingPage.tsx`        las mismas tarifas + un "20%" a mano que producía DOS
 *                                  precios anuales para el mismo plan según el interruptor.
 *   - `SaaSAccountSettings.tsx`    $12 y $499 planos, que no existen en ningún cobro.
 *   - `DashboardTalent360.tsx`     $99 y $499 planos, otro juego distinto.
 *   - `PlatformAdminController`    $199 y $499 planos, con los que se calculaba el MRR.
 *
 * Los precios viven en `billing_plans` y se leen por `App\Support\Tarifario`. Un número aquí
 * vuelve a partir la verdad en dos, así que la prueba falla.
 *
 * Los comentarios se ignoran a propósito: las cifras de arriba TIENEN que poder citarse al
 * explicar por qué se quitaron.
 */
class TarifarioSinPreciosDurosTest extends TestCase
{
    /** Los sitios que tenían un tabulador propio. */
    private const SITIOS = [
        'app/Http/Controllers/SubscriptionController.php',
        'app/Http/Controllers/PlatformAdminController.php',
        '../Frontend/src/components/SaaSLandingPage.tsx',
        '../Frontend/src/components/SaaSAccountSettings.tsx',
        '../Frontend/src/components/DashboardTalent360.tsx',
    ];

    /**
     * Quita comentarios de línea y de bloque para que sólo se juzgue el código que corre.
     * Los comentarios de JSX son bloques normales, así que caen con la misma regla.
     */
    private function soloCodigo(string $fuente): string
    {
        $fuente = preg_replace('#/\*.*?\*/#s', ' ', $fuente);
        $fuente = preg_replace('#^\s*//.*$#m', ' ', $fuente);
        $fuente = preg_replace('#\s//[^\n]*$#m', ' ', $fuente);

        return (string) $fuente;
    }

    public function test_ningun_sitio_vuelve_a_traer_un_precio_escrito_a_mano(): void
    {
        $prohibido = [
            // Los precios planos inventados del panel y de las dos pantallas internas.
            // Se piden en contexto de precio (con `$`, como decimal, o asignados) para no
            // confundirlos con un `rgba(37,99,235)` o un `max-w-[499px]`.
            '/\$\s?(99|199|499)\b/' => 'un precio plano ($99/$199/$499) que no existe en ningún cobro',
            '/\b(99|199|499)\.\d+\b/' => 'un precio plano ($99/$199/$499) que no existe en ningún cobro',
            '/(=|:|\?)\s*(99|199|499)\s*(;|,|\)|:|$)/m' => 'un precio plano ($99/$199/$499) que no existe en ningún cobro',
            '/\bprice\s*:\s*\d/i' => 'un precio escrito en el objeto de la pantalla',
            // El "20% de descuento anual" escrito a mano: derivarlo de una tarifa con un
            // porcentaje es justo lo que daba dos precios anuales para el mismo plan.
            '/\*\s*0\.8\b/' => 'el 20% de descuento anual escrito a mano',
            '/\b0\.8\s*\*/' => 'el 20% de descuento anual escrito a mano',
            // Las tarifas por colaborador multiplicadas a mano. Las mensuales ($29/$69) en
            // cualquier multiplicación; las anuales ($24/$55) sólo con la forma "tarifa × 12",
            // porque un 24 suelto suele ser horas (`1000 * 60 * 60 * 24`).
            '/\*\s*(29|69)\b/' => 'una tarifa mensual por colaborador multiplicada a mano',
            '/\b(29|69)\s*\*/' => 'una tarifa mensual por colaborador multiplicada a mano',
            '/\b(24|55)\s*\*\s*12\b/' => 'una tarifa anual por colaborador multiplicada a mano',
            '/\b12\s*\*\s*(24|55)\b/' => 'una tarifa anual por colaborador multiplicada a mano',
        ];

        foreach (self::SITIOS as $sitio) {
            $ruta = base_path($sitio);
            $this->assertFileExists($ruta, "El candado apunta a un archivo que ya no existe: {$sitio}");

            $codigo = $this->soloCodigo((string) file_get_contents($ruta));

            foreach ($prohibido as $patron => $queEs) {
                $this->assertSame(
                    0,
                    preg_match($patron, $codigo, $m),
                    "{$sitio} volvió a traer {$queEs} (" . trim($m[0] ?? '') . "). Los precios viven "
                    . 'en `billing_plans` y se leen por App\\Support\\Tarifario / el endpoint '
                    . '/public/tarifario. Si hace falta una tarifa nueva, se siembra, no se escribe.'
                );
            }
        }
    }

    public function test_las_pantallas_de_precio_leen_el_tarifario_del_servidor(): void
    {
        // Que no haya números no basta: hay que comprobar que de verdad consumen la fuente
        // única, o el "arreglo" pudo ser simplemente borrar el precio de la pantalla.
        $pantallas = [
            '../Frontend/src/components/SaaSLandingPage.tsx',
            '../Frontend/src/components/SaaSAccountSettings.tsx',
            '../Frontend/src/components/DashboardTalent360.tsx',
        ];

        foreach ($pantallas as $pantalla) {
            $codigo = (string) file_get_contents(base_path($pantalla));
            $this->assertStringContainsString('useTarifario', $codigo, "{$pantalla} ya no lee el tarifario del servidor");
        }

        foreach (['SubscriptionController.php', 'PlatformAdminController.php'] as $controlador) {
            $codigo = (string) file_get_contents(base_path("app/Http/Controllers/{$controlador}"));
            $this->assertStringContainsString('Tarifario::', $codigo, "{$controlador} ya no usa el tarifario único");
        }
    }
}
