<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EL ÚNICO LUGAR DONDE VIVE UN PRECIO DEL SaaS (2026-09-05).
 *
 * Antes había cuatro tabuladores distintos y ninguno sabía de los otros: el que COBRA
 * `SubscriptionController` ($29/$24 y $69/$55 por colaborador), el que pinta la landing (las
 * mismas tarifas deformadas por un "20%" escrito a mano), el de la pantalla del cliente que ya
 * pagó ($12 y $499 planos) y el del panel de plataforma ($199 y $499 planos, con los que se
 * calculaba el MRR). El mismo plan tenía DOS precios anuales según qué interruptor mirara el
 * cliente: en ciclo mensual la landing prometía 29×12×0.8 = $278.40 por colaborador al año, y
 * en ciclo anual cobraba 24×12 = $288. Nadie podía saber cuál era el precio.
 *
 * Reglas de esta clase:
 *
 *  - **Los precios se leen de `billing_plans`, no del código.** Cambiar el tabulador es cambiar
 *    tres filas en la base; no hay que recompilar ni desplegar.
 *  - **El modelo es POR COLABORADOR.** `price_per_user_monthly` es la tarifa al mes;
 *    `price_per_user_yearly` es esa misma tarifa mensual cuando se factura el año completo.
 *    El total anual es `tarifa_anual × 12 × colaboradores`, y NO "el mensual con un descuento".
 *  - **El descuento anual se DERIVA de las dos tarifas.** Nunca se escribe a mano. Con las
 *    tarifas de hoy, PRO ahorra 17.2% (no el 20% que anunciaba la landing) y Enterprise 20.3%.
 *  - **Un plan de pago sin fila es un error de configuración, no un descuento.** Si falta la
 *    fila de `pro` o `enterprise` esto LANZA, porque el camino silencioso —precio 0— regala el
 *    producto: `createPreference` aprovisiona gratis cuando el precio sale en cero.
 *  - **El tope de colaboradores NO bloquea.** Se expone para avisar (criterio del dueño:
 *    "nada bloquea, todo avisa"). Hoy sigue sin existir código que impida rebasarlo.
 */
class Tarifario
{
    /** Los tres códigos que el producto reconoce. Un código fuera de esta lista no es un plan. */
    public const CODIGOS = ['freemium', 'pro', 'enterprise'];

    /** Lo que `SubscriptionController` asumía cuando el alta no mandaba el número. */
    public const COLABORADORES_POR_DEFECTO = 10;

    public const CICLO_MENSUAL = 'monthly';
    public const CICLO_ANUAL = 'yearly';

    /**
     * ¿El ciclo que pidieron es el anual? Cualquier otra cosa es mensual, que es como se ha
     * comportado siempre `cotizar()`. Existe para que el precio que se COBRA y el periodo que se
     * CONCEDE no puedan separarse: los dos preguntan aquí.
     */
    public static function esAnual(?string $ciclo): bool
    {
        return strtolower(trim((string) $ciclo)) === self::CICLO_ANUAL;
    }

    /**
     * Hasta cuándo queda cubierta una empresa que acaba de pagar ese ciclo: la fecha de corte que
     * consume `EstadoDeCobranza`. Un plan anual cubre un año, no un mes — cobrar 12 mensualidades
     * y conceder una sola era la otra mitad del defecto de la fecha de corte (2026-09-08).
     */
    public static function finDelPeriodo(?string $ciclo, ?CarbonInterface $desde = null): Carbon
    {
        $desde = $desde ? Carbon::parse($desde) : Carbon::now();

        return self::esAnual($ciclo) ? $desde->copy()->addYear() : $desde->copy()->addMonth();
    }

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $memo = null;

    /** Tira el caché de proceso. Las pruebas lo necesitan al reescribir el tabulador. */
    public static function olvidar(): void
    {
        self::$memo = null;
    }

    /**
     * El tabulador completo, en el orden en que se presenta al cliente.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function planes(): array
    {
        $planes = [];
        foreach (self::CODIGOS as $codigo) {
            $plan = self::plan($codigo);
            if ($plan !== null) {
                $planes[] = $plan;
            }
        }

        return $planes;
    }

    /**
     * Un plan por su código. Devuelve null si el código no es de los que el producto reconoce
     * (p. ej. alguien manda `plan=basic`), y LANZA si es uno de pago y no tiene fila.
     *
     * @return array<string,mixed>|null
     */
    public static function plan(?string $codigo): ?array
    {
        $codigo = strtolower(trim((string) $codigo));
        if (!in_array($codigo, self::CODIGOS, true)) {
            return null;
        }

        if (self::$memo === null) {
            self::$memo = [];
            $filas = DB::table('billing_plans')
                ->whereIn('code', self::CODIGOS)
                ->where('is_active', true)
                ->get();

            foreach ($filas as $fila) {
                $mensual = round((float) $fila->price_per_user_monthly, 2);
                $anual = round((float) $fila->price_per_user_yearly, 2);
                $codigoFila = strtolower((string) $fila->code);

                self::$memo[$codigoFila] = [
                    'codigo' => $codigoFila,
                    'nombre' => (string) $fila->name,
                    'moneda' => (string) ($fila->currency ?: 'MXN'),
                    'tarifa_mensual_por_colaborador' => $mensual,
                    'tarifa_anual_por_colaborador' => $anual,
                    'tope_colaboradores' => $fila->max_users === null ? null : (int) $fila->max_users,
                    'descuento_anual_pct' => self::descuentoAnual($mensual, $anual),
                    'es_provisional' => (bool) $fila->is_provisional,
                ];
            }
        }

        if (!isset(self::$memo[$codigo])) {
            if ($codigo === 'freemium') {
                return null;
            }

            throw new RuntimeException(
                "TARIFARIO INCOMPLETO: no hay fila activa en `billing_plans` para el plan '{$codigo}'. "
                . 'No se cotiza a ciegas: un precio 0 aquí regalaría el producto (el alta aprovisiona '
                . 'gratis cuando el total sale en cero). Siembre la fila antes de cobrar.'
            );
        }

        return self::$memo[$codigo];
    }

    /**
     * El descuento anual DERIVADO de las dos tarifas. Este número no se escribe a mano en
     * ninguna parte: si el dueño cambia una tarifa, el porcentaje que anuncia la pantalla se
     * mueve solo y sigue siendo cierto.
     */
    public static function descuentoAnual(float $mensual, float $anual): float
    {
        if ($mensual <= 0) {
            return 0.0;
        }

        return round((1 - ($anual / $mensual)) * 100, 1);
    }

    /**
     * El descuento anual más alto del tabulador. La landing lo usa para la insignia global del
     * interruptor mensual/anual, que hoy prometía un "20%" plano que no era cierto para PRO.
     */
    public static function descuentoAnualMaximo(): float
    {
        $descuentos = array_map(
            fn (array $p) => (float) $p['descuento_anual_pct'],
            array_filter(self::planes(), fn (array $p) => $p['tarifa_mensual_por_colaborador'] > 0)
        );

        return $descuentos === [] ? 0.0 : max($descuentos);
    }

    /**
     * La cotización: qué se cobra por N colaboradores en un ciclo. Es la MISMA cuenta que hace
     * la caja, así que lo que pinta la pantalla y lo que cobra el cobro no pueden separarse.
     *
     * @return array<string,mixed>|null  null si el código no es un plan reconocido.
     */
    public static function cotizar(?string $codigo, ?int $colaboradores = null, ?string $ciclo = null): ?array
    {
        $plan = self::plan($codigo);
        if ($plan === null) {
            return null;
        }

        // `null` significa "no me dijeron cuánta gente": se asume el número que asumía el alta.
        // Un **0 explícito es un cero de verdad** — una empresa sin nadie dentro no factura—, y
        // ese caso es real en el panel de plataforma. Confundir los dos hacía que el MRR
        // cobrara 10 colaboradores por cada empresa vacía.
        $n = $colaboradores === null ? self::COLABORADORES_POR_DEFECTO : max(0, $colaboradores);

        $ciclo = self::esAnual($ciclo) ? self::CICLO_ANUAL : self::CICLO_MENSUAL;

        $totalMensual = round($plan['tarifa_mensual_por_colaborador'] * $n, 2);
        // 12 mensualidades a la tarifa anual. NO es el total mensual con un porcentaje encima:
        // ese atajo es exactamente el que producía dos precios anuales distintos.
        $totalAnual = round($plan['tarifa_anual_por_colaborador'] * 12 * $n, 2);

        return $plan + [
            'colaboradores' => $n,
            'ciclo' => $ciclo,
            'total_mensual' => $totalMensual,
            'total_anual' => $totalAnual,
            // El equivalente mensual del plan anual: lo que la landing llamaba "Costo
            // Equivalente" y calculaba como mensual×0.8, dando $23.20 cuando la tarifa anual
            // real es $24.
            'equivalente_mensual_anual' => round($plan['tarifa_anual_por_colaborador'] * $n, 2),
            'total_a_cobrar' => $ciclo === self::CICLO_ANUAL ? $totalAnual : $totalMensual,
            'unidad' => $ciclo === self::CICLO_ANUAL ? 'MXN/año' : 'MXN/mes',
        ];
    }

    /**
     * Lo que se cobra por un alta o una mejora de plan. Es el único número que ve la pasarela.
     * Un código que no es plan cotiza en 0: así se comportaba el `if/elseif` que sustituye
     * (cae al aprovisionamiento gratuito), y cambiarlo aquí sería cambiar el alta sin decirlo.
     */
    public static function totalACobrar(?string $codigo, ?int $colaboradores = null, ?string $ciclo = null): float
    {
        // En la caja, un 0 es "no lo dijeron": el alta asumía 10 y se sigue asumiendo lo mismo.
        // Nadie contrata una suscripción para cero personas.
        $n = ($colaboradores !== null && $colaboradores > 0) ? $colaboradores : self::COLABORADORES_POR_DEFECTO;
        $cotizacion = self::cotizar($codigo, $n, $ciclo);

        return $cotizacion === null ? 0.0 : (float) $cotizacion['total_a_cobrar'];
    }

    /**
     * El tope de colaboradores del plan: null = sin tope. NO bloquea nada; existe para que el
     * admin de la empresa y el panel de plataforma puedan AVISAR cuando se rebasa.
     */
    public static function topeDeColaboradores(?string $codigo): ?int
    {
        $plan = self::plan($codigo);

        return $plan === null ? null : $plan['tope_colaboradores'];
    }

    /** true mientras el tabulador sembrado siga siendo la foto del código y no la decisión del dueño. */
    public static function esProvisional(): bool
    {
        foreach (self::planes() as $plan) {
            if ($plan['es_provisional']) {
                return true;
            }
        }

        return false;
    }
}
