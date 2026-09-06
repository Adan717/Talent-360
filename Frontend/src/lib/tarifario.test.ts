import { describe, it, expect } from 'vitest';
import { cotizar, planDelTarifario, leerTarifario, pesos, type Tarifario } from './tarifario';

/**
 * La pantalla y la caja tienen que dar EL MISMO número.
 *
 * Lo que se corrige: la landing calculaba el precio anual de dos maneras distintas según el
 * interruptor que mirara el cliente. Con 10 colaboradores en PRO decía $2,784 (29×12×0.8×10)
 * con el interruptor en mensual y $2,880 (24×12×10) con el interruptor en anual. La caja
 * cobraba $2,880. Los valores esperados de abajo son los que cobra `SubscriptionController`,
 * comprobados del otro lado en `Backend/tests/Feature/TarifarioTest.php`.
 */

// La respuesta del servidor con el tabulador sembrado al 2026-09-05.
const delServidor: Tarifario = leerTarifario({
  planes: [
    {
      codigo: 'freemium', nombre: 'Plan Gratuito', moneda: 'MXN',
      tarifa_mensual_por_colaborador: 0, tarifa_anual_por_colaborador: 0,
      tope_colaboradores: 10, descuento_anual_pct: 0, es_provisional: true,
    },
    {
      codigo: 'pro', nombre: 'Plan Profesional', moneda: 'MXN',
      tarifa_mensual_por_colaborador: 29, tarifa_anual_por_colaborador: 24,
      tope_colaboradores: null, descuento_anual_pct: 17.2, es_provisional: true,
    },
    {
      codigo: 'enterprise', nombre: 'Plan Enterprise', moneda: 'MXN',
      tarifa_mensual_por_colaborador: 69, tarifa_anual_por_colaborador: 55,
      tope_colaboradores: null, descuento_anual_pct: 20.3, es_provisional: true,
    },
  ],
  colaboradores_por_defecto: 10,
  descuento_anual_maximo_pct: 20.3,
  es_provisional: true,
})!;

const pro = planDelTarifario(delServidor, 'pro');
const enterprise = planDelTarifario(delServidor, 'enterprise');

describe('tarifario: la cuenta de la pantalla es la de la caja', () => {
  it('el total mensual es la tarifa por colaborador por la gente', () => {
    expect(cotizar(pro, 10, 'monthly')!.totalMensual).toBe(290);
    expect(cotizar(enterprise, 10, 'monthly')!.totalMensual).toBe(690);
    expect(cotizar(pro, 17, 'monthly')!.totalMensual).toBe(493);
  });

  it('el total anual son DOCE MENSUALIDADES a la tarifa anual, no el mensual con un 20%', () => {
    // El defecto: 29 × 12 × 0.8 × 10 = 2784, contra los 2880 que de verdad se cobran.
    expect(cotizar(pro, 10, 'yearly')!.totalAnual).toBe(2880);
    expect(cotizar(pro, 10, 'yearly')!.totalAnual).not.toBe(Math.round(29 * 10 * 12 * 0.8));
    expect(cotizar(enterprise, 10, 'yearly')!.totalAnual).toBe(6600);
    expect(cotizar(pro, 17, 'yearly')!.totalAnual).toBe(4896);
  });

  it('el anual NO depende del interruptor que esté mirando el cliente', () => {
    // Era el corazón del defecto: el mismo plan y la misma gente, dos precios anuales.
    const enMensual = cotizar(pro, 23, 'monthly')!;
    const enAnual = cotizar(pro, 23, 'yearly')!;
    expect(enMensual.totalAnual).toBe(enAnual.totalAnual);
    expect(enMensual.totalMensual).toBe(enAnual.totalMensual);
  });

  it('el equivalente mensual del plan anual es la tarifa anual, no el mensual rebajado', () => {
    // La landing pintaba 29 × 0.8 = $23.20 por colaborador; la tarifa anual real es $24.
    expect(cotizar(pro, 1, 'yearly')!.equivalenteMensualAnual).toBe(24);
    expect(cotizar(pro, 1, 'yearly')!.equivalenteMensualAnual).not.toBe(29 * 0.8);
  });

  it('el total a cobrar es el del ciclo elegido', () => {
    expect(cotizar(pro, 10, 'monthly')!.totalACobrar).toBe(290);
    expect(cotizar(pro, 10, 'yearly')!.totalACobrar).toBe(2880);
  });

  it('el freemium no cobra y su tope viene del servidor', () => {
    const freemium = planDelTarifario(delServidor, 'freemium');
    expect(cotizar(freemium, 9, 'monthly')!.totalMensual).toBe(0);
    expect(freemium!.tope_colaboradores).toBe(10);
    expect(pro!.tope_colaboradores).toBeNull();
  });

  it('sin tarifario no hay cotización: la pantalla no inventa un precio', () => {
    expect(planDelTarifario(null, 'pro')).toBeNull();
    expect(cotizar(null, 10, 'monthly')).toBeNull();
    expect(leerTarifario({ planes: [] })).toBeNull();
    expect(leerTarifario(undefined)).toBeNull();
  });

  it('las cifras enteras se pintan sin centavos, y las que tienen centavos con los dos', () => {
    expect(pesos(2880)).toBe('2,880');
    expect(pesos(493)).toBe('493');
    expect(pesos(23.2)).toBe('23.20');
  });
});
