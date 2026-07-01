import React, { useState, useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { ShieldCheck, Zap, Users, GraduationCap, CheckCircle2, ChevronRight, Lock, Sparkles, Building2, Clock, MapPin, UserPlus, Play, LogIn, Coffee, Utensils, LogOut, Fingerprint, Calendar, Eye, FileText, Check, Menu, X, AlertCircle, Armchair, RotateCcw } from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';
import { RelojSimuladoLanding } from './RelojSimuladoLanding';

export const SaaSLandingPage = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const [showCheckout, setShowCheckout] = useState(false);
  const [selectedPlan, setSelectedPlan] = useState<string>('');
  const [proEmployeesCount, setProEmployeesCount] = useState<number>(20); // Professional default
  const [isProcessing, setIsProcessing] = useState(false);
  const [showOnboarding, setShowOnboarding] = useState(false);
  const [error, setError] = useState('');
  const [registrationStep, setRegistrationStep] = useState<1 | 2>(1);
  const [googleUser, setGoogleUser] = useState<{name: string, email: string, google_id: string} | null>(null);
  const [showGoogleForm, setShowGoogleForm] = useState(false);
  const [googleEmail, setGoogleEmail] = useState('');
  const [googleName, setGoogleName] = useState('');
  const [signUpPassword, setSignUpPassword] = useState('');
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly');
  const [isEmailDuplicated, setIsEmailDuplicated] = useState(false);

  // Form Data
  const [formData, setFormData] = useState({
    company_name: '',
    subdomain: ''
  });

  const [activeTab, setActiveTab] = useState<'checador' | 'rrhh' | 'reclutamiento'>('checador');
  const [liveTime, setLiveTime] = useState(new Date().toLocaleTimeString());
  const [simulatedClockState, setSimulatedClockState] = useState<string>('inactive');
  const [phoneActiveTab, setPhoneActiveTab] = useState<'reloj' | 'tareas' | 'academia' | 'herramientas'>('reloj');
  const [simulatedTier, setSimulatedTier] = useState<'free' | 'pro'>('pro');
  const [simKey, setSimKey] = useState<number>(0);
  const [simulatedTask1Done, setSimulatedTask1Done] = useState(true);
  const [simulatedTask2Done, setSimulatedTask2Done] = useState(false);
  const [hoveredNodeId, setHoveredNodeId] = useState<number | null>(null);
  const [atsCandidates, setAtsCandidates] = useState([
    { id: 1, name: 'Valeria Díaz', vacancy: 'Agente de Ventas', status: 'prospect', time: 'Hace 2 horas' },
    { id: 2, name: 'Adriana López', vacancy: 'Agente de Ventas', status: 'interview', time: 'Hoy 15:00' },
    { id: 3, name: 'Cristian Gómez', vacancy: 'Ayudante General', status: 'hired', time: 'PIN: 2514' }
  ]);

  useEffect(() => {
    const timer = setInterval(() => {
      setLiveTime(new Date().toLocaleTimeString());
    }, 1000);
    return () => clearInterval(timer);
  }, []);

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
  const handleGoogleCredentialResponse = async (response: any) => {
    setIsProcessing(true);
    setError('');
    try {
      const res = await axiosInstance.post('/login/social', {
        provider: 'google',
        id_token: response.credential
      });

      const { user, token, tenant } = res.data;
      
      localStorage.setItem('talent_auth_token', token);
      
      if (user.tenant_id) {
        // Si ya tiene una empresa, inicia sesión directo
        setCurrentUser(user);
        setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');
        navigate('/app');
      } else {
        // Si no tiene empresa (pre-registrado), avanza a configurar la empresa
        setGoogleUser({
          name: user.name,
          email: user.email,
          google_id: user.google_id || user.email
        });
        setRegistrationStep(2);
        setShowGoogleForm(false);
      }
    } catch (err: any) {
      setError(err.response?.data?.error || 'Error al autenticar con tu cuenta de Google.');
    } finally {
      setIsProcessing(false);
    }
  };

  useEffect(() => {
    const clientId = import.meta.env.VITE_GOOGLE_CLIENT_ID;
    if (!clientId) return;

    let script = document.querySelector('script[src="https://accounts.google.com/gsi/client"]') as HTMLScriptElement;
    if (!script) {
      script = document.createElement('script');
      script.src = 'https://accounts.google.com/gsi/client';
      script.async = true;
      script.defer = true;
      document.head.appendChild(script);
    }

    const initGoogleButton = () => {
      const google = (window as any).google;
      if (google && showCheckout && !showGoogleForm && registrationStep === 1) {
        google.accounts.id.initialize({
          client_id: clientId,
          callback: handleGoogleCredentialResponse,
        });
        
        const container = document.getElementById('google-signup-btn-container');
        if (container) {
          google.accounts.id.renderButton(
            container,
            { 
              theme: 'outline', 
              size: 'large', 
              shape: 'rectangular',
              text: 'continue_with',
              width: 320
            }
          );
        }
      }
    };

    script.onload = () => {
      initGoogleButton();
    };

    if ((window as any).google) {
      setTimeout(initGoogleButton, 100);
    }
  }, [showCheckout, showGoogleForm, registrationStep]);
  const handleDialClick = () => {
    if (simulatedClockState === 'inactive') {
      setSimulatedClockState('active');
    } else if (simulatedClockState === 'active') {
      setSimulatedClockState('break_active');
    } else if (simulatedClockState === 'break_active') {
      setSimulatedClockState('break_done');
    } else if (simulatedClockState === 'break_done') {
      setSimulatedClockState('lunch_active');
    } else if (simulatedClockState === 'lunch_active') {
      setSimulatedClockState('lunch_done');
    } else if (simulatedClockState === 'lunch_done') {
      setSimulatedClockState('finished');
    } else {
      setSimulatedClockState('inactive');
    }
  };

  const handleCandidateClick = (id: number) => {
    setAtsCandidates(prev => prev.map(c => {
      if (c.id === id) {
        let newStatus: 'prospect' | 'interview' | 'hired' = 'prospect';
        let newTime = c.time;
        if (c.status === 'prospect') {
          newStatus = 'interview';
          newTime = 'Agendado hoy';
        } else if (c.status === 'interview') {
          newStatus = 'hired';
          newTime = 'PIN: 2514';
        } else {
          newStatus = 'prospect';
          newTime = 'Hace 2 horas';
        }
        return { ...c, status: newStatus, time: newTime };
      }
      return c;
    }));
  };

  const handleTraditionalRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!googleEmail || !googleName || !signUpPassword) {
      setError('Por favor, rellena todos los campos obligatorios.');
      return;
    }
    if (signUpPassword.length < 6) {
      setError('La contraseña debe tener al menos 6 caracteres.');
      return;
    }

    setIsProcessing(true);
    setError('');
    setIsEmailDuplicated(false);
    try {
      const response = await axiosInstance.post('/register', {
        name: googleName,
        email: googleEmail,
        password: signUpPassword
      });

      const { user, token } = response.data;
      localStorage.setItem('talent_auth_token', token);
      
      // Pre-registered state - proceed to step 2 (Company Details)
      setGoogleUser({
        name: user.name,
        email: user.email,
        google_id: ''
      });
      setRegistrationStep(2);
      setShowGoogleForm(false);
    } catch (err: any) {
      const errorMsg = err.response?.data?.message || err.response?.data?.error || '';
      const isDup = errorMsg.toLowerCase().includes('registrado') || 
                    errorMsg.toLowerCase().includes('already') || 
                    errorMsg.toLowerCase().includes('taken') || 
                    errorMsg.toLowerCase().includes('duplicate') ||
                    err.response?.status === 422 ||
                    err.response?.status === 409;
      
      if (isDup) {
        setIsEmailDuplicated(true);
        setError('El correo electrónico ya está registrado en la plataforma.');
      } else {
        setError(errorMsg || 'Error al crear la cuenta. Inténtalo de nuevo.');
      }
    } finally {
      setIsProcessing(false);
    }
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
        employees: selectedPlan.toLowerCase() === 'pro' ? proEmployeesCount : null,
        billing_cycle: billingCycle
      });

      if (response.data.provisioned) {
        // Freemium: Provisioned immediately
        const { user, tenant, token } = response.data;
        localStorage.setItem('talent_auth_token', token);
        setCurrentUser(user);
        setCurrentTier(tenant.plan?.toLowerCase() || 'freemium');

        setIsProcessing(false);
        setShowCheckout(false);
        navigate('/app');
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

  // Professional pricing calculations
  const pricePerUser = 12; // $12 MXN per user
  const monthlyProPrice = proEmployeesCount * pricePerUser;
  const yearlyProPrice = Math.round((proEmployeesCount * pricePerUser * 12) * 0.8); // 20% discount
  const fixedEnterprisePrice = 499;

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
          
          {/* Desktop Navigation Links */}
          <div className="hidden md:flex gap-8 text-sm font-bold text-slate-500">
            <a href="#features" className="hover:text-slate-900 transition-colors">Plataforma</a>
            <a href="#pricing" className="hover:text-slate-900 transition-colors">Precios</a>
            <a href="#demo" className="hover:text-slate-900 transition-colors">Demostraciones</a>
          </div>
          
          {/* Desktop Auth Buttons */}
          <div className="hidden md:flex items-center gap-4">
            <button onClick={() => navigate('/login')} className="text-sm font-bold text-slate-600 hover:text-slate-900 transition-colors">
              Iniciar Sesión
            </button>
            <button onClick={() => handleBuy('Freemium')} className="bg-blue-600 text-white px-5 py-2.5 rounded-xl text-sm font-black hover:bg-blue-700 transition-all shadow-md hover:shadow-lg active:scale-98">
              Crear Cuenta Gratis
            </button>
          </div>

          {/* Mobile Right Controls */}
          <div className="flex md:hidden items-center gap-3">
            <button 
              onClick={() => navigate('/login')} 
              className="text-xs font-black text-blue-600 bg-blue-50 px-3.5 py-2 rounded-xl hover:bg-blue-100 transition-all"
            >
              Entrar
            </button>
            <button 
              onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)} 
              className="p-2 text-slate-500 hover:bg-slate-100 rounded-xl transition-all"
              aria-label="Abrir menú"
            >
              {isMobileMenuOpen ? <X size={24} /> : <Menu size={24} />}
            </button>
          </div>
        </div>

        {/* Mobile Navigation Drawer */}
        {isMobileMenuOpen && (
          <div className="md:hidden fixed top-20 left-0 right-0 bottom-0 bg-white/95 backdrop-blur-lg z-30 flex flex-col p-6 animate-in slide-in-from-top-5 duration-200 border-t border-slate-100">
            <nav className="flex flex-col gap-6 text-base font-extrabold text-slate-700 mb-8">
              <a 
                href="#features" 
                onClick={() => setIsMobileMenuOpen(false)}
                className="p-3 rounded-2xl hover:bg-slate-50 hover:text-slate-900 transition-all flex items-center justify-between"
              >
                <span>Plataforma</span>
                <ChevronRight size={16} className="text-slate-400" />
              </a>
              <a 
                href="#pricing" 
                onClick={() => setIsMobileMenuOpen(false)}
                className="p-3 rounded-2xl hover:bg-slate-50 hover:text-slate-900 transition-all flex items-center justify-between"
              >
                <span>Precios y Planes</span>
                <ChevronRight size={16} className="text-slate-400" />
              </a>
              <a 
                href="#demo" 
                onClick={() => setIsMobileMenuOpen(false)}
                className="p-3 rounded-2xl hover:bg-slate-50 hover:text-slate-900 transition-all flex items-center justify-between"
              >
                <span>Demostraciones</span>
                <ChevronRight size={16} className="text-slate-400" />
              </a>
            </nav>
            <div className="mt-auto flex flex-col gap-3.5">
              <button 
                onClick={() => { setIsMobileMenuOpen(false); navigate('/login'); }} 
                className="w-full py-4 rounded-2xl font-black text-slate-700 bg-slate-100 hover:bg-slate-200 transition-colors text-center text-sm"
              >
                Iniciar Sesión
              </button>
              <button 
                onClick={() => { setIsMobileMenuOpen(false); handleBuy('Freemium'); }} 
                className="w-full py-4 rounded-2xl font-black text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/20 active:scale-98 transition-all text-center text-sm"
              >
                Crear Cuenta Gratis
              </button>
            </div>
          </div>
        )}
      </header>

      {/* HERO SECTION */}
      <section className="relative pt-36 pb-24 px-6 overflow-hidden bg-gradient-to-b from-blue-50/50 via-white to-slate-50">
        <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[1000px] h-[300px] bg-gradient-to-r from-blue-200/20 to-purple-200/20 rounded-full blur-[120px] opacity-60 pointer-events-none"></div>

        <div className="max-w-7xl mx-auto relative z-10 animate-in slide-in-from-bottom-4 duration-500">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
            
            {/* Bloque 1: Propuesta de Valor */}
            <div className="col-span-1 lg:col-span-5 text-left space-y-6 order-1">
              <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-blue-50 border border-blue-100 text-[11px] font-bold text-blue-600 shadow-sm">
                <Sparkles size={12} className="text-blue-500" /> Registro rápido con tu cuenta de Google
              </div>
              
              <h2 className="text-3xl sm:text-4xl md:text-5xl font-black tracking-tight text-slate-900 leading-tight">
                El sistema operativo para <br className="hidden sm:block"/>
                <span className="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 via-indigo-600 to-violet-600">tu Capital Humano</span>
              </h2>
              
              <p className="text-sm md:text-base text-slate-500 font-medium leading-relaxed max-w-lg">
                Optimiza la asistencia con control biométrico y GPS, gestiona expedientes, organigramas y capacitación interna. Todo desde un único panel inteligente.
              </p>
            </div>

            {/* Columna Derecha: Showcase Interactivo de Producto (Orden 2 en móvil) */}
            <div className="col-span-1 lg:col-span-7 relative flex flex-col md:flex-row items-center justify-center gap-6 lg:gap-8 order-2 lg:order-2 w-full">
              <div className="absolute inset-0 bg-gradient-to-tr from-blue-300/10 via-purple-300/5 to-transparent rounded-[32px] blur-2xl opacity-75 pointer-events-none"></div>
              
              {activeTab === 'checador' && (
                <div className="flex items-center gap-2 max-w-sm w-full mx-auto mb-4 order-2 md:absolute md:-top-16 md:left-1/2 md:-translate-x-1/2 md:z-20 justify-center">
                  <div className="flex p-1.5 bg-slate-100/90 rounded-2xl border border-slate-200 shadow-sm flex-1">
                    <button 
                      type="button" 
                      onClick={() => setSimulatedTier('free')}
                      className={`flex-1 py-2.5 px-3 rounded-xl text-[10px] md:text-xs font-black uppercase tracking-wider transition-all duration-300 flex items-center justify-center gap-1 cursor-pointer outline-none border-none ${
                        simulatedTier === 'free' 
                          ? 'bg-white text-slate-800 shadow-md shadow-slate-200/50' 
                          : 'text-slate-400 hover:text-slate-650 font-bold'
                      }`}
                    >
                      <span>🔓</span> Básica
                    </button>
                    <button 
                      type="button" 
                      onClick={() => setSimulatedTier('pro')}
                      className={`flex-1 py-2.5 px-3 rounded-xl text-[10px] md:text-xs font-black uppercase tracking-wider transition-all duration-300 flex items-center justify-center gap-1 cursor-pointer outline-none border-none ${
                        simulatedTier === 'pro' 
                          ? 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-md shadow-blue-500/10' 
                          : 'text-slate-400 hover:text-slate-650 font-bold'
                      }`}
                    >
                      <span>👑</span> Pro
                    </button>
                  </div>
                  
                  {/* Botón de Reinicio Rápido */}
                  <button
                    type="button"
                    title="Reiniciar Simulación"
                    onClick={() => setSimKey(prev => prev + 1)}
                    className="p-2.5 bg-white hover:bg-slate-50 border border-slate-200 rounded-2xl shadow-sm text-slate-500 hover:text-slate-800 transition-all flex items-center justify-center cursor-pointer active:scale-95 outline-none shrink-0"
                  >
                    <RotateCcw size={15} />
                  </button>
                </div>
              )}

              {activeTab === 'checador' ? (
                <div className="flex flex-col md:flex-row items-center justify-center gap-8 lg:gap-10 w-full animate-in fade-in duration-300 mt-14 md:mt-8">

                  {/* SMARTPHONE FRAME (Utiliza el RelojVisual clon real en modo simulado) */}
                  <div className="relative w-full max-w-[290px] border-8 border-slate-900 bg-slate-950 rounded-[42px] shadow-2xl overflow-hidden flex flex-col aspect-[9/19] shrink-0 order-2">
                    {/* Speaker & Sensor Notch */}
                    <div className="absolute top-0 left-1/2 -translate-x-1/2 h-5 w-32 bg-slate-900 rounded-b-2xl z-55 flex items-center justify-center gap-1.5">
                      <div className="w-12 h-1 bg-slate-800 rounded-full"></div>
                      <div className="w-2.5 h-2.5 rounded-full bg-slate-800 border border-slate-700"></div>
                    </div>

                    <div className="flex-grow bg-white flex flex-col justify-between overflow-hidden select-none">
                      <RelojSimuladoLanding 
                        key={simKey}
                        tier={simulatedTier}
                        setTier={setSimulatedTier}
                        onActionClick={() => handleBuy('Professional')}
                      />
                    </div>

                    {/* iOS Home Indicator Bar */}
                    <div className="absolute bottom-1.5 left-1/2 -translate-x-1/2 w-24 h-1 bg-slate-800 rounded-full z-55"></div>
                  </div>

                  {/* Right Side: Comparative Detail Card */}
                  <div className="flex-1 max-w-sm text-left bg-white border border-slate-200/80 p-6 rounded-3xl shadow-xl shadow-slate-100/50 space-y-4 order-3">
                    <div className="flex items-center gap-2">
                      <span className="text-xl">{simulatedTier === 'pro' ? '👑' : '🔓'}</span>
                      <h4 className="text-sm font-black text-slate-800 tracking-tight uppercase">
                        {simulatedTier === 'pro' ? 'Reloj Checador Pro' : 'Reloj Checador Básico'}
                      </h4>
                    </div>
                    <p className="text-xs text-slate-500 font-semibold leading-relaxed">
                      {simulatedTier === 'pro' 
                        ? 'Ideal para empresas que requieren un control operacional riguroco, pases de lista automáticos y checklists de tareas vinculados al checador.'
                        : 'Pensado para microempresas que solo necesitan que sus empleados marquen entrada y salida, sin pases de lista ni geolocalización GPS.'}
                    </p>
                    
                    <div className="border-t border-slate-100 pt-3 space-y-2.5">
                      <h5 className="text-[9px] font-black uppercase tracking-wider text-slate-400">Características de esta versión</h5>
                      <ul className="space-y-2">
                        {simulatedTier === 'pro' ? (
                          <>
                            <li className="flex items-start gap-1.5 text-[11px] font-bold text-slate-700">
                              <span className="text-emerald-500 font-extrabold text-xs">✓</span>
                              <span><strong>Barra de Progreso y Badges</strong>: Timeline dinámico con colores de entrada, descansos y comidas.</span>
                            </li>
                            <li className="flex items-start gap-1.5 text-[11px] font-bold text-slate-700">
                              <span className="text-emerald-500 font-extrabold text-xs">✓</span>
                              <span><strong>Auditoría de Ubicación GPS</strong>: Valida que el colaborador esté en sucursal al checar.</span>
                            </li>
                            <li className="flex items-start gap-1.5 text-[11px] font-bold text-slate-700">
                              <span className="text-emerald-500 font-extrabold text-xs">✓</span>
                              <span><strong>Checklist de Tareas Integrado</strong>: Lista de pendientes operativas del día directo en la app.</span>
                            </li>
                            <li className="flex items-start gap-1.5 text-[11px] font-bold text-slate-700">
                              <span className="text-emerald-500 font-extrabold text-xs">✓</span>
                              <span><strong>Academia LMS e Incidencias</strong>: Cursos de inducción y solicitud de vacaciones/permisos.</span>
                            </li>
                          </>
                        ) : (
                          <>
                            <li className="flex items-start gap-1.5 text-[11px] font-bold text-slate-600">
                              <span className="text-emerald-500 font-extrabold text-xs">✓</span>
                              <span><strong>Fichaje Básico de Turnos</strong>: Registro tradicional de entradas y salidas por PIN.</span>
                            </li>
                            <li className="flex items-start gap-1.5 text-[11px] font-bold text-slate-400/80">
                              <span className="text-rose-500 font-extrabold text-xs">✗</span>
                              <span className="line-through">Sin geolocalización (fichajes fuera de sucursal permitidos).</span>
                            </li>
                            <li className="flex items-start gap-1.5 text-[11px] font-bold text-slate-400/80">
                              <span className="text-rose-500 font-extrabold text-xs">✗</span>
                              <span className="line-through">Sin barra cronológica interactiva de colores de estado.</span>
                            </li>
                            <li className="flex items-start gap-1.5 text-[11px] font-bold text-slate-400/80">
                              <span className="text-rose-500 font-extrabold text-xs">✗</span>
                              <span className="line-through">Pestañas de Tareas, LMS y Herramientas bloqueadas.</span>
                            </li>
                          </>
                        )}
                      </ul>

                    </div>
                  </div>
                </div>
              ) : (
                /* SIMULACIÓN ESCRITORIO (BROWSER FRAME) PARA ATS Y ORGANIGRAMA */
                <div className="relative w-full bg-white rounded-3xl border border-slate-200/80 shadow-2xl shadow-slate-200/50 overflow-hidden flex flex-col min-h-[460px] transition-all animate-in fade-in duration-300">
                  {/* Top Browser Bar */}
                  <div className="bg-slate-50/80 border-b border-slate-200/60 px-4 py-3 flex items-center gap-3 shrink-0">
                    <div className="flex gap-1.5">
                      <div className="w-2.5 h-2.5 rounded-full bg-rose-400"></div>
                      <div className="w-2.5 h-2.5 rounded-full bg-amber-400"></div>
                      <div className="w-2.5 h-2.5 rounded-full bg-emerald-400"></div>
                    </div>
                    <div className="flex-1 max-w-sm mx-auto bg-slate-100 rounded-lg py-1 px-3 text-[10px] font-bold text-slate-400 flex items-center gap-1.5 shadow-inner">
                      <Lock size={10} className="text-slate-400" />
                      <span>https://app.talent360.com/{activeTab === 'rrhh' ? 'organigrama' : 'reclutamiento'}</span>
                    </div>
                  </div>

                  {/* Main Viewport Content */}
                  <div className="p-6 flex-1 bg-slate-50/50 flex flex-col justify-center overflow-x-auto">
                    {activeTab === 'rrhh' && (
                      <div className="w-full text-center flex flex-col justify-center min-w-[500px]">
                        <ul className="flex flex-col items-center relative">
                          {/* Nodo Raíz: Administrador General */}
                          <li 
                            className="relative pb-6"
                            onMouseEnter={() => setHoveredNodeId(1)}
                            onMouseLeave={() => setHoveredNodeId(null)}
                          >
                            <div className={`inline-block bg-white border-2 rounded-3xl p-4 text-center min-w-[210px] shadow-sm transition-all duration-300 relative z-10 ${
                              hoveredNodeId === 1 ? 'border-indigo-500 ring-4 ring-indigo-500/20 scale-102 shadow-indigo-100/50' : hoveredNodeId !== null && (hoveredNodeId === 2 || hoveredNodeId === 3) ? 'border-emerald-500 ring-2 ring-emerald-500/10' : 'border-amber-450 bg-amber-50/5'
                            }`}>
                              <div className="mb-1.5">
                                <span className="text-[8px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border border-amber-300 bg-amber-50 text-amber-600">
                                  Dirección General (Nivel 1)
                                </span>
                              </div>
                              <div className="font-black text-xs text-slate-800 uppercase tracking-widest mb-0.5">ADMINISTRADOR GENERAL</div>
                              <div className="text-[8px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-md inline-block mb-2">Administración</div>
                              
                              <div className="flex items-center gap-2 bg-slate-50 border border-slate-100 p-1.5 rounded-2xl">
                                <div className="w-7 h-7 rounded-full bg-blue-100 text-blue-600 font-black text-xs flex items-center justify-center flex-shrink-0">
                                  FV
                                </div>
                                <div className="text-left overflow-hidden">
                                  <div className="text-[10px] font-black text-slate-800 truncate leading-tight">Francisco Vega</div>
                                  <div className="text-[7.5px] font-medium text-slate-400 truncate">francisco@decorarte360.com</div>
                                </div>
                              </div>
                            </div>

                            {/* Vertical Connector Line */}
                            <div className="w-0.5 h-6 bg-slate-300 mx-auto mt-0"></div>
                          </li>

                          {/* Nivel 2: Hijas */}
                          <li className="flex justify-center gap-8 relative">
                            {/* Nodo Hijo 1: Supervisor */}
                            <div 
                              className="flex flex-col items-center"
                              onMouseEnter={() => setHoveredNodeId(2)}
                              onMouseLeave={() => setHoveredNodeId(null)}
                            >
                              <div className={`inline-block bg-white border-2 rounded-3xl p-4 text-center min-w-[210px] shadow-sm transition-all duration-300 relative z-10 ${
                                hoveredNodeId === 2 ? 'border-indigo-500 ring-4 ring-indigo-500/20 scale-102 shadow-indigo-100/50' : hoveredNodeId === 1 ? 'border-blue-500 ring-2 ring-blue-500/10' : 'border-blue-400 bg-blue-50/5'
                              }`}>
                                <div className="mb-1.5">
                                  <span className="text-[8px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border border-blue-300 bg-blue-50 text-blue-650">
                                    Supervisión (Nivel 2)
                                  </span>
                                </div>
                                <div className="font-black text-xs text-slate-800 uppercase tracking-widest mb-0.5">SUPERVISOR DE VENTAS</div>
                                <div className="text-[8px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-md inline-block mb-2">Ventas</div>
                                
                                <div className="flex items-center gap-2 bg-slate-50 border border-slate-100 p-1.5 rounded-2xl">
                                  <div className="w-7 h-7 rounded-full bg-indigo-100 text-indigo-650 font-black text-xs flex items-center justify-center flex-shrink-0">
                                    LC
                                  </div>
                                  <div className="text-left overflow-hidden">
                                    <div className="text-[10px] font-black text-slate-800 truncate leading-tight">Liz Camacho</div>
                                    <div className="text-[7.5px] font-medium text-slate-400 truncate">liz@decorarte360.com</div>
                                  </div>
                                </div>
                              </div>
                            </div>

                            {/* Nodo Hijo 2: Operario */}
                            <div 
                              className="flex flex-col items-center"
                              onMouseEnter={() => setHoveredNodeId(3)}
                              onMouseLeave={() => setHoveredNodeId(null)}
                            >
                              <div className={`inline-block bg-white border-2 rounded-3xl p-4 text-center min-w-[210px] shadow-sm transition-all duration-300 relative z-10 ${
                                hoveredNodeId === 3 ? 'border-indigo-500 ring-4 ring-indigo-500/20 scale-102 shadow-indigo-100/50' : hoveredNodeId === 1 ? 'border-blue-500 ring-2 ring-blue-500/10' : 'border-emerald-400 bg-emerald-50/5'
                              }`}>
                                <div className="mb-1.5">
                                  <span className="text-[8px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border border-emerald-300 bg-emerald-50 text-emerald-650">
                                    Operaciones (Nivel 3)
                                  </span>
                                </div>
                                <div className="font-black text-xs text-slate-800 uppercase tracking-widest mb-0.5">AYUDANTE GENERAL</div>
                                <div className="text-[8px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-md inline-block mb-2">Producción</div>
                                
                                <div className="flex items-center gap-2 bg-slate-50 border border-slate-100 p-1.5 rounded-2xl">
                                  <div className="w-7 h-7 rounded-full bg-violet-100 text-violet-650 font-black text-xs flex items-center justify-center flex-shrink-0">
                                    HC
                                  </div>
                                  <div className="text-left overflow-hidden">
                                    <div className="text-[10px] font-black text-slate-800 truncate leading-tight">Hiraym Castillo</div>
                                    <div className="text-[7.5px] font-medium text-slate-400 truncate">hiraym@decorarte360.com</div>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </li>
                        </ul>
                      </div>
                    )}

                    {activeTab === 'reclutamiento' && (
                      <div className="w-full text-center flex flex-col justify-center min-w-[500px]">
                        <div className="flex justify-between items-center mb-4 px-2">
                          <span className="text-[10px] font-black uppercase tracking-wider text-violet-650">Tablero ATS (Vacantes)</span>
                          <span className="text-[9px] font-bold text-slate-400">Puesto: Agente de Ventas</span>
                        </div>
                        <div className="grid grid-cols-3 gap-4">
                          {/* Col 1: Prospectos */}
                          <div className="flex flex-col bg-slate-100/70 border border-slate-200/50 rounded-2xl p-2.5">
                            <p className="text-[9px] font-black text-slate-400 uppercase tracking-wider mb-2.5 flex justify-between items-center px-1">
                              <span>Prospecto</span>
                              <span className="bg-white text-slate-500 font-bold px-1.5 py-0.5 rounded-full text-[8px] shadow-sm">
                                {atsCandidates.filter(c => c.status === 'prospect').length}
                              </span>
                            </p>
                            <div className="flex-1 space-y-2.5 min-h-[220px]">
                              {atsCandidates.filter(c => c.status === 'prospect').map(c => (
                                <button
                                  type="button"
                                  key={c.id}
                                  onClick={() => handleCandidateClick(c.id)}
                                  className="w-full text-left bg-white border border-slate-200/80 rounded-2xl p-3 shadow-sm hover:border-indigo-400 hover:shadow-md transition-all active:scale-98"
                                >
                                  <div className="flex justify-between items-start mb-1.5">
                                    <h5 className="text-[10.5px] font-black text-slate-800 leading-none">{c.name}</h5>
                                    <span className="text-[7.5px] bg-slate-100 text-slate-500 font-bold px-1.5 py-0.5 rounded-md">CV</span>
                                  </div>
                                  <p className="text-[8px] text-slate-450 font-bold mb-2.5">{c.vacancy}</p>
                                  <div className="flex items-center justify-between border-t border-slate-100 pt-2">
                                    <span className="text-[7.5px] text-slate-400 font-medium">{c.time}</span>
                                    <span className="text-[8px] font-black text-blue-600 flex items-center gap-0.5">Avance <ChevronRight size={8} /></span>
                                  </div>
                                </button>
                              ))}
                            </div>
                          </div>

                          {/* Col 2: Entrevista */}
                          <div className="flex flex-col bg-slate-100/70 border border-slate-200/50 rounded-2xl p-2.5">
                            <p className="text-[9px] font-black text-indigo-500 uppercase tracking-wider mb-2.5 flex justify-between items-center px-1">
                              <span>Entrevista</span>
                              <span className="bg-white text-indigo-500 font-bold px-1.5 py-0.5 rounded-full text-[8px] shadow-sm">
                                {atsCandidates.filter(c => c.status === 'interview').length}
                              </span>
                            </p>
                            <div className="flex-1 space-y-2.5 min-h-[220px]">
                              {atsCandidates.filter(c => c.status === 'interview').map(c => (
                                <button
                                  type="button"
                                  key={c.id}
                                  onClick={() => handleCandidateClick(c.id)}
                                  className="w-full text-left bg-white border border-slate-200/80 rounded-2xl p-3 shadow-sm hover:border-indigo-400 hover:shadow-md transition-all active:scale-98"
                                >
                                  <div className="flex justify-between items-start mb-1.5">
                                    <h5 className="text-[10.5px] font-black text-slate-800 leading-none">{c.name}</h5>
                                    <span className="text-[7.5px] bg-indigo-50 text-indigo-650 font-bold px-1.5 py-0.5 rounded-md">Cita</span>
                                  </div>
                                  <p className="text-[8px] text-slate-450 font-bold mb-2.5">{c.vacancy}</p>
                                  <div className="flex items-center justify-between border-t border-slate-100 pt-2">
                                    <span className="text-[7.5px] text-indigo-500 font-bold">{c.time}</span>
                                    <span className="text-[8px] font-black text-blue-600 flex items-center gap-0.5">Avance <ChevronRight size={8} /></span>
                                  </div>
                                </button>
                              ))}
                            </div>
                          </div>

                          {/* Col 3: Contratados */}
                          <div className="flex flex-col bg-slate-100/70 border border-slate-200/50 rounded-2xl p-2.5">
                            <p className="text-[9px] font-black text-emerald-600 uppercase tracking-wider mb-2.5 flex justify-between items-center px-1">
                              <span>Contratados</span>
                              <span className="bg-white text-emerald-600 font-bold px-1.5 py-0.5 rounded-full text-[8px] shadow-sm">
                                {atsCandidates.filter(c => c.status === 'hired').length}
                              </span>
                            </p>
                            <div className="flex-1 space-y-2.5 min-h-[220px]">
                              {atsCandidates.filter(c => c.status === 'hired').map(c => (
                                <button
                                  type="button"
                                  key={c.id}
                                  onClick={() => handleCandidateClick(c.id)}
                                  className="w-full text-left bg-white border border-l-2 border-l-emerald-500 border-slate-200/80 rounded-2xl p-3 shadow-sm hover:border-indigo-400 hover:shadow-md transition-all active:scale-98"
                                >
                                  <div className="flex justify-between items-start mb-1.5">
                                    <h5 className="text-[10.5px] font-black text-slate-800 leading-none">{c.name}</h5>
                                    <span className="text-[7.5px] bg-emerald-50 text-emerald-650 font-bold px-1.5 py-0.5 rounded-md">Contratado</span>
                                  </div>
                                  <p className="text-[8px] text-slate-450 font-bold mb-2.5">{c.vacancy}</p>
                                  <div className="flex items-center justify-between border-t border-slate-100 pt-2">
                                    <span className="text-[7.5px] text-emerald-600 font-black">{c.time}</span>
                                    <span className="text-[8px] font-black text-blue-600 flex items-center gap-0.5">Reciclar <ChevronRight size={8} /></span>
                                  </div>
                                </button>
                              ))}
                            </div>
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>

            {/* Bloque 3: Controles y Selector de Pestañas (Abajo en Móvil, Izquierda en Desktop) */}
            <div className="col-span-1 lg:col-span-5 text-left space-y-6 order-3 lg:order-2">
              <div className="flex flex-wrap gap-4">
                <button 
                  onClick={() => {
                    document.getElementById('pricing')?.scrollIntoView({ behavior: 'smooth' });
                  }} 
                  className="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl font-black text-xs shadow-lg shadow-blue-500/20 active:scale-98 transition-all flex items-center justify-center gap-2"
                >
                  Ver Planes y Precios <ChevronRight size={14} />
                </button>
              </div>

              {/* Selector de Pestañas Interactivas */}
              <div className="space-y-2.5 border-t border-slate-100 pt-6">
                <p className="text-[9px] font-black text-slate-400 uppercase tracking-widest px-1">Explora las interfaces clave (Toca para interactuar)</p>
                <div className="flex flex-row lg:flex-col gap-2 overflow-x-auto pb-2 scrollbar-none snap-x snap-mandatory scroll-smooth">
                  <button
                    type="button"
                    onClick={() => setActiveTab('checador')}
                    className={`flex items-center gap-3 p-3 rounded-2xl text-left transition-all border shrink-0 snap-center min-w-[245px] lg:min-w-0 ${activeTab === 'checador' ? 'bg-white border-blue-100 shadow-md text-blue-600' : 'border-transparent text-slate-650 hover:bg-slate-50'}`}
                  >
                    <div className={`w-8 h-8 rounded-xl flex items-center justify-center ${activeTab === 'checador' ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-500'}`}>
                      <Clock size={15} />
                    </div>
                    <div>
                      <p className="text-xs font-black">Reloj Checador Premium V2</p>
                      <p className="text-[10px] text-slate-400 font-semibold">Asistencia con geocerca, biométricos y firma digital</p>
                    </div>
                  </button>

                  <button
                    type="button"
                    onClick={() => setActiveTab('rrhh')}
                    className={`flex items-center gap-3 p-3 rounded-2xl text-left transition-all border shrink-0 snap-center min-w-[245px] lg:min-w-0 ${activeTab === 'rrhh' ? 'bg-white border-blue-100 shadow-md text-blue-600' : 'border-transparent text-slate-655 hover:bg-slate-50'}`}
                  >
                    <div className={`w-8 h-8 rounded-xl flex items-center justify-center ${activeTab === 'rrhh' ? 'bg-indigo-50 text-indigo-650' : 'bg-slate-100 text-slate-500'}`}>
                      <Users size={15} />
                    </div>
                    <div>
                      <p className="text-xs font-black">Organigrama & Recursos Humanos</p>
                      <p className="text-[10px] text-slate-400 font-semibold">Visualización de personal y jerarquías relacionales</p>
                    </div>
                  </button>

                  <button
                    type="button"
                    onClick={() => setActiveTab('reclutamiento')}
                    className={`flex items-center gap-3 p-3 rounded-2xl text-left transition-all border shrink-0 snap-center min-w-[245px] lg:min-w-0 ${activeTab === 'reclutamiento' ? 'bg-white border-blue-100 shadow-md text-blue-600' : 'border-transparent text-slate-655 hover:bg-slate-50'}`}
                  >
                    <div className={`w-8 h-8 rounded-xl flex items-center justify-center ${activeTab === 'reclutamiento' ? 'bg-violet-50 text-violet-650' : 'bg-slate-100 text-slate-500'}`}>
                      <UserPlus size={15} />
                    </div>
                    <div>
                      <p className="text-xs font-black">Reclutamiento ATS</p>
                      <p className="text-[10px] text-slate-400 font-semibold">Tablero Kanban para el seguimiento de candidatos</p>
                    </div>
                  </button>
                </div>
              </div>
            </div>
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
          <div className="text-center mb-8">
            <h3 className="text-3xl md:text-5xl font-black text-slate-900 mb-4">Planes Transparentes y Flexibles</h3>
            <p className="text-slate-500 font-medium">Comienza gratis o escala tu plan según el volumen de colaboradores.</p>
          </div>

          {/* Billing Cycle Switch/Toggle */}
          <div className="flex justify-center items-center gap-3 mb-16 select-none">
            <span className={`text-sm font-extrabold transition-colors duration-200 ${billingCycle === 'monthly' ? 'text-blue-600' : 'text-slate-500'}`}>Facturación Mensual</span>
            <button 
              type="button"
              onClick={() => setBillingCycle(billingCycle === 'monthly' ? 'yearly' : 'monthly')}
              className="w-14 h-8 bg-slate-200 hover:bg-slate-300 rounded-full p-1 transition-all duration-300 relative focus:outline-none"
              aria-label="Alternar ciclo de facturación"
            >
              <div 
                className={`w-6 h-6 bg-blue-600 rounded-full transition-all duration-300 transform shadow-md ${billingCycle === 'yearly' ? 'translate-x-6' : 'translate-x-0'}`}
              />
            </button>
            <span className={`text-sm font-extrabold flex items-center gap-1.5 transition-colors duration-200 ${billingCycle === 'yearly' ? 'text-blue-600' : 'text-slate-500'}`}>
              Facturación Anual
              <span className="text-[9px] font-black text-white bg-emerald-500 px-2 py-0.5 rounded-full uppercase tracking-wider animate-pulse">Ahorra 20%</span>
            </span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto items-stretch">
            
            {/* FREE PLAN CARD */}
            <div className="bg-white border border-slate-200/80 rounded-3xl p-8 flex flex-col hover:border-blue-300 hover:shadow-lg transition-all text-left">
              <h4 className="text-2xl font-black text-slate-900 mb-2">Plan Gratuito</h4>
              <p className="text-slate-500 text-sm mb-6 min-h-[40px]">Para pequeños negocios que inician la digitalización de su checador.</p>
              <div className="mb-8 bg-slate-50 p-5 rounded-2xl border border-slate-200/50 flex flex-col justify-center min-h-[106px]">
                <div className="flex items-baseline gap-1">
                  <span className="text-5xl font-black text-slate-900">$0</span>
                  <span className="text-slate-400 font-bold text-xs uppercase">MXN</span>
                  <span className="text-slate-400 font-bold">/mes</span>
                </div>
                <span className="text-[10px] text-slate-400 font-bold mt-1.5">Sin plazos forzosos, gratis para siempre</span>
              </div>
              <ul className="space-y-4 mb-8 flex-1">
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Hasta 5 Colaboradores Activos</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Reloj Checador Básico</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Directorio de Puestos y Estructura</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Onboarding Inicial Limpio</li>
              </ul>
              <button 
                onClick={() => handleBuy('Freemium')} 
                className="w-full font-bold py-3.5 bg-slate-950 text-white hover:bg-slate-800 rounded-xl transition-all shadow-sm active:scale-98 text-center"
              >
                Comenzar Gratis
              </button>
            </div>

            {/* PROFESSIONAL PLAN CARD WITH SLIDER */}
            <div className="bg-white border-2 border-blue-600 rounded-3xl p-8 flex flex-col relative shadow-[0_10px_35px_rgba(37,99,235,0.08)] text-left transform md:-translate-y-4">
              <div className="absolute top-0 right-8 -translate-y-1/2 bg-blue-600 text-white text-[10px] font-black uppercase tracking-widest px-4 py-1.5 rounded-full shadow-md flex items-center gap-1">
                <Sparkles size={12} /> Plan Recomendado
              </div>
              <h4 className="text-2xl font-black text-slate-900 mb-1">Plan Profesional</h4>
              <p className="text-slate-500 text-sm mb-6 min-h-[40px]">Escala a medida que tu equipo crece en base de datos optimizada.</p>
              
              {/* Dynamic Price Display */}
              <div className="mb-6 bg-slate-50 p-5 rounded-2xl border border-slate-200/50 transition-all duration-300">
                <div className="flex justify-between items-baseline mb-2">
                  <span className="text-slate-505 text-xs font-bold uppercase tracking-wider">
                    {billingCycle === 'yearly' ? 'Costo Equivalente' : 'Costo Mensual'}
                  </span>
                  <div className="flex items-baseline gap-1">
                    <span className="text-4xl font-black text-blue-600 transition-all">
                      ${(billingCycle === 'yearly' ? Math.round(monthlyProPrice * 0.8) : monthlyProPrice).toLocaleString()}
                    </span>
                    <span className="text-slate-400 font-bold text-xs uppercase">MXN</span>
                    <span className="text-slate-400 text-xs font-bold">/mes</span>
                  </div>
                </div>
                <div className="flex justify-between items-baseline text-xs border-t border-slate-200/60 pt-2 mt-2">
                  <span className="text-emerald-600 font-bold">
                    {billingCycle === 'yearly' ? 'Facturado anualmente:' : 'Ahorra 20% en Plan Anual:'}
                  </span>
                  <span className="text-slate-700 font-bold whitespace-nowrap">
                    ${(billingCycle === 'yearly' ? yearlyProPrice : Math.round(monthlyProPrice * 12 * 0.8)).toLocaleString()} MXN/año
                  </span>
                </div>
              </div>

              {/* Slider Controller */}
              <div className="mb-8">
                <div className="flex justify-between text-xs font-bold text-slate-650 mb-2">
                  <span>Colaboradores:</span>
                  <span className="text-blue-600 bg-blue-50 px-2 py-0.5 rounded-md font-black">{proEmployeesCount} activos</span>
                </div>
                <input 
                  type="range" 
                  min="6" 
                  max="50" 
                  step="1"
                  value={proEmployeesCount} 
                  onChange={e => setProEmployeesCount(parseInt(e.target.value))}
                  className="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-blue-600 focus:outline-none"
                />
                <div className="flex justify-between text-[10px] text-slate-400 font-bold mt-1">
                  <span>6 colab.</span>
                  <span>25 colab.</span>
                  <span>50 colab.</span>
                </div>
              </div>

              <ul className="space-y-4 mb-8 flex-1">
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-blue-500 shrink-0" size={20}/> Colaboradores Escalables (6 a 50)</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-blue-500 shrink-0" size={20}/> Módulos Incluidos (ATS, LMS, Reportes)</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-blue-500 shrink-0" size={20}/> Reloj Checador Pro</li>
              </ul>
              <button 
                onClick={() => handleBuy('PRO')} 
                className="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-xl transition-all shadow-md active:scale-98 text-center"
              >
                Suscribirse Profesional
              </button>
            </div>

            {/* ENTERPRISE PLAN CARD */}
            <div className="bg-white border border-slate-200/80 rounded-3xl p-8 flex flex-col hover:border-blue-300 hover:shadow-lg transition-all text-left">
              <h4 className="text-2xl font-black text-slate-900 mb-2">Plan Enterprise</h4>
              <p className="text-slate-500 text-sm mb-6 min-h-[40px]">Infraestructura dedicada y aislada para corporativos con volumen.</p>
              
              {/* Dynamic Price Display */}
              <div className="mb-8 bg-slate-50 p-5 rounded-2xl border border-slate-200/50 transition-all duration-300">
                <div className="flex justify-between items-baseline mb-2">
                  <span className="text-slate-500 text-xs font-bold uppercase tracking-wider">
                    {billingCycle === 'yearly' ? 'Costo Equivalente' : 'Costo Mensual'}
                  </span>
                  <div className="flex items-baseline gap-1">
                    <span className="text-4xl font-black text-slate-900 transition-all">
                      ${(billingCycle === 'yearly' ? Math.round(fixedEnterprisePrice * 0.8) : fixedEnterprisePrice).toLocaleString()}
                    </span>
                    <span className="text-slate-400 font-bold text-xs uppercase">MXN</span>
                    <span className="text-slate-400 text-xs font-bold">/mes</span>
                  </div>
                </div>
                <div className="flex justify-between items-baseline text-xs border-t border-slate-200/60 pt-2 mt-2">
                  <span className="text-emerald-600 font-bold">
                    {billingCycle === 'yearly' ? 'Facturado anualmente:' : 'Ahorra 20% en Plan Anual:'}
                  </span>
                  <span className="text-slate-700 font-bold whitespace-nowrap">
                    ${(billingCycle === 'yearly' ? Math.round(fixedEnterprisePrice * 12 * 0.8) : Math.round(fixedEnterprisePrice * 12 * 0.8)).toLocaleString()} MXN/año
                  </span>
                </div>
              </div>

              <ul className="space-y-4 mb-8 flex-1">
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-purple-500 shrink-0" size={20}/> Colaboradores Ilimitados</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-purple-500 shrink-0" size={20}/> Base de datos Dedicada y Aislada</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-purple-500 shrink-0" size={20}/> Subdominio Corporativo Propio</li>
                <li className="flex items-start gap-3 text-slate-600 text-sm font-medium"><CheckCircle2 className="text-purple-500 shrink-0" size={20}/> Soporte Técnico 24/7 Dedicado</li>
              </ul>
              <button 
                onClick={() => handleBuy('Enterprise')} 
                className="w-full font-bold py-3.5 bg-slate-900 text-white hover:bg-slate-800 rounded-xl transition-all shadow-sm active:scale-98 text-center"
              >
                Aprovisionar Enterprise
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
          <div className="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl relative text-slate-900 my-auto border border-slate-100 animate-in zoom-in-95 duration-200 max-h-[92vh] flex flex-col">
            
            {/* Header */}
            <div className="bg-slate-50 p-6 border-b border-slate-200 flex justify-between items-center shrink-0">
              <div className="flex items-center gap-2">
                <Building2 className="text-blue-600" size={22} />
                <span className="font-extrabold text-slate-800 text-base">Crear Cuenta Talent 360</span>
              </div>
              <button onClick={() => setShowCheckout(false)} className="text-slate-400 hover:text-slate-600 font-bold text-xl p-1 bg-slate-200/50 rounded-full w-7 h-7 flex items-center justify-center transition-colors">&times;</button>
            </div>
            
            <div className="p-6 overflow-y-auto flex-1 custom-scrollbar">
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
                        <h4 className="font-extrabold text-slate-800 text-lg">Crea tu cuenta de Administrador</h4>
                        <p className="text-xs text-slate-500 leading-relaxed max-w-xs mx-auto">
                          Valida tu identidad de forma instantánea usando Google o completa los datos para registrar tu cuenta.
                        </p>
                      </div>

                      {/* Opción 1: Google Sign-in */}
                      <div className="w-full max-w-xs mx-auto py-1">
                        {import.meta.env.VITE_GOOGLE_CLIENT_ID ? (
                          <div className="w-full flex flex-col items-center">
                            <div id="google-signup-btn-container" className="w-full min-h-[46px] flex items-center justify-center"></div>
                          </div>
                        ) : (
                          <button
                            type="button"
                            onClick={() => {
                              setGoogleEmail('');
                              setGoogleName('');
                              setShowGoogleForm(true);
                            }}
                            className="w-full py-3.5 px-4 border border-slate-200 hover:border-blue-300 hover:bg-slate-50 rounded-2xl font-black text-xs text-slate-700 transition-all flex items-center justify-center gap-2.5 shadow-sm active:scale-98"
                          >
                            <svg className="w-4.5 h-4.5" viewBox="0 0 24 24">
                              <path fill="#EA4335" d="M12 5.04c1.66 0 3.2.57 4.38 1.69l3.27-3.27C17.68 1.54 14.98 1 12 1 7.35 1 3.37 3.67 1.39 7.56l3.89 3.02c.92-2.78 3.51-4.54 6.72-4.54z"/>
                              <path fill="#4285F4" d="M23.49 12.27c0-.81-.07-1.59-.2-2.36H12v4.51h6.46c-.29 1.48-1.14 2.73-2.4 3.58l3.76 2.91c2.2-2.03 3.67-5.02 3.67-8.64z"/>
                              <path fill="#FBBC05" d="M5.28 14.78c-.24-.72-.38-1.49-.38-2.28s.14-1.56.38-2.28L1.39 7.2C.51 8.97 0 10.93 0 13s.51 4.03 1.39 5.8l3.89-3.02z"/>
                              <path fill="#34A853" d="M12 23c3.24 0 5.97-1.07 7.96-2.91l-3.76-2.91c-1.1.74-2.5 1.18-4.2 1.18-3.21 0-5.8-1.76-6.72-4.54L1.39 16.84C3.37 20.33 7.35 23 12 23z"/>
                            </svg>
                            Continuar con Google (Simulador)
                          </button>
                        )}
                      </div>

                      {/* Divisor */}
                      <div className="relative flex py-2 items-center w-full max-w-xs mx-auto">
                        <div className="flex-grow border-t border-slate-200"></div>
                        <span className="flex-shrink mx-3 text-[10px] text-slate-400 font-black uppercase tracking-wider">o regístrate con tu correo</span>
                        <div className="flex-grow border-t border-slate-200"></div>
                      </div>

                      {/* Formulario tradicional */}
                      <form onSubmit={handleTraditionalRegister} className="space-y-4 text-left w-full max-w-xs mx-auto">
                        <div>
                          <label className="text-[10px] font-black text-slate-500 uppercase tracking-wider mb-1 block">Tu Nombre Completo</label>
                          <input 
                            type="text" 
                            required 
                            value={googleName}
                            onChange={e => setGoogleName(e.target.value)}
                            placeholder="Ej. Francisco Vega" 
                            className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all" 
                          />
                        </div>

                        <div>
                          <label className="text-[10px] font-black text-slate-500 uppercase tracking-wider mb-1 block">Tu Correo de Registro</label>
                          <input 
                            type="email" 
                            required 
                            value={googleEmail}
                            onChange={e => setGoogleEmail(e.target.value.toLowerCase().trim())}
                            placeholder="usuario@dominio.com" 
                            className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all" 
                          />
                        </div>

                        <div>
                          <label className="text-[10px] font-black text-slate-500 uppercase tracking-wider mb-1 block">Contraseña</label>
                          <input 
                            type="password" 
                            required 
                            value={signUpPassword}
                            onChange={e => setSignUpPassword(e.target.value)}
                            placeholder="Mínimo 6 caracteres" 
                            className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all" 
                          />
                        </div>
                        
                        {isEmailDuplicated && (
                          <div className="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-800 space-y-2 mt-2">
                            <p className="font-bold flex items-center gap-1">
                              <AlertCircle size={14} className="text-amber-600 shrink-0" />
                              Esta cuenta ya existe
                            </p>
                            <p className="text-[10px] text-slate-500 font-medium leading-relaxed">
                              La dirección de correo electrónico ya está registrada. Puedes iniciar sesión directamente.
                            </p>
                            <button
                              type="button"
                              onClick={() => navigate(`/login?email=${encodeURIComponent(googleEmail)}`)}
                              className="w-full bg-amber-600 hover:bg-amber-700 text-white font-black py-2 rounded-lg text-[10px] transition-all flex items-center justify-center gap-1 shadow-sm"
                            >
                              <LogIn size={11} /> Iniciar Sesión Ahora
                            </button>
                          </div>
                        )}

                        <button
                          type="submit"
                          disabled={isProcessing}
                          className="w-full mt-2 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-2xl text-xs transition-all shadow-lg shadow-blue-600/10 flex items-center justify-center gap-1.5"
                        >
                          {isProcessing ? 'Procesando...' : 'Crear Cuenta y Continuar'}
                        </button>
                      </form>
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
                        {selectedPlan === 'PRO' ? `Profesional (${proEmployeesCount} colab.)` : selectedPlan === 'Enterprise' ? 'Enterprise (Ilimitado)' : 'Plan Gratuito'}
                      </h5>
                    </div>
                    <div className="text-right">
                      <span className="text-lg font-black text-blue-600">
                        ${selectedPlan === 'PRO' 
                          ? (billingCycle === 'yearly' ? yearlyProPrice.toLocaleString() : monthlyProPrice.toLocaleString()) 
                          : selectedPlan === 'Enterprise' 
                            ? (billingCycle === 'yearly' ? Math.round(fixedEnterprisePrice * 12 * 0.8).toLocaleString() : fixedEnterprisePrice.toLocaleString()) 
                            : '0'}
                      </span>
                      <span className="block text-[9px] text-blue-500 font-bold uppercase">
                        {billingCycle === 'yearly' ? 'MXN / año (Pago Anual)' : 'MXN / mes'}
                      </span>
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
                        <div className="bg-slate-50 px-2.5 sm:px-4 py-3 text-[10px] sm:text-xs text-slate-500 font-black border-l border-slate-200 flex items-center shrink-0">.talent360.com</div>
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
              )}
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
