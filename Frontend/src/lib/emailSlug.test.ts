import { describe, it, expect } from 'vitest';
import { slugParaCorreo } from './emailSlug';

describe('slugParaCorreo (H3)', () => {
  it('quita los acentos del caso que disparó el hallazgo', () => {
    expect(slugParaCorreo('Adán Cuéllar')).toBe('adancuellar');
  });

  it('normaliza la eñe y las mayúsculas acentuadas', () => {
    expect(slugParaCorreo('Íñigo Muñoz')).toBe('inigomunoz');
  });

  it('cubre las vocales acentuadas y la diéresis', () => {
    expect(slugParaCorreo('áéíóú ÁÉÍÓÚ üÜ')).toBe('aeiouaeiouuu');
  });

  it('conserva los caracteres que un correo sí acepta', () => {
    expect(slugParaCorreo('Ana Ma. Sáez')).toBe('anama.saez');
    expect(slugParaCorreo('jose+turno1')).toBe('jose+turno1');
  });

  it('descarta lo que rompería un correo', () => {
    expect(slugParaCorreo('María (Ventas) #1')).toBe('mariaventas1');
  });

  it('aguanta vacío o basura sin romper', () => {
    expect(slugParaCorreo('')).toBe('');
    // @ts-expect-error probamos la robustez ante undefined en runtime
    expect(slugParaCorreo(undefined)).toBe('');
  });
});
