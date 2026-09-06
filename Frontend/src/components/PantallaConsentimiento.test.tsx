import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

vi.mock('../lib/axios', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import axiosInstance from '../lib/axios';
import { PantallaConsentimiento } from './PantallaConsentimiento';
import { RUTA_AVISO, NotaDeDatos } from './AvisoDePrivacidad';

/**
 * La pantalla obligatoria de un toque y las notas de cámara/ubicación (2026-09-05).
 *
 * Lo que se protege aquí es el par que hace defendible el consentimiento: que aceptar REGISTRE en
 * el servidor (no sólo cierre la pantalla) y que el enlace al aviso íntegro exista y abra en otra
 * pestaña — si navegara en la misma, la persona perdería el fichaje o la captura que estaba
 * haciendo, y en la propia pantalla de consentimiento se toparía otra vez con el candado.
 */
describe('PantallaConsentimiento', () => {
  beforeEach(() => vi.clearAllMocks());

  it('aceptar registra en el servidor y sólo entonces levanta la pantalla', async () => {
    (axiosInstance.post as any).mockResolvedValue({ data: { success: true } });
    const onAceptado = vi.fn();

    render(<PantallaConsentimiento nombre="María" version="2026-07-24" onAceptado={onAceptado} />);

    expect(onAceptado).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: /acepto el aviso de privacidad/i }));

    await waitFor(() => expect(onAceptado).toHaveBeenCalled());
    expect(axiosInstance.post).toHaveBeenCalledWith('/me/consentimiento');
  });

  it('si el servidor falla NO finge que se aceptó: la pantalla sigue y lo dice', async () => {
    (axiosInstance.post as any).mockRejectedValue({ response: { data: { error: 'Sin conexión' } } });
    const onAceptado = vi.fn();

    render(<PantallaConsentimiento onAceptado={onAceptado} />);
    fireEvent.click(screen.getByRole('button', { name: /acepto el aviso de privacidad/i }));

    await waitFor(() => expect(screen.getByText('Sin conexión')).toBeInTheDocument());
    expect(onAceptado).not.toHaveBeenCalled();
  });

  it('enlaza el aviso completo, en otra pestaña', () => {
    render(<PantallaConsentimiento onAceptado={vi.fn()} />);

    const enlace = screen.getByRole('link', { name: /aviso de privacidad completo/i });
    expect(enlace).toHaveAttribute('href', RUTA_AVISO);
    expect(enlace).toHaveAttribute('target', '_blank');
  });
});

describe('NotaDeDatos — el aviso corto de la cámara y la ubicación', () => {
  it('dice para qué se usa el dato y enlaza el aviso sin bloquear nada', () => {
    render(<NotaDeDatos texto="Tu ubicación se usa sólo para validar la geocerca." />);

    expect(screen.getByText(/geocerca/i)).toBeInTheDocument();
    const enlace = screen.getByRole('link', { name: /ver el aviso de privacidad/i });
    expect(enlace).toHaveAttribute('href', RUTA_AVISO);
    expect(enlace).toHaveAttribute('target', '_blank');
    // No es un modal ni un formulario: no hay nada que confirmar para seguir.
    expect(screen.queryByRole('button')).toBeNull();
  });
});
