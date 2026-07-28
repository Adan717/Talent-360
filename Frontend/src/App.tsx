import React, { useState, useEffect, useRef, Suspense, lazy } from 'react';
import type { AppModule } from './types';
import { Routes, Route, Navigate, useSearchParams, useNavigate } from 'react-router-dom';
import { 
  LayoutDashboard, Users, GraduationCap, Clock, 
  CheckSquare, Globe, Terminal, ChevronLeft, Menu, Briefcase, ListTodo,
  FileText, X, Lock, Sparkles, ShieldCheck, Zap, Settings, User,
  Coffee, Calendar, MapPin, Heart, Bell, Database, Receipt, Scale, Activity
} from 'lucide-react';
import { useAppStore } from './store/useAppStore';
import axiosInstance from './lib/axios';
import { clearClockLocalCache } from './lib/clockCache';

import { LoadingScreen } from './components/ui/LoadingScreen';
import { ColorMap } from './components/SaaSAccountSettings';

const getIndicatorColor = (modId: string, systemSettings: any) => {
  const cust = systemSettings?.moduleCustomizations?.[modId];
  if (cust?.color && ColorMap[cust.color]) {
    return ColorMap[cust.color].hex;
  }
  switch (modId) {
    case 'asistencia': return '#10b981'; // emerald
    case 'operativo': return '#2563eb'; // blue
    case 'reportes': return '#10b981'; // emerald
    case 'ats': return '#8a2be2'; // purple
    case 'academia': return '#0ea5e9'; // sky
    case 'documentos': return '#eab308'; // yellow
    case 'facturacion': return '#10b981'; // emerald
    case 'lft': return '#f59e0b'; // amber
    default: return '#2563eb';
  }
};

// Mega-Módulos Cargados de Forma Dinámica (Lazy Loading)
const RelojChecador = lazy(() => import('./components/RelojChecador'));
const RecursosHumanos = lazy(() => import('./components/RecursosHumanos'));
const DashboardTalent360 = lazy(() => import('./components/DashboardTalent360'));
const PanelSimulador = lazy(() => import('./components/reloj/PanelSimulador'));
const PanelTareasRutinas = lazy(() => import('./components/tareas_rutinas/PanelTareasRutinas').then(module => ({ default: module.PanelTareasRutinas })));
const WebPublica = lazy(() => import('./components/WebPublica').then(module => ({ default: module.WebPublica })));
const GestorAcademia = lazy(() => import('./components/GestorAcademia').then(module => ({ default: module.GestorAcademia })));
const AtsManager = lazy(() => import('./components/AtsManager').then(module => ({ default: module.AtsManager })));
const ReportesManager = lazy(() => import('./components/ReportesManager'));
const SaaSAccountSettings = lazy(() => import('./components/SaaSAccountSettings').then(m => ({ default: m.SaaSAccountSettings })));
const GestorDocumentos = lazy(() => import('./components/GestorDocumentos').then(module => ({ default: module.GestorDocumentos })));
const FacturacionManager = lazy(() => import('./components/FacturacionManager').then(m => ({ default: m.FacturacionManager })));
const LftManager = lazy(() => import('./components/LftManager'));
const OrgVaultManager = lazy(() => import('./components/OrgVaultManager').then(m => ({ default: m.OrgVaultManager })));
const WebPublicaOrganizacion = lazy(() => import('./components/WebPublicaOrganizacion').then(m => ({ default: m.WebPublicaOrganizacion })));
const MonitorActividadesTiempoReal = lazy(() => import('./components/MonitorActividadesTiempoReal').then(m => ({ default: m.MonitorActividadesTiempoReal })));
import { ModuleUnlockModal } from './components/ModuleUnlockModal';
import { PromotionStoreDock } from './components/PromotionStoreDock';
import { HeaderStats } from './components/HeaderStats';
import { OnboardingWizard } from './components/OnboardingWizard';
import { EmployeeMobileOnboarding } from './components/EmployeeMobileOnboarding';
import { SaaSPlatformAdmin } from './components/SaaSPlatformAdmin';
import { SaaSLandingPage } from './components/SaaSLandingPage';
import { Login } from './components/Login';
import { ProtectedRoute } from './components/ProtectedRoute';
import { MyAccountModal } from './components/MyAccountModal';

const IconMap: Record<string, React.ReactNode> = {
  LayoutDashboard: <LayoutDashboard size={20} />,
  Users: <Users size={20} />,
  Clock: <Clock size={20} />,
  CheckSquare: <ListTodo size={20} />,
  ListTodo: <ListTodo size={20} />,
  Briefcase: <Briefcase size={20} />,
  FileText: <FileText size={20} />,
  GraduationCap: <GraduationCap size={20} />,
  Coffee: <Coffee size={20} />,
  Calendar: <Calendar size={20} />,
  MapPin: <MapPin size={20} />,
  Heart: <Heart size={20} />,
  Bell: <Bell size={20} />,
  Database: <Database size={20} />,
  Globe: <Globe size={20} />,
  Activity: <Activity size={20} />
};

const RootRoute = () => {
  const [searchParams] = useSearchParams();
  const isLanding = searchParams.get('landing') === 'true';
  const { currentUser, isLoadingDB } = useAppStore();
  const hasToken = !!localStorage.getItem('talent_auth_token');

  if (isLanding) {
    return <SaaSLandingPage />;
  }

  if (!hasToken) {
    return <SaaSLandingPage />;
  }

  if (isLoadingDB && currentUser?.role === 'Loading') {
    return <LoadingScreen message="Cargando..." fullScreen={true} />;
  }

  if (currentUser && currentUser.tenant_id === null && currentUser.system_role !== 'platform_admin' && currentUser.system_role !== 'support_agent') {
    return <SaaSLandingPage />;
  }

  if (currentUser?.system_role === 'platform_admin') {
    return <Navigate to="/superadmin" replace />;
  }
  if (currentUser?.system_role === 'support_agent') {
    return <Navigate to="/soporte" replace />;
  }
  if (currentUser?.system_role === 'empleado') {
    return <Navigate to="/empleado" replace />;
  }

  return <MainLayout />;
};

function MainLayout() {
  const [activeModule, setActiveModule] = useState<string>('dashboard');
  const [isSidebarOpen, setIsSidebarOpen] = useState(true);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [showOnboarding, setShowOnboarding] = useState(false);
  const [upsellModule, setUpsellModule] = useState<AppModule | null>(null); // Guardará el módulo que se intentó abrir

  const [isProfileMenuOpen, setIsProfileMenuOpen] = useState(false);
  const [isMyAccountOpen, setIsMyAccountOpen] = useState(false);
  const [settingsTab, setSettingsTab] = useState<'profile' | 'billing' | 'modules'>('billing');
  const profileMenuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (profileMenuRef.current && !profileMenuRef.current.contains(event.target as Node)) {
        setIsProfileMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, []);

  useEffect(() => {
    const handleToggleMobileMenu = () => {
      setIsMobileMenuOpen(prev => !prev);
    };
    window.addEventListener('toggle-mobile-menu', handleToggleMobileMenu);
    return () => {
      window.removeEventListener('toggle-mobile-menu', handleToggleMobileMenu);
    };
  }, []);

  const handleLogout = () => {
    localStorage.removeItem('talent_auth_token');
    // Hallazgo 4 auditoría reloj checador (2026-07-22): limpiar caché personal del reloj
    // para evitar fuga de datos del usuario saliente en dispositivos compartidos de tienda.
    clearClockLocalCache();
    window.location.href = '/login';
  };

  const { currentTier, currentUser, systemSettings, updateSetting, simulatedTierOverride, isLoadingDB } = useAppStore();

  const activeTier = simulatedTierOverride || currentTier;

  // Check if trial is active
  const tenant = currentUser?.tenant;
  let trialActive = false;
  let daysRemaining = 0;
  if (tenant && !simulatedTierOverride) {
    if (tenant.subscription_status === 'trial' || !tenant.subscription_status) {
      if (tenant.trial_ends_at) {
        const endsAt = new Date(tenant.trial_ends_at);
        const diff = endsAt.getTime() - Date.now();
        if (diff > 0) {
          trialActive = true;
          daysRemaining = Math.max(0, Math.ceil(diff / (1000 * 60 * 60 * 24)));
        }
      }
    }
  }

  useEffect(() => {
    const handleOpenWizard = () => setShowOnboarding(true);
    window.addEventListener('open-onboarding-wizard', handleOpenWizard);
    return () => window.removeEventListener('open-onboarding-wizard', handleOpenWizard);
  }, []);

  useEffect(() => {
    if (currentUser && currentUser.role !== 'Loading' && !isLoadingDB) {
      const completedFlag = systemSettings?.onboarding_completed === true || 
                            systemSettings?.onboarding_completed === 'true' || 
                            systemSettings?.onboarding_completed === 1 || 
                            systemSettings?.onboarding_completed === '1';

      if (completedFlag) {
        setShowOnboarding(false);
      } else {
        setShowOnboarding(true);
      }
    }
  }, [currentUser, systemSettings, isLoadingDB]);

  const isModuleUnlocked = (moduleId: string) => {
    const targetModuleId = moduleId;
    if (targetModuleId === 'dashboard' || targetModuleId === 'settings' || targetModuleId === 'matrix' || targetModuleId === 'organizacion') {
      return true;
    }
    if (currentUser?.system_role === 'platform_admin' || currentUser?.role === 'platform_admin') {
      return true;
    }
    if (activeTier === 'enterprise') {
      return true;
    }
    if (trialActive) {
      return true;
    }
    
    const activeMods = systemSettings?.active_modules || [];

    if (activeTier === 'freemium') {
      const freeAllowed = systemSettings?.freemium_allowed_modules || ['reloj', 'rrhh', 'operativo', 'lft', 'organizacion'];
      return freeAllowed.includes(targetModuleId) || activeMods.includes(targetModuleId);
    }
    
    if (activeTier === 'pro') {
      const proBase = ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'lft', 'organizacion'];
      return proBase.includes(targetModuleId) || activeMods.includes(targetModuleId);
    }
    
    return false;
  };

  // Estructura de Mega-Módulos del SaaS
  const modules: AppModule[] = [
    {
      id: 'dashboard',
      title: 'Centro de Mando',
      desc: 'Indicadores globales',
      icon: <LayoutDashboard size={20} />,
      color: 'bg-blue-50 text-blue-600 border-blue-100',
      minTier: 'freemium',
      version: 'v3.0'
    },
    {
      id: 'monitor',
      title: 'Monitor 360',
      desc: 'Supervisión Tiempo Real',
      icon: <Activity size={20} />,
      color: 'bg-rose-50 text-rose-600 border-rose-100',
      minTier: 'freemium',
      version: 'v1.0'
    },
    {
      id: 'rrhh',
      title: 'Directorio Digital',
      desc: 'Estructura y Contratos',
      icon: <Users size={20} />,
      color: 'bg-indigo-50 text-indigo-600 border-indigo-100',
      minTier: 'freemium',
      version: 'v3.1'
    },
    {
      id: 'reloj',
      title: 'Reloj Checador',
      desc: 'Asistencia y Ley Silla',
      icon: <Clock size={20} />,
      color: 'bg-emerald-50 text-emerald-600 border-emerald-100',
      minTier: 'freemium',
      version: 'v4.3'
    },
    {
      id: 'operativo',
      title: 'Tareas IA',
      desc: 'Automatiza Rutinas',
      icon: <ListTodo size={20} />,
      color: 'bg-blue-50 text-blue-600 border-blue-100',
      minTier: 'freemium',
      version: 'v1.5'
    },
    {
      id: 'reportes',
      title: 'Reportes IA',
      desc: 'Analítica Nómina e incidencias',
      icon: <FileText size={20} />,
      color: 'bg-emerald-50 text-emerald-600 border-emerald-100',
      minTier: 'pro',
      version: 'v2.0'
    },
    {
      id: 'ats',
      title: 'Bolsa de Trabajo ATS',
      desc: 'Vacantes y Prospectos',
      icon: <Briefcase size={20} />,
      color: 'bg-purple-50 text-purple-600 border-purple-100',
      minTier: 'pro',
      version: 'v1.1'
    },
    {
      id: 'academia',
      title: 'Academia 360',
      desc: 'Inducción y Capacitación',
      icon: <GraduationCap size={20} />,
      color: 'bg-sky-50 text-sky-600 border-sky-100',
      minTier: 'enterprise',
      version: 'v2.8'
    },
    {
      id: 'documentos',
      title: 'Archivo Digital',
      desc: 'Expedientes y Manuales',
      icon: <FileText size={20} />,
      color: 'bg-yellow-50 text-yellow-600 border-yellow-100',
      minTier: 'enterprise',
      version: 'v1.0'
    },
    {
      id: 'facturacion',
      title: 'Nómina CFDI 4.0',
      desc: 'Timbrado masivo del SAT',
      icon: <Receipt size={20} />,
      color: 'bg-emerald-50 text-emerald-600 border-emerald-100',
      minTier: 'pro',
      version: 'v1.0'
    },
    {
      id: 'matrix',
      title: 'Matrix QA',
      desc: 'Entorno de simulación',
      icon: <Terminal size={20} />,
      color: 'bg-slate-100 text-slate-800 border-slate-200',
      minTier: 'freemium',
      version: 'v1.0'
    },
    {
      id: 'lft',
      title: 'Ley Federal del Trabajo',
      desc: 'Reglamento y tolerancias',
      icon: <Scale size={20} />,
      color: 'bg-amber-50 text-amber-650 border-amber-100',
      minTier: 'pro',
      version: 'v1.0'
    },
    {
      id: 'organizacion',
      title: 'Organigrama y SOP',
      desc: 'Procesos, Puestos y Wiki',
      icon: <Globe size={20} />,
      color: 'bg-indigo-50 text-indigo-600 border-indigo-100',
      minTier: 'pro',
      version: 'v1.0'
    },
    {
      id: 'settings',
      title: 'Configuración',
      desc: 'Ajustes del sistema',
      icon: <Settings size={20} />,
      color: 'bg-slate-100 text-slate-800 border-slate-200',
      minTier: 'freemium',
      version: 'v1.2'
    }
  ];

  // Aplicar personalizaciones dinámicas del administrador
  const customizedModules = modules.map(mod => {
    const customizations = systemSettings?.moduleCustomizations?.[mod.id];
    if (customizations) {
      const customColor = customizations.color && ColorMap[customizations.color];
      return {
        ...mod,
        title: customizations.title || mod.title,
        desc: customizations.desc || mod.desc,
        icon: customizations.iconName && IconMap[customizations.iconName] 
          ? IconMap[customizations.iconName] 
          : mod.icon,
        color: customColor ? customColor.sidebar : mod.color
      };
    }
    return mod;
  });

  const activeModuleData = customizedModules.find(m => m.id === activeModule);

  const visibleModules = customizedModules.filter(mod => {
    // Filtrar módulos que el administrador decidió ocultar de la barra lateral
    const hiddenModules = systemSettings?.hiddenMenuModules || [];
    if (hiddenModules.includes(mod.id)) {
      return false;
    }
    if (currentUser?.role === 'supervisor') {
      return !['reportes', 'matrix', 'settings'].includes(mod.id);
    }
    return true;
  });

  return (
    <div className="flex h-[100dvh] bg-slate-50 font-sans overflow-hidden selection:bg-blue-100 relative">
      
      {/* Onboarding Overlay */}
      {showOnboarding && (
        <OnboardingWizard 
          onComplete={async () => {
            setShowOnboarding(false);
            try {
              await updateSetting('onboarding_completed', true);
            } catch (e) {
              console.error("Error setting onboarding completed:", e);
            }
          }} 
        />
      )}

      {/* OVERLAY PARA MÓVILES (Oscurece el fondo cuando el menú está abierto) */}
      {isMobileMenuOpen && (
        <div 
          className="fixed inset-0 bg-slate-900/60 z-40 lg:hidden backdrop-blur-sm transition-opacity"
          onClick={() => setIsMobileMenuOpen(false)}
        />
      )}

      {/* SIDEBAR LATERAL (SAAS LAYOUT RESPONSIVO) */}
      <aside className={`
        fixed lg:static inset-y-0 left-0 z-50
        bg-white border-r border-slate-200 transition-all duration-300 flex flex-col
        ${isSidebarOpen ? 'w-64' : 'w-20'}
        ${isMobileMenuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'}
      `}>
        {/* Header Sidebar */}
        <div className="min-h-20 py-3 flex flex-col justify-center px-4 border-b border-slate-100 relative">
          <div className={`flex items-start gap-3 overflow-hidden ${!isSidebarOpen && 'justify-center w-full'}`}>
            <div className="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center flex-shrink-0 shadow-sm mt-1 overflow-hidden">
              {systemSettings?.company_logo ? (
                <img src={systemSettings.company_logo} alt="Logo" className="w-full h-full object-contain p-0.5 bg-white" />
              ) : (
                <span className="text-white font-black text-xl">T</span>
              )}
            </div>
            {isSidebarOpen && (
              <div className="flex flex-col overflow-hidden text-left">
                <div className="flex items-center gap-1.5">
                  <h1 className="text-lg font-black text-slate-900 tracking-tight whitespace-nowrap leading-none">
                    Talent <span className="text-blue-600">360</span>
                  </h1>
                  <span className="text-[9px] font-black tracking-widest text-slate-500 bg-slate-100 border border-slate-200 px-1 py-0.2 rounded">v2.5</span>
                </div>
              </div>
            )}
          </div>
          {/* Botón Cerrar en Móviles */}
          <button 
            className="lg:hidden absolute right-4 top-1/2 -translate-y-1/2 p-2 text-slate-400 hover:text-slate-600"
            onClick={() => setIsMobileMenuOpen(false)}
          >
            <X size={20} />
          </button>
        </div>

        {/* Scrollable Navigation */}
        <div className="flex-1 overflow-y-auto py-4 px-3 space-y-1 custom-scrollbar">
          <p className={`text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 px-2 ${!isSidebarOpen && 'text-center'}`}>
            {isSidebarOpen ? 'Módulos' : 'Apps'}
          </p>
          
          {visibleModules.map(mod => {
            const isActive = activeModule === mod.id;
            const hasPermission = isModuleUnlocked(mod.id);
            
            return (
              <button
                key={mod.id}
                onClick={() => { 
                  if (hasPermission) {
                    setActiveModule(mod.id); setIsMobileMenuOpen(false); 
                  } else {
                    setUpsellModule(mod);
                  }
                }}
                className={`
                  w-full flex items-center gap-3 p-3 rounded-xl transition-all duration-200 group relative
                  ${isActive 
                    ? mod.color + ' shadow-sm' 
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                  }
                  ${!hasPermission ? 'opacity-60 hover:opacity-80' : ''}
                `}
                title={!isSidebarOpen ? mod.title : undefined}
              >
                <div className={`
                  w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors
                  ${isActive ? 'bg-white/60 shadow-sm' : (hasPermission ? 'bg-white text-slate-500 border border-slate-200 group-hover:border-slate-300' : 'bg-slate-100 text-slate-400 border border-slate-200')}
                `}>
                  {hasPermission ? mod.icon : <Lock size={18} />}
                </div>
                
                {isSidebarOpen && (
                  <div className="flex flex-col text-left w-full pr-2">
                    <div className="flex items-center justify-between w-full">
                      <span className={`text-sm font-bold transition-colors
                        ${isActive ? '' : 'text-slate-700 group-hover:text-slate-900'}
                      `}>
                        {mod.title}
                      </span>
                      {mod.version && (
                        <span className={`text-[9px] font-black tracking-widest px-1.5 py-0.5 rounded-md whitespace-nowrap ml-2 ${isActive ? 'bg-white/50 text-blue-800' : 'bg-slate-200/50 text-slate-500'}`}>
                          {mod.version}
                        </span>
                      )}
                      {trialActive && mod.minTier !== 'freemium' && (
                        <span className="text-[8px] font-extrabold tracking-wider bg-amber-500 text-white px-1.5 py-0.5 rounded uppercase ml-2 whitespace-nowrap">
                          Prueba
                        </span>
                      )}
                    </div>
                    <span className={`text-[10px] ${isActive ? 'opacity-90 font-medium' : 'text-slate-400'}`}>
                      {mod.desc}
                    </span>
                  </div>
                )}
                {isActive && isSidebarOpen && (
                  <div 
                    className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 rounded-r-full" 
                    style={{ backgroundColor: getIndicatorColor(mod.id, systemSettings) }}
                  />
                )}
              </button>
            );
          })}
        </div>

        {/* Footer Sidebar (Collapse Toggle) */}
        <div className="p-4 border-t border-slate-100 hidden lg:flex justify-end">
          <button 
            onClick={() => setIsSidebarOpen(!isSidebarOpen)}
            className="p-2 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
          >
            <ChevronLeft size={20} className={`transition-transform duration-300 ${!isSidebarOpen && 'rotate-180'}`} />
          </button>
        </div>
      </aside>

      {/* MAIN CONTENT AREA */}
      <main className="flex-1 flex flex-col min-w-0 bg-slate-50 relative z-0">
        {/* Top Navbar */}
        <header className="bg-white border-b border-slate-200 shadow-sm relative z-30 flex-shrink-0">
          {/* Unified Responsive Header */}
          <div className="h-20 flex items-center justify-between px-4 lg:px-8">
            {/* Left Section: Hamburger + Module Icon + Title & Description */}
            <div className="flex items-center gap-2 sm:gap-3.5 min-w-0">
              {/* Hamburger Button (visible on mobile and tablet < lg - larger size for accessibility) */}
              <button 
                className="lg:hidden p-2 -ml-2 rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition-colors focus:outline-none shrink-0"
                onClick={() => setIsMobileMenuOpen(true)}
              >
                <Menu className="w-7 h-7 sm:w-8 sm:h-8" />
              </button>

              {localStorage.getItem('platform_admin_token') && (
                <button 
                  onClick={() => {
                    const originalToken = localStorage.getItem('platform_admin_token');
                    if (originalToken) {
                      localStorage.setItem('talent_auth_token', originalToken);
                      localStorage.removeItem('platform_admin_token');
                      window.location.href = '/superadmin';
                    }
                  }}
                  className="bg-amber-500 hover:bg-amber-600 text-white font-black text-[9px] sm:text-xs px-2 py-1 sm:px-3 sm:py-1.5 rounded-xl shadow-md transition-colors flex items-center gap-1.5 shrink-0 animate-pulse"
                >
                  <ShieldCheck size={12} />
                  <span className="hidden sm:inline">Regresar a SuperAdmin</span>
                  <span className="sm:hidden">Super</span>
                </button>
              )}

              {/* Active Module Icon (Pure colored icon, no background box, extra large focal point) */}
              {activeModuleData?.icon && (
                <div className={`${activeModuleData.color.split(' ').find((c: string) => c.startsWith('text-')) || 'text-slate-600'} shrink-0 flex items-center justify-center [&>svg]:w-[36px] [&>svg]:h-[36px] sm:[&>svg]:w-[48px] sm:[&>svg]:h-[48px] lg:[&>svg]:w-[56px] lg:[&>svg]:h-[56px]`}>
                  {activeModuleData.icon}
                </div>
              )}

              {/* Title & Description (Enlarged and clean, no clutter) */}
              <div className="flex flex-col min-w-0 text-left justify-center">
                <h2 className="text-xl sm:text-3xl lg:text-4xl font-black text-slate-900 tracking-tight leading-tight truncate">
                  {activeModuleData?.title}
                </h2>
                {activeModuleData?.desc && (
                  <p className="text-[10px] sm:text-sm text-slate-500 font-bold mt-1.5 truncate leading-none">
                    {activeModuleData.desc}
                  </p>
                )}
              </div>
            </div>

            {/* Right Section: User Profile & Subscription License & Trial Countdown */}
            <div className="flex items-center gap-2 sm:gap-4 shrink-0">
              {/* Trial Countdown Indicator (Dynamic pill badge next to profile) */}
              {trialActive && (
                <div className="flex flex-col items-end shrink-0 select-none">
                  <span className="bg-gradient-to-r from-emerald-500 to-teal-500 text-white text-[8px] sm:text-xs font-black px-2 sm:px-3 py-1.5 rounded-full shadow-md shadow-emerald-500/20 flex items-center gap-1 sm:gap-1.5 animate-pulse leading-none border border-emerald-400/20 select-none">
                    <span className="w-1.5 h-1.5 rounded-full bg-white animate-ping shrink-0"></span>
                    <span className="hidden xs:inline">Prueba:</span> {daysRemaining} {daysRemaining === 1 ? 'día' : 'días'}
                  </span>
                </div>
              )}

              <div className="relative" ref={profileMenuRef}>
                <button 
                  onClick={() => setIsProfileMenuOpen(!isProfileMenuOpen)}
                  className="flex items-center gap-2 sm:gap-3 hover:bg-slate-50 p-1 sm:p-1.5 rounded-2xl transition-colors focus:outline-none select-none text-left"
                >
                  <div className="hidden md:flex flex-col items-end mr-1 text-right">
                    <span className="text-sm font-bold text-slate-800 leading-none">{currentUser?.name || 'Admin CEO'}</span>
                    <span className="text-[10px] text-blue-600 font-semibold bg-blue-50 px-1.5 py-0.5 rounded-md mt-1">
                      {currentUser?.role === 'admin' ? 'Admin / Gerencia' : currentUser?.role === 'supervisor' ? 'Supervisor' : 'Colaborador'}
                    </span>
                  </div>
                  
                  {/* Profile Avatar and Subscription License underneath (Enlarged) */}
                  <div className="flex flex-col items-center shrink-0">
                    <div className="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-slate-200 border-2 border-white shadow-sm overflow-hidden relative">
                      <img src={currentUser?.avatar || "https://i.pravatar.cc/150?img=11"} alt="Avatar" className="w-full h-full object-cover" />
                      <div className="absolute bottom-0 right-0 w-2.5 h-2.5 bg-emerald-500 rounded-full border-2 border-white"></div>
                    </div>
                    {/* License Tier Badge */}
                    <span className={`text-[7px] sm:text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full leading-none mt-1 border select-none ${
                      activeTier === 'enterprise' ? 'bg-purple-100 text-purple-700 border-purple-200' :
                      activeTier === 'pro' ? 'bg-amber-100 text-amber-700 border-amber-200' :
                      'bg-slate-100 text-slate-600 border-slate-200'
                    }`}>
                      {activeTier === 'enterprise' ? 'Enterprise' : activeTier === 'pro' ? 'Premium' : 'Freemium'}
                    </span>
                  </div>
                </button>

                {isProfileMenuOpen && (
                  <div className="absolute right-0 mt-2 w-52 sm:w-56 bg-white border border-slate-200 rounded-2xl shadow-xl py-2 z-50 animate-in fade-in slide-in-from-top-2 duration-200">
                    <div className="px-4 py-2 border-b border-slate-100">
                      <p className="text-xs sm:text-sm font-bold text-slate-800">Sesión Activa</p>
                      <p className="text-[10px] sm:text-xs text-slate-500 font-medium truncate">{currentUser?.email || 'admin@decorarte360.com'}</p>
                    </div>
                    <div className="p-1.5 space-y-0.5">
                      <button 
                        onClick={() => {
                          setIsMyAccountOpen(true);
                          setIsProfileMenuOpen(false);
                        }}
                        className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center gap-2"
                      >
                        <Users size={14} className="text-slate-400" />
                        Mi Cuenta
                      </button>
                      <button 
                        onClick={() => {
                          setSettingsTab('profile');
                          setActiveModule('settings');
                          setIsProfileMenuOpen(false);
                        }}
                        className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center gap-2"
                      >
                        <Settings size={14} className="text-slate-400" />
                        Perfil de la Empresa
                      </button>
                      <button 
                        onClick={() => {
                          setSettingsTab('billing');
                          setActiveModule('settings');
                          setIsProfileMenuOpen(false);
                        }}
                        className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center gap-2"
                      >
                        <Settings size={14} className="text-slate-400" />
                        Configuración de Empresa
                      </button>
                      <div className="border-t border-slate-100 my-1 sm:my-1.5"></div>
                      <button 
                        onClick={handleLogout}
                        className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-bold text-rose-600 hover:bg-rose-50 transition-colors flex items-center gap-2"
                      >
                        <Lock size={14} className="text-rose-500" />
                        Cerrar Sesión
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        </header>

        {/* Dynamic Canvas */}
        <div className="flex-1 overflow-y-auto p-4 lg:p-8 custom-scrollbar relative z-10">
          <Suspense fallback={<LoadingScreen message="Cargando Módulo..." />}>
            {activeModule === 'dashboard' && <DashboardTalent360 setActiveModule={setActiveModule} />}
            {activeModule === 'monitor' && <MonitorActividadesTiempoReal setActiveModule={setActiveModule} />}
            {activeModule === 'rrhh' && <RecursosHumanos />}
            {activeModule === 'ats' && <AtsManager />}
            {activeModule === 'operativo' && <PanelTareasRutinas />}
            {activeModule === 'academia' && <GestorAcademia />}
{activeModule === 'documentos' && <GestorDocumentos />}
            {activeModule === 'organizacion' && <OrgVaultManager />}
            {activeModule === 'facturacion' && <FacturacionManager />}
            {activeModule === 'reloj' && <RelojChecador />}
            {activeModule === 'reportes' && <ReportesManager />}
            {activeModule === 'matrix' && <PanelSimulador />}
            {activeModule === 'lft' && <LftManager />}
            {activeModule === 'settings' && <SaaSAccountSettings initialTab={settingsTab} />}
          </Suspense>
        </div>
      </main>

      <MyAccountModal
        isOpen={isMyAccountOpen}
        onClose={() => setIsMyAccountOpen(false)}
      />

      <ModuleUnlockModal
        isOpen={!!upsellModule}
        onClose={() => setUpsellModule(null)}
        moduleData={upsellModule ? {
          id: upsellModule.id,
          title: upsellModule.title,
          desc: upsellModule.desc,
          icon: upsellModule.icon,
          pricePerColab: upsellModule.id === 'ats' ? 29 : upsellModule.id === 'academia' ? 49 : 19
        } : null}
        onSuccess={async () => {
          await useAppStore.getState().fetchState();
        }}
      />

      <PromotionStoreDock
        onOpenStore={() => {
          setActiveModule('settings');
          setSettingsTab('modules');
        }}
      />
    </div>
  );
}

function App() {
  const fetchState = useAppStore(state => state.fetchState);
  const navigate = useNavigate();
  const [banReason, setBanReason] = useState<string | null>(null);

  useEffect(() => {
    const handleBan = (e: Event) => {
      const customEvent = e as CustomEvent;
      setBanReason(customEvent.detail || 'Dispositivo suspendido por políticas de abuso.');
    };
    window.addEventListener('device-banned', handleBan);
    return () => {
      window.removeEventListener('device-banned', handleBan);
    };
  }, []);

  useEffect(() => {
    if (localStorage.getItem('talent_auth_token')) {
      fetchState().catch(err => {
        // If error status is 403 and payload says Device Banned, axios interceptor will dispatch the event
        console.error("Error loading app state:", err);
      });
    }
  }, [fetchState]);

  if (banReason) {
    return (
      <div className="w-screen h-[100dvh] bg-slate-950 flex flex-col items-center justify-center p-6 text-center select-none font-sans relative overflow-hidden">
        {/* Glow Effects */}
        <div className="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[350px] h-[350px] bg-rose-500/10 rounded-full blur-[100px] pointer-events-none"></div>
        
        <div className="bg-slate-900/40 border border-rose-500/20 backdrop-blur-xl p-8 rounded-3xl max-w-md w-full shadow-2xl relative z-10 animate-in zoom-in-95">
          <div className="w-16 h-16 bg-rose-500/10 border border-rose-500/30 rounded-2xl flex items-center justify-center mx-auto mb-6 text-rose-500 shadow-inner">
            <Lock size={32} className="animate-pulse" />
          </div>
          
          <h1 className="text-2xl font-black text-white tracking-tight leading-none mb-3">
            Acceso <span className="text-rose-500">Suspendido</span>
          </h1>
          
          <p className="text-slate-400 text-xs leading-relaxed mb-6">
            {banReason}
          </p>
          
          <div className="bg-slate-950/50 border border-slate-800/80 rounded-xl p-4 mb-6 text-left">
            <h2 className="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1">Políticas de Abuso</h2>
            <p className="text-slate-400 text-[11px] leading-relaxed">
              Para prevenir la manipulación de registros y el uso no autorizado de múltiples cuentas gratuitas desde la misma ubicación física, este equipo y red han sido restringidos.
            </p>
          </div>
          
          <button 
            onClick={() => window.location.reload()}
            className="w-full py-3 bg-gradient-to-r from-rose-600 to-rose-700 hover:from-rose-500 hover:to-rose-600 text-white font-bold text-xs uppercase tracking-widest rounded-xl shadow-lg shadow-rose-900/20 transition-all duration-300 transform active:scale-95"
          >
            Reintentar Conexión
          </button>
        </div>
      </div>
    );
  }

  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Navigate to="/inicio" replace />} />
      <Route path="/inicio" element={<SaaSLandingPage />} />
      <Route path="/vacantes/:slug" element={
        <Suspense fallback={<LoadingScreen message="Cargando bolsa de trabajo..." />}>
          <WebPublica />
        </Suspense>
      } />
      <Route path="/organizacion/:tenantSlug" element={
        <Suspense fallback={<LoadingScreen message="Cargando organigrama..." />}>
          <WebPublicaOrganizacion />
        </Suspense>
      } />
      <Route path="/organizacion/:tenantSlug/:docSlug" element={
        <Suspense fallback={<LoadingScreen message="Cargando documento..." />}>
          <WebPublicaOrganizacion />
        </Suspense>
      } />
      <Route path="/superadmin" element={
        <ProtectedRoute allowedRoles={['platform_admin']}>
          <div className="min-h-[100dvh] bg-slate-50 p-3 sm:p-5 md:p-8 overflow-y-auto">
            <SaaSPlatformAdmin />
          </div>
        </ProtectedRoute>
      } />
      {/* 2026-07-25 (a petición de Francisco): Página de Soporte con su propia URL, separada
          de Plataforma Talent360 (/superadmin) — antes ambos roles compartían la misma ruta.
          Reutiliza el mismo componente (ya restringe internamente qué pestañas ve cada rol vía
          `isAdmin`, un support_agent solo ve "Soporte Técnico / Tickets"), pero ahora vive en su
          propia URL para que sea, en los hechos, una superficie de acceso distinta. */}
      <Route path="/soporte" element={
        <ProtectedRoute allowedRoles={['support_agent', 'platform_admin']}>
          <div className="min-h-[100dvh] bg-slate-50 p-3 sm:p-5 md:p-8 overflow-y-auto">
            <SaaSPlatformAdmin />
          </div>
        </ProtectedRoute>
      } />
      <Route path="/empleado" element={
        <ProtectedRoute allowedRoles={['empleado']}>
          <div className="w-screen h-[100dvh] bg-slate-50 overflow-hidden flex m-0 p-0 select-none">
            <Suspense fallback={<LoadingScreen message="Iniciando Checador..." />}>
              <RelojChecador />
            </Suspense>
          </div>
        </ProtectedRoute>
      } />
      <Route path="/invite" element={
        <EmployeeMobileOnboarding onComplete={() => navigate('/empleado')} />
      } />
      <Route path="/app" element={<RootRoute />} />
      <Route path="/" element={<RootRoute />} />
      <Route path="*" element={<Navigate to="/app" replace />} />
    </Routes>
  );
}

export default App;
