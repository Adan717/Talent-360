import React, { useState, useEffect } from 'react';
import {
  Users, Clock, Key, Coffee, ListTodo, Scale,
  Bell, Briefcase, Sparkles, CheckCircle2, Save,
  FileText, Shield, Smartphone, Globe, MessageSquare,
  Building2, GraduationCap, Info, HelpCircle
} from 'lucide-react';
import { CompanyOnboardingSettings } from './CompanyOnboardingSettings';
import { CompanySettingsPanel } from './CompanySettingsPanel';
import NominaSettingsPanel from './NominaSettingsPanel';
import MatrizDePermisos from './MatrizDePermisos';
import { useAppStore } from '../store/useAppStore';
import { MobileModuleBottomDock } from './common/MobileModuleBottomDock';

interface GlobalSystemSettingsPanelProps {
  initialTab?: string;
}

export const GlobalSystemSettingsPanel: React.FC<GlobalSystemSettingsPanelProps> = ({
  initialTab = 'onboarding'
}) => {
  const [activeTab, setActiveTab] = useState(initialTab);
  const isFeatureUnlocked = useAppStore(state => state.isFeatureUnlocked);
  const currentUser = useAppStore(state => state.currentUser);
  // La matriz de permisos es INDELEGABLE (role:admin en el servidor): a un supervisor no se le
  // ofrece una pestaña que sólo le devolvería 403.
  const esAdmin = currentUser?.role === 'admin' || (currentUser as any)?.system_role === 'admin';

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
      badgeColor: 'bg-success-bg text-success-text',
      description: 'Mensajes de bienvenida, plantillas de puestos, expediente digital e invitación PWA.'
    },
    {
      id: 'reloj',
      label: 'Reloj & Asistencia Global',
      icon: <Clock size={18} />,
      badge: 'Operación',
      badgeColor: 'bg-accent-soft text-accent',
      description: 'Tolerancias, geolocalización, foto obligatoria y cursos de puntualidad en Academia.'
    },
    {
      id: 'apertura',
      label: 'Apertura de Sucursales',
      icon: <Key size={18} />,
      badge: 'Tiendas',
      badgeColor: 'bg-warning-bg text-warning-text',
      description: 'Ventanas pre-apertura, delegación de llaves, amnistías y checklists de apertura/cierre.',
      featureFlag: 'store_opening'
    },
    {
      id: 'comidas',
      label: 'Comedor & Reservaciones',
      icon: <Coffee size={18} />,
      badge: 'Beneficios',
      badgeColor: 'bg-danger-bg text-danger-text',
      description: 'Reglas de comedor, horarios de corte y límite de comidas subsidias por colaborador.'
    },
    {
      id: 'tareas',
      label: 'Tareas y Rutinas Globales',
      icon: <ListTodo size={18} />,
      badge: 'Rutinas',
      badgeColor: 'bg-accent-soft text-accent',
      description: 'Asignación de rutinas diarias, checklists por puesto y monitoreo de sillas/zonas.'
    },
    {
      id: 'lft',
      label: 'LFT & Reglas Laborales',
      icon: <Scale size={18} />,
      badge: 'Legal MX',
      badgeColor: 'bg-accent-soft text-accent',
      description: 'Límite de horas extra LFT, festivos oficiales y cálculo automático de jornadas.'
    },
    {
      id: 'nomina',
      label: 'Pre-nómina & Periodicidad',
      icon: <FileText size={18} />,
      badge: 'Pagos',
      badgeColor: 'bg-success-bg text-success-text',
      description: 'Semanal, quincenal o mensual; día de inicio de semana y día de pago.'
    },
    {
      id: 'notificaciones',
      label: 'Notificaciones & Alertas',
      icon: <Bell size={18} />,
      badge: 'Canales',
      badgeColor: 'bg-accent-soft text-accent',
      description: 'Configuración de alertas Push PWA, notificaciones de WhatsApp Bot y correo SMTP.'
    },
    {
      id: 'permisos',
      label: 'Permisos por puesto',
      icon: <Shield size={18} />,
      badge: 'Sólo admin',
      badgeColor: 'bg-slate-200 text-text-2',
      description: 'Qué puede hacer cada puesto: tareas, aperturas, reportes, nómina. Delegación de capacidades.',
      soloAdmin: true
    },
    {
      id: 'ats',
      label: 'Portal ATS & Vacantes',
      icon: <Briefcase size={18} />,
      badge: 'Reclutamiento',
      badgeColor: 'bg-accent-soft text-accent',
      description: 'Personalización de la bolsa de trabajo pública y portal de selección.'
    }
  ];

  return (
    <div className="w-full bg-white rounded-3xl border border-border shadow-xl overflow-hidden min-h-[680px] flex flex-col lg:flex-row">
      {/* Sidebar de Navegación de Configuraciones (Escritorio) */}
      <div className="hidden lg:flex w-80 bg-page/95 backdrop-blur-md border-r border-border p-6 flex-col shrink-0">
        <div className="mb-6">
          <div className="flex items-center gap-2 mb-1">
            <div className="w-8 h-8 rounded-xl bg-accent/10 text-accent flex items-center justify-center font-bold">
              <Sparkles size={18} />
            </div>
            <h2 className="text-base font-black text-text-1 tracking-tight">Ajustes Globales</h2>
          </div>
          <p className="text-xs text-text-3 font-medium">Configura los parámetros operativos, reglas de negocio y onboarding del sistema.</p>
        </div>

        <nav className="space-y-1.5 flex-1 overflow-y-auto custom-scrollbar pr-1">
          {navItems.map((item) => {
            if (item.featureFlag && !isFeatureUnlocked(item.featureFlag as any)) {
              return null;
            }
            if (item.soloAdmin && !esAdmin) {
              return null;
            }
            const isActive = activeTab === item.id;
            return (
              <button
                key={item.id}
                onClick={() => setActiveTab(item.id)}
                className={`w-full text-left p-3 rounded-2xl transition-all flex items-start gap-3 group relative ${
                  isActive
                    ? 'bg-accent text-white shadow-lg shadow-accent/20 font-bold'
                    : 'text-text-2 hover:bg-slate-200/60 hover:text-text-1 font-semibold'
                }`}
              >
                <div className={`p-2 rounded-xl shrink-0 ${
                  isActive ? 'bg-white/20 text-white' : 'bg-slate-200/60 text-text-3 group-hover:text-text-1'
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
                    isActive ? 'text-navy-100' : 'text-slate-400'
                  }`}>
                    {item.description}
                  </p>
                </div>
              </button>
            );
          })}
        </nav>

        {/* Tip / Infobox inferior */}
        <div className="mt-6 p-3.5 rounded-2xl bg-navy-50/60 border border-border text-brand-dark text-xs flex items-start gap-2.5">
          <Info size={16} className="text-accent shrink-0 mt-0.5" />
          <p className="leading-snug text-[11px]">
            Para modificar el <strong>Perfil de la Empresa, Facturación y Respaldos</strong>, utiliza la opción <em>Perfil de la Empresa</em> desde el menú de usuario arriba a la derecha.
          </p>
        </div>
      </div>

      {/* DOCK FLOTANTE INFERIOR MÓVIL (Estilo Reloj Checador con muesca SVG y FAB índigo) */}
      <MobileModuleBottomDock
        colorTheme="indigo"
        activeTab={activeTab}
        onSelectTab={(tab) => setActiveTab(tab)}
        fabIcon={<Sparkles size={28} className="text-white relative z-10 animate-pulse" />}
        onFabClick={() => setActiveTab('onboarding')}
        fabTitle="Ajustes y Parámetros Globales"
        items={navItems
          .filter(item => !item.featureFlag || isFeatureUnlocked(item.featureFlag as any))
          .filter(item => !item.soloAdmin || esAdmin)
          .map(item => ({
            id: item.id,
            label: item.label.split(' ')[0],
            icon: item.icon
          }))
        }
      />

      {/* Área de Contenido de la Configuración Seleccionada */}
      <div className="flex-1 p-4 sm:p-8 overflow-y-auto custom-scrollbar bg-white pb-24 sm:pb-8">
        {activeTab === 'onboarding' && (
          <div className="space-y-8 animate-in fade-in duration-200">
            <div>
              <div className="flex items-center justify-between border-b border-border pb-4 mb-6">
                <div>
                  <h3 className="text-xl font-black text-text-1 tracking-tight flex items-center gap-2">
                    <Users className="text-accent" size={24} />
                    Configuración de Onboarding & Expedientes
                  </h3>
                  <p className="text-xs text-text-3 font-medium mt-0.5">
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

        {activeTab === 'nomina' && (
          <div className="animate-in fade-in duration-200">
            <NominaSettingsPanel />
          </div>
        )}

        {activeTab === 'notificaciones' && (
          <div className="animate-in fade-in duration-200">
            <div className="max-w-3xl space-y-6">
              <div>
                <h3 className="text-xl font-black text-text-1 tracking-tight flex items-center gap-2 mb-1">
                  <Bell className="text-accent" size={24} />
                  Canales de Notificación & Alertas Globales
                </h3>
                <p className="text-xs text-text-3 font-medium">
                  Configura cómo y por qué medios se notifica a los colaboradores y supervisores de incidencias.
                </p>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="p-5 rounded-2xl border border-border bg-page/50 space-y-3">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-success-bg text-success-text flex items-center justify-center font-bold">
                      <MessageSquare size={20} />
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-text-1">WhatsApp Bot</h4>
                      <p className="text-xs text-text-3">Envío de alertas de retardo y bienvenida por WhatsApp.</p>
                    </div>
                  </div>
                  <label className="flex items-center gap-2 cursor-pointer pt-2 border-t border-border text-xs font-bold text-text-2">
                    <input type="checkbox" defaultChecked className="rounded border-slate-300 text-accent" />
                    Habilitar notificaciones WhatsApp
                  </label>
                </div>

                <div className="p-5 rounded-2xl border border-border bg-page/50 space-y-3">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-accent-soft text-accent flex items-center justify-center font-bold">
                      <Smartphone size={20} />
                    </div>
                    <div>
                      <h4 className="text-sm font-bold text-text-1">Push PWA</h4>
                      <p className="text-xs text-text-3">Notificaciones instantáneas en la App instalada.</p>
                    </div>
                  </div>
                  <label className="flex items-center gap-2 cursor-pointer pt-2 border-t border-border text-xs font-bold text-text-2">
                    <input type="checkbox" defaultChecked className="rounded border-slate-300 text-accent" />
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

        {activeTab === 'permisos' && esAdmin && (
          <div className="animate-in fade-in duration-200">
            <MatrizDePermisos />
          </div>
        )}
      </div>
    </div>
  );
};
