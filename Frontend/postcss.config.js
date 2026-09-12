import tailwindcss from '@tailwindcss/postcss';
import autoprefixer from 'autoprefixer';
import talentTokens from './scripts/design-tokens.mjs';

export default {
  plugins: [talentTokens(), tailwindcss(), autoprefixer()],
};
