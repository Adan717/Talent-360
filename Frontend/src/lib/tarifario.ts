/**
 * LOS PRECIOS DEL SaaS, TAL COMO LOS DA EL SERVIDOR (2026-09-05).
 *
 * Este archivo NO contiene ni un solo precio. Los trae `GET /public/tarifario`, que los lee de
 * `billing_plans` — la misma tabla con la que la caja cobra. Aquí sólo vive la ÚNICA fórmula
 * que convierte una tarifa por colaborador en un total, y es la traducción literal de
 * `App\Support\Tarifario::cotizar`.
 *
 * Qué se está corrigiendo: la landing calculaba el precio anual de DOS maneras distintas según
 * el interruptor que mirara el cliente. En ciclo mensual anunciaba `mensual × 12 × 0.8`
 * (= $278.40 por colaborador al año en PRO) y en ciclo anual `tarifa_anual × 12` (= $288). El
 * mismo plan, dos precios. Además pintaba un "Costo Equivalente" de `29 × 0.8 = $23.20` cuando
 * la tarifa anual real es $24, y la pantalla del cliente que ya pagó traía $12 y $499 planos
 * que no existen en ningún cobro.
 *
 * Regla: **no se deriva un precio de otro con un porcentaje**. El total anual son doce
 * mensualidades a la tarifa anual, y el descuento se calcula a partir de las dos tarifas.
 *
 * Si el tarifario no llegó del servidor, la pantalla NO inventa un número: no muestra precio.
 */

export type CicloDeFacturacion = 'monthly' | 'yearly';

export interface PlanDelTarifario {
  codigo: string;
  nombre: string;
  moneda: string;
  tarifa_mensual_por_colaborador: number;
  tarifa_anual_por_colaborador: number;
  /** null = el plan no tiene tope de colaboradores. */
  tope_colaboradores: number | null;
  /** Derivado de las dos tarifas por el servidor. Nunca escrito a mano. */
  descuento_anual_pct: number;
  es_provisional: boolean;
}

export interface Tarifario {
  planes: PlanDelTarifario[];
  colaboradores_por_defecto: number;
  descuento_anual_maximo_pct: number;
  es_provisional: boolean;
}

export interface Cotizacion {
  totalMensual: number;
  totalAnual: number;
  /** Lo que cuesta cada mes del plan anual. NO es el mensual con un porcentaje encima. */
  equivalenteMensualAnual: number;
  /** El número que la caja cobrará por el ciclo elegido. */
  totalACobrar: number;
  unidad: string;
}

const redondear = (n: number): number => Math.round(n * 100) / 100;

export const planDelTarifario = (
  tarifario: Tarifario | null,
  codigo: string
): PlanDelTarifario | null => {
  if (!tarifario) return null;
  const buscado = codigo.trim().toLowerCase();
  return tarifario.planes.find(p => p.codigo === buscado) ?? null;
};

/**
 * La misma cuenta que hace el servidor. Si el plan no llegó, no hay cotización: null.
 */
export const cotizar = (
  plan: PlanDelTarifario | null,
  colaboradores: number,
  ciclo: CicloDeFacturacion
): Cotizacion | null => {
  if (!plan) return null;

  const n = colaboradores > 0 ? colaboradores : 0;
  const totalMensual = redondear(plan.tarifa_mensual_por_colaborador * n);
  const totalAnual = redondear(plan.tarifa_anual_por_colaborador * 12 * n);

  return {
    totalMensual,
    totalAnual,
    equivalenteMensualAnual: redondear(plan.tarifa_anual_por_colaborador * n),
    totalACobrar: ciclo === 'yearly' ? totalAnual : totalMensual,
    unidad: ciclo === 'yearly' ? 'MXN/año' : 'MXN/mes',
  };
};

/** Para pintar cifras: sin decimales cuando son enteras, como venía haciéndolo la landing. */
export const pesos = (n: number): string =>
  n.toLocaleString('es-MX', {
    minimumFractionDigits: Number.isInteger(n) ? 0 : 2,
    maximumFractionDigits: 2,
  });

/** Normaliza la respuesta del endpoint; devuelve null si no trae planes utilizables. */
export const leerTarifario = (data: any): Tarifario | null => {
  if (!data || !Array.isArray(data.planes) || data.planes.length === 0) return null;

  const planes: PlanDelTarifario[] = data.planes.map((p: any) => ({
    codigo: String(p.codigo ?? '').toLowerCase(),
    nombre: String(p.nombre ?? ''),
    moneda: String(p.moneda ?? 'MXN'),
    tarifa_mensual_por_colaborador: Number(p.tarifa_mensual_por_colaborador ?? 0),
    tarifa_anual_por_colaborador: Number(p.tarifa_anual_por_colaborador ?? 0),
    tope_colaboradores:
      p.tope_colaboradores === null || p.tope_colaboradores === undefined
        ? null
        : Number(p.tope_colaboradores),
    descuento_anual_pct: Number(p.descuento_anual_pct ?? 0),
    es_provisional: Boolean(p.es_provisional),
  }));

  return {
    planes,
    colaboradores_por_defecto: Number(data.colaboradores_por_defecto ?? 10),
    descuento_anual_maximo_pct: Number(data.descuento_anual_maximo_pct ?? 0),
    es_provisional: Boolean(data.es_provisional),
  };
};
