import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const fuentes = [
  'SaaSLandingPage.tsx',
  'SaaSAccountSettings.tsx',
  'AtsPortalSettings.tsx',
  'SaaSPlatformAdmin.tsx',
  'SupportChatCopilot.tsx',
].map((archivo) => readFileSync(resolve(__dirname, archivo), 'utf8'));

describe('dominio público real', () => {
  it('no presenta subdominios inexistentes y usa talent360.com.mx', () => {
    for (const fuente of fuentes) {
      expect(fuente).not.toMatch(/\.talent360\.com(?!\.mx)/);
    }
    expect(fuentes.join('\n')).toContain('https://talent360.com.mx/login');
    expect(fuentes.join('\n')).toContain('talent360.com.mx/vacantes/');
  });
});
