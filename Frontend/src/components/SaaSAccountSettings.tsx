import React, { useState, useEffect } from 'react';
import { 
  Building2, CreditCard, LayoutGrid, Save, Check,
  CheckCircle2, AlertCircle, ShieldCheck, 
  Smartphone, Upload, Globe, ChevronRight,
  Receipt, Database, Loader2, MessageSquare, Send, X,
  Users, Clock, CheckSquare, Briefcase, FileText, GraduationCap, ListTodo,
  Info, ArrowLeft, ArrowRight, Zap, Settings, Lock, Coffee, Monitor,
  Eye, EyeOff, Pencil, Calendar, MapPin, Heart, Bell
} from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import { BackupPanel } from './BackupPanel';
import { CompanySettingsPanel } from './CompanySettingsPanel';
import axiosInstance from '../lib/axios';

export const ColorMap: Record<string, { sidebar: string; hex: string; text: string }> = {
  violet: { sidebar: 'bg-violet-50 text-violet-650 border-violet-100 dark:bg-violet-955/40 dark:text-violet-400 dark:border-violet-900/30', hex: '#8a2be2', text: 'text-violet-655' },
  blue: { sidebar: 'bg-blue-50 text-blue-600 border-blue-100 dark:bg-blue-955/40 dark:text-blue-400 dark:border-blue-900/30', hex: '#2563eb', text: 'text-blue-600' },
  emerald: { sidebar: 'bg-emerald-50 text-emerald-600 border-emerald-100 dark:bg-emerald-955/40 dark:text-emerald-400 dark:border-emerald-900/30', hex: '#10b981', text: 'text-emerald-600' },
  indigo: { sidebar: 'bg-indigo-50 text-indigo-600 border-indigo-100 dark:bg-indigo-955/40 dark:text-indigo-400 dark:border-indigo-900/30', hex: '#6366f1', text: 'text-indigo-600' },
  amber: { sidebar: 'bg-amber-50 text-amber-600 border-amber-100 dark:bg-amber-955/40 dark:text-amber-400 dark:border-amber-900/30', hex: '#f59e0b', text: 'text-amber-600' },
  rose: { sidebar: 'bg-rose-50 text-rose-600 border-rose-100 dark:bg-rose-955/40 dark:text-rose-400 dark:border-rose-900/30', hex: '#f43f5e', text: 'text-rose-600' },
  sky: { sidebar: 'bg-sky-50 text-sky-650 border-sky-100 dark:bg-sky-955/40 dark:text-sky-400 dark:border-sky-900/30', hex: '#0ea5e9', text: 'text-sky-655' },
  slate: { sidebar: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700', hex: '#64748b', text: 'text-slate-650' }
};

const IconMap: Record<string, React.ReactNode> = {
  Building2: <Building2 size={20} />,
  LayoutGrid: <LayoutGrid size={20} />,
  Globe: <Globe size={20} />,
  Receipt: <Receipt size={20} />,
  Database: <Database size={20} />,
  Users: <Users size={20} />,
  Clock: <Clock size={20} />,
  CheckSquare: <ListTodo size={20} />,
  ListTodo: <ListTodo size={20} />,
  Briefcase: <Briefcase size={20} />,
  FileText: <FileText size={20} />,
  GraduationCap: <GraduationCap size={20} />,
  Settings: <Settings size={20} />,
  Coffee: <Coffee size={20} />,
  Calendar: <Calendar size={20} />,
  MapPin: <MapPin size={20} />,
  Heart: <Heart size={20} />,
  Monitor: <Monitor size={20} />,
  Bell: <Bell size={20} />
};

const ModuleCard = ({
  mod,
  currentUser,
  systemSettings,
  trialActive,
  setEditingCustomModule,
  updateSetting,
  setSelectedModuleForDetail,
  setConfiguringModule
}: {
  mod: any;
  currentUser: any;
  systemSettings: any;
  trialActive: boolean;
  setEditingCustomModule: (mod: any) => void;
  updateSetting: (key: string, value: any) => void;
  setSelectedModuleForDetail: (mod: any) => void;
  setConfiguringModule: (mod: any) => void;
}) => {
  const activeClasses = 
    mod.tier === 'freemium' ? 'border-l-emerald-500 bg-emerald-50/10 hover:bg-emerald-100/50 hover:shadow-lg hover:shadow-emerald-500/10' :
    mod.tier === 'pro' ? 'border-l-blue-500 bg-blue-50/10 hover:bg-blue-100/50 hover:shadow-lg hover:shadow-blue-500/10' :
    'border-l-purple-500 bg-purple-50/10 hover:bg-purple-100/50 hover:shadow-lg hover:shadow-purple-500/10';

  const cardClasses = mod.active 
    ? activeClasses 
    : 'border-l-slate-300 bg-slate-50/50 opacity-80 grayscale-[20%] hover:shadow-sm';

  const versionBadgeColor = !mod.active ? 'bg-slate-200 text-slate-500' :
    mod.tier === 'freemium' ? 'bg-emerald-100 text-emerald-800' :
    mod.tier === 'pro' ? 'bg-blue-100 text-blue-800' :
    'bg-purple-100 text-purple-800';

  return (
    <div 
      className={`p-4.5 rounded-2xl border border-slate-200 border-l-4 transition-all duration-300 flex flex-col justify-between relative overflow-hidden group hover:border-slate-300 ${cardClasses}`}
    >
      {/* Fondo Temático Alusivo (Marca de Agua Dinámica) */}
      {mod.icon && React.cloneElement(mod.icon as React.ReactElement<any>, { 
        size: 110, 
        className: `absolute -right-4 -bottom-6 opacity-[0.05] pointer-events-none transform rotate-12 transition-all duration-500 group-hover:rotate-6 group-hover:scale-110 group-hover:opacity-[0.12] ${
          mod.active 
            ? (mod.tier === 'freemium' ? 'text-emerald-500' : mod.tier === 'pro' ? 'text-blue-500' : 'text-purple-500') 
            : 'text-slate-400'
        }` 
      } as any)}

      <div className="relative z-10 flex-grow">
        {/* Cabecera Principal (Horizontal): Icono Grande + Textos a la Derecha */}
        <div className="flex items-start gap-3.5 mb-3">
          {/* Icono del Módulo */}
          <div className={`w-11 h-11 rounded-xl flex items-center justify-center ${mod.iconColor} shadow-inner shrink-0 [&>svg]:w-5.5 [&>svg]:h-5.5`}>
            {mod.icon}
          </div>
          
          {/* Nombre, Versión y Descripción */}
          <div className="min-w-0 text-left">
            <div className="flex items-center gap-1.5 flex-wrap mb-0.5">
              <h4 className={`font-black text-sm sm:text-base leading-tight break-words ${mod.active ? 'text-slate-900' : 'text-slate-500'}`}>
                {mod.name}
              </h4>
              {mod.version && (
                <span className={`text-[8.5px] font-black px-1.5 py-0.2 rounded whitespace-nowrap ${versionBadgeColor}`}>
                  {mod.version}
                </span>
              )}
              {trialActive && mod.tier !== 'freemium' && (
                <span className="text-[8px] font-black px-1.5 py-0.2 rounded bg-amber-500 text-white uppercase tracking-wider whitespace-nowrap animate-pulse">
                  Prueba
                </span>
              )}
            </div>
            <p className={`text-xs font-medium leading-relaxed ${mod.active ? 'text-slate-500' : 'text-slate-400'}`}>
              {mod.desc}
            </p>
          </div>
        </div>

        {/* Fila de Acciones y Badges de Estado (Justo abajo de la descripción) */}
        <div className="flex items-center gap-2 mb-4 flex-wrap">
          {mod.moduleId && mod.active && (() => {
            const hiddenModules = systemSettings?.hiddenMenuModules || [];
            const isHidden = hiddenModules.includes(mod.moduleId);
            const isAdmin = currentUser?.role === 'admin' || currentUser?.system_role === 'platform_admin';
            
            return (
              <div className="flex items-center gap-1.5 mr-1">
                {/* Botón de Edición (Lapicito) */}
                <button
                  disabled={!isAdmin}
                  onClick={() => {
                    const defaultIcons: Record<string, string> = {
                      reloj: 'Clock',
                      rrhh: 'Users',
                      operativo: 'ListTodo',
                      ats: 'Briefcase',
                      reportes: 'FileText',
                      academia: 'GraduationCap',
                      documentos: 'FileText',
                      facturacion: 'Receipt',
                      comidas: 'Coffee',
                      portal: 'Globe',
                      matrix: 'Monitor'
                    };
                    setEditingCustomModule({
                      id: mod.moduleId,
                      title: mod.name,
                      desc: mod.desc,
                      iconName: defaultIcons[mod.moduleId] || 'LayoutGrid',
                      color: 'violet',
                      ...(systemSettings?.moduleCustomizations?.[mod.moduleId] || {})
                    });
                  }}
                  className={`p-1 rounded-full border transition-all duration-200 flex items-center justify-center hover:scale-110 shadow-xs ${
                    !isAdmin ? 'opacity-50 cursor-not-allowed bg-slate-50 text-slate-400 border-slate-200' :
                    'bg-blue-50 text-blue-600 border-blue-200 hover:bg-blue-100'
                  }`}
                  title={!isAdmin ? "Solo los administradores pueden cambiar el nombre/icono." : "Editar módulo."}
                >
                  <Pencil size={11} />
                </button>

                {/* Botón de Visibilidad (Ojo) */}
                <button
                  disabled={!isAdmin}
                  onClick={() => {
                    const newHidden = isHidden
                      ? hiddenModules.filter((id: string) => id !== mod.moduleId)
                      : [...hiddenModules, mod.moduleId];
                    updateSetting('hiddenMenuModules', newHidden);
                  }}
                  className={`p-1 rounded-full border transition-all duration-200 flex items-center justify-center hover:scale-110 shadow-xs ${
                    !isAdmin ? 'opacity-50 cursor-not-allowed bg-slate-50 text-slate-400 border-slate-200' :
                    isHidden
                      ? 'bg-rose-50 text-rose-600 border-rose-200 hover:bg-rose-100'
                      : 'bg-emerald-50 text-emerald-665 border-emerald-200 hover:bg-emerald-100'
                  }`}
                  title={!isAdmin ? "Solo los administradores pueden cambiar la visibilidad." : isHidden ? "Mostrar en menú." : "Ocultar en menú."}
                >
                  {isHidden ? <EyeOff size={11} /> : <Eye size={11} />}
                </button>
              </div>
            );
          })()}
          
          {mod.active && (
            <span className="bg-emerald-50 text-emerald-700 text-[8px] font-extrabold uppercase px-2.5 py-0.5 rounded-full border border-emerald-200/50 flex items-center gap-0.5 whitespace-nowrap">
              <CheckCircle2 size={8} /> Activo
            </span>
          )}
          
          <span className={`text-[8px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full border whitespace-nowrap ${
            mod.tier === 'freemium' ? 'bg-slate-100 text-slate-700 border-slate-200' :
            mod.tier === 'pro' ? 'bg-blue-100 text-blue-700 border-blue-200' :
            'bg-purple-100 text-purple-700 border-purple-200'
          }`}>
            {mod.tier === 'freemium' ? 'Incluido' : `Requiere ${mod.tier}`}
          </span>
        </div>
      </div>

      {/* Fila Inferior (Footer): Ver más... y Engranaje */}
      <div className="pt-3 border-t border-slate-100 flex items-center justify-between gap-2 relative z-10">
        <button 
          onClick={() => setSelectedModuleForDetail(mod)}
          className="text-xs font-bold text-blue-600 hover:text-blue-800 hover:underline transition-colors flex items-center gap-1"
        >
          Ver más...
        </button>

        {currentUser?.role === 'admin' || currentUser?.system_role === 'platform_admin' ? (
          mod.active ? (
            <button 
              onClick={() => setConfiguringModule(mod)}
              className="p-1 text-slate-500 hover:text-blue-600 hover:bg-slate-100 rounded-lg hover:rotate-90 transition-all duration-300 border-none bg-transparent cursor-pointer"
              title={`Configurar ${mod.name}`}
            >
              <Settings size={14} />
            </button>
          ) : (
            <button 
              disabled
              className="p-1 text-slate-300 cursor-not-allowed border-none bg-transparent"
              title="Requiere plan superior"
            >
              <Lock size={14} className="opacity-50" />
            </button>
          )
        ) : null}
      </div>
    </div>
  );
};

export const SaaSAccountSettings = ({ initialTab = 'billing' }: { initialTab?: 'profile' | 'billing' | 'modules' | 'backups' }) => {
  const [activeTab, setActiveTab] = useState<'profile' | 'billing' | 'modules' | 'backups'>(initialTab);
  const [selectedModuleForDetail, setSelectedModuleForDetail] = useState<any | null>(null);
  const [configuringModule, setConfiguringModule] = useState<any | null>(null);
  const [editingCustomModule, setEditingCustomModule] = useState<any | null>(null);
  const [tutorialStep, setTutorialStep] = useState<number | null>(null);

  useEffect(() => {
    setActiveTab(initialTab);
  }, [initialTab]);

  const [isSaving, setIsSaving] = useState(false);
  const [showCheckout, setShowCheckout] = useState(false);

  const { currentTier, currentUser, systemSettings, updateSetting, globalUsers, fetchState, isModuleUnlocked, isFeatureUnlocked } = useAppStore();

  const [orgName, setOrgName] = useState('');
  const [subdomain, setSubdomain] = useState('');
  const [welcomeTitle, setWelcomeTitle] = useState('');
  const [welcomeText, setWelcomeText] = useState('');
  const [companyLogo, setCompanyLogo] = useState('');
  const [timezone, setTimezone] = useState('America/Mexico_City');

  // Sync state with store/database once loaded
  useEffect(() => {
    const currentCompany = systemSettings?.company_name || currentUser?.tenant?.name || 'Mi Empresa';
    const currentSubdomain = systemSettings?.subdomain || currentUser?.tenant?.subdomain || 'miempresa';
    
    setOrgName(currentCompany);
    setSubdomain(currentSubdomain);
    setWelcomeTitle(systemSettings?.welcome_title || `¡Bienvenido a ${currentCompany}!`);
    setWelcomeText(systemSettings?.welcome_text || 'Nos emociona tenerte en el equipo. Por favor, instala esta App para tu control de asistencia.');
    setCompanyLogo(systemSettings?.company_logo || '');
    setTimezone(systemSettings?.timezone || 'America/Mexico_City');
  }, [systemSettings, currentUser]);

  // PWA Invite States
  const [showInviteModal, setShowInviteModal] = useState(false);
  const [selectedEmployeeId, setSelectedEmployeeId] = useState<number | ''>('');
  const [invitePhone, setInvitePhone] = useState('');
  const [invitePin, setInvitePin] = useState('');
  const [isGeneratingPin, setIsGeneratingPin] = useState(false);
  const [inviteFeedback, setInviteFeedback] = useState<{ type: 'success' | 'error', message: string } | null>(null);

  const employees = globalUsers.filter(u => u.system_role === 'empleado' || u.role === 'empleado' || u.system_role === 'colaborador');

  const handleSelectEmployee = (empId: number) => {
    setSelectedEmployeeId(empId);
    const emp = globalUsers.find(u => u.id === empId);
    if (emp) {
      let phone = emp.phone || '';
      if (phone.startsWith('52')) phone = phone.slice(2);
      setInvitePhone(phone);
      setInvitePin(emp.pin_code || '');
    } else {
      setInvitePhone('');
      setInvitePin('');
    }
    setInviteFeedback(null);
  };

  const handleGeneratePin = async () => {
    if (!selectedEmployeeId) return;
    setIsGeneratingPin(true);
    setInviteFeedback(null);
    try {
      const emp = globalUsers.find(u => u.id === selectedEmployeeId);
      const employeeIdToUse = emp?.employee_id || selectedEmployeeId;
      const res = await axiosInstance.post(`/admin/employees/${employeeIdToUse}/generate-pin`);
      if (res.data && res.data.pin) {
        setInvitePin(res.data.pin);
        await fetchState();
        setInviteFeedback({ type: 'success', message: 'PIN temporal generado con éxito.' });
      }
    } catch (e: any) {
      console.error(e);
      setInviteFeedback({ type: 'error', message: 'Error al generar el PIN.' });
    } finally {
      setIsGeneratingPin(false);
    }
  };

  const handleSendManualInvite = async () => {
    if (!selectedEmployeeId || !invitePin || !invitePhone) return;
    const emp = globalUsers.find(u => u.id === selectedEmployeeId);
    const employeeIdToUse = emp?.employee_id || selectedEmployeeId;
    const cleanPhone = invitePhone.replace(/\D/g, '');
    const cleanDbPhone = cleanPhone.length === 10 ? `52${cleanPhone}` : cleanPhone;
    
    if (emp && emp.phone !== cleanDbPhone) {
      try {
        await axiosInstance.put(`/employees/${employeeIdToUse}`, { phone: cleanDbPhone });
        await fetchState();
      } catch (err) {
        console.error(err);
      }
    }

    const inviteUrl = `${window.location.origin}/invite?pin=${invitePin}`;
    const message = `*TALENT 360* | ¡Bienvenido al Equipo! 👋\n\nHola, *${emp?.name || 'Colaborador'}*, te damos la más cordial bienvenida a *${orgName}*. 🏢\n\nTu cuenta ha sido registrada con éxito en nuestra plataforma de asistencia y gestión laboral. Para activar tu Reloj Checador móvil (PWA) de forma segura y configurar tu perfil, haz clic en el enlace de invitación:\n\n🔑 *Tu PIN temporal de acceso es:* ${invitePin}\n\n¡Mucho éxito en tu jornada laboral! 🚀\n\n${inviteUrl}`;

    const waUrl = `https://wa.me/${cleanDbPhone}?text=${encodeURIComponent(message)}`;
    window.open(waUrl, '_blank');
  };

  const handleSave = async () => {
    setIsSaving(true);
    try {
      await Promise.all([
        updateSetting('company_name', orgName),
        updateSetting('subdomain', subdomain),
        updateSetting('welcome_title', welcomeTitle),
        updateSetting('welcome_text', welcomeText),
        updateSetting('company_logo', companyLogo),
        updateSetting('timezone', timezone)
      ]);
      alert("Configuración guardada exitosamente.");
    } catch (e) {
      console.error(e);
      alert("Error al guardar la configuración.");
    } finally {
      setIsSaving(false);
    }
  };

  const activeEmployeesCount = Math.max(1, employees.length);
  const [selectedCycle, setSelectedCycle] = useState<'monthly' | 'yearly'>('monthly');
  const [selectedUpgradePlan, setSelectedUpgradePlan] = useState<'pro' | 'enterprise'>(currentTier === 'pro' ? 'enterprise' : 'pro');

  const handleBuyPlan = async (plan: string, cycle: 'monthly' | 'yearly' = selectedCycle) => {
    try {
      const response = await axiosInstance.post('/subscriptions/create-preference', {
        plan: plan,
        employees: activeEmployeesCount,
        billing_cycle: cycle
      });
      if (response.data.init_point) {
        window.location.href = response.data.init_point;
      } else {
        alert('Error al generar la preferencia de pago.');
      }
    } catch (e: any) {
      console.error(e);
      alert(e.response?.data?.error || 'Error al conectar con la pasarela de pagos.');
    }
  };

  const planDetails = {
    freemium: { name: 'Freemium', maxUsers: systemSettings?.freemium_max_users || 10, price: 0, color: 'text-slate-600', bg: 'bg-slate-100' },
    pro: { name: 'PRO', maxUsers: 'Ilimitados', price: 12, color: 'text-blue-600', bg: 'bg-blue-100' },
    enterprise: { name: 'Enterprise', maxUsers: 'Ilimitados', price: 499, color: 'text-purple-600', bg: 'bg-purple-100' },
  };

  const tierInfo = planDetails[currentTier as keyof typeof planDetails] || planDetails.freemium;

  // Check if trial is active
  const tenant = currentUser?.tenant;
  let trialActive = false;
  let daysRemaining = 0;
  if (tenant) {
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

  return (
    <div className="max-w-6xl mx-auto space-y-6 animate-in fade-in duration-500 pb-20">
      
      {/* Header */}
      <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-end justify-between gap-6 relative overflow-hidden">
        <div className="absolute top-0 right-0 w-64 h-64 bg-blue-50 rounded-full blur-3xl -mr-20 -mt-20 opacity-50 pointer-events-none"></div>
        <div className="relative z-10">
          <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-blue-50 border border-blue-100 text-xs font-bold text-blue-700 mb-4">
            <ShieldCheck size={14} /> Espacio de Trabajo Seguro
          </div>
          <h1 className="text-3xl font-black text-slate-900 tracking-tight">Configuración de la Cuenta</h1>
          <p className="text-slate-500 mt-2 font-medium max-w-xl">
            Gestiona los detalles de tu empresa, tu suscripción a Talent 360 y los módulos habilitados para tu equipo.
          </p>
        </div>
        <div className="relative z-10 flex gap-3">
          <button 
            onClick={handleSave}
            disabled={isSaving}
            className="bg-blue-600 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/20 disabled:opacity-70"
          >
            {isSaving ? <span className="animate-pulse">Guardando...</span> : <><Save size={18}/> Guardar Cambios</>}
          </button>
        </div>
      </div>

      {/* Navegación por Tabs */}
      <div className="flex gap-2 p-1.5 bg-slate-200/50 rounded-2xl w-fit">
        <button 
          onClick={() => setActiveTab('profile')}
          className={`flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm transition-all ${activeTab === 'profile' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
        >
          <Building2 size={16} /> Perfil de Empresa
        </button>
        <button 
          onClick={() => setActiveTab('billing')}
          className={`flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm transition-all ${activeTab === 'billing' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
        >
          <CreditCard size={16} /> Facturación y Plan
        </button>
        <button 
          onClick={() => setActiveTab('modules')}
          className={`flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm transition-all ${activeTab === 'modules' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
        >
          <LayoutGrid size={16} /> Módulos del Sistema
        </button>
        <button 
          onClick={() => setActiveTab('backups')}
          className={`flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm transition-all ${activeTab === 'backups' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
        >
          <Database size={16} /> Respaldos
        </button>
      </div>

      {/* Contenido de los Tabs */}
      <div className="mt-6">
        
        {/* TAB: PERFIL DE EMPRESA */}
        {activeTab === 'profile' && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 animate-in slide-in-from-bottom-4">
            
            <div className="lg:col-span-2 space-y-6">
              <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm">
                <h2 className="text-xl font-black text-slate-800 mb-6">Datos Generales</h2>
                <div className="space-y-5">
                  <div>
                    <label className="block text-sm font-bold text-slate-700 mb-2">Nombre de la Organización</label>
                    <input type="text" value={orgName} onChange={e => setOrgName(e.target.value)} className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none font-medium" />
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-slate-700 mb-2">Subdominio PWA</label>
                    <div className="flex border border-slate-200 rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-blue-500">
                      <div className="bg-slate-100 px-4 py-3 text-sm text-slate-500 font-bold border-r border-slate-200 flex items-center gap-2"><Globe size={16}/> https://</div>
                      <input type="text" value={subdomain} onChange={e => setSubdomain(e.target.value)} className="w-full bg-slate-50 px-4 py-3 font-medium outline-none text-blue-600" />
                      <div className="bg-slate-100 px-4 py-3 text-sm text-slate-500 font-bold border-l border-slate-200">.talent360.com</div>
                    </div>
                    <p className="text-xs text-slate-400 mt-2">Esta es la URL que tus empleados usarán para entrar al sistema.</p>
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-slate-700 mb-2">Zona Horaria del Reloj Checador</label>
                    <select
                      value={timezone}
                      onChange={e => setTimezone(e.target.value)}
                      className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none font-medium"
                    >
                      <option value="America/Mexico_City">Tiempo del Centro - CDMX, GDL, MTY (América/Mexico_City)</option>
                      <option value="America/Cancun">Tiempo del Sureste - Cancún, Q. Roo (América/Cancun)</option>
                      <option value="America/Tijuana">Tiempo del Noroeste - Tijuana, B.C. (América/Tijuana)</option>
                      <option value="America/Mazatlan">Tiempo del Pacífico - Mazatlán, Chihuahua, Sinaloa (América/Mazatlan)</option>
                      <option value="America/Hermosillo">Tiempo de Sonora - Hermosillo (América/Hermosillo)</option>
                    </select>
                    <p className="text-xs text-slate-400 mt-2">Determina el huso horario oficial con el que se registrarán las entradas y salidas de los colaboradores.</p>
                  </div>
                </div>
              </div>

              <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm">
                <div className="flex justify-between items-start mb-6">
                  <div>
                    <h2 className="text-xl font-black text-slate-800">Mensaje de Onboarding</h2>
                    <p className="text-sm text-slate-500">Lo que ven tus empleados al instalar la PWA.</p>
                  </div>
                  <Smartphone className="text-slate-300" size={32} />
                </div>
                <div className="space-y-5">
                  <div>
                    <label className="block text-sm font-bold text-slate-700 mb-2">Título de Bienvenida</label>
                    <input type="text" value={welcomeTitle} onChange={e => setWelcomeTitle(e.target.value)} className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none font-medium" />
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-slate-700 mb-2">Texto Introductorio</label>
                    <textarea rows={3} value={welcomeText} onChange={e => setWelcomeText(e.target.value)} className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none font-medium resize-none" />
                  </div>
                </div>
              </div>
            </div>

            <div className="space-y-6">
              <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm flex flex-col items-center text-center">
                <h2 className="text-sm font-bold text-slate-700 mb-4 w-full text-left">Logotipo Corporativo</h2>
                <div 
                  onClick={() => {
                    if (!isFeatureUnlocked('custom_logo')) {
                      alert("La personalización de logotipo está disponible únicamente en el Plan PRO/Enterprise o si fue activada para tu plan. Por favor, actualiza tu plan en Facturación.");
                      return;
                    }
                    document.getElementById('logo-upload-input')?.click();
                  }}
                  className="w-32 h-32 bg-slate-50 border-2 border-dashed border-slate-200 rounded-3xl flex flex-col items-center justify-center text-slate-400 mb-4 hover:bg-blue-50 hover:border-blue-300 transition-colors cursor-pointer group relative overflow-hidden"
                >
                  {companyLogo ? (
                    <img src={companyLogo} alt="Logo preview" className="w-full h-full object-contain p-2" />
                  ) : (
                    <>
                      <Upload size={24} className="mb-2 group-hover:text-blue-500 group-hover:-translate-y-1 transition-all" />
                      <span className="text-xs font-bold">Subir Imagen</span>
                    </>
                  )}
                </div>
                <input 
                  id="logo-upload-input" 
                  type="file" 
                  accept="image/*" 
                  className="hidden" 
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) {
                      const reader = new FileReader();
                      reader.onloadend = () => {
                        setCompanyLogo(reader.result as string);
                      };
                      reader.readAsDataURL(file);
                    }
                  }}
                />
                {companyLogo && (
                  <button 
                    onClick={() => setCompanyLogo('')} 
                    className="text-xs text-rose-500 font-bold hover:text-rose-700 transition-colors mb-2"
                  >
                    Eliminar Logotipo
                  </button>
                )}
                <p className="text-xs text-slate-400">Recomendado: 512x512px, formato PNG transparente.</p>
              </div>

              {/* ACTION: Enviar Invitaciones PWA */}
              <div className="bg-gradient-to-br from-emerald-500 to-teal-600 rounded-3xl p-8 text-white shadow-lg relative overflow-hidden">
                <div className="absolute top-0 right-0 p-4 opacity-20"><Smartphone size={64}/></div>
                <h3 className="font-black text-xl mb-2 relative z-10">Invitaciones PWA</h3>
                <p className="text-emerald-100 text-sm mb-6 relative z-10">Invita a tu personal operativo a descargar el Reloj Checador móvil vía WhatsApp o SMS.</p>
                <button 
                  onClick={() => setShowInviteModal(true)}
                  className="w-full bg-white text-emerald-700 font-black py-3 rounded-xl shadow-md hover:bg-emerald-50 transition-colors relative z-10"
                >
                  Enviar Enlaces
                </button>
              </div>
            </div>

          </div>
        )}

        {/* TAB: FACTURACIÓN Y PLAN */}
        {activeTab === 'billing' && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 animate-in slide-in-from-bottom-4">
            
            {/* Tarjeta del Plan Actual */}
            <div className="lg:col-span-1 space-y-6">
              <div className={`bg-white rounded-3xl p-8 border-2 ${currentTier === 'enterprise' ? 'border-purple-300 bg-gradient-to-b from-purple-50/20 to-white' : currentTier === 'pro' ? 'border-blue-300 bg-gradient-to-b from-blue-50/20 to-white' : 'border-slate-200'} shadow-sm relative overflow-hidden`}>
                <div className="flex justify-between items-start mb-6">
                  <div className={`px-3.5 py-1 rounded-full text-xs font-black uppercase tracking-widest ${tierInfo.bg} ${tierInfo.color} border border-current/20`}>
                    Plan Actual
                  </div>
                </div>
                
                <h3 className="text-4xl font-black text-slate-900 mb-2">{tierInfo.name}</h3>
                
                <div className="mb-6">
                  {currentTier === 'freemium' ? (
                    <div>
                      <span className="text-3xl font-black text-slate-900">$0</span>
                      <span className="text-slate-500 font-bold"> /mes</span>
                      <p className="text-xs text-slate-400 mt-1">Plan Gratuito Permanente</p>
                    </div>
                  ) : currentTier === 'pro' ? (
                    <div>
                      <span className="text-3xl font-black text-slate-900">${tierInfo.price * activeEmployeesCount}</span>
                      <span className="text-slate-500 font-bold"> MXN /mes</span>
                      <p className="text-xs text-slate-400 mt-1">Estimado para {activeEmployeesCount} colaborador(es) ($12/emp/mes)</p>
                    </div>
                  ) : (
                    <div>
                      <span className="text-3xl font-black text-slate-900">${tierInfo.price}</span>
                      <span className="text-slate-500 font-bold"> MXN /mes</span>
                      <p className="text-xs text-slate-400 mt-1">Suite Enterprise Completa para {activeEmployeesCount} colaboradores</p>
                    </div>
                  )}
                </div>

                <div className="space-y-4 mb-8">
                  <div className="bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    <div className="flex justify-between text-sm mb-2">
                      <span className="font-bold text-slate-700">Licencias Utilizadas</span>
                      <span className="font-black text-slate-900">{activeEmployeesCount} / {tierInfo.maxUsers}</span>
                    </div>
                    {currentTier === 'freemium' && (
                      <div className="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                        <div className="bg-slate-800 h-full rounded-full" style={{ width: `${Math.min(100, (activeEmployeesCount / (systemSettings?.freemium_max_users || 10)) * 100)}%` }}></div>
                      </div>
                    )}
                  </div>
                </div>

                {currentTier === 'freemium' && (
                  <button 
                    onClick={() => {
                      setSelectedUpgradePlan('pro');
                      setShowCheckout(true);
                    }}
                    className="w-full bg-slate-900 text-white font-black py-3.5 rounded-xl hover:bg-slate-800 transition-all flex items-center justify-center gap-2 shadow-lg shadow-slate-900/10 cursor-pointer"
                  >
                    Mejorar Plan <ChevronRight size={18}/>
                  </button>
                )}

                {currentTier === 'pro' && (
                  <button 
                    onClick={() => {
                      setSelectedUpgradePlan('enterprise');
                      setShowCheckout(true);
                    }}
                    className="w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-black py-3.5 rounded-xl transition-all flex items-center justify-center gap-2 shadow-lg shadow-purple-600/20 cursor-pointer"
                  >
                    Mejorar a Enterprise <ChevronRight size={18}/>
                  </button>
                )}

                {currentTier === 'enterprise' && (
                  <div className="w-full bg-purple-50 border border-purple-200 text-purple-700 font-extrabold py-3.5 rounded-xl flex items-center justify-center gap-2 text-xs uppercase tracking-wider">
                    <ShieldCheck size={18} /> Plan Máximo Enterprise Activo
                  </div>
                )}
              </div>
            </div>

            {/* Historial de Facturación y Método de Pago */}
            <div className="lg:col-span-2 space-y-6">
              
              <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div className="flex items-center gap-4">
                  <div className="w-14 h-12 bg-slate-100 rounded-xl flex items-center justify-center border border-slate-200 text-slate-400 shrink-0">
                    <CreditCard size={24} />
                  </div>
                  <div>
                    <h4 className="font-bold text-slate-800">
                      {currentTier === 'freemium' ? 'Plan Gratuito Sin Tarjeta' : 'Método de Pago Registrado'}
                    </h4>
                    <p className="text-xs text-slate-500 mt-0.5">
                      {currentTier === 'freemium' 
                        ? 'Tu empresa está operando bajo la versión gratuita Talent360.'
                        : 'Gestionado de forma segura vía pasarela de pagos (MercadoPago / Stripe).'}
                    </p>
                  </div>
                </div>
                {currentTier !== 'enterprise' && (
                  <button 
                    onClick={() => {
                      setSelectedUpgradePlan(currentTier === 'pro' ? 'enterprise' : 'pro');
                      setShowCheckout(true);
                    }}
                    className="text-xs font-bold text-blue-600 hover:text-blue-800 transition-colors bg-blue-50 hover:bg-blue-100 px-4 py-2.5 rounded-xl border border-blue-100 shrink-0"
                  >
                    {currentTier === 'freemium' ? 'Agregar Tarjeta y Mejorar' : 'Actualizar Suscripción'}
                  </button>
                )}
              </div>

              <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm">
                <h3 className="text-lg font-black text-slate-800 mb-6 flex items-center gap-2">
                  <Receipt className="text-slate-400"/> Historial de Facturación
                </h3>
                
                <div className="text-center py-10 px-4 bg-slate-50/70 rounded-2xl border border-dashed border-slate-200">
                  <Receipt className="mx-auto text-slate-300 mb-3" size={40} />
                  <h4 className="font-bold text-slate-700 text-sm">Sin historial de facturación</h4>
                  <p className="text-xs text-slate-400 mt-1 max-w-sm mx-auto leading-relaxed">
                    Esta empresa aún no cuenta con recibos o comprobantes de pago registrados. Las facturas de tu suscripción aparecerán aquí automáticamente al realizar la adquisición o renovación de tu plan.
                  </p>
                </div>
              </div>

            </div>

          </div>
        )}

        {/* TAB: MÓDULOS DEL SISTEMA */}
        {activeTab === 'modules' && (
          <div className="animate-in slide-in-from-bottom-4">
            
            {/* Banner de Tiempo de Prueba */}
            {trialActive && (
              <div className="bg-gradient-to-r from-amber-500/10 to-orange-500/10 border border-amber-300 rounded-3xl p-6 mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                  <div className="w-12 h-12 bg-amber-500/20 text-amber-700 rounded-2xl flex items-center justify-center font-black text-xl shrink-0">
                    ⏳
                  </div>
                  <div>
                    <h3 className="font-black text-slate-800 text-base">Modo de Prueba Activo (Módulos PRO y Enterprise)</h3>
                    <p className="text-xs text-slate-500 font-semibold mt-0.5">
                      Tienes acceso a todas las herramientas. Quedan <span className="text-amber-600 font-extrabold">{daysRemaining} días</span> de prueba completa antes del bloqueo de los módulos premium.
                    </p>
                  </div>
                </div>
                <div className="flex gap-2 shrink-0 flex-wrap">
                  <button 
                    onClick={() => setTutorialStep(0)}
                    className="bg-white hover:bg-slate-50 text-slate-900 border border-slate-200 font-bold text-xs px-5 py-2.5 rounded-xl shadow-md transition-all shrink-0 flex items-center gap-1.5"
                  >
                    📖 Ver Tutorial
                  </button>
                  <button 
                    onClick={() => setActiveTab('billing')}
                    className="bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs px-5 py-2.5 rounded-xl shadow-md transition-all shrink-0"
                  >
                    Adquirir Plan PRO
                  </button>
                </div>
              </div>
            )}

            <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm mb-6">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8 border-b border-slate-100 pb-6">
                <div>
                  <h2 className="text-xl font-black text-slate-800 mb-1">Ecosistema Talent 360</h2>
                  <p className="text-sm text-slate-500">Administra qué herramientas tienen activas tus equipos. El acceso depende de tu plan de suscripción actual.</p>
                </div>
                <button 
                  onClick={() => setTutorialStep(0)}
                  className="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-5 py-3 rounded-xl shadow-md transition-all shrink-0 flex items-center gap-1.5"
                >
                  📖 Ver Tutorial de Módulos
                </button>
              </div>

              {(() => {
                const modulesWithDetails = [
                  { 
                    name: 'Directorio Digital', 
                    desc: 'Estructura y Contratos', 
                    tier: 'freemium', 
                    active: isModuleUnlocked('rrhh'), 
                    version: 'v3.1',
                    icon: <Users size={20} />,
                    iconColor: 'bg-blue-50 text-blue-600',
                    moduleId: 'rrhh',
                    features: [
                      'Expedientes digitales completos de colaboradores.',
                      'Control de roles y permisos (RBAC) personalizados.',
                      'Historial de contratos y carga de documentos de identidad.',
                      'Directorio interno con búsqueda rápida y filtros por sucursal.'
                    ]
                  },
                  { 
                    name: 'Reloj Checador', 
                    desc: 'Asistencia y Ley Silla', 
                    tier: 'freemium', 
                    active: isModuleUnlocked('reloj'), 
                    version: 'v4.3',
                    icon: <Clock size={20} />,
                    iconColor: 'bg-emerald-50 text-emerald-600',
                    moduleId: 'reloj',
                    features: [
                      'Registro de asistencia en tiempo real mediante PWA móvil.',
                      'Geocercas (geofencing) y validaciones faciales alternativas.',
                      'Configuración y logs de eventos de asistencia independientes.',
                      'Cola de sincronización y modo offline aislado.'
                    ]
                  },
                  { 
                    name: 'Control de Comedor', 
                    desc: 'Turnos y reserva de comida', 
                    tier: 'freemium', 
                    active: isModuleUnlocked('reloj'), 
                    version: 'v2.1',
                    icon: <Coffee size={20} />,
                    iconColor: 'bg-amber-50 text-amber-600',
                    moduleId: 'comidas',
                    features: [
                      'Registro básico de comidas y descansos largos.',
                      'Límite de sillas y prevención de solapes (Plan PRO).',
                      'Configuración de horarios de comedor autorizados.',
                      'Integración directa con el historial del Reloj Checador.'
                    ]
                  },
                  { 
                    name: 'Tareas IA', 
                    desc: 'Automatiza Rutinas', 
                    tier: 'freemium', 
                    active: isModuleUnlocked('operativo'), 
                    version: 'v1.5',
                    icon: <CheckSquare size={20} />,
                    iconColor: 'bg-green-50 text-green-600',
                    moduleId: 'operativo',
                    features: [
                      'Creación de listas de tareas diarias para personal de piso.',
                      'Evidencias fotográficas y firmas digitales de finalización.',
                      'Bolsa de Trabajo para asignación manual de tareas y roles.',
                      'Rutinas recurrentes y Asistente de Voz AI (disponible en Plan PRO).'
                    ]
                  },
                  { 
                    name: 'Bolsa de Trabajo ATS', 
                    desc: 'Vacantes y Prospectos', 
                    tier: 'pro', 
                    active: isModuleUnlocked('ats'), 
                    version: 'v1.1',
                    icon: <Briefcase size={20} />,
                    iconColor: 'bg-purple-50 text-purple-600',
                    moduleId: 'ats',
                    features: [
                      'Publicación automática de vacantes en portal de empleo.',
                      'Embudo de reclutamiento (pipeline) con etapas personalizadas.',
                      'Gestión y filtrado inteligente de currículums (CVs) de candidatos.',
                      'Historial de comentarios y evaluación de psicometría básica.'
                    ]
                  },
                  { 
                    name: 'Reportes IA', 
                    desc: 'Analítica Nómina e incidencias', 
                    tier: 'pro', 
                    active: isModuleUnlocked('reportes'), 
                    version: 'v2.0',
                    icon: <FileText size={20} />,
                    iconColor: 'bg-emerald-50 text-emerald-600',
                    moduleId: 'reportes',
                    features: [
                      'Reportes de asistencia consolidados (horas trabajadas, retardos).',
                      'Cálculo automático de incidencias listas para prenómina.',
                      'Gráficas de puntualidad, ausentismo y rotación de personal.',
                      'Exportación de datos en formato Excel (XLSX) y PDF.'
                    ]
                  },
                  { 
                    name: 'Portal de Empleo Web', 
                    desc: 'Sitio web de vacantes', 
                    tier: 'enterprise', 
                    active: isModuleUnlocked('portal'), 
                    version: 'v1.0',
                    icon: <Globe size={20} />,
                    iconColor: 'bg-sky-50 text-sky-600',
                    moduleId: 'portal',
                    features: [
                      'Sitio web corporativo de vacantes personalizado con tu dominio.',
                      'Formulario de aplicación amigable para postulantes móviles.',
                      'Sincronización instantánea con el ATS de reclutamiento.',
                      'Enlaces directos para compartir en redes sociales.'
                    ]
                  },
                  { 
                    name: 'Academia 360', 
                    desc: 'Inducción y Capacitación', 
                    tier: 'enterprise', 
                    active: isModuleUnlocked('academia'), 
                    version: 'v2.8',
                    icon: <GraduationCap size={20} />,
                    iconColor: 'bg-indigo-50 text-indigo-600',
                    moduleId: 'academia',
                    features: [
                      'Plataforma de capacitación interna (LMS) para onboarding.',
                      'Creación de cursos interactivos con videos y cuestionarios.',
                      'Gamificación: tabla de líderes, medallas y recompensas.',
                      'Certificaciones automatizadas descargables para los colaboradores.'
                    ]
                  },
                  { 
                    name: 'Archivo Digital', 
                    desc: 'Expedientes y Manuales', 
                    tier: 'enterprise', 
                    active: isModuleUnlocked('documentos'), 
                    version: 'v1.0',
                    icon: <FileText size={20} />,
                    iconColor: 'bg-yellow-50 text-yellow-600',
                    moduleId: 'documentos',
                    features: [
                      'Expedientes digitales ordenados por colaborador.',
                      'Subida de documentos oficiales (INE, RFC, Acta de Nacimiento).',
                      'Gestión de manuales de operación y protocolos de seguridad.',
                      'Vinculación directa de manuales a cursos de Academia 360.'
                    ]
                  },
                  { 
                    name: 'Nómina CFDI 4.0', 
                    desc: 'Timbrado masivo del SAT', 
                    tier: 'pro', 
                    active: isModuleUnlocked('facturacion'), 
                    version: 'v1.0',
                    icon: <Receipt size={20} />,
                    iconColor: 'bg-emerald-50 text-emerald-650',
                    moduleId: 'facturacion',
                    features: [
                      'Configuración de Sellos CSD y certificados fiscales encriptados.',
                      'Timbrado masivo y generación de archivos PDF/XML en el SAT.',
                      'Integración directa con el cálculo de la pre-nómina.'
                    ]
                  },
                  { 
                    name: 'Matrix QA', 
                    desc: 'Entorno de simulación', 
                    tier: 'pro', 
                    active: isModuleUnlocked('matrix'), 
                    version: 'v1.2',
                    icon: <Monitor size={20} />,
                    iconColor: 'bg-indigo-50 text-indigo-600',
                    moduleId: 'matrix',
                    features: [
                      'Simulación interactiva de múltiples celulares en simultáneo.',
                      'Time Machine para alterar el tiempo virtual y probar tolerancias.',
                      'Bitácora detallada de eventos del motor de asistencia en tiempo real.',
                      'Prueba integrada de Ley Silla, geocercas y llaves de apertura.'
                    ]
                  }
                ];

                const customizedModulesWithDetails = modulesWithDetails.map(mod => {
                  const customizations = mod.moduleId ? systemSettings?.moduleCustomizations?.[mod.moduleId] : undefined;
                  if (customizations) {
                    return {
                      ...mod,
                      name: customizations.title || mod.name,
                      desc: customizations.desc || mod.desc,
                      icon: customizations.iconName && IconMap[customizations.iconName] 
                        ? IconMap[customizations.iconName] 
                        : mod.icon
                    };
                  }
                  return mod;
                });

                return (
                  <>
                    {(() => {
                      const freeModules = customizedModulesWithDetails.filter(m => m.tier === 'freemium');
                      const proModules = customizedModulesWithDetails.filter(m => m.tier === 'pro');
                      const enterpriseModules = customizedModulesWithDetails.filter(m => m.tier === 'enterprise');

                      const renderModuleCard = (mod: any, idx: number) => (
                        <ModuleCard 
                          key={idx}
                          mod={mod}
                          currentUser={currentUser}
                          systemSettings={systemSettings}
                          trialActive={trialActive}
                          setEditingCustomModule={setEditingCustomModule}
                          updateSetting={updateSetting}
                          setSelectedModuleForDetail={setSelectedModuleForDetail}
                          setConfiguringModule={setConfiguringModule}
                        />
                      );

                      return (
                        <div className="space-y-10">
                          {/* SECCIÓN 1: FREE */}
                          <div className="space-y-4">
                            <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                              <div className="flex items-center gap-2">
                                <span className="bg-emerald-100 text-emerald-800 text-[10px] font-black uppercase px-2.5 py-1 rounded-full border border-emerald-200/50">
                                  Plan Free
                                </span>
                                <h3 className="text-base font-black text-slate-800">Módulos del Plan Gratuito</h3>
                              </div>
                              <span className="text-xs text-slate-450 font-bold">Herramientas básicas incluidas</span>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                              {freeModules.map((mod, idx) => renderModuleCard(mod, idx))}
                            </div>
                          </div>

                          {/* SECCIÓN 2: PRO */}
                          <div className="space-y-4">
                            <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                              <div className="flex items-center gap-2">
                                <span className="bg-blue-100 text-blue-800 text-[10px] font-black uppercase px-2.5 py-1 rounded-full border border-blue-200/50 flex items-center gap-1">
                                  🚀 Plan Pro
                                </span>
                                <h3 className="text-base font-black text-slate-800">Módulos Profesionales</h3>
                              </div>
                              <span className="text-xs text-slate-450 font-bold">Cálculo de nóminas, reportes e integraciones SAT</span>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                              {proModules.map((mod, idx) => renderModuleCard(mod, idx))}
                            </div>
                          </div>

                          {/* SECCIÓN 3: ENTERPRISE */}
                          <div className="space-y-4">
                            <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                              <div className="flex items-center gap-2">
                                <span className="bg-purple-100 text-purple-800 text-[10px] font-black uppercase px-2.5 py-1 rounded-full border border-purple-200/50 flex items-center gap-1">
                                  👑 Plan Enterprise
                                </span>
                                <h3 className="text-base font-black text-slate-800">Módulos Corporativos Premium</h3>
                              </div>
                              <span className="text-xs text-slate-450 font-bold">LMS, expedientes avanzados y sitio de vacantes público</span>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                              {enterpriseModules.map((mod, idx) => renderModuleCard(mod, idx))}
                            </div>
                          </div>
                        </div>
                      );
                    })()}

                    {/* Modal de Detalle de Módulo */}
                    {selectedModuleForDetail && (
                      <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center p-4 animate-in fade-in duration-200">
                        <div className="bg-white rounded-3xl p-8 max-w-lg w-full border border-slate-100 shadow-2xl relative animate-in zoom-in-95 duration-200">
                          <button 
                            onClick={() => setSelectedModuleForDetail(null)}
                            className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 transition-colors"
                          >
                            <X size={20} />
                          </button>

                          <div className="flex items-center gap-3.5 mb-6">
                            <div className={`w-12 h-12 rounded-xl flex items-center justify-center ${selectedModuleForDetail.iconColor} shadow-inner shrink-0`}>
                              {selectedModuleForDetail.icon}
                            </div>
                            <div>
                              <div className="flex items-center gap-2">
                                <h3 className="text-2xl font-black text-slate-900">{selectedModuleForDetail.name}</h3>
                                <span className="text-[10px] font-black px-2 py-0.5 rounded-md bg-slate-100 text-slate-600">
                                  {selectedModuleForDetail.version}
                                </span>
                              </div>
                              <span className={`inline-block text-[9px] font-black uppercase tracking-widest px-2.5 py-0.5 rounded-full border mt-1.5 ${
                                selectedModuleForDetail.tier === 'freemium' ? 'bg-slate-100 text-slate-700 border-slate-200' :
                                selectedModuleForDetail.tier === 'pro' ? 'bg-blue-100 text-blue-700 border-blue-200' :
                                'bg-purple-100 text-purple-700 border-purple-200'
                              }`}>
                                Plan {selectedModuleForDetail.tier.toUpperCase()}
                              </span>
                            </div>
                          </div>

                          <p className="text-sm text-slate-600 mb-6 font-medium leading-relaxed">
                            {selectedModuleForDetail.desc}. A continuación te desglosamos las funcionalidades y beneficios incluidos:
                          </p>

                          {/* Degradación Elegante / Upsell Contextual (Módulo Comedor) */}
                          {selectedModuleForDetail.name.includes('Comedor') && (
                            <div className="mb-6 p-4 rounded-2xl border border-amber-100 bg-amber-50/50 text-left">
                              <h5 className="text-[10px] font-black text-amber-800 uppercase tracking-widest mb-2.5 flex items-center gap-1.5">
                                ⚖️ Estado del Servicio (Degradación Activa)
                              </h5>
                              <div className="space-y-2 text-xs">
                                <div className="flex justify-between items-center font-bold text-slate-700">
                                  <span>Registros Básicos de Comida:</span>
                                  <span className="text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-md border border-emerald-200/50 font-black text-[9px] uppercase">Activo (Gratis)</span>
                                </div>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-450 font-semibold">Límite de Sillas en Tiempo Real:</span>
                                  <span className="text-slate-500 bg-slate-100 px-2 py-0.5 rounded border border-slate-200 flex items-center gap-1 font-bold text-[9px] uppercase">
                                    <Lock size={10} /> Bloqueado (PRO)
                                  </span>
                                </div>
                                <div className="flex justify-between items-center">
                                  <span className="text-slate-450 font-semibold">Prevención de Solape de Roles:</span>
                                  <span className="text-slate-500 bg-slate-100 px-2 py-0.5 rounded border border-slate-200 flex items-center gap-1 font-bold text-[9px] uppercase">
                                    <Lock size={10} /> Bloqueado (PRO)
                                  </span>
                                </div>
                              </div>
                            </div>
                          )}

                          <div className="bg-slate-50 border border-slate-100 rounded-2xl p-5 mb-6">
                            <h4 className="text-xs font-black uppercase text-slate-500 tracking-wider mb-3">Funcionalidades Clave</h4>
                            <ul className="space-y-2.5">
                              {selectedModuleForDetail.features.map((feature: string, fIdx: number) => (
                                <li key={fIdx} className="flex gap-2 text-xs font-semibold text-slate-700 leading-relaxed">
                                  <CheckCircle2 size={16} className="text-emerald-500 shrink-0 mt-0.5" />
                                  <span>{feature}</span>
                                </li>
                              ))}
                            </ul>
                          </div>

                          <div className="flex gap-3">
                            <button 
                              onClick={() => setSelectedModuleForDetail(null)}
                              className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs uppercase tracking-widest rounded-xl transition-all"
                            >
                              Cerrar
                            </button>
                            {!selectedModuleForDetail.active ? (
                              <button 
                                onClick={() => {
                                  handleBuyPlan(selectedModuleForDetail.tier);
                                  setSelectedModuleForDetail(null);
                                }}
                                className="flex-1 py-3 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs uppercase tracking-widest rounded-xl shadow-lg shadow-blue-600/20 transition-all flex items-center justify-center gap-1.5"
                              >
                                <Zap size={14} className="fill-current" /> Adquirir {selectedModuleForDetail.tier.toUpperCase()}
                              </button>
                            ) : (
                              <div className="flex-1 flex items-center justify-center gap-1.5 py-3 border border-emerald-200 bg-emerald-50 text-emerald-700 rounded-xl font-bold text-xs uppercase tracking-widest select-none">
                                <CheckCircle2 size={14} /> Módulo Activo
                              </div>
                            )}
                          </div>
                        </div>
                      </div>
                    )}

                    {/* Modal Flotante de Configuración del Módulo */}
                    {configuringModule && (() => {
                      const mapModuleToTab = (moduleId: string) => {
                        switch (moduleId) {
                          case 'rrhh': return 'onboarding';
                          case 'reloj': return 'reloj';
                          case 'comidas': return 'comidas';
                          case 'operativo': return 'tareas';
                          case 'ats':
                          case 'portal': return 'ats';
                          case 'reportes': return 'reportes';
                          case 'academia': return 'academia';
                          case 'documentos': return 'documentos';
                          case 'facturacion': return 'facturacion';
                          default: return 'general';
                        }
                      };

                      return (
                        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center p-4 animate-in fade-in duration-200">
                          <div className="bg-slate-50 rounded-3xl max-w-4xl w-full border border-slate-100 shadow-2xl relative animate-in zoom-in-95 duration-200 flex flex-col max-h-[90vh] overflow-hidden">
                            {/* Cabecera del Modal */}
                            <div className="bg-white px-8 py-5 border-b border-slate-200 flex items-center justify-between shrink-0">
                              <div className="flex items-center gap-3">
                                <div className="p-2.5 bg-blue-50 text-blue-600 rounded-xl">
                                  <Settings size={20} className="animate-spin-slow" />
                                </div>
                                <div>
                                  <h3 className="text-xl font-black text-slate-800">Ajustes: {configuringModule.name}</h3>
                                  <p className="text-xs text-slate-500 font-semibold mt-0.5">Establece los parámetros operativos específicos del módulo</p>
                                </div>
                              </div>
                              <button 
                                onClick={() => setConfiguringModule(null)}
                                className="w-10 h-10 flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 rounded-full transition-all border-none cursor-pointer"
                              >
                                <X size={18} />
                              </button>
                            </div>

                            {/* Cuerpo del Modal (Scrollable) */}
                            <div className="flex-1 overflow-y-auto p-6 bg-slate-50 custom-scrollbar">
                              <CompanySettingsPanel initialTab={mapModuleToTab(configuringModule.moduleId)} hideSidebar={true} />
                            </div>
                          </div>
                        </div>
                      );
                    })()}

                    {editingCustomModule && (() => {
                      const iconsList = [
                        { name: 'LayoutDashboard', label: 'Dashboard' },
                        { name: 'Users', label: 'Colaboradores' },
                        { name: 'Clock', label: 'Reloj Checador' },
                        { name: 'ListTodo', label: 'Tareas/Checklists' },
                        { name: 'Briefcase', label: 'Reclutamiento' },
                        { name: 'FileText', label: 'Documentos' },
                        { name: 'GraduationCap', label: 'Academia' },
                        { name: 'Coffee', label: 'Cafetería' },
                        { name: 'Calendar', label: 'Calendario' },
                        { name: 'MapPin', label: 'Ubicaciones' },
                        { name: 'Heart', label: 'Salud/Clima' },
                        { name: 'Bell', label: 'Notificaciones' },
                        { name: 'Globe', label: 'Portal Web' },
                        { name: 'Receipt', label: 'Facturación' },
                        { name: 'Monitor', label: 'Simulador' }
                      ];

                      const renderIcon = (name: string) => {
                        switch (name) {
                          case 'LayoutDashboard': return <LayoutGrid size={22} />;
                          case 'Users': return <Users size={22} />;
                          case 'Clock': return <Clock size={22} />;
                          case 'CheckSquare':
                          case 'ListTodo': return <ListTodo size={22} />;
                          case 'Briefcase': return <Briefcase size={22} />;
                          case 'FileText': return <FileText size={22} />;
                          case 'GraduationCap': return <GraduationCap size={22} />;
                          case 'Coffee': return <Coffee size={22} />;
                          case 'Calendar': return <Calendar size={22} />;
                          case 'MapPin': return <MapPin size={22} />;
                          case 'Heart': return <Heart size={22} />;
                          case 'Bell': return <Bell size={22} />;
                          case 'Globe': return <Globe size={22} />;
                          case 'Receipt': return <Receipt size={22} />;
                          case 'Monitor': return <Monitor size={22} />;
                          default: return <Settings size={22} />;
                        }
                      };

                      const handleSave = () => {
                        const currentCustoms = systemSettings?.moduleCustomizations || {};
                        const updatedCustoms = {
                          ...currentCustoms,
                          [editingCustomModule.id]: {
                            title: editingCustomModule.title,
                            desc: editingCustomModule.desc,
                            iconName: editingCustomModule.iconName,
                            color: editingCustomModule.color
                          }
                        };
                        updateSetting('moduleCustomizations', updatedCustoms);
                        setEditingCustomModule(null);
                      };

                      return (
                        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[101] flex items-center justify-center p-4 animate-in fade-in duration-200">
                          <div className="bg-white rounded-3xl max-w-lg w-full border border-slate-100 shadow-2xl relative animate-in zoom-in-95 duration-200 flex flex-col overflow-hidden">
                            <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                              <div className="flex items-center gap-2.5">
                                <div className="p-2 bg-blue-50 text-blue-600 rounded-xl">
                                  <Pencil size={18} />
                                </div>
                                <div>
                                  <h3 className="font-black text-slate-800 text-base">Personalizar Módulo</h3>
                                  <p className="text-[11px] text-slate-400 font-medium">Modifica cómo se ve este módulo en el sistema</p>
                                </div>
                              </div>
                              <button
                                onClick={() => setEditingCustomModule(null)}
                                className="w-8 h-8 flex items-center justify-center bg-slate-150 hover:bg-slate-255 text-slate-500 hover:text-slate-800 rounded-full transition-all border-none cursor-pointer"
                              >
                                <X size={16} />
                              </button>
                            </div>

                            <div className="p-6 space-y-5">
                              <div className="space-y-1.5">
                                <label className="text-xs font-black text-slate-600 uppercase tracking-wider">Nombre del Módulo</label>
                                <input
                                  type="text"
                                  value={editingCustomModule.title || ''}
                                  onChange={(e) => setEditingCustomModule({ ...editingCustomModule, title: e.target.value })}
                                  placeholder="Ej. Mi Reloj Inteligente"
                                  className="w-full px-4 py-2.5 rounded-xl border border-slate-250 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 bg-slate-50/50 text-slate-800 text-sm font-semibold transition-all outline-none"
                                />
                              </div>

                              <div className="space-y-1.5">
                                <label className="text-xs font-black text-slate-600 uppercase tracking-wider">Descripción Breve</label>
                                <input
                                  type="text"
                                  value={editingCustomModule.desc || ''}
                                  onChange={(e) => setEditingCustomModule({ ...editingCustomModule, desc: e.target.value })}
                                  placeholder="Ej. Registra entradas de manera rápida"
                                  className="w-full px-4 py-2.5 rounded-xl border border-slate-250 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 bg-slate-50/50 text-slate-800 text-sm font-semibold transition-all outline-none"
                                />
                              </div>

                              <div className="space-y-1.5">
                                <label className="text-xs font-black text-slate-600 uppercase tracking-wider block">Color del Módulo (Ecosistema)</label>
                                <div className="flex flex-wrap gap-2.5 p-3.5 bg-slate-50/60 rounded-2xl border border-slate-100">
                                  {Object.entries(ColorMap).map(([colorKey, colorVal]) => {
                                    const isSelected = editingCustomModule.color === colorKey || (!editingCustomModule.color && colorKey === 'violet');
                                    return (
                                      <button
                                        key={colorKey}
                                        type="button"
                                        onClick={() => setEditingCustomModule({ ...editingCustomModule, color: colorKey })}
                                        className={`w-8 h-8 rounded-full border-2 transition-all cursor-pointer relative flex items-center justify-center ${
                                          isSelected ? 'border-blue-600 scale-110 shadow-sm' : 'border-transparent hover:scale-105'
                                        }`}
                                        style={{ backgroundColor: colorVal.hex }}
                                        title={colorKey.toUpperCase()}
                                      >
                                        {isSelected && (
                                          <Check size={14} className="text-white drop-shadow-sm font-black" />
                                        )}
                                      </button>
                                    );
                                  })}
                                </div>
                              </div>

                              <div className="space-y-2">
                                <label className="text-xs font-black text-slate-600 uppercase tracking-wider block">Icono Visual</label>
                                <div className="grid grid-cols-4 gap-3 bg-slate-50/60 p-3 rounded-2xl border border-slate-100 max-h-[180px] overflow-y-auto custom-scrollbar">
                                  {iconsList.map((ic) => {
                                    const isSelected = editingCustomModule.iconName === ic.name;
                                    return (
                                      <button
                                        key={ic.name}
                                        onClick={() => setEditingCustomModule({ ...editingCustomModule, iconName: ic.name })}
                                        className={`flex flex-col items-center justify-center p-2.5 rounded-xl border transition-all duration-200 cursor-pointer ${
                                          isSelected
                                            ? 'bg-blue-600 text-white border-blue-600 shadow-md scale-105'
                                            : 'bg-white hover:bg-slate-100 text-slate-600 border-slate-200 hover:scale-102 hover:text-slate-800'
                                        }`}
                                        title={ic.label}
                                      >
                                        {renderIcon(ic.name)}
                                        <span className={`text-[9px] mt-1 font-bold truncate max-w-[80px] ${isSelected ? 'text-blue-50' : 'text-slate-400'}`}>
                                          {ic.label}
                                        </span>
                                      </button>
                                    );
                                  })}
                                </div>
                              </div>
                            </div>

                            <div className="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-end gap-2.5">
                              <button
                                onClick={() => setEditingCustomModule(null)}
                                className="px-4 py-2 rounded-xl text-slate-500 hover:bg-slate-150 text-xs font-bold transition-all cursor-pointer border-none bg-transparent"
                              >
                                Cancelar
                              </button>
                              <button
                                onClick={handleSave}
                                className="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow-md shadow-blue-500/20 hover:shadow-blue-500/30 transition-all cursor-pointer border-none flex items-center gap-1.5"
                              >
                                <Save size={14} />
                                Guardar Cambios
                              </button>
                            </div>
                          </div>
                        </div>
                      );
                    })()}

                    {/* Modal de Tutorial Interactivo */}
                    {tutorialStep !== null && (
                      <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center p-4 animate-in fade-in duration-200">
                        <div className="bg-white rounded-3xl p-8 max-w-xl w-full border border-slate-100 shadow-2xl relative animate-in zoom-in-95 duration-200 flex flex-col justify-between min-h-[460px]">
                          <div>
                            {/* Header del Tutorial */}
                            <div className="flex items-center justify-between pb-4 border-b border-slate-100 mb-6">
                              <div className="flex items-center gap-2">
                                <span className="text-xl">📖</span>
                                <h3 className="text-lg font-black text-slate-800">Recorrido de Módulos</h3>
                              </div>
                              <span className="text-xs font-black text-blue-600 bg-blue-50 px-3 py-1 rounded-full">
                                Módulo {tutorialStep + 1} de {customizedModulesWithDetails.length}
                              </span>
                            </div>

                            {/* Contenido del Paso del Tutorial */}
                            {(() => {
                              const mod = customizedModulesWithDetails[tutorialStep];
                              if (!mod) return null;
                              return (
                                <div className="space-y-6">
                                  <div className="flex items-center gap-4">
                                    <div className={`w-14 h-14 rounded-2xl flex items-center justify-center ${mod.iconColor} shadow-inner shrink-0 text-2xl`}>
                                      {mod.icon}
                                    </div>
                                    <div>
                                      <h4 className="text-2xl font-black text-slate-900 leading-tight">{mod.name}</h4>
                                      <p className="text-xs text-slate-400 font-bold uppercase mt-1">Requisito: Plan {mod.tier.toUpperCase()}</p>
                                    </div>
                                  </div>

                                  <div className="space-y-3">
                                    <p className="text-sm text-slate-700 font-bold italic">¿Para qué sirve?</p>
                                    <p className="text-sm text-slate-600 font-medium leading-relaxed">{mod.desc}</p>
                                  </div>

                                  <div className="bg-slate-50 border border-slate-100 rounded-2xl p-5">
                                    <p className="text-xs font-black uppercase text-slate-500 tracking-wider mb-2.5">Funciones destacadas:</p>
                                    <ul className="space-y-2">
                                      {mod.features.map((feature, fIdx) => (
                                        <li key={fIdx} className="flex gap-2 text-xs font-semibold text-slate-700">
                                          <span className="text-blue-500 shrink-0">•</span>
                                          <span>{feature}</span>
                                        </li>
                                      ))}
                                    </ul>
                                  </div>
                                </div>
                              );
                            })()}
                          </div>

                          {/* Botones de Navegación del Tutorial */}
                          <div className="pt-6 border-t border-slate-100 flex items-center justify-between gap-4 mt-6">
                            <button 
                              onClick={() => setTutorialStep(tutorialStep > 0 ? tutorialStep - 1 : null)}
                              className="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs uppercase tracking-widest rounded-xl transition-all flex items-center gap-1.5"
                            >
                              <ArrowLeft size={14} /> {tutorialStep === 0 ? 'Salir' : 'Anterior'}
                            </button>

                            {/* Progress Dots */}
                            <div className="flex gap-1.5">
                              {customizedModulesWithDetails.map((_, dotIdx) => (
                                <div 
                                  key={dotIdx}
                                  className={`w-2 h-2 rounded-full transition-all ${dotIdx === tutorialStep ? 'bg-blue-600 w-4' : 'bg-slate-200'}`}
                                />
                              ))}
                            </div>

                            <button 
                              onClick={() => {
                                if (tutorialStep < customizedModulesWithDetails.length - 1) {
                                  setTutorialStep(tutorialStep + 1);
                                } else {
                                  setTutorialStep(null);
                                }
                              }}
                              className="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs uppercase tracking-widest rounded-xl shadow-lg shadow-blue-600/20 transition-all flex items-center gap-1.5"
                            >
                              {tutorialStep === customizedModulesWithDetails.length - 1 ? 'Finalizar' : 'Siguiente'} <ArrowRight size={14} />
                            </button>
                          </div>
                        </div>
                      </div>
                    )}
                  </>
                );
              })()}

            </div>
          </div>
        )}
        {activeTab === 'backups' && (
          <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-sm animate-in slide-in-from-bottom-4">
            <BackupPanel />
          </div>
        )}
      </div>
      {/* Modal de Invitaciones PWA */}
      {showInviteModal && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-8 border border-slate-100 max-w-md w-full shadow-2xl animate-in zoom-in-95 duration-200 relative">
            <button 
              onClick={() => {
                setShowInviteModal(false);
                setSelectedEmployeeId('');
                setInvitePhone('');
                setInvitePin('');
                setInviteFeedback(null);
              }}
              className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 transition-colors"
            >
              <X size={20} />
            </button>

            <h3 className="text-xl font-black text-slate-900 mb-2">Enviar Invitación PWA</h3>
            <p className="text-sm text-slate-500 mb-6">
              Selecciona un colaborador para enviarle sus datos de acceso temporal y el link de instalación de la PWA.
            </p>

            <div className="space-y-4">
              {/* Selector de Colaborador */}
              <div>
                <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Colaborador</label>
                <select 
                  value={selectedEmployeeId}
                  onChange={(e) => handleSelectEmployee(Number(e.target.value))}
                  className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none font-bold"
                >
                  <option value="">-- Selecciona un colaborador --</option>
                  {employees.map(emp => (
                    <option key={emp.id} value={emp.id}>{emp.name} ({emp.email})</option>
                  ))}
                </select>
              </div>

              {selectedEmployeeId !== '' && (
                <>
                  {/* Teléfono */}
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Número de WhatsApp (10 dígitos)</label>
                    <input 
                      type="text" 
                      value={invitePhone} 
                      onChange={e => setInvitePhone(e.target.value)} 
                      placeholder="Ej. 4622071234"
                      className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none font-bold"
                    />
                  </div>

                  {/* PIN */}
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">PIN Temporal</label>
                    <div className="flex gap-2">
                      <input 
                        type="text" 
                        value={invitePin} 
                        readOnly 
                        placeholder="Sin PIN asignado"
                        className="w-full px-4 py-3 bg-slate-100 border border-slate-200 rounded-xl font-mono text-slate-700 font-bold focus:outline-none"
                      />
                      <button 
                        onClick={handleGeneratePin}
                        disabled={isGeneratingPin}
                        className="bg-blue-50 text-blue-600 border border-blue-200 hover:bg-blue-100 px-4 rounded-xl font-bold text-sm transition-colors whitespace-nowrap flex items-center justify-center gap-1 disabled:opacity-50"
                      >
                        {isGeneratingPin ? <Loader2 className="animate-spin" size={16} /> : 'Generar'}
                      </button>
                    </div>
                  </div>

                  {/* Feedback */}
                  {inviteFeedback && (
                    <div className={`p-4 rounded-xl border text-sm font-medium ${inviteFeedback.type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800'}`}>
                      {inviteFeedback.message}
                    </div>
                  )}

                  {/* Botones de acción */}
                  <div className="pt-4 flex flex-col gap-2">
                    <button 
                      onClick={handleSendManualInvite}
                      disabled={!invitePin || invitePhone.length < 10}
                      className="w-full bg-emerald-600 text-white font-black py-3 rounded-xl hover:bg-emerald-700 transition-colors flex items-center justify-center gap-2 disabled:opacity-50 text-sm"
                    >
                      <MessageSquare size={18} />
                      Abrir WhatsApp con la invitación
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Modal de Actualización / Mejorar Plan */}
      {showCheckout && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl p-8 border border-slate-100 max-w-xl w-full shadow-2xl animate-in zoom-in-95 duration-200 relative">
            <button 
              onClick={() => setShowCheckout(false)}
              className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
            >
              <X size={20} />
            </button>

            <div className="flex items-center gap-3 mb-2">
              <div className="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center font-black">
                <Zap size={20} />
              </div>
              <div>
                <h3 className="text-xl font-black text-slate-900">Mejorar Plan de Suscripción</h3>
                <p className="text-xs text-slate-500 font-medium">Selecciona el plan y periodo para desbloquear las herramientas avanzadas.</p>
              </div>
            </div>

            {/* Selector de Ciclo de Facturación */}
            <div className="my-6 p-1 bg-slate-100 rounded-2xl flex items-center gap-1">
              <button 
                type="button"
                onClick={() => setSelectedCycle('monthly')}
                className={`flex-1 py-2.5 rounded-xl text-xs font-black transition-all ${selectedCycle === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
              >
                Mensual
              </button>
              <button 
                type="button"
                onClick={() => setSelectedCycle('yearly')}
                className={`flex-1 py-2.5 rounded-xl text-xs font-black transition-all flex items-center justify-center gap-1.5 ${selectedCycle === 'yearly' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
              >
                Anual
                <span className="bg-emerald-500 text-white text-[9px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider">
                  20% OFF
                </span>
              </button>
            </div>

            {/* Selector de Planes disponibles */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
              {/* Opción Plan PRO */}
              {currentTier === 'freemium' && (
                <div 
                  onClick={() => setSelectedUpgradePlan('pro')}
                  className={`p-5 rounded-2xl border-2 cursor-pointer transition-all ${selectedUpgradePlan === 'pro' ? 'border-blue-600 bg-blue-50/30 shadow-md' : 'border-slate-200 hover:border-slate-300'}`}
                >
                  <div className="flex justify-between items-start mb-2">
                    <span className="font-black text-slate-900 text-lg">PRO</span>
                    {selectedUpgradePlan === 'pro' && <CheckCircle2 className="text-blue-600" size={18} />}
                  </div>
                  <div className="mb-3">
                    <span className="text-2xl font-black text-slate-900">
                      ${selectedCycle === 'yearly' ? Math.round(12 * activeEmployeesCount * 12 * 0.8) : 12 * activeEmployeesCount}
                    </span>
                    <span className="text-xs text-slate-500 font-bold"> {selectedCycle === 'yearly' ? 'MXN /año' : 'MXN /mes'}</span>
                  </div>
                  <ul className="space-y-1.5 text-xs font-semibold text-slate-600">
                    <li className="flex items-center gap-1.5"><Check size={12} className="text-emerald-500"/> Reloj Checador & LFT</li>
                    <li className="flex items-center gap-1.5"><Check size={12} className="text-emerald-500"/> Reportes Avanzados IA</li>
                    <li className="flex items-center gap-1.5"><Check size={12} className="text-emerald-500"/> Timbrado Nómina CFDI 4.0</li>
                  </ul>
                </div>
              )}

              {/* Opción Plan Enterprise */}
              <div 
                onClick={() => setSelectedUpgradePlan('enterprise')}
                className={`p-5 rounded-2xl border-2 cursor-pointer transition-all ${currentTier === 'freemium' ? '' : 'col-span-2'} ${selectedUpgradePlan === 'enterprise' ? 'border-purple-600 bg-purple-50/30 shadow-md' : 'border-slate-200 hover:border-slate-300'}`}
              >
                <div className="flex justify-between items-start mb-2">
                  <span className="font-black text-purple-900 text-lg">ENTERPRISE</span>
                  {selectedUpgradePlan === 'enterprise' && <CheckCircle2 className="text-purple-600" size={18} />}
                </div>
                <div className="mb-3">
                  <span className="text-2xl font-black text-slate-900">
                    ${selectedCycle === 'yearly' ? Math.round(499 * 12 * 0.8) : 499}
                  </span>
                  <span className="text-xs text-slate-500 font-bold"> {selectedCycle === 'yearly' ? 'MXN /año' : 'MXN /mes'}</span>
                </div>
                <ul className="space-y-1.5 text-xs font-semibold text-slate-600">
                  <li className="flex items-center gap-1.5"><Check size={12} className="text-purple-500"/> Todo lo del Plan PRO</li>
                  <li className="flex items-center gap-1.5"><Check size={12} className="text-purple-500"/> Academia 360 & LMS Ilimitado</li>
                  <li className="flex items-center gap-1.5"><Check size={12} className="text-purple-500"/> Portal Web de Empleo Corporativo</li>
                  <li className="flex items-center gap-1.5"><Check size={12} className="text-purple-500"/> Expedientes Digitales Avanzados</li>
                </ul>
              </div>
            </div>

            {/* Resumen y Botón de Pago */}
            <div className="pt-4 border-t border-slate-100 flex items-center justify-between gap-4">
              <div>
                <p className="text-xs text-slate-400 font-bold uppercase">Total a Pagar</p>
                <p className="text-xl font-black text-slate-900">
                  ${selectedUpgradePlan === 'enterprise' 
                    ? (selectedCycle === 'yearly' ? Math.round(499 * 12 * 0.8) : 499)
                    : (selectedCycle === 'yearly' ? Math.round(12 * activeEmployeesCount * 12 * 0.8) : 12 * activeEmployeesCount)
                  } MXN
                </p>
              </div>

              <button
                onClick={() => handleBuyPlan(selectedUpgradePlan, selectedCycle)}
                className="bg-slate-900 hover:bg-slate-800 text-white font-black px-6 py-3.5 rounded-xl shadow-lg hover:shadow-slate-900/20 transition-all flex items-center gap-2 text-sm cursor-pointer"
              >
                Proceder al Pago <ChevronRight size={18}/>
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
