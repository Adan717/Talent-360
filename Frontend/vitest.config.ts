import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// Config separada de vite.config.ts a propósito: vite.config.ts usa defineConfig
// de 'vite' (sin el campo `test`) y ya trae el plugin de PWA, que no aplica ni
// hace falta para correr pruebas unitarias. `npm test` / `npm run test:watch`
// usan este archivo automáticamente (Vitest lo detecta antes que vite.config.ts).
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
