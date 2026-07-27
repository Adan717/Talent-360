import React, { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Lock, Mail, ArrowRight, ArrowLeft, ShieldCheck, Eye, EyeOff, Fingerprint, KeyRound } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { LegalModal, type LegalDocType } from './LegalModal';
import { clearClockLocalCache } from '../lib/clockCache';

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
  const [showSocialModal, setShowSocialModal] = useState(false);
  const [socialProvider, setSocialProvider] = useState<'google' | 'apple' | 'samsung' | null>(null);
  const [socialEmail, setSocialEmail] = useState(emailParam || '');
  const [showPassword, setShowPassword] = useState(false);
  const { setCurrentUser, setCurrentTier } = useAppStore();
  const [isLegalModalOpen, setIsLegalModalOpen] = useState(false);
  const [legalModalTab, setLegalModalTab] = useState<LegalDocType>('privacy');

  // Estados de simulación de seguridad avanzada (2FA y Biometría)
  const [is2FAStage, setIs2FAStage] = useState(false);
  const [otpCode, setOtpCode] = useState<string[]>(['', '', '', '', '', '']);
  const [tempAuthData, setTempAuthData] = useState<any>(null);
  const [showBiometricModal, setShowBiometricModal] = useState(false);
  const [biometricStatus, setBiometricStatus] = useState<'idle' | 'scanning' | 'success' | 'failed'>('idle');

  useEffect(() => {
    if (emailParam) {
      setEmail(emailParam);
      setSocialEmail(emailParam);
    }
  }, [emailParam]);

  useEffect(() => {
    const tokenParam = searchParams.get('token');
    if (tokenParam) {
      localStorage.setItem('talent_auth_token', tokenParam);
      setIsLoading(true);
      setError('');
      axiosInstance.get('/me', {
        headers: { Authorization: `Bearer ${tokenParam}` }
      })
      .then(res => {
        const { user, tenant } = res.data;
        setCurrentUser({ ...user, system_role: user.role });
        setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');
        navigate('/app');
      })
      .catch(err => {
        console.error('Error in autologin:', err);
        setError('Error al iniciar sesión automáticamente tras el pago.');
        localStorage.removeItem('talent_auth_token');
      })
      .finally(() => {
        setIsLoading(false);
      });
      return;
    }

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

  const handleGoogleCredentialResponse = async (response: any) => {
    setIsLoading(true);
    setError('');
    try {
      const res = await axiosInstance.post('/login/social', {
        provider: 'google',
        id_token: response.credential
      });

      const { user, token, tenant } = res.data;
      
      localStorage.setItem('talent_auth_token', token);
      
      setCurrentUser(user);
      setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');
      
      navigate('/app');
    } catch (err: any) {
      setError(err.response?.data?.error || 'Error al autenticar con tu cuenta de Google.');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    const clientId = import.meta.env.VITE_GOOGLE_CLIENT_ID;
    if (!clientId) return;

    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);

    script.onload = () => {
      const google = (window as any).google;
      if (google) {
        google.accounts.id.initialize({
          client_id: clientId,
          callback: handleGoogleCredentialResponse,
        });
        
        google.accounts.id.renderButton(
          document.getElementById('google-signin-btn-container'),
          { 
            theme: 'outline', 
            size: 'large', 
            shape: 'rectangular',
            width: 110,
            type: 'icon'
          }
        );
      }
    };

    return () => {
      try {
        document.head.removeChild(script);
      } catch (e) {
        // Ignorar si ya fue removido
      }
    };
  }, []);

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
      
      const { user, tenant, token, requires_2fa } = response.data;
      
      // Simular requerimiento de 2FA si el correo contiene '2fa' o el backend lo requiere explícitamente
      if (requires_2fa || email.includes('2fa')) {
        setIs2FAStage(true);
        setTempAuthData({ user, tenant, token });
        setIsLoading(false);
        return;
      }
      
      // Save Token
      localStorage.setItem('talent_auth_token', token);

      // Update Global State
      setCurrentUser({ ...user, system_role: user.role });
      setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium'); // 'freemium', 'pro', 'enterprise'

      if (user.tenant_id === null && user.role !== 'platform_admin' && user.role !== 'support_agent') {
        navigate('/', { state: { resumeRegistration: true, user, token } });
        return;
      }
      
      // Redirect to main platform based on user role
      if (user.role === 'platform_admin') {
        navigate('/superadmin');
      } else if (user.role === 'support_agent') {
        navigate('/soporte');
      } else if (user.role === 'empleado') {
        navigate('/empleado');
      } else {
        navigate('/app');
      }

    } catch (err: any) {
      setError(err.response?.data?.error || 'Error de conexión. Verifica tus credenciales.');
    } finally {
      setIsLoading(false);
    }
  };

  const handle2FAVerify = (e: React.FormEvent) => {
    e.preventDefault();
    const code = otpCode.join('');
    if (code.length < 6) {
      setError('Por favor, ingresa los 6 dígitos del código de verificación.');
      return;
    }

    setIsLoading(true);
    setError('');

    // Simulación de validación exitosa de 2FA
    setTimeout(() => {
      const { user, tenant, token } = tempAuthData || {
        user: { id: 1, name: 'Admin (DecorArte 360)', role: 'admin', email: email },
        tenant: { plan: 'enterprise' },
        token: 'mock_2fa_token'
      };

      localStorage.setItem('talent_auth_token', token);
      setCurrentUser({ ...user, system_role: user.role });
      setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');
      
      if (user.tenant_id === null && user.role !== 'platform_admin' && user.role !== 'support_agent') {
        navigate('/', { state: { resumeRegistration: true, user, token } });
        setIsLoading(false);
        setIs2FAStage(false);
        return;
      }

      setCurrentUser({ ...user, system_role: user.role });
      setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');

      setIsLoading(false);
      setIs2FAStage(false);

      if (user.role === 'platform_admin') {
        navigate('/superadmin');
      } else if (user.role === 'support_agent') {
        navigate('/soporte');
      } else if (user.role === 'empleado') {
        navigate('/empleado');
      } else {
        navigate('/app');
      }
    }, 1500);
  };

  const startBiometricLogin = () => {
    setShowBiometricModal(true);
    setBiometricStatus('scanning');

    // Simulación del sensor de huellas por 2 segundos
    setTimeout(() => {
      setBiometricStatus('success');
      setTimeout(() => {
        // Enlazar sesión como Liz (empleada principal del clon)
        const mockUser = {
          id: 2,
          name: 'Liz (DecorArte 360)',
          email: 'liz@decorarte360.com',
          role: 'empleado',
          system_role: 'empleado',
          tenant_id: 1,
          avatar: 'https://i.pravatar.cc/150?img=47'
        };
        localStorage.setItem('talent_auth_token', 'mock_biometric_token');
        setCurrentUser(mockUser as any);
        setCurrentTier('enterprise');
        setShowBiometricModal(false);
        setBiometricStatus('idle');
        navigate('/empleado');
      }, 1000);
    }, 2000);
  };

  return (
    <div className="min-h-screen min-h-[100dvh] bg-slate-950 flex flex-col items-center justify-between p-3 sm:p-6 selection:bg-blue-500/20 relative overflow-x-hidden">
      
      {/* Background ambient glow effect */}
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-full max-w-7xl h-96 bg-blue-600/10 blur-[120px] pointer-events-none"></div>
      <div className="absolute bottom-0 right-0 w-96 h-96 bg-indigo-600/10 blur-[120px] pointer-events-none"></div>

      {/* Top Header Bar for easy back navigation */}
      <div className="w-full max-w-md flex items-center justify-between py-3 mb-2 shrink-0 z-10">
        <button
          onClick={() => navigate('/?landing=true')}
          className="flex items-center gap-2 text-xs font-bold text-slate-300 hover:text-white transition-colors bg-slate-900/80 hover:bg-slate-800/90 px-3.5 py-2 rounded-xl border border-slate-800 shadow-sm active:scale-95"
        >
          <ArrowLeft size={14} className="text-blue-400" />
          <span>Volver a la landing page</span>
        </button>
        <div className="flex items-center gap-1.5 text-[11px] font-black text-slate-400 bg-slate-900/50 px-2.5 py-1 rounded-lg border border-slate-800/50">
          <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
          <span>Talent 360</span>
        </div>
      </div>

      {/* Main Login Card */}
      <div className="w-full max-w-md bg-white rounded-2xl sm:rounded-3xl shadow-2xl border border-slate-100 overflow-hidden my-auto z-10">
        
        {/* Header */}
        <div className="bg-slate-900 p-6 sm:p-8 text-center relative overflow-hidden">
          <div className="absolute top-0 left-0 w-full h-full bg-blue-600/15 blur-2xl"></div>
          <div className="relative z-10 flex flex-col items-center">
            <div className="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-600/30 mb-3 sm:mb-4">
              <span className="text-white font-black text-2xl">T</span>
            </div>
            <h2 className="text-xl sm:text-2xl font-black text-white tracking-tight">Bienvenido de Vuelta</h2>
            <p className="text-slate-400 text-xs sm:text-sm mt-1 sm:mt-2">Ingresa a tu espacio de trabajo en Talent 360</p>
          </div>
        </div>

        {/* Form */}
        <div className="p-5 sm:p-8">

          {/* Aviso de sesión abierta en este dispositivo. Antes esto no existía: la app
              redirigía sola y la persona entraba a la cuenta ajena sin enterarse. */}
          {hasActiveSession && (
            <div className="bg-amber-50 border border-amber-200 text-amber-800 text-xs font-bold p-3.5 rounded-xl flex items-start gap-2.5 mb-4 shadow-sm">
              <span className="text-base">⚠️</span>
              <div className="text-left flex-1">
                <p className="font-black text-slate-800">Ya hay una sesión abierta en este dispositivo</p>
                <p className="text-[11px] font-semibold text-amber-700 mt-0.5 leading-relaxed">
                  Si no eres tú, escribe tus datos abajo — al entrar se cerrará la sesión anterior.
                </p>
                <button
                  type="button"
                  onClick={() => navigate('/app')}
                  className="mt-2 text-[11px] font-black text-amber-900 underline underline-offset-2 hover:text-amber-950"
                >
                  Continuar con la sesión actual
                </button>
              </div>
            </div>
          )}

          {paymentParam === 'success' && (
            <div className="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs sm:text-sm font-bold p-3.5 sm:p-4 rounded-xl flex items-start gap-2.5 mb-4 sm:mb-5 shadow-sm">
              <span className="text-base">✓</span>
              <div className="text-left">
                <p className="font-black text-slate-800">¡Empresa Creada con Éxito!</p>
                <p className="text-xs font-semibold text-emerald-600 mt-0.5 leading-relaxed">Tu suscripción ha sido confirmada y aprovisionada. Inicia sesión abajo para comenzar a configurar tu espacio.</p>
              </div>
            </div>
          )}

          {error && (
            <div className="bg-rose-50 border border-rose-200 text-rose-600 text-xs sm:text-sm font-bold p-3.5 sm:p-4 rounded-xl flex items-start gap-2 mb-4 sm:mb-5">
              <span>⚠️</span>
              <span className="leading-tight">{error}</span>
            </div>
          )}

          {!is2FAStage ? (
            <form onSubmit={handleLogin} className="space-y-4 sm:space-y-5">
              <div>
                <label className="block text-xs sm:text-sm font-bold text-slate-700 mb-1.5 sm:mb-2">Correo Electrónico</label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 sm:pl-4 flex items-center pointer-events-none text-slate-400">
                    <Mail size={18} />
                  </div>
                  <input 
                    type="email" 
                    required
                    value={email}
                    onChange={e => setEmail(e.target.value.toLowerCase().trim())}
                    className="w-full pl-10 sm:pl-11 pr-4 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-900 text-sm placeholder-slate-400"
                    placeholder="usuario@dominio.com"
                  />
                </div>
              </div>

              <div>
                <div className="flex justify-between items-center mb-1.5 sm:mb-2">
                  <label className="block text-xs sm:text-sm font-bold text-slate-700">Contraseña</label>
                  <a href="#" className="text-xs font-bold text-blue-600 hover:text-blue-800">¿Olvidaste tu contraseña?</a>
                </div>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 sm:pl-4 flex items-center pointer-events-none text-slate-400">
                    <Lock size={18} />
                  </div>
                  <input 
                    type={showPassword ? "text" : "password"} 
                    required
                    value={password}
                    onChange={e => setPassword(e.target.value)}
                    className="w-full pl-10 sm:pl-11 pr-10 sm:pr-11 py-2.5 sm:py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-900 text-sm"
                    placeholder="••••••••"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute inset-y-0 right-0 pr-3.5 sm:pr-4 flex items-center text-slate-400 hover:text-slate-600"
                  >
                    {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                  </button>
                </div>
              </div>

              <div className="flex gap-2.5 sm:gap-3 mt-4">
                <button 
                  type="submit" 
                  disabled={isLoading}
                  className="flex-1 bg-blue-600 text-white font-black py-3 sm:py-3.5 rounded-xl hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/20 flex items-center justify-center gap-2 text-xs sm:text-sm disabled:opacity-70 disabled:cursor-not-allowed active:scale-98"
                >
                  {isLoading ? 'Iniciando...' : 'Entrar al Sistema'}
                  {!isLoading && <ArrowRight size={18} />}
                </button>
                
                <button
                  type="button"
                  onClick={startBiometricLogin}
                  className="px-3.5 sm:px-4 py-3 sm:py-3.5 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-xl text-slate-700 hover:text-slate-900 transition-all active:scale-95 flex items-center justify-center shadow-sm shrink-0"
                  title="Acceder con Huella Digital"
                >
                  <Fingerprint size={20} className="text-blue-600 animate-pulse" />
                </button>
              </div>

              {paymentParam === 'success' && emailParam && (
                <div className="bg-blue-50/80 border border-blue-200 text-blue-700 text-xs font-bold p-3.5 rounded-xl mt-3 text-left">
                  <p className="flex items-center gap-1.5"><span className="text-sm">💡</span> <span className="font-extrabold text-blue-800">Inicio de Sesión Social</span></p>
                  <p className="font-semibold text-[10px] text-blue-500 mt-1 leading-normal">
                    Registraste tu cuenta usando tu cuenta social. Haz clic en el botón de **Google** abajo para ingresar directamente y comenzar a configurar tu empresa.
                  </p>
                </div>
              )}
            </form>
          ) : (
            <form onSubmit={handle2FAVerify} className="space-y-4 sm:space-y-5">
              <div className="text-center flex flex-col items-center justify-center py-1 sm:py-2">
                <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center mb-3">
                  <KeyRound size={22} />
                </div>
                <h4 className="font-extrabold text-slate-800 text-base">Verificación de Dos Pasos</h4>
                <p className="text-xs text-slate-500 mt-1 max-w-[285px] mx-auto leading-relaxed">
                  Ingresa el código de 6 dígitos generado por tu aplicación autenticadora para confirmar tu identidad.
                </p>
              </div>

              <div className="flex justify-center items-center gap-1.5 sm:gap-2 my-4 sm:my-6 w-full">
                {otpCode.map((val, idx) => (
                  <input
                    key={idx}
                    id={`otp-${idx}`}
                    type="text"
                    maxLength={1}
                    value={val}
                    onChange={(e) => {
                      const value = e.target.value;
                      const newOtp = [...otpCode];
                      newOtp[idx] = value.substring(value.length - 1);
                      setOtpCode(newOtp);
                      
                      // Auto-focus next input
                      if (value && idx < 5) {
                        const nextInput = document.getElementById(`otp-${idx + 1}`);
                        nextInput?.focus();
                      }
                    }}
                    onKeyDown={(e) => {
                      // Backspace focus prev input
                      if (e.key === 'Backspace' && !otpCode[idx] && idx > 0) {
                        const prevInput = document.getElementById(`otp-${idx - 1}`);
                        prevInput?.focus();
                      }
                    }}
                    className="w-9 h-11 sm:w-11 sm:h-12 border-2 border-slate-200 bg-slate-50 rounded-xl text-center font-black text-base sm:text-lg text-slate-800 focus:bg-white focus:border-blue-500 focus:outline-none transition-all flex-1 min-w-0 max-w-[48px]"
                  />
                ))}
              </div>

              <button 
                type="submit" 
                disabled={isLoading}
                className="w-full bg-blue-600 text-white font-black py-3 sm:py-3.5 rounded-xl hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/20 flex items-center justify-center gap-2 mt-4 text-xs sm:text-sm disabled:opacity-70 disabled:cursor-not-allowed"
              >
                {isLoading ? 'Verificando...' : 'Confirmar Acceso'}
              </button>

              <button
                type="button"
                onClick={() => {
                  setIs2FAStage(false);
                  setError('');
                }}
                className="w-full border border-slate-200 text-slate-500 hover:text-slate-700 font-bold py-2.5 rounded-xl hover:bg-slate-50 transition-all text-xs"
              >
                Volver al inicio de sesión
              </button>
            </form>
          )}

          {/* Social Logins Divider */}
          <div className="relative my-5 sm:my-6">
            <div className="absolute inset-0 flex items-center">
              <div className="w-full border-t border-slate-200"></div>
            </div>
            <div className="relative flex justify-center text-[10px] sm:text-xs uppercase">
              <span className="bg-white px-3 font-bold text-slate-400">O continuar con</span>
            </div>
          </div>

          {/* Social Buttons */}
          <div className="grid grid-cols-3 gap-2.5 sm:gap-3">
            {import.meta.env.VITE_GOOGLE_CLIENT_ID ? (
              <div id="google-signin-btn-container" className="h-[42px] overflow-hidden flex items-center justify-center border border-slate-200 rounded-xl bg-white shadow-sm hover:bg-slate-50 transition-colors"></div>
            ) : (
              <button
                type="button"
                onClick={() => {
                  setSocialProvider('google');
                  setSocialEmail('');
                  setShowSocialModal(true);
                }}
                className="flex items-center justify-center py-2.5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors shadow-sm"
                title="Iniciar sesión con Google (Simulado)"
              >
                <svg className="w-5 h-5" viewBox="0 0 24 24">
                  <path fill="#EA4335" d="M12 5.04c1.66 0 3.2.57 4.38 1.69l3.27-3.27C17.68 1.54 14.98 1 12 1 7.35 1 3.37 3.67 1.39 7.56l3.89 3.02c.92-2.78 3.51-4.54 6.72-4.54z"/>
                  <path fill="#4285F4" d="M23.49 12.27c0-.81-.07-1.59-.2-2.36H12v4.51h6.46c-.29 1.48-1.14 2.73-2.4 3.58l3.76 2.91c2.2-2.03 3.67-5.02 3.67-8.64z"/>
                  <path fill="#FBBC05" d="M5.28 14.78c-.24-.72-.38-1.49-.38-2.28s.14-1.56.38-2.28L1.39 7.2C.51 8.97 0 10.93 0 13s.51 4.03 1.39 5.8l3.89-3.02z"/>
                  <path fill="#34A853" d="M12 23c3.24 0 5.97-1.07 7.96-2.91l-3.76-2.91c-1.1.74-2.5 1.18-4.2 1.18-3.21 0-5.8-1.76-6.72-4.54L1.39 16.84C3.37 20.33 7.35 23 12 23z"/>
                </svg>
              </button>
            )}
            
            <button
              type="button"
              onClick={() => {
                setSocialProvider('apple');
                setSocialEmail('');
                setShowSocialModal(true);
              }}
              className="flex items-center justify-center py-2.5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors shadow-sm text-slate-800"
              title="Iniciar sesión con Apple"
            >
              <svg className="w-5 h-5 fill-current" viewBox="0 0 24 24">
                <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 4.17c.66-.81 1.11-1.93.99-3.06-.96.04-2.13.64-2.82 1.45-.6.69-1.12 1.83-.98 2.94.1.08.2.16.31.25.96-.04 2.13-.64 2.82-1.45"/>
              </svg>
            </button>

            <button
              type="button"
              onClick={() => {
                setSocialProvider('samsung');
                setSocialEmail('');
                setShowSocialModal(true);
              }}
              className="flex items-center justify-center py-2.5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors shadow-sm"
              title="Iniciar sesión con Samsung"
            >
              <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                <rect x="5" y="2" width="14" height="20" rx="3" fill="#1428a0" stroke="#1428a0"/>
                <circle cx="12" cy="18" r="1" fill="white"/>
                <path d="M9 7h6M9 11h6M10 14h4" stroke="white" strokeLinecap="round"/>
              </svg>
            </button>
          </div>

          <div className="mt-6 pt-5 border-t border-slate-100 flex items-center justify-center gap-2 text-xs font-medium text-slate-400">
            <ShieldCheck size={14} className="text-emerald-500" />
            Conexión Segura SSL (256-bit)
          </div>
        </div>
      </div>

      {/* Social Provider Auth Modal */}
      {showSocialModal && (
        <div className="fixed inset-0 bg-slate-950/70 backdrop-blur-md z-50 flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl sm:rounded-3xl p-5 sm:p-6 max-w-md w-full shadow-2xl border border-slate-100 relative animate-in fade-in zoom-in-95 duration-150 my-auto">
            <button
              type="button"
              onClick={() => {
                setShowSocialModal(false);
                setSocialProvider(null);
                setSocialEmail('');
              }}
              className="absolute top-4 right-4 text-slate-400 hover:text-slate-600 font-bold bg-slate-100 p-2 rounded-full w-8 h-8 flex items-center justify-center transition-colors"
            >
              ✕
            </button>

            <div className="flex items-center gap-3 mb-4">
              {socialProvider === 'google' && (
                <div className="w-10 h-10 bg-red-50 rounded-xl flex items-center justify-center shrink-0">
                  <svg className="w-6 h-6" viewBox="0 0 24 24">
                    <path fill="#EA4335" d="M12 5.04c1.66 0 3.2.57 4.38 1.69l3.27-3.27C17.68 1.54 14.98 1 12 1 7.35 1 3.37 3.67 1.39 7.56l3.89 3.02c.92-2.78 3.51-4.54 6.72-4.54z"/>
                    <path fill="#4285F4" d="M23.49 12.27c0-.81-.07-1.59-.2-2.36H12v4.51h6.46c-.29 1.48-1.14 2.73-2.4 3.58l3.76 2.91c2.2-2.03 3.67-5.02 3.67-8.64z"/>
                  </svg>
                </div>
              )}
              {socialProvider === 'apple' && (
                <div className="w-10 h-10 bg-slate-950 rounded-xl flex items-center justify-center text-white shrink-0">
                  <svg className="w-5 h-5 fill-current" viewBox="0 0 24 24">
                    <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 4.17c.66-.81 1.11-1.93.99-3.06-.96.04-2.13.64-2.82 1.45-.6.69-1.12 1.83-.98 2.94.1.08.2.16.31.25.96-.04 2.13-.64 2.82-1.45"/>
                  </svg>
                </div>
              )}
              {socialProvider === 'samsung' && (
                <div className="w-10 h-10 bg-blue-900 rounded-xl flex items-center justify-center text-white shrink-0">
                  <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                    <rect x="5" y="2" width="14" height="20" rx="3" fill="#1428a0" stroke="#1428a0"/>
                    <circle cx="12" cy="18" r="1" fill="white"/>
                  </svg>
                </div>
              )}
              <div>
                <h3 className="font-extrabold text-slate-800 capitalize text-base sm:text-lg">Validar con {socialProvider}</h3>
                <p className="text-xs text-slate-400">Portal de Identidad Federada</p>
              </div>
            </div>

            <p className="text-slate-500 text-xs mb-4 text-left leading-relaxed">
              Introduce la dirección de correo electrónico vinculada a tu cuenta de {socialProvider} para verificar tu identidad y acceder a tu empresa.
            </p>

            <form onSubmit={async (e) => {
              e.preventDefault();
              if (!socialEmail || isLoading) return;
              setIsLoading(true);
              setError('');
              setShowSocialModal(false);
              try {
                const appState = useAppStore.getState();
                if (appState.isSandboxMode) {
                  const mockUser = {
                    id: 999,
                    name: socialEmail.split('@')[0],
                    email: socialEmail,
                    role: 'Administrador',
                    system_role: 'Administrador',
                    tenant_id: 1
                  };
                  setCurrentUser(mockUser as any);
                  setCurrentTier('enterprise');
                  navigate('/app');
                  return;
                }
                const response = await axiosInstance.post('/login/social', {
                  provider: socialProvider,
                  provider_id: socialEmail,
                  email: socialEmail
                });
                const { user, tenant, token } = response.data;
                localStorage.setItem('talent_auth_token', token);
                
                if (user.tenant_id === null) {
                  navigate('/', { state: { resumeRegistration: true, user, token } });
                  return;
                }
                
                setCurrentUser({ ...user, system_role: user.role });
                setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');
                
                if (user.role === 'platform_admin') {
                  navigate('/superadmin');
                } else if (user.role === 'support_agent') {
                  navigate('/soporte');
                } else if (user.role === 'empleado') {
                  navigate('/empleado');
                } else {
                  navigate('/app');
                }
              } catch (err: any) {
                setError(err.response?.data?.error || 'No se pudo iniciar sesión con esta cuenta social.');
              } finally {
                setIsLoading(false);
              }
            }} className="space-y-4 text-left">
              <div>
                <label className="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Correo Electrónico</label>
                <input
                  type="email"
                  required
                  value={socialEmail}
                  onChange={(e) => setSocialEmail(e.target.value.toLowerCase().trim())}
                  placeholder="usuario@gmail.com"
                  className="w-full px-4 py-3 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none bg-slate-50 font-medium"
                />
              </div>

              <button
                type="submit"
                disabled={!socialEmail || isLoading}
                className={`w-full py-3.5 rounded-xl font-bold text-sm text-white transition-all flex items-center justify-center gap-1.5 ${
                  !socialEmail || isLoading
                    ? 'bg-slate-300 cursor-not-allowed'
                    : 'bg-blue-600 hover:bg-blue-700 shadow-md active:scale-98'
                }`}
              >
                {isLoading ? 'Verificando...' : 'Verificar y Acceder'}
              </button>
            </form>
          </div>
        </div>
      )}

      {/* Biometric Scanner Modal */}
      {showBiometricModal && (
        <div className="fixed inset-0 bg-slate-950/70 backdrop-blur-md z-[60] flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl sm:rounded-3xl p-6 sm:p-8 max-w-sm w-full shadow-2xl border border-slate-100 text-center relative overflow-hidden animate-in fade-in zoom-in-95 duration-200 my-auto">
            {/* Background glowing spot */}
            <div className={`absolute top-0 left-1/2 -translate-x-1/2 w-40 h-40 rounded-full blur-3xl opacity-20 transition-colors duration-500 ${
              biometricStatus === 'scanning' ? 'bg-blue-500' : biometricStatus === 'success' ? 'bg-emerald-500' : 'bg-rose-500'
            }`}></div>

            <button
              type="button"
              onClick={() => {
                setShowBiometricModal(false);
                setBiometricStatus('idle');
              }}
              className="absolute top-4 right-4 text-slate-400 hover:text-slate-600 font-bold bg-slate-50 p-2 rounded-full transition-colors"
            >
              ✕
            </button>

            <div className="relative z-10 flex flex-col items-center">
              <h3 className="font-extrabold text-slate-800 text-lg mb-1">Acceso Biométrico</h3>
              <p className="text-xs text-slate-400 mb-6 sm:mb-8">Talent 360 Passkey</p>

              {/* Fingerprint Scanning Node */}
              <div className="relative w-28 h-28 sm:w-32 sm:h-32 flex items-center justify-center mb-6 sm:mb-8">
                {/* Pulsing circles */}
                <div className={`absolute inset-0 rounded-full border-4 animate-ping opacity-10 transition-colors duration-500 ${
                  biometricStatus === 'scanning' ? 'border-blue-500' : biometricStatus === 'success' ? 'border-emerald-500' : 'border-rose-500'
                }`}></div>
                <div className={`absolute -inset-2 rounded-full border-2 border-dashed transition-colors duration-500 ${
                  biometricStatus === 'scanning' ? 'border-blue-300 animate-spin' : biometricStatus === 'success' ? 'border-emerald-300' : 'border-rose-300'
                }`} style={{ animationDuration: '8s' }}></div>

                {/* Fingerprint Icon */}
                <div className={`w-20 h-20 sm:w-24 sm:h-24 rounded-full flex items-center justify-center shadow-lg transition-all duration-500 relative overflow-hidden ${
                  biometricStatus === 'scanning'
                    ? 'bg-blue-50 text-blue-600 shadow-blue-100 border border-blue-200'
                    : biometricStatus === 'success'
                    ? 'bg-emerald-50 text-emerald-600 shadow-emerald-100 border border-emerald-200 scale-105'
                    : 'bg-rose-50 text-rose-600 shadow-rose-100 border border-rose-200'
                }`}>
                  <Fingerprint size={42} className={biometricStatus === 'scanning' ? 'animate-pulse' : ''} />
                  
                  {/* Scanner moving bar */}
                  {biometricStatus === 'scanning' && (
                    <div className="absolute left-0 right-0 h-1 bg-gradient-to-r from-transparent via-blue-500 to-transparent shadow-md shadow-blue-400 animate-[bounce_2s_infinite]"></div>
                  )}
                </div>
              </div>

              {/* Status Message */}
              <div className="min-h-12 flex flex-col justify-center">
                {biometricStatus === 'scanning' && (
                  <>
                    <p className="text-sm font-extrabold text-blue-600 animate-pulse">Escaneando huella...</p>
                    <p className="text-xs text-slate-400 mt-1">Coloca tu dedo en el sensor del dispositivo</p>
                  </>
                )}
                {biometricStatus === 'success' && (
                  <>
                    <p className="text-sm font-extrabold text-emerald-600">¡Huella reconocida!</p>
                    <p className="text-xs text-slate-400 mt-1">Iniciando sesión como Liz...</p>
                  </>
                )}
                {biometricStatus === 'failed' && (
                  <>
                    <p className="text-sm font-extrabold text-rose-600">No se reconoció la huella</p>
                    <p className="text-xs text-slate-400 mt-1">Inténtalo de nuevo o usa contraseña</p>
                  </>
                )}
              </div>

              {/* Action Button */}
              {biometricStatus === 'failed' && (
                <button
                  type="button"
                  onClick={() => setBiometricStatus('scanning')}
                  className="mt-5 px-5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-all"
                >
                  Reintentar Escaneo
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* FOOTER LEGAL DE LOGIN */}
      <div className="w-full py-4 text-center text-[11px] text-slate-400 bg-slate-950/90 border-t border-slate-800/80 mt-auto flex flex-wrap items-center justify-center gap-3 sm:gap-6 shrink-0 z-10">
        <button 
          type="button" 
          onClick={() => { setLegalModalTab('privacy'); setIsLegalModalOpen(true); }}
          className="hover:text-blue-400 transition cursor-pointer"
        >
          Aviso de Privacidad
        </button>
        <span className="text-slate-700">•</span>
        <button 
          type="button" 
          onClick={() => { setLegalModalTab('terms'); setIsLegalModalOpen(true); }}
          className="hover:text-blue-400 transition cursor-pointer"
        >
          Términos de Servicio (SLA)
        </button>
        <span className="text-slate-700">•</span>
        <button 
          type="button" 
          onClick={() => { setLegalModalTab('arco'); setIsLegalModalOpen(true); }}
          className="hover:text-blue-400 transition cursor-pointer"
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
