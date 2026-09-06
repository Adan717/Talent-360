import { describe, it, expect } from 'vitest';
import {
  TARIFAS,
  precioMensual,
  precioAnual,
  precioMensualEquivalente,
  ahorroAnualPorcentaje,
} from './precios';

describe('precios de los planes', () => {
  it('el precio anual es el que cobra el servidor (colaboradores x tarifa anual x 12)', () => {
    // Misma fórmula que `SubscriptionController::createPreference`: round($employees * 24 * 12)
    // para PRO y round($employees * 55 * 12) para Enterprise. Si esto deja de cuadrar, la
    // pantalla estaría anunciando un precio que el checkout no cobra.
    for (const colaboradores of [6, 10, 20, 25, 50]) {
      expect(precioAnual('pro', colaboradores)).toBe(colaboradores * 24 * 12);
      expect(precioAnual('enterprise', colaboradores)).toBe(colaboradores * 55 * 12);
    }
  });

  it('CANDADO de los $60: doce veces el costo equivalente es el precio anual anunciado', () => {
    // El defecto: Enterprise con 25 colaboradores pintaba "Costo Equivalente $1,380/mes"
    // (1725 x 0.8) junto a "Facturado anualmente: $16,500". 1,380 x 12 = 16,560 — $60 de más
    // en la misma tarjeta. El equivalente ahora SALE del anual, así que no puede descuadrar.
    for (const plan of ['pro', 'enterprise'] as const) {
      for (const colaboradores of [6, 10, 20, 25, 50]) {
        expect(precioMensualEquivalente(plan, colaboradores) * 12).toBe(precioAnual(plan, colaboradores));
      }
    }
  });

  it('los números concretos del caso reportado (Enterprise, 25 colaboradores)', () => {
    expect(precioMensual('enterprise', 25)).toBe(1725);
    expect(precioAnual('enterprise', 25)).toBe(16500);
    expect(precioMensualEquivalente('enterprise', 25)).toBe(1375); // antes decía 1,380
  });

  it('el ahorro anual se deriva de las tarifas, no se afirma', () => {
    // "Ahorra 20%" plano era falso para PRO: 24/29 son 17.2% de ahorro, no 20%.
    expect(ahorroAnualPorcentaje('pro')).toBe(17);
    expect(ahorroAnualPorcentaje('enterprise')).toBe(20);

    for (const plan of ['pro', 'enterprise'] as const) {
      const { mensualPorColaborador, anualPorColaboradorAlMes } = TARIFAS[plan];
      expect(anualPorColaboradorAlMes).toBeLessThan(mensualPorColaborador);
      expect(precioAnual(plan, 10)).toBeLessThan(precioMensual(plan, 10) * 12);
    }
  });
});
