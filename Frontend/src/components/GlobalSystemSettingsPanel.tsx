import React, { useState, useEffect } from 'react';
import { 
  Users, Clock, Key, Coffee, ListTodo, Scale, 
  Bell, Briefcase, Sparkles, CheckCircle2, Save,
  FileText, Shield, Smartphone, Globe, MessageSquare,
  Building2, GraduationCap, Info, HelpCircle
} from 'lucide-react';
import { CompanyOnboardingSettings } from './CompanyOnboardingSettings';
import { CompanySettingsPanel } from './CompanySettingsPanel';
import { useAppStore } from '../store/useAppStore';

interface GlobalSystemSettingsPanelProps {
  initialTab?: string;
}

export const GlobalSystemSettingsPanel: React.FC<GlobalSystemSettingsPanelProps> = ({
  initialTab = 'onboarding'
}) => {
  const [activeTab, setActiveTab] = useState(initialTab);
  const isFeatureUnlocked = useAppStore(state => state.isFeatureUnlocked);

  useEffect(() => {
    if (initialTab) {
      setActiveTab(initialTab);
    }
  }, [initialTab]);

  const navItems = [
    {
      id: 'onboarding',
      label: 'Onboarding & Expedientes',
      icon: <Users size={18} />,
      badge: 'Esencial',
      badgeColor: 'bg-emerald-100 text-emerald-700',
      description: 'Mensajes de bienvenida, plantillas de puestos, expediente digital e invitación PWA.'
    },
    {
      id: 'reloj',
      label: 'Reloj & Asistencia Global',
      icon: <Clock size={18} />,
      badge: 'Operación',
      badgeColor: 'bg-blue-100 text-blue-700',
      description: 'Tolerancias, geolocalización, foto obligatoria y cursos de puntualidad en Academia.'
    },
    {
      id: 'apertura',
      label: 'Apertura de Sucursales',
      icon: <Key size={18} />,
      badge: 'Tiendas',
      badgeColor: 'bg-amber-100 text-amber-700',
      description: 'Ventanas pre-apertura, delegación de llaves, amnistías y checklists de apertura/cierre.',
      featureFlag: 'store_opening'
    },
    {
      id: 'comidas',
      label: 'Comedor & Reservaciones',
      icon: <Coffee size={18} />,
      badge: 'Beneficios',
      badgeColor: 'bg-rose-100 text-rose-700',
      description: 'Reglas de comedor, horarios de corte y límite de comidas subsidias por colaborador.'
    },
    {
      id: 'tareas',
      label: 'Tareas y Rutinas Globales',
      icon: <ListTodo size={18} />,
      badge: 'Rutinas',
      badgeColor: 'bg-purple-100 text-purple-700',
      description: 'Asignación de rutinas diarias, checklists por puesto y monitoreo de sillas/zonas.'
    },
    {
      id: 'lft',
      label: 'LFT & Reglas Laborales',
      icon: <Scale size={18} />,
      badge: 'Legal MX',
      badgeColor: 'bg-indigo-100 text-indigo-700',
      description: 'Límite de horas extra LFT, festivos oficiales y cálculo automático de jornadas.'
    },
    {
      id: 'notificaciones',
      label: 'Notificaciones & Alertas',
      icon: <Bell size={18} />,
      badge: 'Canales',
      badgeColor: 'bg-sky-100 text-sky-700',
      description: 'Configuración de alertas Push PWA, notificaciones de WhatsApp Bot y correo SMTP.'
    },
    {
      id: 'ats',
      label: 'Portal ATS & Vacantes',
      icon: <Briefcase size={18} />,
      badge: 'Reclutamiento',
      badgeColor: 'bg-violet-100 text-violet-700',
      description: 'Personalización de la bolsa de trabajo pública y portal de selección.'
    }
  ];

  return (
    <div className="w-full bg-white rounded-3xl border border-slate-200 shadow-xl overflow-hidden min-h-[680px] flex flex-col lg:flex-row">
      {/* Sidebar de Navegación de Configuraciones (Escritorio) */}
      <div className="hidden lg:flex w-80 bg-slate-50/95 backdrop-blur-md border-r border-slate-200 p-6 flex-col shrink-0">
        <div className="mb-6">
          <div className="flex items-center gap-2 mb-1">
            <div className="w-8 h-8 rounded-xl bg-indigo-600/10 text-indigo-600 flex items-center justify-center font-bold">
              <Sparkles size={18} />
            </div>
            <h2 className="text-base font-black text-slate-800 tracking-tight">Ajustes Globales</h2>
          </div>
          <p className="text-xs text-slate-500 font-medium">Configura los parámetros operativos, reglas de negocio y onboarding del sistema.</p>
        </div>

        <nav className="space-y-1.5 flex-1 overflow-y-auto custom-scrollbar pr-1">
          {navItems.map((item) => {
            if (item.featureFlag && !isFeatureUnlocked(item.featureFlag as any)) {
              return null;
            }
            const isActive = activeTab === item.id;
            return (
              <button
                key={item.id}
                onClick={() => setActiveTab(item.id)}
                className={`w-full text-left p-3 rounded-2xl transition-all flex items-start gap-3 group relative ${
                  isActive
                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20 font-bold'
                    : 'text-slate-600 hover:bg-slate-200/60 hover:text-slate-900 font-semibold'
                }`}
              >
                <div className={`p-2 rounded-xl shrink-0 ${
                  isActive ? 'bg-white/20 text-white' : 'bg-slate-200/60 text-slate-500 group-hover:text-slate-800'
                }`}>
                  {item.icon}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between gap-1 mb-0.5">
                    <span className="text-xs sm:text-sm truncate">{item.label}</span>
                    {item.badge && (
                      <span className={`text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded-md ${
                        isActive ? 'bg-white/20 text-white' : item.badgeColor
                      }`}>
                        {item.badge}
                      </span>
                    )}
                  </div>
                  <p className={`text-[10px] line-clamp-1 font-normal ${
                    isActive ? 'text-indigo-100' : 'text-slate-400'
                  }`}>
                    {item.description}
                  </p>
                </div>
              </button>
            );
          })}
        </nav>

        {/* Tip / Infobox inferior */}
        <div className="mt-6 p-3.5 rounded-2xl bg-indigo-50/60 border border-indigo-100 text-indigo-900 text-xs flex items-start gap-2.5">
          <Info size={16} className="text-indigo-600 shrink-0 mt-0.5" />
          <p className="leading-snug text-[11px]">
            Para modificar el <strong>Perfil de la Empresa, Facturación y Respaldos</strong>, utiliza la opción <em>Perfil de la Empresa</em> desde el menú de usuario arriba a la derecha.
          </p>
        </div>
      </div>

      {/* DOCK FLOTANTE INFERIOR MÓVIL (Estilo Reloj Checador) */}
      <div className="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-2rem)] max-w-md bg-white/95 backdrop-blur-2xl border border-slate-200/90 rounded-3xl p-1.5 shadow-[0_10px_25px_rgba(0,0,0,0.15)] z-40 lg:hidden flex items-center justify-around overflow-x-auto whitespace-nowrap scrollbar-none">
        {navItems.map((item) => {
          if (item.featureFlag && !isFeatureUnlocked(item.featureFlag as any)) {
            return null;
          }
          const isActive = activeTab === item.id;
          return (
            <button
              key={item.id}
              onClick={() => setActiveTab(item.id)}
              className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1 shrink-0"
            >
              <div className={`w-9 h-9 rounded-full flex items-center justify-center transition-all ${
                isActive 
                  ? 'bg-indigo-500/15 border-2 border-indigo-500 shadow-md shadow-indigo-500/20 scale-105' 
                  : 'bg-slate-100 border border-slate-200/80 hover:bg-slate-200/60'
              }`}>
                {React.cloneElement(item.icon, {
                  size: 17,
                  className: isActive ? 'animate-pulse text-indigo-600 font-bold' : 'text-slate-400'
                })}
              </div>
              <span className={`text-[8px] uppercase tracking-wider font-extrabold mt-0.5 ${
                isActive ? 'font-black text-indigo-600' : 'text-slate-400'
              }`}>
                {item.label.split(' ')[0]}
              </span>
            </button>
          );
        })}
      </div>

      {/* Área de Contenido de la Configuración Seleccionada */}
      <div className="flex-1 p-4 sm:p-8 overflow-y-auto custom-scrollbar bg-white pb-24 sm:pb-8">
        {activeTab === 'onboarding' && (
          <div className="space-y-8 animate-in fade-in duration-200">
            <div>
              <div className="flex items-center justify-between border-b border-slate-100 pb-4 mb-6">
                <div>
                  <h3 className="text-xl font-black text-slate-800 tracking-tight flex items-center gap-2">
                    <Users className="text-indigo-600" size={24} />
                    Configuración de Onboarding & Expedientes
                  </h3>
                  <p className="text-xs text-slate-500 font-medium mt-0.5">
                    Personaliza la experiencia de bienvenida, importación de puestos por industria y expedientes digitales para tus colaboradores.
                  </p>
                </div>
              </div>

              {/* Renderiza el flujo interactivo de Onboarding */}
              <CompanyOnboardingSettings />
            </div>
          </div>
        )}

        {activeTab === 'reloj' && (
          <div className="animate-in fade-in duration-200">
            <CompanySettingsPanel initialTab="reloj" hideSidebar={true} />
          </div>
        )}

        {activeTab === 'apertura' && (
          <div className="animate-in fade-in duration-200">
            <CompanySettingsPanel initialTab="apertura" hideSidebar={true} />
          </div>
        )}

        {activeTab === 'comidas' && (
          <div className="animate-in fade-in duration-200">
            <CompanySettingsPanel initialTab="comidas" hideSidebar={true} />
          </div>
        )}

        {activeTab === 'tareas' && (
          <div className="animate-in fade-in duration-200">
            <CompanySettingsPanel initialTab="tareas" hideSidebar={true} />
          </div>
        )}

        {activeTab === 'lft' && (
          <div className="animate-in fade-in duration-200">
            <CompanySettingsPanel initialTab="general" hideSidebar={true} />
          </div>
        )}

        {activeTab === 'notificaciones' && (
          <div className="animate-in fade-in duration-200">
            <div className="max-w-3xl space-y-6">
              <div>
                <h3 className="text-xl font-black text-slate-800 tracking-tight flex items-center gap-2 mb-1">
                  <Bell className="text-indigo-600" size={24} />
                  Canales de Notificación & Alertas Globales
                </h3>
                <p className="text-xs text-slate-500 font-medium">
                  Configura cómo y por qué medios se notifica a los colaboradores y supervisores de incidencias.
                </p>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="p-5 rounded-2xl border border-slate-200 bg-slate-50/50 space-y-3">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold">
                      <MessageSquare size={20} />
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-slate-800">WhatsApp Bot</h4>
                      <p className="text-xs text-slate-500">Envío de alertas de retardo y bienvenida por WhatsApp.</p>
                    </div>
                  </div>
                  <label className="flex items-center gap-2 cursor-pointer pt-2 border-t border-slate-200 text-xs font-bold text-slate-700">
                    <input type="checkbox" defaultChecked className="rounded border-slate-300 text-indigo-600" />
                    Habilitar notificaciones WhatsApp
                  </label>
                </div>

                <div className="p-5 rounded-2xl border border-slate-200 bg-slate-50/50 space-y-3">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 flex items-center justify-center font-bold">
                      <Smartphone size={20} />
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-slate-800">Push PWA</h4>
                      <p className="text-xs text-slate-500">Notificaciones instantáneas en la App instalada.</p>
                    </div>
                  </div>
                  <label className="flex items-center gap-2 cursor-pointer pt-2 border-t border-slate-200 text-xs font-bold text-slate-700">
                    <input type="checkbox" defaultChecked className="rounded border-slate-300 text-indigo-600" />
                    Habilitar Push PWA
                  </label>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'ats' && (
          <div className="animate-in fade-in duration-200">
            <CompanySettingsPanel initialTab="ats" hideSidebar={true} />
          </div>
        )}
      </div>
    </div>
  );
};
