import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

(window as any).Pusher = Pusher;

let backendUrl = import.meta.env.VITE_API_URL || '';

if (!backendUrl) {
  backendUrl = `${window.location.origin}/api`;
}
const authEndpoint = backendUrl.replace('/api/v1', '').replace(/\/$/, '') + '/broadcasting/auth';

export const echoInstance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'reverb-key',
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT) : 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT) : 8080,
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
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
