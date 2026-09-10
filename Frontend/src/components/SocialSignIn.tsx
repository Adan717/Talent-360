import { useEffect, useRef, useState } from 'react';
import axios from '../lib/axios';
import { clearClockLocalCache } from '../lib/clockCache';

type Props = { onSuccess: (data: any) => void; onError: (message: string) => void };
const scripts = new Map<string, Promise<void>>();
function loadScript(src: string) {
  if (!scripts.has(src)) scripts.set(src, new Promise<void>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => { scripts.delete(src); script.remove(); reject(new Error('No se pudo cargar el acceso social.')); };
    document.head.appendChild(script);
  }));
  return scripts.get(src)!;
}

/** Configuración pública del servidor; ninguna clave privada llega al navegador. */
export function SocialSignIn({ onSuccess, onError }: Props) {
  const googleContainer = useRef<HTMLDivElement>(null);
  const handlers = useRef({ onSuccess, onError });
  handlers.current = { onSuccess, onError };
  const [appleReady, setAppleReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const submit = useRef<(provider: string, response: any) => Promise<void>>(async () => {});

  useEffect(() => {
    let cancelled = false;
    setAppleReady(false);
    const box = googleContainer.current;
    if (box) box.replaceChildren();
    (async () => {
      const { data: config } = await axios.get('/auth/social/config');
      if (cancelled || (!config.google_client_id && !config.apple_client_id)) return;
      const { data: challenge } = await axios.post('/auth/social/challenge');
      if (cancelled) return;
      const authenticate = async (provider: string, response: any) => {
        if (cancelled) return;
        setBusy(true);
        try {
          if (provider === 'apple' && response.authorization?.state !== challenge.state) throw new Error('El intento de Apple no coincide.');
          // Una sesión anterior no debe mezclarse con la identidad elegida ahora.
          localStorage.removeItem('talent_auth_token');
          localStorage.removeItem('platform_admin_token');
          clearClockLocalCache();
          const { data } = await axios.post('/login/social', {
            provider, state: challenge.state,
            id_token: provider === 'google' ? response.credential : response.authorization.id_token,
            code: response.authorization?.code,
          });
          if (!data.token || !data.user) throw new Error('El servidor no confirmó el acceso.');
          localStorage.setItem('talent_auth_token', data.token);
          handlers.current.onSuccess(data);
        } catch (error: any) {
          handlers.current.onError(error.response?.data?.error || error.response?.data?.message || error.message || 'No se pudo iniciar sesión.');
          setAttempt(value => value + 1);
        } finally { if (!cancelled) setBusy(false); }
      };
      submit.current = authenticate;
      const loaders: Promise<void>[] = [];
      if (config.google_client_id) loaders.push(loadScript('https://accounts.google.com/gsi/client').then(() => {
        if (cancelled || !box) return;
        const google = (window as any).google;
        google.accounts.id.initialize({ client_id: config.google_client_id, nonce: challenge.nonce, callback: (response: any) => authenticate('google', response) });
        google.accounts.id.renderButton(box, { theme: 'outline', size: 'large', text: 'continue_with', width: 260 });
      }).catch(() => handlers.current.onError('No se pudo cargar el acceso con Google.')));
      if (config.apple_client_id) loaders.push(loadScript('https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/es_ES/appleid.auth.js').then(() => {
        if (cancelled) return;
        (window as any).AppleID.auth.init({ clientId: config.apple_client_id, scope: 'name email', redirectURI: config.apple_redirect_uri, state: challenge.state, nonce: challenge.nonce, usePopup: true });
        setAppleReady(true);
      }).catch(() => handlers.current.onError('No se pudo cargar el acceso con Apple.')));
      await Promise.allSettled(loaders);
    })().catch(() => { if (!cancelled) handlers.current.onError('No se pudo cargar el acceso social. Puedes volver a intentarlo recargando la página.'); });
    return () => { cancelled = true; if (box) box.replaceChildren(); };
  }, [attempt]);

  return <div className="flex flex-col items-center gap-3" aria-busy={busy}>
    <div ref={googleContainer} className={busy ? 'pointer-events-none opacity-60' : ''} />
    {appleReady && <button type="button" disabled={busy} className="w-[260px] rounded-md bg-black text-white px-4 py-2.5 font-semibold disabled:opacity-60" onClick={async () => {
      try { await submit.current('apple', await (window as any).AppleID.auth.signIn()); }
      catch (error: any) { if (error?.error !== 'popup_closed_by_user') handlers.current.onError('Apple no pudo completar el acceso. Intenta de nuevo.'); }
    }}>Continuar con Apple</button>}
    {busy && <p className="text-sm text-slate-500" role="status">Verificando tu cuenta…</p>}
  </div>;
}
