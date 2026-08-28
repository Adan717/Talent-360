import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

(window as any).Pusher = Pusher;

let backendUrl = import.meta.env.VITE_API_URL || '';

if (!backendUrl) {
  backendUrl = `${window.location.origin}/api`;
}
const authEndpoint = backendUrl.replace('/api/v1', '').replace(/\/$/, '') + '/broadcasting/auth';

// (2026-08-28) La conexión en vivo se DERIVA del origen, igual que la API arriba.
//
// El puerto de Reverb se hornea en el build (VITE_REVERB_PORT), y eso servía mientras todo iba
// por HTTP. Con certificado, una página https:// no puede abrir un ws:// a un puerto suelto: el
// navegador lo bloquea como contenido mixto y el tiempo real muere EN SILENCIO (sin error de
// pantalla, sólo deja de actualizarse). Como la misma imagen sirve a la entrada por HTTPS y a la
// directa por HTTP, no vale elegir uno en el build: se decide en el navegador.
//   · página https → wss por el 443, que el proxy (Caddy) enruta a Reverb — mismo origen, sin
//     contenido mixto y sin abrir un puerto más al mundo;
//   · página http  → ws al puerto horneado, como siempre.
const paginaSegura = window.location.protocol === 'https:';
const puertoHorneado = import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT) : 8080;
const puertoTiempoReal = paginaSegura ? 443 : puertoHorneado;

export const echoInstance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'reverb-key',
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: puertoTiempoReal,
    wssPort: puertoTiempoReal,
    forceTLS: paginaSegura || import.meta.env.VITE_REVERB_SCHEME === 'https',
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: authEndpoint,
    auth: {
        headers: {
            Accept: 'application/json',
            Authorization: '',
        }
    }
});
