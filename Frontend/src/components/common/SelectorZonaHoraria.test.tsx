import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { SelectorZonaHoraria, ZONAS_HORARIAS } from './SelectorZonaHoraria';

describe('SelectorZonaHoraria', () => {
  it('ofrece el catálogo completo y deja marcada la zona de la empresa', () => {
    render(<SelectorZonaHoraria value="America/Tijuana" onChange={() => {}} />);

    const select = screen.getByLabelText('Zona Horaria del Reloj Checador') as HTMLSelectElement;
    expect(select.value).toBe('America/Tijuana');
    expect(select.options).toHaveLength(ZONAS_HORARIAS.length);

    cleanup();
  });

  it('CANDADO: una zona fuera del catálogo se muestra, no se pinta el selector vacío', () => {
    // `tenants:fijar-zona-horaria` acepta cualquier zona IANA. Sin la opción extra, el <select>
    // aparecería en blanco y la pantalla aparentaría que la empresa no tiene zona configurada
    // —justo la afirmación falsa que el backend no respalda—.
    render(<SelectorZonaHoraria value="America/Bogota" onChange={() => {}} />);

    const select = screen.getByLabelText('Zona Horaria del Reloj Checador') as HTMLSelectElement;
    expect(select.value).toBe('America/Bogota');
    expect(select.options).toHaveLength(ZONAS_HORARIAS.length + 1);
    expect(select.selectedOptions[0].textContent).toContain('America/Bogota');

    cleanup();
  });

  it('avisa del cambio con el identificador IANA, que es lo que el servidor valida', () => {
    const alCambiar = vi.fn();
    render(<SelectorZonaHoraria value="America/Mexico_City" onChange={alCambiar} />);

    fireEvent.change(screen.getByLabelText('Zona Horaria del Reloj Checador'), {
      target: { value: 'America/Hermosillo' },
    });

    expect(alCambiar).toHaveBeenCalledWith('America/Hermosillo');

    cleanup();
  });
});
