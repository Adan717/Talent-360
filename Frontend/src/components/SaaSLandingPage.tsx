import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ShieldCheck, Zap, Users, GraduationCap, CheckCircle2, ChevronRight, Lock } from 'lucide-react';
import { CompanyOnboardingSettings } from './CompanyOnboardingSettings';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';

export const SaaSLandingPage = () => {
  const navigate = useNavigate();
  const [showCheckout, setShowCheckout] = useState(false);
  const [selectedPlan, setSelectedPlan] = useState<string>('');
  const [employeesCount, setEmployeesCount] = useState<number>(20);
  const [isProcessing, setIsProcessing] = useState(false);
  const [showOnboarding, setShowOnboarding] = useState(false);
  const [error, setError] = useState('');

  // Form Data
  const [formData, setFormData] = useState({
    company_name: '',
    subdomain: '',
    admin_name: '',
    admin_email: '',
    admin_password: ''
  });

  const { setCurrentUser, setCurrentTier } = useAppStore();

  const handleBuy = (plan: string) => {
    setSelectedPlan(plan);
    setShowCheckout(true);
  };

  const processPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsProcessing(true);
    setError('');
    
    try {
      const response = await axiosInstance.post('/subscriptions/create-preference', {
        company_name: formData.company_name,
        subdomain: formData.subdomain,
        admin_name: formData.admin_name,
        admin_email: formData.admin_email,
        admin_password: formData.admin_password,
        plan: selectedPlan.toLowerCase(),
        success_url: window.location.origin + '/login?payment=success',
        failure_url: window.location.origin + '/register?payment=failed',
        pending_url: window.location.origin + '/register?payment=pending'
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
        // Paid: Redirect to payment gateway
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
          <h1 className="text-3xl font-black text-slate-900">¡Pago Exitoso!</h1>
          <p className="text-slate-500 mt-2">Tu instancia de Talent 360 ha sido aprovisionada y configurada con datos Demo para que puedas explorar.</p>
        </div>
        <div className="w-full max-w-2xl bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-200">
          <CompanyOnboardingSettings onComplete={() => navigate('/app')} />
        </div>
      </div>
    );
  }

  const proPricePerUser = 5;
  const entPricePerUser = 12;

  return (
    <div className="min-h-screen bg-slate-950 font-sans text-slate-50 selection:bg-blue-500/30">
      
      <header className="fixed top-0 left-0 right-0 z-40 bg-slate-950/80 backdrop-blur-md border-b border-slate-800">
        <div className="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center shadow-[0_0_20px_rgba(37,99,235,0.4)]">
              <span className="text-white font-black text-xl">T</span>
            </div>
            <h1 className="text-xl font-black tracking-tight text-white">
              Talent <span className="text-blue-500">360</span>
            </h1>
          </div>
          <div className="hidden md:flex gap-8 text-sm font-bold text-slate-400">
            <a href="#features" className="hover:text-white transition-colors">Plataforma</a>
            <a href="#pricing" className="hover:text-white transition-colors">Precios por Usuario</a>
            <a href="#" className="hover:text-white transition-colors">Empresas</a>
          </div>
          <div className="flex gap-4">
            <button onClick={() => navigate('/login')} className="text-sm font-bold text-slate-300 hover:text-white transition-colors hidden md:block">Login (Subdominio)</button>
            <button onClick={() => handleBuy('Freemium')} className="bg-white text-slate-950 px-5 py-2.5 rounded-xl text-sm font-black hover:bg-slate-200 transition-colors shadow-[0_0_20px_rgba(255,255,255,0.2)]">
              Empezar Gratis
            </button>
          </div>
        </div>
      </header>

      <section className="relative pt-40 pb-20 px-6 overflow-hidden">
        <div className="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-blue-600/20 rounded-full blur-[120px] opacity-50 pointer-events-none"></div>
        <div className="absolute top-1/2 right-0 w-[600px] h-[600px] bg-purple-600/20 rounded-full blur-[100px] opacity-40 pointer-events-none"></div>

        <div className="max-w-5xl mx-auto text-center relative z-10 animate-in slide-in-from-bottom-4">
          <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-900 border border-slate-800 text-xs font-bold text-blue-400 mb-8 shadow-inner">
            <Zap size={14} className="text-blue-500" /> Nuevo: Arquitectura SaaS Aislada
          </div>
          <h2 className="text-5xl md:text-7xl font-black tracking-tight mb-8 leading-tight">
            El sistema operativo para <br className="hidden md:block"/>
            <span className="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-400">tu Capital Humano</span>
          </h2>
          <p className="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto mb-10 font-medium leading-relaxed">
            Desde la atracción de talento hasta la nómina. Talent 360 escala con tu empresa pagando un modelo flexible y transparente "por empleado activo".
          </p>
          <div className="flex flex-col sm:flex-row gap-4 justify-center items-center">
            <button onClick={() => {
              document.getElementById('pricing')?.scrollIntoView({ behavior: 'smooth' });
            }} className="w-full sm:w-auto bg-blue-600 text-white px-8 py-4 rounded-2xl font-black text-lg hover:bg-blue-700 transition-all shadow-[0_0_30px_rgba(37,99,235,0.4)] flex items-center justify-center gap-2">
              Ver Planes de Precios <ChevronRight size={20} />
            </button>
          </div>
        </div>
      </section>

      {/* SECCIÓN DE VIDEOS DEMOSTRATIVOS */}
      <section className="py-20 px-6 relative z-10 bg-slate-900/30 border-t border-slate-900">
        <div className="max-w-7xl mx-auto">
          <div className="text-center mb-16">
            <h3 className="text-3xl md:text-5xl font-black mb-4">Explora la Plataforma en Acción</h3>
            <p className="text-slate-400 font-medium max-w-2xl mx-auto">Mira demostraciones interactivas de nuestros módulos clave diseñadas para asombrar a tus colaboradores.</p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
            {/* Card 1 */}
            <div className="group bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden hover:border-blue-500/50 hover:shadow-[0_0_30px_rgba(37,99,235,0.15)] transition-all duration-300">
              <div className="aspect-video bg-slate-950 relative overflow-hidden flex items-center justify-center">
                {/* Simulated Thumbnail */}
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950 to-transparent z-10 opacity-60"></div>
                <div className="absolute inset-0 bg-blue-600/10 mix-blend-overlay group-hover:bg-blue-600/20 transition-colors"></div>
                
                {/* Play Button Icon */}
                <div className="w-14 h-14 bg-blue-600 border border-blue-500 rounded-full flex items-center justify-center text-white relative z-20 group-hover:scale-110 shadow-lg transition-transform duration-300">
                  <span className="text-xl ml-1">▶</span>
                </div>
                
                {/* Visual Placeholder */}
                <div className="absolute inset-0 flex items-center justify-center text-slate-800 font-black text-6xl select-none tracking-widest opacity-25">
                  WIZARD
                </div>
              </div>
              <div className="p-6 text-left">
                <span className="text-[10px] font-black text-blue-400 uppercase tracking-widest">Tutorial Configuración</span>
                <h4 className="font-bold text-white text-base mt-1 mb-2">Onboarding y Flujo Inicial</h4>
                <p className="text-slate-400 text-xs leading-relaxed">
                  Mira cómo el asistente interactivo (Wizard) guía a los administradores desde la sucursal hasta la primera asistencia.
                </p>
              </div>
            </div>

            {/* Card 2 */}
            <div className="group bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden hover:border-amber-500/50 hover:shadow-[0_0_30px_rgba(245,158,11,0.15)] transition-all duration-300">
              <div className="aspect-video bg-slate-950 relative overflow-hidden flex items-center justify-center">
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950 to-transparent z-10 opacity-60"></div>
                <div className="absolute inset-0 bg-amber-600/10 mix-blend-overlay group-hover:bg-amber-600/20 transition-colors"></div>
                
                <div className="w-14 h-14 bg-amber-500 border border-amber-400 rounded-full flex items-center justify-center text-white relative z-20 group-hover:scale-110 shadow-lg transition-transform duration-300">
                  <span className="text-xl ml-1">▶</span>
                </div>
                
                <div className="absolute inset-0 flex items-center justify-center text-slate-800 font-black text-6xl select-none tracking-widest opacity-25">
                  CLOCK
                </div>
              </div>
              <div className="p-6 text-left">
                <span className="text-[10px] font-black text-amber-400 uppercase tracking-widest">Funciones Pro</span>
                <h4 className="font-bold text-white text-base mt-1 mb-2">Reloj Checador y Aforos Pro</h4>
                <p className="text-slate-400 text-xs leading-relaxed">
                  Descubre la experiencia Pro de asistencia, comedor con aforo en tiempo real y asignación de encargados de llaves.
                </p>
              </div>
            </div>

            {/* Card 3 */}
            <div className="group bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden hover:border-purple-500/50 hover:shadow-[0_0_30px_rgba(168,85,247,0.15)] transition-all duration-300">
              <div className="aspect-video bg-slate-950 relative overflow-hidden flex items-center justify-center">
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950 to-transparent z-10 opacity-60"></div>
                <div className="absolute inset-0 bg-purple-600/10 mix-blend-overlay group-hover:bg-purple-600/20 transition-colors"></div>
                
                <div className="w-14 h-14 bg-purple-600 border border-purple-500 rounded-full flex items-center justify-center text-white relative z-20 group-hover:scale-110 shadow-lg transition-transform duration-300">
                  <span className="text-xl ml-1">▶</span>
                </div>
                
                <div className="absolute inset-0 flex items-center justify-center text-slate-800 font-black text-6xl select-none tracking-widest opacity-25">
                  ATS
                </div>
              </div>
              <div className="p-6 text-left">
                <span className="text-[10px] font-black text-purple-400 uppercase tracking-widest">Módulo Reclutamiento</span>
                <h4 className="font-bold text-white text-base mt-1 mb-2">Portal de Empleos y ATS</h4>
                <p className="text-slate-400 text-xs leading-relaxed">
                  Conoce cómo publicar vacantes de forma pública y calificar automáticamente a los candidatos usando IA.
                </p>
              </div>
            </div>

          </div>
        </div>
      </section>

      <section id="pricing" className="py-24 px-6 relative z-10 bg-slate-950">
        <div className="max-w-7xl mx-auto">
          <div className="text-center mb-16">
            <h3 className="text-3xl md:text-5xl font-black mb-4">Planes Sencillos y Transparentes</h3>
            <p className="text-slate-400 font-medium">Elige el plan ideal para tu organización. Ajusta o cambia tu plan en cualquier momento.</p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
            
            <div className="bg-slate-900/50 border border-slate-800 rounded-3xl p-8 flex flex-col hover:border-slate-700 transition-colors text-left">
              <h4 className="text-2xl font-black text-white mb-2">Gratuito</h4>
              <p className="text-slate-400 text-sm mb-6 min-h-[40px]">Para pequeños negocios que comienzan su digitalización.</p>
              <div className="mb-8 flex items-baseline gap-1">
                <span className="text-5xl font-black text-white">$0</span>
                <span className="text-slate-500 font-bold text-xs uppercase">MXN</span>
                <span className="text-slate-500 font-bold">/mes</span>
              </div>
              <ul className="space-y-4 mb-8 flex-1">
                <li className="flex items-start gap-3 text-slate-300 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Hasta 10 Colaboradores Activos</li>
                <li className="flex items-start gap-3 text-slate-300 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Reloj Checador Básico (Sin comedor Pro)</li>
                <li className="flex items-start gap-3 text-slate-300 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> Directorio de Colaboradores y Puestos</li>
                <li className="flex items-start gap-3 text-slate-300 text-sm font-medium"><CheckCircle2 className="text-emerald-500 shrink-0" size={20}/> 30 Días de Prueba de Módulos Pro</li>
              </ul>
              <button 
                onClick={() => handleBuy('Freemium')} 
                className="w-full font-bold py-3 bg-slate-800 text-white hover:bg-slate-700 rounded-xl transition-colors"
              >
                Comenzar Gratis
              </button>
            </div>

            <div className="bg-gradient-to-b from-blue-900/40 to-slate-900/80 border border-blue-500/50 rounded-3xl p-8 flex flex-col relative transform md:-translate-y-4 shadow-[0_0_50px_rgba(37,99,235,0.15)] text-left">
              <div className="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-blue-600 text-white text-[10px] font-black uppercase tracking-widest px-4 py-1 rounded-full shadow-md">
                Más Popular
              </div>
              <h4 className="text-2xl font-black text-white mb-2">Profesional</h4>
              <p className="text-blue-200/70 text-sm mb-6 min-h-[40px]">Todo el poder de la plataforma en base de datos compartida optimizada.</p>
              <div className="mb-8 flex items-baseline gap-1">
                <span className="text-5xl font-black text-white">$99</span>
                <span className="text-blue-300/50 font-bold text-xs uppercase">MXN</span>
                <span className="text-blue-300/50 font-bold">/mes</span>
              </div>
              <ul className="space-y-4 mb-8 flex-1">
                <li className="flex items-start gap-3 text-slate-100 text-sm font-medium"><CheckCircle2 className="text-blue-400 shrink-0" size={20}/> Colaboradores Ilimitados (Hasta 50)</li>
                <li className="flex items-start gap-3 text-slate-100 text-sm font-medium"><CheckCircle2 className="text-blue-400 shrink-0" size={20}/> Todos los Módulos Incluidos (ATS, LMS, Reportes)</li>
                <li className="flex items-start gap-3 text-slate-100 text-sm font-medium"><CheckCircle2 className="text-blue-400 shrink-0" size={20}/> Reloj Checador Pro (Comedor e Integración)</li>
                <li className="flex items-start gap-3 text-slate-100 text-sm font-medium"><CheckCircle2 className="text-blue-400 shrink-0" size={20}/> Respaldos y Sincronización Google Drive</li>
              </ul>
              <button onClick={() => handleBuy('PRO')} className="w-full bg-blue-600 text-white font-black py-4 rounded-xl hover:bg-blue-700 transition-colors shadow-lg">Suscribirse Profesional</button>
            </div>

            <div className="bg-slate-900/50 border border-slate-800 rounded-3xl p-8 flex flex-col hover:border-slate-700 transition-colors text-left">
              <h4 className="text-2xl font-black text-white mb-2">Empresas</h4>
              <p className="text-slate-400 text-sm mb-6 min-h-[40px]">Base de datos totalmente dedicada y aislada para corporativos.</p>
              <div className="mb-8 flex items-baseline gap-1">
                <span className="text-5xl font-black text-white">$499</span>
                <span className="text-slate-500 font-bold text-xs uppercase">MXN</span>
                <span className="text-slate-500 font-bold">/mes</span>
              </div>
              <ul className="space-y-4 mb-8 flex-1">
                <li className="flex items-start gap-3 text-slate-300 text-sm font-medium"><CheckCircle2 className="text-purple-500 shrink-0" size={20}/> Colaboradores Ilimitados sin restricciones</li>
                <li className="flex items-start gap-3 text-slate-300 text-sm font-medium"><CheckCircle2 className="text-purple-500 shrink-0" size={20}/> Infraestructura de BD Dedicada y Aislada</li>
                <li className="flex items-start gap-3 text-slate-300 text-sm font-medium"><CheckCircle2 className="text-purple-500 shrink-0" size={20}/> Subdominio Corporativo Propio</li>
                <li className="flex items-start gap-3 text-slate-300 text-sm font-medium"><CheckCircle2 className="text-purple-500 shrink-0" size={20}/> Soporte Técnico 24/7 Dedicado</li>
              </ul>
              <button onClick={() => handleBuy('Enterprise')} className="w-full bg-slate-800 text-white font-bold py-3 rounded-xl hover:bg-slate-700 transition-colors">Aprovisionar Empresas</button>
            </div>

          </div>
        </div>
      </section>

      {showCheckout && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-in fade-in overflow-y-auto pt-10">
          <div className="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl relative text-slate-900 my-auto">
            <div className="bg-slate-50 p-6 border-b border-slate-200 flex justify-between items-center">
              <div className="flex items-center gap-2">
                <ShieldCheck className="text-emerald-600" size={24} />
                <span className="font-bold text-slate-700">Registro de Cuenta Segura</span>
              </div>
              <button onClick={() => setShowCheckout(false)} className="text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
            </div>
            
            <form onSubmit={processPayment} className="p-6">
              
              {error && (
                <div className="mb-4 bg-rose-50 border border-rose-200 text-rose-600 text-sm font-bold p-3 rounded-xl">
                  {error}
                </div>
              )}

              <div className="mb-6 bg-blue-50 border border-blue-100 p-4 rounded-xl flex justify-between items-center text-left">
                <div>
                  <p className="text-sm text-blue-800 font-bold">Plan <span className="uppercase">{selectedPlan === 'PRO' ? 'Profesional' : selectedPlan === 'Enterprise' ? 'Empresas' : 'Gratuito'}</span></p>
                  <p className="text-xs text-blue-600 mt-1">
                    {selectedPlan === 'Freemium' ? 'Hasta 10 colaboradores' : selectedPlan === 'PRO' ? 'Colaboradores ilimitados (Max 50)' : 'Colaboradores ilimitados (BD Aislada)'}
                  </p>
                </div>
                <div className="text-right">
                  <span className="text-2xl font-black text-blue-700">
                    ${selectedPlan === 'Freemium' ? '0' : selectedPlan === 'PRO' ? '99' : '499'}
                  </span>
                  <span className="block text-[10px] text-blue-600 font-bold uppercase">MXN / mes</span>
                </div>
              </div>

              <div className="space-y-4 mb-8">
                <div>
                  <label className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">Nombre de la Empresa</label>
                  <input type="text" value={formData.company_name} onChange={e => setFormData({...formData, company_name: e.target.value})} required placeholder="Talent360 SA de CV" className="w-full bg-white px-4 py-3 border border-slate-300 rounded-lg font-medium outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">Subdominio Deseado</label>
                  <div className="flex border border-slate-300 rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-blue-500">
                    <input type="text" value={formData.subdomain} onChange={e => setFormData({...formData, subdomain: e.target.value})} required placeholder="talent360" className="w-full bg-white px-4 py-3 font-medium outline-none" />
                    <div className="bg-slate-100 px-4 py-3 text-sm text-slate-500 font-bold border-l border-slate-300">.talent360.com</div>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">Tu Nombre</label>
                    <input type="text" value={formData.admin_name} onChange={e => setFormData({...formData, admin_name: e.target.value})} required placeholder="Juan Pérez" className="w-full bg-white px-4 py-3 border border-slate-300 rounded-lg font-medium outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">Contraseña</label>
                    <input type="password" value={formData.admin_password} onChange={e => setFormData({...formData, admin_password: e.target.value})} required placeholder="••••••" minLength={6} className="w-full bg-white px-4 py-3 border border-slate-300 rounded-lg font-medium outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                </div>

                <div>
                  <label className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">Correo de Administrador</label>
                  <input type="email" value={formData.admin_email} onChange={e => setFormData({...formData, admin_email: e.target.value})} required placeholder="admin@talent360.com" className="w-full bg-white px-4 py-3 border border-slate-300 rounded-lg font-medium outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                
                {selectedPlan !== 'Freemium' && (
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1 block">Tarjeta de Crédito</label>
                    <div className="relative">
                      <input type="text" required placeholder="4242 4242 4242 4242" className="w-full bg-white border border-slate-300 rounded-lg pl-10 pr-4 py-3 font-medium focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                      <Lock size={16} className="absolute left-3 top-3.5 text-slate-400" />
                    </div>
                  </div>
                )}
              </div>

              <button 
                type="submit" 
                disabled={isProcessing}
                className={`w-full text-white font-black py-4 rounded-xl shadow-lg transition-all flex justify-center items-center gap-2 ${isProcessing ? 'bg-indigo-400 cursor-not-allowed' : 'bg-indigo-600 hover:bg-indigo-700'}`}
              >
                {isProcessing ? (
                  <><span className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></span> Creando Base de Datos...</>
                ) : (
                  <>Crear Cuenta {selectedPlan !== 'Freemium' && 'y Pagar'}</>
                )}
              </button>
            </form>
          </div>
        </div>
      )}

    </div>
  );
};
