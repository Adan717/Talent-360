<?php

namespace App\Support;

/**
 * La convención de arranque del organigrama: **cada puesto reporta al primero del nivel
 * inmediatamente superior que exista**.
 *
 * Vive aquí, en un solo lugar, porque la usan las dos puntas del mismo flujo:
 *
 *  - `OnboardingController::catalogo` la usa para SUGERIR el árbol que el admin revisa y ajusta
 *    en el asistente (campo `reporta_a` de cada puesto).
 *  - `OnboardingController::construirOrganigrama` la usa como RESPALDO cuando el asistente no
 *    manda nada — cliente viejo, o un giro aplicado por API.
 *
 * Si el frontend la recalculara por su cuenta habría dos implementaciones de la misma regla, y
 * tarde o temprano dirían cosas distintas. Es exactamente la divergencia que costó limpiar en
 * el catálogo del asistente.
 *
 * **Es una convención, no una verdad.** Produce líneas que en operación real no cuadran —en la
 * empresa de pruebas dejó al Asesor de Ventas colgando de Supervisor de Compras—, y por eso el
 * asistente obliga al admin a revisarla antes de terminar.
 */
class OrganigramaSugerido
{
    /**
     * Devuelve `['Nombre del puesto' => 'Nombre de su jefe' | null]`.
     *
     * Los puestos sin nivel declarado (`jerarquiaLlaves` 0 o ausente) quedan fuera del cálculo:
     * son los que se siembran al crear la empresa, y tomarlos como "el nivel más alto" hacía que
     * la cabeza real terminara reportándole a uno de ellos.
     */
    public static function para(array $puestos): array
    {
        $porNivel = [];

        foreach ($puestos as $p) {
            $nivel = (int) ($p['jerarquiaLlaves'] ?? 0);
            $nombre = $p['name'] ?? null;

            if ($nivel >= 1 && $nombre) {
                $porNivel[$nivel][] = $nombre;
            }
        }

        $niveles = array_keys($porNivel);
        sort($niveles);

        $sugerencia = [];

        foreach ($puestos as $p) {
            $nombre = $p['name'] ?? null;

            if (!$nombre) {
                continue;
            }

            $nivel = (int) ($p['jerarquiaLlaves'] ?? 0);
            $sugerencia[$nombre] = null;

            if ($nivel < 1) {
                continue;
            }

            foreach (array_reverse($niveles) as $candidato) {
                if ($candidato < $nivel && !empty($porNivel[$candidato])) {
                    $jefe = $porNivel[$candidato][0];

                    // Un puesto no se reporta a sí mismo (dos puestos con el mismo nombre en
                    // niveles distintos).
                    if ($jefe !== $nombre) {
                        $sugerencia[$nombre] = $jefe;
                    }

                    break;
                }
            }
        }

        return $sugerencia;
    }
}
