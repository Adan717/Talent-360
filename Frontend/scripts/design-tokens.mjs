import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import postcss from 'postcss';

export const tokenFile = resolve(dirname(fileURLToPath(import.meta.url)), '../src/design/tokens-talent360.json');

// Generate both CSS variables and Tailwind utilities from the approved JSON.
// No second palette, generated file to keep in sync, or runtime theme flash.
export function buildTokenCss(tokens) {
  const variables = new Map();
  const add = (name, value) => {
    if (!/^#[\da-f]{6}$/i.test(value)) throw new Error(`Invalid color token: ${name}`);
    variables.set(name, value);
  };
  for (const [step, value] of Object.entries(tokens.color.brand)) add(`navy-${step}`, value);
  for (const [name, value] of Object.entries(tokens.color.functional)) add(name, value);
  const neutralNames = {
    bg: 'page', surface: 'surface', border: 'border',
    'text-primary': 'text-1', 'text-secondary': 'text-2', 'text-tertiary': 'text-3',
  };
  for (const [name, value] of Object.entries(tokens.color.neutral)) add(neutralNames[name], value);
  for (const [state, parts] of Object.entries(tokens.color.semantic)) {
    for (const [part, value] of Object.entries(parts)) add(`${state}-${part}`, value);
  }
  // Only exact references in usage are resolved; prose is documentation, not executable CSS.
  for (const [name, ref] of Object.entries(tokens.usage)) {
    if (/^(brand|neutral|functional)\.[\w-]+$/.test(ref)) {
      const [group, key] = ref.split('.');
      add(`usage-${name}`, tokens.color[group][key]);
    } else if (/^#[\da-f]{6}$/i.test(ref)) add(`usage-${name}`, ref);
  }
  const root = [...variables].map(([name, value]) => `  --${name}: ${value};`).join('\n');
  const theme = [...variables.keys()].map(name => `  --color-${name}: var(--${name});`).join('\n');
  return `:root {\n${root}\n}\n@theme inline {\n${theme}\n}`;
}

export default function talentTokens() {
  return {
    postcssPlugin: 'talent360-design-tokens',
    Once(root, { result }) {
      root.walkAtRules('talent360-tokens', rule => {
        const tokens = JSON.parse(readFileSync(tokenFile, 'utf8'));
        rule.replaceWith(postcss.parse(buildTokenCss(tokens), { from: tokenFile }).nodes);
        // Editing the JSON also refreshes the running Vite preview.
        result.messages.push({ type: 'dependency', plugin: 'talent360-design-tokens', file: tokenFile });
      });
    },
  };
}
