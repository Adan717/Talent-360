import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import tokens from './src/design/tokens-talent360.json'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      workbox: {
        // Cache estratégico para modo offline
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        runtimeCaching: [
          {
            // API — Network First: la copia sirve para abrir el reloj sin señal. Reporte del jefe
            // (2026-09-23): se guardaba con la URL como única llave —un celular podía mostrarle a
            // una cuenta lo que guardó otra— y con 10 s de red lenta entregaba datos de hasta un
            // día antes. Ahora: sólo peticiones con cuenta, llave = URL + huella del token, y la
            // copia sólo se usa cuando la red FALLA. Al cerrar sesión se borra (src/lib/sesion.ts).
            urlPattern: ({ url, request }) => url.pathname.startsWith('/api/') && request.headers.has('Authorization'),
            handler: 'NetworkFirst',
            options: {
              cacheName: 'talent360-api-por-cuenta', // = API_CACHE de src/lib/sesion.ts
              expiration: { maxEntries: 100, maxAgeSeconds: 60 * 60 * 24 }, // 24h
              plugins: [
                {
                  // Se copia tal cual dentro del sw.js: no puede usar nada de fuera de la función.
                  cacheKeyWillBeUsed: async ({ request }) => {
                    const token = new TextEncoder().encode(request.headers.get('Authorization') || '');
                    const huella = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', token)))
                      .map(b => b.toString(16).padStart(2, '0')).join('');
                    const url = new URL(request.url);
                    url.searchParams.set('__cuenta', huella);
                    return url.href;
                  },
                },
              ],
            },
          },
          {
            // Assets estáticos — Cache First
            urlPattern: /\.(?:png|jpg|jpeg|svg|gif|webp|ico)$/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'talent360-images',
              expiration: { maxEntries: 60, maxAgeSeconds: 60 * 60 * 24 * 30 }, // 30 días
            },
          },
          {
            // Google Fonts
            urlPattern: /^https:\/\/fonts\.googleapis\.com\/.*/i,
            handler: 'StaleWhileRevalidate',
            options: { cacheName: 'google-fonts-stylesheets' },
          },
        ],
      },
      includeAssets: ['favicon.svg', 'pwa-192x192.png', 'pwa-512x512.png'],
      manifest: {
        name: 'Talent360 — Gestión de Capital Humano',
        short_name: 'Talent360',
        description: 'Plataforma SaaS B2B para Recursos Humanos, Reloj Checador GPS, Tareas y Nómina LFT.',
        theme_color: tokens.color.functional['brand-dark'],
        background_color: tokens.color.neutral.bg,
        display: 'standalone',
        orientation: 'portrait-primary',
        start_url: '/app',
        scope: '/',
        lang: 'es-MX',
        categories: ['business', 'productivity', 'utilities'],
        icons: [
          {
            src: 'pwa-192x192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: 'pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: 'pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
        screenshots: [
          {
            src: 'pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            form_factor: 'narrow',
            label: 'Reloj Checador Talent360',
          },
        ],
        shortcuts: [
          {
            name: 'Fichar entrada',
            short_name: 'Entrada',
            url: '/app?action=check_in',
            description: 'Fichar entrada directamente',
          },
          {
            name: 'Ver mis tareas',
            short_name: 'Tareas',
            url: '/app?tab=tareas',
            description: 'Ver tareas asignadas',
          },
        ],
      },
    }),
  ],
  server: {
    host: true,
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/broadcasting': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    sourcemap: false, // OFF en producción para tamaño menor
    rollupOptions: {
      output: {
        // Code splitting manual para mejorar carga inicial
        manualChunks(id) {
          if (id.includes('node_modules')) {
            if (id.includes('react') || id.includes('react-dom') || id.includes('react-router')) return 'react-core';
            if (id.includes('lucide-react'))  return 'ui-components';
            if (id.includes('zustand'))        return 'state';
            if (id.includes('@dnd-kit'))       return 'dnd';
            if (id.includes('pusher') || id.includes('laravel-echo')) return 'realtime';
          }
        },
      },
    },
    chunkSizeWarningLimit: 1500,
  },
})
