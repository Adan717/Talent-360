<?php

namespace App\Http\Controllers;

use App\Support\Tarifario;
use Illuminate\Http\Request;

/**
 * El tabulador que la pantalla PINTA (2026-09-05).
 *
 * Es público a propósito: la landing lo consume antes de que exista cuenta alguna, que es
 * justo donde nacían los precios inventados. Aquí no hay secreto: son los precios de lista.
 *
 * La pantalla no vuelve a calcular ningún precio a partir de otro. Si manda `colaboradores`,
 * el servidor devuelve además la cotización completa —total mensual, total anual y el
 * equivalente mensual del plan anual— y es exactamente la misma cuenta que hace la caja.
 */
class TarifarioController extends Controller
{
    public function publico(Request $request)
    {
        $request->validate([
            'colaboradores' => 'nullable|integer|min:1|max:100000',
        ]);

        $colaboradores = $request->filled('colaboradores') ? (int) $request->input('colaboradores') : null;

        $planes = array_map(function (array $plan) use ($colaboradores) {
            $cotizacion = Tarifario::cotizar($plan['codigo'], $colaboradores);

            return $plan + [
                'colaboradores' => $cotizacion['colaboradores'],
                'total_mensual' => $cotizacion['total_mensual'],
                'total_anual' => $cotizacion['total_anual'],
                'equivalente_mensual_anual' => $cotizacion['equivalente_mensual_anual'],
            ];
        }, Tarifario::planes());

        return response()->json([
            'planes' => $planes,
            'colaboradores' => $colaboradores ?: Tarifario::COLABORADORES_POR_DEFECTO,
            'colaboradores_por_defecto' => Tarifario::COLABORADORES_POR_DEFECTO,
            // Derivado de las tarifas, nunca escrito a mano: la landing anunciaba un "20%"
            // plano que no era cierto para PRO (su ahorro real es 17.2%).
            'descuento_anual_maximo_pct' => Tarifario::descuentoAnualMaximo(),
            // La pantalla debe poder decir que este tabulador es la foto de lo que el código
            // cobraba, no el tabulador oficial del dueño.
            'es_provisional' => Tarifario::esProvisional(),
        ]);
    }
}
