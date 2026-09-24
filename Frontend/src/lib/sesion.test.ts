import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import axios from './axios';
import { cerrarSesion } from './sesion';

vi.mock('./axios', () => ({ default: { post: vi.fn() } }));

describe('cerrarSesion (reporte del jefe, 2026-09-23)', () => {
  const borrarCache = vi.fn();
  beforeEach(() => {
    vi.resetAllMocks();
    localStorage.clear();
    borrarCache.mockResolvedValue(true);
    vi.stubGlobal('caches', { delete: borrarCache });
  });
  afterEach(() => vi.unstubAllGlobals());

  it.each([
    ['el servidor revoca', true],
    ['no hay red', false],
  ])('revoca en el servidor y deja el dispositivo limpio cuando %s', async (_caso, responde) => {
    localStorage.setItem('talent_auth_token', 'token-de-la-cuenta');
    localStorage.setItem('platform_admin_token', 'token-de-plataforma');
    if (responde) vi.mocked(axios.post).mockResolvedValue({});
    else vi.mocked(axios.post).mockRejectedValue(new Error('Network Error'));

    await cerrarSesion();

    expect(axios.post).toHaveBeenCalledWith('/logout');
    expect(localStorage.getItem('talent_auth_token')).toBeNull();
    expect(localStorage.getItem('platform_admin_token')).toBeNull();
    expect(borrarCache).toHaveBeenCalledWith('talent360-api-por-cuenta');
  });
});
