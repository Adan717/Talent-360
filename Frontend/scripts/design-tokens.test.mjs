// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import postcss from 'postcss';
import talentTokens, { buildTokenCss, tokenFile } from './design-tokens.mjs';

const tokens = JSON.parse(readFileSync(tokenFile, 'utf8'));
function luminance(hex) {
  const rgb = hex.slice(1).match(/../g).map(v => parseInt(v, 16) / 255)
    .map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
  return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
}
function contrast(foreground, background) {
  const a = luminance(foreground), b = luminance(background);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

describe('approved Talent 360 tokens', () => {
  it.each(Object.entries(tokens.color.semantic))('%s keeps readable status text and icons', (_name, state) => {
    expect(contrast(state.text, state.bg)).toBeGreaterThanOrEqual(4.5);
    expect(contrast(state.icon, state.bg)).toBeGreaterThanOrEqual(3);
  });

  it('keeps the primary button and dark navigation readable', () => {
    expect(contrast(tokens.color.neutral.surface, tokens.color.functional.accent)).toBeGreaterThanOrEqual(4.5);
    expect(contrast(tokens.color.brand[100], tokens.color.brand[800])).toBeGreaterThanOrEqual(4.5);
    for (const key of ['text-primary', 'text-secondary', 'text-tertiary']) {
      expect(contrast(tokens.color.neutral[key], tokens.color.neutral.surface)).toBeGreaterThanOrEqual(4.5);
      expect(contrast(tokens.color.neutral[key], tokens.color.neutral.bg)).toBeGreaterThanOrEqual(4.5);
    }
  });

  it('updates generated interaction roles when the source token changes', () => {
    const candidate = structuredClone(tokens);
    candidate.color.functional.accent = '#223344';
    const css = buildTokenCss(candidate);
    expect(css).toContain('--accent: #223344');
    expect(css).toContain('--usage-button-primary: #223344');
    expect(css).toContain('--color-accent: var(--accent)');
    expect(css).toContain(`--accent-soft: ${tokens.color.functional['accent-soft']}`);
    expect(css).toContain(`--brand-dark: ${tokens.color.functional['brand-dark']}`);
  });

  it('registers the source as a watched build dependency and consumes the directive', async () => {
    const result = await postcss([talentTokens()]).process('@talent360-tokens;', { from: undefined });
    expect(result.css).not.toContain('@talent360-tokens');
    expect(result.css).toContain('--warning-icon: #B45309');
    expect(result.messages).toContainEqual(expect.objectContaining({ type: 'dependency', file: tokenFile }));
  });

  it('rejects broken usage references instead of emitting an invalid stylesheet', () => {
    const candidate = structuredClone(tokens);
    candidate.usage['button-primary'] = 'functional.missing';
    expect(() => buildTokenCss(candidate)).toThrow('Invalid color token');
  });
});
