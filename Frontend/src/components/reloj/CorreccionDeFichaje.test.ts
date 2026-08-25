import { describe, it, expect, vi } from 'vitest';

// El módulo importa axios para el modal de historia; aquí sólo se prueban las dos reglas puras.
vi.mock('../../lib/axios', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import { puedeCorregirFichajes, esFichajeCorregido, CAPACIDAD_CORREGIR } from './CorreccionDeFichaje';

/**
 * Las dos reglas que la pantalla usa para decidir qué mostrar (Capa 3, 2026-08-25).
 *
 * `puedeCorregirFichajes` tiene que dar EXACTAMENTE lo mismo que el servidor
 * (`PermissionMiddleware::usuarioTiene`). Si discrepan, la pantalla ofrece un botón que el servidor
 * rechaza con 403 — o esconde uno que sí se podía usar, que es peor porque nadie lo reporta.
 */
describe('puedeCorregirFichajes — misma regla que el servidor', () => {
  it('el admin dueño pasa siempre: no puede quedarse fuera de su propia empresa', () => {
    expect(puedeCorregirFichajes({ role: 'admin' }, [])).toBe(true);
  });

  it('un supervisor SIN la capacidad no puede: no se hereda por ser de mando', () => {
    expect(puedeCorregirFichajes({ role: 'supervisor' }, ['manage_tasks', 'view_reports'])).toBe(false);
  });

  it('un supervisor CON la capacidad concedida a su puesto sí puede', () => {
    expect(puedeCorregirFichajes({ role: 'supervisor' }, [CAPACIDAD_CORREGIR])).toBe(true);
  });

  it('un colaborador nunca puede corregir su propia asistencia', () => {
    expect(puedeCorregirFichajes({ role: 'empleado' }, [])).toBe(false);
    expect(puedeCorregirFichajes({ role: 'empleado' }, ['view_reports'])).toBe(false);
  });

  it('sin sesión no se ofrece nada', () => {
    expect(puedeCorregirFichajes(null, [CAPACIDAD_CORREGIR])).toBe(false);
    expect(puedeCorregirFichajes(undefined, [])).toBe(false);
  });

  it('una lista de permisos ausente o rota no abre la puerta', () => {
    expect(puedeCorregirFichajes({ role: 'supervisor' }, undefined as any)).toBe(false);
    expect(puedeCorregirFichajes({ role: 'supervisor' }, null as any)).toBe(false);
  });
});

describe('esFichajeCorregido — de ahí sale la etiqueta que ve el colaborador', () => {
  it('un fichaje nacido de una corrección se marca', () => {
    expect(esFichajeCorregido({ id: 5, creado_por_correccion_id: 3 })).toBe(true);
  });

  it('también si el campo llega en camelCase desde el store', () => {
    expect(esFichajeCorregido({ id: 5, creadoPorCorreccionId: 3 })).toBe(true);
  });

  it('un fichaje original del reloj no lleva etiqueta', () => {
    expect(esFichajeCorregido({ id: 5, creado_por_correccion_id: null })).toBe(false);
    expect(esFichajeCorregido({ id: 5 })).toBe(false);
  });

  it('no revienta con datos incompletos', () => {
    expect(esFichajeCorregido(null)).toBe(false);
    expect(esFichajeCorregido(undefined)).toBe(false);
  });
});
