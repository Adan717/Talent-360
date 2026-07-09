import axios from 'axios';

let API_URL = import.meta.env.VITE_API_URL || '';

if (!API_URL) {
  // Con el proxy de Vite configurado en desarrollo local, las peticiones van al mismo origen/puerto del front (5173) 
  // y Vite las redirige al backend (8000), esquivando cortafuegos y problemas de CORS en celulares.
  API_URL = `${window.location.origin}/api`;
}

if (!API_URL.endsWith('/v1')) {
    API_URL = API_URL.replace(/\/$/, '') + '/v1';
}

const axiosInstance = axios.create({
    baseURL: API_URL,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    // Esto es crucial para Sanctum (Cookies y CSRF) si el front y back están en el mismo dominio
    withCredentials: true 
});

import { getDeviceFingerprint } from './deviceFingerprint';

// Interceptor para inyectar el Token en cada petición
axiosInstance.interceptors.request.use(
    (config) => {
        const token = localStorage.getItem('talent_auth_token');
        if (token && !config.headers.Authorization) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        config.headers['X-Device-Fingerprint'] = getDeviceFingerprint();
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// Interceptor para atrapar respuestas 401 (Token Expirado/Inválido) y 403 (Baneos)
axiosInstance.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response) {
            if (error.response.status === 401) {
                // Si la petición original ERA para hacer login o es parte del baúl público, NO redireccionamos
                if (error.config && (
                    error.config.url.includes('/public/org-vault') || 
                    error.config.url === '/login' || 
                    error.config.url === 'login' || 
                    error.config.url.endsWith('/login')
                )) {
                    return Promise.reject(error);
                }
                
                // El token ya no es válido, cerramos sesión
                localStorage.removeItem('talent_auth_token');
                
                // Redirigir manteniendo los parámetros útiles si es posible
                const isMobile = window.location.pathname.startsWith('/empleado') || new URLSearchParams(window.location.search).get('mobile') === 'true';
                window.location.href = isMobile ? '/login?mobile=true' : '/login';
            } else if (error.response.status === 403 && error.response.data && error.response.data.error === 'Device Banned') {
                // Dispatch window event for device ban screen
                window.dispatchEvent(new CustomEvent('device-banned', { detail: error.response.data.message }));
            }
        }
        return Promise.reject(error);
    }
);

export default axiosInstance;
