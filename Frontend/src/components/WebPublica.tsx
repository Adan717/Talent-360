import React, { useState, useEffect } from 'react';
import { useSearchParams, useParams } from 'react-router-dom';
import { Briefcase, CalendarDays, CircleDollarSign, CheckCircle2, Building2, ArrowRight, X, Share2, Copy, Check, Send, ChevronDown } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';

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
  const tenantId = searchParams.get('tenant_id') || currentUser?.tenant?.id || '1';

  const [selectedVacancy, setSelectedVacancy] = useState<any>(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
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
  const [authProvider, setAuthProvider] = useState<string | null>(null);
  const [copiedLink, setCopiedLink] = useState(false);

  const [expandedVacancies, setExpandedVacancies] = useState<Record<number, boolean>>({});

  const toggleRequirements = (id: number) => {
    setExpandedVacancies(prev => ({
      ...prev,
      [id]: !prev[id]
    }));
  };

  const handlePostularse = (vacancy: any) => {
    setSelectedVacancy(vacancy);
    setShowSocialAuthModal(true);
    setAuthProvider(null);
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
        slug: activeSlug,
        tenant_id: Number(tenantId)
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
      <div className="relative overflow-hidden bg-white text-slate-900 rounded-3xl p-12 text-center shadow-sm border border-slate-200 mb-12">
        <div className="absolute top-0 left-0 w-full h-1" style={{ backgroundColor: tenant.brand_color || '#3b82f6' }}></div>
        <div className="relative z-10 max-w-2xl mx-auto flex flex-col items-center">
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

          <h1 className="text-4xl md:text-5xl font-black mb-4 tracking-tight text-slate-900">
            Únete a {tenant.name}
          </h1>
          <p className="text-lg text-slate-500 mb-8 font-medium">
            No solo ofrecemos trabajo, construimos carreras. Encuentra tu lugar en nuestra familia.
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
            {!authProvider ? (
              // Paso 1: Selección de Proveedor
              <div className="p-8">
                <div className="flex justify-between items-center mb-6">
                  <h3 className="text-2xl font-black text-slate-900">Postulación</h3>
                  <button 
                    onClick={() => {
                      setShowSocialAuthModal(false);
                    }}
                    className="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition-all"
                  >
                    <X size={20} />
                  </button>
                </div>

                <p className="text-sm text-slate-500 mb-6">
                  Estás postulándote a <strong className="text-blue-600">{selectedVacancy.title}</strong>. Para continuar, vincula tu cuenta de redes sociales.
                </p>

                <div className="space-y-3 mb-6">
                  {/* Google */}
                  <button 
                    onClick={() => {
                      setAuthProvider('google');
                      setCandidateForm({
                        ...candidateForm,
                        name: 'Carlos Mendoza',
                        email: 'carlos.mendoza.dev@gmail.com',
                        phone: '5543210987'
                      });
                    }}
                    className="w-full bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-3 shadow-sm"
                  >
                    <svg className="w-5 h-5" viewBox="0 0 24 24">
                      <path fill="#EA4335" d="M12.24 10.285V14.4h6.887c-.648 2.41-2.519 4.114-5.136 4.114-3.41 0-6.19-2.78-6.19-6.19 0-3.41 2.78-6.19 6.19-6.19 1.488 0 2.85.535 3.923 1.417l3.056-3.055C18.895 2.146 15.753 1 12.24 1 6.043 1 1 6.043 1 12.24s5.043 11.24 11.24 11.24c5.84 0 10.84-4.148 10.84-11.24 0-.668-.063-1.31-.18-1.955H12.24z"/>
                    </svg>
                    Continuar con Google
                  </button>

                  {/* Apple */}
                  <button 
                    onClick={() => {
                      setAuthProvider('apple');
                      setCandidateForm({
                        ...candidateForm,
                        name: 'Sofía Rodríguez',
                        email: 's.rodriguez@icloud.com',
                        phone: '5587654321'
                      });
                    }}
                    className="w-full bg-black hover:bg-slate-900 text-white py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-3 shadow-sm"
                  >
                    <svg className="w-5 h-5 fill-current" viewBox="0 0 24 24">
                      <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 4.17c.66-.81 1.11-1.93.99-3.06-.96.04-2.13.64-2.82 1.45-.6.69-1.12 1.83-.98 2.94 1.07.08 2.15-.52 2.81-1.33z"/>
                    </svg>
                    Continuar con Apple
                  </button>

                  {/* Samsung */}
                  <button 
                    onClick={() => {
                      setAuthProvider('samsung');
                      setCandidateForm({
                        ...candidateForm,
                        name: 'Alejandro Ruiz',
                        email: 'alejandro.ruiz@samsung.com',
                        phone: '5576543210'
                      });
                    }}
                    className="w-full bg-[#1428a0] hover:bg-[#0f1f7a] text-white py-3 rounded-xl font-bold transition-all flex items-center justify-center gap-3 shadow-sm"
                  >
                    <span className="font-extrabold tracking-wider text-sm italic">SAMSUNG</span>
                    Continuar con Samsung
                  </button>
                </div>

                <button 
                  onClick={() => {
                    setShowSocialAuthModal(false);
                  }}
                  className="w-full text-center text-slate-500 font-bold hover:text-slate-700 transition-colors text-sm"
                >
                  Cancelar
                </button>
              </div>
            ) : (
              // Paso 2: Consentimiento
              <div className="p-8">
                <div className="flex justify-between items-center mb-6">
                  <div className="flex items-center gap-2">
                    {authProvider === 'google' && (
                      <span className="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse"></span>
                    )}
                    {authProvider === 'apple' && (
                      <span className="w-2.5 h-2.5 rounded-full bg-slate-800 animate-pulse"></span>
                    )}
                    {authProvider === 'samsung' && (
                      <span className="w-2.5 h-2.5 rounded-full bg-blue-600 animate-pulse"></span>
                    )}
                    <h3 className="text-lg font-black text-slate-900 uppercase tracking-wider">
                      Perfil {authProvider}
                    </h3>
                  </div>
                  <button 
                    onClick={() => setAuthProvider(null)}
                    className="text-xs font-bold text-slate-500 hover:text-slate-800 transition-colors"
                  >
                    Atrás
                  </button>
                </div>

                <p className="text-sm text-slate-500 mb-6">
                  Confirma tus datos vinculados desde <strong className="capitalize">{authProvider}</strong> para postularte a <strong className="text-blue-600">{selectedVacancy.title}</strong>:
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
                  Vincular y Continuar a Inducción <ArrowRight size={16} />
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

              <p className="text-sm text-slate-500 mb-6">
                Te enviaremos una notificación por correo electrónico en cuanto la posición de <strong className="text-blue-600">{alertVacancy.title}</strong> vuelva a estar disponible.
              </p>

              {alertSuccess ? (
                <div className="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 text-emerald-800 text-center mb-4">
                  <CheckCircle2 className="mx-auto text-emerald-500 mb-3" size={40} />
                  <p className="font-bold text-lg mb-1">¡Alerta Activada!</p>
                  <p className="text-sm font-medium">Te notificaremos de inmediato en cuanto se publique o habilite esta vacante.</p>
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
            <div className="p-4 sm:p-8">
              <h2 className="text-3xl font-extrabold text-slate-800 mb-2">¡Solicitud Recibida! 🎉</h2>
              <p className="text-slate-500 text-lg mb-8">Para avanzar en tu proceso, necesitamos que completes tu Curso de Inducción Básico.</p>
              
              <div className="aspect-video w-full rounded-2xl overflow-hidden shadow-lg border border-slate-200 bg-black mb-8">
                <iframe 
                  className="w-full h-full" 
                  src="https://www.youtube.com/embed/dQw4w9WgXcQ" 
                  title="YouTube video player" 
                  frameBorder="0" 
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                  allowFullScreen
                ></iframe>
              </div>
 
              <div className="bg-indigo-50 rounded-2xl p-6 border border-indigo-100 mb-8">
                <h3 className="font-bold text-indigo-900 mb-4 text-xl">Test Rápido:</h3>
                <div className="space-y-4">
                  <div>
                    <p className="font-semibold text-indigo-800 mb-2">1. ¿Cuál es el valor principal de Talent360?</p>
                    <div className="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                      <label className="flex items-center space-x-2">
                        <input 
                          type="radio" 
                          name="q1" 
                          checked={candidateForm.answers.q1 === 'Puntualidad'} 
                          onChange={() => setCandidateForm({ ...candidateForm, answers: { ...candidateForm.answers, q1: 'Puntualidad' } })}
                          className="form-radio text-indigo-600" 
                        /> 
                        <span>Puntualidad</span>
                      </label>
                      <label className="flex items-center space-x-2">
                        <input 
                          type="radio" 
                          name="q1" 
                          checked={candidateForm.answers.q1 === 'Creatividad'} 
                          onChange={() => setCandidateForm({ ...candidateForm, answers: { ...candidateForm.answers, q1: 'Creatividad' } })}
                          className="form-radio text-indigo-600" 
                        /> 
                        <span>Creatividad</span>
                      </label>
                      <label className="flex items-center space-x-2">
                        <input 
                          type="radio" 
                          name="q1" 
                          checked={candidateForm.answers.q1 === 'Velocidad'} 
                          onChange={() => setCandidateForm({ ...candidateForm, answers: { ...candidateForm.answers, q1: 'Velocidad' } })}
                          className="form-radio text-indigo-600" 
                        /> 
                        <span>Velocidad</span>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
 
              <div className="flex flex-col-reverse sm:flex-row justify-between items-stretch sm:items-center gap-4">
                <button 
                  onClick={() => { setShowInduction(false); setSelectedVacancy(null); }}
                  className="text-slate-500 font-bold hover:text-slate-700 transition-colors py-2 text-center"
                  type="button"
                >
                  Hacerlo más tarde
                </button>
                <button 
                  onClick={async () => {
                    try {
                      const payload = {
                        name: candidateForm.name,
                        email: candidateForm.email,
                        phone: candidateForm.phone,
                        applied_vacancy_id: selectedVacancy.id,
                        tenant_id: selectedVacancy.tenant_id ?? Number(tenantId) ?? 1,
                        answers: JSON.stringify(candidateForm.answers)
                      };
                      await axiosInstance.post('/public/candidates', payload);
                      alert('¡Has completado tu inducción! Tu expediente ha sido enviado a Recursos Humanos.');
                      setShowInduction(false);
                      setSelectedVacancy(null);
                      setCandidateForm({ name: '', email: '', phone: '', answers: { q1: 'Puntualidad' } });
                    } catch (err) {
                      console.error("Error submitting candidate:", err);
                      alert("Hubo un error al enviar tu postulación. Intenta nuevamente.");
                    }
                  }}
                  className="btn-brand text-white px-8 py-3 rounded-xl font-bold transition-all shadow-md text-center"
                  type="button"
                >
                  Terminar y Enviar Expediente
                </button>
              </div>
            </div>
            <div className="h-2 w-full bg-gradient-to-r from-emerald-400 to-teal-400"></div>
          </div>
        </div>
      )}

      {/* Modal de Bienvenida (Video Corporativo) */}
      {showWelcomeModal && (
        <div className="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-white rounded-3xl w-full max-w-4xl overflow-hidden shadow-2xl relative animate-slide-up">
            <button onClick={() => setShowWelcomeModal(false)} className="absolute top-6 right-6 z-20 text-white hover:text-white bg-black/40 hover:bg-black/60 p-2 rounded-full backdrop-blur-md transition-all"><X size={20}/></button>
            <div className="flex flex-col md:flex-row">
               <div className="w-full md:w-1/2 bg-black aspect-video md:aspect-auto md:min-h-[400px] relative">
                  <iframe 
                    className="absolute inset-0 w-full h-full" 
                    src="https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=1&mute=1" 
                    title="Video Corporativo" 
                    frameBorder="0" 
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
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
                  <p className="text-slate-500 mb-8 text-lg leading-relaxed">
                     Somos más que una empresa; somos una familia enfocada en la excelencia y el desarrollo de nuestro talento. Descubre por qué trabajar con nosotros es tu mejor decisión.
                  </p>
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
