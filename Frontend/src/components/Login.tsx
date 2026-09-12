import React, { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Lock, Mail, ArrowRight, ArrowLeft, ShieldCheck, Eye, EyeOff, AlertTriangle, CircleX, CheckCircle2, Info } from 'lucide-react';
import { SocialSignIn } from './SocialSignIn';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { LegalModal, type LegalDocType } from './LegalModal';
import { clearClockLocalCache } from '../lib/clockCache';
import { TalentLogo } from './ui/TalentLogo';

export const Login = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const paymentParam = searchParams.get('payment');
  const emailParam = searchParams.get('email');

  const [email, setEmail] = useState(emailParam || '');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  // Hay una sesión guardada en este dispositivo (ver comentario del efecto de abajo).
  const [hasActiveSession, setHasActiveSession] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const { setCurrentUser, setCurrentTier } = useAppStore();
  const [isLegalModalOpen, setIsLegalModalOpen] = useState(false);
  const [legalModalTab, setLegalModalTab] = useState<LegalDocType>('privacy');

  // Bloque 1 (2026-08-13): cambio de contraseña forzado al iniciar sesión.
  const [mustChangeStage, setMustChangeStage] = useState(false);
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [pendingAuth, setPendingAuth] = useState<any>(null);

  useEffect(() => {
    if (emailParam) {
      setEmail(emailParam);
    }
  }, [emailParam]);

  useEffect(() => {
    // ─────────────────────────────────────────────────────────────────────────────────
    // 2026-07-26 (auditoría en vivo) — ANTES: `if (hasToken) navigate('/app')`.
    // Si existía CUALQUIER token guardado, esta pantalla redirigía adentro sin mostrar
    // siquiera el formulario. En un dispositivo compartido —que es justamente el caso de
    // uso del Reloj Checador en tienda— eso significa que si alguien deja su sesión
    // abierta, la siguiente persona que entra a "iniciar sesión" cae DENTRO de la cuenta
    // ajena sin darse cuenta. Reproducido en producción: se navegó a /login con la sesión
    // de otra usuaria abierta y la app entró como ella, con acceso total a su empresa.
    // AHORA: no se redirige solo. Se avisa que hay una sesión activa y se deja elegir
    // entre continuar con ella o entrar con otra cuenta (lo que la cierra primero).
    // ─────────────────────────────────────────────────────────────────────────────────
    const hasToken = !!localStorage.getItem('talent_auth_token');
    if (hasToken && paymentParam !== 'success') {
      setHasActiveSession(true);
    }
  }, [navigate, paymentParam, searchParams, setCurrentUser, setCurrentTier]);

  // Navegación post-autenticación (compartida por el login normal y el cambio forzado).
  const enterApp = (user: any, tenant: any) => {
    setCurrentUser({ ...user, system_role: user.role });
    setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');

    if (user.tenant_id === null && user.role !== 'platform_admin' && user.role !== 'support_agent') {
      navigate('/', { state: { resumeRegistration: true, user, token: localStorage.getItem('talent_auth_token') } });
      return;
    }

    if (user.role === 'platform_admin') {
      navigate('/superadmin');
    } else if (user.role === 'support_agent') {
      navigate('/soporte');
    } else if (user.role === 'empleado') {
      navigate('/empleado');
    } else {
      navigate('/app');
    }
  };

  // Bloque 1: la cuenta entró con una contraseña que alguien más conoce; el backend no la deja
  // usar ninguna otra ruta hasta que elija una propia. La actual es la que acaba de teclear.
  const handleChangePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword !== confirmPassword) {
      setError('Las contraseñas no coinciden.');
      return;
    }
    setIsLoading(true);
    setError('');
    try {
      await axiosInstance.post('/me/change-password', {
        current_password: password,
        new_password: newPassword,
        new_password_confirmation: confirmPassword,
      });
      const { user, tenant } = pendingAuth;
      setMustChangeStage(false);
      enterApp({ ...user, must_change_password: false }, tenant);
    } catch (err: any) {
      setError(err.response?.data?.error || err.response?.data?.message || 'No se pudo cambiar la contraseña.');
    } finally {
      setIsLoading(false);
    }
  };

  /*
   * Acceso por huella pausado por Adán (2026-09-10), reservado para WebAuthn/passkeys.
   * No volver a activar la simulación anterior: ni usuarios ni tokens se crean en el cliente.
   * La huella/Face ID/PIN desbloquea la passkey en el dispositivo; la biometría no se envía.
   * Antes de habilitar: registro autenticado de credenciales, desafío de un solo uso,
   * verificación en servidor de firma, origen, RP ID y usuario, y recuperación de cuenta.
   * Los endpoints siguientes son un esquema pendiente, NO rutas implementadas.
   *
   * const handleBiometricLogin = async () => {
   *   const options = await solicitarDesafioWebAuthnAlServidor();
   *   const assertion = await navigator.credentials.get({ publicKey: options });
   *   const { user, tenant, token } = await verificarAssertionEnServidor(assertion);
   *   localStorage.setItem('talent_auth_token', token);
   *   enterApp(user, tenant); // sólo después de la validación real del servidor
   * };
   */

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setError('');

    // Si había una sesión previa en este dispositivo, se descarta ANTES de autenticar.
    // Así, si el login falla, la persona queda fuera —no dentro de la cuenta anterior— y
    // si tiene éxito, no queda ningún rastro del usuario que estaba antes (mismo criterio
    // que handleLogout: el caché operativo del reloj también se limpia).
    localStorage.removeItem('talent_auth_token');
    localStorage.removeItem('platform_admin_token');
    clearClockLocalCache();

    try {
      const response = await axiosInstance.post('/login', { email, password });

      const { user, tenant, token } = response.data;

      // Save Token
      localStorage.setItem('talent_auth_token', token);

      // Bloque 1: contraseña conocida por otros → antes de entrar, elegir una propia.
      if (user.must_change_password) {
        setPendingAuth({ user, tenant });
        setMustChangeStage(true);
        setIsLoading(false);
        return;
      }

      enterApp(user, tenant);

    } catch (err: any) {
      setError(err.response?.data?.error || 'Error de conexión. Verifica tus credenciales.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen min-h-[100dvh] bg-page text-text-1 flex flex-col items-center justify-between p-3 sm:p-6 selection:bg-accent/20 relative overflow-x-hidden">

      {/* Top Header Bar for easy back navigation */}
      <div className="w-full max-w-md flex items-center justify-between py-3 mb-2 shrink-0 z-10">
        <button
          onClick={() => navigate('/?landing=true')}
          className="flex items-center gap-2 text-xs font-bold text-text-2 hover:text-accent transition-colors bg-surface hover:bg-accent-soft px-3.5 py-2 rounded-xl border border-border shadow-sm active:scale-95"
        >
          <ArrowLeft size={14} className="text-accent" />
          <span>Volver a la landing page</span>
        </button>
        <div className="flex items-center gap-1.5 text-[11px] font-black text-navy-800 bg-accent-soft px-2.5 py-1 rounded-lg border border-border">
          <TalentLogo className="w-5 h-5 text-brand-dark" />
          <span>Talent 360</span>
        </div>
      </div>

      {/* Main Login Card */}
      <div className="w-full max-w-md bg-white rounded-2xl sm:rounded-3xl shadow-2xl border border-border overflow-hidden my-auto z-10">

        {/* Header */}
        <div className="bg-usage-login-panel p-6 sm:p-8 text-center relative overflow-hidden">
          <div className="relative z-10 flex flex-col items-center">
            <TalentLogo className="w-14 h-14 text-usage-logo-on-dark mb-3 sm:mb-4" />
            <h2 className="text-xl sm:text-2xl font-black text-white tracking-tight">Bienvenido de Vuelta</h2>
            <p className="text-navy-100 text-xs sm:text-sm mt-1 sm:mt-2">Ingresa a tu espacio de trabajo en Talent 360</p>
          </div>
        </div>

        {/* Form */}
        <div className="p-5 sm:p-8">

          {/* Aviso de sesión abierta en este dispositivo. Antes esto no existía: la app
              redirigía sola y la persona entraba a la cuenta ajena sin enterarse. */}
          {hasActiveSession && (
            <div className="bg-warning-bg border border-warning-text/20 text-warning-text text-xs font-bold p-3.5 rounded-xl flex items-start gap-2.5 mb-4 shadow-sm">
              <AlertTriangle size={18} className="text-warning-icon shrink-0" aria-hidden="true" />
              <div className="text-left flex-1">
                <p className="font-black text-text-1">Ya hay una sesión abierta en este dispositivo</p>
                <p className="text-[11px] font-semibold text-warning-text mt-0.5 leading-relaxed">
                  Si no eres tú, escribe tus datos abajo — al entrar se cerrará la sesión anterior.
                </p>
                <button
                  type="button"
                  onClick={() => navigate('/app')}
                  className="mt-2 text-[11px] font-black text-warning-text underline underline-offset-2 hover:text-warning-text"
                >
                  Continuar con la sesión actual
                </button>
              </div>
            </div>
          )}

          {/* Bloque 1: se llegó aquí rebotado por el 403 de cambio forzado — decir POR QUÉ. */}
          {searchParams.get('motivo') === 'cambio-contrasena' && !mustChangeStage && (
            <div className="bg-warning-bg border border-warning-text/20 text-warning-text text-xs font-bold p-3.5 rounded-xl flex items-start gap-2.5 mb-4 shadow-sm">
              <Lock size={18} className="text-warning-icon shrink-0" aria-hidden="true" />
              <div className="text-left">
                <p className="font-black text-text-1">Tu cuenta necesita una contraseña nueva</p>
                <p className="text-[11px] font-semibold text-warning-text mt-0.5 leading-relaxed">
                  Inicia sesión con tu contraseña actual y el sistema te pedirá elegir una nueva.
                </p>
              </div>
            </div>
          )}

          {paymentParam === 'success' && (
            <div className="bg-success-bg border border-success-text/20 text-success-text text-xs sm:text-sm font-bold p-3.5 sm:p-4 rounded-xl flex items-start gap-2.5 mb-4 sm:mb-5 shadow-sm">
              <CheckCircle2 size={18} className="text-success-icon shrink-0" aria-hidden="true" />
              <div className="text-left">
                <p className="font-black text-text-1">¡Empresa Creada con Éxito!</p>
                <p className="text-xs font-semibold text-success-text mt-0.5 leading-relaxed">Tu suscripción ha sido confirmada y aprovisionada. Inicia sesión abajo para comenzar a configurar tu espacio.</p>
              </div>
            </div>
          )}

          {error && (
            <div role="alert" className="bg-error-bg border border-error-text/20 text-error-text text-xs sm:text-sm font-bold p-3.5 sm:p-4 rounded-xl flex items-start gap-2 mb-4 sm:mb-5">
              <CircleX size={18} className="text-error-icon shrink-0" aria-hidden="true" />
              <span className="leading-tight">{error}</span>
            </div>
          )}

          {mustChangeStage ? (
            <form onSubmit={handleChangePassword} className="space-y-4 sm:space-y-5">
              <div className="text-center flex flex-col items-center justify-center py-1 sm:py-2">
                <div className="w-12 h-12 bg-warning-bg text-warning-text rounded-full flex items-center justify-center mb-3">
                  <Lock size={22} />
                </div>
                <h4 className="font-extrabold text-text-1 text-base">Crea tu nueva contraseña</h4>
                <p className="text-xs text-text-3 mt-1 max-w-[300px] mx-auto leading-relaxed">
                  Tu contraseña actual es temporal o conocida por alguien más. Por seguridad,
                  elige una nueva antes de continuar — solo tú debes conocerla.
                </p>
              </div>

              <div>
                <label className="block text-xs sm:text-sm font-bold text-text-2 mb-1.5 sm:mb-2">Nueva contraseña</label>
                <input
                  type="password"
                  required
                  minLength={6}
                  value={newPassword}
                  onChange={e => setNewPassword(e.target.value)}
                  className="w-full px-4 py-2.5 sm:py-3 bg-surface border border-border rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:border-transparent transition-all font-medium text-text-1 text-sm"
                  placeholder="Mínimo 6 caracteres"
                />
              </div>

              <div>
                <label className="block text-xs sm:text-sm font-bold text-text-2 mb-1.5 sm:mb-2">Confirmar nueva contraseña</label>
                <input
                  type="password"
                  required
                  minLength={6}
                  value={confirmPassword}
                  onChange={e => setConfirmPassword(e.target.value)}
                  className="w-full px-4 py-2.5 sm:py-3 bg-surface border border-border rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:border-transparent transition-all font-medium text-text-1 text-sm"
                  placeholder="Repítela"
                />
              </div>

              <button
                type="submit"
                disabled={isLoading}
                className="w-full bg-accent text-white font-black py-3 sm:py-3.5 rounded-xl hover:bg-accent-hover transition-all shadow-lg shadow-accent/20 flex items-center justify-center gap-2 text-xs sm:text-sm disabled:opacity-70 disabled:cursor-not-allowed"
              >
                {isLoading ? 'Guardando...' : 'Guardar y Entrar'}
                {!isLoading && <ArrowRight size={18} />}
              </button>
            </form>
          ) : (
            <form onSubmit={handleLogin} className="space-y-4 sm:space-y-5">
              <div>
                <label htmlFor="login-email" className="block text-xs sm:text-sm font-bold text-text-2 mb-1.5 sm:mb-2">Correo Electrónico</label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 sm:pl-4 flex items-center pointer-events-none text-text-3">
                    <Mail size={18} />
                  </div>
                  <input
                    id="login-email"
                    type="email"
                    autoComplete="username"
                    required
                    value={email}
                    onChange={e => setEmail(e.target.value.toLowerCase().trim())}
                    className="w-full pl-10 sm:pl-11 pr-4 py-2.5 sm:py-3 bg-surface border border-border rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:border-transparent transition-all font-medium text-text-1 text-sm placeholder-text-3"
                    placeholder="usuario@dominio.com"
                  />
                </div>
              </div>

              <div>
                <div className="flex justify-between items-center mb-1.5 sm:mb-2">
                  <label htmlFor="login-password" className="block text-xs sm:text-sm font-bold text-text-2">Contraseña</label>
                  <a href="/forgot-password" className="text-xs font-bold text-accent hover:text-navy-800">¿Olvidaste tu contraseña?</a>
                </div>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 sm:pl-4 flex items-center pointer-events-none text-text-3">
                    <Lock size={18} />
                  </div>
                  <input
                    id="login-password"
                    autoComplete="current-password"
                    type={showPassword ? "text" : "password"}
                    required
                    value={password}
                    onChange={e => setPassword(e.target.value)}
                    className="w-full pl-10 sm:pl-11 pr-10 sm:pr-11 py-2.5 sm:py-3 bg-surface border border-border rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:border-transparent transition-all font-medium text-text-1 text-sm"
                    placeholder="••••••••"
                  />
                  <button
                    type="button"
                    aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute inset-y-0 right-0 pr-3.5 sm:pr-4 flex items-center text-text-3 hover:text-text-2"
                  >
                    {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                  </button>
                </div>
              </div>

              <div className="flex gap-2.5 sm:gap-3 mt-4">
                <button
                  type="submit"
                  disabled={isLoading}
                  className="flex-1 bg-accent text-white font-black py-3 sm:py-3.5 rounded-xl hover:bg-accent-hover transition-all shadow-lg shadow-accent/20 flex items-center justify-center gap-2 text-xs sm:text-sm disabled:opacity-70 disabled:cursor-not-allowed active:scale-98"
                >
                  {isLoading ? 'Iniciando...' : 'Entrar al Sistema'}
                  {!isLoading && <ArrowRight size={18} />}
                </button>


              </div>

              {paymentParam === 'success' && emailParam && (
                <div className="bg-info-bg border border-info-text/20 text-info-text text-xs font-bold p-3.5 rounded-xl mt-3 text-left">
                  <p className="flex items-center gap-1.5"><Info size={16} className="text-info-icon shrink-0" aria-hidden="true" /> <span className="font-extrabold text-navy-800">Inicio de Sesión Social</span></p>
                  <p className="font-semibold text-[10px] text-accent mt-1 leading-normal">
                    Usa el mismo método con el que registraste tu cuenta para continuar con tu empresa.
                  </p>
                </div>
              )}
            </form>
          )}

          <div className="mt-5">
            <SocialSignIn onSuccess={({ user, tenant }) => enterApp(user, tenant)} onError={setError} />
          </div>

          <div className="mt-6 pt-5 border-t border-border flex items-center justify-center gap-2 text-xs font-medium text-text-3">
            <ShieldCheck size={14} className="text-success-text" />
            Conexión Segura SSL (256-bit)
          </div>
        </div>
      </div>

      {/* FOOTER LEGAL DE LOGIN */}
      <div className="w-full py-4 text-center text-[11px] text-text-2 bg-page border-t border-border mt-auto flex flex-wrap items-center justify-center gap-3 sm:gap-6 shrink-0 z-10">
        <button
          type="button"
          onClick={() => { setLegalModalTab('privacy'); setIsLegalModalOpen(true); }}
          className="hover:text-accent transition cursor-pointer"
        >
          Aviso de Privacidad
        </button>
        <span className="text-text-2">•</span>
        <button
          type="button"
          onClick={() => { setLegalModalTab('terms'); setIsLegalModalOpen(true); }}
          className="hover:text-accent transition cursor-pointer"
        >
          Términos de Servicio (SLA)
        </button>
        <span className="text-text-2">•</span>
        <button
          type="button"
          onClick={() => { setLegalModalTab('arco'); setIsLegalModalOpen(true); }}
          className="hover:text-accent transition cursor-pointer"
        >
          Derechos ARCO
        </button>
      </div>

      <LegalModal
        isOpen={isLegalModalOpen}
        onClose={() => setIsLegalModalOpen(false)}
        defaultTab={legalModalTab}
      />
    </div>
  );
};
