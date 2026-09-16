import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { ProductPreview } from './ProductPreview';

afterEach(cleanup);

describe('Landing lab product preview', () => {
  it('labels its dashboard as illustrative data', () => {
    render(<ProductPreview compact />);
    expect(screen.getByRole('heading', { name: 'Monitor 360' })).toBeVisible();
    expect(screen.getByText('Datos de ejemplo')).toBeVisible();
    expect(screen.getByRole('img', { name: /Ejemplo: asistencia/ })).toBeVisible();
  });

  it('registers and resets an attendance example entirely in local state', () => {
    render(<ProductPreview view="asistencia" />);
    fireEvent.click(screen.getByRole('button', { name: 'Registrar entrada de ejemplo' }));
    expect(screen.getByRole('status')).toHaveTextContent('Entrada registrada. ¡Buen día, Ana!');
    fireEvent.click(screen.getByRole('button', { name: 'Reiniciar ejemplo' }));
    expect(screen.getByRole('button', { name: 'Registrar entrada de ejemplo' })).toBeVisible();
    expect(screen.getByText('Pendiente')).toBeVisible();
  });

  it('filters by department or name, ignoring case, spaces and accents', () => {
    render(<ProductPreview view="equipo" />);
    const search = screen.getByRole('textbox', { name: 'Buscar en el directorio de ejemplo' });
    fireEvent.change(search, { target: { value: ' PRODUCTO ' } });
    expect(screen.getAllByRole('article')).toHaveLength(1);
    expect(screen.getByRole('heading', { name: 'Ana Martínez' })).toBeVisible();
    fireEvent.change(search, { target: { value: 'lucia' } });
    expect(screen.getByRole('heading', { name: 'Lucía Sánchez' })).toBeVisible();
    expect(screen.queryByRole('heading', { name: 'Ana Martínez' })).not.toBeInTheDocument();
  });

  it('announces an empty search and restores the directory when cleared', () => {
    render(<ProductPreview view="equipo" />);
    const search = screen.getByRole('textbox', { name: 'Buscar en el directorio de ejemplo' });
    fireEvent.change(search, { target: { value: 'no-existe' } });
    expect(screen.getByRole('status')).toHaveTextContent('No encontramos coincidencias');
    expect(screen.queryAllByRole('article')).toHaveLength(0);
    fireEvent.change(search, { target: { value: '' } });
    expect(screen.getAllByRole('article')).toHaveLength(4);
  });
});
