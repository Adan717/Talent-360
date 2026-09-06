/**
 * Tarifas de los planes de pago, en UN solo lugar.
 *
 * Son las MISMAS que cobra el servidor en `SubscriptionController` (`$employees * 29` / `* 69` al
 * mes; `round($employees * 24 * 12)` / `* 55 * 12` al año). La pantalla de precios las tenía
 * regadas en literales y, además del tabulador anual, calculaba números "anuales" propios como
 * `mensual * 12 * 0.8`. Los dos criterios no dan lo mismo y ambos se pintaban en la MISMA tarjeta:
 *
 *   · Enterprise, 25 colaboradores: "Costo Equivalente" $1,380/mes (= 1725 × 0.8) pintado junto a
 *     "Facturado anualmente: $16,500" — pero 1,380 × 12 son $16,560. Los $60 que no cuadraban.
 *   · Profesional: en vista mensual la tarjeta prometía $6,960/año (725 × 12 × 0.8) y al mover el
 *     interruptor a anual decía $7,200 (el tabulador real). La misma tarjeta, dos precios.
 *
 * El número bueno es el del TABULADOR ANUAL, porque es el que el checkout cobra: si la pantalla
 * anunciara `mensual × 12 × 0.8` estaría prometiendo un precio que el servidor no aplica. Por eso
 * aquí no hay una constante de "20% de descuento": el descuento es la CONSECUENCIA de las dos
 * tarifas, y `ahorroAnualPorcentaje()` lo deriva en vez de afirmarlo (Profesional ahorra 17%,
 * Enterprise 20% — el "Ahorra 20%" plano que decía la pantalla sólo era cierto para uno).
 *
 * OJO: esto NO es la fuente de verdad de los precios, es su espejo del lado del navegador. Si el
 * dueño cambia el tabulador hay que cambiarlo en `SubscriptionController` Y aquí.
 */
export const TARIFAS = {
  pro: { mensualPorColaborador: 29, anualPorColaboradorAlMes: 24 },
  enterprise: { mensualPorColaborador: 69, anualPorColaboradorAlMes: 55 },
} as const;

export type PlanDePago = keyof typeof TARIFAS;

/** Lo que se cobra al mes con facturación mensual. */
export function precioMensual(plan: PlanDePago, colaboradores: number): number {
  return colaboradores * TARIFAS[plan].mensualPorColaborador;
}

/** Lo que se cobra de una vez con facturación anual (lo mismo que calcula el servidor). */
export function precioAnual(plan: PlanDePago, colaboradores: number): number {
  return Math.round(colaboradores * TARIFAS[plan].anualPorColaboradorAlMes * 12);
}

/**
 * El "costo equivalente al mes" que se pinta en la vista anual. Sale del precio anual dividido
 * entre 12 —y no del mensual con un descuento aparte— para que 12 veces lo que dice la tarjeta
 * sea exactamente lo que dice la tarjeta que se factura.
 */
export function precioMensualEquivalente(plan: PlanDePago, colaboradores: number): number {
  return Math.round(precioAnual(plan, colaboradores) / 12);
}

/** Ahorro real del plan anual frente al mensual, en puntos porcentuales redondeados. */
export function ahorroAnualPorcentaje(plan: PlanDePago): number {
  const { mensualPorColaborador, anualPorColaboradorAlMes } = TARIFAS[plan];

  return Math.round((1 - anualPorColaboradorAlMes / mensualPorColaborador) * 100);
}
