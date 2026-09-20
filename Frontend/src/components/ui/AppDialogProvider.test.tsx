import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { confirmAction, notify, promptForText } from '../../lib/appDialogs';
import { AppDialogProvider } from './AppDialogProvider';

function Harness({ onConfirm, onPrompt }: { onConfirm: (value: boolean) => void; onPrompt: (value: string | null) => void }) {
  return (
    <>
      <button type="button" onClick={() => confirmAction('¿Eliminar el registro?', { confirmLabel: 'Eliminar', tone: 'error' }).then(onConfirm)}>Probar confirmación</button>
      <button type="button" onClick={() => promptForText('Escribe un motivo').then(onPrompt)}>Probar entrada</button>
      <button type="button" onClick={() => notify('Cambios guardados correctamente')}>Probar aviso</button>
    </>
  );
}

describe('AppDialogProvider', () => {
  it('resuelve confirmaciones y entradas sin usar cuadros nativos bloqueantes', async () => {
    const onConfirm = vi.fn();
    const onPrompt = vi.fn();
    render(<AppDialogProvider><Harness onConfirm={onConfirm} onPrompt={onPrompt} /></AppDialogProvider>);

    fireEvent.click(screen.getByRole('button', { name: 'Probar confirmación' }));
    expect(screen.getByRole('dialog')).toHaveTextContent('¿Eliminar el registro?');
    fireEvent.click(screen.getByRole('button', { name: 'Eliminar' }));
    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(true));

    fireEvent.click(screen.getByRole('button', { name: 'Probar entrada' }));
    fireEvent.change(screen.getByLabelText('Respuesta'), { target: { value: 'Duplicado' } });
    fireEvent.click(screen.getByRole('button', { name: 'Continuar' }));
    await waitFor(() => expect(onPrompt).toHaveBeenCalledWith('Duplicado'));
  });

  it('muestra notificaciones accesibles sin cuadros nativos bloqueantes', async () => {
    render(<AppDialogProvider><Harness onConfirm={vi.fn()} onPrompt={vi.fn()} /></AppDialogProvider>);
    fireEvent.click(screen.getByRole('button', { name: 'Probar aviso' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Cambios guardados correctamente');
  });
});
