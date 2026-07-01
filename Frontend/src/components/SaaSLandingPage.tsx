import React, { useState, useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { ShieldCheck, Zap, Users, GraduationCap, CheckCircle2, ChevronRight, Lock, Sparkles, Building2 } from 'lucide-react';
import { CompanyOnboardingSettings } from './CompanyOnboardingSettings';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';

export const SaaSLandingPage = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const [showCheckout, setShowCheckout] = useState(false);
  const [selectedPlan, setSelectedPlan] = useState<string>('');
  const [employeesCount, setEmployeesCount] = useState<number>(30); // Enterprise default
  const [isProcessing, setIsProcessing] = useState(false);
  const [showOnboarding, setShowOnboarding] = useState(false);
  const [error, setError] = useState('');
  const [registrationStep, setRegistrationStep] = useState<1 | 2>(1);
  const [googleUser, setGoogleUser] = useState<{name: string, email: string, google_id: string} | null>(null);
  const [showGoogleForm, setShowGoogleForm] = useState(false);
  const [googleEmail, setGoogleEmail] = useState('');
  const [googleName, setGoogleName] = useState('');

  // Form Data
  const [formData, setFormData] = useState({
    company_name: '',
    subdomain: ''
  });

  const { setCurrentUser, setCurrentTier } = useAppStore();

  useEffect(() => {
    if (location.state && location.state.resumeRegistration) {
      const { user } = location.state;
      setGoogleUser({
        name: user.name,
        email: user.email,
        google_id: user.google_id || user.email
      });
      setSelectedPlan('Freemium');
      setRegistrationStep(2);
      setShowCheckout(true);
      // Clean history state
      navigate(location.pathname, { replace: true });
    }
  }, [location, navigate]);

  const handleBuy = (plan: string) => {
    setSelectedPlan(plan);
    setRegistrationStep(1);
    setGoogleUser(null);
    setShowGoogleForm(false);
    setError('');
    setShowCheckout(true);
  };

  const handleGoogleMockLogin = async (mockEmail: string, mockName: string) => {
    setIsProcessing(true);
    setError('');
    try {
      const response = await axiosInstance.post('/login/social', {
        provider: 'google',
        provider_id: mockEmail,
        email: mockEmail,
        name: mockName
      });

      const { user, token, tenant } = response.data;
      
      localStorage.setItem('talent_auth_token', token);
      
      if (user.tenant_id) {
        // Already owns a company - direct login
        setCurrentUser(user);
        setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');
        navigate('/app');
      } else {
        // Pre-registered state - proceed to step 2 (Company Details)
        setGoogleUser({
          name: user.name,
          email: user.email,
          google_id: mockEmail
        });
        setRegistrationStep(2);
        setShowGoogleForm(false);
      }
    } catch (err: any) {
      setError(err.response?.data?.error || 'Error al autenticar con Google');
    } finally {
      setIsProcessing(false);
    }
  };

  const processPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsProcessing(true);
    setError('');
    
    try {
      const response = await axiosInstance.post('/subscriptions/create-preference', {
        company_name: formData.company_name,
        subdomain: formData.subdomain,
        plan: selectedPlan.toLowerCase(),
        // dynamic calculation for enterprise
        employees: selectedPlan.toLowerCase() === 'enterprise' ? employeesCount : null
      });

      if (response.data.provisioned) {
        // Freemium: Provisioned immediately
        const { user, tenant, token } = response.data;
        localStorage.setItem('talent_auth_token', token);
        setCurrentUser(user);
        setCurrentTier(tenant.plan?.toLowerCase() || 'freemium');

        setIsProcessing(false);
        setShowCheckout(false);
        setShowOnboarding(true);
      } else if (response.data.init_point) {
        // Paid: Redirect to payment gateway/simulator
        window.location.href = response.data.init_point;
      } else {
        throw new Error('Respuesta inválida del servidor');
      }

    } catch (err: any) {
      setError(err.response?.data?.error || err.message || 'Hubo un problema al procesar el registro.');
      setIsProcessing(false);
    }
  };

  if (showOnboarding) {
    return (
      <div className="min-h-screen bg-slate-50 flex flex-col items-center py-12 px-4 animate-in fade-in">
        <div className="mb-8 text-center">
          <div className="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <CheckCircle2 size={32} />
          </div>
          <h1 className="text-3xl font-black text-slate-900">¡Estructura Creada!</h1>
          <p className="text-slate-500 mt-2">Tu instancia de Talent 360 ha sido configurada. Comienza a registrar tu sucursal y tus puestos reales.</p>
        </div>
        <div className="w-full max-w-2xl bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-200">
          <CompanyOnboardingSettings onComplete={() => navigate('/app')} />
        </div>
      </div>
    );
  }

  // Enterprise pricing calculations
  const pricePerUser = 12; // $12 MXN per user
  const monthlyEnterprisePrice = employeesCount * pricePerUser;
  const yearlyEnterprisePrice = Math.round((employeesCount * pricePerUser * 12) * 0.8); // 20% discount

  return (
    <div className="min-h-screen bg-slate-50 font-sans text-slate-800 selection:bg-blue-100 selection:text-blue-900">
      
      {/* NAVBAR */}
      <header className="fixed top-0 left-0 right-0 z-40 bg-white/80 backdrop-blur-md border-b border-slate-100">
        <div className="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center shadow-lg shadow-blue-500/20">
              <span className="text-white font-black text-xl">T</span>
            </div>
            <h1 className="text-xl font-extrabold tracking-tight text-slate-900">
              Talent <span className="text-blue-600">360</span>
            </h1>
          </div>
          <div className="hidden md:flex gap-8 text-sm font-bold text-slate-500">
            <a href="#features" className="hover:text-slate-900 transition-colors">Plataforma</a>
            <a href="#pricing" className="hover:text-slate-900 transition-colors">Precios</a>
            <a href="#demo" className="hover:text-slate-900 transition-colors">Demostraciones</a>
          </div>
          <div className="flex gap-4">
            <button onClick={() => navigate('/login')} className="text-sm font-bold text-slate-600 hover:text-slate-900 transition-colors">
              Iniciar Sesión
            </button>
            <button onClick={() => handleBuy('Freemium')} className="bg-blue-600 text-white px-5 py-2.5 rounded-xl text-sm font-black hover:bg-blue-700 transition-all shadow-md hover:shadow-lg active:scale-98">
              Crear Cuenta Gratis
            </button>
          </div>
        </div>
      </header>

      {/* HERO SECTION */}
      <section className="relative pt-44 pb-24 px-6 overflow-hidden bg-gradient-to-b from-blue-50/50 via-white to-slate-50">
        <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[1000px] h-[300px] bg-gradient-to-r from-blue-200/30 to-purple-200/30 rounded-full blur-[120px] opacity-60 pointer-events-none"></div>

        <div className="max-w-5xl mx-auto text-center relative z-10 animate-in slide-in-from-bottom-4 duration-500">
          <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-blue-50 border border-blue-100 text-xs font-bold text-blue-600 mb-8 shadow-sm">
            <Sparkles size={14} className="text-blue-500" /> Nuevo: Registro rápido con Google Account
          </div>
          <h2 className="text-5xl md:text-7xl font-black tracking-tight text-slate-900 mb-8 leading-tight">
            El sistema operativo para <br className="hidden md:block"/>
            <span className="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-indigo-600">tu Capital Humano</span>
          </h2>
          <p className="text-lg md:text-xl text-slate-500 max-w-3xl mx-auto mb-12 font-medium leading-relaxed">
            Optimiza la atracción de talento, reloj checador con control biométrico, academia y nóminas. Talent 360 se adapta al tamaño de tu empresa con un modelo transparente.
          </p>
          <div className="flex flex-col sm:flex-row gap-4 justify-center items-center">
            <button onClick={() => {
              document.getElementById('pricing')?.scrollIntoView({ behavior: 'smooth' });
            }} className="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white px-8 py-4 rounded-2xl font-black text-lg shadow-lg shadow-blue-500/25 active:scale-98 transition-all flex items-center justify-center gap-2">
              Ver Planes y Precios <ChevronRight size={20} />
            </button>
          </div>
        </div>
      </section>

      {/* DEMO SECTION */}
      <section id="demo" className="py-24 px-6 bg-white border-y border-slate-100">
        <div className="max-w-7xl mx-auto">
          <div className="text-center mb-20">
            <h3 className="text-3xl md:text-5xl font-black text-slate-900 mb-4">Módulos en Acción</h3>
            <p className="text-slate-500 font-medium max-w-2xl mx-auto">Explora el ecosistema operativo diseñado para dar la mejor experiencia a tus equipos.</p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
            {/* Card 1 */}
            <div className="group bg-slate-50 border border-slate-200/60 rounded-3xl overflow-hidden hover:border-blue-400 hover:shadow-xl transition-all duration-300">
              <div className="aspect-video bg-slate-200 relative overflow-hidden flex items-center justify-center">
                <div className="absolute inset-0 bg-blue-600/5 mix-blend-overlay group-hover:bg-blue-600/10 transition-colors"></div>
                <div className="w-14 h-14 bg-white border border-slate-200 rounded-full flex items-center justify-center text-blue-600 relative z-20 group-hover:scale-110 shadow-md transition-transform duration-300">
                  <span className="text-xl ml-1">▶</span>
                </div>
                <div className="absolute inset-0 flex items-center justify-center text-slate-300 font-black text-6xl select-none tracking-widest opacity-35">
                  WIZARD
                </div>
              </div>
              <div className="p-6 text-left">
                <span className="text-[10px] font-black text-blue-600 uppercase tracking-widest">Configuración Rápida</span>
                <h4 className="font-bold text-slate-800 text-base mt-1 mb-2">Onboarding y Cuentas</h4>
                <p className="text-slate-500 text-xs leading-relaxed">
                  Configura tu sucursal, áreas de trabajo y puestos en pocos pasos a través de nuestro asistente inteligente.
                </p>
              </div>
            </div>

            {/* Card 2 */}
            <div className="group bg-slate-50 border border-slate-200/60 rounded-3xl overflow-hidden hover:border-blue-400 hover:shadow-xl transition-all duration-300">
              <div className="aspect-video bg-slate-200 relative overflow-hidden flex items-center justify-center">
                <div className="absolute inset-0 bg-blue-600/5 mix-blend-overlay group-hover:bg-blue-600/10 transition-colors"></div>
                <div className="w-14 h-14 bg-white border border-slate-200 rounded-full flex items-center justify-center text-blue-600 relative z-20 group-hover:scale-110 shadow-md transition-transform duration-300">
                  <span className="text-xl ml-1">▶</span>
                </div>
                <div className="absolute inset-0 flex items-center justify-center text-slate-300 font-black text-6xl select-none tracking-widest opacity-35">
                  CLOCK
                </div>
              </div>
              <div className="p-6 text-left">
                <span className="text-[10px] font-black text-blue-600 uppercase tracking-widest">Asistencia</span>
                <h4 className="font-bold text-slate-800 text-base mt-1 mb-2">Reloj Checador Biométrico</h4>
                <p className="text-slate-500 text-xs leading-relaxed">
                  Control de horarios mediante huella digital y biometría, prevención de fraude y registro en tiempo real.
                </p>
              </div>
            </div>

            {/* Card 3 */}
            <div className="group bg-slate-50 border border-slate-200/60 rounded-3xl overflow-hidden hover:border-blue-400 hover:shadow-xl transition-all duration-300">
              <div className="aspect-video bg-slate-200 relative overflow-hidden flex items-center justify-center">
                <div className="absolute inset-0 bg-blue-600/5 mix-blend-overlay group-hover:bg-blue-600/10 transition-colors"></div>
                <div className="w-14 h-14 bg-white border border-slate-200 rounded-full flex items-center justify-center text-blue-600 relative z-20 group-hover:scale-110 shadow-md transition-transform duration-300">
                  <span className="text-xl ml-1">▶</span>
                </div>
                <div className="absolute inset-0 flex items-center justify-center text-slate-300 font-black text-6xl select-none tracking-widest opacity-35">
                  ATS
                </div>
              </div>
              <div className="p-6 text-left">
                <span className="text-[10px] font-black text-blue-600 uppercase tracking-widest">Reclutamiento</span>
                <h4 className="font-bold text-slate-800 text-base mt-1 mb-2">Portal de Empleos Integrado</h4>
                <p className="text-slate-500 text-xs leading-relaxed">
                  Publica vacantes de forma pública, gestiona candidatos y califica postulantes de forma inteligente.
                </p>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* PRICING SECTION WITH SLIDER */}
      <section id="pricing" className="py-24 px-6 bg-slate-50">
        <div className="max-w-7xl mx-auto">
          <div className="text-center mb-16">
            <h3 className="text-3xl md:text-5xl font-black text-slate-900 mb-4">Planes Transparentes y Flexibles</h3>
            <p className="text-slate-500 font-medium">Comienza gratis o escala tu plan según el volumen de colaboradores.</p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
            
            {/* FREE PLAN CARD */}
            <div className="bg-white border border-slate-200/80 rounded-3xl p-8 flex flex-col hover:border-blue-300 hover:shadow-lg transition-all text-left">
              <h4 className="text-2xl font-black text-slate-900 mb-2">Plan Gratuito</h4>
              <p className="text-slate-500 text-sm mb-6 min-h-[40px]">Para pequeños negocios que inician la digitalización de su checador.</p>
              <div className="mb-8 flex items-baseline gap-1">
                <span className="text-5xl font-black text-slate-900">$0</span>
                <span className="text-slate-400 font-bold text-xs uppercase">MXN</span>
                <span className="text-slate-400 font-bold">/mes</span>
              </div>
              <ul className="space-y-4 mb-8 flex-1">
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Hasta 5 Colaboradores Activos</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Reloj Checador Básico</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Directorio de Puestos y Estructura</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Onboarding Inicial Limpio</li>
              </ul>
              <button 
                onClick={() => handleBuy('Freemium')} 
                className="w-full font-bold py-3.5 bg-slate-900 text-white hover:bg-slate-800 rounded-xl transition-all shadow-sm active:scale-98 text-center"
              >
                Comenzar Gratis
              </button>
            </div>

            {/* ENTERPRISE PLAN CARD WITH SLIDER */}
            <div className="bg-white border-2 border-blue-600 rounded-3xl p-8 flex flex-col relative shadow-[0_10px_35px_rgba(37,99,235,0.08)] text-left">
              <div className="absolute top-0 right-8 -translate-y-1/2 bg-blue-600 text-white text-[10px] font-black uppercase tracking-widest px-4 py-1.5 rounded-full shadow-md flex items-center gap-1">
                <Sparkles size={12} /> Plan Recomendado
              </div>
              <h4 className="text-2xl font-black text-slate-900 mb-1">Plan Enterprise</h4>
              <p className="text-slate-500 text-sm mb-6 min-h-[40px]">Todo el poder operativo de la plataforma en base de datos dedicada y aislada.</p>
              
              {/* Dynamic Price Display */}
              <div className="mb-6 bg-slate-50 p-5 rounded-2xl border border-slate-200/50">
                <div className="flex justify-between items-baseline mb-2">
                  <span className="text-slate-500 text-xs font-bold uppercase tracking-wider">Costo Mensual</span>
                  <div className="flex items-baseline gap-1">
                    <span className="text-4xl font-black text-blue-600">${monthlyEnterprisePrice.toLocaleString()}</span>
                    <span className="text-slate-400 font-bold text-xs uppercase">MXN</span>
                  </div>
                </div>
                <div className="flex justify-between items-baseline text-xs">
                  <span className="text-emerald-600 font-bold">Pago Anual (Ahorra 20%):</span>
                  <span className="text-slate-700 font-bold">${yearlyEnterprisePrice.toLocaleString()} MXN / año</span>
                </div>
              </div>

              {/* Slider Controller */}
              <div className="mb-8">
                <div className="flex justify-between text-xs font-bold text-slate-600 mb-2">
                  <span>Colaboradores:</span>
                  <span className="text-blue-600 bg-blue-50 px-2 py-0.5 rounded-md">{employeesCount} activos</span>
                </div>
                <input 
                  type="range" 
                  min="10" 
                  max="300" 
                  step="5"
                  value={employeesCount} 
                  onChange={e => setEmployeesCount(parseInt(e.target.value))}
                  className="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-blue-600 focus:outline-none"
                />
                <div className="flex justify-between text-[10px] text-slate-400 font-bold mt-1">
                  <span>10 colab.</span>
                  <span>150 colab.</span>
                  <span>300+ colab.</span>
                </div>
              </div>

              <ul className="space-y-4 mb-8 flex-1">
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-blue-500 shrink-0" size={20}/> Base de datos Aislada por Seguridad</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-blue-500 shrink-0" size={20}/> Módulos Completos: ATS, LMS, Reloj Pro, Nómina</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-blue-500 shrink-0" size={20}/> Soporte Técnico Prioritario</li>
              </ul>
              <button 
                onClick={() => handleBuy('Enterprise')} 
                className="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-xl transition-all shadow-md active:scale-98 text-center"
              >
                Suscribirse Enterprise
              </button>
            </div>
            
          </div>
        </div>
      </section>

      {/* FOOTER */}
      <footer className="bg-white border-t border-slate-100 py-12 px-6 text-center text-sm text-slate-400 font-bold">
        <div className="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-6">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white font-black text-sm">T</div>
            <span className="text-slate-800 font-black">Talent 360</span>
          </div>
          <p>© 2026 Talent 360. Todos los derechos reservados. Infraestructura SaaS Dedicada.</p>
        </div>
      </footer>

      {/* REGISTRATION STEP WIZARD MODAL */}
      {showCheckout && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-in fade-in overflow-y-auto">
          <div className="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl relative text-slate-900 my-auto border border-slate-100 animate-in zoom-in-95 duration-200">
            
            {/* Header */}
            <div className="bg-slate-50 p-6 border-b border-slate-200 flex justify-between items-center">
              <div className="flex items-center gap-2">
                <Building2 className="text-blue-600" size={22} />
                <span className="font-extrabold text-slate-800 text-base">Crear Cuenta Talent 360</span>
              </div>
              <button onClick={() => setShowCheckout(false)} className="text-slate-400 hover:text-slate-600 font-bold text-xl p-1 bg-slate-200/50 rounded-full w-7 h-7 flex items-center justify-center transition-colors">&times;</button>
            </div>
            
            <div className="p-6">
              {error && (
                <div className="mb-4 bg-rose-50 border border-rose-200 text-rose-600 text-xs font-bold p-3 rounded-xl flex gap-1.5 items-start">
                  <span>⚠️</span> <span>{error}</span>
                </div>
              )}

              {/* Progress Steps Indicator */}
              <div className="flex items-center justify-center gap-4 mb-6">
                <div className="flex items-center gap-2">
                  <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-black ${registrationStep === 1 ? 'bg-blue-600 text-white' : 'bg-emerald-100 text-emerald-600'}`}>
                    {registrationStep === 1 ? '1' : '✓'}
                  </div>
                  <span className={`text-xs font-bold ${registrationStep === 1 ? 'text-blue-600' : 'text-slate-500'}`}>Identidad</span>
                </div>
                <div className="w-10 h-0.5 bg-slate-200"></div>
                <div className="flex items-center gap-2">
                  <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-black ${registrationStep === 2 ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-400'}`}>
                    2
                  </div>
                  <span className={`text-xs font-bold ${registrationStep === 2 ? 'text-blue-600' : 'text-slate-400'}`}>Empresa</span>
                </div>
              </div>

              {/* STEP 1: GOOGLE OAUTH FORCED */}
              {registrationStep === 1 && (
                <div className="text-center py-4 space-y-6 animate-in fade-in duration-200">
                  <div className="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mx-auto shadow-inner">
                    <Lock size={28} />
                  </div>
                  
                  {!showGoogleForm ? (
                    <>
                      <div className="space-y-1">
                        <h4 className="font-extrabold text-slate-800 text-lg">Para empezar, valida tu cuenta</h4>
                        <p className="text-xs text-slate-500 leading-relaxed max-w-xs mx-auto">
                          Para garantizar la seguridad de tu base de datos dedicada, debes iniciar sesión con una cuenta de Google.
                        </p>
                      </div>

                      <button
                        type="button"
                        onClick={() => {
                          setGoogleEmail('');
                          setGoogleName('');
                          setShowGoogleForm(true);
                        }}
                        className="w-full py-4 px-6 border-2 border-slate-200 hover:border-blue-300 hover:bg-slate-50 rounded-2xl font-black text-sm text-slate-700 transition-all flex items-center justify-center gap-3 shadow-sm active:scale-98"
                      >
                        <svg className="w-5 h-5" viewBox="0 0 24 24">
                          <path fill="#EA4335" d="M12 5.04c1.66 0 3.2.57 4.38 1.69l3.27-3.27C17.68 1.54 14.98 1 12 1 7.35 1 3.37 3.67 1.39 7.56l3.89 3.02c.92-2.78 3.51-4.54 6.72-4.54z"/>
                          <path fill="#4285F4" d="M23.49 12.27c0-.81-.07-1.59-.2-2.36H12v4.51h6.46c-.29 1.48-1.14 2.73-2.4 3.58l3.76 2.91c2.2-2.03 3.67-5.02 3.67-8.64z"/>
                          <path fill="#FBBC05" d="M5.28 14.78c-.24-.72-.38-1.49-.38-2.28s.14-1.56.38-2.28L1.39 7.2C.51 8.97 0 10.93 0 13s.51 4.03 1.39 5.8l3.89-3.02z"/>
                          <path fill="#34A853" d="M12 23c3.24 0 5.97-1.07 7.96-2.91l-3.76-2.91c-1.1.74-2.5 1.18-4.2 1.18-3.21 0-5.8-1.76-6.72-4.54L1.39 16.84C3.37 20.33 7.35 23 12 23z"/>
                        </svg>
                        Continuar con Google
                      </button>
                    </>
                  ) : (
                    <form 
                      onSubmit={(e) => {
                        e.preventDefault();
                        if (googleEmail && googleName) {
                          handleGoogleMockLogin(googleEmail, googleName);
                        }
                      }}
                      className="space-y-4 text-left"
                    >
                      <div className="text-center mb-2">
                        <h4 className="font-extrabold text-slate-805 text-base">Inicia sesión con Google</h4>
                        <p className="text-[10px] text-slate-400 font-semibold mt-0.5">Ingresa los datos de tu cuenta de Google</p>
                      </div>

                      <div>
                        <label className="text-[10px] font-black text-slate-500 uppercase tracking-wider mb-1 block">Tu Nombre Completo</label>
                        <input 
                          type="text" 
                          required 
                          value={googleName}
                          onChange={e => setGoogleName(e.target.value)}
                          placeholder="Ej. Francisco Vega" 
                          className="w-full bg-white px-4 py-2.5 border border-slate-200 rounded-xl font-medium outline-none focus:ring-2 focus:ring-blue-500 text-sm text-slate-800" 
                        />
                      </div>

                      <div>
                        <label className="text-[10px] font-black text-slate-500 uppercase tracking-wider mb-1 block">Tu Correo de Google</label>
                        <input 
                          type="email" 
                          required 
                          value={googleEmail}
                          onChange={e => setGoogleEmail(e.target.value.toLowerCase().trim())}
                          placeholder="usuario@gmail.com" 
                          className="w-full bg-white px-4 py-2.5 border border-slate-200 rounded-xl font-medium outline-none focus:ring-2 focus:ring-blue-500 text-sm text-slate-800" 
                        />
                      </div>

                      <div className="flex gap-3 pt-2">
                        <button
                          type="button"
                          onClick={() => setShowGoogleForm(false)}
                          className="w-1/3 py-2.5 border border-slate-200 text-slate-500 font-bold rounded-xl text-xs hover:bg-slate-50 active:scale-98 transition-all text-center"
                        >
                          Volver
                        </button>
                        <button
                          type="submit"
                          disabled={isProcessing}
                          className="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-black py-2.5 rounded-xl text-xs shadow-md active:scale-98 transition-all flex justify-center items-center gap-1.5"
                        >
                          {isProcessing ? 'Verificando...' : 'Verificar y Continuar'}
                        </button>
                      </div>
                    </form>
                  )}
                </div>
              )}

              {/* STEP 2: COMPANY DETAILS & SIMULATED CHECKOUT */}
              {registrationStep === 2 && googleUser && (
                <form onSubmit={processPayment} className="space-y-5">
                  
                  {/* Google Authenticated profile badge */}
                  <div className="bg-slate-50 border border-slate-200 rounded-2xl p-3.5 flex items-center gap-3">
                    <div className="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-base shadow-sm">
                      {googleUser.name.charAt(0).toUpperCase()}
                    </div>
                    <div className="text-left">
                      <p className="text-xs font-black text-slate-800">{googleUser.name}</p>
                      <p className="text-[10px] text-slate-400 font-semibold">{googleUser.email}</p>
                    </div>
                    <div className="ml-auto bg-blue-50 text-blue-600 text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded border border-blue-100">
                      Google OK
                    </div>
                  </div>

                  {/* Summary of the selected plan */}
                  <div className="bg-blue-50/50 border border-blue-100 p-4 rounded-2xl text-left flex justify-between items-center">
                    <div>
                      <p className="text-xs text-blue-800 font-extrabold uppercase">Plan Seleccionado</p>
                      <h5 className="text-sm font-black text-slate-900 mt-0.5">
                        {selectedPlan === 'Enterprise' ? `Enterprise (${employeesCount} colab.)` : 'Plan Gratuito'}
                      </h5>
                    </div>
                    <div className="text-right">
                      <span className="text-lg font-black text-blue-600">
                        ${selectedPlan === 'Enterprise' ? monthlyEnterprisePrice.toLocaleString() : '0'}
                      </span>
                      <span className="block text-[9px] text-blue-500 font-bold uppercase">MXN / mes</span>
                    </div>
                  </div>

                  {/* Fields */}
                  <div className="space-y-4 text-left">
                    <div>
                      <label className="text-[10px] font-black text-slate-500 uppercase tracking-wider mb-1 block">Nombre de la Empresa</label>
                      <input 
                        type="text" 
                        value={formData.company_name} 
                        onChange={e => setFormData({...formData, company_name: e.target.value})} 
                        required 
                        placeholder="Ej. DashComputer" 
                        className="w-full bg-white px-4 py-3 border border-slate-200 rounded-xl font-medium outline-none focus:ring-2 focus:ring-blue-500 text-sm" 
                      />
                    </div>
                    <div>
                      <label className="text-[10px] font-black text-slate-500 uppercase tracking-wider mb-1 block">Subdominio para tus empleados</label>
                      <div className="flex border border-slate-200 rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-blue-500 bg-white">
                        <input 
                          type="text" 
                          value={formData.subdomain} 
                          onChange={e => setFormData({...formData, subdomain: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '')})} 
                          required 
                          placeholder="dashcomputer" 
                          className="w-full bg-white px-4 py-3 font-medium outline-none text-sm text-slate-800" 
                        />
                        <div className="bg-slate-50 px-4 py-3 text-xs text-slate-500 font-bold border-l border-slate-200 flex items-center">.talent360.com</div>
                      </div>
                      <p className="text-[9px] text-slate-400 mt-1 font-semibold">Tus empleados ingresarán desde: {formData.subdomain || 'subdominio'}.talent360.com</p>
                    </div>
                  </div>

                  <button 
                    type="submit" 
                    disabled={isProcessing}
                    className={`w-full text-white font-black py-4 rounded-2xl shadow-lg shadow-blue-500/20 transition-all flex justify-center items-center gap-2 text-sm ${isProcessing ? 'bg-blue-400 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700'}`}
                  >
                    {isProcessing ? (
                      <><span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span> Creando Instancia...</>
                    ) : (
                      <>{selectedPlan === 'Enterprise' ? 'Proceder al Pago' : 'Crear mi Empresa'}</>
                    )}
                  </button>
                </form>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
