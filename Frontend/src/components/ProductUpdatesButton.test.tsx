import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ProductUpdatesButton } from './ProductUpdatesButton';
import axiosInstance from '../lib/axios';

vi.mock('../lib/axios', () => ({
  default: { get: vi.fn() },
}));

const mockedGet = vi.mocked(axiosInstance.get);

describe('ProductUpdatesButton', () => {
  beforeEach(() => {
    localStorage.clear();
    mockedGet.mockReset();
  });

  it('no ocupa espacio cuando no hay novedades publicadas', async () => {
    mockedGet.mockResolvedValue({ data: { updates: [] } } as any);
    const { container } = render(<ProductUpdatesButton userId={7} />);

    await waitFor(() => expect(mockedGet).toHaveBeenCalledWith('/product-updates'));
    expect(container).toBeEmptyDOMElement();
  });

  it('muestra un indicador sin leer y abre el detalle accesible', async () => {
    mockedGet.mockResolvedValue({
      data: {
        updates: [{
          id: 'u-1',
          title: 'Nueva bandeja',
          summary: 'Ya puedes gestionar incidencias desde el reloj.',
          published_at: '2026-09-15T12:00:00-06:00',
          is_active: true,
          target_module: 'reloj',
        }],
      },
    } as any);

    const onOpenModule = vi.fn();
    render(<ProductUpdatesButton userId={7} onOpenModule={onOpenModule} />);

    const trigger = await screen.findByRole('button', { name: '1 novedades sin leer' });
    fireEvent.click(trigger);

    expect(screen.getByRole('dialog', { name: 'Novedades de Talent 360' })).toBeInTheDocument();
    expect(screen.getByText('Nueva bandeja')).toBeInTheDocument();
    expect(localStorage.getItem('talent360_read_product_updates_7')).toContain('u-1');

    fireEvent.click(screen.getByRole('button', { name: /Abrir módulo/ }));
    expect(onOpenModule).toHaveBeenCalledWith('reloj');
  });
});
