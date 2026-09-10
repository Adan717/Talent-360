import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { PasswordRecovery } from './PasswordRecovery';
import { SocialSignIn } from './SocialSignIn';
import axios from '../lib/axios';

vi.mock('../lib/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));
vi.mock('../lib/clockCache', () => ({ clearClockLocalCache: vi.fn() }));
beforeEach(() => { vi.resetAllMocks(); localStorage.clear(); });

describe('Recuperación y acceso sin simulaciones', () => {
  it('solicita el enlace y no revela si existe la cuenta', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'Si existe la cuenta, recibirás un enlace.' } });
    render(<MemoryRouter><PasswordRecovery /></MemoryRouter>);
    fireEvent.change(screen.getByLabelText('Correo electrónico'), { target: { value: 'QA@EXAMPLE.TEST' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviar enlace' }));
    await screen.findByRole('status');
    expect(axios.post).toHaveBeenCalledWith('/forgot-password', { email: 'qa@example.test' });
    expect(localStorage.getItem('talent_auth_token')).toBeNull();
  });

  it('valida confirmación y restablece con el token del enlace, sin iniciar sesión', async () => {
    localStorage.setItem('talent_auth_token', 'old');
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'Contraseña actualizada.' } });
    render(<MemoryRouter initialEntries={['/reset-password?token=one-use&email=qa@example.test']}><PasswordRecovery reset /></MemoryRouter>);
    fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: 'Nueva!2026' } });
    fireEvent.change(screen.getByLabelText('Confirmar contraseña'), { target: { value: 'Distinta!2026' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar contraseña' }));
    expect(screen.getByRole('alert')).toHaveTextContent('no coinciden');
    expect(axios.post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Confirmar contraseña'), { target: { value: 'Nueva!2026' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar contraseña' }));
    await screen.findByRole('status');
    expect(axios.post).toHaveBeenCalledWith('/reset-password', { email: 'qa@example.test', token: 'one-use', password: 'Nueva!2026', password_confirmation: 'Nueva!2026' });
    expect(localStorage.getItem('talent_auth_token')).toBeNull();
  });

  it('muestra un enlace inválido sin ocultarlo mediante una redirección', async () => {
    vi.mocked(axios.post).mockRejectedValue({ response: { data: { message: 'Enlace vencido.' } } });
    render(<MemoryRouter initialEntries={['/reset-password?token=expired&email=qa@example.test']}><PasswordRecovery reset /></MemoryRouter>);
    for (const label of ['Nueva contraseña', 'Confirmar contraseña']) fireEvent.change(screen.getByLabelText(label), { target: { value: 'Nueva!2026' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar contraseña' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Enlace vencido');
  });

  it('no inventa botones sociales ni sesiones sin configuración del servidor', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { google_client_id: null, apple_client_id: null } });
    const onSuccess = vi.fn();
    const { container } = render(<SocialSignIn onSuccess={onSuccess} onError={vi.fn()} />);
    await waitFor(() => expect(axios.get).toHaveBeenCalledWith('/auth/social/config'));
    expect(container.querySelector('button')).toBeNull();
    expect(axios.post).not.toHaveBeenCalled();
    expect(onSuccess).not.toHaveBeenCalled();
  });
});
