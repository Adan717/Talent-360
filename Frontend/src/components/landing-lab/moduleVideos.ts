/** Paste each public YouTube link here. Empty links display an honest coming-soon state. */
export const moduleVideos = [
  {
    id: 'onboarding', category: 'Configuración rápida', title: 'Onboarding y Cuentas',
    description: 'Configura tu sucursal, áreas de trabajo y puestos en pocos pasos a través de nuestro asistente inteligente.',
    youtubeUrl: '', // Example shape: https://www.youtube.com/watch?v=VIDEO_ID
  },
  {
    id: 'asistencia', category: 'Asistencia', title: 'Reloj Checador Biométrico',
    description: 'Control de horarios con acceso personal o kiosco con PIN, validación de ubicación y registro en tiempo real.',
    youtubeUrl: '',
  },
  {
    id: 'reclutamiento', category: 'Reclutamiento', title: 'Portal de Empleos Integrado',
    description: 'Publica vacantes de forma pública, gestiona candidatos y califica postulantes de forma inteligente.',
    youtubeUrl: '',
  },
] as const;

export type VideoModuleId = typeof moduleVideos[number]['id'];
export type ModuleVideo = { id: VideoModuleId; category: string; title: string; description: string; youtubeUrl: string };

/** Accept YouTube pages/short links, never arbitrary iframe URLs or HTML. */
export function youtubeEmbedUrl(link: string): string | null {
  try {
    const url = new URL(link.trim());
    if (url.protocol !== 'https:' || url.username || url.password || url.port) return null;
    const host = url.hostname.toLowerCase();
    const parts = url.pathname.split('/').filter(Boolean);
    let id: string | null = null;
    if (host === 'youtu.be' && parts.length === 1) id = parts[0];
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'].includes(host)) {
      if (url.pathname === '/watch') id = url.searchParams.get('v');
      else if (['embed', 'shorts', 'live'].includes(parts[0]) && parts.length === 2) id = parts[1];
    }
    return id && /^[\w-]{11}$/.test(id) ? `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&playsinline=1` : null;
  } catch { return null; }
}
