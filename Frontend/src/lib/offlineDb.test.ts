import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from './axios';
import { offlineDb, subirFichajesPendientes, type OfflinePunch } from './offlineDb';

vi.mock('./axios', () => ({ default: { post: vi.fn() } }));

const ponche = (id: number): OfflinePunch => ({
  id, userId: 7, type: 'check_in', time: '08:00:00', clientTimestamp: '2026-09-23T14:00:00Z',
  offlineStamp: 'firma', gps: null, details: '', timestamp: 0,
});

describe('subirFichajesPendientes', () => {
  beforeEach(() => vi.resetAllMocks());

  it('saca de la cola lo subido y lo rechazado, y conserva lo ajeno y lo que no tuvo respuesta', async () => {
    vi.spyOn(offlineDb, 'getPunches').mockResolvedValue([1, 2, 3, 4].map(ponche));
    const borrar = vi.spyOn(offlineDb, 'deletePunch').mockResolvedValue();
    vi.mocked(axios.post).mockResolvedValue({ data: { results: [
      { index: 0, success: true },
      { index: 1, success: false, status: 'rejected' },
      // Ponche de otra cuenta (reporte del jefe, 2026-09-23): antes se trataba como rechazo y se
      // borraba; ahora espera a que su dueño entre en este dispositivo.
      { index: 2, success: false, status: 'ajeno' },
      // El índice 3 no trae resultado (falló a medias): se reintenta después.
    ] } });

    expect(await subirFichajesPendientes()).toEqual({ subidos: 1, rechazados: 1 });
    expect(borrar.mock.calls.map(([id]) => id)).toEqual([1, 2]);
  });
});
