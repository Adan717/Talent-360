import React, { useState, useEffect } from 'react';
import {
  Building2, Users, CreditCard, Activity,
  AlertOctagon, TrendingUp, DollarSign, ServerCrash,
  ArrowUpRight, ShieldAlert, ShieldCheck, GraduationCap, Loader2,
  User, LogOut, ChevronDown, Search, Filter, Eye, Key, LogIn, Ban,
  Info, RefreshCw, X, ShieldX, KeyRound, CheckCircle2, Settings,
  LifeBuoy, MessageSquare, Plus, Trash2, Sparkles, Monitor, Menu
} from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';
import { SaaSPlatformBilling } from './SaaSPlatformBilling';
import { CLOCK_FEATURE_TAGS_MATRIX } from './reloj/logic/clockFeatureTags';
import { slugParaCorreo } from '../lib/emailSlug';

/**
 * Estado de cobranza tal como lo respalda el backend (App\Support\EstadoDeCobranza).
 *
 * ANTES (2026-09-05) esta pantalla inventaba el estado por su cuenta y mentía en dos formas:
 *
 *  1. Calculaba "⚠️ Prueba Expirada" en el navegador con la sola presencia de `trial_ends_at`,
 *     y ponía ese cálculo ANTES de mirar `subscription_status`. Las 3 empresas vivas que están
 *     en 'active' arrastrando un `trial_ends_at` viejo del alta se veían como pruebas
 *     expiradas en vez de "✓ Suscrito", que era lo que decía el backend.
 *  2. Tenía un color ámbar para 'past_due' — un estado que NINGÚN código del backend escribía
 *     jamás. Era decoración de una situación que no podía ocurrir.
 *
 * Ahora manda el estado del backend; `trial_ends_at` sólo se lee cuando la empresa está de
 * verdad en periodo de prueba, y 'past_due' ya lo escribe el barrido `suscripciones:revisar-
 * vencidas`, así que el ámbar por fin significa algo. Cuando no hay fecha de corte la pantalla
 * lo dice en vez de callarlo: es la razón por la que la cobranza automática no revisa a nadie.
 */
type InsigniaDeCobranza = { etiqueta: string; clases: string; detalle?: string };

const diasDesde = (fecha?: string | null): number | null => {
  if (!fecha) return null;
  const t = new Date(fecha).getTime();
  if (Number.isNaN(t)) return null;
  return Math.floor((Date.now() - t) / 86400000);
};

const estadoDeCobranza = (t: any): InsigniaDeCobranza => {
  if (t?.billing_exempt) {
    return {
      etiqueta: 'Exenta de cobro',
      clases: 'text-accent bg-navy-50 border-border',
      detalle: t?.billing_exempt_reason || 'Sin motivo anotado',
    };
  }

  const estado = (t?.subscription_status || 'trial') as string;
  const corte = t?.current_period_end || null;

  if (estado === 'past_due') {
    const dias = diasDesde(corte);
    return {
      etiqueta: 'Pago vencido',
      clases: 'text-warning-text bg-warning-bg border-warning-text/20',
      detalle: dias !== null ? `${dias} día(s) desde la fecha de corte` : 'Sin fecha de corte registrada',
    };
  }

  if (estado === 'cancelled') {
    return { etiqueta: 'Baja', clases: 'text-danger-text bg-danger-bg border-danger-text/20' };
  }

  if (estado === 'active') {
    return {
      etiqueta: '✓ Suscrito',
      clases: 'text-success-text bg-success-bg border-success-text/20',
      detalle: corte ? undefined : 'Sin fecha de corte: la cobranza automática no la revisa',
    };
  }

  const fin = t?.trial_ends_at ? new Date(t.trial_ends_at) : null;
  if (fin && !Number.isNaN(fin.getTime())) {
    const restan = Math.ceil((fin.getTime() - Date.now()) / 86400000);
    return restan > 0
      ? { etiqueta: `⏳ ${restan}d prueba`, clases: 'text-warning-text bg-warning-bg border-warning-text/20' }
      : { etiqueta: 'Prueba vencida', clases: 'text-text-2 bg-page border-border' };
  }

  return { etiqueta: 'En prueba', clases: 'text-accent bg-navy-50 border-border' };
};

const moduleAudits = [
  {
    id: 'rrhh',
    name: 'Recursos Humanos',
    score: 9,
    description: 'Gestión de expedientes de colaboradores, contratos e información básica de empleados.',
    details: {
      coverage: '94% Cobertura de Tests (Feature/Unit)',
      performance: 'Consultas indexadas en PostgreSQL, sin N+1.',
      security: 'Aislamiento estricto de datos con TenantScope en Eloquent.',
      status: 'Estable'
    }
  },
  {
    id: 'reloj',
    name: 'Reloj Checador IA',
    score: 8,
    description: 'Registro de asistencia inteligente, control de comedor, Ley Silla y geofencing estricto.',
    details: {
      coverage: '90% Cobertura de Tests',
      performance: 'Modo offline optimizado para registro diferido y sincronización en red local.',
      security: 'Firmado HMAC de tokens de fichaje y geocercas inteligentes.',
      status: 'Estable'
    }
  },
  {
    id: 'operativo',
    name: 'Rutinas y Tareas',
    score: 7,
    description: 'Rutinas y listas de verificación operativas para supervisores y empleados.',
    details: {
      coverage: '82% Cobertura de Tests',
      performance: 'Validación por supervisor diferida sin bloqueos en base de datos.',
      security: 'Verificaciones de permisos basadas en roles jerárquicos.',
      status: 'Estable'
    }
  },
  {
    id: 'ats',
    name: 'Reclutamiento ATS',
    score: 8,
    description: 'Embudo de selección, entrevistas técnicas e inducción automatizada de candidatos.',
    details: {
      coverage: '90% Cobertura de Tests',
      performance: 'Carga eficiente de vacantes en portal público.',
      security: 'Aislamiento estricto de expedientes y datos sensibles de aplicantes.',
      status: 'Estable'
    }
  },
  {
    id: 'reportes',
    name: 'Reportes y Analítica',
    score: 6,
    description: 'Generación de métricas de horas trabajadas, retrasos y exportación a prenómina.',
    details: {
      coverage: '75% Cobertura de Tests',
      performance: 'Consultas pesadas de agregación temporal (se recomienda implementar caché Redis).',
      security: 'Acceso restringido únicamente a administradores del Tenant.',
      status: 'Mejorable'
    }
  },
  {
    id: 'portal',
    name: 'Portal Público (Vacantes)',
    score: 9,
    description: 'Sitio web corporativo público de cada empresa para reclutamiento.',
    details: {
      coverage: '95% Cobertura de Tests',
      performance: 'SSR optimizado para indexación rápida en motores de búsqueda (SEO).',
      security: 'Público pero sanitizado contra inyecciones e intentos de scraping masivos.',
      status: 'Excelente'
    }
  },
  {
    id: 'academia',
    name: 'Academia LMS',
    score: 7,
    description: 'Cursos de capacitación, inducción interactiva y evaluaciones de personal.',
    details: {
      coverage: '80% Cobertura de Tests',
      performance: 'Carga optimizada de recursos y videos mediante CDN.',
      security: 'Avance de curso verificado y firmado mediante llaves criptográficas de progreso.',
      status: 'Estable'
    }
  },
  {
    id: 'documentos',
    name: 'Gestor Documental',
    score: 8,
    description: 'Almacenamiento y firma digital de expedientes y manuales corporativos.',
    details: {
      coverage: '85% Cobertura de Tests',
      performance: 'Compresión local en el cliente antes de la carga de archivos.',
      security: 'Almacenamiento cifrado con AES-256 a nivel de bloque en storage.',
      status: 'Estable'
    }
  }
];

export const SaaSPlatformAdmin = () => {
  const {
    systemSettings,
    updateSetting,
    saasAlerts,
    saasPricing,
    updateSaaSPricing,
    resolveSaaSAlert,
    currentUser
  } = useAppStore();

  const [isPricingModalOpen, setIsPricingModalOpen] = useState(false);

  const isAdmin = currentUser?.system_role === 'platform_admin';
  const [activeTab, setActiveTab] = useState(isAdmin ? 'dashboard' : 'tickets');

  // Support Tickets State
  const [ticketsList, setTicketsList] = useState<any[]>([]);
  const [agentsList, setAgentsList] = useState<any[]>([]);
  const [isTicketsLoading, setIsTicketsLoading] = useState(false);
  const [ticketsSearchQuery, setTicketsSearchQuery] = useState('');
  const [ticketsStatusFilter, setTicketsStatusFilter] = useState('all');
  const [ticketsPriorityFilter, setTicketsPriorityFilter] = useState('all');
  const [ticketsTenantFilter, setTicketsTenantFilter] = useState('all');

  // Selected Ticket details
  const [selectedTicketId, setSelectedTicketId] = useState<number | null>(null);
  const [ticketDetailData, setTicketDetailData] = useState<any>(null);
  const [isTicketDetailOpen, setIsTicketDetailOpen] = useState(false);
  const [isTicketDetailLoading, setIsTicketDetailLoading] = useState(false);
  const [newNoteText, setNewNoteText] = useState('');
  const [isAddingNote, setIsAddingNote] = useState(false);
  const [isSuggestingIA, setIsSuggestingIA] = useState(false);

  // Security Logs State
  const [securityLogs, setSecurityLogs] = useState<any[]>([]);
  const [isLogsLoading, setIsLogsLoading] = useState(false);
  const [logsTenantFilter, setLogsTenantFilter] = useState('all');
  const [logsEventFilter, setLogsEventFilter] = useState('all');

  const fetchSecurityLogs = async () => {
    setIsLogsLoading(true);
    try {
      const res = await axiosInstance.get(`/platform/security-logs?tenant_id=${logsTenantFilter}&event_type=${logsEventFilter}`);
      setSecurityLogs(res.data);
    } catch (error) {
      console.error("Error fetching security logs:", error);
    } finally {
      setIsLogsLoading(false);
    }
  };

  // Pending Registrations (Registros Inconclusos) State
  const [pendingRegistrations, setPendingRegistrations] = useState<any[]>([]);
  const [isPendingLoading, setIsPendingLoading] = useState(false);

  // Social Grace & Seasonal Promotions State
  const [socialClaims, setSocialClaims] = useState<any[]>([]);
  const [promotionsList, setPromotionsList] = useState<any[]>([]);
  const [socialGraceDaysConfig, setSocialGraceDaysConfig] = useState(30);
  const [isSocialLoading, setIsSocialLoading] = useState(false);
  const [newPromoTitle, setNewPromoTitle] = useState('');
  const [newPromoSubtitle, setNewPromoSubtitle] = useState('');
  const [newPromoBadge, setNewPromoBadge] = useState('20% OFF');
  const [newPromoDiscount, setNewPromoDiscount] = useState(20);

  const fetchSocialPromotionsData = async () => {
    setIsSocialLoading(true);
    try {
      const [claimsRes, promosRes, configRes] = await Promise.all([
        axiosInstance.get('/platform/social-claims'),
        axiosInstance.get('/platform/promotions'),
        axiosInstance.get('/platform/social-grace-config')
      ]);
      setSocialClaims(claimsRes.data.claims || []);
      setPromotionsList(promosRes.data.promotions || []);
      setSocialGraceDaysConfig(configRes.data.social_grace_days || 30);
    } catch (err) {
      console.error("Error fetching social & promotions data:", err);
    } finally {
      setIsSocialLoading(false);
    }
  };

  const fetchPendingRegistrations = async () => {
    setIsPendingLoading(true);
    try {
      const res = await axiosInstance.get('/platform/pending-registrations');
      setPendingRegistrations(res.data);
    } catch (error) {
      console.error("Error fetching pending registrations:", error);
    } finally {
      setIsPendingLoading(false);
    }
  };

  const handleDeletePendingRegistration = async (id: number, email: string) => {
    if (!window.confirm(`¿Estás seguro de que deseas eliminar permanentemente el registro inconcluso de "${email}"? Esta acción liberará la dirección de correo para futuros registros.`)) return;

    try {
      const res = await axiosInstance.delete(`/platform/pending-registrations/${id}`);
      alert(res.data.message || "Registro inconcluso eliminado con éxito.");
      fetchPendingRegistrations();
    } catch (error: any) {
      console.error("Error deleting pending registration:", error);
      alert(error.response?.data?.error || "Error al eliminar el registro.");
    }
  };

  // New ticket modal
  const [isNewTicketModalOpen, setIsNewTicketModalOpen] = useState(false);
  const [newTicketTitle, setNewTicketTitle] = useState('');
  const [newTicketDesc, setNewTicketDesc] = useState('');
  const [newTicketPriority, setNewTicketPriority] = useState('medium');
  const [newTicketTenantId, setNewTicketTenantId] = useState<string>('');
  const [newTicketContactName, setNewTicketContactName] = useState('');
  const [newTicketContactEmail, setNewTicketContactEmail] = useState('');
  const [newTicketAssignedTo, setNewTicketAssignedTo] = useState<string>('');
  const [isCreatingTicket, setIsCreatingTicket] = useState(false);

  const fetchTickets = async () => {
    setIsTicketsLoading(true);
    try {
      const res = await axiosInstance.get(`/platform/tickets?search=${ticketsSearchQuery}&status=${ticketsStatusFilter}&priority=${ticketsPriorityFilter}&tenant_id=${ticketsTenantFilter}`);
      setTicketsList(res.data);
    } catch (error) {
      console.error("Error fetching tickets:", error);
    } finally {
      setIsTicketsLoading(false);
    }
  };

  const fetchAgents = async () => {
    try {
      const res = await axiosInstance.get('/platform/tickets/agents');
      setAgentsList(res.data);
    } catch (error) {
      console.error("Error fetching agents:", error);
    }
  };

  const handleOpenTicketDetails = async (id: number) => {
    setSelectedTicketId(id);
    setIsTicketDetailOpen(true);
    setIsTicketDetailLoading(true);
    setNewNoteText('');
    try {
      const res = await axiosInstance.get(`/platform/tickets/${id}`);
      setTicketDetailData(res.data);
    } catch (error) {
      console.error("Error fetching ticket details:", error);
      alert("Error al cargar los detalles del ticket.");
      setIsTicketDetailOpen(false);
    } finally {
      setIsTicketDetailLoading(false);
    }
  };

  const handleUpdateTicketStatus = async (status: string) => {
    if (!selectedTicketId) return;
    try {
      await axiosInstance.put(`/platform/tickets/${selectedTicketId}`, { status });
      const res = await axiosInstance.get(`/platform/tickets/${selectedTicketId}`);
      setTicketDetailData(res.data);
      fetchTickets();
    } catch (error) {
      console.error("Error updating ticket status:", error);
      alert("Error al actualizar el estado.");
    }
  };

  const handleUpdateTicketPriority = async (priority: string) => {
    if (!selectedTicketId) return;
    try {
      await axiosInstance.put(`/platform/tickets/${selectedTicketId}`, { priority });
      const res = await axiosInstance.get(`/platform/tickets/${selectedTicketId}`);
      setTicketDetailData(res.data);
      fetchTickets();
    } catch (error) {
      console.error("Error updating ticket priority:", error);
      alert("Error al actualizar la prioridad.");
    }
  };

  const handleUpdateTicketAssignment = async (agentId: string) => {
    if (!selectedTicketId) return;
    try {
      await axiosInstance.put(`/platform/tickets/${selectedTicketId}`, { assigned_to: agentId ? Number(agentId) : null });
      const res = await axiosInstance.get(`/platform/tickets/${selectedTicketId}`);
      setTicketDetailData(res.data);
      fetchTickets();
    } catch (error) {
      console.error("Error updating ticket assignment:", error);
      alert("Error al asignar el ticket.");
    }
  };

  const handleAddNote = async () => {
    if (!selectedTicketId || !newNoteText.trim()) return;
    setIsAddingNote(true);
    try {
      await axiosInstance.post(`/platform/tickets/${selectedTicketId}/notes`, { note: newNoteText });
      setNewNoteText('');
      const res = await axiosInstance.get(`/platform/tickets/${selectedTicketId}`);
      setTicketDetailData(res.data);
    } catch (error) {
      console.error("Error adding internal note:", error);
      alert("Error al agregar nota interna.");
    } finally {
      setIsAddingNote(false);
    }
  };

  const handleSuggestResponseWithIA = async () => {
    if (!selectedTicketId || !ticketDetailData) return;
    setIsSuggestingIA(true);
    try {
      const response = await axiosInstance.post('/support/copilot', {
        question: 'Genera una sugerencia de respuesta técnica u operativa formal para resolver el siguiente caso de soporte de un cliente.',
        context: `Título: ${ticketDetailData.title}. Descripción: ${ticketDetailData.description}.`
      });
      if (response.data && response.data.answer) {
        setNewNoteText(response.data.answer);
      }
    } catch (error) {
      console.error("Error suggesting IA response:", error);
      alert("No se pudo obtener sugerencia de la IA.");
    } finally {
      setIsSuggestingIA(false);
    }
  };

  const handleCreateTicket = async () => {
    if (!newTicketTitle.trim() || !newTicketDesc.trim()) {
      alert("El título y la descripción son requeridos.");
      return;
    }
    setIsCreatingTicket(true);
    try {
      await axiosInstance.post('/platform/tickets', {
        title: newTicketTitle,
        description: newTicketDesc,
        priority: newTicketPriority,
        status: 'open',
        tenant_id: newTicketTenantId ? Number(newTicketTenantId) : null,
        assigned_to: newTicketAssignedTo ? Number(newTicketAssignedTo) : null,
        contact_name: newTicketContactName || null,
        contact_email: newTicketContactEmail || null
      });
      setIsNewTicketModalOpen(false);
      setNewTicketTitle('');
      setNewTicketDesc('');
      setNewTicketPriority('medium');
      setNewTicketTenantId('');
      setNewTicketContactName('');
      setNewTicketContactEmail('');
      setNewTicketAssignedTo('');
      fetchTickets();
    } catch (error) {
      console.error("Error creating ticket:", error);
      alert("Error al crear el ticket.");
    } finally {
      setIsCreatingTicket(false);
    }
  };

  const handleDeleteTicket = async (id: number) => {
    if (!window.confirm("¿Estás seguro de que deseas eliminar este ticket permanentemente?")) return;
    try {
      await axiosInstance.delete(`/platform/tickets/${id}`);
      fetchTickets();
      if (selectedTicketId === id) {
        setIsTicketDetailOpen(false);
      }
    } catch (error) {
      console.error("Error deleting ticket:", error);
      alert("Error al eliminar el ticket.");
    }
  };

  useEffect(() => {
    if (activeTab === 'tickets') {
      fetchTickets();
      fetchAgents();
    }
  }, [activeTab, ticketsSearchQuery, ticketsStatusFilter, ticketsPriorityFilter, ticketsTenantFilter]);

  useEffect(() => {
    if (activeTab === 'security_logs') {
      fetchSecurityLogs();
    }
    if (activeTab === 'pending_registrations' || activeTab === 'dashboard') {
      fetchPendingRegistrations();
    }
    if (activeTab === 'social_promotions' || activeTab === 'dashboard') {
      fetchSocialPromotionsData();
    }
  }, [activeTab, logsTenantFilter, logsEventFilter]);

  useEffect(() => {
    if (currentUser && currentUser.system_role !== 'Loading') {
      const isPlatformAdmin = currentUser.system_role === 'platform_admin';
      setActiveTab(isPlatformAdmin ? 'dashboard' : 'tickets');
    }
  }, [currentUser]);
  const [isNewTenantModalOpen, setIsNewTenantModalOpen] = useState(false);
  const [newTenantName, setNewTenantName] = useState('');
  const [newTenantPlan, setNewTenantPlan] = useState('Freemium');
  const [isProfileMenuOpen, setIsProfileMenuOpen] = useState(false);
  const [isNavMenuOpen, setIsNavMenuOpen] = useState(false);
  const [showBanner, setShowBanner] = useState<boolean>(() => {
    return localStorage.getItem('talent360_hide_global_banner') !== 'true';
  });
  const [isBannerCloseMenuOpen, setIsBannerCloseMenuOpen] = useState(false);
  const [createdTenantData, setCreatedTenantData] = useState<any>(null);

  // Estados para la configuración del plan gratuito (Freemium)
  const [isFreemiumConfigOpen, setIsFreemiumConfigOpen] = useState(false);
  const [freemiumModules, setFreemiumModules] = useState<string[]>([]);
  const [freemiumFeatures, setFreemiumFeatures] = useState<string[]>([]);
  const [globalTrialDays, setGlobalTrialDays] = useState(30);
  const [isSavingFreemium, setIsSavingFreemium] = useState(false);

  // Estados para la configuración bancaria de la plataforma
  const [isBankConfigOpen, setIsBankConfigOpen] = useState(false);
  const [bankConfigData, setBankConfigData] = useState({
    bank_name: '',
    account_holder: '',
    clabe: '',
    card_number: '',
    instructions: '',
    is_active: false
  });
  const [isSavingBank, setIsSavingBank] = useState(false);

  // Estados para la configuración del simulador de la landing page
  const [isSimulatorConfigOpen, setIsSimulatorConfigOpen] = useState(false);
  const [simulatorConfig, setSimulatorConfig] = useState({
    scale: 90,
    emp_name: 'Francisco Vega',
    store_name: 'Decorarte 365'
  });
  const [isSavingSimulator, setIsSavingSimulator] = useState(false);

  const handleOpenSimulatorConfig = async () => {
    setIsSimulatorConfigOpen(true);
    setIsLoading(true);
    try {
      const res = await axiosInstance.get('/platform/landing-simulator-settings');
      if (res.data) {
        setSimulatorConfig({
          scale: res.data.scale || 90,
          emp_name: res.data.emp_name || 'Francisco Vega',
          store_name: res.data.store_name || 'Decorarte 365'
        });
      }
    } catch (error) {
      console.error("Error loading simulator config:", error);
    } finally {
      setIsLoading(false);
    }
  };

  const handleSaveSimulatorConfig = async () => {
    setIsSavingSimulator(true);
    try {
      await axiosInstance.post('/platform/landing-simulator-settings', simulatorConfig);
      alert("Configuración del simulador guardada y actualizada con éxito.");
      setIsSimulatorConfigOpen(false);
    } catch (error: any) {
      console.error("Error saving simulator config:", error);
      alert(error.response?.data?.error || "Error al guardar la configuración del simulador.");
    } finally {
      setIsSavingSimulator(false);
    }
  };

  // Estados Real desde la BD
  const [stats, setStats] = useState({
    mrr: 0,
    active_tenants: 0,
    total_users: 0,
    churn_rate: '0%'
  });
  const [tenantsList, setTenantsList] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  // Filtros y Búsqueda
  const [searchQuery, setSearchQuery] = useState('');
  const [planFilter, setPlanFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');

  // Estados para detalles y acciones
  const [selectedTenantId, setSelectedTenantId] = useState<number | null>(null);
  const [tenantDetail, setTenantDetail] = useState<any>(null);
  const [isDetailOpen, setIsDetailOpen] = useState(false);
  const [isDetailLoading, setIsDetailLoading] = useState(false);
  const [tenantAllowedModules, setTenantAllowedModules] = useState<string[]>([]);
  const [tenantAllowedFeatures, setTenantAllowedFeatures] = useState<string[]>([]);
  const [isSavingTenantFeatures, setIsSavingTenantFeatures] = useState(false);

  // Suspensión Modal
  const [isSuspensionModalOpen, setIsSuspensionModalOpen] = useState(false);
  const [suspensionTenantId, setSuspensionTenantId] = useState<number | null>(null);
  const [suspensionTenantName, setSuspensionTenantName] = useState('');
  const [suspensionReason, setSuspensionReason] = useState('Falta de pago');
  const [customSuspensionReason, setCustomSuspensionReason] = useState('');

  // Password Reset
  const [newPassword, setNewPassword] = useState('');
  const [isResetFormVisible, setIsResetFormVisible] = useState(false);
  // Estados para Edición de Empresa
  const [isEditing, setIsEditing] = useState(false);
  const [editTenantName, setEditTenantName] = useState('');
  const [editTenantPlan, setEditTenantPlan] = useState('freemium');
  const [editMaxUsers, setEditMaxUsers] = useState(10);
  const [editAdminName, setEditAdminName] = useState('');
  const [editAdminEmail, setEditAdminEmail] = useState('');
  const [editAdminPassword, setEditAdminPassword] = useState('');
  const [editAdminPhone, setEditAdminPhone] = useState('');
  const [isSavingEdit, setIsSavingEdit] = useState(false);

  const [isResetting, setIsResetting] = useState(false);
  const [selectedAuditModule, setSelectedAuditModule] = useState<any>(null);
  const [moduleAuditsList, setModuleAuditsList] = useState<any[]>(moduleAudits);

  const fetchModuleAudits = async () => {
    try {
      const res = await axiosInstance.get('/platform/audits');
      setModuleAuditsList(res.data);
      setSelectedAuditModule((prev: any) => {
        if (!prev) return null;
        const updated = res.data.find((m: any) => m.id === prev.id);
        return updated || prev;
      });
    } catch (error) {
      console.error("Error fetching module audits:", error);
    }
  };

  // Helpers to format and clean phone numbers (prefixed with Mexican country code 52)
  const formatPhoneVisual = (val: string) => {
    if (!val) return '';
    let clean = val.replace(/\D/g, '');
    if (clean.startsWith('52')) {
      clean = clean.slice(2);
    }
    clean = clean.slice(0, 10);
    if (clean.length <= 3) return clean;
    if (clean.length <= 6) return `${clean.slice(0, 3)} ${clean.slice(3)}`;
    return `${clean.slice(0, 3)} ${clean.slice(3, 6)} ${clean.slice(6)}`;
  };

  const getNichoBadge = (nicho?: string) => {
    const n = (nicho || '').toLowerCase();
    if (n.includes('retail') || n.includes('tienda') || n.includes('comercio') || n.includes('decoracion') || n.includes('boutique') || n.includes('minimarket') || n.includes('ferreteria')) {
      return <span className="inline-flex items-center gap-1 text-[10px] font-bold text-success-text bg-success-bg border border-success-text/20 px-2 py-0.5 rounded-md shrink-0">🛍️ Tienda / Retail</span>;
    }
    if (n.includes('restaurante') || n.includes('comedor') || n.includes('cafeteria') || n.includes('comida') || n.includes('bar') || n.includes('taqueria')) {
      return <span className="inline-flex items-center gap-1 text-[10px] font-bold text-warning-text bg-warning-bg border border-warning-text/20 px-2 py-0.5 rounded-md shrink-0">🍽️ Restaurante</span>;
    }
    if (n.includes('oficina') || n.includes('servicios') || n.includes('despacho') || n.includes('agencia') || n.includes('consultoria') || n.includes('inmobiliaria')) {
      return <span className="inline-flex items-center gap-1 text-[10px] font-bold text-accent bg-navy-50 border border-border px-2 py-0.5 rounded-md shrink-0">🏢 Servicios</span>;
    }
    if (n.includes('taller') || n.includes('mecanico') || n.includes('manufactura') || n.includes('industrial') || n.includes('tecnico')) {
      return <span className="inline-flex items-center gap-1 text-[10px] font-bold text-accent bg-navy-50 border border-border px-2 py-0.5 rounded-md shrink-0">🔧 Taller / Industria</span>;
    }
    if (n.includes('salud') || n.includes('farmacia') || n.includes('clinica') || n.includes('hospital')) {
      return <span className="inline-flex items-center gap-1 text-[10px] font-bold text-danger-text bg-danger-bg border border-danger-text/20 px-2 py-0.5 rounded-md shrink-0">🩺 Salud / Clínica</span>;
    }
    if (n.includes('educacion') || n.includes('escuela') || n.includes('academia') || n.includes('curso')) {
      return <span className="inline-flex items-center gap-1 text-[10px] font-bold text-accent bg-navy-50 border border-border px-2 py-0.5 rounded-md shrink-0">🎓 Educación</span>;
    }
    return <span className="inline-flex items-center gap-1 text-[10px] font-bold text-text-2 bg-page border border-border px-2 py-0.5 rounded-md shrink-0">🏬 General</span>;
  };

  const getCleanDbPhone = (val: string) => {
    const clean = val.replace(/\D/g, '');
    if (!clean) return '';
    if (clean.length === 10) return `52${clean}`;
    if (clean.startsWith('52') && clean.length > 10) return clean;
    return clean;
  };

  const handleLogout = () => {
    localStorage.removeItem('talent_auth_token');
    window.location.href = '/login';
  };

  const fetchGlobalData = async (search = '', plan = 'all', status = 'all') => {
    setIsLoading(true);
    try {
      const searchParam = search ? `search=${encodeURIComponent(search)}&` : '';
      const planParam = (plan && plan !== 'all') ? `plan=${encodeURIComponent(plan)}&` : '';
      const statusParam = (status && status !== 'all') ? `status=${encodeURIComponent(status)}&` : '';
      const queryString = `${searchParam}${planParam}${statusParam}`.replace(/&$/, '');
      const url = `/platform/tenants${queryString ? `?${queryString}` : ''}`;

      const [statsRes, tenantsRes, auditsRes] = await Promise.allSettled([
        axiosInstance.get('/platform/stats'),
        axiosInstance.get(url),
        axiosInstance.get('/platform/audits')
      ]);

      if (statsRes.status === 'fulfilled' && statsRes.value.data) {
        setStats(statsRes.value.data);
      }
      if (tenantsRes.status === 'fulfilled') {
        const rawData = tenantsRes.value.data;
        const list = Array.isArray(rawData) ? rawData : (rawData?.tenants || rawData?.data || []);
        setTenantsList(list);
      }
      if (auditsRes.status === 'fulfilled' && auditsRes.value.data) {
        setModuleAuditsList(auditsRes.value.data);
      }
    } catch (error) {
      console.error("Error fetching platform data:", error);
    } finally {
      setIsLoading(false);
    }
  };

  // Polling para Auditoría de Calidad (cada 10 segundos para actualización en tiempo real)
  useEffect(() => {
    const interval = setInterval(() => {
      fetchModuleAudits();
    }, 10000);
    return () => clearInterval(interval);
  }, []);

  // Debounce para búsqueda
  useEffect(() => {
    const delayDebounceFn = setTimeout(() => {
      fetchGlobalData(searchQuery, planFilter, statusFilter);
    }, 300);

    return () => clearTimeout(delayDebounceFn);
  }, [searchQuery, planFilter, statusFilter]);

  const timeMode = systemSettings?.time_mode || 'simulated';

  const kpis = [
    { label: 'MRR', value: `$${stats.mrr.toLocaleString()}`, icon: DollarSign, color: 'text-success-text', watermarkColor: 'text-success-text/25', trend: '+15% este mes' },
    { label: 'Empresas', value: stats.active_tenants.toString(), icon: Building2, color: 'text-accent', watermarkColor: 'text-accent/25', trend: `+0 en Trial` },
    { label: 'Usuarios', value: stats.total_users.toLocaleString(), icon: Users, color: 'text-accent', watermarkColor: 'text-accent/25', trend: 'Crecimiento estable' },
    { label: 'Churn', value: stats.churn_rate, icon: TrendingUp, color: 'text-danger-text', watermarkColor: 'text-danger-text/25', trend: 'Ligeramente alto' },
  ];

  const handleCreateTenant = async () => {
    if (!newTenantName.trim()) return;
    setIsLoading(true);
    // La contraseña del admin de la empresa era la cadena fija `password123`: sabiendo el correo
    // del admin se entraba a la empresa. Se genera una distinta por empresa y se muestra abajo
    // (es la única vez que se puede leer).
    const passwordGenerada = Array.from(crypto.getRandomValues(new Uint8Array(12)))
      .map(b => 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'[b % 57]).join('');
    try {
      const response = await axiosInstance.post('/tenants', {
        subdomain: newTenantName.toLowerCase().replace(/[^a-z0-9]/g, '') + Math.floor(Math.random() * 1000),
        plan: newTenantPlan.toLowerCase(),
        company_name: newTenantName,
        admin_name: 'Admin ' + newTenantName,
        // H3: el DOMINIO también salía con acentos si la empresa los llevaba en el nombre
        // ("Panadería" → @panadería.com), produciendo un correo inservible para SMTP.
        admin_email: `admin_${Math.floor(Math.random() * 10000)}@${slugParaCorreo(newTenantName)}.com`,
        admin_password: passwordGenerada
      });

      await fetchGlobalData(searchQuery, planFilter, statusFilter);

      setCreatedTenantData({
        tenant: response.data.tenant,
        user: response.data.user,
        password: passwordGenerada
      });
    } catch (error) {
      console.error("Error creating tenant:", error);
      alert("Hubo un error al crear la empresa de prueba.");
    } finally {
      setIsLoading(false);
    }
  };

  const handleDeleteTenant = async (id: number, name: string) => {
    const confirmDelete = window.confirm(`¿Estás seguro de que deseas eliminar la empresa "${name}"? Esta acción borrará permanentemente todos sus usuarios, vacantes, candidatos y datos asociados de forma irreversible.`);
    if (!confirmDelete) return;

    setIsLoading(true);
    try {
      await axiosInstance.delete(`/platform/tenants/${id}`);
      await fetchGlobalData(searchQuery, planFilter, statusFilter);
      alert(`La empresa "${name}" ha sido eliminada con éxito.`);
      if (selectedTenantId === id) {
        setIsDetailOpen(false);
      }
    } catch (error: any) {
      console.error("Error deleting tenant:", error);
      alert(error.response?.data?.error || "Hubo un error al eliminar la empresa.");
    } finally {
      setIsLoading(false);
    }
  };

  // Cargar detalles de un inquilino
  const handleOpenDetails = async (id: number) => {
    setSelectedTenantId(id);
    setIsDetailOpen(true);
    setIsDetailLoading(true);
    setIsResetFormVisible(false);
    setNewPassword('');
    setIsEditing(false);
    setEditAdminPassword('');

    try {
      const res = await axiosInstance.get(`/platform/tenants/${id}`);
      const data = res.data;
      setTenantDetail(data);

      // Cargar estados para la edición
      setEditTenantName(data.tenant?.name || '');
      setEditTenantPlan(data.tenant?.plan?.toLowerCase() || 'freemium');
      setEditMaxUsers(data.tenant?.max_users || 10);
      setEditAdminName(data.admin?.name || '');
      setEditAdminEmail(data.admin?.email || '');
      setEditAdminPhone(data.admin?.phone || '');

      // Cargar módulos y características permitidas de este tenant
      setTenantAllowedModules(Array.isArray(data.tenant?.allowed_modules) ? data.tenant.allowed_modules : []);
      setTenantAllowedFeatures(Array.isArray(data.tenant?.allowed_features) ? data.tenant.allowed_features : []);
    } catch (error) {
      console.error("Error loading tenant details:", error);
      alert("Error al cargar los detalles de la empresa.");
      setIsDetailOpen(false);
    } finally {
      setIsDetailLoading(false);
    }
  };

  const handleSaveTenantFeatures = async () => {
    if (!selectedTenantId) return;
    setIsSavingTenantFeatures(true);
    try {
      await axiosInstance.post(`/platform/tenants/${selectedTenantId}/features`, {
        modules: tenantAllowedModules,
        features: tenantAllowedFeatures
      });
      alert("Módulos y funciones personalizadas guardadas con éxito para esta empresa.");
      await handleOpenDetails(selectedTenantId);
    } catch (error: any) {
      console.error("Error saving tenant features:", error);
      alert(error.response?.data?.error || "Error al guardar los permisos de la empresa.");
    } finally {
      setIsSavingTenantFeatures(false);
    }
  };

  const toggleTenantModule = (modId: string) => {
    setTenantAllowedModules(prev =>
      prev.includes(modId) ? prev.filter(id => id !== modId) : [...prev, modId]
    );
  };

  const toggleTenantFeature = (featKey: string) => {
    setTenantAllowedFeatures(prev =>
      prev.includes(featKey) ? prev.filter(key => key !== featKey) : [...prev, featKey]
    );
  };

  // Guardar Cambios de Edición del Inquilino
  const handleSaveTenantEdit = async () => {
    if (!selectedTenantId) return;
    if (!editTenantName.trim() || !editAdminName.trim() || !editAdminEmail.trim()) {
      alert("Por favor completa todos los campos requeridos.");
      return;
    }

    setIsSavingEdit(true);
    try {
      await axiosInstance.put(`/platform/tenants/${selectedTenantId}/update-profile`, {
        name: editTenantName,
        plan: editTenantPlan,
        max_users: editMaxUsers,
        admin_name: editAdminName,
        admin_email: editAdminEmail,
        admin_password: editAdminPassword || null,
        admin_phone: editAdminPhone || null
      });

      alert("Datos de la empresa y del administrador actualizados con éxito.");
      setIsEditing(false);
      setEditAdminPassword('');

      // Recargar lista global y volver a abrir los detalles actualizados
      await fetchGlobalData(searchQuery, planFilter, statusFilter);
      await handleOpenDetails(selectedTenantId);
    } catch (error: any) {
      console.error("Error updating tenant details:", error);
      alert(error.response?.data?.error || "Error al actualizar los datos de la empresa.");
    } finally {
      setIsSavingEdit(false);
    }
  };

  // Generar contraseña aleatoria
  const generateTemporaryPassword = () => {
    const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
    let password = "";
    for (let i = 0; i < 10; i++) {
      password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    setEditAdminPassword(password);
  };

  // Activar o Suspender Inquilino
  const handleToggleStatus = (id: number, name: string, isActive: boolean) => {
    if (isActive) {
      // Si está activo, proceder a suspender (abrir modal de razón)
      setSuspensionTenantId(id);
      setSuspensionTenantName(name);
      setSuspensionReason('Falta de pago');
      setCustomSuspensionReason('');
      setIsSuspensionModalOpen(true);
    } else {
      // Si está inactivo, activar inmediatamente
      const confirmActivate = window.confirm(`¿Deseas activar la empresa "${name}" de nuevo?`);
      if (!confirmActivate) return;

      triggerToggleStatus(id, true, null);
    }
  };

  const triggerToggleStatus = async (id: number, targetActive: boolean, reason: string | null) => {
    setIsLoading(true);
    try {
      await axiosInstance.post(`/platform/tenants/${id}/toggle-status`, {
        is_active: targetActive,
        suspension_reason: reason
      });

      alert(targetActive ? "Empresa activada exitosamente." : "Empresa suspendida exitosamente.");
      setIsSuspensionModalOpen(false);

      // Recargar lista y detalles si están abiertos
      await fetchGlobalData(searchQuery, planFilter, statusFilter);
      if (selectedTenantId === id) {
        handleOpenDetails(id);
      }
    } catch (error: any) {
      console.error("Error changing status:", error);
      alert(error.response?.data?.error || "Error al cambiar el estado de la empresa.");
    } finally {
      setIsLoading(false);
    }
  };

  const handleConfirmSuspension = () => {
    if (!suspensionTenantId) return;
    const finalReason = suspensionReason === 'Otro' ? customSuspensionReason : suspensionReason;
    triggerToggleStatus(suspensionTenantId, false, finalReason);
  };

  // Restablecer contraseña
  const handleResetPassword = async () => {
    if (!selectedTenantId || !newPassword.trim()) return;
    if (newPassword.length < 6) {
      alert("La contraseña debe tener al menos 6 caracteres.");
      return;
    }

    setIsResetting(true);
    try {
      await axiosInstance.post(`/platform/tenants/${selectedTenantId}/reset-password`, {
        password: newPassword
      });
      alert("Contraseña actualizada con éxito.");
      setNewPassword('');
      setIsResetFormVisible(false);
    } catch (error: any) {
      console.error("Error resetting password:", error);
      alert(error.response?.data?.error || "Error al restablecer la contraseña.");
    } finally {
      setIsResetting(false);
    }
  };

  // Impersonación de Inquilino
  const handleImpersonate = async (id: number) => {
    const confirmImpersonation = window.confirm("¿Deseas iniciar sesión temporalmente como el administrador de esta empresa? Podrás volver a tu cuenta de Super Admin en cualquier momento.");
    if (!confirmImpersonation) return;

    setIsLoading(true);
    try {
      const res = await axiosInstance.post(`/platform/tenants/${id}/impersonate`);
      const { token } = res.data;

      // Guardar token original de Super Admin
      const currentToken = localStorage.getItem('talent_auth_token');
      if (currentToken) {
        localStorage.setItem('platform_admin_token', currentToken);
      }

      // Establecer token impersonado
      localStorage.setItem('talent_auth_token', token);

      // Redirigir al dashboard cliente
      window.location.href = '/app';
    } catch (error: any) {
      console.error("Error starting impersonation:", error);
      alert(error.response?.data?.error || "Error al iniciar sesión como administrador de la empresa.");
      setIsLoading(false);
    }
  };

  const handleOpenFreemiumConfig = async () => {
    setIsFreemiumConfigOpen(true);
    setIsLoading(true);
    try {
      const res = await axiosInstance.get('/platform/freemium-config');
      if (res.data) {
        setFreemiumModules(res.data.modules || ['reloj', 'rrhh', 'operativo']);
        setFreemiumFeatures(res.data.features || []);
        setGlobalTrialDays(res.data.global_trial_days !== undefined ? res.data.global_trial_days : 30);
      }
    } catch (error) {
      console.error("Error loading freemium config:", error);
      alert("Error al cargar la configuración freemium.");
    } finally {
      setIsLoading(false);
    }
  };

  const handleSaveFreemiumConfig = async () => {
    setIsSavingFreemium(true);
    try {
      await axiosInstance.post('/platform/freemium-config', {
        modules: freemiumModules,
        features: freemiumFeatures,
        global_trial_days: globalTrialDays
      });

      updateSetting('freemium_allowed_features', freemiumFeatures);
      updateSetting('freemium_allowed_modules', freemiumModules);
      updateSetting('global_trial_days', globalTrialDays);

      alert("Configuración de plan gratuito y días de prueba guardada con éxito.");
      setIsFreemiumConfigOpen(false);
    } catch (error) {
      console.error("Error saving freemium config:", error);
      alert("Error al guardar la configuración.");
    } finally {
      setIsSavingFreemium(false);
    }
  };

  const handleOpenBankConfig = async () => {
    setIsBankConfigOpen(true);
    setIsLoading(true);
    try {
      const res = await axiosInstance.get('/platform/bank-config');
      if (res.data) {
        setBankConfigData({
          bank_name: res.data.bank_name || '',
          account_holder: res.data.account_holder || '',
          clabe: res.data.clabe || '',
          card_number: res.data.card_number || '',
          instructions: res.data.instructions || '',
          is_active: res.data.is_active || false
        });
      }
    } catch (error) {
      console.error("Error loading bank config:", error);
      alert("Error al cargar la configuración bancaria.");
    } finally {
      setIsLoading(false);
    }
  };

  const handleSaveBankConfig = async () => {
    if (bankConfigData.clabe && bankConfigData.clabe.length !== 18) {
      alert("La CLABE debe tener exactamente 18 dígitos.");
      return;
    }
    if (bankConfigData.card_number && bankConfigData.card_number.length !== 16) {
      alert("El número de tarjeta debe tener exactamente 16 dígitos.");
      return;
    }

    setIsSavingBank(true);
    try {
      await axiosInstance.post('/platform/bank-config', bankConfigData);
      alert("Configuración bancaria guardada con éxito.");
      setIsBankConfigOpen(false);
    } catch (error) {
      console.error("Error saving bank config:", error);
      alert("Error al guardar la configuración bancaria.");
    } finally {
      setIsSavingBank(false);
    }
  };

  const toggleFreemiumModule = (modId: string) => {
    setFreemiumModules(prev =>
      prev.includes(modId) ? prev.filter(id => id !== modId) : [...prev, modId]
    );
  };

  const toggleFreemiumFeature = (featId: string) => {
    setFreemiumFeatures(prev =>
      prev.includes(featId) ? prev.filter(id => id !== featId) : [...prev, featId]
    );
  };

  return (
    <div className="max-w-7xl mx-auto space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">

      {/* Sticky Top Bar con Menú Hamburguesa y Perfil de Usuario */}
      <div className="sticky top-0 z-40 bg-white/95 backdrop-blur-md border border-border/90 rounded-2xl p-3 sm:p-4 shadow-sm flex items-center justify-between gap-4 mb-6">
        {/* Izquierda: Branding e Identificación de la Consola */}
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 bg-accent rounded-xl flex items-center justify-center shadow-sm shrink-0">
            <span className="text-white font-black text-lg">T</span>
          </div>
          <div>
            <h2 className="text-sm sm:text-base font-black text-text-1 leading-tight">
              {isAdmin ? 'Talent 360' : 'Página de Soporte'}
            </h2>
            <p className="text-[11px] text-text-3 font-bold leading-tight">
              {isAdmin ? 'Consola de administración' : 'Soporte técnico y atención a empresas'}
            </p>
          </div>
        </div>

        {/* Derecha: Menú Hamburguesa & Perfil del Usuario Estático */}
        <div className="flex items-center gap-3">
          {/* Menú de Hamburguesa para Navegación Global */}
          <div className="relative">
            <button
              type="button"
              onClick={() => setIsNavMenuOpen(!isNavMenuOpen)}
              className="p-2 bg-page hover:bg-slate-200 text-text-2 rounded-xl border border-border transition-all flex items-center gap-1.5 text-xs font-bold cursor-pointer"
              title="Menú de Navegación Global"
            >
              <Menu size={18} />
              <span className="hidden sm:inline font-black text-text-1">Menú</span>
            </button>

            {isNavMenuOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setIsNavMenuOpen(false)}></div>
                <div className="absolute right-0 mt-2 w-64 bg-white border border-border rounded-2xl shadow-xl p-2.5 z-20 animate-in fade-in slide-in-from-top-2 duration-150 space-y-1">
                  <div className="px-3 py-1.5 border-b border-border mb-1">
                    <p className="text-[10px] font-black text-slate-400 uppercase tracking-wider">Secciones de Plataforma</p>
                  </div>

                  {isAdmin && (
                    <button
                      type="button"
                      onClick={() => { setActiveTab('dashboard'); setIsNavMenuOpen(false); }}
                      className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-black transition-all ${
                        activeTab === 'dashboard' ? 'bg-navy-50 text-accent' : 'text-text-2 hover:bg-page'
                      }`}
                    >
                      <span className="flex items-center gap-2">📊 Dashboard Global</span>
                    </button>
                  )}

                  {isAdmin && (
                    <button
                      type="button"
                      onClick={() => { setActiveTab('pending_registrations'); setIsNavMenuOpen(false); }}
                      className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-black transition-all ${
                        activeTab === 'pending_registrations' ? 'bg-navy-50 text-accent' : 'text-text-2 hover:bg-page'
                      }`}
                    >
                      <span className="flex items-center gap-2">⏳ Registros Inconclusos</span>
                      {pendingRegistrations.length > 0 && (
                        <span className="bg-warning-icon text-white text-[10px] font-black px-2 py-0.5 rounded-full animate-pulse">
                          {pendingRegistrations.length}
                        </span>
                      )}
                    </button>
                  )}

                  <button
                    type="button"
                    onClick={() => { setActiveTab('tickets'); setIsNavMenuOpen(false); }}
                    className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-black transition-all ${
                      activeTab === 'tickets' ? 'bg-navy-50 text-accent' : 'text-text-2 hover:bg-page'
                    }`}
                  >
                    <span className="flex items-center gap-2">🎧 Soporte Técnico / Tickets</span>
                  </button>

                  {isAdmin && (
                    <button
                      type="button"
                      onClick={() => { setActiveTab('security_logs'); setIsNavMenuOpen(false); }}
                      className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-black transition-all ${
                        activeTab === 'security_logs' ? 'bg-navy-50 text-accent' : 'text-text-2 hover:bg-page'
                      }`}
                    >
                      <span className="flex items-center gap-2">🛡️ Bitácora de Seguridad</span>
                    </button>
                  )}

                  {isAdmin && (
                    <button
                      type="button"
                      onClick={() => { setActiveTab('social_promotions'); setIsNavMenuOpen(false); }}
                      className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-black transition-all ${
                        activeTab === 'social_promotions' ? 'bg-navy-50 text-accent' : 'text-text-2 hover:bg-page'
                      }`}
                    >
                      <span className="flex items-center gap-2">📱 Redes Sociales & Promociones</span>
                      {socialClaims.filter(c => c.status === 'pending_approval').length > 0 && (
                        <span className="bg-accent text-white text-[10px] font-black px-2 py-0.5 rounded-full animate-pulse">
                          {socialClaims.filter(c => c.status === 'pending_approval').length}
                        </span>
                      )}
                    </button>
                  )}

                  {isAdmin && (
                    <button
                      type="button"
                      onClick={() => { setActiveTab('billing'); setIsNavMenuOpen(false); }}
                      className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-black transition-all ${
                        activeTab === 'billing' ? 'bg-navy-50 text-accent' : 'text-text-2 hover:bg-page'
                      }`}
                    >
                      <span className="flex items-center gap-2">💳 Facturación Global</span>
                    </button>
                  )}
                </div>
              </>
            )}
          </div>

          {/* Perfil del Usuario: Icono/Avatar arriba y abajo Nombre y Puesto */}
          <div className="relative">
            <button
              type="button"
              onClick={() => setIsProfileMenuOpen(!isProfileMenuOpen)}
              className="flex flex-col items-center justify-center p-1.5 hover:bg-page rounded-xl transition-all cursor-pointer border-none bg-transparent group"
            >
              <div className="w-8 h-8 rounded-full bg-navy-50 text-accent border border-border flex items-center justify-center overflow-hidden shadow-xs mb-0.5 group-hover:scale-105 transition-transform">
                {currentUser?.avatar ? (
                  <img src={currentUser.avatar} alt="Avatar" className="w-full h-full object-cover" />
                ) : (
                  <User size={16} />
                )}
              </div>
              <div className="text-center leading-tight max-w-[120px] truncate">
                <p className="text-[11px] font-black text-text-1 truncate">{currentUser?.name || 'Administrador'}</p>
                <p className="text-[9px] text-slate-400 font-bold uppercase tracking-wider truncate">{currentUser?.role || 'Super Admin'}</p>
              </div>
            </button>

            {isProfileMenuOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setIsProfileMenuOpen(false)}></div>
                <div className="absolute right-0 mt-2 w-64 bg-white border border-border rounded-2xl shadow-xl p-4 z-20 animate-in fade-in slide-in-from-top-2 duration-150">
                  <div className="border-b border-border pb-3 mb-3">
                    <p className="text-sm font-black text-text-1">{currentUser?.name || 'Administrador'}</p>
                    <p className="text-xs text-text-3 font-medium truncate">{currentUser?.email || 'admin@talent360.com.mx'}</p>
                  </div>
                  <div className="space-y-2.5 text-xs text-text-2 font-semibold mb-3 bg-page p-3 rounded-xl border border-border">
                    <div className="flex justify-between">
                      <span className="text-slate-400 font-medium">ID Usuario:</span>
                      <span>{currentUser?.id || 'N/A'}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-slate-400 font-medium">Rol:</span>
                      <span className="text-danger-text font-bold">{currentUser?.system_role || currentUser?.role || 'platform_admin'}</span>
                    </div>
                  </div>
                  <button
                    onClick={handleLogout}
                    className="w-full flex items-center justify-center gap-2 text-danger-text hover:text-white bg-danger-bg hover:bg-danger-text border border-danger-text/20 hover:border-transparent py-2.5 rounded-xl font-bold transition-all text-xs"
                  >
                    <LogOut size={14} />
                    Cerrar Sesión
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {activeTab === 'dashboard' && (
        <>
          {/* Header del Platform Admin Compacto (Opcional/Cerrable) */}
          {showBanner ? (
            <div className="bg-slate-900 p-4 sm:p-5 rounded-2xl shadow-xl border border-slate-800 text-white flex flex-col md:flex-row justify-between items-start md:items-center gap-4 relative overflow-hidden mb-5 transition-all">
              <div className="absolute top-0 right-0 p-6 opacity-5 pointer-events-none">
                <Activity size={160} />
              </div>

              {/* Botón de cierre ("Tachita") en la esquina superior derecha */}
              <div className="absolute top-3 right-3 z-20">
                <button
                  type="button"
                  onClick={() => setIsBannerCloseMenuOpen(!isBannerCloseMenuOpen)}
                  className="w-7 h-7 rounded-lg bg-slate-800/80 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition-colors cursor-pointer border border-slate-700/60"
                  title="Opciones de visibilidad del panel"
                >
                  <X size={14} />
                </button>

                {isBannerCloseMenuOpen && (
                  <>
                    <div className="fixed inset-0 z-30" onClick={() => setIsBannerCloseMenuOpen(false)}></div>
                    <div className="absolute right-0 mt-2 w-64 bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl p-3 z-40 text-left text-xs animate-in fade-in slide-in-from-top-2 duration-150">
                      <p className="font-black text-slate-200 mb-2 border-b border-slate-800 pb-1.5 px-1">
                        Visibilidad del Panel
                      </p>
                      <button
                        type="button"
                        onClick={() => {
                          setShowBanner(false);
                          setIsBannerCloseMenuOpen(false);
                        }}
                        className="w-full text-left p-2 rounded-xl hover:bg-slate-800 text-slate-300 hover:text-white transition-colors block mb-1 cursor-pointer"
                      >
                        <p className="font-bold flex items-center gap-1.5 text-slate-100">
                          <Eye size={14} className="text-navy-300" /> Cerrar por esta sesión
                        </p>
                        <p className="text-[10px] text-slate-400 mt-0.5">Se volverá a mostrar al recargar.</p>
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          localStorage.setItem('talent360_hide_global_banner', 'true');
                          setShowBanner(false);
                          setIsBannerCloseMenuOpen(false);
                        }}
                        className="w-full text-left p-2 rounded-xl hover:bg-slate-800 text-slate-300 hover:text-white transition-colors block cursor-pointer"
                      >
                        <p className="font-bold flex items-center gap-1.5 text-danger-text">
                          <Ban size={14} /> No volver a mostrar
                        </p>
                        <p className="text-[10px] text-slate-400 mt-0.5">Ocultar de forma permanente.</p>
                      </button>
                    </div>
                  </>
                )}
              </div>

              <div className="relative z-10 flex-1 pr-8">
                <div className="flex items-center gap-2 mb-1">
                  <span className="bg-danger-icon/90 text-white text-[9px] font-black uppercase tracking-widest px-2.5 py-0.5 rounded-full animate-pulse">
                    Plataforma Central
                  </span>
                  <span className="text-slate-400 text-xs font-bold">Modo Dueño del SaaS</span>
                </div>
                <h1 className="text-xl sm:text-2xl font-black tracking-tight">Centro de Control Global</h1>
                <p className="text-slate-400 max-w-xl text-xs font-medium mt-0.5">
                  Monitoreo de salud del software, facturación global e infraestructura de servidores.
                </p>
              </div>

              <div className="relative z-10 flex flex-wrap sm:flex-nowrap items-center gap-3 w-full md:w-auto pr-6 md:pr-0">
                {/* Selector Modo de Tiempo Compacto */}
                <div className="bg-slate-800/90 p-2 rounded-xl border border-slate-700 backdrop-blur-md flex items-center gap-2 flex-1 sm:flex-initial">
                  <div className="text-left px-1">
                    <span className="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Tiempo (DB)</span>
                    <span className={`text-[10px] font-bold ${timeMode === 'simulated' ? 'text-navy-300' : 'text-success-text'}`}>
                      {timeMode === 'simulated' ? 'Simulado' : 'Tiempo Real'}
                    </span>
                  </div>
                  <div className="flex bg-slate-900/80 p-0.5 rounded-lg border border-slate-700/60 gap-1">
                    <button
                      type="button"
                      onClick={() => updateSetting('time_mode', 'simulated')}
                      className={`text-[10px] font-bold px-2.5 py-1 rounded-md transition-colors cursor-pointer ${timeMode === 'simulated' ? 'bg-accent text-white shadow-xs' : 'text-slate-400 hover:text-white'}`}
                    >
                      Simulado
                    </button>
                    <button
                      type="button"
                      onClick={() => updateSetting('time_mode', 'real')}
                      className={`text-[10px] font-bold px-2.5 py-1 rounded-md transition-colors cursor-pointer ${timeMode === 'real' ? 'bg-success-text text-white shadow-xs' : 'text-slate-400 hover:text-white'}`}
                    >
                      Real
                    </button>
                  </div>
                </div>

                {/* Botón Facturación Stripe Compacto */}
                <button
                  type="button"
                  onClick={() => setActiveTab('billing')}
                  className="bg-white hover:bg-page text-text-1 px-4 py-2.5 rounded-xl font-extrabold text-xs shadow-md transition-all flex items-center justify-center gap-1.5 cursor-pointer shrink-0"
                >
                  <CreditCard size={15} />
                  Facturación Stripe
                </button>
              </div>
            </div>
          ) : (
            <div className="flex justify-end mb-3">
              <button
                type="button"
                onClick={() => {
                  localStorage.removeItem('talent360_hide_global_banner');
                  setShowBanner(true);
                }}
                className="text-[11px] font-bold text-slate-400 hover:text-accent flex items-center gap-1.5 transition-colors bg-white hover:bg-navy-50 border border-border px-3 py-1 rounded-xl shadow-2xs cursor-pointer"
              >
                <Info size={13} />
                Mostrar panel de información
              </button>
            </div>
          )}

      {/* KPIs Financieros y de Crecimiento Compactos en una Sola Fila (1x4) con Marca de Agua Coloreada */}
      <div className="grid grid-cols-4 gap-2 sm:gap-3 mb-5">
        {kpis.map((stat, idx) => (
          <div
            key={idx}
            className="relative overflow-hidden bg-white p-2.5 sm:p-3.5 rounded-2xl shadow-xs border border-border/90 flex flex-col justify-between group hover:border-navy-300 transition-all min-h-[86px] sm:min-h-[92px]"
          >
            {/* Icono Grande de Fondo en Marca de Agua con Color Específico */}
            <stat.icon
              size={64}
              className={`absolute -right-1 -bottom-1 ${stat.watermarkColor} pointer-events-none group-hover:scale-110 transition-transform duration-300`}
            />

            <div className="flex items-center justify-between relative z-10">
              <span className="text-[10px] sm:text-[11px] font-black text-slate-400 uppercase tracking-wider truncate">
                {stat.label}
              </span>
              <ArrowUpRight size={13} className="text-slate-300 group-hover:text-accent transition-colors shrink-0 hidden sm:block" />
            </div>

            <div className="relative z-10 my-0.5 sm:my-1">
              <h3 className="text-base sm:text-xl md:text-2xl font-black text-text-1 leading-none tracking-tight truncate">
                {stat.value}
              </h3>
            </div>

            <div className="relative z-10 flex items-center justify-between">
              <span className="text-[9px] sm:text-[10px] font-bold text-text-3 bg-page px-1.5 py-0.5 rounded border border-border/80 inline-block truncate max-w-full">
                {stat.trend}
              </span>
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Empresas Recientes */}
        <div className="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-border p-6">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
             <h2 className="text-lg font-black text-text-1 flex items-center gap-2">
                <Building2 className="text-accent" size={20} />
                Clientes e Inquilinos
             </h2>
             <button onClick={() => setIsNewTenantModalOpen(true)} className="text-sm font-bold text-accent hover:bg-accent-soft/50 bg-navy-50 px-4 py-2 rounded-xl transition-all self-start sm:self-auto">Simular Alta de Empresa</button>
          </div>

          {/* Barra de Filtros y Búsqueda */}
          <div className="flex flex-col md:flex-row gap-3 mb-6 bg-page p-4 rounded-2xl border border-border">
             <div className="relative flex-1">
                <Search size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Buscar empresa por nombre o subdominio..."
                  className="w-full pl-10 pr-4 py-2 text-sm bg-white border border-border rounded-xl focus:border-accent focus:ring-1 focus-visible:ring-focus-ring outline-none transition-all font-semibold text-text-1 placeholder-slate-400"
                />
             </div>
             <div className="flex gap-2">
                <div className="flex items-center gap-1.5 bg-white border border-border px-3 py-1.5 rounded-xl text-xs font-bold text-text-2">
                   <Filter size={14} className="text-slate-400" />
                   <span>Plan:</span>
                   <select
                      value={planFilter}
                      onChange={(e) => setPlanFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-text-1 font-extrabold pr-4"
                   >
                      <option value="all">Todos</option>
                      <option value="freemium">Freemium</option>
                      <option value="pro">PRO</option>
                      <option value="enterprise">Enterprise</option>
                   </select>
                </div>
                <div className="flex items-center gap-1.5 bg-white border border-border px-3 py-1.5 rounded-xl text-xs font-bold text-text-2">
                   <Activity size={14} className="text-slate-400" />
                   <span>Estado:</span>
                   <select
                      value={statusFilter}
                      onChange={(e) => setStatusFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-text-1 font-extrabold pr-4"
                   >
                      <option value="all">Todos</option>
                      <option value="active">Activo</option>
                      <option value="inactive">Inactivo</option>
                   </select>
                </div>
             </div>
          </div>

          {/* Vista Móvil (Tarjetas Responsivas) */}
          <div className="block md:hidden space-y-3">
             {isLoading ? (
                <div className="py-8 text-center text-text-3 font-medium"><Loader2 className="animate-spin mx-auto mb-2" /> Cargando inquilinos...</div>
             ) : tenantsList.length === 0 ? (
                <div className="py-8 text-center text-text-3 font-medium">No se encontraron inquilinos con los filtros aplicados.</div>
             ) : tenantsList.map((comp, idx) => (
                <div key={idx} className="bg-page border border-border rounded-2xl p-4 space-y-3 shadow-xs">
                   <div className="flex justify-between items-start">
                      <div>
                         <h4 className="font-extrabold text-text-1 text-sm leading-snug">{comp.name}</h4>
                         <div className="mt-1">{getNichoBadge(comp.nicho)}</div>
                         <span className="text-[10px] text-slate-400 font-semibold block mt-0.5">{comp.date}</span>
                      </div>
                      <div className="text-right">
                         <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase ${
                            comp.plan === 'PRO' ? 'bg-warning-bg text-warning-text' :
                            comp.plan === 'Enterprise' ? 'bg-accent-soft text-accent' :
                            'bg-slate-200 text-text-2'
                         }`}>
                            {comp.plan}
                         </span>
                         <div className="text-[11px] font-extrabold text-text-1 mt-1">
                            ${comp.monthly_price ?? 0} <span className="text-[9px] text-text-3 font-semibold">/mes</span>
                         </div>
                      </div>
                   </div>

                   <div className="grid grid-cols-3 gap-2 text-center text-xs bg-white p-2 rounded-xl border border-border/80">
                      <div>
                         <span className="text-[9px] font-black text-slate-400 block uppercase">Módulos</span>
                         <span className="font-extrabold text-accent">{comp.modules_count ?? 0} / {comp.total_modules_available ?? 12}</span>
                      </div>
                      <div>
                         <span className="text-[9px] font-black text-slate-400 block uppercase">Usuarios</span>
                         {/* AVISO, no candado (2026-09-05): el tope del plan no lo aplica ningún
                             código —`max_users` se guarda y nadie lo revisa—, así que el panel al
                             menos tiene que poder VER quién lo rebasó. El servidor manda
                             `sobre_cupo` ya calculado contra el tope del tarifario. */}
                         <span className={`font-extrabold ${comp.sobre_cupo ? 'text-warning-text' : 'text-text-2'}`}>
                            {comp.users} / {comp.tope_colaboradores ?? '∞'}
                            {comp.sobre_cupo && <span className="ml-1 text-[8px] uppercase" title="Rebasa el cupo de su plan. No se bloquea nada.">sobre cupo</span>}
                         </span>
                      </div>
                      <div>
                         <span className="text-[9px] font-black text-slate-400 block uppercase">Volumen DB</span>
                         <span className="font-extrabold text-success-text">{comp.tx_daily_avg ?? 0} <span className="text-[8px] text-slate-400">Tx/día</span></span>
                      </div>
                   </div>

                   <div className="flex items-center justify-between text-xs border-t border-b border-border/70 py-2">
                      <span className="flex items-center gap-1.5">
                         <span className={`w-2 h-2 rounded-full ${comp.status === 'Activo' ? 'bg-success-icon' : 'bg-danger-icon'}`}></span>
                         <span className={`font-bold ${comp.status === 'Activo' ? 'text-text-2' : 'text-danger-text'}`}>{comp.status}</span>
                      </span>
                      <div className="text-xs">
                         {(() => {
                            if (comp.plan?.toLowerCase() === 'freemium' && !comp.trial_ends_at) {
                               return <span className="text-[10px] text-slate-400 font-semibold block">Gratuito permanente</span>;
                            }
                            const cob = estadoDeCobranza(comp);
                            return (
                               <span
                                 title={cob.detalle || ''}
                                 className={`text-[10px] font-bold px-2 py-0.5 rounded-full border ${cob.clases}`}
                               >
                                  {cob.etiqueta}
                               </span>
                            );
                         })()}
                      </div>
                   </div>

                   <div className="flex items-center justify-end gap-1.5 pt-1">
                      <button
                        onClick={() => handleOpenDetails(comp.id)}
                        title="Ver Detalles y Accesos"
                        className="p-2 bg-white hover:bg-page text-text-2 rounded-xl transition-colors border border-border text-xs font-bold flex items-center gap-1"
                      >
                        <Eye size={14} />
                        Detalles
                      </button>
                      <button
                        onClick={() => handleImpersonate(comp.id)}
                        title="Iniciar Sesión como Admin"
                        className="p-2 bg-accent hover:bg-accent-hover text-white rounded-xl transition-colors text-xs font-bold flex items-center gap-1"
                      >
                        <LogIn size={14} />
                        Entrar
                      </button>
                   </div>
                </div>
             ))}
          </div>

          {/* Vista Escritorio (Tabla Completa) */}
          <div className="hidden md:block overflow-x-auto">
             <table className="w-full text-left text-sm">
                <thead>
                   <tr className="border-b border-border text-text-3">
                      <th className="pb-3 font-bold">Empresa</th>
                      <th className="pb-3 font-bold">Plan & Costo</th>
                      <th className="pb-3 font-bold">Módulos</th>
                      <th className="pb-3 font-bold">Usuarios</th>
                      <th className="pb-3 font-bold">Volumen DB</th>
                      <th className="pb-3 font-bold">Estado</th>
                      <th className="pb-3 font-bold text-right">Acciones</th>
                   </tr>
                </thead>
                <tbody className="divide-y divide-border">
                   {isLoading ? (
                      <tr><td colSpan={7} className="py-8 text-center text-text-3 font-medium"><Loader2 className="animate-spin mx-auto mb-2" /> Cargando inquilinos...</td></tr>
                   ) : tenantsList.length === 0 ? (
                      <tr><td colSpan={7} className="py-8 text-center text-text-3 font-medium">No se encontraron inquilinos con los filtros aplicados.</td></tr>
                   ) : tenantsList.map((comp, idx) => (
                      <tr key={idx} className="hover:bg-page/80 transition-colors">
                          <td className="py-4 font-bold text-text-1">
                             <div className="flex items-center gap-2 flex-wrap">
                                <span>{comp.name}</span>
                                {getNichoBadge(comp.nicho)}
                             </div>
                             <span className="text-[10px] text-slate-400 font-semibold block">ID: {comp.subdomain}</span>
                          </td>
                          <td className="py-4">
                             <div className="flex items-center gap-2">
                                <span className={`px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase ${
                                   comp.plan === 'PRO' ? 'bg-warning-bg text-warning-text' :
                                   comp.plan === 'Enterprise' ? 'bg-accent-soft text-accent' :
                                   'bg-page text-text-2'
                                }`}>
                                   {comp.plan}
                                </span>
                                <span className="text-xs font-black text-text-1">
                                   ${comp.monthly_price ?? 0} <span className="text-[9px] text-slate-400 font-medium">/mes</span>
                                </span>
                             </div>
                          </td>
                          <td className="py-4">
                             <span
                               title={`Módulos habilitados (${comp.allowed_modules?.length || 0}): ${(comp.allowed_modules || []).join(', ')}`}
                               className="inline-flex items-center gap-1.5 bg-navy-50 border border-border text-accent px-2.5 py-1 rounded-lg text-xs font-extrabold cursor-help"
                             >
                               <span>📦</span>
                               <span>{comp.modules_count ?? 0} / {comp.total_modules_available ?? 12}</span>
                             </span>
                          </td>
                          <td className="py-4 font-medium text-text-2">
                             <span className="font-bold text-text-1">{comp.users}</span>
                             <span className="text-[10px] text-slate-400 font-semibold"> / {comp.max_users ?? 5}</span>
                          </td>
                          <td className="py-4">
                             <div
                               title={`Total 30 días: ${comp.tx_30_days || 0} operaciones de base de datos (${comp.tx_total || 0} históricas)`}
                               className="inline-flex items-center gap-1 bg-success-bg border border-success-text/20 text-success-text px-2 py-0.5 rounded-lg text-xs font-extrabold cursor-help"
                             >
                               <span>⚡</span>
                               <span>{comp.tx_daily_avg ?? 0}</span>
                               <span className="text-[9px] font-semibold text-success-text">Tx/día</span>
                             </div>
                          </td>
                          <td className="py-4">
                             <div className="flex flex-col gap-0.5">
                                <span className="flex items-center gap-1.5">
                                   <span className={`w-2 h-2 rounded-full ${comp.status === 'Activo' ? 'bg-success-icon' : 'bg-danger-icon'}`}></span>
                                   <span className={`text-xs font-bold ${comp.status === 'Activo' ? 'text-text-2' : 'text-danger-text'}`}>{comp.status}</span>
                                </span>
                                {(() => {
                                   const cob = estadoDeCobranza(comp);
                                   const insignia = (
                                      <span
                                        title={cob.detalle || ''}
                                        className={`inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full border mt-0.5 whitespace-nowrap ${cob.clases}`}
                                      >
                                         {cob.etiqueta}
                                      </span>
                                   );

                                   // Las insignias de evidencia son cosa del plan freemium (difusión social
                                   // a cambio de módulos). Antes también salían colgadas de "Prueba
                                   // Expirada", así que una empresa enterprise al corriente terminaba
                                   // marcada con "📢 Publicidad Pendiente" sin deberle nada a nadie.
                                   if (comp.plan?.toLowerCase() !== 'freemium') {
                                      return insignia;
                                   }

                                   return (
                                      <div className="mt-0.5">
                                         {comp.trial_ends_at
                                            ? insignia
                                            : <span className="text-[10px] text-slate-400 font-semibold block">Gratuito permanente</span>}
                                         {comp.freemium_compliance_status === 'approved' ? (
                                            <span className="inline-flex items-center gap-1 text-[9px] font-bold text-success-text bg-success-bg border border-success-text/20 px-2 py-0.5 rounded-full mt-1">
                                               ✓ Evidencia Aprobada
                                            </span>
                                         ) : comp.freemium_compliance_status === 'submitted' ? (
                                            <span className="inline-flex items-center gap-1 text-[9px] font-bold text-warning-text bg-warning-bg border border-warning-text/20 px-2 py-0.5 rounded-full mt-1">
                                               ⏳ Comprobante por Revisar
                                            </span>
                                         ) : comp.freemium_compliance_status === 'rejected' ? (
                                            <span className="inline-flex items-center gap-1 text-[9px] font-bold text-danger-text bg-danger-bg border border-danger-text/20 px-2 py-0.5 rounded-full mt-1">
                                               ⚠️ Evidencia Rechazada
                                            </span>
                                         ) : (
                                            <span className="inline-flex items-center gap-1 text-[9px] font-bold text-accent bg-navy-50 border border-border px-2 py-0.5 rounded-full mt-1">
                                               📢 Comprobante Pendiente
                                            </span>
                                         )}
                                      </div>
                                   );
                                })()}
                             </div>
                          </td>
                          <td className="py-4 text-right">
                             <div className="flex justify-end gap-1.5">
                                <button
                                  onClick={() => handleOpenDetails(comp.id)}
                                  title="Ver Detalles y Accesos"
                                  className="p-1.5 bg-page hover:bg-slate-200 text-text-2 rounded-lg transition-colors border border-border"
                                >
                                  <Eye size={14} />
                                </button>

                                <button
                                  onClick={() => handleImpersonate(comp.id)}
                                  title="Iniciar Sesión como Admin"
                                  className="p-1.5 bg-navy-50 hover:bg-accent-soft text-accent rounded-lg transition-colors border border-border"
                                >
                                  <LogIn size={14} />
                                </button>

                                {comp.id !== 1 && comp.name !== 'Talent 360' ? (
                                  <>
                                    <button
                                      onClick={() => handleToggleStatus(comp.id, comp.name, comp.status === 'Activo')}
                                      title={comp.status === 'Activo' ? "Suspender Empresa" : "Activar Empresa"}
                                      className={`p-1.5 rounded-lg transition-colors border ${
                                        comp.status === 'Activo'
                                          ? 'bg-danger-bg hover:bg-danger-bg text-danger-text border-danger-text/20'
                                          : 'bg-success-bg hover:bg-success-bg text-success-text border-success-text/20'
                                      }`}
                                    >
                                      <Ban size={14} />
                                    </button>
                                    <button
                                      onClick={() => handleDeleteTenant(comp.id, comp.name)}
                                      title="Eliminar permanentemente"
                                      className="p-1.5 bg-page hover:bg-danger-text hover:text-white text-slate-400 rounded-lg transition-colors border border-border hover:border-transparent"
                                    >
                                      <X size={14} />
                                    </button>
                                  </>
                                ) : (
                                  <span className="text-[10px] text-slate-400 font-black italic bg-page border border-border px-2 py-1 rounded-lg">Protegido</span>
                                )}
                             </div>
                          </td>
                      </tr>
                   ))}
                </tbody>
             </table>
          </div>
        </div>

        {/* Monitoreo de Errores e Infraestructura */}
        <div className="bg-white rounded-2xl shadow-sm border border-border p-6 flex flex-col">
          <h2 className="text-lg font-black text-text-1 flex items-center gap-2 mb-6">
             <ServerCrash className="text-danger-text" size={20} />
             Salud del Sistema
          </h2>

          <div className="space-y-4 flex-1">
             {saasAlerts.length === 0 ? (
                <div className="text-center text-text-3 py-8 text-sm font-bold">Sin alertas actuales.</div>
             ) : (
                saasAlerts.map((alert, idx) => (
                   <div key={idx} className={`p-4 rounded-xl border flex justify-between items-center gap-2 ${alert.type === 'error' ? 'bg-danger-bg border-danger-text/20 text-danger-text' : 'bg-warning-bg border-warning-text/20 text-warning-text'}`}>
                      <div className="flex items-start gap-3">
                         {alert.type === 'error' ? <ShieldAlert size={18} className="mt-0.5 shrink-0" /> : <AlertOctagon size={18} className="mt-0.5 shrink-0" />}
                         <div>
                            <p className="text-sm font-bold leading-tight">{alert.message}</p>
                            <p className="text-xs mt-2 opacity-70 font-medium">{alert.time}</p>
                         </div>
                      </div>
                      <button onClick={() => resolveSaaSAlert(alert.id)} className="text-xs font-bold bg-white/50 px-2 py-1 rounded hover:bg-white transition-colors">Resolver</button>
                   </div>
                ))
             )}
          </div>

          {/* Calificación de Módulos (Auditoría del 1 al 10) */}
          <div className="mt-6 border-t border-border pt-6">
            <h3 className="text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center justify-between">
              <span className="flex items-center gap-1.5">
                <span>📊</span> Auditoría de Calidad por Módulo
              </span>
              <span className="flex items-center gap-1.5 bg-success-bg text-success-text border border-success-text/50 px-2 py-0.5 rounded-full text-[9px] font-extrabold normal-case">
                <span className="w-1.5 h-1.5 rounded-full bg-success-icon animate-pulse"></span>
                En Tiempo Real
              </span>
            </h3>
            <div className="space-y-3.5">
              {moduleAuditsList.map((mod) => (
                <div key={mod.id} className="group">
                  <div className="flex justify-between items-center mb-1 text-xs font-bold text-text-2">
                    <span className="text-text-1 font-extrabold">{mod.name}</span>
                    <div className="flex items-center gap-2">
                      <span className={`px-1.5 py-0.5 rounded text-[10px] font-extrabold ${
                        mod.score >= 8 ? 'bg-success-bg text-success-text border border-success-text/20' :
                        mod.score >= 6 ? 'bg-warning-bg text-warning-text border border-warning-text/20' :
                        'bg-danger-bg text-danger-text border border-danger-text/20'
                      }`}>{mod.score}/10</span>
                      <button
                        onClick={() => setSelectedAuditModule(mod)}
                        className="text-[10px] font-black text-accent hover:text-navy-800 transition-colors"
                      >
                        Ver detalles
                      </button>
                    </div>
                  </div>
                  <div className="w-full bg-page rounded-full h-1.5">
                    <div
                      className={`h-1.5 rounded-full transition-all duration-500 ${
                        mod.score >= 8 ? 'bg-success-icon' :
                        mod.score >= 6 ? 'bg-warning-icon' :
                        'bg-danger-icon'
                      }`}
                      style={{ width: `${mod.score * 10}%` }}
                    ></div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          <button className="w-full mt-6 bg-slate-900 text-white font-bold py-3 rounded-xl hover:bg-slate-800 transition-colors">
             Ver Logs en Datadog
          </button>
        </div>
      </div>

      {/* Estado de Módulos y Add-ons (App Store / Premium) */}
      <div className="bg-white rounded-2xl shadow-sm border border-border p-6 mt-6">
        <div className="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
           <h2 className="text-lg font-black text-text-1 flex items-center gap-2">
              <Activity className="text-accent" size={20} />
              Adopción de Módulos y Precios
           </h2>
            <div className="flex flex-wrap gap-2.5 sm:gap-3">
               <button
                  onClick={handleOpenFreemiumConfig}
                  className="text-xs font-bold text-text-2 bg-page hover:bg-slate-200 border border-border px-3.5 py-1.5 rounded-xl transition-colors flex items-center gap-1.5"
               >
                  <Settings size={14} />
                  Configurar Plan Gratuito y Prueba
               </button>
               <button
                  onClick={() => setIsPricingModalOpen(true)}
                  className="text-xs font-bold text-accent hover:bg-accent-soft/50 bg-navy-50 border border-border px-3.5 py-1.5 rounded-xl transition-colors"
               >
                  Configurar Precios
               </button>
               <button
                  onClick={handleOpenBankConfig}
                  className="text-xs font-bold text-success-text hover:bg-success-bg/50 bg-success-bg border border-success-text/20 px-3.5 py-1.5 rounded-xl transition-colors flex items-center gap-1.5"
               >
                  <CreditCard size={14} />
                  Configurar Cuenta Bancaria (SPEI)
               </button>
               <button
                  onClick={handleOpenSimulatorConfig}
                  className="text-xs font-bold text-accent hover:bg-accent-soft/50 bg-navy-50 border border-border px-3.5 py-1.5 rounded-xl transition-colors flex items-center gap-1.5"
               >
                  <Monitor size={14} />
                  Ajustes del Simulador Landing
               </button>
            </div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

           <div className="border border-border rounded-xl p-5 hover:border-navy-300 transition-colors group">
              <div className="flex justify-between items-start mb-3">
                 <span className="p-2 bg-navy-50 text-accent rounded-lg group-hover:scale-110 transition-transform"><Building2 size={20} /></span>
                 <span className="text-[9px] font-black uppercase tracking-widest bg-warning-bg text-warning-text px-2 py-0.5 rounded">Premium</span>
              </div>
              <h3 className="font-bold text-text-1 text-sm">Portal de Vacantes</h3>
              <p className="text-xs text-text-3 mt-1 mb-3">Atracción de talento externo y publicación de empleos.</p>
              <div className="w-full bg-page rounded-full h-1.5 mb-1"><div className="bg-accent h-1.5 rounded-full" style={{ width: '35%' }}></div></div>
              <p className="text-[10px] font-bold text-slate-400">35% de Inquilinos activos</p>
           </div>

           <div className="border border-border rounded-xl p-5 hover:border-navy-300 transition-colors group">
              <div className="flex justify-between items-start mb-3">
                 <span className="p-2 bg-navy-50 text-accent rounded-lg group-hover:scale-110 transition-transform"><Users size={20} /></span>
                 <span className="text-[9px] font-black uppercase tracking-widest bg-success-bg text-success-text px-2 py-0.5 rounded">Freemium</span>
              </div>
              <h3 className="font-bold text-text-1 text-sm">Rutinas y Tareas</h3>
              <p className="text-xs text-text-3 mt-1 mb-3">Asignación de tickets, matriz de QA y check-lists.</p>
              <div className="w-full bg-page rounded-full h-1.5 mb-1"><div className="bg-success-icon h-1.5 rounded-full" style={{ width: '85%' }}></div></div>
              <p className="text-[10px] font-bold text-slate-400">85% de Inquilinos activos</p>
           </div>

           <div className="border border-border rounded-xl p-5 hover:border-navy-300 transition-colors group">
              <div className="flex justify-between items-start mb-3">
                 <span className="p-2 bg-navy-50 text-accent rounded-lg group-hover:scale-110 transition-transform"><TrendingUp size={20} /></span>
                 <span className="text-[9px] font-black uppercase tracking-widest bg-success-bg text-success-text px-2 py-0.5 rounded">Freemium</span>
              </div>
              <h3 className="font-bold text-text-1 text-sm">Reportes y Analítica</h3>
              <p className="text-xs text-text-3 mt-1 mb-3">Tableros de Business Intelligence y exportación.</p>
              <div className="w-full bg-page rounded-full h-1.5 mb-1"><div className="bg-accent h-1.5 rounded-full" style={{ width: '60%' }}></div></div>
              <p className="text-[10px] font-bold text-slate-400">60% de Inquilinos activos</p>
           </div>

           <div className="border border-border rounded-xl p-5 hover:border-navy-300 transition-colors group">
              <div className="flex justify-between items-start mb-3">
                 <span className="p-2 bg-navy-50 text-accent rounded-lg group-hover:scale-110 transition-transform"><AlertOctagon size={20} /></span>
                 <span className="text-[9px] font-black uppercase tracking-widest bg-warning-bg text-warning-text px-2 py-0.5 rounded">Premium</span>
              </div>
              <h3 className="font-bold text-text-1 text-sm">Academia Interna</h3>
              <p className="text-xs text-text-3 mt-1 mb-3">Cursos interactivos, plan de carrera y evaluaciones.</p>
              <div className="w-full bg-page rounded-full h-1.5 mb-1"><div className="bg-warning-icon h-1.5 rounded-full" style={{ width: '20%' }}></div></div>
              <p className="text-[10px] font-bold text-slate-400">20% de Inquilinos activos</p>
           </div>

        </div>
      </div>
        </>
      )}

      {activeTab === 'pending_registrations' && (
        <div className="bg-white rounded-2xl sm:rounded-3xl p-4 sm:p-8 border border-border shadow-sm animate-in fade-in duration-300">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 border-b border-border pb-6">
            <div>
              <div className="flex items-center gap-2 mb-1">
                <span className="bg-warning-bg text-warning-text text-[10px] font-black uppercase px-2.5 py-1 rounded-full border border-warning-text/50">
                  Pre-registros Huérfanos
                </span>
                <h2 className="text-lg sm:text-xl font-black text-text-1">Registros Inconclusos de Plataforma</h2>
              </div>
              <p className="text-xs sm:text-sm text-text-3">
                Usuarios que iniciaron el registro en Talent360 pero no completaron la creación de su empresa. Puedes contactarles para dar seguimiento comercial o eliminar el registro para liberar el correo.
              </p>
            </div>
            <button
              onClick={fetchPendingRegistrations}
              className="bg-page hover:bg-slate-200 text-text-2 font-bold text-xs px-4 py-2.5 rounded-xl transition-all flex items-center justify-center gap-1.5 shrink-0 border-none cursor-pointer w-full sm:w-auto"
            >
              <RefreshCw size={14} className={isPendingLoading ? 'animate-spin' : ''} />
              Actualizar Lista
            </button>
          </div>

          {isPendingLoading ? (
            <div className="py-16 text-center text-slate-400">
              <Loader2 className="animate-spin mx-auto mb-2 text-accent" size={28} />
              <p className="text-xs font-bold">Cargando registros inconclusos...</p>
            </div>
          ) : pendingRegistrations.length === 0 ? (
            <div className="text-center py-12 px-4 bg-page rounded-2xl border border-dashed border-border">
              <CheckCircle2 className="mx-auto text-success-text mb-3" size={40} />
              <h4 className="font-bold text-text-2 text-sm">Sin registros inconclusos</h4>
              <p className="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                No hay usuarios pendientes sin empresa en la plataforma. Todos los pre-registros han completado la creación de su organización.
              </p>
            </div>
          ) : (
            <>
              {/* Vista Móvil para Registros Inconclusos */}
              <div className="block md:hidden space-y-3">
                {pendingRegistrations.map((u) => (
                  <div key={u.id} className="bg-page border border-border rounded-2xl p-4 space-y-3">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center font-black text-text-2 text-xs border border-slate-300">
                          {u.name ? u.name.charAt(0).toUpperCase() : 'U'}
                        </div>
                        <div>
                          <p className="font-extrabold text-text-1 text-xs">{u.name || 'Sin Nombre'}</p>
                          <span className="text-[10px] text-slate-400 font-bold uppercase">ID #{u.id}</span>
                        </div>
                      </div>
                      <span className={`px-2 py-0.5 rounded-full text-[9px] font-black uppercase ${
                        u.provider === 'Google' ? 'bg-danger-bg text-danger-text border border-danger-text/20' : 'bg-slate-200 text-text-2'
                      }`}>
                        {u.provider}
                      </span>
                    </div>

                    <div className="text-xs font-bold text-accent truncate border-t border-b border-border/70 py-2">
                      {u.email}
                      <span className="block text-[10px] text-slate-400 font-normal mt-0.5">{u.created_at_human}</span>
                    </div>

                    <div className="flex gap-2 justify-end pt-1">
                      <button
                        onClick={() => {
                          navigator.clipboard.writeText(u.email);
                          alert(`Correo ${u.email} copiado al portapapeles.`);
                        }}
                        className="p-2 text-text-2 hover:text-accent hover:bg-navy-50 rounded-xl transition-all font-bold text-xs flex items-center gap-1 border border-border cursor-pointer"
                        title="Copiar Correo"
                      >
                        <MessageSquare size={14} /> Contactar
                      </button>
                      <button
                        onClick={() => handleDeletePendingRegistration(u.id, u.email)}
                        className="p-2 text-danger-text hover:bg-danger-bg rounded-xl transition-all font-bold text-xs flex items-center gap-1 border border-danger-text/20 cursor-pointer"
                        title="Eliminar Registro Inconcluso"
                      >
                        <Trash2 size={14} /> Eliminar
                      </button>
                    </div>
                  </div>
                ))}
              </div>

              {/* Vista Escritorio para Registros Inconclusos */}
              <div className="hidden md:block overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead>
                    <tr className="bg-page text-text-3 font-black uppercase tracking-wider border-b border-border">
                      <th className="p-4 rounded-l-xl">Solicitante</th>
                      <th className="p-4">Correo Electrónico</th>
                      <th className="p-4">Proveedor Auth</th>
                      <th className="p-4">Registro</th>
                      <th className="p-4 text-right rounded-r-xl">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {pendingRegistrations.map((u) => (
                      <tr key={u.id} className="hover:bg-page/80 transition-colors">
                        <td className="p-4 font-black text-text-1 flex items-center gap-2">
                          <div className="w-8 h-8 rounded-full bg-page flex items-center justify-center font-black text-text-2 border border-border">
                            {u.name ? u.name.charAt(0).toUpperCase() : 'U'}
                          </div>
                          <div>
                            <p className="font-extrabold text-text-1">{u.name || 'Sin Nombre'}</p>
                            <span className="text-[10px] text-slate-400 font-bold uppercase">ID #{u.id}</span>
                          </div>
                        </td>
                        <td className="p-4 font-bold text-accent">
                          {u.email}
                        </td>
                        <td className="p-4">
                          <span className={`px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider ${
                            u.provider === 'Google' ? 'bg-danger-bg text-danger-text border border-danger-text/20' : 'bg-page text-text-2 border border-border'
                          }`}>
                            {u.provider}
                          </span>
                        </td>
                        <td className="p-4 text-text-3 font-medium">
                          {u.created_at_human}
                        </td>
                        <td className="p-4 text-right">
                          <div className="flex items-center justify-end gap-2">
                            <button
                              onClick={() => {
                                navigator.clipboard.writeText(u.email);
                                alert(`Correo ${u.email} copiado al portapapeles.`);
                              }}
                              className="p-2 text-text-2 hover:text-accent hover:bg-navy-50 rounded-xl transition-all font-bold text-xs flex items-center gap-1 border border-border cursor-pointer"
                              title="Copiar Correo"
                            >
                              <MessageSquare size={14} /> Contactar
                            </button>
                            <button
                              onClick={() => handleDeletePendingRegistration(u.id, u.email)}
                              className="p-2 text-danger-text hover:bg-danger-bg rounded-xl transition-all font-bold text-xs flex items-center gap-1 border border-danger-text/20 cursor-pointer"
                              title="Eliminar Registro Inconcluso"
                            >
                              <Trash2 size={14} /> Eliminar
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      )}

      {activeTab === 'social_promotions' && (
        <div className="space-y-6 animate-in fade-in duration-300">

          {/* Header & Grace Days Configuration */}
          <div className="bg-white rounded-2xl sm:rounded-3xl p-6 border border-border shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div>
              <div className="flex items-center gap-2 mb-1">
                <span className="bg-accent-soft text-navy-800 text-[10px] font-black uppercase px-2.5 py-1 rounded-full border border-border">
                  Difusión Social & Promociones
                </span>
              </div>
              <h2 className="text-xl font-black text-text-1">Gestión de Tiempo de Gracia y Banners</h2>
              <p className="text-xs text-text-3 mt-0.5">
                Configura los días otorgados por compartir en redes sociales, aprueba solicitudes de clientes y crea banners promocionales para el pie de página.
              </p>
            </div>

            <div className="p-4 bg-page rounded-2xl border border-border flex items-center gap-3 shrink-0">
              <span className="text-xs font-bold text-text-2">Días de Gracia por Defecto:</span>
              <input
                type="number"
                min="1"
                max="365"
                value={socialGraceDaysConfig}
                onChange={(e) => setSocialGraceDaysConfig(parseInt(e.target.value) || 30)}
                className="w-16 text-center text-xs font-black p-2 rounded-xl border border-slate-300 bg-white"
              />
              <button
                onClick={async () => {
                  try {
                    await axiosInstance.post('/platform/social-grace-config', { social_grace_days: socialGraceDaysConfig });
                    alert("Configuración de días de gracia guardada.");
                  } catch (err) {
                    alert("Error al guardar.");
                  }
                }}
                className="px-3 py-2 bg-accent hover:bg-accent-hover text-white font-bold text-xs rounded-xl shadow-sm transition-all"
              >
                Guardar
              </button>
            </div>
          </div>

          {/* Solicitudes de Difusión Social (Clientes) */}
          <div className="bg-white rounded-2xl sm:rounded-3xl p-6 border border-border shadow-sm space-y-4">
            <div className="flex justify-between items-center pb-4 border-b border-border">
              <div>
                <h3 className="text-base font-black text-text-1">Solicitudes de Difusión Social</h3>
                <p className="text-xs text-text-3">Evidencias enviadas por clientes para desbloquear módulos gratis.</p>
              </div>
              <button
                onClick={fetchSocialPromotionsData}
                className="p-2 text-text-3 hover:text-text-1 rounded-xl hover:bg-page text-xs font-bold flex items-center gap-1.5"
              >
                <RefreshCw size={14} className={isSocialLoading ? 'animate-spin' : ''} /> Actualizar
              </button>
            </div>

            {socialClaims.length === 0 ? (
              <div className="text-center py-10 text-slate-400 text-xs font-medium">
                No hay solicitudes de difusión en este momento.
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="bg-page text-text-3 font-bold uppercase text-[10px] border-b border-border">
                      <th className="p-3.5">Empresa / Tenant</th>
                      <th className="p-3.5">Módulo Solicitado</th>
                      <th className="p-3.5">Evidencia / URL</th>
                      <th className="p-3.5">Días Otorgados</th>
                      <th className="p-3.5">Estado</th>
                      <th className="p-3.5 text-right">Acción</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {socialClaims.map((claim) => (
                      <tr key={claim.id} className="hover:bg-page/80">
                        <td className="p-3.5 font-bold text-text-1">
                          {claim.tenant?.name || `Tenant #${claim.tenant_id}`}
                        </td>
                        <td className="p-3.5 font-extrabold text-accent uppercase">
                          {claim.module_key}
                        </td>
                        <td className="p-3.5 max-w-xs truncate">
                          {claim.proof_url ? (
                            <a href={claim.proof_url} target="_blank" rel="noopener noreferrer" className="text-accent underline font-semibold truncate block">
                              {claim.proof_url}
                            </a>
                          ) : (
                            <span className="text-slate-400 font-medium">{claim.proof_note || 'Sin enlace'}</span>
                          )}
                        </td>
                        <td className="p-3.5 font-bold text-text-2">
                          {claim.grace_days_granted || 30} días
                        </td>
                        <td className="p-3.5">
                          <span className={`px-2.5 py-1 rounded-full text-[10px] font-black uppercase ${
                            claim.status === 'active' ? 'bg-success-bg text-success-text' :
                            claim.status === 'pending_approval' ? 'bg-warning-bg text-warning-text' :
                            'bg-danger-bg text-danger-text'
                          }`}>
                            {claim.status === 'active' ? 'Aprobado (Activo)' : claim.status === 'pending_approval' ? 'Pendiente' : 'Rechazado'}
                          </span>
                        </td>
                        <td className="p-3.5 text-right">
                          {claim.status === 'pending_approval' && (
                            <div className="flex items-center justify-end gap-2">
                              <button
                                onClick={async () => {
                                  try {
                                    await axiosInstance.post(`/platform/social-claims/${claim.id}/approve`);
                                    fetchSocialPromotionsData();
                                  } catch (err) {
                                    alert("Error al aprobar.");
                                  }
                                }}
                                className="px-3 py-1.5 bg-success-text hover:bg-success-text text-white font-bold text-xs rounded-xl shadow-sm"
                              >
                                Aprobar
                              </button>
                              <button
                                onClick={async () => {
                                  try {
                                    await axiosInstance.post(`/platform/social-claims/${claim.id}/reject`);
                                    fetchSocialPromotionsData();
                                  } catch (err) {
                                    alert("Error al rechazar.");
                                  }
                                }}
                                className="px-3 py-1.5 bg-danger-text hover:bg-danger-text text-white font-bold text-xs rounded-xl shadow-sm"
                              >
                                Rechazar
                              </button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {/* Creador de Promociones de Temporada */}
          <div className="bg-white rounded-2xl sm:rounded-3xl p-6 border border-border shadow-sm space-y-6">
            <div>
              <h3 className="text-base font-black text-text-1">Promociones de Temporada (Store Dock Inferior)</h3>
              <p className="text-xs text-text-3">Crea banners flotantes de ofertas especiales para los clientes.</p>
            </div>

            <form
              onSubmit={async (e) => {
                e.preventDefault();
                try {
                  await axiosInstance.post('/platform/promotions', {
                    title: newPromoTitle,
                    subtitle: newPromoSubtitle,
                    badge_text: newPromoBadge,
                    discount_percentage: newPromoDiscount,
                    is_active: true
                  });
                  setNewPromoTitle('');
                  setNewPromoSubtitle('');
                  fetchSocialPromotionsData();
                  alert("Promoción creada correctamente.");
                } catch (err) {
                  alert("Error al crear la promoción.");
                }
              }}
              className="grid grid-cols-1 md:grid-cols-4 gap-4 bg-page p-4 rounded-2xl border border-border"
            >
              <div>
                <label className="block text-[11px] font-bold text-text-2 mb-1">Título de la Oferta:</label>
                <input
                  type="text"
                  required
                  value={newPromoTitle}
                  onChange={(e) => setNewPromoTitle(e.target.value)}
                  placeholder="ej. 🔥 Especial Día del Padre"
                  className="w-full text-xs p-2.5 rounded-xl border border-slate-300 bg-white"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold text-text-2 mb-1">Subtítulo / Mensaje:</label>
                <input
                  type="text"
                  value={newPromoSubtitle}
                  onChange={(e) => setNewPromoSubtitle(e.target.value)}
                  placeholder="20% OFF en Plan Enterprise anual"
                  className="w-full text-xs p-2.5 rounded-xl border border-slate-300 bg-white"
                />
              </div>

              <div>
                <label className="block text-[11px] font-bold text-text-2 mb-1">Etiqueta Badge:</label>
                <input
                  type="text"
                  value={newPromoBadge}
                  onChange={(e) => setNewPromoBadge(e.target.value)}
                  placeholder="20% OFF"
                  className="w-full text-xs p-2.5 rounded-xl border border-slate-300 bg-white"
                />
              </div>

              <div className="flex items-end">
                <button
                  type="submit"
                  className="w-full py-2.5 bg-accent hover:bg-accent-hover text-white font-extrabold text-xs rounded-xl shadow-md transition-all"
                >
                  Publicar Promoción
                </button>
              </div>
            </form>

            <div className="space-y-3">
              <h4 className="text-xs font-bold text-text-2">Promociones Existentes:</h4>
              {promotionsList.length === 0 ? (
                <p className="text-xs text-slate-400 italic">No hay promociones activas registradas.</p>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {promotionsList.map((promo) => (
                    <div key={promo.id} className="p-4 rounded-2xl bg-slate-900 text-white flex justify-between items-center shadow-sm">
                      <div>
                        <span className="text-[10px] font-black uppercase px-2 py-0.5 rounded bg-warning-icon text-text-1">
                          {promo.badge_text || 'PROMO'}
                        </span>
                        <h4 className="font-extrabold text-sm mt-1">{promo.title}</h4>
                        <p className="text-xs text-slate-300">{promo.subtitle}</p>
                      </div>
                      <button
                        onClick={async () => {
                          if (window.confirm("¿Eliminar esta promoción?")) {
                            await axiosInstance.delete(`/platform/promotions/${promo.id}`);
                            fetchSocialPromotionsData();
                          }
                        }}
                        className="p-2 text-danger-text hover:text-danger-text hover:bg-white/10 rounded-xl"
                      >
                        <Trash2 size={16} />
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

        </div>
      )}

      {activeTab === 'tickets' && (
        <div className="space-y-6">
          <div className="flex flex-col sm:flex-row justify-between items-stretch sm:items-center bg-white border border-border rounded-2xl sm:rounded-3xl p-4 sm:p-5 shadow-sm gap-4">
             <div className="flex items-center gap-3">
                <div className="w-10 h-10 bg-accent rounded-xl flex items-center justify-center shadow-md shrink-0">
                   <LifeBuoy className="text-white" size={20} />
                </div>
                <div>
                   <h2 className="text-base sm:text-lg font-black text-text-1 leading-tight">Consola de Soporte y Call Center</h2>
                   <p className="text-xs text-text-3 font-medium">Monitoreo de incidencias y atención a inquilinos en tiempo real</p>
                </div>
             </div>
             <button
                type="button"
                onClick={() => setIsNewTicketModalOpen(true)}
                className="bg-accent hover:bg-accent-hover text-white px-4 py-2.5 rounded-xl font-bold shadow-lg transition-colors flex items-center justify-center gap-1.5 text-xs w-full sm:w-auto"
             >
                <Plus size={14} />
                Registrar Ticket
             </button>
          </div>

          {/* Filtros de Tickets */}
          <div className="flex flex-col md:flex-row gap-3 bg-white p-4 rounded-2xl border border-border shadow-sm">
             <div className="relative flex-1">
                <Search size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                   type="text"
                   value={ticketsSearchQuery}
                   onChange={(e) => setTicketsSearchQuery(e.target.value)}
                   placeholder="Buscar ticket por asunto, descripción o contacto..."
                   className="w-full pl-10 pr-4 py-2 text-xs bg-page border border-border rounded-xl focus:border-accent focus:ring-1 focus-visible:ring-focus-ring outline-none transition-all font-bold text-text-1 placeholder-slate-400"
                />
             </div>
             <div className="flex flex-wrap gap-2">
                <div className="flex items-center gap-1.5 bg-page border border-border px-3 py-1.5 rounded-xl text-xs font-bold text-text-2">
                   <Filter size={12} className="text-slate-400" />
                   <span>Estado:</span>
                   <select
                      value={ticketsStatusFilter}
                      onChange={(e) => setTicketsStatusFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-text-1 font-black pr-4"
                   >
                      <option value="all">Todos</option>
                      <option value="open">Abiertos</option>
                      <option value="in_progress">En Proceso</option>
                      <option value="resolved">Resueltos</option>
                      <option value="closed">Cerrados</option>
                   </select>
                </div>
                <div className="flex items-center gap-1.5 bg-page border border-border px-3 py-1.5 rounded-xl text-xs font-bold text-text-2">
                   <Activity size={12} className="text-slate-400" />
                   <span>Prioridad:</span>
                   <select
                      value={ticketsPriorityFilter}
                      onChange={(e) => setTicketsPriorityFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-text-1 font-black pr-4"
                   >
                      <option value="all">Todas</option>
                      <option value="low">Baja</option>
                      <option value="medium">Media</option>
                      <option value="high">Alta</option>
                   </select>
                </div>
                <div className="flex items-center gap-1.5 bg-page border border-border px-3 py-1.5 rounded-xl text-xs font-bold text-text-2">
                   <Building2 size={12} className="text-slate-400" />
                   <span>Inquilino:</span>
                   <select
                      value={ticketsTenantFilter}
                      onChange={(e) => setTicketsTenantFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-text-1 font-black pr-4 max-w-[150px] truncate"
                   >
                      <option value="all">Todos</option>
                      {tenantsList.map(t => (
                         <option key={t.id} value={t.id}>{t.name}</option>
                      ))}
                   </select>
                </div>
             </div>
          </div>

          {/* Lista de Tickets */}
          {isTicketsLoading ? (
            <div className="flex flex-col items-center justify-center py-12 text-text-3">
              <Loader2 className="animate-spin mb-2" />
              <span className="font-bold text-xs">Cargando tickets de soporte...</span>
            </div>
          ) : ticketsList.length === 0 ? (
            <div className="text-center py-16 bg-white border border-border rounded-3xl p-8 shadow-sm">
              <LifeBuoy size={48} className="mx-auto text-slate-350 mb-3 animate-bounce" />
              <h3 className="text-base font-black text-text-1">Sin tickets de soporte</h3>
              <p className="text-xs text-text-3 mt-1 max-w-sm mx-auto font-medium">No se encontraron tickets con los filtros actuales. Registra un nuevo ticket si ingresa una llamada o reporte.</p>
              <button onClick={() => setIsNewTicketModalOpen(true)} className="mt-4 bg-accent hover:bg-accent-hover text-white font-bold text-xs px-4 py-2 rounded-xl transition-all">Registrar Primer Ticket</button>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {ticketsList.map((ticket) => (
                 <div key={ticket.id} className="bg-white border border-border rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between">
                    <div>
                       <div className="flex justify-between items-start mb-3 gap-2">
                          <span className={`px-2.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider ${
                             ticket.status === 'open' ? 'bg-danger-bg text-danger-text border border-danger-text/20' :
                             ticket.status === 'in_progress' ? 'bg-warning-bg text-warning-text border border-warning-text/20' :
                             ticket.status === 'resolved' ? 'bg-success-bg text-success-text border border-success-text/20' :
                             'bg-page text-text-2 border border-border'
                          }`}>
                             {ticket.status === 'open' ? 'Abierto' :
                              ticket.status === 'in_progress' ? 'En Proceso' :
                              ticket.status === 'resolved' ? 'Resuelto' : 'Cerrado'}
                          </span>
                          <span className={`px-2.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider ${
                             ticket.priority === 'high' ? 'bg-danger-bg text-danger-text' :
                             ticket.priority === 'medium' ? 'bg-warning-bg text-warning-text' :
                             'bg-page text-text-2'
                          }`}>
                             {ticket.priority === 'high' ? 'Alta' :
                              ticket.priority === 'medium' ? 'Media' : 'Baja'}
                          </span>
                       </div>
                       <h3 className="font-extrabold text-text-1 text-sm leading-tight line-clamp-1">{ticket.title}</h3>
                       <p className="text-xs text-text-3 mt-1.5 leading-relaxed line-clamp-2">{ticket.description}</p>

                       {ticket.tenant && (
                          <div className="mt-3.5 bg-page border border-border rounded-xl p-2.5 text-[11px] font-semibold text-text-2 flex justify-between items-center">
                             <span className="text-slate-400">Cliente:</span>
                             <span className="text-text-1 font-extrabold">{ticket.tenant.name}</span>
                          </div>
                       )}
                       {ticket.contact_name && (
                          <div className="mt-2 text-[10px] text-text-3 font-bold px-1 truncate">
                             Contacto: <span className="text-text-2">{ticket.contact_name}</span> {ticket.contact_email && <span className="text-slate-400 font-semibold">({ticket.contact_email})</span>}
                          </div>
                       )}
                    </div>
                    <div className="mt-5 pt-3.5 border-t border-border flex items-center justify-between">
                       <div className="text-[10px] text-slate-400 font-bold">
                          Creado {new Date(ticket.created_at).toLocaleDateString()}
                       </div>
                       <div className="flex gap-2">
                          <button
                             onClick={() => handleOpenTicketDetails(ticket.id)}
                             className="bg-navy-50 hover:bg-accent-soft text-accent text-xs font-black px-3.5 py-1.5 rounded-xl border border-border transition-colors"
                          >
                             Atender Ticket
                          </button>
                          {isAdmin && (
                             <button
                                onClick={() => handleDeleteTicket(ticket.id)}
                                className="text-danger-text hover:text-danger-text p-1.5 rounded-lg border border-transparent hover:bg-danger-bg transition-all"
                             >
                                <Trash2 size={14} />
                             </button>
                          )}
                       </div>
                    </div>
                 </div>
              ))}
            </div>
          )}
        </div>
      )}

    {/* MODAL: CONFIGURAR PRECIOS */}
    {isPricingModalOpen && (
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div className="bg-white rounded-3xl p-8 max-w-md w-full shadow-2xl">
          <h3 className="text-2xl font-black text-text-1 mb-6">Configurar Precios</h3>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-bold text-text-2 block mb-1">Portal de Vacantes (MXN/mes)</label>
              <input type="number" value={saasPricing.reclutamiento} onChange={(e) => updateSaaSPricing('reclutamiento', Number(e.target.value))} className="w-full bg-page border border-border rounded-xl px-4 py-2 font-bold" />
            </div>
            <div>
              <label className="text-sm font-bold text-text-2 block mb-1">Academia Interna (MXN/mes)</label>
              <input type="number" value={saasPricing.academia} onChange={(e) => updateSaaSPricing('academia', Number(e.target.value))} className="w-full bg-page border border-border rounded-xl px-4 py-2 font-bold" />
            </div>
            <div>
              <label className="text-sm font-bold text-text-2 block mb-1">Reportes Avanzados (MXN/mes)</label>
              <input type="number" value={saasPricing.reportes} onChange={(e) => updateSaaSPricing('reportes', Number(e.target.value))} className="w-full bg-page border border-border rounded-xl px-4 py-2 font-bold" />
            </div>
          </div>
          <button onClick={() => setIsPricingModalOpen(false)} className="w-full mt-8 bg-accent text-white font-black py-3 rounded-xl shadow-lg hover:bg-accent-hover transition-colors">Guardar y Cerrar</button>
        </div>
      </div>
    )}

    {/* MODAL: CONFIGURAR DATOS BANCARIOS (SPEI) */}
    {isBankConfigOpen && (
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 overflow-y-auto">
        <div className="bg-white rounded-3xl p-8 max-w-lg w-full shadow-2xl my-8">
          <div className="flex justify-between items-start mb-6">
            <div>
              <h3 className="text-2xl font-black text-text-1 flex items-center gap-2">
                <CreditCard className="text-success-text" size={24} />
                Configurar Datos Bancarios (SPEI)
              </h3>
              <p className="text-xs text-text-3 font-semibold mt-1">
                Establece la cuenta bancaria donde los clientes realizarán transferencias para pagar el servicio.
              </p>
            </div>
            <button
              onClick={() => setIsBankConfigOpen(false)}
              className="p-1.5 hover:bg-page rounded-lg text-slate-400 hover:text-text-2 transition-colors"
            >
              <X size={20} />
            </button>
          </div>

          <div className="space-y-4 pr-1">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-xs font-bold text-text-2 block mb-1.5">Banco Receptor</label>
                <input
                  type="text"
                  value={bankConfigData.bank_name}
                  onChange={(e) => setBankConfigData({...bankConfigData, bank_name: e.target.value})}
                  placeholder="Ej. BBVA Bancomer"
                  className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-semibold text-sm text-text-1 focus:outline-none focus:ring-2 focus-visible:ring-success-text focus:bg-white transition-all"
                />
              </div>
              <div>
                <label className="text-xs font-bold text-text-2 block mb-1.5">Titular de la Cuenta</label>
                <input
                  type="text"
                  value={bankConfigData.account_holder}
                  onChange={(e) => setBankConfigData({...bankConfigData, account_holder: e.target.value})}
                  placeholder="Ej. Talent 360 SA de CV"
                  className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-semibold text-sm text-text-1 focus:outline-none focus:ring-2 focus-visible:ring-success-text focus:bg-white transition-all"
                />
              </div>
            </div>

            <div>
              <label className="text-xs font-bold text-text-2 block mb-1.5">CLABE Interbancaria (18 dígitos)</label>
              <input
                type="text"
                maxLength={18}
                value={bankConfigData.clabe}
                onChange={(e) => setBankConfigData({...bankConfigData, clabe: e.target.value.replace(/\D/g, '').slice(0, 18)})}
                placeholder="Ej. 012180004512345678"
                className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-black text-sm text-text-1 tracking-wider focus:outline-none focus:ring-2 focus-visible:ring-success-text focus:bg-white transition-all"
              />
            </div>

            <div>
              <label className="text-xs font-bold text-text-2 block mb-1.5">Número de Tarjeta (16 dígitos, opcional)</label>
              <input
                type="text"
                maxLength={16}
                value={bankConfigData.card_number}
                onChange={(e) => setBankConfigData({...bankConfigData, card_number: e.target.value.replace(/\D/g, '').slice(0, 16)})}
                placeholder="Ej. 4152313412345678"
                className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-bold text-sm text-text-1 focus:outline-none focus:ring-2 focus-visible:ring-success-text focus:bg-white transition-all"
              />
            </div>

            <div>
              <label className="text-xs font-bold text-text-2 block mb-1.5">Instrucciones Especiales</label>
              <textarea
                rows={3}
                value={bankConfigData.instructions}
                onChange={(e) => setBankConfigData({...bankConfigData, instructions: e.target.value})}
                placeholder="Ej. Una vez hecha tu transferencia SPEI, reporta tu comprobante al correo facturacion@talent360.com.mx para la activación inmediata."
                className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-medium text-xs text-text-2 focus:outline-none focus:ring-2 focus-visible:ring-success-text focus:bg-white transition-all resize-none"
              />
            </div>

            <div className="flex items-center justify-between bg-page border border-border/60 p-4 rounded-2xl mt-4">
              <div>
                <span className="text-xs font-bold text-text-1 block">Habilitar en Checkout</span>
                <span className="text-[10px] text-text-3 block">Mostrar este método de transferencia como alternativa en la pasarela.</span>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={bankConfigData.is_active}
                  onChange={(e) => setBankConfigData({...bankConfigData, is_active: e.target.checked})}
                  className="sr-only peer"
                />
                <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-success-icon"></div>
              </label>
            </div>
          </div>

          <div className="flex gap-3 mt-8">
            <button
              onClick={() => setIsBankConfigOpen(false)}
              className="w-1/3 border border-border text-text-3 font-bold py-3 rounded-xl hover:bg-page transition-colors text-sm"
            >
              Cancelar
            </button>
            <button
              onClick={handleSaveBankConfig}
              disabled={isSavingBank}
              className="flex-1 bg-success-text text-white font-black py-3 rounded-xl shadow-lg shadow-success-text/10 hover:bg-success-text transition-colors text-sm flex items-center justify-center gap-1.5"
            >
              {isSavingBank ? 'Guardando...' : 'Guardar Configuración'}
            </button>
          </div>
        </div>
      </div>
    )}

    {/* MODAL: CONFIGURAR PLAN GRATUITO Y PRUEBA */}
    {isFreemiumConfigOpen && (
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 overflow-y-auto">
        <div className="bg-white rounded-3xl p-8 max-w-2xl w-full shadow-2xl my-8">
          <div className="flex justify-between items-start mb-6">
            <div>
              <h3 className="text-2xl font-black text-text-1 flex items-center gap-2">
                <Settings className="text-accent" size={24} />
                Plan Gratuito y Prueba Global
              </h3>
              <p className="text-xs text-text-3 font-semibold mt-1">Configura las limitaciones y el periodo de prueba para nuevas cuentas.</p>
            </div>
            <button
              onClick={() => setIsFreemiumConfigOpen(false)}
              className="p-1.5 hover:bg-page rounded-lg text-slate-400 hover:text-text-2 transition-colors"
            >
              <X size={20} />
            </button>
          </div>

          <div className="space-y-6 max-h-[60vh] overflow-y-auto pr-2 custom-scrollbar">
            {/* Sección 1: Días de Prueba */}
            <div className="bg-navy-50/50 border border-border/80 rounded-2xl p-4">
              <label className="text-sm font-bold text-text-1 block mb-1.5 flex items-center gap-1.5">
                <span>⏱</span> Días de Periodo de Prueba Global
              </label>
              <p className="text-[11px] text-text-3 font-medium mb-3">Establece la cantidad de días que las nuevas empresas registradas tendrán acceso ilimitado de prueba antes de bloquearse y degradarse a la versión gratuita básica.</p>
              <input
                type="number"
                min={0}
                value={globalTrialDays}
                onChange={(e) => setGlobalTrialDays(Math.max(0, parseInt(e.target.value) || 0))}
                className="w-32 bg-white border border-border rounded-xl px-4 py-2 font-black text-text-1 focus:outline-none focus:ring-2 focus-visible:ring-focus-ring"
              />
            </div>

            {/* Sección 2: Módulos del Sistema */}
            <div>
              <h4 className="text-sm font-black text-text-1 uppercase tracking-wider mb-3 pb-1 border-b border-border flex items-center gap-1.5">
                <span>📦</span> Módulos Incluidos en el Plan Gratuito
              </h4>
              <p className="text-[11px] text-text-3 font-semibold mb-4">Selecciona cuáles de los siguientes módulos principales serán totalmente gratuitos para siempre (Freemium):</p>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {[
                  { id: 'rrhh', label: 'Recursos Humanos', desc: 'Directorio y expediente básico' },
                  { id: 'reloj', label: 'Reloj Checador', desc: 'Control de turnos y checador básico' },
                  { id: 'operativo', label: 'Rutinas y Tareas', desc: 'Tickets y checklists de tareas' },
                  { id: 'ats', label: 'Reclutamiento ATS', desc: 'Embudo de selección y candidatos' },
                  { id: 'reportes', label: 'Reportes y Analítica', desc: 'BI, prenóminas e incidencias' },
                  { id: 'portal', label: 'Portal Web', desc: 'Bolsa de trabajo pública' },
                  { id: 'academia', label: 'Academia 360', desc: 'Capacitación y cursos LMS' },
                  { id: 'documentos', label: 'Documentos', desc: 'Expediente digital y políticas de empresa' },
                  { id: 'matrix', label: 'Matrix QA', desc: 'Entorno de simulación' },
                  { id: 'facturacion', label: 'Nómina CFDI 4.0', desc: 'Timbrado masivo del SAT' },
                  { id: 'lft', label: 'Ley Federal del Trabajo', desc: 'Reglamento y tolerancias' },
                  { id: 'organizacion', label: 'Organigrama y SOP', desc: 'Procesos, Puestos y Wiki' }
                ].map(mod => (
                  <label
                    key={mod.id}
                    className={`flex items-start gap-3 p-3.5 rounded-2xl border transition-all cursor-pointer select-none ${
                      freemiumModules.includes(mod.id)
                        ? 'border-accent bg-navy-50/30'
                        : 'border-border hover:border-slate-300 hover:bg-page/50'
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={freemiumModules.includes(mod.id)}
                      onChange={() => toggleFreemiumModule(mod.id)}
                      className="mt-0.5 rounded text-accent focus-visible:ring-focus-ring"
                    />
                    <div>
                      <span className="text-xs font-bold text-text-1 block">{mod.label}</span>
                      <span className="text-[10px] text-text-3 font-medium">{mod.desc}</span>
                    </div>
                  </label>
                ))}
              </div>
            </div>

            {/* Sección 3: Eventos y Funciones del Reloj Checador (Dialer) */}
            <div>
              <h4 className="text-sm font-black text-text-1 uppercase tracking-wider mb-3 pb-1 border-b border-border flex items-center gap-1.5">
                <span>🕒</span> Eventos y Funciones del Reloj Checador (Dialer)
              </h4>
              <p className="text-[11px] text-text-3 font-semibold mb-4">Selecciona qué características y eventos del Dialer estarán desbloqueados en la versión gratuita:</p>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
                {CLOCK_FEATURE_TAGS_MATRIX.map(tag => {
                  const isChecked = tag.isMandatory || freemiumFeatures.includes(tag.key);
                  return (
                    <label
                      key={tag.key}
                      className={`flex items-start gap-3 p-3.5 rounded-2xl border transition-all select-none ${
                        tag.isMandatory
                          ? 'border-success-text/20 bg-success-bg/40 cursor-not-allowed'
                          : isChecked
                          ? 'border-accent bg-navy-50/30 cursor-pointer'
                          : 'border-border hover:border-slate-300 hover:bg-page/50 cursor-pointer'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={isChecked}
                        disabled={tag.isMandatory}
                        onChange={() => !tag.isMandatory && toggleFreemiumFeature(tag.key)}
                        className="mt-0.5 rounded text-accent focus-visible:ring-focus-ring"
                      />
                      <div className="flex-1">
                        <div className="flex items-center justify-between">
                          <span className="text-xs font-bold text-text-1 block">{tag.name}</span>
                          {tag.isMandatory ? (
                            <span className="text-[9px] font-black px-1.5 py-0.5 bg-success-bg text-success-text rounded">Core</span>
                          ) : (
                            <span className="text-[9px] font-bold px-1.5 py-0.5 bg-page text-text-2 rounded uppercase">{tag.defaultTier}</span>
                          )}
                        </div>
                        <span className="text-[10px] text-text-3 font-medium block mt-0.5">{tag.description}</span>
                        <code className="text-[9px] text-slate-400 font-mono mt-1 inline-block">Key: {tag.key}</code>
                      </div>
                    </label>
                  );
                })}
              </div>
            </div>

            {/* Sección 4: Funciones Especiales Globales */}
            <div>
              <h4 className="text-sm font-black text-text-1 uppercase tracking-wider mb-3 pb-1 border-b border-border flex items-center gap-1.5">
                <span>⚡</span> Funciones Especiales Globales
              </h4>
              <p className="text-[11px] text-text-3 font-semibold mb-4">Activa funcionalidades globales que se considerarán libres de costo en la versión gratuita:</p>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {[
                  { id: 'voice_assistant', label: 'Asistente de Voz AI', desc: 'Creación de tareas mediante comandos de voz AI' },
                  { id: 'routines_management', label: 'Gestión de Rutinas', desc: 'Organización de tareas recurrentes en checklists' },
                  { id: 'supervisor_validation', label: 'Aprobación de Tareas', desc: 'Exigir validación del supervisor' },
                  { id: 'gps_validation', label: 'Validación GPS de Checadas', desc: 'Restricción de ubicación geográfica al checar' },
                  { id: 'face_validation', label: 'Selfie Checador', desc: 'Checar obligatoriamente con selfie' },
                  { id: 'system_backups', label: 'Respaldos JSON', desc: 'Exportación de la BD de empresa' },
                  { id: 'custom_logo', label: 'Logotipo Personalizado', desc: 'Establecer logotipo propio del workspace' }
                ].map(feat => (
                  <label
                    key={feat.id}
                    className={`flex items-start gap-3 p-3.5 rounded-2xl border transition-all cursor-pointer select-none ${
                      freemiumFeatures.includes(feat.id)
                        ? 'border-accent bg-navy-50/30'
                        : 'border-border hover:border-slate-300 hover:bg-page/50'
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={freemiumFeatures.includes(feat.id)}
                      onChange={() => toggleFreemiumFeature(feat.id)}
                      className="mt-0.5 rounded text-accent focus-visible:ring-focus-ring"
                    />
                    <div>
                      <span className="text-xs font-bold text-text-1 block">{feat.label}</span>
                      <span className="text-[10px] text-text-3 font-medium">{feat.desc}</span>
                    </div>
                  </label>
                ))}
              </div>
            </div>
          </div>

          <div className="flex gap-3 mt-8 border-t border-border pt-6">
            <button
              onClick={() => setIsFreemiumConfigOpen(false)}
              className="flex-1 py-3 rounded-xl font-bold text-text-2 bg-page hover:bg-slate-200 transition-colors text-sm"
            >
              Cancelar
            </button>
            <button
              onClick={handleSaveFreemiumConfig}
              disabled={isSavingFreemium}
              className="flex-1 py-3 rounded-xl font-black text-white bg-accent hover:bg-accent-hover shadow-lg shadow-accent/25 transition-all text-sm flex items-center justify-center gap-2"
            >
              {isSavingFreemium ? (
                <>
                  <Loader2 className="animate-spin" size={16} />
                  Guardando...
                </>
              ) : (
                'Guardar Configuración'
              )}
            </button>
          </div>
        </div>
      </div>
    )}

    {/* MODAL: CONFIGURAR SIMULADOR DE LANDING PAGE */}
    {isSimulatorConfigOpen && (
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 overflow-y-auto">
        <div className="bg-white rounded-3xl p-8 max-w-md w-full shadow-2xl my-8">
          <div className="flex justify-between items-start mb-6">
            <div>
              <h3 className="text-xl font-black text-text-1 flex items-center gap-2">
                <Monitor className="text-accent" size={22} />
                Ajustes del Simulador Landing
              </h3>
              <p className="text-xs text-text-3 font-semibold mt-1">Configura las dimensiones y contenidos de la simulación en la página de inicio.</p>
            </div>
            <button
              onClick={() => setIsSimulatorConfigOpen(false)}
              className="p-1.5 hover:bg-page rounded-lg text-slate-400 hover:text-text-2 transition-colors border-none bg-transparent cursor-pointer"
            >
              <X size={20} />
            </button>
          </div>

          <div className="space-y-4 text-left">
            <div>
              <label className="text-xs font-bold text-text-2 block mb-1.5">Escala del Reloj (Porcentaje)</label>
              <div className="flex items-center gap-2">
                <input
                  type="range"
                  min="50"
                  max="150"
                  value={simulatorConfig.scale}
                  onChange={(e) => setSimulatorConfig({...simulatorConfig, scale: parseInt(e.target.value)})}
                  className="flex-1 accent-focus-ring cursor-pointer"
                />
                <span className="text-xs font-black text-text-2 w-10 text-right">{simulatorConfig.scale}%</span>
              </div>
              <p className="text-[10px] text-slate-400 mt-1 font-medium">Permite reducir o agrandar el smartphone del simulador en la landing page para que encaje mejor.</p>
            </div>

            <div>
              <label className="text-xs font-bold text-text-2 block mb-1.5">Nombre del Colaborador</label>
              <input
                type="text"
                value={simulatorConfig.emp_name}
                onChange={(e) => setSimulatorConfig({...simulatorConfig, emp_name: e.target.value})}
                placeholder="Francisco Vega"
                className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-medium text-xs text-text-2 focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:bg-white transition-all"
              />
              <p className="text-[10px] text-slate-400 mt-1 font-medium">El nombre ficticio del empleado que se mostrará en el simulador.</p>
            </div>

            <div>
              <label className="text-xs font-bold text-text-2 block mb-1.5">Nombre de la Sucursal</label>
              <input
                type="text"
                value={simulatorConfig.store_name}
                onChange={(e) => setSimulatorConfig({...simulatorConfig, store_name: e.target.value})}
                placeholder="Decorarte 365"
                className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-medium text-xs text-text-2 focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:bg-white transition-all"
              />
              <p className="text-[10px] text-slate-400 mt-1 font-medium">El nombre de la sucursal ficticia que se mostrará en el simulador.</p>
            </div>
          </div>

          <div className="flex gap-3 mt-8 border-t border-border pt-6">
            <button
              onClick={() => setIsSimulatorConfigOpen(false)}
              className="flex-1 py-3 rounded-xl font-bold text-text-2 bg-page hover:bg-slate-200 transition-colors text-sm border-none cursor-pointer"
            >
              Cancelar
            </button>
            <button
              onClick={handleSaveSimulatorConfig}
              disabled={isSavingSimulator}
              className="flex-1 py-3 rounded-xl font-black text-white bg-accent hover:bg-accent-hover shadow-lg shadow-accent/25 transition-all text-sm flex items-center justify-center gap-2 border-none cursor-pointer"
            >
              {isSavingSimulator ? (
                <>
                  <Loader2 className="animate-spin" size={16} />
                  Guardando...
                </>
              ) : (
                'Guardar Ajustes'
              )}
            </button>
          </div>
        </div>
      </div>
    )}

    {/* MODAL: ALTA DE EMPRESA DE PRUEBA */}
    {isNewTenantModalOpen && (
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div className="bg-white rounded-3xl p-8 max-w-md w-full shadow-2xl animate-in fade-in zoom-in-95 duration-200">
          {createdTenantData ? (
            <div className="text-center">
              <div className="w-12 h-12 bg-success-bg text-success-text rounded-full flex items-center justify-center mx-auto mb-4 border border-success-text/20">
                <ShieldCheck size={24} className="text-success-text" />
              </div>
              <h3 className="text-2xl font-black text-text-1 mb-2">¡Empresa Creada!</h3>
              <p className="text-sm text-text-3 mb-6">Guarda estas credenciales: <span className="font-bold text-text-2">la contraseña sólo se muestra aquí</span> y no se puede volver a consultar (si se pierde, se resetea desde la ficha de la empresa).</p>

              <div className="bg-page border border-border rounded-2xl p-4 text-left space-y-3 mb-6 text-sm">
                <div>
                  <span className="block text-xs font-bold text-slate-400 uppercase">Empresa</span>
                  <span className="font-bold text-text-1">{createdTenantData.tenant.name}</span>
                </div>
                <div>
                  <span className="block text-xs font-bold text-slate-400 uppercase">Subdominio / Slug</span>
                  <span className="font-mono text-xs font-bold text-accent bg-navy-50 px-2 py-1 rounded">{createdTenantData.tenant.subdomain}</span>
                </div>
                <div>
                  <span className="block text-xs font-bold text-slate-400 uppercase">Usuario Administrador</span>
                  <span className="font-bold text-text-1">{createdTenantData.user.email}</span>
                </div>
                <div>
                  <span className="block text-xs font-bold text-slate-400 uppercase">Contraseña</span>
                  <span className="font-mono font-bold text-text-1">{createdTenantData.password}</span>
                </div>
              </div>

              <button
                onClick={() => {
                  setIsNewTenantModalOpen(false);
                  setCreatedTenantData(null);
                  setNewTenantName('');
                }}
                className="w-full bg-slate-900 text-white font-black py-3 rounded-xl hover:bg-slate-800 transition-colors"
              >
                Entendido
              </button>
            </div>
          ) : (
            <>
              <h3 className="text-2xl font-black text-text-1 mb-6">Simular Nueva Empresa</h3>
              <div className="space-y-4">
                <div>
                  <label className="text-sm font-bold text-text-2 block mb-1">Nombre de la Empresa</label>
                  <input type="text" value={newTenantName} onChange={(e) => setNewTenantName(e.target.value)} placeholder="Ej. Constructora del Norte" className="w-full bg-page border border-border rounded-xl px-4 py-2 font-bold text-text-1" />
                </div>
                <div>
                  <label className="text-sm font-bold text-text-2 block mb-1">Plan a Contratar</label>
                  <select value={newTenantPlan} onChange={(e) => setNewTenantPlan(e.target.value)} className="w-full bg-page border border-border rounded-xl px-4 py-2 font-bold text-text-1">
                    <option value="Freemium">Freemium (Gratis)</option>
                    <option value="PRO">PRO</option>
                    <option value="Enterprise">Enterprise</option>
                  </select>
                </div>
              </div>
              <div className="flex gap-4 mt-8">
                <button onClick={() => { setIsNewTenantModalOpen(false); setNewTenantName(''); }} className="flex-1 bg-page text-text-2 font-black py-3 rounded-xl hover:bg-slate-200 transition-colors">Cancelar</button>
                <button onClick={handleCreateTenant} disabled={isLoading} className="flex-1 bg-accent text-white font-black py-3 rounded-xl shadow-lg hover:bg-accent-hover transition-colors flex items-center justify-center gap-2">
                  {isLoading ? 'Creando...' : 'Crear Inquilino'}
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    )}

    {/* MODAL: DETALLES DE INQUILINO (SLIDE-OVER LATERAL DESDE LA DERECHA) */}
    {isDetailOpen && (
      <div className="fixed inset-0 z-50 overflow-hidden">
        {/* Backdrop */}
        <div
          className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
          onClick={() => setIsDetailOpen(false)}
        />

        <div className="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-0 sm:pl-10">
          <div className="pointer-events-auto w-screen max-w-full sm:max-w-xl md:max-w-2xl transform bg-white shadow-2xl transition-all duration-300 ease-in-out border-l border-border flex flex-col h-full animate-in slide-in-from-right duration-300">
            {/* Header del Slide-over */}
            <div className="bg-slate-900 px-6 py-6 text-white flex items-center justify-between shadow-md">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-slate-800 rounded-xl border border-slate-700">
                  <Building2 size={24} className="text-navy-300" />
                </div>
                <div>
                  <h2 className="text-lg font-black leading-tight truncate max-w-[280px]">
                    {isDetailLoading ? 'Cargando...' : tenantDetail?.tenant?.name}
                  </h2>
                  <p className="text-xs text-slate-400 font-medium">
                    {isDetailLoading ? '' : `ID: ${tenantDetail?.tenant?.subdomain}`}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2">
                {!isDetailLoading && (
                  <button
                    onClick={() => setIsEditing(!isEditing)}
                    title={isEditing ? "Ver Detalles" : "Editar Datos"}
                    className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition-colors"
                  >
                    <Settings size={18} className={isEditing ? "text-navy-300 animate-spin-slow" : ""} />
                  </button>
                )}
                <button
                  onClick={() => setIsDetailOpen(false)}
                  className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition-colors"
                >
                  <X size={20} />
                </button>
              </div>
            </div>

            {/* Contenido */}
            <div className="flex-1 overflow-y-auto p-6 space-y-6">
              {isDetailLoading ? (
                <div className="h-full flex flex-col items-center justify-center text-slate-400">
                  <Loader2 className="animate-spin mb-3 text-accent" size={32} />
                  <p className="text-sm font-bold">Cargando información del inquilino...</p>
                </div>
              ) : (
                <>
                  {isEditing ? (
                    <div className="space-y-5 animate-in fade-in duration-200">
                      {/* Formulario de Edición */}
                      <div className="bg-page border border-border rounded-2xl p-5 space-y-4">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Datos de la Empresa</h3>

                        <div>
                          <label className="block text-[10px] font-black text-text-3 uppercase mb-1">Nombre de la Empresa</label>
                          <input
                            type="text"
                            value={editTenantName}
                            onChange={(e) => setEditTenantName(e.target.value)}
                            className="w-full bg-white border border-border rounded-xl px-3 py-2 text-sm font-bold outline-none text-text-1 focus:border-accent transition-colors"
                          />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-[10px] font-black text-text-3 uppercase mb-1">Plan</label>
                            <select
                              value={editTenantPlan}
                              onChange={(e) => setEditTenantPlan(e.target.value)}
                              className="w-full bg-white border border-border rounded-xl px-3 py-2 text-sm font-bold outline-none text-text-1 focus:border-accent transition-colors"
                            >
                              <option value="freemium">Freemium</option>
                              <option value="pro">Pro</option>
                              <option value="enterprise">Enterprise</option>
                            </select>
                          </div>
                          <div>
                            <label className="block text-[10px] font-black text-text-3 uppercase mb-1">Límite Usuarios</label>
                            <input
                              type="number"
                              value={editMaxUsers}
                              onChange={(e) => setEditMaxUsers(Number(e.target.value))}
                              className="w-full bg-white border border-border rounded-xl px-3 py-2 text-sm font-bold outline-none text-text-1 focus:border-accent transition-colors"
                            />
                          </div>
                        </div>
                      </div>

                      <div className="bg-page border border-border rounded-2xl p-5 space-y-4">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Datos del Administrador</h3>

                        <div>
                          <label className="block text-[10px] font-black text-text-3 uppercase mb-1">Nombre Completo</label>
                          <input
                            type="text"
                            value={editAdminName}
                            onChange={(e) => setEditAdminName(e.target.value)}
                            className="w-full bg-white border border-border rounded-xl px-3 py-2 text-sm font-bold outline-none text-text-1 focus:border-accent transition-colors"
                          />
                        </div>

                        <div>
                           <label className="block text-[10px] font-black text-text-3 uppercase mb-1">Correo de Acceso</label>
                           <input
                             type="email"
                             value={editAdminEmail}
                             onChange={(e) => setEditAdminEmail(e.target.value)}
                             className="w-full bg-white border border-border rounded-xl px-3 py-2 text-sm font-bold outline-none text-text-1 focus:border-accent transition-colors focus:ring-0"
                           />
                         </div>

                         <div>
                           <label className="block text-[10px] font-black text-text-3 uppercase mb-1">Teléfono WhatsApp</label>
                           <div className="flex border border-border rounded-xl overflow-hidden focus-within:ring-1 focus-within:ring-focus-ring focus-within:border-accent bg-white">
                             <div className="bg-page px-3 py-2 text-xs text-text-3 font-bold border-r border-border flex items-center gap-1.5 select-none">
                               <span>🇲🇽</span>
                               <span>+52</span>
                             </div>
                             <input
                               type="text"
                               value={formatPhoneVisual(editAdminPhone)}
                               onChange={(e) => setEditAdminPhone(getCleanDbPhone(e.target.value))}
                               className="w-full px-3 py-2 text-sm font-bold outline-none text-text-1 font-mono"
                               placeholder="10 dígitos (ej: 55 1234 5678)"
                             />
                           </div>
                         </div>

                        <div className="border-t border-border pt-4 mt-2">
                          <label className="block text-[10px] font-black text-text-3 uppercase mb-1">Contraseña Temporal (Opcional)</label>
                          <div className="flex gap-2">
                            <input
                              type="text"
                              value={editAdminPassword}
                              onChange={(e) => setEditAdminPassword(e.target.value)}
                              placeholder="Dejar vacío para mantener actual"
                              className="flex-1 bg-white border border-border rounded-xl px-3 py-2 text-xs font-bold outline-none text-text-1 focus:border-accent transition-colors"
                            />
                            <button
                              type="button"
                              onClick={generateTemporaryPassword}
                              className="bg-navy-50 hover:bg-accent-soft text-accent text-xs font-black px-3 py-2 rounded-xl transition-colors border border-border"
                            >
                              Generar
                            </button>
                          </div>
                        </div>
                      </div>

                      <div className="flex gap-3 pt-4">
                        <button
                          onClick={() => setIsEditing(false)}
                          className="flex-1 bg-page text-text-2 font-bold py-3 rounded-xl hover:bg-slate-200 transition-colors text-xs"
                        >
                          Cancelar
                        </button>
                        <button
                          onClick={handleSaveTenantEdit}
                          disabled={isSavingEdit}
                          className="flex-1 bg-accent text-white font-black py-3 rounded-xl shadow-lg shadow-accent/10 hover:bg-accent-hover transition-colors text-xs flex items-center justify-center gap-1.5"
                        >
                          {isSavingEdit ? 'Guardando...' : 'Guardar Cambios'}
                        </button>
                      </div>
                    </div>
                  ) : (
                    <>
                      {/* Banner de Suspensión si no está activo */}
                      {!tenantDetail?.tenant?.is_active && (
                        <div className="p-4 bg-danger-bg border border-danger-text/20 rounded-2xl text-danger-text flex items-start gap-3">
                          <ShieldX size={20} className="shrink-0 text-danger-text mt-0.5" />
                          <div>
                            <p className="text-sm font-extrabold leading-tight">Empresa Suspendida</p>
                            <p className="text-xs mt-1 text-danger-text/90 font-bold">
                              Motivo: <span className="underline">{tenantDetail?.tenant?.suspension_reason || 'No especificado'}</span>
                            </p>
                            {tenantDetail?.tenant?.suspended_at && (
                              <p className="text-[10px] text-danger-text mt-2 font-medium">
                                Suspendida el {new Date(tenantDetail.tenant.suspended_at).toLocaleString()}
                              </p>
                            )}
                          </div>
                        </div>
                      )}

                      {/* General Info Card */}
                      <div className="bg-page border border-border rounded-2xl p-5 space-y-3.5">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Información del Plan</h3>
                        <div className="flex justify-between items-center text-sm">
                          <span className="font-semibold text-text-3">Plan Contratado:</span>
                          <span className={`px-2.5 py-1 rounded-md text-xs font-black ${
                            tenantDetail?.tenant?.plan?.toLowerCase() === 'pro' ? 'bg-warning-bg text-warning-text border border-warning-text/20' :
                            tenantDetail?.tenant?.plan?.toLowerCase() === 'enterprise' ? 'bg-accent-soft text-accent border border-border' :
                            'bg-page text-text-2 border border-border'
                          }`}>
                            {tenantDetail?.tenant?.plan}
                          </span>
                        </div>
                        <div className="flex justify-between items-center text-sm">
                          <span className="font-semibold text-text-3">Costo Mensual Actual:</span>
                          <span className="font-black text-accent bg-navy-50 border border-border px-2.5 py-1 rounded-lg text-sm">
                            ${tenantDetail?.tenant?.monthly_price ?? 0} <span className="text-[10px] text-text-3 font-semibold">/mes</span>
                          </span>
                        </div>
                        <div className="flex justify-between items-center text-sm">
                          <span className="font-semibold text-text-3">Estado de Facturación:</span>
                          <span className={`px-2 py-0.5 rounded text-xs font-bold border ${estadoDeCobranza(tenantDetail?.tenant).clases}`}>
                            {estadoDeCobranza(tenantDetail?.tenant).etiqueta}
                          </span>
                        </div>
                        {estadoDeCobranza(tenantDetail?.tenant).detalle && (
                          <p className="text-[11px] text-text-3 font-medium -mt-1.5">
                            {estadoDeCobranza(tenantDetail?.tenant).detalle}
                          </p>
                        )}
                        {tenantDetail?.tenant?.trial_ends_at && (
                          <div className="flex justify-between items-center text-sm border-t border-border/50 pt-2">
                            <span className="font-semibold text-text-3">Periodo de Prueba Finaliza:</span>
                            <span className="font-bold text-text-2">{new Date(tenantDetail.tenant.trial_ends_at).toLocaleDateString()}</span>
                          </div>
                        )}
                        <div className="flex justify-between items-center text-sm border-t border-border/50 pt-2">
                          <span className="font-semibold text-text-3">Próximo Cobro / Fin Ciclo:</span>
                          {tenantDetail?.tenant?.current_period_end ? (
                            <span className="font-bold text-text-2">{new Date(tenantDetail.tenant.current_period_end).toLocaleDateString()}</span>
                          ) : (
                            // Callarlo era peor que decirlo: sin fecha de corte, el barrido de mora
                            // (suscripciones:revisar-vencidas) no revisa a esta empresa nunca.
                            <span className="font-bold text-slate-400">Sin fecha de corte</span>
                          )}
                        </div>
                        {tenantDetail?.tenant?.payment_warning_sent_at && (
                          <div className="flex justify-between items-center text-sm border-t border-border/50 pt-2">
                            <span className="font-semibold text-text-3">Aviso de mora registrado:</span>
                            <span className="font-bold text-warning-text">{new Date(tenantDetail.tenant.payment_warning_sent_at).toLocaleDateString()}</span>
                          </div>
                        )}
                      </div>

                      {/* Historial de Suscripciones y Evolución de Plan (Snapshotting Inmutable) */}
                      <div className="border border-border bg-navy-50/10 rounded-2xl p-5 space-y-4">
                        <div className="flex items-center justify-between">
                          <h3 className="text-xs font-black uppercase tracking-wider text-brand-dark flex items-center gap-1.5">
                            <span>📈</span> Historial y Evolución de Plan
                          </h3>
                          <span className="text-[10px] font-black text-accent bg-accent-soft px-2 py-0.5 rounded">
                            {tenantDetail?.subscription_history?.length || 0} registros
                          </span>
                        </div>
                        <p className="text-[11px] text-text-3 font-medium">
                          Registro inmutable de la evolución de la empresa, pagos acordados y módulos contratados a lo largo del tiempo:
                        </p>

                        <div className="space-y-3 relative before:absolute before:left-3 before:top-2 before:bottom-2 before:w-0.5 before:bg-accent-soft">
                          {(tenantDetail?.subscription_history || []).map((hist: any, index: number) => (
                            <div key={hist.id || index} className="relative pl-7 text-xs">
                              <div className={`absolute left-1.5 top-1.5 w-3 h-3 rounded-full border-2 bg-white ${
                                hist.status === 'active' ? 'border-success-text bg-success-icon' : 'border-navy-300'
                              }`} />
                              <div className="bg-white border border-border rounded-xl p-3 shadow-xs space-y-1.5">
                                <div className="flex items-center justify-between">
                                  <div className="flex items-center gap-1.5">
                                    <span className="font-black text-text-1">{hist.plan_name}</span>
                                    <span className="font-extrabold text-accent bg-navy-50 px-2 py-0.5 rounded text-[10px]">
                                      ${hist.monthly_price} /mes
                                    </span>
                                  </div>
                                  <span className={`text-[9px] font-black uppercase px-2 py-0.5 rounded-full ${
                                    hist.status === 'active' ? 'bg-success-bg text-success-text' : 'bg-page text-text-3'
                                  }`}>
                                    {hist.status === 'active' ? 'Vigente Actual' : 'Histórico'}
                                  </span>
                                </div>
                                <div className="flex flex-wrap gap-2 text-[11px] text-text-2">
                                  <span>📦 <strong>{hist.modules_count}</strong> Módulos</span>
                                  <span>•</span>
                                  <span>👥 <strong>{hist.max_users}</strong> Usuarios Max</span>
                                  <span>•</span>
                                  <span className="text-slate-400 font-mono text-[10px]">{hist.date_formatted}</span>
                                </div>
                                {hist.change_reason && (
                                  <div className="text-[10px] text-text-3 italic bg-page p-1.5 rounded border border-border">
                                    Motivo: {hist.change_reason}
                                  </div>
                                )}
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>

                      {/* Consumo y Recursos */}
                      <div className="border border-border rounded-2xl p-5 space-y-4">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Consumo de Recursos y Transacciones BD</h3>

                        {/* Barra de usuarios */}
                        <div>
                          <div className="flex justify-between text-xs font-bold mb-1.5 text-text-2">
                            <span>Usuarios Creados</span>
                            <span>{tenantDetail?.metrics?.users_count} / {tenantDetail?.tenant?.max_users}</span>
                          </div>
                          <div className="w-full bg-page rounded-full h-2">
                            <div
                              className={`h-2 rounded-full transition-all duration-500 ${
                                (tenantDetail?.metrics?.users_count / tenantDetail?.tenant?.max_users) >= 0.9
                                  ? 'bg-danger-icon'
                                  : (tenantDetail?.metrics?.users_count / tenantDetail?.tenant?.max_users) >= 0.7
                                  ? 'bg-warning-icon'
                                  : 'bg-accent'
                              }`}
                              style={{ width: `${Math.min(100, (tenantDetail?.metrics?.users_count / tenantDetail?.tenant?.max_users) * 100)}%` }}
                            ></div>
                          </div>
                        </div>

                        {/* Volumen de Transacciones */}
                        <div className="bg-page border border-border rounded-xl p-3.5 space-y-2">
                          <div className="flex justify-between items-center text-xs">
                            <span className="font-extrabold text-text-2 flex items-center gap-1">
                              ⚡ Throughput BD (Últimos 30 días):
                            </span>
                            <span className="font-black text-success-text bg-success-bg border border-success-text/20 px-2 py-0.5 rounded-md">
                              {tenantDetail?.metrics?.tx_daily_avg ?? 0} Tx / día
                            </span>
                          </div>
                          <div className="text-[11px] text-text-3 flex justify-between font-medium">
                            <span>Total 30 Días: <strong>{tenantDetail?.metrics?.tx_30_days ?? 0}</strong> operaciones</span>
                            <span>Histórico Total: <strong>{tenantDetail?.metrics?.tx_total ?? 0}</strong> operaciones</span>
                          </div>
                        </div>

                        {/* Vacantes */}
                        <div className="flex justify-between items-center text-sm border-t border-border pt-3">
                          <span className="font-semibold text-text-3">Vacantes Publicadas:</span>
                          <span className="font-black text-text-1 bg-page px-3 py-1 rounded-lg border border-border">{tenantDetail?.metrics?.vacancies_count}</span>
                        </div>
                      </div>

                      {/* Módulos y Funciones Habilitadas (Tenant Overrides) */}
                      <div className="border border-border bg-navy-50/20 rounded-2xl p-5 space-y-4">
                        <div className="flex items-center justify-between">
                          <h3 className="text-xs font-black uppercase tracking-wider text-brand-dark flex items-center gap-1.5">
                            <span>🎛️</span> Módulos y Funciones Habilitadas
                          </h3>
                          <span className="text-[9px] font-black text-accent bg-accent-soft px-2 py-0.5 rounded uppercase">
                            Empresa #{tenantDetail?.tenant?.id}
                          </span>
                        </div>
                        <p className="text-[11px] text-text-3 font-medium">
                          Personaliza los módulos y las funciones contratadas o permitidas específicamente para esta empresa:
                        </p>

                        {/* Módulos Principales */}
                        <div className="space-y-2">
                          <h4 className="text-[11px] font-extrabold text-text-2 uppercase tracking-wide">📦 Módulos de Sistema</h4>
                          <div className="grid grid-cols-2 gap-2">
                            {[
                              { id: 'rrhh', label: 'Recursos Humanos' },
                              { id: 'reloj', label: 'Reloj Checador' },
                              { id: 'operativo', label: 'Rutinas y Tareas' },
                              { id: 'ats', label: 'Reclutamiento ATS' },
                              { id: 'reportes', label: 'Reportes y Analítica' },
                              { id: 'portal', label: 'Portal Web' },
                              { id: 'academia', label: 'Academia 360' },
                              { id: 'documentos', label: 'Gestor Documental' },
                              { id: 'matrix', label: 'Matrix QA (Simulador)' },
                              { id: 'facturacion', label: 'Nómina CFDI 4.0' },
                              { id: 'lft', label: 'Ley Federal del Trabajo' },
                              { id: 'organizacion', label: 'Organigrama y SOP' }
                            ].map(mod => (
                              <label key={mod.id} className="flex items-center gap-2 p-2 bg-white border border-border rounded-xl cursor-pointer text-xs font-bold text-text-1 hover:bg-page">
                                <input
                                  type="checkbox"
                                  checked={tenantAllowedModules.includes(mod.id)}
                                  onChange={() => toggleTenantModule(mod.id)}
                                  className="rounded text-accent focus-visible:ring-focus-ring"
                                />
                                <span className="truncate">{mod.label}</span>
                              </label>
                            ))}
                          </div>
                        </div>

                        {/* Eventos del Reloj Checador (Dialer) */}
                        <div className="space-y-2 pt-2 border-t border-border">
                          <h4 className="text-[11px] font-extrabold text-text-2 uppercase tracking-wide">🕒 Funciones del Dialer (Reloj)</h4>
                          <div className="grid grid-cols-1 gap-2 max-h-52 overflow-y-auto pr-1 custom-scrollbar">
                            {CLOCK_FEATURE_TAGS_MATRIX.map(tag => {
                              const isChecked = tag.isMandatory || tenantAllowedFeatures.includes(tag.key);
                              return (
                                <label key={tag.key} className={`flex items-start gap-2 p-2 bg-white border rounded-xl text-xs select-none ${tag.isMandatory ? 'border-success-text/20 bg-success-bg/30' : isChecked ? 'border-navy-300 bg-navy-50/40 cursor-pointer' : 'border-border hover:bg-page cursor-pointer'}`}>
                                  <input
                                    type="checkbox"
                                    checked={isChecked}
                                    disabled={tag.isMandatory}
                                    onChange={() => !tag.isMandatory && toggleTenantFeature(tag.key)}
                                    className="mt-0.5 rounded text-accent focus-visible:ring-focus-ring"
                                  />
                                  <div className="flex-1 min-w-0">
                                    <div className="flex items-center justify-between">
                                      <span className="font-bold text-text-1 truncate">{tag.name}</span>
                                      <span className="text-[8px] font-black px-1.5 py-0.2 bg-page text-text-2 rounded uppercase">{tag.defaultTier}</span>
                                    </div>
                                    <p className="text-[10px] text-slate-400 font-normal leading-tight line-clamp-1">{tag.description}</p>
                                  </div>
                                </label>
                              );
                            })}
                          </div>
                        </div>

                        {/* Funciones Especiales Globales */}
                        <div className="space-y-2 pt-2 border-t border-border">
                          <h4 className="text-[11px] font-extrabold text-text-2 uppercase tracking-wide">⚡ Funciones Especiales</h4>
                          <div className="grid grid-cols-2 gap-2">
                            {[
                              { id: 'voice_assistant', label: 'Asistente Voz AI' },
                              { id: 'routines_management', label: 'Gestión Rutinas' },
                              { id: 'supervisor_validation', label: 'Aprobación Tareas' },
                              { id: 'gps_validation', label: 'Validación GPS' },
                              { id: 'face_validation', label: 'Selfie Checador' },
                              { id: 'system_backups', label: 'Respaldos JSON' },
                              { id: 'custom_logo', label: 'Logo Propio' }
                            ].map(feat => (
                              <label key={feat.id} className="flex items-center gap-2 p-2 bg-white border border-border rounded-xl cursor-pointer text-xs font-bold text-text-1 hover:bg-page">
                                <input
                                  type="checkbox"
                                  checked={tenantAllowedFeatures.includes(feat.id)}
                                  onChange={() => toggleTenantFeature(feat.id)}
                                  className="rounded text-accent focus-visible:ring-focus-ring"
                                />
                                <span className="truncate">{feat.label}</span>
                              </label>
                            ))}
                          </div>
                        </div>

                        {/* Botón de Guardado para la Empresa */}
                        <button
                          onClick={handleSaveTenantFeatures}
                          disabled={isSavingTenantFeatures}
                          className="w-full mt-3 bg-accent hover:bg-accent-hover text-white font-black py-2.5 rounded-xl shadow-md transition-all text-xs flex items-center justify-center gap-2 cursor-pointer border-none"
                        >
                          {isSavingTenantFeatures ? (
                            <>
                              <Loader2 className="animate-spin" size={14} />
                              Guardando...
                            </>
                          ) : (
                            'Guardar Permisos de Empresa'
                          )}
                        </button>
                      </div>

                      {/* Accesos Administrativos */}
                      <div className="border border-border rounded-2xl p-5 space-y-4">
                        <div className="flex items-center justify-between">
                          <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Acceso Administrador</h3>
                          <span className="text-[10px] font-black text-accent bg-navy-50 px-2 py-0.5 rounded">Owner</span>
                        </div>
                        {tenantDetail?.admin ? (
                          <div className="space-y-3">
                            <div className="text-sm">
                              <span className="block text-[10px] font-bold text-slate-400 uppercase">Nombre Completo</span>
                              <span className="font-extrabold text-text-1">{tenantDetail.admin.name}</span>
                            </div>
                            <div className="text-sm">
                              <span className="block text-[10px] font-bold text-slate-400 uppercase">Correo Electrónico</span>
                              <span className="font-mono font-bold text-accent bg-navy-50 px-2 py-1 rounded select-all break-all">{tenantDetail.admin.email}</span>
                            </div>
                            <div className="text-sm">
                              <span className="block text-[10px] font-bold text-slate-400 uppercase">Teléfono WhatsApp</span>
                              <span className="font-mono font-bold text-accent bg-navy-50 px-2 py-1 rounded select-all break-all">{tenantDetail.admin.phone || 'No registrado'}</span>
                            </div>

                            {/* Modificar Contraseña */}
                            <div className="border-t border-border pt-3 mt-2">
                              {!isResetFormVisible ? (
                                <button
                                  onClick={() => setIsResetFormVisible(true)}
                                  className="text-xs font-bold text-accent hover:text-navy-800 hover:underline flex items-center gap-1"
                                >
                                  <KeyRound size={12} />
                                  Cambiar / Restablecer Contraseña
                                </button>
                              ) : (
                                <div className="space-y-3 p-3 bg-page border border-border rounded-xl animate-in fade-in duration-200">
                                  <label className="block text-[10px] font-black text-text-3 uppercase">Nueva Contraseña</label>
                                  <div className="flex gap-2">
                                    <input
                                      type="text"
                                      value={newPassword}
                                      onChange={(e) => setNewPassword(e.target.value)}
                                      placeholder="Min. 6 caracteres"
                                      className="flex-1 bg-white border border-border rounded-lg px-3 py-1.5 text-xs font-bold outline-none text-text-1"
                                    />
                                    <button
                                      onClick={handleResetPassword}
                                      disabled={isResetting || !newPassword.trim()}
                                      className="bg-accent hover:bg-accent-hover text-white text-xs font-bold px-3 py-1.5 rounded-lg disabled:opacity-50 transition-colors"
                                    >
                                      {isResetting ? 'Guardando...' : 'Aplicar'}
                                    </button>
                                    <button
                                      onClick={() => { setIsResetFormVisible(false); setNewPassword(''); }}
                                      className="text-slate-400 hover:text-text-2 p-1 text-xs"
                                    >
                                      Cancelar
                                    </button>
                                  </div>
                                </div>
                              )}
                            </div>
                          </div>
                        ) : (
                          <p className="text-xs text-danger-text font-bold bg-danger-bg p-3 rounded-xl border border-danger-text/20">Advertencia: No se encontró ningún usuario administrador asignado a esta empresa.</p>
                        )}
                      </div>

                      {/* Acciones del Slide-over */}
                      <div className="grid grid-cols-2 gap-3 border-t border-border pt-6">
                        <button
                          onClick={() => handleImpersonate(tenantDetail.tenant.id)}
                          className="w-full flex items-center justify-center gap-2 bg-accent hover:bg-accent-hover text-white py-3 rounded-xl font-bold shadow-lg shadow-accent/10 transition-colors text-xs"
                        >
                          <LogIn size={14} />
                          Entrar como Admin
                        </button>

                        {tenantDetail.tenant.id !== 1 && tenantDetail.tenant.subdomain !== 'talent360' ? (
                          <button
                            onClick={() => handleToggleStatus(tenantDetail.tenant.id, tenantDetail.tenant.name, tenantDetail.tenant.is_active)}
                            className={`w-full flex items-center justify-center gap-2 py-3 rounded-xl font-bold transition-colors text-xs border ${
                              tenantDetail.tenant.is_active
                                ? 'bg-danger-bg hover:bg-danger-bg text-danger-text border-danger-text/20'
                                : 'bg-success-bg hover:bg-success-bg text-success-text border-success-text/20'
                            }`}
                          >
                            <Ban size={14} />
                            {tenantDetail.tenant.is_active ? 'Suspender Empresa' : 'Activar Empresa'}
                          </button>
                        ) : (
                          <div className="col-span-1 text-center py-3 bg-page border border-border text-slate-400 font-bold italic rounded-xl text-xs flex items-center justify-center">
                            Cuenta de Sistema Protegida
                          </div>
                        )}
                      </div>
                    </>
                  )}
                </>
              )}
            </div>
          </div>
        </div>
      </div>
    )}

    {/* MODAL: CONFIRMAR SUSPENSIÓN */}
    {isSuspensionModalOpen && (
      <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4 animate-in fade-in duration-200">
        <div className="bg-white rounded-3xl p-7 max-w-md w-full shadow-2xl border border-border animate-in zoom-in-95 duration-150">
          <div className="w-12 h-12 bg-danger-bg text-danger-text rounded-full flex items-center justify-center mb-4 border border-danger-text/20">
            <ShieldAlert size={24} />
          </div>
          <h3 className="text-xl font-black text-text-1 mb-2">Suspender Empresa</h3>
          <p className="text-sm text-text-3 mb-5 leading-normal">
            Estás a punto de suspender el acceso de la empresa <span className="font-extrabold text-text-1">"{suspensionTenantName}"</span>. Sus usuarios no podrán usar la aplicación ni loguearse.
          </p>

          <div className="space-y-4 mb-6">
            <div>
              <label className="text-xs font-bold text-text-2 block mb-1.5 uppercase">Motivo de la Suspensión</label>
              <select
                value={suspensionReason}
                onChange={(e) => setSuspensionReason(e.target.value)}
                className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-bold text-text-1 focus:border-accent outline-none text-sm"
              >
                <option value="Falta de pago">Falta de pago (Adeudo/Facturación)</option>
                <option value="Término del periodo de prueba">Término del periodo de prueba</option>
                <option value="Incumplimiento de términos">Violación de términos y condiciones</option>
                <option value="Otro">Otro (Especificar motivo)</option>
              </select>
            </div>

            {suspensionReason === 'Otro' && (
              <div className="animate-in slide-in-from-top-2 duration-150">
                <label className="text-xs font-bold text-text-2 block mb-1.5 uppercase">Especificar Razón</label>
                <textarea
                  value={customSuspensionReason}
                  onChange={(e) => setCustomSuspensionReason(e.target.value)}
                  placeholder="Detalla la razón de la suspensión..."
                  className="w-full bg-page border border-border rounded-xl px-4 py-2.5 font-bold text-text-1 text-sm focus:border-accent outline-none h-20"
                />
              </div>
            )}
          </div>

          <div className="flex gap-3">
            <button
              onClick={() => setIsSuspensionModalOpen(false)}
              className="flex-1 bg-page text-text-2 font-bold py-3 rounded-xl hover:bg-slate-200 transition-colors text-xs"
            >
              Cancelar
            </button>
            <button
              onClick={handleConfirmSuspension}
              className="flex-1 bg-danger-text text-white font-bold py-3 rounded-xl shadow-lg hover:bg-danger-text transition-colors text-xs flex items-center justify-center gap-1.5"
            >
              Confirmar Suspensión
            </button>
          </div>
        </div>
      </div>
    )}

    {/* MODAL: DETALLES DE AUDITORÍA DE MÓDULO */}
    {selectedAuditModule && (
      <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4 animate-in fade-in duration-200">
        <div className="bg-white rounded-3xl p-7 max-w-md w-full shadow-2xl border border-border animate-in zoom-in-95 duration-150 relative">
          <button
            onClick={() => setSelectedAuditModule(null)}
            className="absolute top-5 right-5 p-1.5 hover:bg-page rounded-lg text-slate-400 hover:text-text-2 transition-colors"
          >
            <X size={18} />
          </button>

          <div className="flex items-center gap-3 mb-4">
            <div className={`w-10 h-10 rounded-xl flex items-center justify-center font-bold text-lg ${
              selectedAuditModule.score >= 8 ? 'bg-success-bg text-success-text border border-success-text/20' :
              selectedAuditModule.score >= 6 ? 'bg-warning-bg text-warning-text border border-warning-text/20' :
              'bg-danger-bg text-danger-text border border-danger-text/20'
            }`}>
              {selectedAuditModule.score}
            </div>
            <div>
              <h3 className="text-lg font-black text-text-1 leading-tight">{selectedAuditModule.name}</h3>
              <span className={`inline-block text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded mt-0.5 ${
                selectedAuditModule.details.status === 'Excelente' ? 'bg-success-bg text-success-text' :
                selectedAuditModule.details.status === 'Estable' ? 'bg-accent-soft text-navy-800' :
                'bg-warning-bg text-warning-text'
              }`}>
                Estado: {selectedAuditModule.details.status}
              </span>
            </div>
          </div>

          <p className="text-xs text-text-3 leading-relaxed mb-6 font-semibold bg-page p-3.5 rounded-xl border border-border">
            {selectedAuditModule.description}
          </p>

          <h4 className="text-xs font-black text-text-2 uppercase tracking-wider mb-3">Métricas Técnicas de Auditoría</h4>
          <div className="space-y-3.5 text-xs font-bold text-text-2 mb-7">
            {selectedAuditModule.details.meta && (
              <div className="flex justify-between items-center gap-4 pb-2.5 border-b border-border text-success-text font-extrabold bg-success-bg/30 px-2.5 py-1.5 rounded-xl">
                <span className="text-text-3 font-semibold shrink-0">Monitoreo en Vivo:</span>
                <span className="text-right">{selectedAuditModule.details.meta}</span>
              </div>
            )}
            <div className="flex justify-between items-start gap-4 pb-2.5 border-b border-border">
              <span className="text-slate-400 font-medium shrink-0">Cobertura de Código:</span>
              <span className="text-text-1 text-right">{selectedAuditModule.details.coverage}</span>
            </div>
            <div className="flex justify-between items-start gap-4 pb-2.5 border-b border-border">
              <span className="text-slate-400 font-medium shrink-0">Base de Datos / Rendimiento:</span>
              <span className="text-text-1 text-right">{selectedAuditModule.details.performance}</span>
            </div>
            <div className="flex justify-between items-start gap-4">
              <span className="text-slate-400 font-medium shrink-0">Seguridad & Multitenant:</span>
              <span className="text-text-1 text-right">{selectedAuditModule.details.security}</span>
            </div>
          </div>

          <button
            onClick={() => setSelectedAuditModule(null)}
            className="w-full bg-slate-900 text-white font-bold py-3 rounded-xl hover:bg-slate-800 transition-colors text-xs"
          >
            Cerrar Detalles
          </button>
        </div>
      </div>
    )}

    {/* MODAL: DETALLES DE TICKET (SLIDE-OVER LATERAL DESDE LA DERECHA) */}
    {isTicketDetailOpen && (
      <div className="fixed inset-0 z-50 overflow-hidden">
        <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onClick={() => setIsTicketDetailOpen(false)} />
        <div className="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-0 sm:pl-10">
          <div className="pointer-events-auto w-screen max-w-full sm:max-w-xl md:max-w-2xl transform bg-white shadow-2xl transition-all duration-350 ease-in-out border-l border-border flex flex-col h-full animate-in slide-in-from-right duration-300">
            {/* Header del Drawer */}
            <div className="bg-slate-950 px-6 py-6 text-white flex items-center justify-between shadow-md">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-slate-800 rounded-xl border border-slate-700">
                  <LifeBuoy size={24} className="text-navy-300" />
                </div>
                <div>
                  <h2 className="text-base font-black leading-tight truncate max-w-[280px]">
                    {isTicketDetailLoading ? 'Cargando...' : ticketDetailData?.title}
                  </h2>
                  <p className="text-[11px] text-slate-400 font-medium">
                    {isTicketDetailLoading ? '' : `Ticket #${ticketDetailData?.id}`}
                  </p>
                </div>
              </div>
              <button onClick={() => setIsTicketDetailOpen(false)} className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition-colors">
                <X size={20} />
              </button>
            </div>

            {/* Contenido */}
            <div className="flex-1 overflow-y-auto p-6 space-y-6 flex flex-col justify-between">
              {isTicketDetailLoading ? (
                <div className="h-full flex flex-col items-center justify-center text-slate-400">
                  <Loader2 className="animate-spin mb-3 text-accent" size={32} />
                  <p className="text-xs font-bold">Cargando detalles del ticket...</p>
                </div>
              ) : (
                <div className="space-y-6 flex-1">
                   {/* Detalles Generales */}
                   <div className="bg-page border border-border rounded-2xl p-5 space-y-4">
                      <div>
                         <span className="block text-[10px] font-black text-slate-400 uppercase">Descripción del Reporte</span>
                         <span className="text-xs text-text-2 font-bold block mt-1 leading-relaxed bg-white border border-border rounded-xl p-3 select-all whitespace-pre-wrap">{ticketDetailData?.description}</span>
                      </div>

                      <div className="grid grid-cols-2 gap-4 pt-2 border-t border-border/50">
                         <div>
                            <span className="block text-[10px] font-black text-slate-400 uppercase">Contacto</span>
                            <span className="text-xs text-text-1 font-extrabold block mt-0.5">{ticketDetailData?.contact_name || 'N/A'}</span>
                            {ticketDetailData?.contact_email && <span className="text-[11px] text-accent font-bold block leading-tight truncate select-all">{ticketDetailData?.contact_email}</span>}
                         </div>
                         <div>
                            <span className="block text-[10px] font-black text-slate-400 uppercase">Empresa Cliente</span>
                            <span className="text-xs text-text-1 font-extrabold block mt-0.5">{ticketDetailData?.tenant?.name || 'N/A'}</span>
                            {ticketDetailData?.tenant?.subdomain && <span className="text-[10px] text-text-3 font-medium block truncate">ID: {ticketDetailData?.tenant?.subdomain}</span>}
                         </div>
                      </div>
                   </div>

                   {/* Modificar Atributos */}
                   <div className="border border-border rounded-2xl p-5 space-y-4">
                      <h3 className="text-xs font-black uppercase tracking-wider text-slate-400 border-b border-border pb-2">Acciones y Estado</h3>

                      <div className="grid grid-cols-2 gap-3">
                         <div>
                            <label className="block text-[10px] font-black text-text-3 uppercase mb-1.5">Estado</label>
                            <select
                               value={ticketDetailData?.status || 'open'}
                               onChange={(e) => handleUpdateTicketStatus(e.target.value)}
                               className="w-full bg-page border border-border rounded-xl px-3 py-2 text-xs font-bold outline-none text-text-1 focus:border-accent transition-colors"
                            >
                               <option value="open">Abierto (Open)</option>
                               <option value="in_progress">En Proceso</option>
                               <option value="resolved">Resueltos</option>
                               <option value="closed">Cerrados</option>
                            </select>
                         </div>
                         <div>
                            <label className="block text-[10px] font-black text-text-3 uppercase mb-1.5">Prioridad</label>
                            <select
                               value={ticketDetailData?.priority || 'medium'}
                               onChange={(e) => handleUpdateTicketPriority(e.target.value)}
                               className="w-full bg-page border border-border rounded-xl px-3 py-2 text-xs font-bold outline-none text-text-1 focus:border-accent transition-colors"
                            >
                               <option value="low">Baja</option>
                               <option value="medium">Media</option>
                               <option value="high">Alta</option>
                            </select>
                         </div>
                      </div>

                      <div>
                         <label className="block text-[10px] font-black text-text-3 uppercase mb-1.5">Agente Asignado</label>
                         <select
                            value={ticketDetailData?.assigned_to || ''}
                            onChange={(e) => handleUpdateTicketAssignment(e.target.value)}
                            className="w-full bg-page border border-border rounded-xl px-3 py-2 text-xs font-bold outline-none text-text-1 focus:border-accent transition-colors"
                         >
                            <option value="">-- Sin asignar --</option>
                            {agentsList.map(agent => (
                               <option key={agent.id} value={agent.id}>{agent.name} ({agent.role === 'platform_admin' ? 'Super Admin' : 'Soporte'})</option>
                            ))}
                         </select>
                      </div>
                   </div>

                   {/* Notas Internas / Bitácora */}
                   <div className="border border-border rounded-2xl p-5 space-y-4">
                      <h3 className="text-xs font-black uppercase tracking-wider text-slate-400 border-b border-border pb-2 flex items-center gap-1">
                         <MessageSquare size={12} />
                         Notas Internas del Call Center
                      </h3>

                      <div className="space-y-3.5 max-h-48 overflow-y-auto pr-1">
                         {(!ticketDetailData?.notes || ticketDetailData.notes.length === 0) ? (
                            <p className="text-[11px] text-slate-400 font-bold italic py-2">No hay notas internas todavía. Escribe una nota para dar seguimiento.</p>
                         ) : (
                            ticketDetailData.notes.map((note: any) => (
                               <div key={note.id} className="bg-page border border-border/80 rounded-xl p-3 text-xs leading-relaxed">
                                  <div className="flex justify-between items-center mb-1 text-[10px] font-bold text-slate-400">
                                     <span className="text-accent font-extrabold">{note.user_name}</span>
                                     <span>{new Date(note.created_at).toLocaleString()}</span>
                                  </div>
                                  <p className="text-text-2 font-semibold">{note.note}</p>
                               </div>
                            ))
                         )}
                      </div>

                      <div className="pt-2">
                         <textarea
                            value={newNoteText}
                            onChange={(e) => setNewNoteText(e.target.value)}
                            placeholder="Escribe una nota de seguimiento interna..."
                            className="w-full bg-page border border-border rounded-xl px-3.5 py-2 text-xs font-bold text-text-1 placeholder-slate-400 outline-none h-16 focus:border-accent transition-colors"
                         />
                         <div className="flex gap-2 mt-2">
                            <button
                               type="button"
                               onClick={handleSuggestResponseWithIA}
                               disabled={isSuggestingIA}
                               className="flex-1 bg-gradient-to-r from-accent to-accent disabled:opacity-50 text-white font-extrabold py-2 px-3 rounded-xl text-xs transition-all active:scale-95 shadow-md flex items-center justify-center gap-1.5 border-none outline-none cursor-pointer"
                            >
                               <Sparkles size={12} className="animate-pulse" />
                               {isSuggestingIA ? 'Sugiriendo...' : 'Sugerir con IA'}
                            </button>
                            <button
                               type="button"
                               onClick={handleAddNote}
                               disabled={isAddingNote || !newNoteText.trim()}
                               className="flex-1 bg-slate-850 hover:bg-slate-900 disabled:opacity-50 text-white font-bold py-2 px-3 rounded-xl text-xs transition-colors border-none outline-none cursor-pointer"
                            >
                               {isAddingNote ? 'Agregando...' : 'Agregar Nota'}
                            </button>
                         </div>
                      </div>
                   </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    )}

    {/* MODAL: REGISTRAR NUEVO TICKET */}
    {isNewTicketModalOpen && (
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div className="bg-white rounded-3xl p-7 max-w-md w-full shadow-2xl animate-in zoom-in-95 duration-150">
          <div className="flex justify-between items-center mb-5 border-b border-border pb-3">
             <h3 className="text-lg font-black text-text-1 flex items-center gap-1.5">
                <LifeBuoy className="text-accent" size={20} />
                Registrar Ticket de Soporte
             </h3>
             <button type="button" onClick={() => setIsNewTicketModalOpen(false)} className="p-1 hover:bg-page rounded-lg text-slate-400 hover:text-text-2 transition-all"><X size={18} /></button>
          </div>

          <div className="space-y-4 max-h-[60vh] overflow-y-auto pr-1">
             <div>
                <label className="text-xs font-bold text-text-2 block mb-1">Título / Asunto</label>
                <input
                   type="text"
                   value={newTicketTitle}
                   onChange={(e) => setNewTicketTitle(e.target.value)}
                   placeholder="Ej. Falla en sincronización de reloj"
                   className="w-full bg-page border border-border rounded-xl px-3.5 py-2 text-xs font-bold text-text-1"
                />
             </div>
             <div>
                <label className="text-xs font-bold text-text-2 block mb-1">Descripción del Problema</label>
                <textarea
                   value={newTicketDesc}
                   onChange={(e) => setNewTicketDesc(e.target.value)}
                   placeholder="Describe los detalles del problema reportado por el cliente..."
                   className="w-full bg-page border border-border rounded-xl px-3.5 py-2 text-xs font-bold text-text-1 h-24"
                />
             </div>
             <div className="grid grid-cols-2 gap-3.5">
                <div>
                   <label className="text-xs font-bold text-text-2 block mb-1">Prioridad</label>
                   <select
                      value={newTicketPriority}
                      onChange={(e) => setNewTicketPriority(e.target.value)}
                      className="w-full bg-page border border-border rounded-xl px-3 py-2 text-xs font-bold text-text-1"
                   >
                      <option value="low">Baja</option>
                      <option value="medium">Media</option>
                      <option value="high">Alta</option>
                   </select>
                </div>
                <div>
                   <label className="text-xs font-bold text-text-2 block mb-1">Asignar Agente</label>
                   <select
                      value={newTicketAssignedTo}
                      onChange={(e) => setNewTicketAssignedTo(e.target.value)}
                      className="w-full bg-page border border-border rounded-xl px-3 py-2 text-xs font-bold text-text-1"
                   >
                      <option value="">-- Sin asignar --</option>
                      {agentsList.map(agent => (
                         <option key={agent.id} value={agent.id}>{agent.name}</option>
                      ))}
                   </select>
                </div>
             </div>

             <div>
                <label className="text-xs font-bold text-text-2 block mb-1">Empresa Relacionada (Opcional)</label>
                <select
                   value={newTicketTenantId}
                   onChange={(e) => setNewTicketTenantId(e.target.value)}
                   className="w-full bg-page border border-border rounded-xl px-3 py-2 text-xs font-bold text-text-1"
                >
                   <option value="">-- Ninguna --</option>
                   {tenantsList.map(tenant => (
                      <option key={tenant.id} value={tenant.id}>{tenant.name}</option>
                   ))}
                </select>
             </div>

             <div className="border-t border-border pt-3 mt-1">
                <span className="block text-[10px] font-black text-slate-400 uppercase mb-2">Datos de Contacto del Reporte</span>
                <div className="grid grid-cols-2 gap-3.5">
                   <div>
                      <label className="text-[10px] font-bold text-text-3 block mb-1">Nombre</label>
                      <input
                         type="text"
                         value={newTicketContactName}
                         onChange={(e) => setNewTicketContactName(e.target.value)}
                         placeholder="Juan López"
                         className="w-full bg-page border border-border rounded-xl px-3 py-1.5 text-xs font-bold text-text-1"
                      />
                   </div>
                   <div>
                      <label className="text-[10px] font-bold text-text-3 block mb-1">Correo</label>
                      <input
                         type="email"
                         value={newTicketContactEmail}
                         onChange={(e) => setNewTicketContactEmail(e.target.value)}
                         placeholder="juan@empresa.com"
                         className="w-full bg-page border border-border rounded-xl px-3 py-1.5 text-xs font-bold text-text-1"
                      />
                   </div>
                </div>
             </div>
          </div>

          <div className="flex gap-3 mt-6 border-t border-border pt-4">
             <button type="button" onClick={() => setIsNewTicketModalOpen(false)} className="flex-1 bg-page text-text-2 font-bold py-2.5 rounded-xl hover:bg-slate-200 transition-colors text-xs">Cancelar</button>
             <button
                type="button"
                onClick={handleCreateTicket}
                disabled={isCreatingTicket}
                className="flex-1 bg-accent text-white font-bold py-2.5 rounded-xl shadow-lg hover:bg-accent-hover transition-colors text-xs flex items-center justify-center gap-1.5"
             >
                {isCreatingTicket ? 'Registrando...' : 'Registrar Ticket'}
             </button>
          </div>
        </div>
      </div>
    )}

      {activeTab === 'security_logs' && (
        <div className="space-y-6 animate-in fade-in duration-300">
          <div className="bg-slate-900 p-5 sm:p-8 rounded-2xl sm:rounded-3xl shadow-xl border border-slate-800 text-white flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 relative overflow-hidden">
            <div className="absolute top-0 right-0 p-8 opacity-5">
               <ShieldCheck size={200} />
            </div>
            <div className="text-left relative z-10">
              <span className="text-[10px] font-black uppercase text-navy-300 tracking-widest bg-accent/10 px-3 py-1 rounded-full">Ciberseguridad SaaS</span>
              <h1 className="text-2xl sm:text-3xl font-black tracking-tight mt-2">Bitácora de <span className="text-transparent bg-clip-text bg-gradient-to-r from-navy-400 to-navy-400">Seguridad y Auditoría</span></h1>
              <p className="text-slate-400 text-xs font-semibold mt-1">Historial de accesos, intentos de autenticación, timbrados SAT CFDI 4.0 y eventos del sistema.</p>
            </div>

            <button
              type="button"
              onClick={fetchSecurityLogs}
              disabled={isLogsLoading}
              className="bg-white hover:bg-page text-text-1 px-5 py-2.5 rounded-full font-black text-xs uppercase tracking-wider transition-all shadow-md active:scale-95 border-none outline-none cursor-pointer w-full sm:w-auto"
            >
              {isLogsLoading ? 'Recargando...' : '🔄 Actualizar Bitácora'}
            </button>
          </div>

          {/* Filtros */}
          <div className="bg-white dark:bg-slate-900 border border-border dark:border-slate-800 rounded-3xl p-5 shadow-md flex flex-wrap gap-4 items-center">
            <div className="flex-1 min-w-[200px]">
              <label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase block mb-1">Filtrar por Empresa</label>
              <select
                value={logsTenantFilter}
                onChange={(e) => {
                  setLogsTenantFilter(e.target.value);
                }}
                className="w-full bg-page dark:bg-slate-950 border border-border dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-bold text-text-1 dark:text-slate-200 outline-none"
              >
                <option value="all">Todas las Empresas / Tenants</option>
                {tenantsList.map((tenant: any) => (
                  <option key={tenant.id} value={tenant.id}>{tenant.name}</option>
                ))}
              </select>
            </div>

            <div className="flex-1 min-w-[200px]">
              <label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase block mb-1">Tipo de Evento</label>
              <select
                value={logsEventFilter}
                onChange={(e) => {
                  setLogsEventFilter(e.target.value);
                }}
                className="w-full bg-page dark:bg-slate-950 border border-border dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-bold text-text-1 dark:text-slate-200 outline-none"
              >
                <option value="all">Todos los Eventos</option>
                <option value="auth_login">auth_login (Inicio de sesión)</option>
                <option value="auth_logout">auth_logout (Cierre de sesión)</option>
                <option value="auth_failed">auth_failed (Login fallido)</option>
                <option value="cfdi_signed">cfdi_signed (Timbrado SAT)</option>
                <option value="stripe_webhook">stripe_webhook (Pago Recibido)</option>
                <option value="tenant_onboarding">tenant_onboarding (Nuevo Registro)</option>
              </select>
            </div>
          </div>

          {/* Tabla de Logs */}
          <div className="bg-white dark:bg-slate-900 border border-border dark:border-slate-800 rounded-3xl shadow-md overflow-hidden">
            {isLogsLoading ? (
              <div className="p-20 text-center flex flex-col items-center justify-center gap-3">
                <div className="w-10 h-10 border-4 border-accent border-t-transparent rounded-full animate-spin"></div>
                <p className="text-xs font-bold text-text-3">Cargando registros de auditoría de seguridad...</p>
              </div>
            ) : securityLogs.length === 0 ? (
              <div className="p-20 text-center">
                <p className="text-sm font-bold text-slate-400 italic">No se encontraron eventos en la bitácora de seguridad con los filtros actuales.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left text-xs font-sans">
                  <thead>
                    <tr className="bg-page dark:bg-slate-950/40 text-slate-450 border-b border-border dark:border-slate-800/60 uppercase font-black tracking-widest text-[9.5px]">
                      <th className="px-6 py-4">Evento / ID</th>
                      <th className="px-6 py-4">Empresa</th>
                      <th className="px-6 py-4">Usuario</th>
                      <th className="px-6 py-4">Descripción del Suceso</th>
                      <th className="px-6 py-4">Dirección IP / Navegador</th>
                      <th className="px-6 py-4">Fecha y Hora</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border dark:divide-slate-800/40">
                    {securityLogs.map((log: any) => {
                      let badgeColor = 'bg-page text-text-2 border-border';
                      if (log.event_type === 'auth_login') badgeColor = 'bg-success-icon/10 text-success-text border-success-text/20';
                      else if (log.event_type === 'auth_failed') badgeColor = 'bg-danger-icon/10 text-danger-text border-danger-text/20';
                      else if (log.event_type === 'cfdi_signed') badgeColor = 'bg-accent/10 text-accent border-accent/20';
                      else if (log.event_type === 'stripe_webhook') badgeColor = 'bg-accent/10 text-accent border-accent/20';

                      return (
                        <tr key={log.id} className="hover:bg-page/60 dark:hover:bg-slate-950/20 font-medium text-text-2 dark:text-slate-300">
                          <td className="px-6 py-4.5">
                            <span className={`inline-flex px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wide border ${badgeColor}`}>
                              {log.event_type}
                            </span>
                            <span className="block text-[9px] text-slate-400 mt-1 font-mono">ID: #{log.id}</span>
                          </td>
                          <td className="px-6 py-4.5 font-bold text-text-1 dark:text-slate-205">{log.tenant_name}</td>
                          <td className="px-6 py-4.5 font-bold text-accent dark:text-navy-300">{log.user_name}</td>
                          <td className="px-6 py-4.5 max-w-xs truncate leading-relaxed" title={log.description}>{log.description}</td>
                          <td className="px-6 py-4.5">
                            <span className="font-mono text-text-2 dark:text-slate-400 font-bold block">{log.ip_address}</span>
                            <span className="block text-[9.5px] text-slate-400 truncate max-w-[150px] mt-0.5" title={log.user_agent}>{log.user_agent}</span>
                          </td>
                          <td className="px-6 py-4.5 text-text-3 dark:text-slate-400">
                            {new Date(log.created_at).toLocaleString()}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {activeTab === 'billing' && (
        <SaaSPlatformBilling />
      )}
    </div>
  );
};
