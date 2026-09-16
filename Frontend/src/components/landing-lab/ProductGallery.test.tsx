import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ProductGallery } from './ProductGallery';

afterEach(cleanup);

function viewport() {
  return screen.getByLabelText('Pantallas del producto. Usa las flechas del teclado o desliza para cambiar.');
}

function pointer(type: string, target: HTMLElement, x: number, y: number) {
  const event = new MouseEvent(type, { bubbles: true, clientX: x, clientY: y, button: 0 });
  Object.defineProperties(event, { pointerId: { value: 1 }, isPrimary: { value: true } });
  fireEvent(target, event);
}

describe('Hero product gallery', () => {
  it('starts on Monitor, shows only one accessible slide and keeps the preview inert', () => {
    const { container } = render(<ProductGallery />);
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 1 de 4: Monitor 360');
    expect(screen.getAllByRole('img')).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Ver Monitor 360' })).toHaveAttribute('aria-current', 'true');
    expect(container.querySelector('.lab-gallery-image > div')).toHaveAttribute('inert');
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Registrar entrada de ejemplo' })).not.toBeInTheDocument();
  });

  it('moves forward and backward, wrapping at both ends', () => {
    render(<ProductGallery />);
    fireEvent.click(screen.getByRole('button', { name: 'Pantalla anterior' }));
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 4 de 4: Tareas y rutinas');
    fireEvent.click(screen.getByRole('button', { name: 'Pantalla siguiente' }));
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 1 de 4: Monitor 360');
    fireEvent.click(screen.getByRole('button', { name: 'Pantalla siguiente' }));
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 2 de 4: Asistencia');
  });

  it('selects a screen directly and supports keyboard navigation without moving focus', () => {
    render(<ProductGallery />);
    const directory = screen.getByRole('button', { name: 'Ver Directorio' });
    fireEvent.click(directory);
    directory.focus();
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 3 de 4: Directorio');
    fireEvent.keyDown(directory, { key: 'ArrowRight' });
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 4 de 4');
    expect(directory).toHaveFocus();
    fireEvent.keyDown(viewport(), { key: 'Home' });
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 1 de 4');
    fireEvent.keyDown(viewport(), { key: 'End' });
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 4 de 4');
    fireEvent.keyDown(viewport(), { key: 'ArrowLeft' });
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 3 de 4');
  });

  it('swipes horizontally but ignores taps, vertical scrolling and cancelled gestures', () => {
    render(<ProductGallery />);
    const target = viewport();
    Object.assign(target, { setPointerCapture: vi.fn(), releasePointerCapture: vi.fn(), hasPointerCapture: () => true });
    pointer('pointerdown', target, 240, 100);
    pointer('pointerup', target, 100, 110);
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 2 de 4');
    pointer('pointerdown', target, 100, 100);
    pointer('pointerup', target, 200, 110);
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 1 de 4');
    pointer('pointerdown', target, 240, 100);
    pointer('pointerup', target, 230, 110);
    pointer('pointerdown', target, 240, 100);
    pointer('pointerup', target, 180, 240);
    pointer('pointerdown', target, 240, 100);
    pointer('pointercancel', target, 240, 100);
    pointer('pointerup', target, 100, 100);
    expect(screen.getByRole('status')).toHaveTextContent('Pantalla 1 de 4');
  });
});
