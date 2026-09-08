import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

vi.mock('../lib/axios', () => ({
  default: { get: vi.fn(), put: vi.fn() },
}));

import axiosInstance from '../lib/axios';
import MatrizDePermisos, { normalizarMatriz } from './MatrizDePermisos';

/**
 * La pantalla de la matriz de permisos (Plan A4, 2026-09-07).
 *
 * Lo que se protege: que la pantalla lea la forma EXACTA que devuelve `PermissionMatrixController`
 * (`matrix` llega como `[]` vacío o como objeto por id), que un cambio del admin viaje al servidor
 * con el contrato del PUT (`{ matrix: { "<id>": [...] } }`) reemplazando la fila completa del
 * puesto, y que un 403 se diga en vez de pintar una matriz vacía "sin colaboradores".
 */
const respuestaServidor = {
  success: true,
  capabilities: [
    { name: 'manage_tasks', description: 'Tareas, rutinas, asignaciones, plan del día', delegable: true },
    { name: 'view_reports', description: 'Reportes y analítica operativa', delegable: true },
  ],
  indelegable: [
    { name: 'manage_permissions', description: 'Otorgar y revocar permisos a los puestos', delegable: false },
  ],
  supervisor_defaults: ['manage_tasks', 'view_reports'],
  job_roles: [
    { id: 7, name: 'Cajero' },
    { id: 9, name: 'Encargado' },
  ],
  matrix: { '9': ['view_reports'] },
};

describe('normalizarMatriz', () => {
  it('acepta el [] que manda Laravel cuando no hay filas y deja cada puesto en vacío', () => {
    expect(normalizarMatriz([], [{ id: 7, name: 'Cajero' }])).toEqual({ 7: [] });
  });

  it('acepta el objeto por id y conserva los puestos sin filas', () => {
    expect(normalizarMatriz({ '9': ['view_reports'] }, [{ id: 7, name: 'Cajero' }, { id: 9, name: 'Encargado' }]))
      .toEqual({ 7: [], 9: ['view_reports'] });
  });
});

describe('MatrizDePermisos', () => {
  beforeEach(() => vi.clearAllMocks());

  it('pinta lo que el servidor dice que tiene cada puesto y bloquea lo indelegable', async () => {
    (axiosInstance.get as any).mockResolvedValue({ data: respuestaServidor });

    render(<MatrizDePermisos />);

    const encargadoReportes = await screen.findByLabelText('view_reports para Encargado');
    expect(encargadoReportes).toBeChecked();
    expect(screen.getByLabelText('manage_tasks para Encargado')).not.toBeChecked();
    expect(screen.getByLabelText('view_reports para Cajero')).not.toBeChecked();

    const indelegable = screen.getByLabelText('manage_permissions para Cajero (indelegable)');
    expect(indelegable).toBeDisabled();
    expect(indelegable).not.toBeChecked();

    // Sin cambios no hay nada que guardar.
    expect(screen.getByRole('button', { name: /guardar cambios/i })).toBeDisabled();
  });

  it('marcar una capacidad y guardar manda la matriz completa con el contrato del PUT', async () => {
    (axiosInstance.get as any).mockResolvedValue({ data: respuestaServidor });
    (axiosInstance.put as any).mockResolvedValue({ data: { success: true, message: 'Matriz de permisos actualizada.', notes: [] } });

    render(<MatrizDePermisos />);

    fireEvent.click(await screen.findByLabelText('manage_tasks para Encargado'));
    const guardar = screen.getByRole('button', { name: /guardar cambios/i });
    expect(guardar).toBeEnabled();
    fireEvent.click(guardar);

    await waitFor(() => expect(axiosInstance.put).toHaveBeenCalledTimes(1));
    expect(axiosInstance.put).toHaveBeenCalledWith('/admin/permissions/matrix', {
      matrix: { '7': [], '9': ['view_reports', 'manage_tasks'] },
    });
    // Tras guardar se vuelve a leer del servidor: lo que vale es lo que quedó guardado.
    await waitFor(() => expect(axiosInstance.get).toHaveBeenCalledTimes(2));
    expect(await screen.findByRole('status')).toHaveTextContent('Matriz de permisos actualizada.');
  });

  it('"base de supervisor" deja al puesto exactamente con los defaults del servidor', async () => {
    (axiosInstance.get as any).mockResolvedValue({ data: respuestaServidor });
    (axiosInstance.put as any).mockResolvedValue({ data: { success: true } });

    render(<MatrizDePermisos />);
    await screen.findByLabelText('view_reports para Encargado');

    fireEvent.click(screen.getAllByRole('button', { name: /base de supervisor/i })[0]); // Cajero
    fireEvent.click(screen.getByRole('button', { name: /guardar cambios/i }));

    await waitFor(() => expect(axiosInstance.put).toHaveBeenCalledWith('/admin/permissions/matrix', {
      matrix: { '7': ['manage_tasks', 'view_reports'], '9': ['view_reports'] },
    }));
  });

  it('un 403 se dice con todas sus letras en vez de pintar una matriz vacía', async () => {
    (axiosInstance.get as any).mockRejectedValue({ response: { status: 403 } });

    render(<MatrizDePermisos />);

    expect(await screen.findByRole('alert')).toHaveTextContent(/sólo el administrador dueño/i);
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });

  it('si el servidor ignoró algo al guardar, lo muestra (notes) y no lo esconde', async () => {
    (axiosInstance.get as any).mockResolvedValue({ data: respuestaServidor });
    (axiosInstance.put as any).mockResolvedValue({ data: { success: true, message: 'Matriz de permisos actualizada.', notes: ['puesto 9: ignoradas manage_billing'] } });

    render(<MatrizDePermisos />);
    fireEvent.click(await screen.findByLabelText('manage_tasks para Cajero'));
    fireEvent.click(screen.getByRole('button', { name: /guardar cambios/i }));

    expect(await screen.findByText('puesto 9: ignoradas manage_billing')).toBeInTheDocument();
  });
});
