import React, { useState, useEffect } from 'react';
import { useSearchParams, useParams } from 'react-router-dom';
import { Briefcase, CalendarDays, CircleDollarSign, CheckCircle2, Building2, ArrowRight, X, Share2, Copy, Check, Send, ChevronDown, Mail, Phone } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { LegalModal, type LegalDocType } from './LegalModal';

const Facebook = ({ size = 16 }: { size?: number }) => (
  <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
);
const Instagram = ({ size = 16 }: { size?: number }) => (
  <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
);
const Linkedin = ({ size = 16 }: { size?: number }) => (
  <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect width="4" height="12" x="2" y="9"/><circle cx="4" cy="4" r="2"/></svg>
);

// Tipos base para la vista pública
interface PublicVacancy {
  id: number;
  title: string;
  description: string;
  requirements: string[];
  image_url: string;
  work_type: string;
  schedule: string;
  salary_range: string;
}

function splitSchedule(scheduleText: string): { days: string; hours: string } {
  if (!scheduleText) return { days: '', hours: '' };
  
  // Try to find where the time part starts.
  // Time parts usually start with a digit, or 'de ' followed by a digit, or just 'de'/'desde'
  // Let's find the index of the first number in the string
  const match = scheduleText.match(/(?:de\s+)?\d/i);
  if (match && match.index !== undefined) {
    const days = scheduleText.substring(0, match.index).trim();
    const hours = scheduleText.substring(match.index).trim();
    return { days, hours };
  }
  
  // Fallback: if no digit, return it as days
  return { days: scheduleText, hours: '' };
}

interface WebPublicaProps {
  previewTenant?: {
    name: string;
    logo_url: string;
    brand_color: string;
    public_portal_enabled: boolean;
  };
  previewVacancies?: any[];
}

export function WebPublica({ previewTenant, previewVacancies }: WebPublicaProps = {}) {
  const [searchParams] = useSearchParams();
  const { slug: routeSlug } = useParams();
  const currentUser = useAppStore(state => state.currentUser);
  
  const activeSlug = routeSlug || currentUser?.tenant?.public_slug || currentUser?.tenant?.subdomain || 'default';
  // En el portal público NO hay sesión, así que aquí no se puede saber de qué empresa es la bolsa:
  // la dirección (`slug`) es el único dato fiable, y el servidor resuelve la empresa a partir de
  // ella. Antes se mandaba un `tenant_id` que caía SIEMPRE al literal '1', y el correo de quien
  // pedía aviso en la bolsa de cualquier cliente se archivaba en la empresa 1.

  const [selectedVacancy, setSelectedVacancy] = useState<any>(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [isLegalModalOpen, setIsLegalModalOpen] = useState(false);
  const [legalModalTab, setLegalModalTab] = useState<LegalDocType>('privacy');
  const [vacancies, setVacancies] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showInduction, setShowInduction] = useState(false);
  const [showWelcomeModal, setShowWelcomeModal] = useState(true);
  
  const [tenant, setTenant] = useState<any>({
    name: 'Talent360',
    logo_url: '',
    brand_color: '#3b82f6',
    public_portal_enabled: true
  });

  const [candidateForm, setCandidateForm] = useState({
    name: '',
    email: '',
    phone: '',
    answers: { q1: 'Puntualidad' }
  });

  const [alertForm, setAlertForm] = useState({ email: '', jobRoleName: '' });
  const [submittingAlert, setSubmittingAlert] = useState(false);
  const [alertSuccess, setAlertSuccess] = useState(false);

  const [alertVacancy, setAlertVacancy] = useState<any>(null);
  const [shareVacancy, setShareVacancy] = useState<any>(null);
  const [showSocialAuthModal, setShowSocialAuthModal] = useState(false);
  const [copiedLink, setCopiedLink] = useState(false);

  const [expandedVacancies, setExpandedVacancies] = useState<Record<number, boolean>>({});

  /** Vídeo de bienvenida configurado por la empresa (vacío = no hay modal). Acepta el enlace
   *  normal de YouTube y lo convierte al de incrustar; cualquier otra URL se usa tal cual. */
  const videoDeBienvenida = (() => {
    const url = (tenant?.custom_settings?.welcome_video_url || '').trim();
    if (!url) return '';
    const yt = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([\w-]{11})/);
    return yt ? `https://www.youtube.com/embed/${yt[1]}` : url;
  })();

  const toggleRequirements = (id: number) => {
    setExpandedVacancies(prev => ({
      ...prev,
      [id]: !prev[id]
    }));
  };

  const handlePostularse = (vacancy: any) => {
    setSelectedVacancy(vacancy);
    setShowSocialAuthModal(true);
    // El formulario abre VACÍO: ya no se prellena con identidades inventadas.
    setCandidateForm({ name: '', email: '', phone: '', answers: { q1: 'Puntualidad' } });
  };

  const handleNotificarme = (vacancy: any) => {
    setAlertVacancy(vacancy);
    setAlertSuccess(false);
    setAlertForm({ email: '', jobRoleName: vacancy.title });
  };

  const handleShare = (vacancy: any) => {
    setShareVacancy(vacancy);
    setCopiedLink(false);
  };

  const handleAlertSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!alertForm.email.trim() || !alertForm.jobRoleName.trim()) {
      alert("Por favor, completa todos los campos.");
      return;
    }
    setSubmittingAlert(true);
    try {
      await axiosInstance.post('/public/vacancy-alerts', {
        email: alertForm.email,
        job_role_name: alertForm.jobRoleName,
        slug: activeSlug
      });
      setAlertSuccess(true);
      setAlertForm({ email: '', jobRoleName: '' });
    } catch (err) {
      console.error("Error creating vacancy alert:", err);
      alert("Hubo un error al guardar tu alerta. Intenta nuevamente.");
    } finally {
      setSubmittingAlert(false);
    }
  };

  useEffect(() => {
    if (previewTenant) {
      setTenant(previewTenant);
      setVacancies(previewVacancies || []);
      setLoading(false);
      return;
    }

    setLoading(true);
    axiosInstance.get(`/public/vacancies/${activeSlug}`)
      .then(res => {
        if (res.data.tenant) {
          setTenant(res.data.tenant);
        }
        const vacs = res.data.vacancies || [];
        setVacancies(vacs);
        setLoading(false);

        // Auto-select if vacancy_id is in params
        const vId = searchParams.get('vacancy_id');
        if (vId) {
          const idNum = Number(vId);
          const found = vacs.find((v: any) => v.id === idNum);
          if (found) {
            setSelectedVacancy(found);
            setShowDetailModal(true);
          }
        }
      })
      .catch(err => {
        console.error("Error fetching vacancies", err);
        setLoading(false);
      });
  }, [activeSlug, searchParams, previewTenant, previewVacancies]);

  if (loading) {
    return <div className="min-h-[400px] flex items-center justify-center font-bold text-slate-500">Cargando Bolsa de Trabajo...</div>;
  }

  if (!tenant.public_portal_enabled && !previewTenant) {
    return (
      <div className="w-full min-h-[500px] flex flex-col items-center justify-center bg-slate-50 p-8 text-center rounded-3xl border border-slate-200">
        <div className="w-20 h-20 bg-slate-100 text-slate-400 rounded-3xl flex items-center justify-center mb-6 border border-slate-200 shadow-sm animate-pulse">
          <Briefcase size={36} />
        </div>
        <h2 className="text-3xl font-black text-slate-800 mb-2 tracking-tight">Portal no disponible</h2>
        <p className="text-slate-500 max-w-md leading-relaxed text-sm font-medium">
          El portal público de vacantes de esta organización no está activo en este momento. Por favor, vuelve a intentarlo más tarde o ponte en contacto con Recursos Humanos.
        </p>
      </div>
    );
  }

  const customSettings = tenant.custom_settings || {};
  const headerTitle = customSettings.header_title || `Únete a ${tenant.name}`;
  const headerSubtitle = customSettings.header_subtitle || "No solo ofrecemos trabajo, construimos carreras. Encuentra tu lugar en nuestra familia.";
  const headerBgUrl = customSettings.header_bg_url || "";

  return (
    <div className="w-full min-h-[calc(100vh-100px)] bg-slate-50 font-sans">
      <style dangerouslySetInnerHTML={{__html: `
        :root {
          --brand-color: ${tenant.brand_color || '#3b82f6'};
          --brand-color-hover: ${(tenant.brand_color || '#3b82f6')}dd;
          --brand-color-light: ${(tenant.brand_color || '#3b82f6')}15;
          --brand-color-border: ${(tenant.brand_color || '#3b82f6')}33;
        }
        .btn-brand {
          background-color: var(--brand-color) !important;
          color: white !important;
        }
        .btn-brand:hover {
          background-color: var(--brand-color-hover) !important;
        }
        .text-brand {
          color: var(--brand-color) !important;
        }
        .bg-brand-light {
          background-color: var(--brand-color-light) !important;
        }
        .border-brand-light {
          border-color: var(--brand-color-border) !important;
        }
        .group:hover .bg-brand-hover-target {
          background-color: var(--brand-color) !important;
        }
        .group:hover .group-hover-text-brand {
          color: var(--brand-color) !important;
        }
        .group:hover .group-hover-border-brand {
          border-color: var(--brand-color) !important;
        }
      `}} />

      {/* Hero Section */}
      <div 
        className="relative overflow-hidden bg-white text-slate-900 rounded-3xl p-12 text-center shadow-sm border border-slate-200 mb-12 bg-cover bg-center"
        style={{ 
          backgroundImage: headerBgUrl ? `url(${headerBgUrl})` : undefined,
        }}
      >
        {headerBgUrl && <div className="absolute inset-0 bg-slate-950/60 z-0"></div>}
        <div className="absolute top-0 left-0 w-full h-1" style={{ backgroundColor: tenant.brand_color || '#3b82f6', zIndex: 10 }}></div>
        <div className={`relative z-10 max-w-2xl mx-auto flex flex-col items-center ${headerBgUrl ? 'text-white' : 'text-slate-900'}`}>
          {/* Contenedor de Logo o Iniciales */}
          <div className="w-20 h-20 rounded-2xl flex items-center justify-center mb-6 shadow-sm border border-slate-150 overflow-hidden bg-white">
            {tenant.logo_url ? (
              <img 
                src={tenant.logo_url} 
                alt={tenant.name} 
                className="w-full h-full object-contain p-2" 
                onError={(e) => { 
                  (e.target as any).style.display = 'none';
                  const parent = (e.target as any).parentElement;
                  if (parent) {
                    const fallback = parent.querySelector('.logo-fallback');
                    if (fallback) fallback.style.display = 'flex';
                  }
                }} 
              />
            ) : null}
            <div 
              className="logo-fallback w-full h-full flex items-center justify-center text-white font-black text-2xl" 
              style={{ 
                backgroundColor: tenant.brand_color || '#3b82f6',
                display: tenant.logo_url ? 'none' : 'flex'
              }}
            >
              {tenant.name.substring(0, 2).toUpperCase()}
            </div>
          </div>

          <h1 className={`text-4xl md:text-5xl font-black mb-4 tracking-tight ${headerBgUrl ? 'text-white' : 'text-slate-900'}`}>
            {headerTitle}
          </h1>
          <p className={`text-lg mb-8 font-medium ${headerBgUrl ? 'text-slate-200' : 'text-slate-500'}`}>
            {headerSubtitle}
          </p>
          <button 
            className="btn-brand text-white px-8 py-3 rounded-xl font-bold transition-all shadow-sm flex items-center gap-2"
            onClick={() => {
              const el = document.getElementById('vacancies-grid');
              if (el) el.scrollIntoView({ behavior: 'smooth' });
            }}
          >
            Ver Vacantes Abiertas <ArrowRight size={18} />
          </button>
        </div>
      </div>

      {/* Grid de Vacantes (Glassmorphism) */}
      <div id="vacancies-grid" className="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 pb-12 px-4">
        {vacancies.map(vacancy => {
          const isActive = vacancy.is_active === 1 || vacancy.is_active === true;
          const { days, hours } = splitSchedule(vacancy.schedule);
          
          return (
          <div 
            key={vacancy.id} 
            onClick={() => {
              setSelectedVacancy(vacancy);
              setShowDetailModal(true);
            }}
            className={`group relative bg-white border rounded-3xl overflow-hidden shadow-sm transition-all flex flex-col h-full ${isActive ? 'border-slate-200 hover:shadow-md group-hover-border-brand cursor-pointer hover:-translate-y-1' : 'border-slate-150 opacity-85 hover:shadow-md cursor-pointer'}`}
          >
            <div className={`absolute top-0 left-0 w-full h-1 z-10 transition-colors ${isActive ? 'bg-brand-hover-target' : 'bg-slate-300'}`}></div>
            
            {/* Imagen Alusiva Limpia y Clara */}
            <div className="h-48 w-full relative overflow-hidden bg-slate-100">
              <img 
                src={vacancy.image_url} 
                alt={vacancy.title} 
                className={`w-full h-full object-cover transition-transform duration-700 ${isActive ? 'group-hover:scale-105' : ''}`} 
              />
              
              {/* Badges de Modalidad y Disponibilidad (Esquina Superior Izquierda/Derecha) */}
              <div className="absolute top-3 left-3 flex gap-2">
                <span className="inline-block bg-white/95 backdrop-blur-sm text-slate-800 text-[10px] uppercase tracking-wider font-extrabold px-3 py-1.5 rounded-lg shadow-sm border border-white/20">
                  {vacancy.work_type}
                </span>
              </div>
              {!isActive && (
                <div className="absolute top-3 right-3">
                  <span className="inline-block bg-rose-600/90 text-white text-[10px] uppercase tracking-wider font-extrabold px-3 py-1.5 rounded-lg shadow-sm backdrop-blur-sm">
                    Pausada / Ocupada
                  </span>
                </div>
              )}
            </div>

            <div className="p-6 flex-grow flex flex-col bg-white text-left">
              {/* Título en tipografía oscura de alto contraste */}
              <h3 className="text-lg md:text-xl font-bold text-slate-800 tracking-tight leading-snug mb-3 group-hover-text-brand transition-colors text-left">
                {vacancy.title}
              </h3>
              
              {/* Horario y Sueldo en Filas Independientes (Gris y Verde suave) */}
              <div className="flex flex-col gap-1.5 mb-4 text-[11px] font-bold text-slate-500 text-left">
                {days && (
                  <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-100 px-2.5 py-1.5 rounded-lg w-fit text-left">
                    <CalendarDays className="text-brand shrink-0" size={13} /> 
                    <span className="truncate max-w-[220px]">{days}</span>
                  </div>
                )}
                {hours && (
                  <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-100 px-2.5 py-1.5 rounded-lg w-fit text-left">
                    <CalendarDays className="text-brand shrink-0" size={13} /> 
                    <span className="truncate max-w-[220px]">{hours}</span>
                  </div>
                )}
                <div className="flex items-center gap-1.5 bg-emerald-50 border border-emerald-100 px-2.5 py-1.5 rounded-lg w-fit text-left">
                  <CircleDollarSign className="text-emerald-600 shrink-0" size={13} /> 
                  <span className="text-emerald-700 font-extrabold">{vacancy.salary_range}</span>
                </div>
              </div>

              <p className="text-slate-500 text-sm leading-relaxed mb-6 flex-grow text-left">
                {vacancy.description && vacancy.description.length > 110 
                  ? vacancy.description.substring(0, 110) + '...' 
                  : vacancy.description}
              </p>
              
              <button 
                onClick={(e) => {
                  e.stopPropagation();
                  setSelectedVacancy(vacancy);
                  setShowDetailModal(true);
                }}
                className="w-full bg-slate-50 hover:bg-brand-light hover:text-brand text-slate-700 border border-slate-200 hover:border-brand-light py-3 rounded-xl font-bold text-sm transition-all flex justify-center items-center gap-2 mt-auto"
              >
                <span>Ver Detalles</span>
                <ArrowRight size={16} />
              </button>
            </div>
          </div>
        )})}
      </div>

      {/* Footer Premium */}
      <footer className="w-full bg-slate-900 text-slate-400 py-12 px-6 rounded-t-[2.5rem] mt-12 border-t border-slate-800 relative z-10">
        <div className="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-8">
          {/* Logo y Nombre */}
          <div className="space-y-4 text-left">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl overflow-hidden bg-white flex items-center justify-center border border-slate-800 shadow-sm shrink-0">
                {tenant.logo_url ? (
                  <img src={tenant.logo_url} alt={tenant.name} className="w-full h-full object-contain p-1" onError={(e) => { (e.target as any).style.display = 'none'; }} />
                ) : (
                  <div className="w-full h-full flex items-center justify-center text-white font-extrabold text-sm" style={{ backgroundColor: tenant.brand_color || '#3b82f6' }}>
                    {tenant.name.substring(0, 2).toUpperCase()}
                  </div>
                )}
              </div>
              <span className="text-white font-black text-lg tracking-tight">{tenant.name}</span>
            </div>
            <p className="text-xs text-slate-500 max-w-xs leading-relaxed">
              Descubre grandes oportunidades y únete a un equipo excepcional enfocado en el crecimiento profesional y personal.
            </p>
          </div>

          {/* Información de Contacto */}
          <div className="space-y-4 text-left">
            <h4 className="text-xs font-bold text-white uppercase tracking-wider pl-1">Contacto</h4>
            <div className="space-y-2.5 text-xs">
              {customSettings.contact_email && (
                <div className="flex items-center gap-2">
                  <Mail size={14} className="text-brand" />
                  <a href={`mailto:${customSettings.contact_email}`} className="hover:text-white transition-colors">{customSettings.contact_email}</a>
                </div>
              )}
              {customSettings.contact_phone && (
                <div className="flex items-center gap-2">
                  <Phone size={14} className="text-brand" />
                  <span className="text-slate-450">{customSettings.contact_phone}</span>
                </div>
              )}
              {!customSettings.contact_email && !customSettings.contact_phone && (
                <p className="text-slate-500 italic">No se ha especificado información de contacto.</p>
              )}
            </div>
          </div>

          {/* Redes Sociales */}
          <div className="space-y-4 text-left">
            <h4 className="text-xs font-bold text-white uppercase tracking-wider pl-1">Redes Sociales</h4>
            <div className="flex gap-2">
              {customSettings.social_facebook && (
                <a href={customSettings.social_facebook} target="_blank" rel="noopener noreferrer" className="p-2.5 bg-slate-800 hover:bg-brand hover:text-white text-slate-400 rounded-xl transition-all shadow-sm flex items-center justify-center border border-slate-700/50">
                  <Facebook size={16} />
                </a>
              )}
              {customSettings.social_instagram && (
                <a href={customSettings.social_instagram} target="_blank" rel="noopener noreferrer" className="p-2.5 bg-slate-800 hover:bg-brand hover:text-white text-slate-400 rounded-xl transition-all shadow-sm flex items-center justify-center border border-slate-700/50">
                  <Instagram size={16} />
                </a>
              )}
              {customSettings.social_linkedin && (
                <a href={customSettings.social_linkedin} target="_blank" rel="noopener noreferrer" className="p-2.5 bg-slate-800 hover:bg-brand hover:text-white text-slate-400 rounded-xl transition-all shadow-sm flex items-center justify-center border border-slate-700/50">
                  <Linkedin size={16} />
                </a>
              )}
              {!customSettings.social_facebook && !customSettings.social_instagram && !customSettings.social_linkedin && (
                <p className="text-slate-500 italic text-xs">Síguenos en nuestras plataformas oficiales.</p>
              )}
            </div>
          </div>
        </div>

        {/* Línea inferior de copyright */}
        <div className="max-w-6xl mx-auto border-t border-slate-800/80 mt-8 pt-6 flex flex-col sm:flex-row justify-between items-center gap-4 text-[10px] text-slate-500">
          <span>{customSettings.footer_text || `© ${new Date().getFullYear()} ${tenant.name}. Todos los derechos reservados.`}</span>
          <div className="flex items-center gap-3">
            <button 
              type="button" 
              onClick={() => { setLegalModalTab('privacy'); setIsLegalModalOpen(true); }}
              className="hover:text-slate-300 transition underline cursor-pointer"
            >
              Aviso de Privacidad
            </button>
            <span>•</span>
            <button 
              type="button" 
              onClick={() => { setLegalModalTab('terms'); setIsLegalModalOpen(true); }}
              className="hover:text-slate-300 transition underline cursor-pointer"
            >
              Términos
            </button>
          </div>
          <span className="flex items-center gap-1 font-semibold">Desarrollado con <span className="text-rose-500">♥</span> por <span className="text-white">Talent360</span></span>
        </div>
      </footer>

      {/* Modal Legal */}
      <LegalModal 
        isOpen={isLegalModalOpen} 
        onClose={() => setIsLegalModalOpen(false)} 
        defaultTab={legalModalTab} 
      />

      {/* Modal de Detalles de Vacante */}
      {showDetailModal && selectedVacancy && (() => {
        const isActive = selectedVacancy.is_active === 1 || selectedVacancy.is_active === true;
        const { days, hours } = splitSchedule(selectedVacancy.schedule);
        
        return (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/65 backdrop-blur-sm animate-fade-in overflow-y-auto">
            <div className="bg-white rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl relative my-8 animate-slide-up flex flex-col max-h-[90vh]">
              {/* Botón Cerrar Flotante */}
              <button 
                onClick={() => {
                  setShowDetailModal(false);
                  setSelectedVacancy(null);
                }}
                className="absolute top-4 right-4 z-20 text-slate-400 hover:text-slate-700 bg-white/80 hover:bg-white p-2.5 rounded-full backdrop-blur-md transition-all shadow-md"
              >
                <X size={20} />
              </button>

              {/* Encabezado con Imagen Limpia */}
              <div className="h-52 w-full relative overflow-hidden bg-slate-100 shrink-0">
                <img 
                  src={selectedVacancy.image_url} 
                  alt={selectedVacancy.title} 
                  className="w-full h-full object-cover opacity-100" 
                />
                
                {/* Badges de Modalidad y Disponibilidad */}
                <div className="absolute top-3 left-3 flex gap-2">
                  <span className="inline-block bg-white/95 backdrop-blur-sm text-slate-800 text-[10px] uppercase tracking-wider font-extrabold px-3 py-1.5 rounded-lg shadow-sm border border-white/20">
                    {selectedVacancy.work_type}
                  </span>
                </div>
                {!isActive && (
                  <div className="absolute top-3 right-3">
                    <span className="inline-block bg-rose-600/90 text-white text-[10px] uppercase tracking-wider font-extrabold px-3 py-1.5 rounded-lg shadow-sm backdrop-blur-sm">
                      Pausada / Ocupada
                    </span>
                  </div>
                )}
              </div>

              {/* Cuerpo del Modal (Scrollable) */}
              <div className="p-6 md:p-8 space-y-6 overflow-y-auto flex-grow text-left">
                {/* Título en tipografía oscura de alto contraste */}
                <div className="text-left">
                  <h3 className="text-2xl md:text-3xl font-black text-slate-800 leading-tight tracking-tight text-left">
                    {selectedVacancy.title}
                  </h3>
                </div>
                {/* Bloque de Información General (Dos filas de horario, sueldo) */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-50 p-5 rounded-2xl border border-slate-100 shrink-0 text-left">
                  <div className="flex items-start gap-3">
                    <CalendarDays className="text-brand shrink-0 mt-0.5" size={18} />
                    <div className="text-left">
                      <span className="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Jornada y Horario</span>
                      <span className="block text-sm font-bold text-slate-700 mt-0.5">{days || 'No especificado'}</span>
                      {hours && <span className="block text-xs font-semibold text-slate-500 mt-0.5">{hours}</span>}
                    </div>
                  </div>
                  <div className="flex items-start gap-3 border-t md:border-t-0 md:border-l border-slate-200 pt-3 md:pt-0 md:pl-4 text-left">
                    <CircleDollarSign className="text-emerald-500 shrink-0 mt-0.5" size={18} />
                    <div className="text-left">
                      <span className="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Sueldo Ofrecido</span>
                      <span className="block text-sm font-extrabold text-emerald-600 mt-0.5">{selectedVacancy.salary_range}</span>
                    </div>
                  </div>
                </div>

                {/* Descripción Completa */}
                <div className="text-left">
                  <h4 className="text-xs font-extrabold text-slate-400 uppercase tracking-wider mb-2.5 pl-1">Descripción del Puesto</h4>
                  <div className="bg-slate-50/50 border border-slate-100 p-5 rounded-2xl text-slate-600 text-sm leading-relaxed whitespace-pre-line text-left">
                    {selectedVacancy.description}
                  </div>
                </div>

                {/* Requisitos Completos */}
                {Array.isArray(selectedVacancy.requirements) && selectedVacancy.requirements.length > 0 && (
                  <div className="text-left">
                    <h4 className="text-xs font-extrabold text-slate-400 uppercase tracking-wider mb-2.5 pl-1">Requisitos</h4>
                    <div className="space-y-3 bg-slate-50/50 border border-slate-100 p-5 rounded-2xl text-left">
                      {selectedVacancy.requirements.map((req: string, idx: number) => (
                        <div key={idx} className="flex items-start gap-3 text-sm text-slate-700 font-medium text-left">
                          <CheckCircle2 className="text-brand shrink-0 mt-0.5" size={18} />
                          <span>{req}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>

              {/* Footer con Acciones Centralizadas */}
              <div className="p-4 sm:p-6 bg-slate-50 border-t border-slate-100 flex flex-col sm:flex-row items-stretch sm:items-center gap-3 shrink-0">
                {isActive ? (
                  <button 
                    onClick={() => {
                      handlePostularse(selectedVacancy);
                    }}
                    className="w-full sm:flex-grow btn-brand text-white py-3.5 rounded-xl font-bold transition-all shadow-md flex justify-center items-center gap-2 text-sm"
                  >
                    Postularse a esta Vacante
                  </button>
                ) : (
                  <button 
                    onClick={() => {
                      handleNotificarme(selectedVacancy);
                    }}
                    className="w-full sm:flex-grow btn-brand text-white py-3.5 rounded-xl font-bold transition-all shadow-md flex justify-center items-center gap-2 text-sm"
                  >
                    Notificarme Disponibilidad
                  </button>
                )}
                
                <div className="flex gap-2 w-full sm:w-auto">
                  <button 
                    onClick={() => handleShare(selectedVacancy)}
                    className="flex-1 sm:flex-initial bg-white border border-slate-200 hover:border-slate-300 hover:bg-slate-100 text-slate-600 p-3.5 rounded-xl transition-all shadow-sm flex justify-center items-center"
                    title="Compartir"
                  >
                    <Share2 size={20} />
                  </button>
                  
                  <button 
                    onClick={() => {
                      setShowDetailModal(false);
                      setSelectedVacancy(null);
                    }}
                    className="flex-1 sm:flex-initial bg-white border border-slate-200 hover:bg-slate-100 text-slate-650 px-5 py-3.5 rounded-xl font-bold text-sm transition-all"
                  >
                    Cerrar
                  </button>
                </div>
              </div>
            </div>
          </div>
        );
      })()}

      {/* Modal de Compartir */}
      {shareVacancy && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-fade-in">
          <div className="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl relative animate-slide-up">
            <div className="p-8">
              <div className="flex justify-between items-center mb-6">
                <h3 className="text-2xl font-black text-slate-900">Compartir Vacante</h3>
                <button 
                  onClick={() => setShareVacancy(null)}
                  className="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition-all"
                >
                  <X size={20} />
                </button>
              </div>
              
              <p className="text-sm text-slate-500 mb-6">
                Comparte la vacante para <strong className="text-blue-600">{shareVacancy.title}</strong> con tus amigos o en redes sociales.
              </p>

              {/* Botones de Redes Sociales */}
              <div className="space-y-3 mb-6">
                {/* WhatsApp */}
                <button 
                  onClick={() => {
                    const shareUrl = `${window.location.origin}${window.location.pathname}?vacancy_id=${shareVacancy.id}`;
                    const text = `¡Hola! Te comparto esta vacante de empleo: ${shareVacancy.title}. Puedes postularte aquí: ${shareUrl}`;
                    window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
                  }}
                  className="w-full bg-[#25D366] hover:bg-[#20ba5a] text-white py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-2.5 shadow-sm shadow-emerald-500/10"
                >
                  <Send size={18} /> Compartir en WhatsApp
                </button>

                {/* Facebook */}
                <button 
                  onClick={() => {
                    const shareUrl = `${window.location.origin}${window.location.pathname}?vacancy_id=${shareVacancy.id}`;
                    window.open(`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareUrl)}`, '_blank');
                  }}
                  className="w-full bg-[#1877F2] hover:bg-[#166fe5] text-white py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-2.5 shadow-sm shadow-blue-500/10"
                >
                  <svg className="w-5 h-5 fill-current" viewBox="0 0 24 24">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                  </svg>
                  Compartir en Facebook
                </button>
              </div>

              {/* Input con enlace para copiar */}
              <div className="relative bg-slate-50 border border-slate-200 rounded-xl p-3 flex items-center justify-between gap-2 mb-4">
                <span className="text-xs font-semibold text-slate-600 truncate flex-grow">
                  {`${window.location.origin}${window.location.pathname}?vacancy_id=${shareVacancy.id}`}
                </span>
                <button 
                  onClick={() => {
                    const shareUrl = `${window.location.origin}${window.location.pathname}?vacancy_id=${shareVacancy.id}`;
                    navigator.clipboard.writeText(shareUrl);
                    setCopiedLink(true);
                    setTimeout(() => setCopiedLink(false), 2000);
                  }}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 ${copiedLink ? 'bg-emerald-500 text-white' : 'bg-slate-200 hover:bg-slate-300 text-slate-700'}`}
                >
                  {copiedLink ? (
                    <>
                      <Check size={14} /> ¡Copiado!
                    </>
                  ) : (
                    <>
                      <Copy size={14} /> Copiar
                    </>
                  )}
                </button>
              </div>
            </div>
            <div className="h-1.5 w-full bg-gradient-to-r from-blue-500 to-indigo-500"></div>
          </div>
        </div>
      )}

      {/* Modal de Autenticación Social (Google, Apple, Samsung) */}
      {showSocialAuthModal && selectedVacancy && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-fade-in">
          <div className="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl relative animate-slide-up">
            {/* ELIMINADO (2026-08-11): el "Paso 1 — Continuar con Google / Apple / Samsung".
                No había OAuth en ninguna parte del producto: los tres botones sólo rellenaban el
                formulario con tres identidades INVENTADAS escritas en el código ("Carlos Mendoza
                / carlos.mendoza.dev@gmail.com", "Sofía Rodríguez", "Alejandro Ruiz") y la
                pantalla afirmaba después "Confirma tus datos vinculados desde google". Como era
                el ÚNICO camino para postularse, todo el que usaba la bolsa de trabajo de un
                cliente real arrancaba con el nombre, el correo y el teléfono de un tercero ya
                escritos — y quien no se fijaba mandaba su postulación a nombre de otra persona.
                Ahora el formulario abre vacío. Vinculación social de verdad = trabajo aparte. */}
            {(
              <div className="p-8">
                <div className="flex justify-between items-center mb-6">
                  <h3 className="text-2xl font-black text-slate-900">Postulación</h3>
                  <button
                    onClick={() => setShowSocialAuthModal(false)}
                    className="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition-all"
                  >
                    <X size={20} />
                  </button>
                </div>

                <p className="text-sm text-slate-500 mb-6">
                  Déjanos tus datos para postularte a <strong className="text-blue-600">{selectedVacancy.title}</strong>:
                </p>

                <div className="space-y-4 mb-6">
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Nombre Completo</label>
                    <input 
                      type="text" 
                      value={candidateForm.name} 
                      onChange={e => setCandidateForm({ ...candidateForm, name: e.target.value })} 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all" 
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Correo Electrónico</label>
                    <input 
                      type="email" 
                      value={candidateForm.email} 
                      onChange={e => setCandidateForm({ ...candidateForm, email: e.target.value })} 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all" 
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Teléfono de Contacto</label>
                    <input 
                      type="tel" 
                      value={candidateForm.phone} 
                      onChange={e => setCandidateForm({ ...candidateForm, phone: e.target.value })} 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all" 
                    />
                  </div>
                </div>

                <button 
                  onClick={() => {
                    if (!candidateForm.name.trim() || !candidateForm.email.trim() || !candidateForm.phone.trim()) {
                      alert("Por favor, completa todos los campos.");
                      return;
                    }
                    setShowSocialAuthModal(false);
                    setShowInduction(true);
                  }}
                  className="w-full bg-blue-600 hover:bg-blue-700 text-white py-3.5 rounded-xl font-bold transition-all shadow-md flex items-center justify-center gap-2"
                >
                  Revisar y enviar <ArrowRight size={16} />
                </button>
              </div>
            )}
            <div className="h-1.5 w-full bg-gradient-to-r from-blue-500 to-indigo-500"></div>
          </div>
        </div>
      )}

      {/* Modal de Alerta de Vacante Individual */}
      {alertVacancy && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-fade-in">
          <div className="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl relative animate-slide-up">
            <div className="p-8">
              <div className="flex justify-between items-center mb-6">
                <h3 className="text-2xl font-black text-slate-900">Activar Alerta</h3>
                <button 
                  onClick={() => {
                    setAlertVacancy(null);
                    setAlertSuccess(false);
                  }}
                  className="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition-all"
                >
                  <X size={20} />
                </button>
              </div>

              {/* No existe (todavía) ningún envío automático de correo: la tabla de alertas era
                  de SOLO ESCRITURA y nadie la leía jamás. Ahora Recursos Humanos sí ve la lista
                  en su panel, así que el texto promete lo que de verdad va a pasar: que la
                  empresa te contacte. El correo automático es una función aparte. */}
              <p className="text-sm text-slate-500 mb-6">
                Guardaremos tu correo y Recursos Humanos de <strong className="text-blue-600">{tenant.name}</strong> podrá contactarte cuando la posición de <strong className="text-blue-600">{alertVacancy.title}</strong> vuelva a abrirse.
              </p>

              {alertSuccess ? (
                <div className="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 text-emerald-800 text-center mb-4">
                  <CheckCircle2 className="mx-auto text-emerald-500 mb-3" size={40} />
                  <p className="font-bold text-lg mb-1">Registramos tu interés</p>
                  <p className="text-sm font-medium">Recursos Humanos ya tiene tu correo en su lista de interesados para esta posición.</p>
                </div>
              ) : (
                <form onSubmit={handleAlertSubmit} className="space-y-4 mb-4">
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Correo Electrónico</label>
                    <input 
                      type="email" 
                      required
                      value={alertForm.email} 
                      onChange={e => setAlertForm({ ...alertForm, email: e.target.value })} 
                      placeholder="tu-correo@ejemplo.com"
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all" 
                    />
                  </div>
                  <button 
                    type="submit"
                    disabled={submittingAlert}
                    className="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-6 rounded-xl transition-all shadow-md flex items-center justify-center gap-2 disabled:bg-slate-300 disabled:cursor-not-allowed"
                  >
                    {submittingAlert ? 'Activando...' : 'Activar Alerta de Vacante'}
                  </button>
                </form>
              )}

              <button 
                onClick={() => {
                  setAlertVacancy(null);
                  setAlertSuccess(false);
                }}
                className="w-full text-center text-slate-500 font-bold hover:text-slate-700 transition-colors text-sm mt-2"
              >
                Cerrar
              </button>
            </div>
            <div className="h-1.5 w-full bg-gradient-to-r from-blue-500 to-indigo-500"></div>
          </div>
        </div>
      )}

      {/* Modal de Inducción Obligatoria */}
      {showInduction && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-slate-900/90 backdrop-blur-md animate-fade-in overflow-y-auto">
          <div className="bg-white rounded-3xl w-full max-w-4xl overflow-hidden shadow-2xl relative my-8 max-h-[90vh] overflow-y-auto">
            {/* Antes esta pantalla encabezaba "¡Solicitud Recibida!" ANTES de llamar a ningún
                endpoint, embebía un vídeo de YouTube en duro (el mismo rickroll que ya se retiró
                de la Academia) y preguntaba por "el valor principal de Talent360" —el proveedor
                del SaaS— en la bolsa de trabajo del cliente. Y la respuesta del test se
                descartaba en silencio: el servidor nunca la guardó. Ahora es lo que de verdad
                es: el paso de revisar y enviar. La inducción real vive en la Academia. */}
            <div className="p-4 sm:p-8">
              <h2 className="text-3xl font-extrabold text-slate-800 mb-2">Revisa y envía tu postulación</h2>
              <p className="text-slate-500 text-lg mb-8">
                Estos son los datos que llegarán a Recursos Humanos de {tenant.name} para la vacante
                de <span className="font-bold text-slate-700">{selectedVacancy?.title}</span>.
              </p>

              <div className="bg-slate-50 rounded-2xl p-6 border border-slate-200 mb-8 space-y-2 text-sm">
                <div><span className="font-bold text-slate-500 uppercase text-xs block">Nombre</span>{candidateForm.name}</div>
                <div><span className="font-bold text-slate-500 uppercase text-xs block">Correo</span>{candidateForm.email}</div>
                {candidateForm.phone && (
                  <div><span className="font-bold text-slate-500 uppercase text-xs block">Teléfono</span>{candidateForm.phone}</div>
                )}
              </div>

              <div className="flex flex-col-reverse sm:flex-row justify-between items-stretch sm:items-center gap-4">
                <button
                  onClick={() => { setShowInduction(false); setShowSocialAuthModal(true); }}
                  className="text-slate-500 font-bold hover:text-slate-700 transition-colors py-2 text-center"
                  type="button"
                >
                  Corregir mis datos
                </button>
                <button
                  onClick={async () => {
                    try {
                      const payload = {
                        name: candidateForm.name,
                        email: candidateForm.email,
                        phone: candidateForm.phone,
                        applied_vacancy_id: selectedVacancy.id
                      };
                      await axiosInstance.post('/public/candidates', payload);
                      alert(`¡Listo! Tu postulación llegó a Recursos Humanos de ${tenant.name}. Te contactarán al correo o teléfono que dejaste.`);
                      setShowInduction(false);
                      setSelectedVacancy(null);
                      setCandidateForm({ name: '', email: '', phone: '', answers: { q1: 'Puntualidad' } });
                    } catch (err: any) {
                      console.error("Error submitting candidate:", err);
                      alert(err?.response?.data?.message || "Hubo un error al enviar tu postulación. Intenta nuevamente.");
                    }
                  }}
                  className="btn-brand text-white px-8 py-3 rounded-xl font-bold transition-all shadow-md text-center"
                  type="button"
                >
                  Enviar mi postulación
                </button>
              </div>
            </div>
            <div className="h-2 w-full bg-gradient-to-r from-emerald-400 to-teal-400"></div>
          </div>
        </div>
      )}

      {/* Modal de bienvenida: sólo si la empresa configuró un vídeo suyo.
          Antes se abría SOLO al cargar, a pantalla completa y con autoplay, embebiendo un enlace
          de YouTube escrito en duro — el mismo rickroll que ya se había retirado de la Academia,
          sobreviviendo justo en la única pantalla que ven personas ajenas a la empresa, con la
          marca del cliente encima. El vídeo se configura en Ajustes del Portal Público. */}
      {showWelcomeModal && videoDeBienvenida && (
        <div className="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-white rounded-3xl w-full max-w-4xl overflow-hidden shadow-2xl relative animate-slide-up">
            <button onClick={() => setShowWelcomeModal(false)} className="absolute top-6 right-6 z-20 text-white hover:text-white bg-black/40 hover:bg-black/60 p-2 rounded-full backdrop-blur-md transition-all"><X size={20}/></button>
            <div className="flex flex-col md:flex-row">
               <div className="w-full md:w-1/2 bg-black aspect-video md:aspect-auto md:min-h-[400px] relative">
                  <iframe
                    className="absolute inset-0 w-full h-full"
                    src={videoDeBienvenida}
                    title={`Video de ${tenant.name}`}
                    frameBorder="0"
                    allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                  ></iframe>
               </div>
               <div className="w-full md:w-1/2 p-10 flex flex-col justify-center bg-white">
                  <div
                    className="w-12 h-12 rounded-2xl flex items-center justify-center mb-6 shadow-sm border border-brand-light bg-brand-light text-brand"
                  >
                     <Building2 size={24} />
                  </div>
                  <h2 className="text-3xl font-black text-slate-900 mb-4 tracking-tight">Bienvenido a {tenant.name}</h2>
                  {tenant.custom_settings?.header_subtitle && (
                    <p className="text-slate-500 mb-8 text-lg leading-relaxed">
                       {tenant.custom_settings.header_subtitle}
                    </p>
                  )}
                  <button
                     onClick={() => setShowWelcomeModal(false)}
                     className="btn-brand text-white px-8 py-3.5 rounded-xl font-bold transition-all shadow-md flex justify-center items-center gap-2 w-full"
                  >
                     Ver Vacantes Disponibles <ArrowRight size={18} />
                  </button>
               </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
