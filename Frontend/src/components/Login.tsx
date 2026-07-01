import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Lock, Mail, ArrowRight, ShieldCheck, Eye, EyeOff, Fingerprint, KeyRound } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';

export const Login = () => {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [showSocialModal, setShowSocialModal] = useState(false);
  const [socialProvider, setSocialProvider] = useState<'google' | 'apple' | 'samsung' | null>(null);
  const [socialEmail, setSocialEmail] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const { setCurrentUser, setCurrentTier } = useAppStore();

  // Estados de simulación de seguridad avanzada (2FA y Biometría)
  const [is2FAStage, setIs2FAStage] = useState(false);
  const [otpCode, setOtpCode] = useState<string[]>(['', '', '', '', '', '']);
  const [tempAuthData, setTempAuthData] = useState<any>(null);
  const [showBiometricModal, setShowBiometricModal] = useState(false);
  const [biometricStatus, setBiometricStatus] = useState<'idle' | 'scanning' | 'success' | 'failed'>('idle');

  useEffect(() => {
    const hasToken = !!localStorage.getItem('talent_auth_token');
    if (hasToken) {
      navigate('/app');
    }
  }, [navigate]);

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setError('');

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
      
      // Redirect to main platform based on user role
      if (user.role === 'platform_admin') {
        navigate('/superadmin');
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

      setIsLoading(false);
      setIs2FAStage(false);

      if (user.role === 'platform_admin') {
        navigate('/superadmin');
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
    <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4 selection:bg-blue-100">
      <div className="w-full max-w-md bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden">
        
        {/* Header */}
        <div className="bg-slate-900 p-8 text-center relative overflow-hidden">
          <div className="absolute top-0 left-0 w-full h-full bg-blue-600/10 blur-3xl"></div>
          <div className="relative z-10 flex flex-col items-center">
            <div className="w-12 h-12 bg-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-600/30 mb-4">
              <span className="text-white font-black text-2xl">T</span>
            </div>
            <h2 className="text-2xl font-black text-white">Bienvenido de Vuelta</h2>
            <p className="text-slate-400 text-sm mt-2">Ingresa a tu espacio de trabajo en Talent 360</p>
          </div>
        </div>

        {/* Form */}
        <div className="p-8">
          
          {error && (
            <div className="bg-rose-50 border border-rose-200 text-rose-600 text-sm font-bold p-4 rounded-xl flex items-start gap-2 mb-5">
              <span>⚠️</span>
              <span>{error}</span>
            </div>
          )}

          {!is2FAStage ? (
            <form onSubmit={handleLogin} className="space-y-5">
              <div>
                <label className="block text-sm font-bold text-slate-700 mb-2">Correo Electrónico</label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                    <Mail size={18} />
                  </div>
                  <input 
                    type="email" 
                    required
                    value={email}
                    onChange={e => setEmail(e.target.value.toLowerCase().trim())}
                    className="w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-900 placeholder-slate-400"
                    placeholder="francisco@decorarte360.com"
                  />
                </div>
              </div>

              <div>
                <div className="flex justify-between items-center mb-2">
                  <label className="block text-sm font-bold text-slate-700">Contraseña</label>
                  <a href="#" className="text-xs font-bold text-blue-600 hover:text-blue-800">¿Olvidaste tu contraseña?</a>
                </div>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                    <Lock size={18} />
                  </div>
                  <input 
                    type={showPassword ? "text" : "password"} 
                    required
                    value={password}
                    onChange={e => setPassword(e.target.value)}
                    className="w-full pl-11 pr-11 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-900"
                    placeholder="••••••••"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-slate-600"
                  >
                    {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                  </button>
                </div>
              </div>

              <div className="flex gap-3 mt-4">
                <button 
                  type="submit" 
                  disabled={isLoading}
                  className="flex-1 bg-blue-600 text-white font-black py-3.5 rounded-xl hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/20 flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed"
                >
                  {isLoading ? 'Iniciando...' : 'Entrar al Sistema'}
                  {!isLoading && <ArrowRight size={18} />}
                </button>
                
                <button
                  type="button"
                  onClick={startBiometricLogin}
                  className="px-4 py-3.5 bg-slate-100 hover:bg-slate-200 border border-slate-200 rounded-xl text-slate-700 hover:text-slate-900 transition-all active:scale-95 flex items-center justify-center shadow-sm"
                  title="Acceder con Huella Digital"
                >
                  <Fingerprint size={20} className="text-blue-600 animate-pulse" />
                </button>
              </div>
            </form>
          ) : (
            <form onSubmit={handle2FAVerify} className="space-y-5">
              <div className="text-center flex flex-col items-center justify-center py-2">
                <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center mb-3">
                  <KeyRound size={22} />
                </div>
                <h4 className="font-extrabold text-slate-800 text-base">Verificación de Dos Pasos</h4>
                <p className="text-xs text-slate-500 mt-1 max-w-[285px] mx-auto leading-relaxed">
                  Ingresa el código de 6 dígitos generado por tu aplicación autenticadora para confirmar tu identidad.
                </p>
              </div>

              <div className="flex justify-between items-center gap-2 my-6">
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
                    className="w-12 h-12 border-2 border-slate-200 bg-slate-50 rounded-xl text-center font-black text-lg text-slate-800 focus:bg-white focus:border-blue-500 focus:outline-none transition-all"
                  />
                ))}
              </div>

              <button 
                type="submit" 
                disabled={isLoading}
                className="w-full bg-blue-600 text-white font-black py-3.5 rounded-xl hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/20 flex items-center justify-center gap-2 mt-4 disabled:opacity-70 disabled:cursor-not-allowed"
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
          <div className="relative my-6">
            <div className="absolute inset-0 flex items-center">
              <div className="w-full border-t border-slate-200"></div>
            </div>
            <div className="relative flex justify-center text-xs uppercase">
              <span className="bg-white px-3 font-bold text-slate-400">O continuar con</span>
            </div>
          </div>

          {/* Social Buttons */}
          <div className="grid grid-cols-3 gap-3">
            <button
              type="button"
              onClick={() => {
                setSocialProvider('google');
                setSocialEmail('');
                setShowSocialModal(true);
              }}
              className="flex items-center justify-center py-2.5 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors shadow-sm"
              title="Iniciar sesión con Google"
            >
              <svg className="w-5 h-5" viewBox="0 0 24 24">
                <path fill="#EA4335" d="M12 5.04c1.66 0 3.2.57 4.38 1.69l3.27-3.27C17.68 1.54 14.98 1 12 1 7.35 1 3.37 3.67 1.39 7.56l3.89 3.02c.92-2.78 3.51-4.54 6.72-4.54z"/>
                <path fill="#4285F4" d="M23.49 12.27c0-.81-.07-1.59-.2-2.36H12v4.51h6.46c-.29 1.48-1.14 2.73-2.4 3.58l3.76 2.91c2.2-2.03 3.67-5.02 3.67-8.64z"/>
                <path fill="#FBBC05" d="M5.28 14.78c-.24-.72-.38-1.49-.38-2.28s.14-1.56.38-2.28L1.39 7.2C.51 8.97 0 10.93 0 13s.51 4.03 1.39 5.8l3.89-3.02z"/>
                <path fill="#34A853" d="M12 23c3.24 0 5.97-1.07 7.96-2.91l-3.76-2.91c-1.1.74-2.5 1.18-4.2 1.18-3.21 0-5.8-1.76-6.72-4.54L1.39 16.84C3.37 20.33 7.35 23 12 23z"/>
              </svg>
            </button>
            
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

          <div className="mt-8 pt-6 border-t border-slate-100 flex items-center justify-center gap-2 text-xs font-medium text-slate-400">
            <ShieldCheck size={14} className="text-emerald-500" />
            Conexión Segura SSL
          </div>
        </div>
      </div>

      {/* Social Provider Auth Modal */}
      {showSocialModal && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 max-w-md w-full shadow-2xl border border-slate-100 animate-slide-up relative">
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
                <div className="w-10 h-10 bg-red-50 rounded-xl flex items-center justify-center">
                  <svg className="w-6 h-6" viewBox="0 0 24 24">
                    <path fill="#EA4335" d="M12 5.04c1.66 0 3.2.57 4.38 1.69l3.27-3.27C17.68 1.54 14.98 1 12 1 7.35 1 3.37 3.67 1.39 7.56l3.89 3.02c.92-2.78 3.51-4.54 6.72-4.54z"/>
                    <path fill="#4285F4" d="M23.49 12.27c0-.81-.07-1.59-.2-2.36H12v4.51h6.46c-.29 1.48-1.14 2.73-2.4 3.58l3.76 2.91c2.2-2.03 3.67-5.02 3.67-8.64z"/>
                  </svg>
                </div>
              )}
              {socialProvider === 'apple' && (
                <div className="w-10 h-10 bg-slate-950 rounded-xl flex items-center justify-center text-white">
                  <svg className="w-5 h-5 fill-current" viewBox="0 0 24 24">
                    <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 4.17c.66-.81 1.11-1.93.99-3.06-.96.04-2.13.64-2.82 1.45-.6.69-1.12 1.83-.98 2.94.1.08.2.16.31.25.96-.04 2.13-.64 2.82-1.45"/>
                  </svg>
                </div>
              )}
              {socialProvider === 'samsung' && (
                <div className="w-10 h-10 bg-blue-900 rounded-xl flex items-center justify-center text-white">
                  <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                    <rect x="5" y="2" width="14" height="20" rx="3" fill="#1428a0" stroke="#1428a0"/>
                    <circle cx="12" cy="18" r="1" fill="white"/>
                  </svg>
                </div>
              )}
              <div>
                <h3 className="font-extrabold text-slate-800 capitalize text-lg">Validar con {socialProvider}</h3>
                <p className="text-xs text-slate-400">Portal de Identidad Federada</p>
              </div>
            </div>

            <p className="text-slate-500 text-xs mb-5 text-left leading-relaxed">
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
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl border border-slate-100 text-center relative overflow-hidden animate-in fade-in zoom-in duration-200">
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
              <p className="text-xs text-slate-400 mb-8">Talent 360 Passkey</p>

              {/* Fingerprint Scanning Node */}
              <div className="relative w-32 h-32 flex items-center justify-center mb-8">
                {/* Pulsing circles */}
                <div className={`absolute inset-0 rounded-full border-4 animate-ping opacity-10 transition-colors duration-500 ${
                  biometricStatus === 'scanning' ? 'border-blue-500' : biometricStatus === 'success' ? 'border-emerald-500' : 'border-rose-500'
                }`}></div>
                <div className={`absolute -inset-2 rounded-full border-2 border-dashed transition-colors duration-500 ${
                  biometricStatus === 'scanning' ? 'border-blue-300 animate-spin' : biometricStatus === 'success' ? 'border-emerald-300' : 'border-rose-300'
                }`} style={{ animationDuration: '8s' }}></div>

                {/* Fingerprint Icon */}
                <div className={`w-24 h-24 rounded-full flex items-center justify-center shadow-lg transition-all duration-500 relative overflow-hidden ${
                  biometricStatus === 'scanning'
                    ? 'bg-blue-50 text-blue-600 shadow-blue-100 border border-blue-200'
                    : biometricStatus === 'success'
                    ? 'bg-emerald-50 text-emerald-600 shadow-emerald-100 border border-emerald-200 scale-105'
                    : 'bg-rose-50 text-rose-600 shadow-rose-100 border border-rose-200'
                }`}>
                  <Fingerprint size={48} className={biometricStatus === 'scanning' ? 'animate-pulse' : ''} />
                  
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
                  className="mt-6 px-5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-all"
                >
                  Reintentar Escaneo
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
