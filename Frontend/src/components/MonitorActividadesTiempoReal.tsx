import React, { useState, useEffect, useRef } from 'react';
import { EtiquetaCorregido, HistoriaDeFichaje, BotonCorregirFichaje, puedeCorregirFichajes } from './reloj/CorreccionDeFichaje';
import {
  Users, Clock, CheckSquare, Bot, Sparkles, Truck, MessageSquare,
  Plus, Search, Filter, ShieldCheck, AlertTriangle, ChevronRight, X, EyeOff,
  RefreshCw, Play, CheckCircle2, UserCheck, Building2, FileText,
  Camera, Zap, Send, Shield, LayoutDashboard, Settings, Award,
  Briefcase, GraduationCap, BarChart3, Receipt, Sparkle, Lock, Pin
} from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { GlobalSystemSettingsPanel } from './GlobalSystemSettingsPanel';
import { LateAuthorizationsPanel } from './reloj/LateAuthorizationsPanel';
import { PanicIncidentsPanel } from './reloj/PanicIncidentsPanel';
import { LateJustificationsPanel } from './reloj/LateJustificationsPanel';
import { ContingenciesPanel } from './reloj/ContingenciesPanel';
import { IncompleteTasksPanel } from './reloj/IncompleteTasksPanel';
import { FichajesPorRevisarPanel } from './reloj/FichajesPorRevisarPanel';

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
  { id: 'reloj', name: 'Reloj Checador', icon: Clock, color: 'text-navy-300 bg-accent/20 border-navy-300/40' },
  { id: 'rrhh', name: 'Gestión RRHH', icon: Users, color: 'text-success-text bg-success-icon/20 border-success-text/40' },
  { id: 'operativo', name: 'Control Operativo', icon: Zap, color: 'text-warning-text bg-warning-icon/20 border-warning-text/40' },
  { id: 'ats', name: 'Reclutamiento ATS', icon: Briefcase, color: 'text-navy-300 bg-accent/20 border-navy-300/40' },
  { id: 'academia', name: 'Academia 360', icon: GraduationCap, color: 'text-navy-300 bg-accent/20 border-navy-300/40' },
  { id: 'reportes', name: 'Reportes IA', icon: BarChart3, color: 'text-danger-text bg-danger-icon/20 border-danger-text/40' },
  { id: 'documentos', name: 'Archivo Digital', icon: FileText, color: 'text-warning-text bg-warning-icon/20 border-warning-text/40' },
  { id: 'facturacion', name: 'Pre-nómina para contador', icon: Receipt, color: 'text-success-text bg-success-icon/20 border-success-text/40' },
];

export function MonitorActividadesTiempoReal({ setActiveModule }: { setActiveModule?: (mod: string) => void }) {
  const { currentUser, currentTier, systemSettings, isModuleUnlocked } = useAppStore();

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
  /**
   * Quién rebasó el tope de tiempo extraordinario de la empresa esta semana, con su cifra real.
   *
   * (2026-09-05) Hasta hoy no había contador de horas extra en ninguna pantalla del producto: el
   * jefe —que es quien puede repartir la carga o mandar a alguien a casa— no tenía cómo enterarse.
   * Las cifras las calcula el servidor con la misma fórmula que el reporte de horas trabajadas.
   * Es un aviso: no bloquea a nadie.
   */
  const [alertasHorasExtra, setAlertasHorasExtra] = useState<{
    user_id: number; nombre: string; minutos: number; tope: number; dias: number;
    desde: string; hasta: string;
  }[]>([]);

  // UI & Filter States
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  // Un 403 del monitor se DICE; antes se tragaba y la pantalla quedaba en "0 / 0".
  const [accesoDenegado, setAccesoDenegado] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [mobileTab, setMobileTab] = useState<'employees' | 'feed'>('employees');
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
        setAlertasHorasExtra(res.data.data.alertas_horas_extra || []);
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
  const avgEfficiency = users.length > 0
    ? Math.round(users.reduce((acc, u) => acc + (u.efficiency || 0), 0) / users.length)
    : null;

  const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];

  return (
    <div className="max-w-[1440px] mx-auto min-w-0 space-y-5 text-text-1 pb-8">

      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed top-20 right-6 z-50 bg-slate-900 text-white font-bold text-xs px-4 py-3 rounded-2xl shadow-xl border border-slate-800 animate-in fade-in slide-in-from-top-3 duration-300">
          {toastMessage}
        </div>
      )}

      {/* 1. HEADER BIENVENIDA Y AGRADECIMIENTO (ALINEACIÓN CENTRAL) */}
      {headerDismissType === 'none' && (
        <div className="bg-white text-text-1 border border-border rounded-2xl px-4 py-3 sm:px-5 shadow-sm relative overflow-hidden group text-left flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          {/* Ambient Glow & Grid Accents */}
          <div className="hidden" />
          <div className="hidden" />
          <div className="hidden" />

          {/* Logo Emblem Oficial de Talent 360 */}
          <div className="hidden">
            <div className="relative w-6 h-6 flex items-center justify-center">
              <div className="absolute inset-0 bg-accent rounded-full blur-xs opacity-75 animate-pulse"></div>
              <div className="relative w-6 h-6 rounded-full bg-gradient-to-tr from-accent to-navy-400 flex items-center justify-center text-white font-black text-xs shadow-xs">
                360
              </div>
            </div>
            <span className="text-xs font-black tracking-widest uppercase text-slate-200">
              TALENT <span className="text-navy-300 font-black">360</span>
            </span>
          </div>

          <div className="relative z-10 min-w-0">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-accent mb-1">Resumen operativo</p>
            <h1 className="text-lg sm:text-xl font-semibold text-text-1 tracking-tight leading-tight truncate">
              {/* H12: el default era 'DecorArte 360' — una empresa recién registrada saludaba
                  con el nombre de OTRA en su primera pantalla. Se prefiere el nombre real
                  (tenant o company_name de settings) y, si aún no cargó, algo neutro. */}
              Bienvenido a <span className="text-navy-800">{currentUser?.tenant?.name || systemSettings?.company_name || 'tu empresa'}</span>
            </h1>

            <p className="hidden">
              "{welcomePhrase}"
            </p>

            {/* Metadatos: Plan + Iconos de Módulos Contratados + Cliente Desde */}
            <div className="flex flex-wrap items-center gap-2 mt-2 text-[11px] font-medium text-text-3">
              {/* Badge del Plan */}
              <span className="bg-navy-50 border border-navy-100 text-navy-800 font-semibold px-2.5 py-1 rounded-full flex items-center gap-1.5">
                <Award size={14} className="text-warning-text" />
                Plan {(currentUser?.tenant?.plan || currentTier).toUpperCase()}
              </span>

              {/* Iconos de Módulos Activos / Contratados */}
              <div className="hidden" title="Módulos Activos en tu Plan">
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
                  <span className="text-border hidden sm:inline">•</span>
                  <span>Cliente desde {new Date(currentUser.tenant.created_at).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                </>
              )}
              <span className="text-border hidden sm:inline">•</span>
              <span className="flex items-center gap-1.5">
                <ShieldCheck size={14} className="text-accent" />
                Supervisión activa
              </span>
            </div>
          </div>

          {/* Botón X con Menú de Opciones de Ocultar */}
          <div className="absolute top-2 right-2 z-20">
            <button
              onClick={() => setShowDismissMenu(!showDismissMenu)}
              className="p-2 text-text-3 hover:text-text-1 hover:bg-page rounded-lg transition-all"
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
                  className="w-full text-left px-3 py-2.5 rounded-xl text-xs font-bold text-slate-200 hover:bg-slate-800 hover:text-navy-100 transition-colors flex items-center gap-2.5"
                >
                  <EyeOff size={14} className="text-navy-300 shrink-0" />
                  <span>Ocultar en esta sesión</span>
                </button>
                <button
                  onClick={handleDismissPermanent}
                  className="w-full text-left px-3 py-2.5 rounded-xl text-xs font-bold text-slate-200 hover:bg-slate-800 hover:text-danger-text transition-colors flex items-center gap-2.5 border-t border-slate-800/60 mt-1"
                >
                  <CheckCircle2 size={14} className="text-danger-text shrink-0" />
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
          <FichajesPorRevisarPanel />

          {/* 3. BARRA DE HERRAMIENTAS Y ACCIONES DEL MONITOR 360 */}
          <div className="bg-white border border-border rounded-2xl p-4 sm:p-5 shadow-sm space-y-4">

            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-navy-50 text-accent rounded-xl border border-navy-100">
                  <Zap className="w-5 h-5" />
                </div>
                <div>
                  <div className="flex items-center gap-2 flex-wrap">
                    <h2 className="text-base sm:text-lg font-semibold text-text-1">Control Operativo en Tiempo Real</h2>
                    <span className="px-2 py-0.5 bg-success-bg text-success-text border border-success-text/20 rounded-full font-semibold text-xs flex items-center gap-1.5">
                      <span className="w-1.5 h-1.5 rounded-full bg-success-icon"></span>
                      {/* El refresco real es cada 5 s (setInterval de fetchData); el "(3s)"
                          era el del carrusel de módulos, otra cosa. */}
                      En Vivo (5s)
                    </span>
                  </div>
                  <p className="text-xs text-text-3">Supervisión de colaboradores, tareas e incidencias en curso</p>
                </div>
              </div>

              {/* Botones de Control Rápidos */}
              <div className="flex flex-wrap items-center gap-2">
                {/* Bloque 2: sin llave de IA el botón prometía algo que no puede ocurrir. */}
                {iaDisponible && users.length > 0 && (
                  <button
                    onClick={handleGenerateAiPlan}
                    className="px-3 py-2 rounded-xl bg-accent text-white font-semibold text-xs hover:bg-accent-hover transition-colors flex items-center justify-center gap-2"
                  >
                    <Sparkles className="w-4 h-4" />
                    <span>Plan Diario IA</span>
                  </button>
                )}

                {inPremisesVendors > 0 && (
                  <button
                    onClick={() => setActiveModule?.('reloj')}
                    className="inline-flex items-center gap-2 rounded-xl border border-warning-text/20 bg-warning-bg px-3 py-2 text-xs font-bold text-warning-text hover:border-warning-text/40"
                    title="Gestionar en Reloj Checador → Herramientas"
                  >
                    <Truck className="h-4 w-4" />
                    {inPremisesVendors} {inPremisesVendors === 1 ? 'visita activa' : 'visitas activas'}
                  </button>
                )}

                <button
                  onClick={() => setShowChatDrawer(!showChatDrawer)}
                  className="px-4 py-2.5 rounded-xl bg-page hover:bg-slate-200 text-text-1 font-bold text-xs sm:text-sm border border-border transition-all flex items-center gap-2 active:scale-95 relative"
                >
                  <MessageSquare className="w-4 h-4 text-accent" />
                  <span>Chat Operativo</span>
                  {chatMessages.length > 0 && (
                    <span className="w-2.5 h-2.5 rounded-full bg-accent absolute top-1 right-1"></span>
                  )}
                </button>

                <button
                  onClick={fetchData}
                  disabled={refreshing}
                  className="p-2.5 rounded-xl bg-page hover:bg-slate-200 text-text-2 border border-border transition-all"
                  title="Actualizar datos"
                >
                  <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin text-accent' : ''}`} />
                </button>
              </div>
            </div>

            {/* KPI METRICAS RAPIDAS (3 o 4 Fichas estilo botón/pill en 1 sola fila continua) */}
            <div className={`grid grid-cols-2 ${isModuleUnlocked('ats') ? 'lg:grid-cols-4' : 'sm:grid-cols-3'} gap-2 sm:gap-3 pt-1`}>
              {/* Personal Presente */}
              <div
                onClick={() => setActiveModule && setActiveModule('rrhh')}
                className={`relative overflow-hidden bg-page/90 hover:bg-page/90 p-2.5 sm:p-3 rounded-2xl border border-border/80 transition-all flex items-center justify-between group shadow-2xs ${setActiveModule ? 'cursor-pointer active:scale-98' : ''}`}
                title={setActiveModule ? "Ir a Gestión de RRHH" : undefined}
              >
                <div className="flex items-center gap-2 sm:gap-3 z-10 min-w-0">
                  <div className="p-2 sm:p-2.5 rounded-xl bg-success-bg/90 text-success-text font-bold shadow-2xs shrink-0">
                    <UserCheck className="w-4 h-4 sm:w-5 sm:h-5" />
                  </div>
                  <div className="min-w-0">
                    <div className="text-sm sm:text-lg font-black text-text-1 leading-tight truncate">{activeCount} / {staffCount}</div>
                    <div className="text-xs sm:text-xs text-text-3 font-bold tracking-tight truncate">
                      {staffCount > activeCount + breakCount
                        ? `Personal · ${staffCount - activeCount - breakCount} sin checar`
                        : 'Personal'}
                    </div>
                  </div>
                </div>
              </div>

              {/* En Almuerzo/Break */}
              <div className="relative overflow-hidden bg-page/90 hover:bg-page/90 p-2.5 sm:p-3 rounded-2xl border border-border/80 transition-all flex items-center justify-between group shadow-2xs">
                <div className="flex items-center gap-2 sm:gap-3 z-10 min-w-0">
                  <div className="p-2 sm:p-2.5 rounded-xl bg-warning-bg/90 text-warning-text font-bold shadow-2xs shrink-0">
                    <Clock className="w-4 h-4 sm:w-5 sm:h-5" />
                  </div>
                  <div className="min-w-0">
                    <div className="text-sm sm:text-lg font-black text-text-1 leading-tight truncate">{breakCount}</div>
                    <div className="text-xs sm:text-xs text-text-3 font-bold tracking-tight truncate">Almuerzo</div>
                  </div>
                </div>
              </div>

              {/* Eficiencia Promedio */}
              <div className="relative overflow-hidden bg-page/90 hover:bg-page/90 p-2.5 sm:p-3 rounded-2xl border border-border/80 transition-all flex items-center justify-between group shadow-2xs">
                <div className="flex items-center gap-2 sm:gap-3 z-10 min-w-0">
                  <div className="p-2 sm:p-2.5 rounded-xl bg-accent-soft/90 text-accent font-bold shadow-2xs shrink-0">
                    <CheckSquare className="w-4 h-4 sm:w-5 sm:h-5" />
                  </div>
                  <div className="min-w-0">
                    <div className="text-sm sm:text-lg font-black text-text-1 leading-tight truncate">{avgEfficiency === null ? '—' : `${avgEfficiency}%`}</div>
                    <div className="text-xs sm:text-xs text-text-3 font-bold tracking-tight truncate">{avgEfficiency === null ? 'Sin datos' : 'Eficiencia'}</div>
                  </div>
                </div>
              </div>

              {/* Prospectos (Solo si ATS está activo) */}
              {isModuleUnlocked('ats') && (
                <div
                  onClick={() => setActiveModule && setActiveModule('ats')}
                  className={`relative overflow-hidden bg-page/90 hover:bg-page/90 p-2.5 sm:p-3 rounded-2xl border border-border/80 transition-all flex items-center justify-between group shadow-2xs ${setActiveModule ? 'cursor-pointer active:scale-98' : ''}`}
                  title={setActiveModule ? "Ir a Bolsa de Trabajo ATS" : undefined}
                >
                  <div className="flex items-center gap-2 sm:gap-3 z-10 min-w-0">
                    <div className="p-2 sm:p-2.5 rounded-xl bg-accent-soft/90 text-accent font-bold shadow-2xs shrink-0">
                      <Briefcase className="w-4 h-4 sm:w-5 sm:h-5" />
                    </div>
                    <div className="min-w-0">
                      <div className="text-sm sm:text-lg font-black text-text-1 leading-tight truncate">{prospectsCount}</div>
                      <div className="text-xs sm:text-xs text-text-3 font-bold tracking-tight truncate">Prospectos</div>
                    </div>
                  </div>
                </div>
              )}
            </div>

          </div>

          {/* TOPE DE TIEMPO EXTRAORDINARIO REBASADO (2026-09-05).
              Sale sólo cuando alguien lo rebasó de verdad —una alerta que siempre está encendida
              deja de leerse— y siempre con la CIFRA: cuánto lleva y cuál es el tope. Avisa, no
              bloquea: nadie deja de poder fichar por esto. */}
          {alertasHorasExtra.length > 0 && (
            <div className="rounded-2xl border border-warning-text/20 bg-warning-bg p-3 sm:p-4">
              <div className="flex items-center gap-2 mb-2">
                <AlertTriangle className="w-4 h-4 text-warning-text shrink-0" />
                <h4 className="text-xs sm:text-sm font-black text-warning-text">
                  Tope de horas extra rebasado ({alertasHorasExtra.length})
                </h4>
              </div>
              <div className="space-y-1.5">
                {alertasHorasExtra.map((a) => {
                  const enHoras = (min: number) => {
                    const h = Math.floor(min / 60);
                    const m = min % 60;
                    if (h === 0) return `${m} min`;
                    return m === 0 ? `${h} h` : `${h} h ${m} min`;
                  };
                  return (
                    <div key={a.user_id} className="text-[11px] sm:text-xs text-warning-text leading-snug">
                      <b>{a.nombre}</b> lleva <b>{enHoras(a.minutos)}</b> de tiempo extraordinario
                      esta semana ({a.dias} {a.dias === 1 ? 'día' : 'días'}); el tope de la empresa
                      es {enHoras(a.tope)}.
                    </div>
                  );
                })}
              </div>
              <p className="text-xs text-warning-text mt-2 pt-2 border-t border-warning-text/20">
                Semana del {alertasHorasExtra[0].desde} al {alertasHorasExtra[0].hasta}. El sistema
                sólo avisa: nadie queda bloqueado y la nómina no cambia (se paga por día, no por horas).
              </p>
            </div>
          )}

          {/* PESTAÑAS MÓVILES PARA SMARTPHONE */}
          <div className="flex sm:hidden bg-slate-200/60 p-1 rounded-2xl border border-border">
            <button
              onClick={() => setMobileTab('employees')}
              className={`flex-1 py-2 text-xs font-bold rounded-xl transition-all ${mobileTab === 'employees' ? 'bg-white text-accent shadow-sm' : 'text-text-2'}`}
            >
              Personal ({users.length})
            </button>
            <button
              onClick={() => setMobileTab('feed')}
              className={`flex-1 py-2 text-xs font-bold rounded-xl transition-all ${mobileTab === 'feed' ? 'bg-white text-accent shadow-sm' : 'text-text-2'}`}
            >
              Bitácora
            </button>
          </div>

          {/* GRID PRINCIPAL: EMPLEADOS + BITÁCORA */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {/* COLUMNA EMPLEADOS */}
            <div className={`lg:col-span-2 space-y-4 ${mobileTab !== 'employees' ? 'hidden sm:block' : ''}`}>

              <div className="flex flex-col sm:flex-row gap-3 justify-between items-center bg-white p-3.5 rounded-2xl border border-border shadow-sm">
                <div className="relative w-full sm:w-72">
                  <Search className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                  <input
                    type="text"
                    placeholder="Buscar colaborador o puesto..."
                    value={searchTerm}
                    onChange={e => setSearchTerm(e.target.value)}
                    className="w-full bg-page text-text-1 text-xs rounded-xl pl-9 pr-3 py-2 border border-border focus:outline-none focus:border-accent font-medium"
                  />
                </div>

                <div className="flex gap-1.5 w-full sm:w-auto overflow-x-auto">
                  <button
                    onClick={() => setStatusFilter('all')}
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl border transition-all ${statusFilter === 'all' ? 'bg-navy-50 text-accent border-border' : 'bg-page text-text-2 border-border'}`}
                  >
                    Todos ({users.length})
                  </button>
                  <button
                    onClick={() => setStatusFilter('active')}
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl border transition-all ${statusFilter === 'active' ? 'bg-success-bg text-success-text border-success-text/20' : 'bg-page text-text-2 border-border'}`}
                  >
                    <span className="inline-flex items-center gap-1.5"><CheckCircle2 size={12} /> En turno</span>
                  </button>
                  <button
                    onClick={() => setStatusFilter('break')}
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl border transition-all ${statusFilter === 'break' ? 'bg-warning-bg text-warning-text border-warning-text/20' : 'bg-page text-text-2 border-border'}`}
                  >
                    <span className="inline-flex items-center gap-1.5"><Clock size={12} /> En almuerzo</span>
                  </button>
                </div>
              </div>

              {/* GRID CARDS EMPLEADOS */}
              {loading ? (
                <div className="p-12 text-center text-slate-400 bg-white rounded-3xl border border-border">
                  <RefreshCw className="w-8 h-8 animate-spin mx-auto text-accent mb-2" />
                  <p className="text-sm font-semibold text-text-2">Cargando monitor de actividad...</p>
                </div>
              ) : accesoDenegado ? (
                <div className="p-8 text-center bg-danger-bg rounded-3xl border border-danger-text/20 text-danger-text">
                  <UserCheck className="w-10 h-10 mx-auto text-danger-text mb-2" />
                  <p className="text-sm font-bold">Sin acceso al monitor</p>
                  <p className="text-xs mt-1">{accesoDenegado}</p>
                </div>
              ) : filteredUsers.length === 0 ? (
                <div className="p-8 text-center bg-white rounded-3xl border border-border text-text-3">
                  <UserCheck className="w-10 h-10 mx-auto text-slate-400 mb-2" />
                  <p className="text-sm font-semibold">No hay colaboradores activos en este filtro.</p>
                </div>
              ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {filteredUsers.map(u => (
                    <div
                      key={u.id}
                      className="bg-white border border-border hover:border-navy-300 rounded-3xl p-4.5 transition-all duration-200 shadow-sm hover:shadow-md flex flex-col justify-between"
                    >
                      <div>
                        <div className="flex items-start justify-between gap-3 mb-3">
                          <div className="flex items-center gap-3">
                            <img
                              src={u.avatar}
                              alt={u.name}
                              className="w-11 h-11 rounded-2xl bg-page border border-border object-cover"
                            />
                            <div>
                              <h3 className="font-bold text-sm text-text-1">
                                {u.name}
                              </h3>
                              <span className="text-xs text-text-3 font-medium">{u.role_name}</span>
                            </div>
                          </div>

                          <span className={`px-2.5 py-1 text-xs font-bold rounded-full border flex items-center gap-1.5 ${
                            u.status === 'active'
                              ? 'bg-success-bg text-success-text border-success-text/20'
                              : u.status === 'break'
                              ? 'bg-warning-bg text-warning-text border-warning-text/20'
                              : 'bg-danger-bg text-danger-text border-danger-text/20'
                          }`}>
                            <span className={`w-2 h-2 rounded-full ${
                              u.status === 'active' ? 'bg-success-icon animate-pulse' : u.status === 'break' ? 'bg-warning-icon' : 'bg-danger-icon'
                            }`}></span>
                            {u.status_text}
                          </span>
                        </div>

                        <div className="bg-page p-3 rounded-2xl border border-border/80 mb-3">
                          <div className="flex items-center justify-between text-xs text-text-3 mb-1">
                            <span className="flex items-center gap-1 font-bold text-text-2">
                              <CheckSquare className="w-3.5 h-3.5 text-accent" />
                              Tarea Actual:
                            </span>
                            <span className="text-text-2 font-mono font-semibold">
                              {u.completed_tasks_count} completadas
                            </span>
                          </div>

                          {u.active_task ? (
                            <div>
                              <p className="text-xs font-bold text-text-1 line-clamp-1">
                                {u.active_task.title}
                              </p>
                              <div className="flex items-center justify-between text-[11px] text-text-3 mt-1 font-medium">
                                <span>Estimado: {u.active_task.estimated_mins || 30} min</span>
                                <span className="text-accent font-bold">Eficiencia: {u.efficiency}%</span>
                              </div>
                            </div>
                          ) : (
                            <p className="text-xs italic text-slate-400">Sin tarea activa asignada</p>
                          )}
                        </div>

                        <div className="space-y-1 mb-4">
                          <div className="flex justify-between text-[11px] text-text-3 font-semibold">
                            {/* Decía "Avance de Jornada" pero pinta `efficiency` (puntualidad
                                + tareas cerradas): nada que ver con cuánto lleva del turno. */}
                            <span>Eficiencia del día</span>
                            <span className="font-bold text-text-1">{u.efficiency}%</span>
                          </div>
                          <div className="w-full bg-page h-2 rounded-full overflow-hidden border border-border">
                            <div
                              className="bg-gradient-to-r from-accent to-success-icon h-full rounded-full transition-all duration-500"
                              style={{ width: `${Math.min(100, u.efficiency)}%` }}
                            ></div>
                          </div>
                        </div>
                      </div>

                      <div className="flex items-center gap-2 pt-2 border-t border-border">
                        <button
                          onClick={() => {
                            setSelectedUserForAssign(u);
                            setShowAssignModal(true);
                          }}
                          className="flex-1 py-2 text-xs font-extrabold rounded-xl bg-navy-50 text-accent hover:bg-accent-soft border border-border transition-all flex items-center justify-center gap-1"
                        >
                          <Plus className="w-3.5 h-3.5" />
                          Asignar Tarea
                        </button>

                        <button
                          onClick={() => {
                            setShowChatDrawer(true);
                            setChatInput(`@${u.name} `);
                          }}
                          className="p-2 rounded-xl bg-page text-text-2 hover:bg-slate-200 border border-border transition-all"
                          title="Enviar mensaje directo"
                        >
                          <MessageSquare className="w-4 h-4 text-accent" />
                        </button>
                      </div>

                    </div>
                  ))}
                </div>
              )}

            </div>

            {/* COLUMNA BITÁCORA */}
            <div className={`space-y-6 ${mobileTab === 'employees' ? 'hidden sm:block' : ''}`}>

              <div className="bg-white border border-border rounded-3xl p-4.5 shadow-sm space-y-3">
                <div className="flex items-center justify-between">
                  <h3 className="text-xs font-bold text-text-1 flex items-center gap-2 uppercase tracking-wider">
                    <Zap className="w-4 h-4 text-warning-text" />
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
                        className="p-3 rounded-2xl bg-page border border-border/80 flex items-start gap-2.5 text-xs hover:border-slate-300 transition-all"
                      >
                        <div className="p-1.5 rounded-lg bg-accent-soft text-accent mt-0.5">
                          <Clock className="w-3.5 h-3.5" />
                        </div>
                        <div className="flex-1">
                          <div className="flex items-center justify-between">
                            <span className="font-bold text-text-1">{item.user}</span>
                            <span className="text-xs text-slate-400 font-medium">{item.time}</span>
                          </div>
                          <p className="text-text-2 mt-0.5 font-medium">{item.details}</p>

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
                                className="text-xs font-bold text-text-3 hover:text-text-2 underline border-none bg-transparent cursor-pointer px-0"
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

            </div>

          </div>

        </>
      )}

      {/* MODAL PLAN DE TRABAJO IA */}
      {showAiModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white border border-border rounded-3xl max-w-2xl w-full p-6 shadow-2xl relative max-h-[90vh] overflow-y-auto text-text-1">
            <button
              onClick={() => setShowAiModal(false)}
              className="absolute top-4 right-4 p-2 text-slate-400 hover:text-text-2"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-3 mb-4">
              <div className="p-3 rounded-2xl bg-gradient-to-tr from-accent to-accent text-white shadow-md">
                <Sparkles className="w-6 h-6 animate-pulse text-warning-text" />
              </div>
              <div>
                <h2 className="text-lg font-black text-text-1">Plan de Trabajo Diario Asistido por IA</h2>
                <p className="text-xs text-text-3 font-medium">Asistencia real + Organigrama + Manuales SOP de Obsidian Vault</p>
              </div>
            </div>

            {aiLoading ? (
              <div className="py-16 text-center text-text-3">
                <Sparkles className="w-10 h-10 animate-spin mx-auto text-accent mb-3" />
                <p className="text-sm font-bold text-text-1">La IA está procesando el personal presente y los SOPs...</p>
                <p className="text-xs text-text-3 mt-1">Generando matriz de distribución de tareas...</p>
              </div>
            ) : aiError ? (
              <div className="py-12 text-center space-y-4">
                <AlertTriangle className="w-10 h-10 mx-auto text-danger-text" />
                <p className="text-sm font-bold text-text-1">{aiError}</p>
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
                  <div className="p-4 rounded-2xl bg-warning-bg border border-warning-text/20 text-warning-text text-xs flex items-start gap-2">
                    <AlertTriangle className="w-4 h-4 text-warning-text shrink-0 mt-0.5" />
                    <span>
                      <span className="font-extrabold">El asistente de IA no está disponible en este momento.</span>{' '}
                      No se generó ninguna sugerencia — no hay plan que mostrar. Puedes repartir el trabajo
                      a mano desde el tablero o con "Tarea Express".
                    </span>
                  </div>
                ) : (
                  <>
                    <div className="p-4 rounded-2xl bg-navy-50 border border-border text-brand-dark text-xs">
                      <span className="font-extrabold text-brand-dark">Diagnóstico IA:</span>{' '}
                      {aiPlan.summary || 'Sin resumen.'}
                    </div>

                    {aiPlan.staffing_gap_detected && (
                      <div className="p-3 rounded-xl bg-warning-bg border border-warning-text/20 text-warning-text text-xs flex items-center gap-2 font-medium">
                        <AlertTriangle className="w-4 h-4 text-warning-text flex-shrink-0" />
                        <span>Hay huecos de personal hoy: faltó gente cuyas responsabilidades conviene cubrir.</span>
                      </div>
                    )}

                    <div className="space-y-3">
                      <h3 className="text-xs font-black text-slate-400 uppercase tracking-wider">
                        Distribución Recomendada ({aiPlan.suggestions.length})
                      </h3>

                      {aiPlan.suggestions.length === 0 ? (
                        <div className="p-4 rounded-2xl bg-page border border-dashed border-border text-xs text-text-3 font-medium text-center">
                          La IA no sugirió cambios: con el personal de hoy el trabajo pendiente está cubierto.
                        </div>
                      ) : (
                        aiPlan.suggestions.map((s, idx) => (
                          <div key={idx} className="bg-page p-3.5 rounded-2xl border border-border space-y-1.5">
                            <div className="flex items-center justify-between gap-3 text-xs font-bold text-text-1">
                              <span className="min-w-0 truncate">
                                {s.suggested_new_task_title
                                  ? s.suggested_new_task_title
                                  : 'Reasignar tarea pendiente'}
                              </span>
                              {!!s.estimated_mins && (
                                <span className="text-[11px] text-text-3 font-mono font-bold shrink-0">{s.estimated_mins} min</span>
                              )}
                            </div>
                            <div className="text-[11px] font-bold text-accent">
                              → {nombreDestinoSugerido(s.suggested_target_type, s.suggested_target_id)}
                            </div>
                            {s.reason && (
                              <p className="text-[11px] text-text-2 font-medium leading-relaxed">{s.reason}</p>
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
                <div className="pt-4 border-t border-border space-y-2">
                  <p className="text-[11px] text-text-3 font-medium text-center">
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
          <div className="bg-white border border-border rounded-3xl max-w-md w-full p-6 shadow-2xl relative text-text-1">
            <button
              onClick={() => setShowAssignModal(false)}
              className="absolute top-4 right-4 p-2 text-slate-400 hover:text-text-2"
            >
              <X className="w-5 h-5" />
            </button>

            <h2 className="text-base font-black text-text-1 mb-1">
              Asignar Tarea Express
            </h2>
            <p className="text-xs text-text-3 mb-4 font-medium">
              Para: <span className="text-accent font-bold">{selectedUserForAssign.name}</span> ({selectedUserForAssign.role_name})
            </p>

            <div className="space-y-3 mb-6">
              <div>
                <label className="text-xs text-text-2 font-bold mb-1 block">Título de la Tarea</label>
                <input
                  type="text"
                  placeholder="Ej: Reorganizar bodega y arqueo..."
                  value={customTaskTitle}
                  onChange={e => setCustomTaskTitle(e.target.value)}
                  className="w-full bg-page text-text-1 text-xs rounded-xl p-3 border border-border focus:outline-none focus:border-accent font-medium"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xs text-text-2 font-bold mb-1 block">Tiempo Estimado (min)</label>
                  <input
                    type="number"
                    min={1}
                    value={customTaskMins}
                    onChange={e => setCustomTaskMins(Number(e.target.value))}
                    className="w-full bg-page text-text-1 text-xs rounded-xl p-3 border border-border focus:outline-none focus:border-accent font-medium"
                  />
                </div>
                <div>
                  <label className="text-xs text-text-2 font-bold mb-1 block">Prioridad</label>
                  <select
                    value={customTaskPriority}
                    onChange={e => setCustomTaskPriority(e.target.value)}
                    className="w-full bg-page text-text-1 text-xs rounded-xl p-3 border border-border focus:outline-none focus:border-accent font-medium"
                  >
                    <option value="normal">Normal</option>
                    <option value="alta">Alta</option>
                    <option value="bloqueante">Bloqueante</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="text-xs text-text-2 font-bold mb-1 block">Evidencia al completar</label>
                <select
                  value={customTaskEvidencia}
                  onChange={e => setCustomTaskEvidencia(e.target.value)}
                  className="w-full bg-page text-text-1 text-xs rounded-xl p-3 border border-border focus:outline-none focus:border-accent font-medium"
                >
                  <option value="ninguno">Sin evidencia</option>
                  <option value="evidencia_foto">Foto</option>
                  <option value="captura_numero">Capturar un número</option>
                </select>
              </div>

              {customTaskEvidencia !== 'ninguno' && (
                <div>
                  <label className="text-xs text-text-2 font-bold mb-1 block">Instrucción de la evidencia</label>
                  <input
                    type="text"
                    placeholder={customTaskEvidencia === 'evidencia_foto' ? 'Ej: Foto de la reparación terminada.' : 'Ej: Ingrese las piezas contadas.'}
                    value={customTaskPrompt}
                    onChange={e => setCustomTaskPrompt(e.target.value)}
                    className="w-full bg-page text-text-1 text-xs rounded-xl p-3 border border-border focus:outline-none focus:border-accent font-medium"
                  />
                </div>
              )}

              <p className="text-xs text-slate-400 font-medium">La tarea exigirá la firma del supervisor al validarse — paga monedas con las mismas reglas que una rutina.</p>

              {assignError && (
                <p className="text-[11px] text-danger-text font-bold bg-danger-bg rounded-xl p-2.5">{assignError}</p>
              )}
            </div>

            <div className="flex gap-2">
              <button
                onClick={() => setShowAssignModal(false)}
                className="flex-1 py-2.5 rounded-xl bg-page text-text-2 font-bold text-xs hover:bg-slate-200"
              >
                Cancelar
              </button>
              <button
                onClick={handleAssignExpressTask}
                className="flex-1 py-2.5 rounded-xl bg-accent text-white font-extrabold text-xs hover:bg-accent-hover shadow-md"
              >
                Asignar Tarea
              </button>
            </div>
          </div>
        </div>
      )}

      {/* DRAWER CHAT OPERATIVO */}
      {showChatDrawer && (
        <div className="fixed bottom-0 right-0 sm:right-6 w-full sm:w-96 bg-white border border-border rounded-t-3xl sm:rounded-2xl p-4 shadow-2xl z-40 space-y-3 text-text-1">
          <div className="flex items-center justify-between border-b border-border pb-2">
            <div className="flex items-center gap-2">
              <MessageSquare className="w-4 h-4 text-accent" />
              <span className="text-xs font-bold text-text-1">Chat Operativo de Sucursal</span>
            </div>
            <button onClick={() => setShowChatDrawer(false)} className="text-slate-400 hover:text-text-2">
              <X className="w-4 h-4" />
            </button>
          </div>

          {/* D3: la retención se DICE aquí — una purga que nadie anuncia es una emboscada. */}
          <p className="text-xs text-slate-400 font-medium -mt-1">
            Los mensajes del equipo se conservan {chatRetentionDays} días. Los conservados, privados y avisos no se borran.
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
                      ? 'bg-navy-50 border-border'
                      : 'bg-page border-border/80'
                  }`}
                >
                  <div className="flex justify-between text-xs text-text-3 mb-0.5 font-medium">
                    <span className="font-bold text-accent">{msg.sender_name}</span>
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
                          <Pin size={13} fill={msg.preserved ? 'currentColor' : 'none'} />
                        </button>
                      )}
                    </span>
                  </div>
                  {/* Un privado se distingue a simple vista y dice para quién es: si no, nadie
                      sabría si lo que escribió lo leyó el turno entero. */}
                  {msg.receiver_id && (
                    <p className="text-xs font-black text-accent uppercase tracking-wider mb-0.5 flex items-center gap-1">
                      <Lock size={11} /> Privado para {msg.receiver_name || 'un colaborador'}
                    </p>
                  )}
                  <p className="text-text-1 font-medium">{msg.content}</p>
                </div>
              ))
            )}
            <div ref={chatBottomRef} />
          </div>

          <div className="pt-2 border-t border-border space-y-2">
            <div className="flex items-center gap-2">
              <span className="text-xs font-black text-slate-400 uppercase tracking-wider shrink-0">Para</span>
              <select
                value={chatDestinatario}
                onChange={e => setChatDestinatario(e.target.value ? Number(e.target.value) : '')}
                className="flex-1 bg-page text-text-1 text-[11px] font-bold rounded-lg px-2 py-1.5 border border-border focus:outline-none focus:border-accent"
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
                className={`flex-1 text-text-1 text-xs rounded-xl px-3 py-2 border focus:outline-none font-medium ${
                  chatDestinatario
                    ? 'bg-navy-50 border-navy-300 focus:border-accent'
                    : 'bg-page border-border focus:border-accent'
                }`}
              />
              <button
                onClick={handleSendMessage}
                className={`p-2 text-white rounded-xl shadow-sm ${
                  chatDestinatario ? 'bg-accent hover:bg-accent-hover' : 'bg-accent hover:bg-accent-hover'
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
