import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import axios from './axios';
import { offlineDb, subirFichajesPendientes } from './offlineDb';
import { confirmAction } from './appDialogs';
import { cerrarSesion } from './sesion';
import { useAppStore } from '../store/useAppStore';

vi.mock('./axios', () => ({ default: { post: vi.fn() } }));
vi.mock('./offlineDb', () => ({ offlineDb: { getPunches: vi.fn() }, subirFichajesPendientes: vi.fn() }));
vi.mock('./appDialogs', () => ({ confirmAction: vi.fn() }));

describe('cerrarSesion (reporte del jefe, 2026-09-23)', () => {
  const borrarCache = vi.fn();
  beforeEach(() => {
    vi.resetAllMocks();
    localStorage.clear();
    borrarCache.mockResolvedValue(true);
    vi.stubGlobal('caches', { delete: borrarCache });
    vi.mocked(offlineDb.getPunches).mockResolvedValue([]);
    vi.mocked(subirFichajesPendientes).mockResolvedValue({ subidos: 0, rechazados: 0 });
    useAppStore.setState({ currentUser: { ...useAppStore.getState().currentUser, id: 7, role: 'empleado' } });
    localStorage.setItem('talent_auth_token', 'token-de-la-cuenta');
    localStorage.setItem('platform_admin_token', 'token-de-plataforma');
  });
  afterEach(() => vi.unstubAllGlobals());

  it.each([
    ['el servidor revoca', true],
    ['no hay red', false],
  ])('revoca en el servidor y deja el dispositivo limpio cuando %s', async (_caso, responde) => {
    if (responde) vi.mocked(axios.post).mockResolvedValue({});
    else vi.mocked(axios.post).mockRejectedValue(new Error('Network Error'));

    await cerrarSesion();

    expect(axios.post).toHaveBeenCalledWith('/logout');
    expect(localStorage.getItem('talent_auth_token')).toBeNull();
    expect(localStorage.getItem('platform_admin_token')).toBeNull();
    expect(borrarCache).toHaveBeenCalledWith('talent360-api-por-cuenta');
    expect(confirmAction).not.toHaveBeenCalled();
  });

  it('intenta subir lo pendiente y, si algo propio no subió, pregunta antes de salir', async () => {
    // Uno es de la persona que cierra (7) y otro de alguien que usó antes el celular (9).
    vi.mocked(offlineDb.getPunches).mockResolvedValue([{ userId: 7 }, { userId: 9 }] as any);
    vi.mocked(confirmAction).mockResolvedValue(false);

    await cerrarSesion();

    expect(subirFichajesPendientes).toHaveBeenCalled();
    expect(vi.mocked(confirmAction).mock.calls[0][0]).toContain('Tienes 1 fichaje(s)');
    // Eligió esperar: la sesión sigue intacta.
    expect(axios.post).not.toHaveBeenCalled();
    expect(localStorage.getItem('talent_auth_token')).toBe('token-de-la-cuenta');

    vi.mocked(confirmAction).mockResolvedValue(true);
    vi.mocked(axios.post).mockResolvedValue({});
    await cerrarSesion();
    expect(axios.post).toHaveBeenCalledWith('/logout');
    expect(localStorage.getItem('talent_auth_token')).toBeNull();
  });
});
