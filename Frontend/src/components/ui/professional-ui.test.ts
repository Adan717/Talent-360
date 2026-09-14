import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8');
const emoji = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u;

describe('professional application shell', () => {
  it.each([
    'src/components/MonitorActividadesTiempoReal.tsx',
    'src/components/tareas_rutinas/PanelTareasRutinas.tsx',
    'src/components/GestorAcademia.tsx',
    'src/components/GestorDocumentos.tsx',
    'src/components/ReportesManager.tsx',
    'src/components/FacturacionManager.tsx',
  ])('does not use emoji glyphs in %s', (path) => {
    expect(source(path)).not.toMatch(emoji);
  });

  it('keeps the collapsed sidebar free of native horizontal scrollbars', () => {
    const app = source('src/App.tsx');
    expect(app).toContain("w-[72px]");
    expect(app).toContain('overflow-y-auto overflow-x-hidden');
    expect(app).toContain('scrollbar-none');
  });

  it('uses light semantic sidebar tokens', () => {
    const tokens = JSON.parse(source('src/design/tokens-talent360.json'));
    expect(tokens.usage['sidebar-bg']).toBe('neutral.surface');
    expect(tokens.usage['sidebar-active-bg']).toBe('brand.50');
  });

  it('uses vector icons instead of emoji in the desktop clock navigation', () => {
    const clock = source('src/components/reloj/RelojVisual.tsx');
    const navigation = clock.slice(
      clock.indexOf('/* RENDER DESKTOP NAVIGATION VIEW */'),
      clock.indexOf('/* --- CONTENT LAYOUTS --- */'),
    );

    expect(navigation).not.toMatch(emoji);
    expect(navigation).toContain('<Clock size={15}');
    expect(navigation).toContain('<GraduationCap size={15}');
    expect(navigation).toContain('<User size={15}');
  });
});
