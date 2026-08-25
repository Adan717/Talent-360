import React, { useState, useEffect, useRef } from 'react';
import { EtiquetaCorregido, HistoriaDeFichaje, BotonCorregirFichaje, puedeCorregirFichajes } from './reloj/CorreccionDeFichaje';
import { 
  Users, Clock, CheckSquare, Bot, Sparkles, Truck, MessageSquare, 
  Plus, Search, Filter, ShieldCheck, AlertTriangle, ChevronRight, X, EyeOff,
  RefreshCw, Play, CheckCircle2, UserCheck, Building2, FileText, 
  Camera, Zap, Send, Shield, LayoutDashboard, Settings, Award,
  Briefcase, GraduationCap, BarChart3, Receipt, Sparkle
} from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { GlobalSystemSettingsPanel } from './GlobalSystemSettingsPanel';
import { LateAuthorizationsPanel } from './reloj/LateAuthorizationsPanel';
import { PanicIncidentsPanel } from './reloj/PanicIncidentsPanel';
import { LateJustificationsPanel } from './reloj/LateJustificationsPanel';
import { ContingenciesPanel } from './reloj/ContingenciesPanel';
import { IncompleteTasksPanel } from './reloj/IncompleteTasksPanel';

interface UserMonitorItem {
  id: number;
  name: string;
  role_name: string;
  hire_date?: string;
  status: 'active' | 'break' | 'idle' | 'offline' | 'late';
  status_text: string;
  active_task?: {
    id: string;
    title: string;
    started_at_mins?: number;
    estimated_mins?: number;
    accumulated_mins?: number;
    sop_step?: string;
  } | null;
  active_tasks?: Array<{
    id: string;
    title: string;
    status: string;
    estimated_mins?: number;
  }>;
  completed_tasks_count: number;
  completed_points: number;
  avatar: string;
  time_remaining: string;
  shift_start?: string;
  shift_end?: string;
  efficiency: number;
  time_entries?: any[];
}

interface FeedEvent {
  id: string;
  user: string;
  action: string;
  details: string;
  time: string;
  timestamp: string;
  type?: 'attendance' | 'task' | 'vendor' | 'permission' | 'store';
  photo_url?: string;
  // Bitácora inmutable (Capa 3): sólo los eventos de fichaje los traen.
  time_entry_id?: number;
  creado_por_correccion_id?: number | null;
}

interface VendorLog {
  id: string;
  vendor_name: string;
  driver_name: string;
  order_ref: string;
  arrival_time: string;
  status: 'in_premises' | 'completed';
  received_by: string;
  photo_url?: string;
}

/**
 * Forma REAL que devuelve el backend (`suggestWorkPlan` + GeminiAIService).
 *
 * La interfaz anterior describía otra cosa (`assignments[]` con `suggested_tasks[]`) que el
 * servidor nunca envió: la condición del handler exigía `res.data.suggestion || res.data.plan`,
 * claves inexistentes, así que el 100% de las veces se descartaba la respuesta de Gemini y se
 * pintaba un plan FABRICADO en el navegador (las mismas dos tareas de restaurante para todos,
 * en cualquier giro) bajo el rótulo "Diagnóstico IA".
 */
interface AiPlanSuggestion {
  ai_available: boolean;
  summary: string | null;
  staffing_gap_detected: boolean;
  suggestions: Array<{
    task_assignment_id?: string | number | null;
    suggested_new_task_title?: string | null;
    suggested_target_type?: 'role' | 'user' | null;
    suggested_target_id?: number | null;
    estimated_mins?: number | null;
    reason?: string | null;
  }>;
}

const WELCOME_MESSAGES = [
  "¡Nos alegra mucho tenerte aquí! Gracias por confiar en Talent360 para impulsar la excelencia operativa, la eficiencia y el crecimiento de todo tu equipo.",
  "Es un honor acompañar el liderazgo de tu organización. Gracias por elegirnos para llevar la productividad y el talento de tu equipo al siguiente nivel.",
  "¡Bienvenido de vuelta! Tu gestión y dedicación marcan la diferencia. Gracias por construir el futuro operativo de tu empresa junto a nosotros.",
  "Gracias por hacer parte de la familia Talent360. Estamos comprometidos en brindarle a tu equipo la mejor experiencia operativa y de gestión.",
  "¡Qué gusto verte de nuevo! Agradecemos la confianza depositada en Talent360 para transformar y optimizar los procesos de tu organización diariamente."
];

const MODULE_ICON_LIST = [
  { id: 'reloj', name: 'Reloj Checador', icon: Clock, color: 'text-sky-400 bg-sky-500/20 border-sky-400/40' },
  { id: 'rrhh', name: 'Gestión RRHH', icon: Users, color: 'text-emerald-400 bg-emerald-500/20 border-emerald-400/40' },
  { id: 'operativo', name: 'Control Operativo', icon: Zap, color: 'text-amber-400 bg-amber-500/20 border-amber-400/40' },
  { id: 'ats', name: 'Reclutamiento ATS', icon: Briefcase, color: 'text-purple-400 bg-purple-500/20 border-purple-400/40' },
  { id: 'academia', name: 'Academia 360', icon: GraduationCap, color: 'text-blue-400 bg-blue-500/20 border-blue-400/40' },
  { id: 'reportes', name: 'Reportes IA', icon: BarChart3, color: 'text-rose-400 bg-rose-500/20 border-rose-400/40' },
  { id: 'documentos', name: 'Archivo Digital', icon: FileText, color: 'text-amber-400 bg-amber-500/20 border-amber-400/40' },
  { id: 'facturacion', name: 'Nómina CFDI 4.0', icon: Receipt, color: 'text-emerald-400 bg-emerald-500/20 border-emerald-400/40' },
];

export function MonitorActividadesTiempoReal({ setActiveModule }: { setActiveModule?: (mod: string) => void }) {
  const { currentUser, currentTier, systemSettings, fetchState, isModuleUnlocked } = useAppStore();
  
  // Tab Principal de Cabecera (Visión General vs Onboarding)
  const [activeHeaderTab, setActiveHeaderTab] = useState<'overview' | 'onboarding'>('overview');

  // Dismissal states: 'session' | 'permanent' | 'none'
  const [headerDismissType, setHeaderDismissType] = useState<'none' | 'session' | 'permanent'>(() => {
    if (localStorage.getItem('monitor_header_dismissed_perm') === 'true') return 'permanent';
    if (sessionStorage.getItem('monitor_header_dismissed') === 'true') return 'session';
    return 'none';
  });

  const [showDismissMenu, setShowDismissMenu] = useState(false);

  const [welcomePhrase] = useState(() => {
    const index = Math.floor(Math.random() * WELCOME_MESSAGES.length);
    return WELCOME_MESSAGES[index];
  });

  const handleDismissSession = () => {
    sessionStorage.setItem('monitor_header_dismissed', 'true');
    setHeaderDismissType('session');
    setShowDismissMenu(false);
  };

  const handleDismissPermanent = () => {
    localStorage.setItem('monitor_header_dismissed_perm', 'true');
    setHeaderDismissType('permanent');
    setShowDismissMenu(false);
  };

  const handleRestoreHeader = () => {
    sessionStorage.removeItem('monitor_header_dismissed');
    localStorage.removeItem('monitor_header_dismissed_perm');
    setHeaderDismissType('none');
  };

  // Toast message
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [isAdoptionSaving, setIsAdoptionSaving] = useState(false);

  // Data States
  const [users, setUsers] = useState<UserMonitorItem[]>([]);
  // Toda la plantilla con cuenta (en turno o no) — para el selector de mensajes privados.
  const [staff, setStaff] = useState<{ user_id: number; name: string }[]>([]);
  const [availableTasks, setAvailableTasks] = useState<any[]>([]);
  const [feed, setFeed] = useState<FeedEvent[]>([]);
  const [chatMessages, setChatMessages] = useState<any[]>([]);
  // Bloque 2: el botón del Plan IA sólo existe si el servidor tiene llave de IA configurada.
  const [iaDisponible, setIaDisponible] = useState(false);
  // D3: días de retención del chat (el servidor manda el valor real de la empresa).
  const [chatRetentionDays, setChatRetentionDays] = useState(7);
  const [jobRoles, setJobRoles] = useState<any[]>([]);
  const [vendors, setVendors] = useState<VendorLog[]>([]);
  const [prospectsCount, setProspectsCount] = useState<number>(0);

  // UI & Filter States
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  // Un 403 del monitor se DICE; antes se tragaba y la pantalla quedaba en "0 / 0".
  const [accesoDenegado, setAccesoDenegado] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [mobileTab, setMobileTab] = useState<'employees' | 'feed' | 'vendors' | 'chat'>('employees');
  // Bitácora inmutable (Capa 3): qué fichaje se está auditando en el modal de historia.
  const [fichajeEnHistoria, setFichajeEnHistoria] = useState<number | null>(null);
  // Misma regla que el servidor (PermissionMiddleware::usuarioTiene), escrita una sola vez en
  // CorreccionDeFichaje: el admin dueño pasa siempre; los demás, por la capacidad de su puesto.
  // Ocultar el botón no es la seguridad —ésa la pone el 403—: es no ofrecer lo que va a ser
  // rechazado, que es de las cosas que más confunden a quien usa el sistema.
  // Lo contesta el SERVIDOR (`puede_corregir_fichajes` del monitor), con la MISMA funcion que usa
  // el middleware. No se deduce aqui: dos copias de una regla de permisos acaban discrepando, y
  // entonces el Monitor ofrece un boton que termina en 403 —o esconde uno que si se podia usar,
  // que es peor porque nadie lo reporta. Mientras llega la respuesta, se asume el caso obvio: el
  // admin dueno, que pasa siempre.
  const [puedeCorregir, setPuedeCorregir] = useState(puedeCorregirFichajes(currentUser, []));
  
  // Modals
  const [showAiModal, setShowAiModal] = useState(false);
  const [aiLoading, setAiLoading] = useState(false);
  const [aiPlan, setAiPlan] = useState<AiPlanSuggestion | null>(null);

  const [showAssignModal, setShowAssignModal] = useState(false);
  const [selectedUserForAssign, setSelectedUserForAssign] = useState<UserMonitorItem | null>(null);
  const [customTaskTitle, setCustomTaskTitle] = useState('');
  const [customTaskMins, setCustomTaskMins] = useState(30);
  const [customTaskPriority, setCustomTaskPriority] = useState('normal');
  // P7: la evidencia se elige AL CREARLA, no tras el incumplimiento.
  const [customTaskEvidencia, setCustomTaskEvidencia] = useState('ninguno');
  const [customTaskPrompt, setCustomTaskPrompt] = useState('');
  const [assignError, setAssignError] = useState('');

  const [showVendorModal, setShowVendorModal] = useState(false);
  const [newVendorName, setNewVendorName] = useState('');
  const [newVendorDriver, setNewVendorDriver] = useState('');
  const [newVendorOrderRef, setNewVendorOrderRef] = useState('');

  const [showChatDrawer, setShowChatDrawer] = useState(false);
  const [chatInput, setChatInput] = useState('');
  /**
   * A quién va el mensaje: vacío = a todo el equipo (el chat operativo de siempre); con un id,
   * es privado y sólo lo ven esa persona y quien escribe.
   *
   * Nace de un diagnóstico: el "mensaje privado" del Reloj no existía —no había dónde escribirlo
   * ni código que lo leyera— y se decidió no resucitar aquel camino, sino darle modo privado al
   * chat que sí funciona. Mantener dos canales de mensajería sería duplicar el problema.
   */
  const [chatDestinatario, setChatDestinatario] = useState<number | ''>('');
  const chatBottomRef = useRef<HTMLDivElement>(null);

  // Auto-scroll slider ref and logic for adoption modules
  const modulesSliderRef = useRef<HTMLDivElement>(null);
  const [activeModuleIndex, setActiveModuleIndex] = useState(0);

  useEffect(() => {
    const interval = setInterval(() => {
      const slider = modulesSliderRef.current;
      if (!slider || !slider.children.length) return;
      
      const totalCards = slider.children.length;
      setActiveModuleIndex(prev => {
        const nextIndex = (prev + 1) % totalCards;
        const cardWidth = slider.scrollWidth / totalCards;
        slider.scrollTo({ left: nextIndex * cardWidth, behavior: 'smooth' });
        return nextIndex;
      });
    }, 3000);

    return () => clearInterval(interval);
  }, []);

  // Fetch Real-time Monitor Data
  const fetchData = async () => {
    try {
      setRefreshing(true);
      const res = await axiosInstance.get('/admin/dashboard/monitor');
      if (res.data?.status === 'success' && res.data?.data) {
        setUsers(res.data.data.users || []);
        setStaff(res.data.data.staff || []);
        // Los proveedores vienen del servidor: antes solo vivían en este navegador.
        setVendors(res.data.data.vendors || []);
        setAvailableTasks(res.data.data.available_tasks || []);
        setFeed(res.data.data.feed || []);
        setPuedeCorregir(!!res.data.data.puede_corregir_fichajes);
        setChatMessages(res.data.data.chat || []);
        setJobRoles(res.data.data.job_roles || []);
        // Bloque 2: sin llave de IA no se ofrece el Plan IA; y el chat DICE su retención.
        setIaDisponible(res.data.data.ia_disponible === true);
        if (res.data.data.chat_retention_days) setChatRetentionDays(res.data.data.chat_retention_days);
        if (res.data.data.prospects_count !== undefined) {
          setProspectsCount(res.data.data.prospects_count);
        }
      }
      
      // Fallback si no viene prospects_count y ATS está desbloqueado
      if (isModuleUnlocked('ats') && (!res.data?.data || res.data.data.prospects_count === undefined)) {
        try {
          const candRes = await axiosInstance.get('/admin/candidates');
          setProspectsCount(candRes.data?.length || 0);
        } catch (cErr) {
          // ignore candidate fallback error
        }
      }
    } catch (err: any) {
      console.error("Error al cargar datos del monitor:", err);
      if (err?.response?.status === 403) {
        setAccesoDenegado(err.response?.data?.message || 'Tu cuenta no tiene permiso para ver el monitor. Pide a tu administrador que lo habilite en tu puesto.');
      }
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchData();
    const interval = setInterval(fetchData, 5000);
    return () => clearInterval(interval);
  }, []);

  // El hilo del chat se abre en el mensaje más reciente y baja solo cuando llega uno nuevo:
  // sin esto había que arrastrar el scroll para ver lo último, en un panel que se refresca
  // cada 5 segundos.
  useEffect(() => {
    if (showChatDrawer) chatBottomRef.current?.scrollIntoView({ block: 'nearest' });
  }, [chatMessages.length, showChatDrawer]);

  // Toggle Module Adoption
  const handleToggleModule = async (moduleKey: string, moduleName: string) => {
    setIsAdoptionSaving(true);
    const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];
    const isActive = activeModules.includes(moduleKey);
    const updatedModules = isActive 
      ? activeModules.filter((m: string) => m !== moduleKey)
      : [...activeModules, moduleKey];
    
    try {
      await axiosInstance.post('/sync/settings', { active_modules: updatedModules });
      await fetchState();
      setToastMessage(isActive ? `Módulo ${moduleName} desactivado.` : `Módulo ${moduleName} adoptado con éxito.`);
      setTimeout(() => setToastMessage(null), 3000);
    } catch (err) {
      console.error(err);
    } finally {
      setIsAdoptionSaving(false);
    }
  };

  // Generate AI Work Plan. Se pinta LO QUE LA IA RESPONDIÓ, y si no respondió se dice —
  // antes cualquier resultado (incluido el bueno) terminaba en un plan inventado en el
  // navegador que se presentaba como diagnóstico de IA.
  const [aiError, setAiError] = useState<string | null>(null);

  const handleGenerateAiPlan = async () => {
    setShowAiModal(true);
    setAiLoading(true);
    setAiError(null);
    setAiPlan(null);
    try {
      const res = await axiosInstance.post('/admin/dashboard/suggest-work-plan', {
        date: new Date().toISOString().split('T')[0]
      });
      setAiPlan({
        ai_available: !!res.data?.ai_available,
        summary: res.data?.summary ?? null,
        staffing_gap_detected: !!res.data?.staffing_gap_detected,
        suggestions: Array.isArray(res.data?.suggestions) ? res.data.suggestions : []
      });
    } catch (err: any) {
      console.error("Error al generar plan IA:", err);
      setAiError(err?.response?.data?.message || 'No se pudo generar el plan. Intenta de nuevo.');
    } finally {
      setAiLoading(false);
    }
  };

  // Sugerencia de función: ahora se ENVÍA (queda como ticket con el equipo). Antes el
  // prompt() se agradecía y el texto se tiraba.
  const handleSugerirFuncion = async () => {
    const suggestion = prompt("💡 ¿Qué nueva función o módulo te gustaría ver en Talent360?");
    if (!suggestion || !suggestion.trim()) return;
    try {
      const res = await axiosInstance.post('/feature-suggestions', { suggestion: suggestion.trim() });
      setToastMessage(res.data?.message || '¡Gracias! Tu sugerencia quedó registrada con el equipo de Talent360.');
    } catch (err: any) {
      console.error('Error al enviar la sugerencia:', err);
      setToastMessage(err?.response?.data?.message || 'No se pudo enviar la sugerencia. Intenta de nuevo.');
    }
    setTimeout(() => setToastMessage(null), 4000);
  };

  // Nombre legible del destinatario que sugiere la IA (puesto o persona).
  const nombreDestinoSugerido = (tipo?: string | null, id?: number | null) => {
    if (!tipo || !id) return 'Sin destinatario sugerido';
    if (tipo === 'role') return jobRoles.find((r: any) => r.id === id)?.name || `Puesto #${id}`;
    return staff.find((s) => s.user_id === id)?.name || `Colaborador #${id}`;
  };

  // Tarea al vuelo (§31, P5-P7): va por la puerta ENDURECIDA — solo mandos, nunca a uno
  // mismo, minutos obligatorios, evidencia elegida aquí y firma del supervisor forzada.
  // (Antes iba a /admin/dashboard/create-task, que aceptaba points arbitrarios del cliente.)
  const handleAssignExpressTask = async () => {
    if (!selectedUserForAssign || !customTaskTitle) return;
    setAssignError('');
    try {
      await axiosInstance.post('/task-assignments/al-vuelo', {
        title: customTaskTitle,
        target_user_id: selectedUserForAssign.id,
        estimated_mins: customTaskMins,
        priority: customTaskPriority,
        assistant_type: customTaskEvidencia,
        assistant_prompt: customTaskEvidencia === 'ninguno' ? '' : customTaskPrompt
      });
      setShowAssignModal(false);
      setCustomTaskTitle('');
      setCustomTaskPrompt('');
      fetchData();
    } catch (err: any) {
      // El backend explica el candado (p. ej. "no puedes lanzarte una tarea a ti mismo").
      setAssignError(err.response?.data?.message || 'No se pudo lanzar la tarea.');
    }
  };

  // Register New Vendor Arrival. El id lo pone el SERVIDOR: antes se fabricaba uno local
  // ('v_' + Date.now()) y con él la salida nunca se podía registrar. Si el alta falla, no
  // se pinta la tarjeta — un proveedor "registrado" que no existe en la bitácora es peor
  // que un error visible.
  const handleRegisterVendor = async () => {
    if (!newVendorName) return;
    try {
      await axiosInstance.post('/admin/dashboard/vendors', {
        vendor_name: newVendorName,
        driver_name: newVendorDriver || 'Repartidor',
        order_ref: newVendorOrderRef || 'S/N'
      });
      setNewVendorName('');
      setNewVendorDriver('');
      setNewVendorOrderRef('');
      setShowVendorModal(false);
      await fetchData();
    } catch (err: any) {
      console.error("Error al registrar proveedor:", err);
      setToastMessage(err?.response?.data?.message || 'No se pudo registrar el proveedor. Intenta de nuevo.');
      setTimeout(() => setToastMessage(null), 4000);
    }
  };

  // Complete Vendor Visit. La URL llevaba '/api/v1' duplicado (el baseURL de axios ya lo
  // trae): salía a /api/v1/api/v1/... y era 404 SIEMPRE, pero la tarjeta se apagaba igual.
  const handleCompleteVendor = async (id: string) => {
    try {
      await axiosInstance.post(`/admin/dashboard/vendors/${id}/complete`);
      await fetchData();
    } catch (err: any) {
      console.error("Error al registrar salida de proveedor:", err);
      setToastMessage(err?.response?.data?.message || 'No se pudo registrar la salida del proveedor.');
      setTimeout(() => setToastMessage(null), 4000);
    }
  };

  // Send Chat Message
  const handleSendMessage = async () => {
    if (!chatInput.trim()) return;
    try {
      const res = await axiosInstance.post('/admin/dashboard/send-message', {
        content: chatInput,
        type: 'general',
        // Modo privado (2026-08-06): con destinatario el mensaje es sólo para esa persona y le
        // llega a SU reloj. Sin destinatario es del equipo, como toda la vida.
        receiver_id: chatDestinatario || undefined
      });
      if (res.data?.data) {
        setChatMessages([...chatMessages, res.data.data]);
      }
      setChatInput('');
      chatBottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    } catch (err) {
      console.error("Error al enviar mensaje:", err);
    }
  };

  // Filtered Users. "En Turno" incluye a los 'idle': el backend degrada a idle a TODO el
  // que está checado sin tarea en curso, así que el filtro ocultaba a gente presente —
  // el caso normal a media mañana, cuando nadie tiene tarea abierta.
  const filteredUsers = users.filter(u => {
    const matchesSearch = u.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          u.role_name.toLowerCase().includes(searchTerm.toLowerCase());
    if (statusFilter === 'all') return matchesSearch;
    if (statusFilter === 'active') return matchesSearch && (u.status === 'active' || u.status === 'idle');
    return matchesSearch && u.status === statusFilter;
  });

  // Metrics
  const activeCount = users.filter(u => u.status === 'active' || u.status === 'idle').length;
  // Denominador = plantilla activa con cuenta, NO los que vinieron: con "1 / 1" un dueño
  // con 10 colaboradores y uno solo checado leía asistencia completa. Las faltas son
  // justo lo que este tablero existe para mostrar.
  const staffCount = staff.length || users.length;
  const breakCount = users.filter(u => u.status === 'break').length;
  const inPremisesVendors = vendors.filter(v => v.status === 'in_premises').length;
  const avgEfficiency = users.length > 0 ? Math.round(users.reduce((acc, u) => acc + (u.efficiency || 100), 0) / users.length) : 100;

  const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];

  return (
    <div className="space-y-6 text-slate-900 pb-12">
      
      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed top-20 right-6 z-50 bg-slate-900 text-white font-bold text-xs px-4 py-3 rounded-2xl shadow-xl border border-slate-800 animate-in fade-in slide-in-from-top-3 duration-300">
          {toastMessage}
        </div>
      )}

      {/* 1. HEADER BIENVENIDA Y AGRADECIMIENTO (ALINEACIÓN CENTRAL) */}
      {headerDismissType === 'none' && (
        <div className="bg-gradient-to-r from-slate-900 via-slate-900 to-indigo-950 text-white border border-slate-800/80 rounded-3xl p-6 sm:p-8 shadow-2xl space-y-5 relative overflow-hidden group text-center flex flex-col items-center">
          {/* Ambient Glow & Grid Accents */}
          <div className="absolute -top-24 left-1/2 -translate-x-1/2 w-96 h-96 bg-blue-600/15 rounded-full blur-3xl pointer-events-none group-hover:bg-blue-500/25 transition-all duration-700" />
          <div className="absolute -bottom-20 -left-20 w-64 h-64 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none" />
          <div className="absolute inset-0 bg-[radial-gradient(#ffffff_1px,transparent_1px)] [background-size:24px_24px] opacity-[0.03] pointer-events-none" />

          {/* Logo Emblem Oficial de Talent 360 */}
          <div className="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-slate-800/80 border border-slate-700/80 text-white shadow-md backdrop-blur-md relative z-10 hover:border-slate-600 transition-all">
            <div className="relative w-6 h-6 flex items-center justify-center">
              <div className="absolute inset-0 bg-blue-500 rounded-full blur-xs opacity-75 animate-pulse"></div>
              <div className="relative w-6 h-6 rounded-full bg-gradient-to-tr from-blue-600 to-sky-400 flex items-center justify-center text-white font-black text-[10px] shadow-xs">
                360
              </div>
            </div>
            <span className="text-xs font-black tracking-widest uppercase text-slate-200">
              TALENT <span className="text-sky-400 font-black">360</span>
            </span>
          </div>

          <div className="space-y-3 relative z-10 max-w-4xl mx-auto">
            <h1 className="text-2xl sm:text-3xl lg:text-4xl font-black text-white tracking-tight flex items-center justify-center gap-3 flex-wrap leading-tight">
              {/* H12: el default era 'DecorArte 360' — una empresa recién registrada saludaba
                  con el nombre de OTRA en su primera pantalla. Se prefiere el nombre real
                  (tenant o company_name de settings) y, si aún no cargó, algo neutro. */}
              Bienvenido a <span className="bg-gradient-to-r from-blue-300 via-white to-sky-200 bg-clip-text text-transparent">{currentUser?.tenant?.name || systemSettings?.company_name || 'tu empresa'}</span>
            </h1>

            <p className="text-sm sm:text-base text-slate-300 font-medium max-w-2xl mx-auto leading-relaxed italic">
              "{welcomePhrase}"
            </p>

            {/* Metadatos: Plan + Iconos de Módulos Contratados + Cliente Desde */}
            <div className="flex flex-wrap items-center justify-center gap-3 pt-4 border-t border-slate-800/80 text-xs font-semibold text-slate-400 w-full">
              {/* Badge del Plan */}
              <span className="bg-amber-400/10 border border-amber-400/30 text-amber-300 font-extrabold px-3.5 py-1.5 rounded-full flex items-center gap-1.5 shadow-xs">
                <Award size={14} className="text-amber-400" />
                Plan {(currentUser?.tenant?.plan || currentTier).toUpperCase()}
              </span>

              {/* Iconos de Módulos Activos / Contratados */}
              <div className="flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-800/60 border border-slate-700/60 backdrop-blur-md" title="Módulos Activos en tu Plan">
                <span className="text-[11px] font-bold text-slate-400 mr-1">Módulos:</span>
                {MODULE_ICON_LIST.filter(m => activeModules.includes(m.id)).map(m => {
                  const IconComp = m.icon;
                  return (
                    <span 
                      key={m.id} 
                      title={m.name}
                      className={`p-1 rounded-lg border flex items-center justify-center transition-all hover:scale-115 ${m.color}`}
                    >
                      <IconComp size={13} />
                    </span>
                  );
                })}
              </div>

              {currentUser?.tenant?.created_at && (
                <>
                  <span className="text-slate-700 hidden sm:inline">•</span>
                  <span className="text-slate-400">Cliente desde {new Date(currentUser.tenant.created_at).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                </>
              )}
              <span className="text-slate-700 hidden sm:inline">•</span>
              <span className="flex items-center gap-1.5 text-slate-400">
                <ShieldCheck size={14} className="text-blue-400" />
                Supervisión activa
              </span>
            </div>
          </div>

          {/* Botón X con Menú de Opciones de Ocultar */}
          <div className="absolute top-5 right-5 z-20">
            <button
              onClick={() => setShowDismissMenu(!showDismissMenu)}
              className="p-2.5 text-slate-400 hover:text-white hover:bg-white/10 rounded-full transition-all backdrop-blur-md border border-transparent hover:border-slate-700"
              title="Opciones para ocultar bienvenida"
            >
              <X size={18} />
            </button>

            {/* Menú Desplegable de Cierre */}
            {showDismissMenu && (
              <div className="absolute right-0 mt-2 w-64 bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl p-2 z-30 text-left animate-in fade-in slide-in-from-top-2 duration-150">
                <div className="px-3 py-2 border-b border-slate-800 text-[11px] font-black text-slate-400 uppercase tracking-wider">
                  ¿Ocultar este mensaje?
                </div>
                <button
                  onClick={handleDismissSession}
                  className="w-full text-left px-3 py-2.5 rounded-xl text-xs font-bold text-slate-200 hover:bg-slate-800 hover:text-sky-300 transition-colors flex items-center gap-2.5"
                >
                  <EyeOff size={14} className="text-sky-400 shrink-0" />
                  <span>Ocultar en esta sesión</span>
                </button>
                <button
                  onClick={handleDismissPermanent}
                  className="w-full text-left px-3 py-2.5 rounded-xl text-xs font-bold text-slate-200 hover:bg-slate-800 hover:text-rose-400 transition-colors flex items-center gap-2.5 border-t border-slate-800/60 mt-1"
                >
                  <CheckCircle2 size={14} className="text-rose-400 shrink-0" />
                  <span>No volver a mostrar nunca</span>
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Si el banner fue ocultado (Eliminación completa del DOM para espacio 100% libre) */}
      {/* Nada se renderiza cuando se oculta en esta sesión o permanentemente */}

      {activeHeaderTab === 'onboarding' ? (
        <GlobalSystemSettingsPanel initialTab="onboarding" />
      ) : (
        <>
          {/* Paneles de resolución del Reloj. Viven aquí porque el rediseño Monitor 360
              sustituyó a DashboardTalent360 como pantalla del módulo `dashboard` (App.tsx),
              y sin ellos NADA de lo que el colaborador declara desde el dial (entrada tardía
              R56/R57, pánico R80, justificante R82, contingencia R83, tareas inconclusas M3)
              tiene dónde aprobarse o rechazarse. Son autocontenidos: sondean solos y se
              ocultan cuando no hay pendientes. */}
          <LateAuthorizationsPanel />
          <PanicIncidentsPanel />
          <LateJustificationsPanel />
          <ContingenciesPanel />
          <IncompleteTasksPanel />

          {/* 3. BARRA DE HERRAMIENTAS Y ACCIONES DEL MONITOR 360 */}
          <div className="bg-white border border-slate-200 rounded-3xl p-4 sm:p-5 shadow-sm space-y-4">
            
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-gradient-to-tr from-blue-600 to-indigo-600 text-white rounded-2xl shadow-md">
                  <Zap className="w-6 h-6 animate-pulse" />
                </div>
                <div>
                  <div className="flex items-center gap-2 flex-wrap">
                    <h2 className="text-lg font-bold text-slate-900">Control Operativo en Tiempo Real</h2>
                    <span className="px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full font-bold text-xs flex items-center gap-1.5 shadow-2xs">
                      <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                      {/* El refresco real es cada 5 s (setInterval de fetchData); el "(3s)"
                          era el del carrusel de módulos, otra cosa. */}
                      En Vivo (5s)
                    </span>
                  </div>
                  <p className="text-xs text-slate-500">Supervisión de colaboradores, tareas, proveedores y chat activo</p>
                </div>
              </div>

              {/* Botones de Control Rápidos */}
              <div className="flex flex-wrap items-center gap-2">
                {/* Bloque 2: sin llave de IA el botón prometía algo que no puede ocurrir. */}
                {iaDisponible && (
                  <button
                    onClick={handleGenerateAiPlan}
                    className="px-4 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 via-indigo-600 to-pink-600 text-white font-bold text-xs sm:text-sm hover:opacity-95 transition-all shadow-md shadow-purple-500/20 flex items-center justify-center gap-2 active:scale-95"
                  >
                    <Sparkles className="w-4 h-4 text-amber-300 animate-spin" />
                    <span>Plan Diario IA</span>
                  </button>
                )}

                <button 
                  onClick={() => setShowVendorModal(true)}
                  className="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs sm:text-sm border border-slate-200 transition-all flex items-center gap-2 active:scale-95"
                >
                  <Truck className="w-4 h-4 text-emerald-600" />
                  <span>+ Proveedor</span>
                  {inPremisesVendors > 0 && (
                    <span className="w-5 h-5 rounded-full bg-emerald-500 text-white font-bold text-xs flex items-center justify-center">
                      {inPremisesVendors}
                    </span>
                  )}
                </button>

                <button 
                  onClick={() => setShowChatDrawer(!showChatDrawer)}
                  className="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs sm:text-sm border border-slate-200 transition-all flex items-center gap-2 active:scale-95 relative"
                >
                  <MessageSquare className="w-4 h-4 text-blue-600" />
                  <span>Chat Operativo</span>
                  {chatMessages.length > 0 && (
                    <span className="w-2.5 h-2.5 rounded-full bg-blue-500 absolute top-1 right-1"></span>
                  )}
                </button>

                <button 
                  onClick={fetchData} 
                  disabled={refreshing}
                  className="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 transition-all"
                  title="Actualizar datos"
                >
                  <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin text-blue-600' : ''}`} />
                </button>
              </div>
            </div>

            {/* KPI METRICAS RAPIDAS (3 o 4 Fichas estilo botón/pill en 1 sola fila continua) */}
            <div className={`grid ${isModuleUnlocked('ats') ? 'grid-cols-4' : 'grid-cols-3'} gap-1.5 sm:gap-3 pt-1`}>
              {/* Personal Presente */}
              <div 
                onClick={() => setActiveModule && setActiveModule('rrhh')}
                className={`relative overflow-hidden bg-slate-50/90 hover:bg-slate-100/90 p-2.5 sm:p-3 rounded-2xl border border-slate-200/80 transition-all flex items-center justify-between group shadow-2xs ${setActiveModule ? 'cursor-pointer active:scale-98' : ''}`}
                title={setActiveModule ? "Ir a Gestión de RRHH" : undefined}
              >
                <div className="flex items-center gap-2 sm:gap-3 z-10 min-w-0">
                  <div className="p-2 sm:p-2.5 rounded-xl bg-emerald-100/90 text-emerald-700 font-bold shadow-2xs shrink-0">
                    <UserCheck className="w-4 h-4 sm:w-5 sm:h-5" />
                  </div>
                  <div className="min-w-0">
                    <div className="text-sm sm:text-lg font-black text-slate-900 leading-tight truncate">{activeCount} / {staffCount}</div>
                    <div className="text-[10px] sm:text-xs text-slate-500 font-bold tracking-tight truncate">
                      {staffCount > activeCount + breakCount
                        ? `Personal · ${staffCount - activeCount - breakCount} sin checar`
                        : 'Personal'}
                    </div>
                  </div>
                </div>
                <UserCheck className="absolute -right-2 -bottom-2 sm:-right-3 sm:-bottom-3 w-12 h-12 sm:w-16 sm:h-16 text-emerald-600/10 pointer-events-none group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300" />
              </div>

              {/* En Almuerzo/Break */}
              <div className="relative overflow-hidden bg-slate-50/90 hover:bg-slate-100/90 p-2.5 sm:p-3 rounded-2xl border border-slate-200/80 transition-all flex items-center justify-between group shadow-2xs">
                <div className="flex items-center gap-2 sm:gap-3 z-10 min-w-0">
                  <div className="p-2 sm:p-2.5 rounded-xl bg-amber-100/90 text-amber-700 font-bold shadow-2xs shrink-0">
                    <Clock className="w-4 h-4 sm:w-5 sm:h-5" />
                  </div>
                  <div className="min-w-0">
                    <div className="text-sm sm:text-lg font-black text-slate-900 leading-tight truncate">{breakCount}</div>
                    <div className="text-[10px] sm:text-xs text-slate-500 font-bold tracking-tight truncate">Almuerzo</div>
                  </div>
                </div>
                <Clock className="absolute -right-2 -bottom-2 sm:-right-3 sm:-bottom-3 w-12 h-12 sm:w-16 sm:h-16 text-amber-600/10 pointer-events-none group-hover:scale-110 group-hover:-rotate-6 transition-transform duration-300" />
              </div>

              {/* Eficiencia Promedio */}
              <div className="relative overflow-hidden bg-slate-50/90 hover:bg-slate-100/90 p-2.5 sm:p-3 rounded-2xl border border-slate-200/80 transition-all flex items-center justify-between group shadow-2xs">
                <div className="flex items-center gap-2 sm:gap-3 z-10 min-w-0">
                  <div className="p-2 sm:p-2.5 rounded-xl bg-blue-100/90 text-blue-700 font-bold shadow-2xs shrink-0">
                    <CheckSquare className="w-4 h-4 sm:w-5 sm:h-5" />
                  </div>
                  <div className="min-w-0">
                    <div className="text-sm sm:text-lg font-black text-slate-900 leading-tight truncate">{avgEfficiency}%</div>
                    <div className="text-[10px] sm:text-xs text-slate-500 font-bold tracking-tight truncate">Eficiencia</div>
                  </div>
                </div>
                <CheckSquare className="absolute -right-2 -bottom-2 sm:-right-3 sm:-bottom-3 w-12 h-12 sm:w-16 sm:h-16 text-blue-600/10 pointer-events-none group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300" />
              </div>

              {/* Prospectos (Solo si ATS está activo) */}
              {isModuleUnlocked('ats') && (
                <div 
                  onClick={() => setActiveModule && setActiveModule('ats')}
                  className={`relative overflow-hidden bg-slate-50/90 hover:bg-slate-100/90 p-2.5 sm:p-3 rounded-2xl border border-slate-200/80 transition-all flex items-center justify-between group shadow-2xs ${setActiveModule ? 'cursor-pointer active:scale-98' : ''}`}
                  title={setActiveModule ? "Ir a Bolsa de Trabajo ATS" : undefined}
                >
                  <div className="flex items-center gap-2 sm:gap-3 z-10 min-w-0">
                    <div className="p-2 sm:p-2.5 rounded-xl bg-purple-100/90 text-purple-700 font-bold shadow-2xs shrink-0">
                      <Briefcase className="w-4 h-4 sm:w-5 sm:h-5" />
                    </div>
                    <div className="min-w-0">
                      <div className="text-sm sm:text-lg font-black text-slate-900 leading-tight truncate">{prospectsCount}</div>
                      <div className="text-[10px] sm:text-xs text-slate-500 font-bold tracking-tight truncate">Prospectos</div>
                    </div>
                  </div>
                  <Briefcase className="absolute -right-2 -bottom-2 sm:-right-3 sm:-bottom-3 w-12 h-12 sm:w-16 sm:h-16 text-purple-600/10 pointer-events-none group-hover:scale-110 group-hover:-rotate-6 transition-transform duration-300" />
                </div>
              )}
            </div>

          </div>

          {/* PESTAÑAS MÓVILES PARA SMARTPHONE */}
          <div className="flex sm:hidden bg-slate-200/60 p-1 rounded-2xl border border-slate-200">
            <button
              onClick={() => setMobileTab('employees')}
              className={`flex-1 py-2 text-xs font-bold rounded-xl transition-all ${mobileTab === 'employees' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600'}`}
            >
              Personal ({users.length})
            </button>
            <button
              onClick={() => setMobileTab('feed')}
              className={`flex-1 py-2 text-xs font-bold rounded-xl transition-all ${mobileTab === 'feed' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600'}`}
            >
              Bitácora
            </button>
            <button
              onClick={() => setMobileTab('vendors')}
              className={`flex-1 py-2 text-xs font-bold rounded-xl transition-all ${mobileTab === 'vendors' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600'}`}
            >
              Proveedores ({vendors.length})
            </button>
          </div>

          {/* GRID PRINCIPAL: EMPLEADOS + BITÁCORA */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            {/* COLUMNA EMPLEADOS */}
            <div className={`lg:col-span-2 space-y-4 ${mobileTab !== 'employees' ? 'hidden sm:block' : ''}`}>
              
              <div className="flex flex-col sm:flex-row gap-3 justify-between items-center bg-white p-3.5 rounded-2xl border border-slate-200 shadow-sm">
                <div className="relative w-full sm:w-72">
                  <Search className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                  <input
                    type="text"
                    placeholder="Buscar colaborador o puesto..."
                    value={searchTerm}
                    onChange={e => setSearchTerm(e.target.value)}
                    className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl pl-9 pr-3 py-2 border border-slate-200 focus:outline-none focus:border-blue-600 font-medium"
                  />
                </div>

                <div className="flex gap-1.5 w-full sm:w-auto overflow-x-auto">
                  <button
                    onClick={() => setStatusFilter('all')}
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl border transition-all ${statusFilter === 'all' ? 'bg-blue-50 text-blue-600 border-blue-200' : 'bg-slate-50 text-slate-600 border-slate-200'}`}
                  >
                    Todos ({users.length})
                  </button>
                  <button
                    onClick={() => setStatusFilter('active')}
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl border transition-all ${statusFilter === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-50 text-slate-600 border-slate-200'}`}
                  >
                    🟢 En Turno
                  </button>
                  <button
                    onClick={() => setStatusFilter('break')}
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl border transition-all ${statusFilter === 'break' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-slate-50 text-slate-600 border-slate-200'}`}
                  >
                    🟡 En Almuerzo
                  </button>
                </div>
              </div>

              {/* GRID CARDS EMPLEADOS */}
              {loading ? (
                <div className="p-12 text-center text-slate-400 bg-white rounded-3xl border border-slate-200">
                  <RefreshCw className="w-8 h-8 animate-spin mx-auto text-blue-600 mb-2" />
                  <p className="text-sm font-semibold text-slate-700">Cargando monitor de actividad...</p>
                </div>
              ) : accesoDenegado ? (
                <div className="p-8 text-center bg-rose-50 rounded-3xl border border-rose-200 text-rose-700">
                  <UserCheck className="w-10 h-10 mx-auto text-rose-400 mb-2" />
                  <p className="text-sm font-bold">Sin acceso al monitor</p>
                  <p className="text-xs mt-1">{accesoDenegado}</p>
                </div>
              ) : filteredUsers.length === 0 ? (
                <div className="p-8 text-center bg-white rounded-3xl border border-slate-200 text-slate-500">
                  <UserCheck className="w-10 h-10 mx-auto text-slate-400 mb-2" />
                  <p className="text-sm font-semibold">No hay colaboradores activos en este filtro.</p>
                </div>
              ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {filteredUsers.map(u => (
                    <div 
                      key={u.id}
                      className="bg-white border border-slate-200 hover:border-blue-400 rounded-3xl p-4.5 transition-all duration-200 shadow-sm hover:shadow-md flex flex-col justify-between"
                    >
                      <div>
                        <div className="flex items-start justify-between gap-3 mb-3">
                          <div className="flex items-center gap-3">
                            <img 
                              src={u.avatar} 
                              alt={u.name} 
                              className="w-11 h-11 rounded-2xl bg-slate-100 border border-slate-200 object-cover"
                            />
                            <div>
                              <h3 className="font-bold text-sm text-slate-900">
                                {u.name}
                              </h3>
                              <span className="text-xs text-slate-500 font-medium">{u.role_name}</span>
                            </div>
                          </div>

                          <span className={`px-2.5 py-1 text-xs font-bold rounded-full border flex items-center gap-1.5 ${
                            u.status === 'active' 
                              ? 'bg-emerald-50 text-emerald-700 border-emerald-200' 
                              : u.status === 'break' 
                              ? 'bg-amber-50 text-amber-700 border-amber-200' 
                              : 'bg-rose-50 text-rose-700 border-rose-200'
                          }`}>
                            <span className={`w-2 h-2 rounded-full ${
                              u.status === 'active' ? 'bg-emerald-500 animate-pulse' : u.status === 'break' ? 'bg-amber-500' : 'bg-rose-500'
                            }`}></span>
                            {u.status_text}
                          </span>
                        </div>

                        <div className="bg-slate-50 p-3 rounded-2xl border border-slate-200/80 mb-3">
                          <div className="flex items-center justify-between text-xs text-slate-500 mb-1">
                            <span className="flex items-center gap-1 font-bold text-slate-700">
                              <CheckSquare className="w-3.5 h-3.5 text-blue-600" />
                              Tarea Actual:
                            </span>
                            <span className="text-slate-600 font-mono font-semibold">
                              {u.completed_tasks_count} completadas
                            </span>
                          </div>
                          
                          {u.active_task ? (
                            <div>
                              <p className="text-xs font-bold text-slate-900 line-clamp-1">
                                {u.active_task.title}
                              </p>
                              <div className="flex items-center justify-between text-[11px] text-slate-500 mt-1 font-medium">
                                <span>Estimado: {u.active_task.estimated_mins || 30} min</span>
                                <span className="text-blue-600 font-bold">Eficiencia: {u.efficiency}%</span>
                              </div>
                            </div>
                          ) : (
                            <p className="text-xs italic text-slate-400">Sin tarea activa asignada</p>
                          )}
                        </div>

                        <div className="space-y-1 mb-4">
                          <div className="flex justify-between text-[11px] text-slate-500 font-semibold">
                            {/* Decía "Avance de Jornada" pero pinta `efficiency` (puntualidad
                                + tareas cerradas): nada que ver con cuánto lleva del turno. */}
                            <span>Eficiencia del día</span>
                            <span className="font-bold text-slate-800">{u.efficiency}%</span>
                          </div>
                          <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden border border-slate-200">
                            <div 
                              className="bg-gradient-to-r from-blue-600 to-emerald-500 h-full rounded-full transition-all duration-500"
                              style={{ width: `${Math.min(100, u.efficiency)}%` }}
                            ></div>
                          </div>
                        </div>
                      </div>

                      <div className="flex items-center gap-2 pt-2 border-t border-slate-100">
                        <button 
                          onClick={() => {
                            setSelectedUserForAssign(u);
                            setShowAssignModal(true);
                          }}
                          className="flex-1 py-2 text-xs font-extrabold rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-100 border border-blue-200 transition-all flex items-center justify-center gap-1"
                        >
                          <Plus className="w-3.5 h-3.5" />
                          Asignar Tarea
                        </button>

                        <button 
                          onClick={() => {
                            setShowChatDrawer(true);
                            setChatInput(`@${u.name} `);
                          }}
                          className="p-2 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-200 transition-all"
                          title="Enviar mensaje directo"
                        >
                          <MessageSquare className="w-4 h-4 text-blue-600" />
                        </button>
                      </div>

                    </div>
                  ))}
                </div>
              )}

            </div>

            {/* COLUMNA BITÁCORA Y PROVEEDORES */}
            <div className={`space-y-6 ${mobileTab === 'employees' ? 'hidden sm:block' : ''}`}>
              
              <div className="bg-white border border-slate-200 rounded-3xl p-4.5 shadow-sm space-y-3">
                <div className="flex items-center justify-between">
                  <h3 className="text-xs font-bold text-slate-900 flex items-center gap-2 uppercase tracking-wider">
                    <Zap className="w-4 h-4 text-amber-500" />
                    Bitácora en Vivo
                  </h3>
                  <span className="text-[11px] font-semibold text-slate-400">Stream continuo</span>
                </div>

                <div className="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">
                  {feed.length === 0 ? (
                    <p className="text-xs text-slate-400 italic text-center py-6">Sin eventos registrados hoy</p>
                  ) : (
                    feed.map((item, idx) => (
                      <div 
                        key={item.id || idx}
                        className="p-3 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-start gap-2.5 text-xs hover:border-slate-300 transition-all"
                      >
                        <div className="p-1.5 rounded-lg bg-blue-100 text-blue-700 mt-0.5">
                          <Clock className="w-3.5 h-3.5" />
                        </div>
                        <div className="flex-1">
                          <div className="flex items-center justify-between">
                            <span className="font-bold text-slate-900">{item.user}</span>
                            <span className="text-[10px] text-slate-400 font-medium">{item.time}</span>
                          </div>
                          <p className="text-slate-600 mt-0.5 font-medium">{item.details}</p>

                          {/* Bitácora inmutable: un fichaje que nació de una corrección se
                              anuncia, y desde aquí se puede ver su historia o corregirlo — el
                              botón sólo si se tiene la capacidad, para no ofrecer lo que el
                              servidor va a rechazar con 403. */}
                          {item.time_entry_id && (
                            <div className="flex items-center gap-2 mt-1.5">
                              {item.creado_por_correccion_id && (
                                <EtiquetaCorregido
                                  compacta
                                  onVerHistoria={() => setFichajeEnHistoria(item.time_entry_id ?? null)}
                                />
                              )}
                              <button
                                type="button"
                                onClick={() => setFichajeEnHistoria(item.time_entry_id ?? null)}
                                className="text-[10px] font-bold text-slate-500 hover:text-slate-700 underline border-none bg-transparent cursor-pointer px-0"
                              >
                                Ver historia
                              </button>
                              {puedeCorregir && (
                                <BotonCorregirFichaje
                                  fichaje={{ id: item.time_entry_id, time: item.time }}
                                  onCorregido={fetchData}
                                />
                              )}
                            </div>
                          )}
                        </div>
                      </div>
                    ))
                  )}
                </div>
              </div>

              <div className="bg-white border border-slate-200 rounded-3xl p-4.5 shadow-sm space-y-3">
                <div className="flex items-center justify-between">
                  <h3 className="text-xs font-bold text-slate-900 flex items-center gap-2 uppercase tracking-wider">
                    <Truck className="w-4 h-4 text-emerald-600" />
                    Proveedores en Sitio
                  </h3>
                  <button 
                    onClick={() => setShowVendorModal(true)}
                    className="px-3 py-1 rounded-xl bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 text-xs font-extrabold flex items-center gap-1"
                  >
                    <Plus className="w-3.5 h-3.5" /> Registrar
                  </button>
                </div>

                <div className="space-y-2.5">
                  {vendors.length === 0 ? (
                    <p className="text-xs text-slate-400 italic text-center py-4">No hay proveedores en las instalaciones</p>
                  ) : (
                    vendors.map(v => (
                      <div 
                        key={v.id}
                        className={`p-3 rounded-2xl border flex items-center justify-between gap-3 text-xs transition-all ${
                          v.status === 'in_premises' 
                            ? 'bg-emerald-50/60 border-emerald-200 text-emerald-900' 
                            : 'bg-slate-50 border-slate-200 text-slate-500 opacity-60'
                        }`}
                      >
                        <div>
                          <div className="font-bold text-slate-900">{v.vendor_name}</div>
                          <div className="text-[11px] text-slate-600 font-medium">
                            Chofer: {v.driver_name} • Ref: {v.order_ref}
                          </div>
                          <div className="text-[10px] text-emerald-700 font-semibold mt-0.5">
                            Llegó: {v.arrival_time} • Atendió: {v.received_by}
                          </div>
                        </div>

                        {v.status === 'in_premises' && (
                          <button 
                            onClick={() => handleCompleteVendor(v.id)}
                            className="px-3 py-1 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-[11px] shadow-sm"
                          >
                            Salida
                          </button>
                        )}
                      </div>
                    ))
                  )}
                </div>
              </div>

            </div>

          </div>

          {/* 4. SECCIÓN DE ADOPCIÓN DE MÓDULOS & ROADMAP DE INNOVACIÓN (SLIDER 3S CON BOTÓN Y TARJETA DE SUGERENCIAS) */}
          <div className="bg-white border border-slate-200 rounded-3xl p-5 sm:p-6 shadow-sm space-y-4 relative overflow-hidden">
            {/* Barra de Gradiente Superior Elegante */}
            <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-500 via-purple-500 via-pink-500 to-amber-500"></div>

            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-slate-100 pb-4 gap-3 pt-1">
              <div>
                <h2 className="text-base sm:text-lg font-black text-slate-900 tracking-tight flex items-center gap-2 flex-wrap">
                  <Sparkles className="w-5 h-5 text-amber-500 animate-bounce" />
                  Nuevos Módulos Disponibles & Roadmap de Innovación
                </h2>
                <p className="text-xs text-slate-500 font-medium">Adopta funciones a la carta o sugiere las próximas innovaciones para tu organización</p>
              </div>

              <button
                onClick={handleSugerirFuncion}
                className="px-4 py-2 rounded-2xl bg-gradient-to-r from-amber-400 via-amber-500 to-amber-600 hover:from-amber-500 hover:to-amber-700 text-slate-950 font-black text-xs shadow-md shadow-amber-500/20 border border-amber-300 shrink-0 flex items-center gap-2 transition-all active:scale-95 cursor-pointer"
              >
                <Sparkles size={16} />
                💡 Sugerir una función a nuestro equipo
              </button>
            </div>

            {/* Carrusel Deslizable Automático (Snap Slider 3s) en Celular y Escritorio */}
            <div 
              ref={modulesSliderRef}
              className="flex overflow-x-auto snap-x snap-mandatory gap-4 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden pb-3 pt-1 text-slate-900"
            >
              
              {/* ATS Card */}
              {(() => {
                const isAtsActive = activeModules.includes('ats');
                return (
                  <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl transition-all relative overflow-hidden group shadow-md hover:shadow-lg flex flex-col justify-between border-2 border-violet-300/80 bg-gradient-to-b from-violet-50/60 via-slate-50/40 to-white hover:border-violet-400 ring-1 ring-violet-200/50">
                    <div>
                      {/* Imagen Alusiva al Tema */}
                      <div className="relative h-28 w-full mb-3 rounded-xl overflow-hidden shadow-xs border border-violet-200/80">
                        <img 
                          src="/assets/modules/ats.jpg" 
                          alt="Reclutamiento ATS" 
                          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                        />
                        <div className="absolute top-2 left-2 p-1.5 bg-white/95 backdrop-blur-xs text-violet-600 rounded-lg shadow-2xs border border-violet-200">
                          <Briefcase size={16} />
                        </div>
                      </div>

                      <div className="flex justify-between items-start mb-2 relative z-10">
                        <h3 className="font-bold text-slate-900 text-xs">Reclutamiento ATS</h3>
                        <button 
                          disabled={isAdoptionSaving}
                          onClick={() => handleToggleModule('ats', 'Reclutamiento ATS')}
                          className={`text-[11px] font-black px-3 py-1 rounded-xl transition-all shadow-xs shrink-0 ${
                            isAtsActive 
                              ? 'bg-violet-600 hover:bg-violet-700 text-white shadow-violet-500/30' 
                              : 'bg-white hover:bg-violet-50 text-violet-700 border border-violet-300'
                          }`}
                        >
                          {isAtsActive ? 'Adoptado' : 'Adoptar'}
                        </button>
                      </div>

                      {/* Frase Gancho Persuasiva */}
                      <div className="mb-2.5 px-2 py-1.5 rounded-lg bg-violet-600/10 border border-violet-300/40 text-[10px] font-bold text-violet-800 leading-tight relative z-10">
                        🔥 "¡Contrata al mejor talento en tiempo récord antes que la competencia!"
                      </div>

                      <p className="text-slate-500 text-[10px] mb-2 leading-relaxed font-medium relative z-10">Vacantes, bolsa de trabajo y entrevistas.</p>
                    </div>

                    <span className="text-xs font-black text-violet-600 relative z-10">+$29 MXN / mes</span>
                    <Briefcase className="absolute -right-3 -bottom-3 w-20 h-20 text-violet-600/15 pointer-events-none group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300" />
                  </div>
                );
              })()}

              {/* LMS Card */}
              {(() => {
                const isLmsActive = activeModules.includes('academia');
                return (
                  <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl transition-all relative overflow-hidden group shadow-md hover:shadow-lg flex flex-col justify-between border-2 border-sky-300/80 bg-gradient-to-b from-sky-50/60 via-slate-50/40 to-white hover:border-sky-400 ring-1 ring-sky-200/50">
                    <div>
                      {/* Imagen Alusiva al Tema */}
                      <div className="relative h-28 w-full mb-3 rounded-xl overflow-hidden shadow-xs border border-sky-200/80">
                        <img 
                          src="/assets/modules/academia.jpg" 
                          alt="Academia 360" 
                          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                        />
                        <div className="absolute top-2 left-2 p-1.5 bg-white/95 backdrop-blur-xs text-sky-600 rounded-lg shadow-2xs border border-sky-200">
                          <GraduationCap size={16} />
                        </div>
                      </div>

                      <div className="flex justify-between items-start mb-2 relative z-10">
                        <h3 className="font-bold text-slate-900 text-xs">Academia 360</h3>
                        <button 
                          disabled={isAdoptionSaving}
                          onClick={() => handleToggleModule('academia', 'Academia 360')}
                          className={`text-[11px] font-black px-3 py-1 rounded-xl transition-all shadow-xs shrink-0 ${
                            isLmsActive 
                              ? 'bg-sky-600 hover:bg-sky-700 text-white shadow-sky-500/30' 
                              : 'bg-white hover:bg-sky-50 text-sky-700 border border-sky-300'
                          }`}
                        >
                          {isLmsActive ? 'Adoptado' : 'Adoptar'}
                        </button>
                      </div>

                      {/* Frase Gancho Persuasiva */}
                      <div className="mb-2.5 px-2 py-1.5 rounded-lg bg-sky-600/10 border border-sky-300/40 text-[10px] font-bold text-sky-800 leading-tight relative z-10">
                        🚀 "¡Capacita e induce a tu personal 100% en automático sin perder tiempo!"
                      </div>

                      <p className="text-slate-500 text-[10px] mb-2 leading-relaxed font-medium relative z-10">Cursos interactivos e inducción.</p>
                    </div>

                    <span className="text-xs font-black text-sky-600 relative z-10">+$49 MXN / mes</span>
                    <GraduationCap className="absolute -right-3 -bottom-3 w-20 h-20 text-sky-600/15 pointer-events-none group-hover:scale-110 group-hover:-rotate-6 transition-transform duration-300" />
                  </div>
                );
              })()}

              {/* Reports Card */}
              {(() => {
                const isReportsActive = activeModules.includes('reportes');
                return (
                  <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl transition-all relative overflow-hidden group shadow-md hover:shadow-lg flex flex-col justify-between border-2 border-rose-300/80 bg-gradient-to-b from-rose-50/60 via-slate-50/40 to-white hover:border-rose-400 ring-1 ring-rose-200/50">
                    <div>
                      {/* Imagen Alusiva al Tema */}
                      <div className="relative h-28 w-full mb-3 rounded-xl overflow-hidden shadow-xs border border-rose-200/80">
                        <img 
                          src="/assets/modules/reportes.jpg" 
                          alt="Reportes IA" 
                          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                        />
                        <div className="absolute top-2 left-2 p-1.5 bg-white/95 backdrop-blur-xs text-rose-600 rounded-lg shadow-2xs border border-rose-200">
                          <BarChart3 size={16} />
                        </div>
                      </div>

                      <div className="flex justify-between items-start mb-2 relative z-10">
                        <h3 className="font-bold text-slate-900 text-xs">Reportes IA</h3>
                        <button 
                          disabled={isAdoptionSaving}
                          onClick={() => handleToggleModule('reportes', 'Reportes IA')}
                          className={`text-[11px] font-black px-3 py-1 rounded-xl transition-all shadow-xs shrink-0 ${
                            isReportsActive 
                              ? 'bg-rose-600 hover:bg-rose-700 text-white shadow-rose-500/30' 
                              : 'bg-white hover:bg-rose-50 text-rose-700 border border-rose-300'
                          }`}
                        >
                          {isReportsActive ? 'Adoptado' : 'Adoptar'}
                        </button>
                      </div>

                      {/* Frase Gancho Persuasiva */}
                      <div className="mb-2.5 px-2 py-1.5 rounded-lg bg-rose-600/10 border border-rose-300/40 text-[10px] font-bold text-rose-800 leading-tight relative z-10">
                        💡 "¡Detecta fugas de tiempo y toma decisiones operativas con IA!"
                      </div>

                      <p className="text-slate-500 text-[10px] mb-2 leading-relaxed font-medium relative z-10">Faltas, retardos y analítica Ley Silla.</p>
                    </div>

                    <span className="text-xs font-black text-rose-600 relative z-10">+$19 MXN / mes</span>
                    <BarChart3 className="absolute -right-3 -bottom-3 w-20 h-20 text-rose-600/15 pointer-events-none group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300" />
                  </div>
                );
              })()}

              {/* Archivo Digital Card */}
              {(() => {
                const isDocsActive = activeModules.includes('documentos');
                return (
                  <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl transition-all relative overflow-hidden group shadow-md hover:shadow-lg flex flex-col justify-between border-2 border-amber-300/80 bg-gradient-to-b from-amber-50/60 via-slate-50/40 to-white hover:border-amber-400 ring-1 ring-amber-200/50">
                    <div>
                      {/* Imagen Alusiva al Tema */}
                      <div className="relative h-28 w-full mb-3 rounded-xl overflow-hidden shadow-xs border border-amber-200/80">
                        <img 
                          src="/assets/modules/documentos.jpg" 
                          alt="Archivo Digital" 
                          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                        />
                        <div className="absolute top-2 left-2 p-1.5 bg-white/95 backdrop-blur-xs text-amber-600 rounded-lg shadow-2xs border border-amber-200">
                          <FileText size={16} />
                        </div>
                      </div>

                      <div className="flex justify-between items-start mb-2 relative z-10">
                        <h3 className="font-bold text-slate-900 text-xs">Archivo Digital</h3>
                        <button 
                          disabled={isAdoptionSaving}
                          onClick={() => handleToggleModule('documentos', 'Archivo Digital')}
                          className={`text-[11px] font-black px-3 py-1 rounded-xl transition-all shadow-xs shrink-0 ${
                            isDocsActive 
                              ? 'bg-amber-600 hover:bg-amber-700 text-white shadow-amber-500/30' 
                              : 'bg-white hover:bg-amber-50 text-amber-700 border border-amber-300'
                          }`}
                        >
                          {isDocsActive ? 'Adoptado' : 'Adoptar'}
                        </button>
                      </div>

                      {/* Frase Gancho Persuasiva */}
                      <div className="mb-2.5 px-2 py-1.5 rounded-lg bg-amber-600/10 border border-amber-300/40 text-[10px] font-bold text-amber-800 leading-tight relative z-10">
                        📁 "¡Di adiós al papel y protege tus expedientes laborales en la nube!"
                      </div>

                      <p className="text-slate-500 text-[10px] mb-2 leading-relaxed font-medium relative z-10">Expedientes avanzados y contratos.</p>
                    </div>

                    <span className="text-xs font-black text-amber-600 relative z-10">+$19 MXN / mes</span>
                    <FileText className="absolute -right-3 -bottom-3 w-20 h-20 text-amber-600/15 pointer-events-none group-hover:scale-110 group-hover:-rotate-6 transition-transform duration-300" />
                  </div>
                );
              })()}

              {/* Facturacion CFDI Card */}
              {(() => {
                const isCfdiActive = activeModules.includes('facturacion');
                return (
                  <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl transition-all relative overflow-hidden group shadow-md hover:shadow-lg flex flex-col justify-between border-2 border-emerald-300/80 bg-gradient-to-b from-emerald-50/60 via-slate-50/40 to-white hover:border-emerald-400 ring-1 ring-emerald-200/50">
                    <div>
                      {/* Imagen Alusiva al Tema */}
                      <div className="relative h-28 w-full mb-3 rounded-xl overflow-hidden shadow-xs border border-emerald-200/80">
                        <img 
                          src="/assets/modules/facturacion.jpg" 
                          alt="Nómina CFDI 4.0" 
                          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                        />
                        <div className="absolute top-2 left-2 p-1.5 bg-white/95 backdrop-blur-xs text-emerald-600 rounded-lg shadow-2xs border border-emerald-200">
                          <Receipt size={16} />
                        </div>
                      </div>

                      <div className="flex justify-between items-start mb-2 relative z-10">
                        <h3 className="font-bold text-slate-900 text-xs">Nómina CFDI 4.0</h3>
                        <button 
                          disabled={isAdoptionSaving}
                          onClick={() => handleToggleModule('facturacion', 'Nómina CFDI 4.0')}
                          className={`text-[11px] font-black px-3 py-1 rounded-xl transition-all shadow-xs shrink-0 ${
                            isCfdiActive 
                              ? 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-emerald-500/30' 
                              : 'bg-white hover:bg-emerald-50 text-emerald-700 border border-emerald-300'
                          }`}
                        >
                          {isCfdiActive ? 'Adoptado' : 'Adoptar'}
                        </button>
                      </div>

                      {/* Frase Gancho Persuasiva */}
                      <div className="mb-2.5 px-2 py-1.5 rounded-lg bg-emerald-600/10 border border-emerald-300/40 text-[10px] font-bold text-emerald-800 leading-tight relative z-10">
                        ⚡ "¡Timbra tu nómina masiva ante el SAT sin errores y en un solo clic!"
                      </div>

                      <p className="text-slate-500 text-[10px] mb-2 leading-relaxed font-medium relative z-10">Timbrado masivo del SAT.</p>
                    </div>

                    <span className="text-xs font-black text-emerald-600 relative z-10">+$39 MXN / mes</span>
                    <Receipt className="absolute -right-3 -bottom-3 w-20 h-20 text-emerald-600/15 pointer-events-none group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300" />
                  </div>
                );
              })()}

              {/* ROADMAP 1: Evaluación 360 */}
              <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl bg-gradient-to-b from-indigo-900 via-slate-900 to-slate-950 text-white border-2 border-amber-400/60 shadow-lg flex flex-col justify-between group hover:border-amber-400 transition-all relative overflow-hidden">
                <div className="relative z-10">
                  <div className="flex justify-between items-center mb-2">
                    <span className="px-2.5 py-0.5 rounded-full bg-amber-400/20 text-amber-300 border border-amber-400/40 text-[10px] font-black uppercase">
                      🚀 Roadmap • Q3 2026
                    </span>
                    <Award size={18} className="text-amber-400" />
                  </div>
                  <h3 className="font-bold text-white text-xs mb-1">Evaluación 360° & Desempeño</h3>
                  <div className="mb-2.5 px-2 py-1.5 rounded-lg bg-amber-400/10 border border-amber-400/30 text-[10px] font-bold text-amber-200 leading-tight">
                    🔥 "¡Mide el potencial, competencias y retroalimentación 360° de tus líderes!"
                  </div>
                  <p className="text-slate-300 text-[10px] mb-2 leading-relaxed font-medium">Evaluaciones periódicas y matriz de talento.</p>
                </div>
                <span className="text-xs font-black text-amber-400 relative z-10">Próximo Lanzamiento</span>
                <Award className="absolute -right-3 -bottom-3 w-20 h-20 text-amber-400/15 pointer-events-none group-hover:scale-110 transition-transform duration-300" />
              </div>

              {/* ROADMAP 2: Firma Electrónica NOM-151 */}
              <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl bg-gradient-to-b from-slate-900 via-indigo-950 to-slate-950 text-white border-2 border-blue-400/60 shadow-lg flex flex-col justify-between group hover:border-blue-400 transition-all relative overflow-hidden">
                <div className="relative z-10">
                  <div className="flex justify-between items-center mb-2">
                    <span className="px-2.5 py-0.5 rounded-full bg-blue-400/20 text-blue-300 border border-blue-400/40 text-[10px] font-black uppercase">
                      🖋️ Roadmap • Q3 2026
                    </span>
                    <FileText size={18} className="text-blue-400" />
                  </div>
                  <h3 className="font-bold text-white text-xs mb-1">Firma Electrónica Avanzada</h3>
                  <div className="mb-2.5 px-2 py-1.5 rounded-lg bg-blue-400/10 border border-blue-400/30 text-[10px] font-bold text-blue-200 leading-tight">
                    🖋️ "¡Firma contratos y convenios digitalmente con validez oficial NOM-151!"
                  </div>
                  <p className="text-slate-300 text-[10px] mb-2 leading-relaxed font-medium">Contratos digitales e historial con sello legal.</p>
                </div>
                <span className="text-xs font-black text-blue-400 relative z-10">Próximo Lanzamiento</span>
                <FileText className="absolute -right-3 -bottom-3 w-20 h-20 text-blue-400/15 pointer-events-none group-hover:scale-110 transition-transform duration-300" />
              </div>

              {/* ROADMAP 3: Bot WhatsApp */}
              <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl bg-gradient-to-b from-slate-900 via-emerald-950 to-slate-950 text-white border-2 border-emerald-400/60 shadow-lg flex flex-col justify-between group hover:border-emerald-400 transition-all relative overflow-hidden">
                <div className="relative z-10">
                  <div className="flex justify-between items-center mb-2">
                    <span className="px-2.5 py-0.5 rounded-full bg-emerald-400/20 text-emerald-300 border border-emerald-400/40 text-[10px] font-black uppercase">
                      💬 Roadmap • Q4 2026
                    </span>
                    <MessageSquare size={18} className="text-emerald-400" />
                  </div>
                  <h3 className="font-bold text-white text-xs mb-1">Bot Asistente WhatsApp 24/7</h3>
                  <div className="mb-2.5 px-2 py-1.5 rounded-lg bg-emerald-400/10 border border-emerald-400/30 text-[10px] font-bold text-emerald-200 leading-tight">
                    💬 "¡Atención a colaboradores, recibos de nómina y vacaciones vía WhatsApp!"
                  </div>
                  <p className="text-slate-300 text-[10px] mb-2 leading-relaxed font-medium">Respuestas automatizadas e inteligencia artificial.</p>
                </div>
                <span className="text-xs font-black text-emerald-400 relative z-10">Próximo Lanzamiento</span>
                <MessageSquare className="absolute -right-3 -bottom-3 w-20 h-20 text-emerald-400/15 pointer-events-none group-hover:scale-110 transition-transform duration-300" />
              </div>

              {/* CARD DEDICADA 9: SUGERIR UNA FUNCIÓN / MÓDULO */}
              <div className="min-w-[280px] sm:min-w-[310px] snap-center p-4 rounded-2xl bg-gradient-to-br from-amber-500 via-purple-700 to-slate-900 text-white border-2 border-amber-300 shadow-xl flex flex-col justify-between group relative overflow-hidden">
                <div className="relative z-10">
                  <div className="flex justify-between items-center mb-2">
                    <span className="px-2.5 py-0.5 rounded-full bg-white/20 text-white border border-white/30 text-[10px] font-black uppercase flex items-center gap-1">
                      <Sparkles size={12} className="animate-spin" /> Tu Opinión Importa
                    </span>
                    <Sparkles size={20} className="text-amber-300 animate-bounce" />
                  </div>
                  <h3 className="font-black text-white text-sm mb-1">¿Tienes una idea para Talent360?</h3>
                  <p className="text-amber-100 text-xs mb-3 font-medium leading-relaxed">
                    💡 "¡Construimos la plataforma junto contigo! Dinos qué función o módulo necesita tu empresa."
                  </p>
                </div>

                <button
                  onClick={handleSugerirFuncion}
                  className="w-full py-2.5 rounded-xl bg-white text-purple-950 font-black text-xs shadow-md hover:bg-amber-50 transition-all flex items-center justify-center gap-2 cursor-pointer active:scale-95 relative z-10"
                >
                  <Sparkles size={16} className="text-amber-600" />
                  Sugerir una función ahora
                </button>
                <Sparkles className="absolute -right-3 -bottom-3 w-24 h-24 text-white/10 pointer-events-none group-hover:scale-110 transition-transform duration-300" />
              </div>

            </div>

            {/* Puntos Indicadores del Slider Automático (3s) */}
            <div className="flex justify-center items-center gap-1.5 pt-2">
              {[0, 1, 2, 3, 4, 5, 6, 7, 8].map(idx => (
                <div 
                  key={idx} 
                  className={`h-2 rounded-full transition-all duration-500 ${activeModuleIndex === idx ? 'w-6 bg-purple-600 shadow-sm shadow-purple-500/40' : 'w-2 bg-slate-300'}`} 
                />
              ))}
            </div>
          </div>

        </>
      )}

      {/* MODAL PLAN DE TRABAJO IA */}
      {showAiModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white border border-slate-200 rounded-3xl max-w-2xl w-full p-6 shadow-2xl relative max-h-[90vh] overflow-y-auto text-slate-900">
            <button 
              onClick={() => setShowAiModal(false)}
              className="absolute top-4 right-4 p-2 text-slate-400 hover:text-slate-700"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-3 mb-4">
              <div className="p-3 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 text-white shadow-md">
                <Sparkles className="w-6 h-6 animate-pulse text-amber-300" />
              </div>
              <div>
                <h2 className="text-lg font-black text-slate-900">Plan de Trabajo Diario Asistido por IA</h2>
                <p className="text-xs text-slate-500 font-medium">Asistencia real + Organigrama + Manuales SOP de Obsidian Vault</p>
              </div>
            </div>

            {aiLoading ? (
              <div className="py-16 text-center text-slate-500">
                <Sparkles className="w-10 h-10 animate-spin mx-auto text-purple-600 mb-3" />
                <p className="text-sm font-bold text-slate-800">La IA está procesando el personal presente y los SOPs...</p>
                <p className="text-xs text-slate-500 mt-1">Generando matriz de distribución de tareas...</p>
              </div>
            ) : aiError ? (
              <div className="py-12 text-center space-y-4">
                <AlertTriangle className="w-10 h-10 mx-auto text-rose-500" />
                <p className="text-sm font-bold text-slate-800">{aiError}</p>
                <button
                  onClick={handleGenerateAiPlan}
                  className="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-slate-800 transition-colors"
                >
                  Reintentar
                </button>
              </div>
            ) : aiPlan ? (
              <div className="space-y-4">

                {/* Si la IA no contestó se dice, en vez de inventar un plan y firmarlo como suyo. */}
                {!aiPlan.ai_available ? (
                  <div className="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-2">
                    <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                    <span>
                      <span className="font-extrabold">El asistente de IA no está disponible en este momento.</span>{' '}
                      No se generó ninguna sugerencia — no hay plan que mostrar. Puedes repartir el trabajo
                      a mano desde el tablero o con "Tarea Express".
                    </span>
                  </div>
                ) : (
                  <>
                    <div className="p-4 rounded-2xl bg-purple-50 border border-purple-200 text-purple-950 text-xs">
                      <span className="font-extrabold text-purple-900">Diagnóstico IA:</span>{' '}
                      {aiPlan.summary || 'Sin resumen.'}
                    </div>

                    {aiPlan.staffing_gap_detected && (
                      <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-center gap-2 font-medium">
                        <AlertTriangle className="w-4 h-4 text-amber-600 flex-shrink-0" />
                        <span>Hay huecos de personal hoy: faltó gente cuyas responsabilidades conviene cubrir.</span>
                      </div>
                    )}

                    <div className="space-y-3">
                      <h3 className="text-xs font-black text-slate-400 uppercase tracking-wider">
                        Distribución Recomendada ({aiPlan.suggestions.length})
                      </h3>

                      {aiPlan.suggestions.length === 0 ? (
                        <div className="p-4 rounded-2xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500 font-medium text-center">
                          La IA no sugirió cambios: con el personal de hoy el trabajo pendiente está cubierto.
                        </div>
                      ) : (
                        aiPlan.suggestions.map((s, idx) => (
                          <div key={idx} className="bg-slate-50 p-3.5 rounded-2xl border border-slate-200 space-y-1.5">
                            <div className="flex items-center justify-between gap-3 text-xs font-bold text-slate-900">
                              <span className="min-w-0 truncate">
                                {s.suggested_new_task_title
                                  ? `➕ ${s.suggested_new_task_title}`
                                  : '🔁 Reasignar tarea pendiente'}
                              </span>
                              {!!s.estimated_mins && (
                                <span className="text-[11px] text-slate-500 font-mono font-bold shrink-0">{s.estimated_mins} min</span>
                              )}
                            </div>
                            <div className="text-[11px] font-bold text-purple-700">
                              → {nombreDestinoSugerido(s.suggested_target_type, s.suggested_target_id)}
                            </div>
                            {s.reason && (
                              <p className="text-[11px] text-slate-600 font-medium leading-relaxed">{s.reason}</p>
                            )}
                          </div>
                        ))
                      )}
                    </div>
                  </>
                )}

                {/* El botón decía "Aprobar y Despachar Plan Diario" y solo cerraba el modal:
                    ningún colaborador recibía nada. El plan es —por diseño del backend— una
                    SUGERENCIA para la junta; asignar se hace desde el tablero o Tarea Express. */}
                <div className="pt-4 border-t border-slate-200 space-y-2">
                  <p className="text-[11px] text-slate-500 font-medium text-center">
                    Esta es una sugerencia para tu junta: no asigna tareas por sí sola.
                    Repártelas con "Tarea Express" o desde el tablero de tareas.
                  </p>
                  <button
                    onClick={() => setShowAiModal(false)}
                    className="w-full py-3 rounded-2xl bg-slate-900 text-white font-extrabold text-xs hover:bg-slate-800 transition-all shadow-md"
                  >
                    Cerrar
                  </button>
                </div>

              </div>
            ) : null}
          </div>
        </div>
      )}

      {/* MODAL ASIGNAR TAREA EXPRESS */}
      {showAssignModal && selectedUserForAssign && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white border border-slate-200 rounded-3xl max-w-md w-full p-6 shadow-2xl relative text-slate-900">
            <button 
              onClick={() => setShowAssignModal(false)}
              className="absolute top-4 right-4 p-2 text-slate-400 hover:text-slate-700"
            >
              <X className="w-5 h-5" />
            </button>

            <h2 className="text-base font-black text-slate-900 mb-1">
              Asignar Tarea Express
            </h2>
            <p className="text-xs text-slate-500 mb-4 font-medium">
              Para: <span className="text-blue-600 font-bold">{selectedUserForAssign.name}</span> ({selectedUserForAssign.role_name})
            </p>

            <div className="space-y-3 mb-6">
              <div>
                <label className="text-xs text-slate-700 font-bold mb-1 block">Título de la Tarea</label>
                <input 
                  type="text"
                  placeholder="Ej: Reorganizar bodega y arqueo..."
                  value={customTaskTitle}
                  onChange={e => setCustomTaskTitle(e.target.value)}
                  className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-blue-600 font-medium"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xs text-slate-700 font-bold mb-1 block">Tiempo Estimado (min)</label>
                  <input 
                    type="number"
                    min={1}
                    value={customTaskMins}
                    onChange={e => setCustomTaskMins(Number(e.target.value))}
                    className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-blue-600 font-medium"
                  />
                </div>
                <div>
                  <label className="text-xs text-slate-700 font-bold mb-1 block">Prioridad</label>
                  <select
                    value={customTaskPriority}
                    onChange={e => setCustomTaskPriority(e.target.value)}
                    className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-blue-600 font-medium"
                  >
                    <option value="normal">Normal</option>
                    <option value="alta">Alta</option>
                    <option value="bloqueante">Bloqueante</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="text-xs text-slate-700 font-bold mb-1 block">Evidencia al completar</label>
                <select
                  value={customTaskEvidencia}
                  onChange={e => setCustomTaskEvidencia(e.target.value)}
                  className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-blue-600 font-medium"
                >
                  <option value="ninguno">Sin evidencia</option>
                  <option value="evidencia_foto">📷 Foto</option>
                  <option value="captura_numero">🔢 Capturar un número</option>
                </select>
              </div>

              {customTaskEvidencia !== 'ninguno' && (
                <div>
                  <label className="text-xs text-slate-700 font-bold mb-1 block">Instrucción de la evidencia</label>
                  <input
                    type="text"
                    placeholder={customTaskEvidencia === 'evidencia_foto' ? 'Ej: Foto de la reparación terminada.' : 'Ej: Ingrese las piezas contadas.'}
                    value={customTaskPrompt}
                    onChange={e => setCustomTaskPrompt(e.target.value)}
                    className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-blue-600 font-medium"
                  />
                </div>
              )}

              <p className="text-[10px] text-slate-400 font-medium">La tarea exigirá la firma del supervisor al validarse — paga monedas con las mismas reglas que una rutina.</p>

              {assignError && (
                <p className="text-[11px] text-red-600 font-bold bg-red-50 rounded-xl p-2.5">{assignError}</p>
              )}
            </div>

            <div className="flex gap-2">
              <button 
                onClick={() => setShowAssignModal(false)}
                className="flex-1 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-bold text-xs hover:bg-slate-200"
              >
                Cancelar
              </button>
              <button 
                onClick={handleAssignExpressTask}
                className="flex-1 py-2.5 rounded-xl bg-blue-600 text-white font-extrabold text-xs hover:bg-blue-700 shadow-md"
              >
                Asignar Tarea
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL REGISTRAR PROVEEDOR */}
      {showVendorModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white border border-slate-200 rounded-3xl max-w-md w-full p-6 shadow-2xl relative text-slate-900">
            <button 
              onClick={() => setShowVendorModal(false)}
              className="absolute top-4 right-4 p-2 text-slate-400 hover:text-slate-700"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-2 mb-4">
              <Truck className="w-5 h-5 text-emerald-600" />
              <h2 className="text-base font-black text-slate-900">Registrar Entrada de Proveedor</h2>
            </div>

            <div className="space-y-3 mb-6">
              <div>
                <label className="text-xs text-slate-700 font-bold mb-1 block">Empresa / Proveedor</label>
                <input 
                  type="text"
                  placeholder="Ej: Lácteos Lala, Coca-Cola..."
                  value={newVendorName}
                  onChange={e => setNewVendorName(e.target.value)}
                  className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium"
                />
              </div>

              <div>
                <label className="text-xs text-slate-700 font-bold mb-1 block">Nombre del Chofer / Repartidor</label>
                <input 
                  type="text"
                  placeholder="Ej: Juan Pérez"
                  value={newVendorDriver}
                  onChange={e => setNewVendorDriver(e.target.value)}
                  className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium"
                />
              </div>

              <div>
                <label className="text-xs text-slate-700 font-bold mb-1 block">Factura o Remisión #</label>
                <input 
                  type="text"
                  placeholder="Ej: FAC-99401"
                  value={newVendorOrderRef}
                  onChange={e => setNewVendorOrderRef(e.target.value)}
                  className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium"
                />
              </div>
            </div>

            <div className="flex gap-2">
              <button 
                onClick={() => setShowVendorModal(false)}
                className="flex-1 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-bold text-xs hover:bg-slate-200"
              >
                Cancelar
              </button>
              <button 
                onClick={handleRegisterVendor}
                className="flex-1 py-2.5 rounded-xl bg-emerald-600 text-white font-extrabold text-xs hover:bg-emerald-700 shadow-md"
              >
                Confirmar Check-In
              </button>
            </div>
          </div>
        </div>
      )}

      {/* DRAWER CHAT OPERATIVO */}
      {showChatDrawer && (
        <div className="fixed bottom-0 right-0 sm:right-6 w-full sm:w-96 bg-white border border-slate-200 rounded-t-3xl sm:rounded-2xl p-4 shadow-2xl z-40 space-y-3 text-slate-900">
          <div className="flex items-center justify-between border-b border-slate-100 pb-2">
            <div className="flex items-center gap-2">
              <MessageSquare className="w-4 h-4 text-blue-600" />
              <span className="text-xs font-bold text-slate-900">Chat Operativo de Sucursal</span>
            </div>
            <button onClick={() => setShowChatDrawer(false)} className="text-slate-400 hover:text-slate-700">
              <X className="w-4 h-4" />
            </button>
          </div>

          {/* D3: la retención se DICE aquí — una purga que nadie anuncia es una emboscada. */}
          <p className="text-[10px] text-slate-400 font-medium -mt-1">
            Los mensajes del equipo se conservan {chatRetentionDays} días (los 📌 conservados, los privados y los avisos de megáfono no se borran).
          </p>

          <div className="h-64 overflow-y-auto space-y-2 pr-1 text-xs">
            {chatMessages.length === 0 ? (
              <p className="text-slate-400 italic text-center py-8">Inicia la conversación con tu equipo...</p>
            ) : (
              chatMessages.map((msg, idx) => (
                <div
                  key={msg.id || idx}
                  className={`p-2.5 rounded-xl border ${
                    msg.receiver_id
                      ? 'bg-violet-50 border-violet-200'
                      : 'bg-slate-50 border-slate-200/80'
                  }`}
                >
                  <div className="flex justify-between text-[10px] text-slate-500 mb-0.5 font-medium">
                    <span className="font-bold text-blue-600">{msg.sender_name}</span>
                    <span className="flex items-center gap-1.5">
                      {msg.time}
                      {/* D3: conservar un mensaje (citado en un incidente) lo excluye de la purga. */}
                      {!msg.receiver_id && msg.id && (
                        <button
                          onClick={async () => {
                            try {
                              const r = await axiosInstance.post(`/admin/dashboard/messages/${msg.id}/preserve`);
                              setChatMessages(prev => prev.map(m => m.id === msg.id ? { ...m, preserved: r.data.preserved } : m));
                            } catch { /* sin drama: el siguiente poll repinta la verdad */ }
                          }}
                          title={msg.preserved ? 'Conservado: la purga no lo toca. Clic para soltarlo.' : 'Conservar (citado en un incidente)'}
                          className={msg.preserved ? '' : 'opacity-30 hover:opacity-100'}
                        >
                          📌
                        </button>
                      )}
                    </span>
                  </div>
                  {/* Un privado se distingue a simple vista y dice para quién es: si no, nadie
                      sabría si lo que escribió lo leyó el turno entero. */}
                  {msg.receiver_id && (
                    <p className="text-[10px] font-black text-violet-700 uppercase tracking-wider mb-0.5">
                      🔒 Privado para {msg.receiver_name || 'un colaborador'}
                    </p>
                  )}
                  <p className="text-slate-800 font-medium">{msg.content}</p>
                </div>
              ))
            )}
            <div ref={chatBottomRef} />
          </div>

          <div className="pt-2 border-t border-slate-100 space-y-2">
            <div className="flex items-center gap-2">
              <span className="text-[10px] font-black text-slate-400 uppercase tracking-wider shrink-0">Para</span>
              <select
                value={chatDestinatario}
                onChange={e => setChatDestinatario(e.target.value ? Number(e.target.value) : '')}
                className="flex-1 bg-slate-50 text-slate-800 text-[11px] font-bold rounded-lg px-2 py-1.5 border border-slate-200 focus:outline-none focus:border-blue-600"
              >
                <option value="">Todo el equipo</option>
                {/* `staff` (TODA la plantilla con cuenta), no `users` (solo en turno): el
                    privado típico —"pasa a la oficina mañana"— es para quien ya salió, y con
                    la lista de en turno el destinatario ni aparecía fuera de horario.
                    `user_id`, NO id de empleado: el destinatario es una cuenta de usuario;
                    confundirlos es la familia §29/§30. Quien no tenga cuenta no se ofrece. */}
                {staff.map((u) => (
                  <option key={u.user_id} value={u.user_id}>
                    {u.name} — sólo para él/ella
                  </option>
                ))}
              </select>
            </div>

            <div className="flex gap-2">
              <input
                type="text"
                placeholder={chatDestinatario ? 'Mensaje privado…' : 'Escribir mensaje al equipo...'}
                value={chatInput}
                onChange={e => setChatInput(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && handleSendMessage()}
                className={`flex-1 text-slate-900 text-xs rounded-xl px-3 py-2 border focus:outline-none font-medium ${
                  chatDestinatario
                    ? 'bg-violet-50 border-violet-300 focus:border-violet-600'
                    : 'bg-slate-50 border-slate-200 focus:border-blue-600'
                }`}
              />
              <button
                onClick={handleSendMessage}
                className={`p-2 text-white rounded-xl shadow-sm ${
                  chatDestinatario ? 'bg-violet-600 hover:bg-violet-700' : 'bg-blue-600 hover:bg-blue-700'
                }`}
              >
                <Send className="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Bitácora inmutable: la historia de un fichaje se puede LEER sin poder corregir —
          ver la evidencia no es moverla. */}
      {fichajeEnHistoria !== null && (
        <HistoriaDeFichaje fichajeId={fichajeEnHistoria} onCerrar={() => setFichajeEnHistoria(null)} />
      )}

    </div>
  );
}
