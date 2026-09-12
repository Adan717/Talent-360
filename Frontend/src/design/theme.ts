import tokens from './tokens-talent360.json';

export { tokens };

export const moduleTheme = {
  sidebar: 'bg-accent-soft text-navy-800 border-navy-100',
  hex: tokens.color.functional.accent,
  text: 'text-accent',
};

// Keep persisted legacy keys readable without reintroducing independent brand palettes.
export const ColorMap: Record<string, typeof moduleTheme> = Object.fromEntries(
  ['navy', 'violet', 'blue', 'emerald', 'indigo', 'amber', 'rose', 'sky', 'slate']
    .map(key => [key, moduleTheme]),
);
