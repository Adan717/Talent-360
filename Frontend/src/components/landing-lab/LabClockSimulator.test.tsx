import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LabClockSimulator } from './LabClockSimulator';

// The dial's appearance is verified in the browser; isolate the original clock state flow here.
vi.mock('../reloj/DialPrincipal', () => ({ default: ({ btnProps, handleAction }: any) => <button disabled={btnProps.disabled} onClick={handleAction}>{btnProps.text}</button> }));
// Fail if the recovered demo accidentally starts importing the application's shared stores.
vi.mock('../../store/useAppStore', () => { throw new Error('The local clock must not import the live app store'); });
vi.mock('../../store/useTaskStore', () => { throw new Error('The local clock must not import the live task store'); });

afterEach(() => { cleanup(); vi.useRealTimers(); });

describe('Isolated original clock simulator', () => {
  it('preserves the Pro entry, break and return flow', () => {
    vi.useFakeTimers();
    render(<LabClockSimulator tier="pro" />);
    fireEvent.click(screen.getByRole('button', { name: 'Registrar Entrada' }));
    act(() => vi.advanceTimersByTime(3200));
    fireEvent.click(screen.getByRole('button', { name: 'Descanso Ley Silla' }));
    act(() => vi.advanceTimersByTime(1500));
    fireEvent.click(screen.getByRole('button', { name: 'Regresar de Descanso' }));
    expect(screen.getByRole('button', { name: 'Iniciar Horario de Comida' })).toBeEnabled();
  });

  it('preserves Basic entry and exit, without the shared stores', () => {
    vi.useFakeTimers();
    render(<LabClockSimulator tier="free" />);
    fireEvent.click(screen.getByRole('button', { name: 'Registrar Entrada' }));
    act(() => vi.advanceTimersByTime(1200));
    fireEvent.click(screen.getByRole('button', { name: 'Registrar Salida' }));
    act(() => vi.advanceTimersByTime(1200));
    expect(screen.getByRole('button', { name: 'Jornada Finalizada' })).toBeDisabled();
  });

  it('cancels outstanding verification timers when reset or unmounted', () => {
    vi.useFakeTimers();
    const { unmount } = render(<LabClockSimulator tier="pro" />);
    fireEvent.click(screen.getByRole('button', { name: 'Registrar Entrada' }));
    expect(vi.getTimerCount()).toBeGreaterThan(0);
    unmount();
    expect(vi.getTimerCount()).toBe(0);
  });
});
