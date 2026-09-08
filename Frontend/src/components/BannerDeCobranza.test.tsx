import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { BannerDeCobranza } from './BannerDeCobranza';
import type { AvisoDeCobranza } from '../types';

/**
 * Candado del banner de pago pendiente (Plan C4).
 *
 * Lo que se protege es que la pantalla NO invente su propia cuenta: pinta el texto que compuso el
 * servidor y nada más. Si alguien mete aquí un cálculo de días de gracia, la app acabará
 * prometiendo un plazo distinto del que respeta el backend.
 */
const aviso = (extra: Partial<AvisoDeCobranza> = {}): AvisoDeCobranza => ({
  tono: 'aviso',
  titulo: 'Tenemos un pago pendiente',
  mensaje: 'Tienes 3 días (hasta el 13 de septiembre) para regularizarlo.',
  dias_restantes: 3,
  fecha_limite: '2026-09-13',
  fecha_de_corte: '2026-09-08',
  bloquea: false,
  ...extra,
});

describe('BannerDeCobranza', () => {
  it('no pinta nada cuando la empresa está al corriente (sin aviso)', () => {
    const { container } = render(<BannerDeCobranza aviso={null} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('pinta el título y el mensaje TAL CUAL los mandó el servidor', () => {
    render(<BannerDeCobranza aviso={aviso()} />);

    expect(screen.getByText('Tenemos un pago pendiente')).toBeTruthy();
    expect(screen.getByText('Tienes 3 días (hasta el 13 de septiembre) para regularizarlo.')).toBeTruthy();
  });

  it('el aviso dentro de la gracia es ámbar y el apagón es rojo', () => {
    const { rerender } = render(<BannerDeCobranza aviso={aviso()} />);
    expect(screen.getByTestId('banner-de-cobranza').className).toContain('amber');

    rerender(<BannerDeCobranza aviso={aviso({ tono: 'apagon', dias_restantes: 0 })} />);
    expect(screen.getByTestId('banner-de-cobranza').className).toContain('red');
  });
});
