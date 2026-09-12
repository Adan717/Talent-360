import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import axios from '../lib/axios';
import { clearClockLocalCache } from '../lib/clockCache';

export function PasswordRecovery({ reset = false }: { reset?: boolean }) {
  const [params] = useSearchParams();
  const [email, setEmail] = useState(params.get('email') || '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [token] = useState(params.get('token') || '');
  useEffect(() => {
    // El token ya está en memoria: quitarlo de la barra y de enlaces compartidos.
    if (reset && token) window.history.replaceState(null, '', window.location.pathname);
  }, [reset, token]);

  return <main className="min-h-screen bg-slate-950 flex items-center justify-center p-5">
    <section className="bg-white rounded-2xl p-7 w-full max-w-md shadow-xl">
      <h1 className="text-xl font-bold mb-3">{reset ? 'Elige una nueva contraseña' : 'Recuperar acceso'}</h1>
      <p className="text-sm text-text-2 mb-5">{reset ? 'El enlace es de un solo uso y vence en 60 minutos.' : 'Introduce el correo de tu cuenta para recibir un enlace de recuperación.'}</p>
      {message && <p role="status" className="p-3 mb-4 rounded-lg bg-success-bg text-success-text text-sm">{message}</p>}
      {error && <p role="alert" className="p-3 mb-4 rounded-lg bg-danger-bg text-danger-text text-sm">{error}</p>}
      {!done && <form className="space-y-4" onSubmit={async e => {
        e.preventDefault(); setError('');
        if (reset && !token) { setError('Falta el enlace de recuperación. Solicita uno nuevo.'); return; }
        if (reset && password !== confirmation) { setError('Las contraseñas no coinciden.'); return; }
        setBusy(true);
        try {
          const { data } = await axios.post(reset ? '/reset-password' : '/forgot-password', reset ? { email: email.trim().toLowerCase(), token, password, password_confirmation: confirmation } : { email: email.trim().toLowerCase() });
          setMessage(data.message); setDone(true);
          if (reset) { localStorage.removeItem('talent_auth_token'); localStorage.removeItem('platform_admin_token'); clearClockLocalCache(); }
        } catch (e: any) { setError(e.response?.data?.message || 'No pudimos procesar la solicitud. Intenta de nuevo.'); }
        finally { setBusy(false); }
      }}>
        <label className="block text-sm font-semibold">Correo electrónico<input type="email" autoComplete="email" required value={email} onChange={e => setEmail(e.target.value)} className="block border rounded-lg p-3 w-full mt-1" /></label>
        {reset && <>
          <label className="block text-sm font-semibold">Nueva contraseña<input type="password" autoComplete="new-password" required minLength={8} value={password} onChange={e => setPassword(e.target.value)} className="block border rounded-lg p-3 w-full mt-1" /></label>
          <label className="block text-sm font-semibold">Confirmar contraseña<input type="password" autoComplete="new-password" required minLength={8} value={confirmation} onChange={e => setConfirmation(e.target.value)} className="block border rounded-lg p-3 w-full mt-1" /></label>
        </>}
        <button disabled={busy} className="w-full bg-accent text-white rounded-lg p-3 font-bold disabled:opacity-60">{busy ? 'Procesando…' : reset ? 'Guardar contraseña' : 'Enviar enlace'}</button>
      </form>}
      <div className="flex justify-between gap-3 mt-5 text-sm text-accent"><Link to="/login">Volver al inicio de sesión</Link>{reset && <Link to="/forgot-password">Solicitar otro enlace</Link>}</div>
    </section>
  </main>;
}
