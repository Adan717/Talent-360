import { describe, it, expect } from 'vitest';
import { detectCategory } from './PanelTareasRutinas';

describe('detectCategory()', () => {
  it('detecta "mantenimiento" por palabras clave', () => {
    expect(detectCategory('Revisar la selladora #2')).toBe('mantenimiento');
    expect(detectCategory('Limpieza profunda de sanitarios')).toBe('mantenimiento');
  });

  it('detecta "administrativo" por palabras clave', () => {
    expect(detectCategory('Corte de caja del turno matutino')).toBe('administrativo');
    expect(detectCategory('Pago de servicios de telefonía')).toBe('administrativo');
  });

  it('cae por defecto en "operativo" si no hay coincidencias', () => {
    expect(detectCategory('Recibir mercancía de proveedor')).toBe('operativo');
  });

  it('no distingue mayúsculas/minúsculas', () => {
    expect(detectCategory('MANTENIMIENTO de la maquinaria')).toBe('mantenimiento');
  });
});
