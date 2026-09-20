import { SocialSignIn } from './SocialSignIn';
import React, { useState, useEffect, useRef } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { AlertCircle, ArrowRight, ArrowUpRight, Building2, Check, CheckCheck, CheckCircle2, Fingerprint, Globe2, GraduationCap, Info, LayoutDashboard, ListChecks, Lock, LogIn, MapPin, Menu, MousePointer2, Play, Sparkles, Sprout, Users, X, Zap } from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';
import { PhoneExperience } from './landing-lab/PhoneExperience';
import { ModuleVideoShowcase } from './landing-lab/ModuleVideoShowcase';
import { moduleVideos, youtubeEmbedUrl, type VideoModuleId } from './landing-lab/moduleVideos';
import { PlatformOverview } from './landing-lab/PlatformOverview';
import { LandingFaqModal } from './landing-lab/LandingFaqModal';
import { TalentLogo } from './ui/TalentLogo';
import './landing-lab/landing-lab.css';
import './landing-lab/media-experience.css';
import { LegalModal, type LegalDocType } from './LegalModal';
import { useTarifario } from '../hooks/useTarifario';
import { cotizar, planDelTarifario, pesos } from '../lib/tarifario';

/**
 * Landing pública de Talent 360. La ruta /landing-lab la reutiliza únicamente
 * como espacio de revisión local durante desarrollo.
 */
export const SaaSLandingLab = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const [isLegalModalOpen, setIsLegalModalOpen] = useState(false);
  const [legalModalTab, setLegalModalTab] = useState<LegalDocType>('privacy');
  const [isFaqModalOpen, setIsFaqModalOpen] = useState(false);
  // 2026-07-26 (auditoría en vivo): arrancaba en `true`, es decir la casilla de aceptar el SLA y
  // el Aviso de Privacidad venía pre-marcada. La LFPDPPP exige consentimiento afirmativo del
  // titular; una casilla ya marcada no acredita que la persona haya consentido, y debilita el
  // valor probatorio del propio aviso. Arranca desmarcada — el botón ya estaba condicionado a
  // ella (`disabled={!acceptedTerms}`), así que el flujo sigue funcionando igual.
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const [showCheckout, setShowCheckout] = useState(false);
  const [selectedPlan, setSelectedPlan] = useState<string>('');
  const [proEmployeesCount, setProEmployeesCount] = useState<number>(20); // Professional default
  const [isProcessing, setIsProcessing] = useState(false);
  const [error, setError] = useState('');
  const [registrationStep, setRegistrationStep] = useState<1 | 2>(1);
  const [googleUser, setGoogleUser] = useState<{name: string, email: string, google_id: string} | null>(null);
  const [googleEmail, setGoogleEmail] = useState('');
  const [googleName, setGoogleName] = useState('');
  const [signUpPassword, setSignUpPassword] = useState('');
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly');
  const [isEmailDuplicated, setIsEmailDuplicated] = useState(false);

  const [videoModule, setVideoModule] = useState<VideoModuleId>('onboarding');
  const availableModuleVideos = moduleVideos.filter(video => Boolean(youtubeEmbedUrl(video.youtubeUrl)));
  const productAnchor = availableModuleVideos.length > 0 ? '#lab-producto' : '#lab-reloj';
  const [heroCtaVisible, setHeroCtaVisible] = useState(true);
  const heroCtaRef = useRef<HTMLButtonElement>(null);
  const checkoutRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!heroCtaRef.current) return;
    const observer = new IntersectionObserver(([entry]) => setHeroCtaVisible(entry.isIntersecting), { rootMargin: '-80px 0px 0px 0px' });
    observer.observe(heroCtaRef.current);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    if (!isMobileMenuOpen && !showCheckout && !isLegalModalOpen && !isFaqModalOpen) return;
    const overflowBefore = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const onEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && isFaqModalOpen) {
        setIsFaqModalOpen(false);
      } else if (event.key === 'Escape' && isLegalModalOpen) {
        setIsLegalModalOpen(false);
      } else if (event.key === 'Escape') {
        setIsMobileMenuOpen(false);
        setShowCheckout(false);
      }
    };
    document.addEventListener('keydown', onEscape);
    return () => {
      document.body.style.overflow = overflowBefore;
      document.removeEventListener('keydown', onEscape);
    };
  }, [isMobileMenuOpen, showCheckout, isLegalModalOpen, isFaqModalOpen]);

  useEffect(() => {
    if (!showCheckout || isLegalModalOpen || isFaqModalOpen) return;
    const previousFocus = document.activeElement as HTMLElement | null;
    const dialog = checkoutRef.current;
    dialog?.focus();
    const trapFocus = (event: KeyboardEvent) => {
      if (event.key !== 'Tab' || !dialog) return;
      const elements = Array.from(dialog.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled), [tabindex="0"]')).filter(element => element.getClientRects().length > 0);
      const first = elements[0];
      const last = elements[elements.length - 1];
      if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    dialog?.addEventListener('keydown', trapFocus);
    return () => { dialog?.removeEventListener('keydown', trapFocus); previousFocus?.focus(); };
  }, [showCheckout, isLegalModalOpen, isFaqModalOpen]);

  // Form Data
  const [formData, setFormData] = useState({
    company_name: '',
    subdomain: ''
  });
  const [isSubdomainManual, setIsSubdomainManual] = useState(false);

  const { currentUser, setCurrentUser, setCurrentTier } = useAppStore();

  useEffect(() => {
    const isResume = location.state && location.state.resumeRegistration;
    const isUserWithoutTenant = currentUser && currentUser.tenant_id === null && currentUser.role !== 'Loading' && currentUser.system_role !== 'platform_admin' && currentUser.system_role !== 'support_agent';

    if (isResume || isUserWithoutTenant) {
      const targetUser = (location.state && location.state.user) || currentUser;
      if (targetUser && targetUser.email) {
        setGoogleUser({
          name: targetUser.name || targetUser.email.split('@')[0],
          email: targetUser.email,
          google_id: targetUser.google_id || targetUser.email
        });
      }
      setSelectedPlan('Freemium');
      setRegistrationStep(2);
      setShowCheckout(true);
      if (isResume) {
        navigate(location.pathname, { replace: true });
      }
    }
  }, [location, currentUser, navigate]);

  const handleBuy = (plan: string) => {
    setSelectedPlan(plan);
    setRegistrationStep(1);
    setGoogleUser(null);

    setError('');
    setShowCheckout(true);
  };
  const handleSocialSuccess = ({ user, tenant }: any) => {
    if (user.tenant_id || user.role === 'platform_admin' || user.role === 'support_agent') {
      setCurrentUser({ ...user, system_role: user.role });
      setCurrentTier(tenant?.plan?.toLowerCase() || 'freemium');
      navigate(user.role === 'platform_admin' ? '/superadmin' : user.role === 'support_agent' ? '/soporte' : user.role === 'empleado' ? '/empleado' : '/app');
    } else {
      setGoogleUser({ name: user.name, email: user.email, google_id: user.google_id || user.apple_id || '' });
      setRegistrationStep(2);
    }
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


  const processPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsProcessing(true);
    setError('');

    try {
      const response = await axiosInstance.post('/subscriptions/create-preference', {
        company_name: formData.company_name,
        subdomain: formData.subdomain,
        plan: selectedPlan.toLowerCase(),
        employees: (selectedPlan.toLowerCase() === 'pro' || selectedPlan.toLowerCase() === 'enterprise') ? proEmployeesCount : null,
        billing_cycle: billingCycle,
        // (2026-09-05) La casilla de aceptación ERA TEATRO: habilitaba el botón y su valor moría
        // en el navegador — cero tablas, cero endpoints. Ahora viaja, y el servidor la exige y deja
        // constancia con la versión del aviso, la fecha, la IP y el navegador.
        acepta_aviso: acceptedTerms,
        ...(googleUser ? {
          admin_name: googleUser.name,
          admin_email: googleUser.email
        } : {})
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
      const firstValidationError = err.response?.data?.errors ? (Object.values(err.response.data.errors).flat()[0] as string) : null;
      const errorMsg = firstValidationError || err.response?.data?.error || err.response?.data?.message || err.message || 'Hubo un problema al procesar el registro.';
      setError(errorMsg);
      setIsProcessing(false);
    }
  };

  // Los precios los da el SERVIDOR (2026-09-05). Aquí no hay ni una tarifa escrita a mano.
  //
  // Lo que había antes: las tarifas duplicadas del backend ($29/$24 y $69/$55) y, al pintarlas,
  // el anual calculado de dos maneras distintas según el interruptor — `mensual × 12 × 0.8`
  // ($278.40 por colaborador) en ciclo mensual y `tarifa_anual × 12` ($288) en ciclo anual.
  // El mismo plan con dos precios anuales, y ninguno de los dos avisaba del otro.
  const { tarifario } = useTarifario();
  const planFreemium = planDelTarifario(tarifario, 'freemium');
  const planPro = planDelTarifario(tarifario, 'pro');
  const planEnterprise = planDelTarifario(tarifario, 'enterprise');
  const cotizacionPro = cotizar(planPro, proEmployeesCount, billingCycle);
  const cotizacionEnterprise = cotizar(planEnterprise, proEmployeesCount, billingCycle);

  return (
    <div className="saas-landing talent-landing-lab">

      <a className="lab-skip-link" href="#lab-main">Ir al contenido</a>
      <header className="lab-header">
        <div className="lab-container lab-header-inner">
          <a className="lab-brand" href="#lab-top" aria-label="Talent 360, inicio"><TalentLogo /><span>Talent<span className="lab-brand-number">360</span><i /></span></a>
          <nav className="lab-desktop-nav" aria-label="Navegación principal">
            <a href={productAnchor}>Plataforma</a><a href="#lab-soluciones">Soluciones</a><a href="#pricing">Precios</a>
          </nav>
          <div className="lab-header-actions">
            <button className="lab-login" onClick={() => navigate('/login')}>Iniciar sesión</button>
            <button className={`lab-button lab-nav-cta ${heroCtaVisible ? 'lab-button--outline' : 'lab-button--primary'}`} onClick={() => handleBuy('Freemium')}>Comenzar gratis <ArrowUpRight size={16} /></button>
            <button className="lab-menu-toggle" aria-label={isMobileMenuOpen ? 'Cerrar menú' : 'Abrir menú'} aria-expanded={isMobileMenuOpen} aria-controls="lab-mobile-menu" onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}>{isMobileMenuOpen ? <X /> : <Menu />}</button>
          </div>
        </div>
        {isMobileMenuOpen && <nav id="lab-mobile-menu" className="lab-mobile-nav" aria-label="Navegación móvil">
          {[['Plataforma', productAnchor], ['Soluciones', '#lab-soluciones'], ['Precios', '#pricing']].map(([label, link]) => <a key={label} href={link} onClick={() => setIsMobileMenuOpen(false)}>{label}<ArrowUpRight size={18} /></a>)}
          <a href="/login">Iniciar sesión <LogIn size={18} /></a>
          <button className="lab-button lab-button--primary" onClick={() => { setIsMobileMenuOpen(false); handleBuy('Freemium'); }}>Crear cuenta gratis <ArrowRight size={18} /></button>
        </nav>}
      </header>

      <main id="lab-main">
        <section className="lab-hero" id="lab-top" aria-labelledby="lab-hero-title">
          <div className="lab-container lab-hero-grid">
            <div className="lab-hero-copy">
              <span className="lab-eyebrow"><span className="lab-eyebrow-line" /> MENOS ADMINISTRACIÓN. MÁS EQUIPO.</span>
              <h1 id="lab-hero-title">El sistema operativo para tu <span>Capital Humano.</span></h1>
              <p className="lab-hero-description">Asistencia, expedientes y operación, conectados. Dale a tu equipo las herramientas para trabajar mejor y a ti, la claridad para hacerlo crecer.</p>
              <div className="lab-hero-actions">
                <button ref={heroCtaRef} className="lab-button lab-button--warm lab-button--large" onClick={() => handleBuy('Freemium')}>Crear cuenta gratis <ArrowUpRight size={18} /></button>
                <a className="lab-text-link" href={productAnchor}><span className="lab-play-icon"><Play size={12} fill="currentColor" /></span> Explorar la plataforma</a>
              </div>
              <p className="lab-hero-note"><Check size={14} /> Plan gratuito disponible <span /> Correo o Google</p>
            </div>
            <PlatformOverview />
          </div>
          <div className="lab-container lab-hero-bottom"><span>CREADO PARA CONECTAR TU OPERACIÓN</span><div><Fingerprint size={16} /> Asistencia</div><div><Users size={16} /> Recursos humanos</div><div><ListChecks size={16} /> Operaciones</div><div><GraduationCap size={16} /> Capacitación</div></div>
        </section>

        <section className="lab-dark-band" aria-label="Ventajas de una plataforma conectada">
          <div className="lab-container lab-band-grid">
            <div><span className="lab-band-index">01 / VISIBILIDAD</span><h2>Lo que pasa hoy.<br />En tiempo real.</h2><p>Asistencia e incidencias a la vista para actuar a tiempo.</p></div>
            <div><span className="lab-band-index">02 / ORDEN</span><h2>La información correcta.<br />En un solo lugar.</h2><p>Expedientes y equipos conectados, sin perder el contexto.</p></div>
            <div><span className="lab-band-index">03 / CONTINUIDAD</span><h2>De la entrada.<br />Hasta la prenómina.</h2><p>Una operación que acompaña cada etapa de la jornada.</p></div>
          </div>
        </section>

        <section id="lab-soluciones" className="lab-section lab-solutions" aria-labelledby="lab-solutions-title">
          <div className="lab-container">
            <div className="lab-section-heading"><div><span className="lab-eyebrow">EL TRABAJO FLUYE MEJOR</span><h2 id="lab-solutions-title">Menos piezas sueltas.<br /><span>Más equipo.</span></h2></div><p>Haz espacio para lo importante. Talent 360 reúne el día a día de tu organización en herramientas que trabajan juntas.</p></div>
            <div className="lab-benefit-grid">
              <article className="lab-benefit-card"><div className="lab-benefit-top"><span className="lab-feature-icon"><Fingerprint /></span><span>01</span></div><h3>Cada jornada cuenta.</h3><p>Registra entradas y salidas con biometría y GPS. Consulta lo que sucede en tus sucursales desde un mismo lugar.</p><div className="lab-benefit-illustration lab-location-illustration" aria-hidden="true"><div className="lab-location-path" /><span className="lab-map-pin"><MapPin size={24} /></span><div className="lab-location-label"><Check size={13} /> Registro en sucursal <b>09:00</b></div></div><a href="#lab-reloj">Explorar asistencia <ArrowUpRight size={16} /></a></article>
              <article className="lab-benefit-card"><div className="lab-benefit-top"><span className="lab-feature-icon"><Users /></span><span>02</span></div><h3>Personas, no archivos.</h3><p>Expedientes, puestos y organigramas siempre a mano. Encuentra a cada persona y entiende cómo se conecta tu equipo.</p><div className="lab-benefit-illustration lab-people-illustration" aria-hidden="true"><div className="lab-person-pill"><span className="lab-avatar">CO</span><span>Colaborador<small>Equipo</small></span><Check size={14} /></div><div className="lab-person-pill"><span className="lab-avatar">EQ</span><span>Equipo<small>Área de trabajo</small></span><Check size={14} /></div></div><a href={productAnchor} onClick={() => setVideoModule('onboarding')}>Conocer el directorio <ArrowUpRight size={16} /></a></article>
              <article className="lab-benefit-card"><div className="lab-benefit-top"><span className="lab-feature-icon"><ListChecks /></span><span>03</span></div><h3>Del pendiente al listo.</h3><p>Coordina tareas, da seguimiento a rutinas y acompaña la capacitación. Cada persona sabe cuál es su siguiente paso.</p><div className="lab-benefit-illustration lab-task-illustration" aria-hidden="true"><span><Check size={14} /><s>Apertura de sucursal</s><small>Listo</small></span><span><Check size={14} /><s>Checklist de seguridad</s><small>Listo</small></span><span><i /> Capacitación del equipo<small>En curso</small></span></div><a href={productAnchor} onClick={() => setVideoModule('asistencia')}>Ver la operación <ArrowUpRight size={16} /></a></article>
            </div>
          </div>
        </section>

        {availableModuleVideos.length > 0 && (
          <ModuleVideoShowcase selected={videoModule} onSelect={setVideoModule} videos={availableModuleVideos} />
        )}

        <PhoneExperience onChoosePlan={() => handleBuy('PRO')} />

        <section id="pricing" className="bg-page px-6 py-24" aria-labelledby="lab-pricing-title">
          <div className="mx-auto max-w-7xl">
            <div className="mb-8 text-center">
              <span className="lab-eyebrow">UN PLAN PARA CADA ETAPA</span>
              <h2 id="lab-pricing-title" className="mt-4 text-3xl font-semibold tracking-tight text-text-1 md:text-5xl">Planes transparentes y flexibles</h2>
              <p className="mt-4 font-medium text-text-3">Comienza gratis o escala tu plan según el volumen de colaboradores.</p>
            </div>
            <div className="mb-16 flex select-none items-center justify-center gap-3">
              <span className={`text-sm font-extrabold transition-colors ${billingCycle === 'monthly' ? 'text-accent' : 'text-text-3'}`}>Facturación mensual</span>
              <button type="button" onClick={() => setBillingCycle(billingCycle === 'monthly' ? 'yearly' : 'monthly')} className="relative h-8 w-14 rounded-full bg-navy-100 p-1 transition-colors hover:bg-navy-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2" aria-label="Alternar ciclo de facturación"><span className={`block h-6 w-6 rounded-full bg-accent shadow-sm transition-transform ${billingCycle === 'yearly' ? 'translate-x-6' : 'translate-x-0'}`} /></button>
              <span className={`flex items-center gap-1.5 text-sm font-extrabold transition-colors ${billingCycle === 'yearly' ? 'text-accent' : 'text-text-3'}`}>Facturación anual {tarifario && tarifario.descuento_anual_maximo_pct > 0 && <b className="rounded-full bg-accent px-2 py-0.5 text-xs font-black uppercase tracking-wider text-white">Ahorra hasta {tarifario.descuento_anual_maximo_pct}%</b>}</span>
            </div>
            <div className="mx-auto grid max-w-6xl items-stretch gap-8 md:grid-cols-3">
              <article className="flex flex-col rounded-3xl border border-border/80 bg-white p-8 text-left transition hover:border-navy-600 hover:shadow-[0_1px_3px_rgba(16,24,40,0.08)]">
                <h3 className="mb-2 text-2xl font-semibold tracking-tight text-text-1">Plan Gratuito</h3>
                <p className="mb-6 min-h-[40px] text-sm text-text-3">Para empezar a organizar la información esencial de tu equipo.</p>
                <div className="mb-8 flex min-h-[106px] flex-col justify-center rounded-2xl border border-border/50 bg-page p-5"><div className="flex items-baseline gap-1"><span className="text-5xl font-semibold tracking-tight text-text-1">$0</span><span className="text-xs font-bold uppercase text-text-3">MXN</span><span className="font-bold text-text-3">/mes</span></div><span className="mt-1.5 text-xs font-bold text-text-3">Sin plazos forzosos</span></div>
                <ul className="mb-8 flex flex-1 flex-col gap-3.5">{[
                  planFreemium?.tope_colaboradores ? `Hasta ${planFreemium.tope_colaboradores} colaboradores activos` : 'Colaboradores activos', 'Reloj Checador', 'Directorio Digital', 'Control de entradas y salidas',
                ].map(feature => <li key={feature} className="flex items-start gap-3 text-xs font-semibold text-text-2"><CheckCircle2 className="shrink-0 text-accent" size={18} />{feature}</li>)}</ul>
                <button onClick={() => handleBuy('Freemium')} className="w-full rounded-xl bg-brand-dark py-3.5 text-center font-bold text-white transition hover:bg-navy-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2">Comenzar gratis</button>
              </article>
              <article className="relative flex flex-col rounded-3xl border-2 border-accent bg-white p-8 text-left shadow-[0_1px_3px_rgba(16,24,40,0.08)] md:-translate-y-4">
                <span className="absolute right-8 top-0 flex -translate-y-1/2 items-center gap-1 rounded-full bg-accent px-4 py-1.5 text-xs font-black uppercase tracking-widest text-white"><Sparkles size={12} /> Plan recomendado</span>
                <h3 className="mb-1 text-2xl font-semibold tracking-tight text-text-1">Plan Profesional</h3><p className="mb-6 min-h-[40px] text-sm text-text-3">Más visibilidad para acompañar la operación diaria de tu equipo.</p>
                <div className="mb-6 rounded-2xl border border-border/50 bg-page p-5"><div className="mb-2 flex items-baseline justify-between"><span className="text-xs font-bold uppercase tracking-wider text-text-3">{billingCycle === 'yearly' ? 'Costo equivalente' : 'Costo mensual'}</span><div className="flex items-baseline gap-1"><span className="text-4xl font-semibold tracking-tight text-accent">{cotizacionPro ? `$${pesos(billingCycle === 'yearly' ? cotizacionPro.equivalenteMensualAnual : cotizacionPro.totalMensual)}` : '—'}</span><span className="text-xs font-bold uppercase text-text-3">MXN</span><span className="text-xs font-bold text-text-3">/mes</span></div></div><div className="mt-2 flex items-baseline justify-between border-t border-border/60 pt-2 text-xs"><span className="font-bold text-accent">{cotizacionPro ? (billingCycle === 'yearly' ? 'Facturado anualmente:' : `Ahorra ${planPro?.descuento_anual_pct ?? 0}% en plan anual:`) : 'Tarifa vigente:'}</span><span className="whitespace-nowrap font-bold text-text-2">{cotizacionPro ? `$${pesos(cotizacionPro.totalAnual)} MXN/año` : 'Pendiente de cargar'}</span></div></div>
                <div className="mb-6"><div className="mb-2 flex justify-between text-xs font-bold text-text-2"><span>Colaboradores:</span><span className="rounded-md bg-navy-50 px-2 py-0.5 font-black text-accent">{proEmployeesCount} activos</span></div><input type="range" min="6" max="50" step="1" value={proEmployeesCount} onChange={e => setProEmployeesCount(parseInt(e.target.value))} aria-label="Número de colaboradores para cotizar" className="lab-range-input w-full cursor-pointer appearance-none focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2" /><div className="mt-1 flex justify-between text-xs font-bold text-text-3"><span>6 colab.</span><span>25 colab.</span><span>50 colab.</span></div></div>
                <ul className="mb-8 flex flex-1 flex-col gap-3.5">{['Todo lo del plan gratuito', 'Monitor 360', 'Tareas IA', 'Reportes IA', 'Áreas, puestos y sucursales'].map(feature => <li key={feature} className="flex items-start gap-3 text-xs font-semibold text-text-2"><CheckCircle2 className="shrink-0 text-accent" size={18} />{feature}</li>)}</ul>
                <button onClick={() => handleBuy('PRO')} className="w-full rounded-xl bg-accent py-4 text-center font-black text-white transition hover:bg-navy-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2">{cotizacionPro ? 'Elegir Profesional' : 'Solicitar propuesta'}</button>
              </article>
              <article className="flex flex-col rounded-3xl border border-border/80 bg-white p-8 text-left transition hover:border-navy-600 hover:shadow-[0_1px_3px_rgba(16,24,40,0.08)]"><h3 className="mb-2 text-2xl font-semibold tracking-tight text-text-1">Plan Enterprise</h3><p className="mb-6 min-h-[40px] text-sm text-text-3">Para una operación que necesita sumar más módulos y estructura.</p><div className="mb-8 rounded-2xl border border-border/50 bg-page p-5"><div className="mb-2 flex items-baseline justify-between"><span className="text-xs font-bold uppercase tracking-wider text-text-3">{billingCycle === 'yearly' ? 'Costo equivalente' : 'Costo mensual'}</span><div className="flex items-baseline gap-1"><span className="text-4xl font-semibold tracking-tight text-text-1">{cotizacionEnterprise ? `$${pesos(billingCycle === 'yearly' ? cotizacionEnterprise.equivalenteMensualAnual : cotizacionEnterprise.totalMensual)}` : '—'}</span><span className="text-xs font-bold uppercase text-text-3">MXN</span><span className="text-xs font-bold text-text-3">/mes</span></div></div><div className="mt-2 flex items-baseline justify-between border-t border-border/60 pt-2 text-xs"><span className="font-bold text-accent">{cotizacionEnterprise ? (billingCycle === 'yearly' ? 'Facturado anualmente:' : `Ahorra ${planEnterprise?.descuento_anual_pct ?? 0}% en plan anual:`) : 'Tarifa vigente:'}</span><span className="whitespace-nowrap font-bold text-text-2">{cotizacionEnterprise ? `$${pesos(cotizacionEnterprise.totalAnual)} MXN/año` : 'Pendiente de cargar'}</span></div></div><ul className="mb-8 flex flex-1 flex-col gap-3">{['Todo lo del plan Profesional', 'Bolsa de Trabajo ATS', 'Academia 360', 'Ley Federal del Trabajo', 'Organigrama y SOP'].map(feature => <li key={feature} className="flex items-start gap-2.5 text-xs font-semibold text-text-2"><CheckCircle2 className="shrink-0 text-accent" size={16} />{feature}</li>)}</ul><button onClick={() => handleBuy('Enterprise')} className="w-full rounded-xl bg-brand-dark py-3.5 text-center font-bold text-white transition hover:bg-navy-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2">{cotizacionEnterprise ? 'Elegir Enterprise' : 'Solicitar propuesta'}</button></article>
            </div>
            {tarifario?.es_provisional && <p className="mx-auto mt-6 flex max-w-xl items-center justify-center gap-2 text-center text-xs font-medium text-text-3"><Info size={14} /> La cotización final se confirma antes de contratar.</p>}
            {!tarifario && <p className="mx-auto mt-6 flex max-w-xl items-center justify-center gap-2 text-center text-xs font-medium text-text-3"><Info size={14} /> Estamos consultando la tarifa vigente; no mostramos estimaciones.</p>}
          </div>
        </section>

        <section className="lab-final-cta"><div className="lab-container lab-final-inner"><div><span className="lab-eyebrow">TU EQUIPO TIENE MUCHO POR HACER</span><h2>Dale el espacio<br />para hacerlo mejor.</h2><p>El siguiente capítulo de tu organización empieza con una cuenta.</p><button className="lab-button lab-button--warm lab-button--large" onClick={() => handleBuy('Freemium')}>Crear cuenta gratis <ArrowUpRight size={18} /></button></div><TalentLogo className="lab-final-logo" /></div></section>
      </main>

      {/* REGISTRATION STEP WIZARD MODAL */}
      {showCheckout && (
        <div className="lab-checkout-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-brand-dark/60 backdrop-blur-md animate-in fade-in overflow-y-auto">
          <div ref={checkoutRef} role="dialog" aria-modal="true" aria-labelledby="lab-checkout-title" tabIndex={-1} className="lab-checkout bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl relative text-text-1 my-auto border border-border animate-in zoom-in-95 duration-200 max-h-[92vh] flex flex-col">

            {/* Header */}
            <div className="bg-page p-6 border-b border-border flex justify-between items-center shrink-0">
              <div className="flex items-center gap-2">
                <Building2 className="text-accent" size={22} />
                <span id="lab-checkout-title" className="font-extrabold text-text-1 text-base">Crear cuenta Talent 360</span>
              </div>
              <button aria-label="Cerrar registro" onClick={() => setShowCheckout(false)} className="text-text-3 hover:text-text-2 font-bold text-xl p-1 bg-navy-50 rounded-full w-7 h-7 flex items-center justify-center transition-colors">&times;</button>
            </div>

            <div className="p-6 overflow-y-auto flex-1 custom-scrollbar">
              {error && (
                <div className="mb-4 bg-navy-50 border border-navy-100 text-navy-800 text-xs font-bold p-3 rounded-xl flex gap-1.5 items-start">
                  <AlertCircle size={16} className="shrink-0" aria-hidden="true" /> <span>{error}</span>
                </div>
              )}

              {/* Progress Steps Indicator */}
              <div className="flex items-center justify-center gap-4 mb-6">
                <div className="flex items-center gap-2">
                  <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-black ${registrationStep === 1 ? 'bg-accent text-white' : 'bg-navy-50 text-navy-800'}`}>
                    {registrationStep === 1 ? '1' : '✓'}
                  </div>
                  <span className={`text-xs font-bold ${registrationStep === 1 ? 'text-accent' : 'text-text-3'}`}>Identidad</span>
                </div>
                <div className="w-10 h-0.5 bg-navy-100"></div>
                <div className="flex items-center gap-2">
                  <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-black ${registrationStep === 2 ? 'bg-accent text-white' : 'bg-page text-text-3'}`}>
                    2
                  </div>
                  <span className={`text-xs font-bold ${registrationStep === 2 ? 'text-accent' : 'text-text-3'}`}>Empresa</span>
                </div>
              </div>

              {/* STEP 1: GOOGLE OAUTH FORCED */}
              {registrationStep === 1 && (
                <div className="text-center py-4 space-y-6 animate-in fade-in duration-200">
                  <div className="w-16 h-16 bg-navy-50 text-accent rounded-2xl flex items-center justify-center mx-auto shadow-inner">
                    <Lock size={28} />
                  </div>


                      <div className="space-y-1">
                        <h4 className="font-extrabold text-text-1 text-lg">Crea tu cuenta de Administrador</h4>
                        <p className="text-xs text-text-3 leading-relaxed max-w-xs mx-auto">
                          Usa Google o completa tus datos para registrar tu cuenta.
                        </p>
                      </div>

                      <SocialSignIn onSuccess={handleSocialSuccess} onError={setError} />

                      {/* Divisor */}
                      <div className="relative flex py-2 items-center w-full max-w-xs mx-auto">
                        <div className="flex-grow border-t border-border"></div>
                        <span className="flex-shrink mx-3 text-xs text-text-3 font-black uppercase tracking-wider">o regístrate con tu correo</span>
                        <div className="flex-grow border-t border-border"></div>
                      </div>

                      {/* Formulario tradicional */}
                      <form onSubmit={handleTraditionalRegister} className="space-y-4 text-left w-full max-w-xs mx-auto">
                        <div>
                          <label htmlFor="lab-name" className="text-xs font-black text-text-3 uppercase tracking-wider mb-1 block">Tu Nombre Completo</label>
                          <input
                            type="text"
                            id="lab-name"
                            required
                            value={googleName}
                            onChange={e => setGoogleName(e.target.value)}
                            placeholder="Ej. Nombre Apellido"
                            className="w-full bg-page border border-border rounded-xl px-4 py-2.5 text-xs font-semibold text-text-1 focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:bg-white transition-all"
                          />
                        </div>

                        <div>
                          <label htmlFor="lab-email" className="text-xs font-black text-text-3 uppercase tracking-wider mb-1 block">Tu Correo de Registro</label>
                          <input
                            type="email"
                            id="lab-email"
                            required
                            value={googleEmail}
                            onChange={e => setGoogleEmail(e.target.value.toLowerCase().trim())}
                            placeholder="usuario@dominio.com"
                            className="w-full bg-page border border-border rounded-xl px-4 py-2.5 text-xs font-semibold text-text-1 focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:bg-white transition-all"
                          />
                        </div>

                        <div>
                          <label htmlFor="lab-password" className="text-xs font-black text-text-3 uppercase tracking-wider mb-1 block">Contraseña</label>
                          <input
                            type="password"
                            id="lab-password"
                            required
                            value={signUpPassword}
                            onChange={e => setSignUpPassword(e.target.value)}
                            placeholder="Mínimo 6 caracteres"
                            className="w-full bg-page border border-border rounded-xl px-4 py-2.5 text-xs font-semibold text-text-1 focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:bg-white transition-all"
                          />
                        </div>

                        {isEmailDuplicated && (
                          <div className="bg-navy-50 border border-navy-100 rounded-xl p-3 text-xs text-navy-800 space-y-2 mt-2">
                            <p className="font-bold flex items-center gap-1">
                              <AlertCircle size={14} className="text-navy-600 shrink-0" />
                              Esta cuenta ya existe
                            </p>
                            <p className="text-xs text-text-3 font-medium leading-relaxed">
                              La dirección de correo electrónico ya está registrada. Puedes iniciar sesión directamente.
                            </p>
                            <button
                              type="button"
                              onClick={() => navigate(`/login?email=${encodeURIComponent(googleEmail)}`)}
                              className="w-full bg-navy-800 hover:bg-navy-600 text-white font-black py-2 rounded-lg text-xs transition-all flex items-center justify-center gap-1 shadow-sm"
                            >
                              <LogIn size={11} /> Iniciar Sesión Ahora
                            </button>
                          </div>
                        )}

                        <button
                          type="submit"
                          disabled={isProcessing}
                          className="w-full mt-2 py-3.5 bg-accent hover:bg-navy-800 text-white font-black rounded-2xl text-xs transition-all shadow-lg shadow-accent/10 flex items-center justify-center gap-1.5"
                        >
                          {isProcessing ? 'Procesando...' : 'Crear Cuenta y Continuar'}
                        </button>
                      </form>

                </div>
              )}

              {/* STEP 2: COMPANY DETAILS & SIMULATED CHECKOUT */}
              {registrationStep === 2 && googleUser && (
                <form onSubmit={processPayment} className="space-y-5">

                  {/* Google Authenticated profile badge */}
                  <div className="bg-page border border-border rounded-2xl p-3.5 flex items-center gap-3">
                    <div className="w-10 h-10 rounded-full bg-accent-soft text-accent flex items-center justify-center font-bold text-base shadow-sm">
                      {googleUser.name.charAt(0).toUpperCase()}
                    </div>
                    <div className="text-left">
                      <p className="text-xs font-black text-text-1">{googleUser.name}</p>
                      <p className="text-xs text-text-3 font-semibold">{googleUser.email}</p>
                    </div>
                    {/* 2026-07-26 (auditoría en vivo): esta insignia decía siempre "Cuenta social",
                        incluso cuando el alta se hizo con correo y contraseña — afirmaba una
                        validación con Google que no había ocurrido. Ahora refleja el método real:
                        el registro por correo deja `google_id` vacío, el de Google lo llena. */}
                    <div className={`ml-auto text-xs font-black uppercase tracking-wider px-2 py-0.5 rounded border ${
                      googleUser.google_id
                        ? 'bg-navy-50 text-accent border-border'
                        : 'bg-page text-text-3 border-border'
                    }`}>
                      {googleUser.google_id ? 'Google OK' : 'Correo'}
                    </div>
                  </div>

                  {/* Summary of the selected plan */}
                  <div className="bg-navy-50/50 border border-border p-4 rounded-2xl text-left flex justify-between items-center">
                    <div>
                      <p className="text-xs text-navy-800 font-extrabold uppercase">Plan Seleccionado</p>
                      <h5 className="text-sm font-black text-text-1 mt-0.5">
                        {selectedPlan === 'PRO' ? `Profesional (${proEmployeesCount} colab.)` : selectedPlan === 'Enterprise' ? `Enterprise (${proEmployeesCount} colab.)` : 'Plan Gratuito'}
                      </h5>
                    </div>
                    <div className="text-right">
                      {/* Lo que la caja va a cobrar, calculado por el servidor con la misma
                          fórmula que `SubscriptionController`. */}
                      <span className="text-lg font-black text-accent">
                        ${selectedPlan === 'PRO'
                          ? (cotizacionPro ? pesos(cotizacionPro.totalACobrar) : '—')
                          : selectedPlan === 'Enterprise'
                            ? (cotizacionEnterprise ? pesos(cotizacionEnterprise.totalACobrar) : '—')
                            : '0'}
                      </span>
                      <span className="block text-xs text-accent font-bold uppercase">
                        {billingCycle === 'yearly' ? 'MXN / año (Pago Anual)' : 'MXN / mes'}
                      </span>
                    </div>
                  </div>

                  {/* Fields */}
                  <div className="space-y-4 text-left">
                    <div>
                      <label className="text-xs font-black text-text-3 uppercase tracking-wider mb-1 block">Nombre de la Empresa</label>
                      <input
                        type="text"
                        value={formData.company_name}
                        onChange={e => {
                          const val = e.target.value;
                          const autoSub = val.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9-]/g, '').slice(0, 30);
                          setFormData(prev => ({
                            ...prev,
                            company_name: val,
                            subdomain: isSubdomainManual ? prev.subdomain : autoSub
                          }));
                        }}
                        required
                        placeholder="Ej. DashComputer"
                        className="w-full bg-white px-4 py-3 border border-border rounded-xl font-medium outline-none focus:ring-2 focus-visible:ring-focus-ring text-sm"
                      />
                    </div>
                    <div>
                      <label className="text-xs font-black text-text-3 uppercase tracking-wider mb-1 block">Identificador único de tu empresa</label>
                      <div className="flex border border-border rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-focus-ring bg-white">
                        <input
                          type="text"
                          value={formData.subdomain}
                          onChange={e => {
                            setIsSubdomainManual(true);
                            setFormData({...formData, subdomain: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '')});
                          }}
                          required
                          placeholder="dashcomputer"
                          className="w-full bg-white px-4 py-3 font-medium outline-none text-sm text-text-1"
                        />
                      </div>
                      <p className="text-xs text-accent bg-navy-50/70 border border-border rounded-xl p-2.5 mt-2 font-bold flex items-start gap-1.5 leading-normal">
                        <Globe2 size={14} className="shrink-0" aria-hidden="true" />
                        <span>Este identificador separa los datos de tu empresa. El acceso real para administradores y empleados es <strong className="font-black text-navy-800">https://talent360.com.mx/login</strong>.</span>
                      </p>
                    </div>

                    {/* Aceptación de Términos y Privacidad */}
                    <div className="bg-page border border-border/80 rounded-xl p-3 flex items-start gap-2 text-left">
                      <input
                        type="checkbox"
                        id="lab_accept_terms"
                        checked={acceptedTerms}
                        onChange={e => setAcceptedTerms(e.target.checked)}
                        className="mt-0.5 rounded text-accent focus-visible:ring-focus-ring cursor-pointer"
                        required
                      />
                      <label htmlFor="lab_accept_terms" className="text-[10.5px] text-text-2 leading-tight font-medium cursor-pointer">
                        Acepto los{' '}
                        <button
                          type="button"
                          onClick={() => { setLegalModalTab('terms'); setIsLegalModalOpen(true); }}
                          className="text-accent font-bold hover:underline"
                        >
                          Términos del Servicio (SLA)
                        </button>{' '}
                        y el{' '}
                        <button
                          type="button"
                          onClick={() => { setLegalModalTab('privacy'); setIsLegalModalOpen(true); }}
                          className="text-accent font-bold hover:underline"
                        >
                          Aviso de Privacidad
                        </button>{' '}
                        conforme a la LFPDPPP.
                      </label>
                    </div>
                  </div>

                  <button
                    type="submit"
                    disabled={isProcessing || !acceptedTerms}
                    className={`w-full text-white font-black py-4 rounded-2xl shadow-lg shadow-accent/20 transition-all flex justify-center items-center gap-2 text-sm ${isProcessing || !acceptedTerms ? 'bg-text-3 cursor-not-allowed opacity-60' : 'bg-accent hover:bg-navy-800'}`}
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


      <footer className="lab-footer"><div className="lab-container">
        <div className="lab-footer-top"><a className="lab-brand" href="#lab-top" aria-label="Talent 360, volver al inicio"><TalentLogo /><span>Talent<span className="lab-brand-number">360</span><i /></span></a><p>Personas conectadas. Operaciones claras.</p><a className="lab-back-top" href="#lab-top">Volver arriba <ArrowUpRight size={16} /></a></div>
        <div className="lab-footer-bottom"><span>© {new Date().getFullYear()} Talent 360.</span><div><button onClick={() => setIsFaqModalOpen(true)}>Preguntas frecuentes</button><button onClick={() => { setLegalModalTab('privacy'); setIsLegalModalOpen(true); }}>Aviso de privacidad</button><button onClick={() => { setLegalModalTab('terms'); setIsLegalModalOpen(true); }}>Términos del servicio</button><button onClick={() => { setLegalModalTab('arco'); setIsLegalModalOpen(true); }}>Derechos ARCO</button></div><span>Hecho para tu equipo.</span></div>
      </div></footer>
      <LegalModal isOpen={isLegalModalOpen} onClose={() => setIsLegalModalOpen(false)} defaultTab={legalModalTab} />
      <LandingFaqModal isOpen={isFaqModalOpen} onClose={() => setIsFaqModalOpen(false)} />
    </div>
  );
};
