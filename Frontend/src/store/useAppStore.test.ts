import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock de axiosInstance: fetchState() solo necesita .get controlado; los interceptores
// reales (auth, device fingerprint, 401/403) no aplican en este test unitario.
vi.mock('../lib/axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

import axiosInstance from '../lib/axios';
import { useAppStore } from './useAppStore';

const todayStr = new Date().toLocaleDateString('sv-SE');

describe('useAppStore.fetchState() — hidratación de globalClockStates', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();

    // Reset de los campos relevantes del store entre pruebas (zustand es un singleton
    // de módulo, así que el estado persiste entre tests si no se reinicia a mano).
    useAppStore.setState({
      globalClockStates: {},
      globalCheckOutTimes: {},
      globalPendingBreakRequests: {},
      // fetchState() solo procesa time_entries cuando isSandboxMode es false
      // (ver bloque `if (!state.isSandboxMode)` en useAppStore.ts).
      isSandboxMode: false,
      currentUser: null,
    });
  });

  it('mapea check_out a "finished" (regresión del bug que lo dejaba en "inactive")', async () => {
    (axiosInstance.get as any).mockImplementation((url: string) => {
      if (url === '/sync/state') {
        return Promise.resolve({
          status: 200,
          data: {
            time_entries: [
              { id: 1, user_id: 2, type: 'check_in', time: '09:00', date: todayStr },
              { id: 2, user_id: 2, type: 'check_out', time: '17:00', date: todayStr },
            ],
          },
        });
      }
      return Promise.resolve({ status: 200, data: {} });
    });

    await useAppStore.getState().fetchState();

    expect(useAppStore.getState().globalClockStates[2]).toBe('finished');
  });

  it('mapea temp_exit_start a "temp_exit" y absent a "absent" (antes se perdían al refrescar)', async () => {
    (axiosInstance.get as any).mockImplementation((url: string) => {
      if (url === '/sync/state') {
        return Promise.resolve({
          status: 200,
          data: {
            time_entries: [
              { id: 1, user_id: 1, type: 'check_in', time: '09:00', date: todayStr },
              { id: 2, user_id: 1, type: 'temp_exit_start', time: '11:00', date: todayStr },
              { id: 3, user_id: 3, type: 'absent', time: '09:00', date: todayStr },
            ],
          },
        });
      }
      return Promise.resolve({ status: 200, data: {} });
    });

    await useAppStore.getState().fetchState();

    const states = useAppStore.getState().globalClockStates;
    expect(states[1]).toBe('temp_exit');
    expect(states[3]).toBe('absent');
  });

  it('temp_exit_end regresa el estado a "active"', async () => {
    (axiosInstance.get as any).mockImplementation((url: string) => {
      if (url === '/sync/state') {
        return Promise.resolve({
          status: 200,
          data: {
            time_entries: [
              { id: 1, user_id: 5, type: 'check_in', time: '09:00', date: todayStr },
              { id: 2, user_id: 5, type: 'temp_exit_start', time: '11:00', date: todayStr },
              { id: 3, user_id: 5, type: 'temp_exit_end', time: '11:15', date: todayStr },
            ],
          },
        });
      }
      return Promise.resolve({ status: 200, data: {} });
    });

    await useAppStore.getState().fetchState();

    expect(useAppStore.getState().globalClockStates[5]).toBe('active');
  });

  it('ignora entradas de time_entries de otro día', async () => {
    (axiosInstance.get as any).mockImplementation((url: string) => {
      if (url === '/sync/state') {
        return Promise.resolve({
          status: 200,
          data: {
            time_entries: [
              { id: 1, user_id: 9, type: 'check_out', time: '17:00', date: '2000-01-01' },
            ],
          },
        });
      }
      return Promise.resolve({ status: 200, data: {} });
    });

    await useAppStore.getState().fetchState();

    expect(useAppStore.getState().globalClockStates[9]).toBeUndefined();
  });
});
