import React, { useState, useEffect } from 'react';
import { 
  Building2, Users, CreditCard, Activity, 
  AlertOctagon, TrendingUp, DollarSign, ServerCrash, 
  ArrowUpRight, ShieldAlert, ShieldCheck, GraduationCap, Loader2,
  User, LogOut, ChevronDown, Search, Filter, Eye, Key, LogIn, Ban, 
  Info, RefreshCw, X, ShieldX, KeyRound, CheckCircle2, Settings,
  LifeBuoy, MessageSquare, Plus, Trash2, Sparkles, Monitor
} from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';

const moduleAudits = [
  {
    id: 'rrhh',
    name: 'Directorio HR',
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
    name: 'Reloj Checador (PWA)',
    score: 8,
    description: 'Registro de asistencia, turnos, geolocalización GPS y verificación facial selfie.',
    details: {
      coverage: '88% Cobertura de Tests',
      performance: 'Modo offline optimizado para registro diferido y sincronización en red local.',
      security: 'Firmado HMAC de tokens de fichaje y validación en rango GPS.',
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
      const [statsRes, tenantsRes, auditsRes] = await Promise.all([
        axiosInstance.get('/platform/stats'),
        axiosInstance.get(`/platform/tenants?search=${search}&plan=${plan}&status=${status}`),
        axiosInstance.get('/platform/audits')
      ]);
      
      setStats(statsRes.data);
      setTenantsList(tenantsRes.data);
      setModuleAuditsList(auditsRes.data);
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
    { label: 'Ingresos Recurrentes (MRR)', value: `$${stats.mrr.toLocaleString()}`, icon: DollarSign, color: 'text-emerald-600', bg: 'bg-emerald-100', trend: '+15% este mes' },
    { label: 'Empresas Activas', value: stats.active_tenants.toString(), icon: Building2, color: 'text-blue-600', bg: 'bg-blue-100', trend: `+0 en Trial` },
    { label: 'Usuarios Totales', value: stats.total_users.toLocaleString(), icon: Users, color: 'text-indigo-600', bg: 'bg-indigo-100', trend: 'Crecimiento estable' },
    { label: 'Tasa de Cancelación (Churn)', value: stats.churn_rate, icon: TrendingUp, color: 'text-rose-600', bg: 'bg-rose-100', trend: 'Ligeramente alto' },
  ];

  const handleCreateTenant = async () => {
    if (!newTenantName.trim()) return;
    setIsLoading(true);
    try {
      const response = await axiosInstance.post('/tenants', {
        subdomain: newTenantName.toLowerCase().replace(/[^a-z0-9]/g, '') + Math.floor(Math.random() * 1000),
        plan: newTenantPlan.toLowerCase(),
        company_name: newTenantName,
        admin_name: 'Admin ' + newTenantName,
        admin_email: `admin_${Math.floor(Math.random() * 10000)}@${newTenantName.toLowerCase().replace(/\s/g, '')}.com`,
        admin_password: 'password123'
      });
      
      await fetchGlobalData(searchQuery, planFilter, statusFilter);
      
      setCreatedTenantData({
        tenant: response.data.tenant,
        user: response.data.user,
        password: 'password123'
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
    } catch (error) {
      console.error("Error loading tenant details:", error);
      alert("Error al cargar los detalles de la empresa.");
      setIsDetailOpen(false);
    } finally {
      setIsDetailLoading(false);
    }
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
      
      {/* Top Bar with User Profile */}
      <div className="flex justify-between items-center bg-white border border-slate-200 rounded-3xl p-5 shadow-sm">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center shadow-md">
            <span className="text-white font-black text-xl">T</span>
          </div>
          <div>
            <h2 className="text-lg font-black text-slate-800 leading-tight">Talent360 SaaS</h2>
            <p className="text-xs text-slate-500 font-medium">Consola de Administración Global</p>
          </div>
        </div>
        
        {/* User Menu */}
        <div className="relative">
          <button 
            onClick={() => setIsProfileMenuOpen(!isProfileMenuOpen)}
            className="flex items-center gap-3 hover:bg-slate-50 p-2 rounded-xl border border-slate-200 transition-colors"
          >
            <div className="w-9 h-9 bg-slate-100 rounded-lg flex items-center justify-center text-slate-600 border border-slate-200 overflow-hidden">
              {currentUser?.avatar ? (
                <img src={currentUser.avatar} alt="Avatar" className="w-full h-full object-cover" />
              ) : (
                <User size={18} />
              )}
            </div>
            <div className="text-left hidden sm:block">
              <p className="text-xs font-black text-slate-800 leading-tight">{currentUser?.name || 'Administrador'}</p>
              <p className="text-[10px] text-slate-500 font-bold uppercase tracking-wider">{currentUser?.role || 'Super Admin'}</p>
            </div>
            <ChevronDown size={14} className="text-slate-400" />
          </button>

          {isProfileMenuOpen && (
            <>
              <div className="fixed inset-0 z-10" onClick={() => setIsProfileMenuOpen(false)}></div>
              <div className="absolute right-0 mt-2 w-64 bg-white border border-slate-200 rounded-2xl shadow-xl p-4 z-20 animate-in fade-in slide-in-from-top-2 duration-150">
                <div className="border-b border-slate-100 pb-3 mb-3">
                  <p className="text-sm font-black text-slate-800">{currentUser?.name || 'Administrador'}</p>
                  <p className="text-xs text-slate-500 font-medium truncate">{currentUser?.email || 'master@talent360.com'}</p>
                </div>
                <div className="space-y-2.5 text-xs text-slate-600 font-semibold mb-3 bg-slate-50 p-3 rounded-xl border border-slate-100">
                  <div className="flex justify-between">
                    <span className="text-slate-400 font-medium">ID Usuario:</span>
                    <span>{currentUser?.id || 'N/A'}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-400 font-medium">Rol:</span>
                    <span className="text-rose-600 font-bold">{currentUser?.system_role || currentUser?.role || 'platform_admin'}</span>
                  </div>
                </div>
                <button 
                  onClick={handleLogout}
                  className="w-full flex items-center justify-center gap-2 text-rose-600 hover:text-white bg-rose-50 hover:bg-rose-600 border border-rose-100 hover:border-transparent py-2.5 rounded-xl font-bold transition-all text-xs"
                >
                  <LogOut size={14} />
                  Cerrar Sesión
                </button>
              </div>
            </>
          )}
        </div>
      </div>

      {/* Tab Switcher */}
      <div className="flex border-b border-slate-200 gap-6 mb-6">
        {isAdmin && (
          <button 
            type="button"
            onClick={() => setActiveTab('dashboard')}
            className={`pb-3 text-sm font-black transition-all border-b-2 px-1 ${
              activeTab === 'dashboard' 
                ? 'border-indigo-600 text-indigo-600' 
                : 'border-transparent text-slate-400 hover:text-slate-600'
            }`}
          >
            📊 Dashboard Global
          </button>
        )}
        <button 
          type="button"
          onClick={() => setActiveTab('tickets')}
          className={`pb-3 text-sm font-black transition-all border-b-2 px-1 flex items-center gap-1.5 ${
            activeTab === 'tickets' 
              ? 'border-indigo-600 text-indigo-600' 
              : 'border-transparent text-slate-400 hover:text-slate-600'
          }`}
        >
          <LifeBuoy size={16} />
          Soporte Técnico / Tickets
        </button>
        {isAdmin && (
          <button 
            type="button"
            onClick={() => setActiveTab('security_logs')}
            className={`pb-3 text-sm font-black transition-all border-b-2 px-1 flex items-center gap-1.5 ${
              activeTab === 'security_logs' 
                ? 'border-indigo-600 text-indigo-600' 
                : 'border-transparent text-slate-400 hover:text-slate-600'
            }`}
          >
            🛡️ Bitácora de Seguridad
          </button>
        )}
      </div>

      {activeTab === 'dashboard' && (
        <>
          {/* Header del Platform Admin */}
          <div className="bg-slate-900 p-8 rounded-3xl shadow-xl border border-slate-800 text-white flex flex-col md:flex-row justify-between items-center gap-6 relative overflow-hidden">
        <div className="absolute top-0 right-0 p-8 opacity-5">
           <Activity size={200} />
        </div>
        <div className="relative z-10 flex-1">
          <div className="flex items-center gap-3 mb-2">
            <span className="bg-rose-500 text-white text-[10px] font-black uppercase tracking-widest px-3 py-1 rounded-full animate-pulse">
              Plataforma Central
            </span>
            <span className="text-slate-400 text-sm font-bold">Modo Dueño del SaaS</span>
          </div>
          <h1 className="text-3xl font-black tracking-tight">Centro de Control Global</h1>
          <p className="text-slate-400 mt-2 max-w-xl">
            Desde aquí monitoreas la salud de tu negocio de software, la facturación global y la infraestructura de los servidores de todos tus clientes.
          </p>
        </div>
        <div className="relative z-10 flex flex-col gap-4 items-end">
          <div className="bg-slate-800/80 p-4 rounded-xl border border-slate-700 backdrop-blur-md w-full max-w-xs">
             <div className="flex justify-between items-center mb-2">
                <span className="text-xs font-bold text-slate-300 uppercase tracking-wider">Modo de Tiempo (DB)</span>
                <span className={`text-[10px] font-black px-2 py-0.5 rounded ${timeMode === 'simulated' ? 'bg-indigo-500/20 text-indigo-400' : 'bg-emerald-500/20 text-emerald-400'}`}>
                  {timeMode === 'simulated' ? 'Simulador Activo' : 'NTP Activo'}
                </span>
             </div>
             <p className="text-[10px] text-slate-500 mb-3 leading-tight">Controla si el backend registra usando NTP (Real) o la máquina del tiempo.</p>
             <div className="flex gap-2">
                <button 
                  onClick={() => updateSetting('time_mode', 'simulated')}
                  className={`flex-1 text-xs font-bold py-2 rounded-lg transition-colors ${timeMode === 'simulated' ? 'bg-indigo-600 text-white shadow-inner' : 'bg-slate-700 hover:bg-slate-600 text-slate-300'}`}
                >
                  Simulado
                </button>
                <button 
                  onClick={() => updateSetting('time_mode', 'real')}
                  className={`flex-1 text-xs font-bold py-2 rounded-lg transition-colors ${timeMode === 'real' ? 'bg-emerald-600 text-white shadow-inner' : 'bg-slate-700 hover:bg-slate-600 text-slate-300'}`}
                >
                  Tiempo Real
                </button>
             </div>
          </div>
          <button className="bg-white text-slate-900 px-6 py-3 rounded-xl font-bold shadow-lg hover:bg-slate-100 transition-colors flex items-center gap-2">
            <CreditCard size={18} />
            Ver Facturación Stripe
          </button>
        </div>
      </div>

      {/* KPIs Financieros y de Crecimiento */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {kpis.map((stat, idx) => (
          <div key={idx} className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col justify-between group">
            <div className="flex items-start justify-between">
              <div className={`p-3 rounded-xl ${stat.bg} ${stat.color}`}>
                <stat.icon size={24} strokeWidth={2} />
              </div>
              <ArrowUpRight size={20} className="text-slate-300 group-hover:text-slate-500 transition-colors" />
            </div>
            <div className="mt-4">
              <h3 className="text-3xl font-black text-slate-800">{stat.value}</h3>
              <p className="text-sm font-medium text-slate-500 mt-1">{stat.label}</p>
            </div>
            <div className="mt-4 text-xs font-bold text-slate-400 bg-slate-50 py-1.5 px-3 rounded-lg inline-block w-max">
              {stat.trend}
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Empresas Recientes */}
        <div className="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
             <h2 className="text-lg font-black text-slate-800 flex items-center gap-2">
                <Building2 className="text-blue-600" size={20} />
                Clientes e Inquilinos
             </h2>
             <button onClick={() => setIsNewTenantModalOpen(true)} className="text-sm font-bold text-blue-600 hover:bg-blue-100/50 bg-blue-50 px-4 py-2 rounded-xl transition-all self-start sm:self-auto">Simular Alta de Empresa</button>
          </div>

          {/* Barra de Filtros y Búsqueda */}
          <div className="flex flex-col md:flex-row gap-3 mb-6 bg-slate-50 p-4 rounded-2xl border border-slate-100">
             <div className="relative flex-1">
                <Search size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
                <input 
                  type="text" 
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Buscar empresa por nombre o subdominio..." 
                  className="w-full pl-10 pr-4 py-2 text-sm bg-white border border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition-all font-semibold text-slate-800 placeholder-slate-400"
                />
             </div>
             <div className="flex gap-2">
                <div className="flex items-center gap-1.5 bg-white border border-slate-200 px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600">
                   <Filter size={14} className="text-slate-400" />
                   <span>Plan:</span>
                   <select 
                      value={planFilter}
                      onChange={(e) => setPlanFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-slate-800 font-extrabold pr-4"
                   >
                      <option value="all">Todos</option>
                      <option value="freemium">Freemium</option>
                      <option value="pro">PRO</option>
                      <option value="enterprise">Enterprise</option>
                   </select>
                </div>
                <div className="flex items-center gap-1.5 bg-white border border-slate-200 px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600">
                   <Activity size={14} className="text-slate-400" />
                   <span>Estado:</span>
                   <select 
                      value={statusFilter}
                      onChange={(e) => setStatusFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-slate-800 font-extrabold pr-4"
                   >
                      <option value="all">Todos</option>
                      <option value="active">Activo</option>
                      <option value="inactive">Inactivo</option>
                   </select>
                </div>
             </div>
          </div>

          <div className="overflow-x-auto">
             <table className="w-full text-left text-sm">
                <thead>
                   <tr className="border-b border-slate-100 text-slate-500">
                      <th className="pb-3 font-bold">Empresa</th>
                      <th className="pb-3 font-bold">Plan</th>
                      <th className="pb-3 font-bold">Usuarios</th>
                      <th className="pb-3 font-bold">Estado</th>
                      <th className="pb-3 font-bold">Suscripción</th>
                      <th className="pb-3 font-bold text-right">Acciones</th>
                   </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                   {isLoading ? (
                      <tr><td colSpan={6} className="py-8 text-center text-slate-500 font-medium"><Loader2 className="animate-spin mx-auto mb-2" /> Cargando inquilinos...</td></tr>
                   ) : tenantsList.length === 0 ? (
                      <tr><td colSpan={6} className="py-8 text-center text-slate-500 font-medium">No se encontraron inquilinos con los filtros aplicados.</td></tr>
                   ) : tenantsList.map((comp, idx) => (
                      <tr key={idx} className="hover:bg-slate-50/80 transition-colors">
                          <td className="py-4 font-bold text-slate-800">{comp.name}</td>
                          <td className="py-4">
                             <span className={`px-2 py-1 rounded-md text-[10px] font-bold ${
                                comp.plan === 'PRO' ? 'bg-amber-100 text-amber-700' :
                                comp.plan === 'Enterprise' ? 'bg-indigo-100 text-indigo-700' :
                                'bg-slate-100 text-slate-600'
                             }`}>
                                {comp.plan}
                             </span>
                          </td>
                          <td className="py-4 font-medium text-slate-600">{comp.users}</td>
                          <td className="py-4">
                             <span className="flex items-center gap-1.5">
                                <span className={`w-2 h-2 rounded-full ${comp.status === 'Activo' ? 'bg-emerald-500' : 'bg-rose-500'}`}></span>
                                <span className={`text-xs font-bold ${comp.status === 'Activo' ? 'text-slate-700' : 'text-rose-600'}`}>{comp.status}</span>
                             </span>
                          </td>
                           <td className="py-4 text-xs font-medium text-slate-500">
                              <div>{comp.date}</div>
                              {(() => {
                                 if (comp.plan?.toLowerCase() === 'freemium' && !comp.trial_ends_at) {
                                    return <span className="text-[10px] text-slate-400 font-semibold block mt-0.5">Gratuito permanente</span>;
                                 }
                                 
                                 if (comp.trial_ends_at) {
                                    const endsAt = new Date(comp.trial_ends_at);
                                    const diff = endsAt.getTime() - Date.now();
                                    const days = Math.max(0, Math.ceil(diff / (1000 * 60 * 60 * 24)));
                                    
                                    if (diff > 0) {
                                       return (
                                          <span className="inline-flex items-center gap-1 text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-100 px-2 py-0.5 rounded-full mt-1.5 whitespace-nowrap">
                                             ⏳ Quedan {days} días de prueba
                                          </span>
                                       );
                                    } else {
                                       return (
                                          <span className="inline-flex items-center gap-1 text-[9px] font-bold text-rose-600 bg-rose-50 border border-rose-100 px-2 py-0.5 rounded-full mt-1.5 whitespace-nowrap">
                                             ⚠️ Prueba Expirada
                                          </span>
                                       );
                                    }
                                 }

                                 if (comp.subscription_status === 'active') {
                                    return (
                                       <span className="inline-flex items-center gap-1 text-[9px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-100 px-2 py-0.5 rounded-full mt-1.5 whitespace-nowrap">
                                          ✓ Suscrito
                                       </span>
                                    );
                                 }

                                 return null;
                              })()}
                           </td>
                          <td className="py-4 text-right">
                             <div className="flex justify-end gap-1.5">
                                <button 
                                  onClick={() => handleOpenDetails(comp.id)}
                                  title="Ver Detalles y Accesos"
                                  className="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg transition-colors border border-slate-200"
                                >
                                  <Eye size={14} />
                                </button>
                                
                                <button 
                                  onClick={() => handleImpersonate(comp.id)}
                                  title="Iniciar Sesión como Admin"
                                  className="p-1.5 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg transition-colors border border-blue-100"
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
                                          ? 'bg-rose-50 hover:bg-rose-100 text-rose-600 border-rose-100' 
                                          : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-600 border-emerald-100'
                                      }`}
                                    >
                                      <Ban size={14} />
                                    </button>
                                    <button 
                                      onClick={() => handleDeleteTenant(comp.id, comp.name)}
                                      title="Eliminar permanentemente"
                                      className="p-1.5 bg-slate-50 hover:bg-rose-600 hover:text-white text-slate-400 rounded-lg transition-colors border border-slate-200 hover:border-transparent"
                                    >
                                      <X size={14} />
                                    </button>
                                  </>
                                ) : (
                                  <span className="text-[10px] text-slate-400 font-black italic bg-slate-50 border border-slate-200 px-2 py-1 rounded-lg">Protegido</span>
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
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 flex flex-col">
          <h2 className="text-lg font-black text-slate-800 flex items-center gap-2 mb-6">
             <ServerCrash className="text-rose-600" size={20} />
             Salud del Sistema
          </h2>
          
          <div className="space-y-4 flex-1">
             {saasAlerts.length === 0 ? (
                <div className="text-center text-slate-500 py-8 text-sm font-bold">Sin alertas actuales.</div>
             ) : (
                saasAlerts.map((alert, idx) => (
                   <div key={idx} className={`p-4 rounded-xl border flex justify-between items-center gap-2 ${alert.type === 'error' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-amber-50 border-amber-200 text-amber-800'}`}>
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
          <div className="mt-6 border-t border-slate-100 pt-6">
            <h3 className="text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center justify-between">
              <span className="flex items-center gap-1.5">
                <span>📊</span> Auditoría de Calidad por Módulo
              </span>
              <span className="flex items-center gap-1.5 bg-emerald-50 text-emerald-600 border border-emerald-100/50 px-2 py-0.5 rounded-full text-[9px] font-extrabold normal-case">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                En Tiempo Real
              </span>
            </h3>
            <div className="space-y-3.5">
              {moduleAuditsList.map((mod) => (
                <div key={mod.id} className="group">
                  <div className="flex justify-between items-center mb-1 text-xs font-bold text-slate-700">
                    <span className="text-slate-800 font-extrabold">{mod.name}</span>
                    <div className="flex items-center gap-2">
                      <span className={`px-1.5 py-0.5 rounded text-[10px] font-extrabold ${
                        mod.score >= 8 ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' :
                        mod.score >= 6 ? 'bg-amber-50 text-amber-700 border border-amber-100' :
                        'bg-rose-50 text-rose-700 border border-rose-100'
                      }`}>{mod.score}/10</span>
                      <button 
                        onClick={() => setSelectedAuditModule(mod)} 
                        className="text-[10px] font-black text-indigo-600 hover:text-indigo-800 transition-colors"
                      >
                        Ver detalles
                      </button>
                    </div>
                  </div>
                  <div className="w-full bg-slate-100 rounded-full h-1.5">
                    <div 
                      className={`h-1.5 rounded-full transition-all duration-500 ${
                        mod.score >= 8 ? 'bg-emerald-500' :
                        mod.score >= 6 ? 'bg-amber-500' :
                        'bg-rose-500'
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
      <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mt-6">
        <div className="flex items-center justify-between mb-6">
           <h2 className="text-lg font-black text-slate-800 flex items-center gap-2">
              <Activity className="text-indigo-600" size={20} />
              Adopción de Módulos y Precios
           </h2>
            <div className="flex gap-3">
               <button 
                  onClick={handleOpenFreemiumConfig} 
                  className="text-xs font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 px-3.5 py-1.5 rounded-xl transition-colors flex items-center gap-1.5"
               >
                  <Settings size={14} />
                  Configurar Plan Gratuito y Prueba
               </button>
               <button 
                  onClick={() => setIsPricingModalOpen(true)} 
                  className="text-xs font-bold text-indigo-600 hover:bg-indigo-100/50 bg-indigo-50 border border-indigo-100 px-3.5 py-1.5 rounded-xl transition-colors"
               >
                  Configurar Precios
               </button>
               <button 
                  onClick={handleOpenBankConfig} 
                  className="text-xs font-bold text-emerald-600 hover:bg-emerald-100/50 bg-emerald-50 border border-emerald-100 px-3.5 py-1.5 rounded-xl transition-colors flex items-center gap-1.5"
               >
                  <CreditCard size={14} />
                  Configurar Cuenta Bancaria (SPEI)
               </button>
               <button 
                  onClick={handleOpenSimulatorConfig} 
                  className="text-xs font-bold text-violet-600 hover:bg-violet-100/50 bg-violet-50 border border-violet-100 px-3.5 py-1.5 rounded-xl transition-colors flex items-center gap-1.5"
               >
                  <Monitor size={14} />
                  Ajustes del Simulador Landing
               </button>
            </div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
           
           <div className="border border-slate-200 rounded-xl p-5 hover:border-indigo-300 transition-colors group">
              <div className="flex justify-between items-start mb-3">
                 <span className="p-2 bg-indigo-50 text-indigo-600 rounded-lg group-hover:scale-110 transition-transform"><Building2 size={20} /></span>
                 <span className="text-[9px] font-black uppercase tracking-widest bg-amber-100 text-amber-700 px-2 py-0.5 rounded">Premium</span>
              </div>
              <h3 className="font-bold text-slate-800 text-sm">Portal de Vacantes</h3>
              <p className="text-xs text-slate-500 mt-1 mb-3">Atracción de talento externo y publicación de empleos.</p>
              <div className="w-full bg-slate-100 rounded-full h-1.5 mb-1"><div className="bg-indigo-500 h-1.5 rounded-full" style={{ width: '35%' }}></div></div>
              <p className="text-[10px] font-bold text-slate-400">35% de Inquilinos activos</p>
           </div>

           <div className="border border-slate-200 rounded-xl p-5 hover:border-indigo-300 transition-colors group">
              <div className="flex justify-between items-start mb-3">
                 <span className="p-2 bg-indigo-50 text-indigo-600 rounded-lg group-hover:scale-110 transition-transform"><Users size={20} /></span>
                 <span className="text-[9px] font-black uppercase tracking-widest bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded">Freemium</span>
              </div>
              <h3 className="font-bold text-slate-800 text-sm">Rutinas y Tareas</h3>
              <p className="text-xs text-slate-500 mt-1 mb-3">Asignación de tickets, matriz de QA y check-lists.</p>
              <div className="w-full bg-slate-100 rounded-full h-1.5 mb-1"><div className="bg-emerald-500 h-1.5 rounded-full" style={{ width: '85%' }}></div></div>
              <p className="text-[10px] font-bold text-slate-400">85% de Inquilinos activos</p>
           </div>

           <div className="border border-slate-200 rounded-xl p-5 hover:border-indigo-300 transition-colors group">
              <div className="flex justify-between items-start mb-3">
                 <span className="p-2 bg-indigo-50 text-indigo-600 rounded-lg group-hover:scale-110 transition-transform"><TrendingUp size={20} /></span>
                 <span className="text-[9px] font-black uppercase tracking-widest bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded">Freemium</span>
              </div>
              <h3 className="font-bold text-slate-800 text-sm">Reportes y Analítica</h3>
              <p className="text-xs text-slate-500 mt-1 mb-3">Tableros de Business Intelligence y exportación.</p>
              <div className="w-full bg-slate-100 rounded-full h-1.5 mb-1"><div className="bg-blue-500 h-1.5 rounded-full" style={{ width: '60%' }}></div></div>
              <p className="text-[10px] font-bold text-slate-400">60% de Inquilinos activos</p>
           </div>

           <div className="border border-slate-200 rounded-xl p-5 hover:border-indigo-300 transition-colors group">
              <div className="flex justify-between items-start mb-3">
                 <span className="p-2 bg-indigo-50 text-indigo-600 rounded-lg group-hover:scale-110 transition-transform"><AlertOctagon size={20} /></span>
                 <span className="text-[9px] font-black uppercase tracking-widest bg-amber-100 text-amber-700 px-2 py-0.5 rounded">Premium</span>
              </div>
              <h3 className="font-bold text-slate-800 text-sm">Academia Interna</h3>
              <p className="text-xs text-slate-500 mt-1 mb-3">Cursos interactivos, plan de carrera y evaluaciones.</p>
              <div className="w-full bg-slate-100 rounded-full h-1.5 mb-1"><div className="bg-amber-500 h-1.5 rounded-full" style={{ width: '20%' }}></div></div>
              <p className="text-[10px] font-bold text-slate-400">20% de Inquilinos activos</p>
           </div>

        </div>
      </div>
        </>
      )}

      {activeTab === 'tickets' && (
        <div className="space-y-6">
          <div className="flex justify-between items-center bg-white border border-slate-200 rounded-3xl p-5 shadow-sm">
             <div className="flex items-center gap-3">
                <div className="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center shadow-md">
                   <LifeBuoy className="text-white" size={20} />
                </div>
                <div>
                   <h2 className="text-lg font-black text-slate-800 leading-tight">Consola de Soporte y Call Center</h2>
                   <p className="text-xs text-slate-500 font-medium">Monitoreo de incidencias y atención a inquilinos en tiempo real</p>
                </div>
             </div>
             <button 
                type="button"
                onClick={() => setIsNewTicketModalOpen(true)} 
                className="bg-indigo-600 hover:bg-indigo-750 text-white px-4 py-2.5 rounded-xl font-bold shadow-lg transition-colors flex items-center gap-1.5 text-xs"
             >
                <Plus size={14} />
                Registrar Ticket
             </button>
          </div>

          {/* Filtros de Tickets */}
          <div className="flex flex-col md:flex-row gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
             <div className="relative flex-1">
                <Search size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
                <input 
                   type="text" 
                   value={ticketsSearchQuery}
                   onChange={(e) => setTicketsSearchQuery(e.target.value)}
                   placeholder="Buscar ticket por asunto, descripción o contacto..." 
                   className="w-full pl-10 pr-4 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition-all font-bold text-slate-800 placeholder-slate-400"
                />
             </div>
             <div className="flex flex-wrap gap-2">
                <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-xl text-xs font-bold text-slate-650">
                   <Filter size={12} className="text-slate-400" />
                   <span>Estado:</span>
                   <select 
                      value={ticketsStatusFilter}
                      onChange={(e) => setTicketsStatusFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-slate-800 font-black pr-4"
                   >
                      <option value="all">Todos</option>
                      <option value="open">Abiertos</option>
                      <option value="in_progress">En Proceso</option>
                      <option value="resolved">Resueltos</option>
                      <option value="closed">Cerrados</option>
                   </select>
                </div>
                <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-xl text-xs font-bold text-slate-650">
                   <Activity size={12} className="text-slate-400" />
                   <span>Prioridad:</span>
                   <select 
                      value={ticketsPriorityFilter}
                      onChange={(e) => setTicketsPriorityFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-slate-800 font-black pr-4"
                   >
                      <option value="all">Todas</option>
                      <option value="low">Baja</option>
                      <option value="medium">Media</option>
                      <option value="high">Alta</option>
                   </select>
                </div>
                <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-xl text-xs font-bold text-slate-650">
                   <Building2 size={12} className="text-slate-400" />
                   <span>Inquilino:</span>
                   <select 
                      value={ticketsTenantFilter}
                      onChange={(e) => setTicketsTenantFilter(e.target.value)}
                      className="bg-transparent border-none outline-none cursor-pointer focus:ring-0 text-slate-800 font-black pr-4 max-w-[150px] truncate"
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
            <div className="flex flex-col items-center justify-center py-12 text-slate-500">
              <Loader2 className="animate-spin mb-2" />
              <span className="font-bold text-xs">Cargando tickets de soporte...</span>
            </div>
          ) : ticketsList.length === 0 ? (
            <div className="text-center py-16 bg-white border border-slate-200 rounded-3xl p-8 shadow-sm">
              <LifeBuoy size={48} className="mx-auto text-slate-350 mb-3 animate-bounce" />
              <h3 className="text-base font-black text-slate-850">Sin tickets de soporte</h3>
              <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto font-medium">No se encontraron tickets con los filtros actuales. Registra un nuevo ticket si ingresa una llamada o reporte.</p>
              <button onClick={() => setIsNewTicketModalOpen(true)} className="mt-4 bg-indigo-600 hover:bg-indigo-750 text-white font-bold text-xs px-4 py-2 rounded-xl transition-all">Registrar Primer Ticket</button>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {ticketsList.map((ticket) => (
                 <div key={ticket.id} className="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between">
                    <div>
                       <div className="flex justify-between items-start mb-3 gap-2">
                          <span className={`px-2.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider ${
                             ticket.status === 'open' ? 'bg-rose-50 text-rose-600 border border-rose-100' :
                             ticket.status === 'in_progress' ? 'bg-amber-50 text-amber-600 border border-amber-100' :
                             ticket.status === 'resolved' ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' :
                             'bg-slate-50 text-slate-650 border border-slate-100'
                          }`}>
                             {ticket.status === 'open' ? 'Abierto' :
                              ticket.status === 'in_progress' ? 'En Proceso' :
                              ticket.status === 'resolved' ? 'Resuelto' : 'Cerrado'}
                          </span>
                          <span className={`px-2.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider ${
                             ticket.priority === 'high' ? 'bg-rose-100 text-rose-800' :
                             ticket.priority === 'medium' ? 'bg-amber-100 text-amber-800' :
                             'bg-slate-100 text-slate-650'
                          }`}>
                             {ticket.priority === 'high' ? 'Alta' :
                              ticket.priority === 'medium' ? 'Media' : 'Baja'}
                          </span>
                       </div>
                       <h3 className="font-extrabold text-slate-800 text-sm leading-tight line-clamp-1">{ticket.title}</h3>
                       <p className="text-xs text-slate-500 mt-1.5 leading-relaxed line-clamp-2">{ticket.description}</p>

                       {ticket.tenant && (
                          <div className="mt-3.5 bg-slate-50 border border-slate-100 rounded-xl p-2.5 text-[11px] font-semibold text-slate-650 flex justify-between items-center">
                             <span className="text-slate-400">Cliente:</span>
                             <span className="text-slate-800 font-extrabold">{ticket.tenant.name}</span>
                          </div>
                       )}
                       {ticket.contact_name && (
                          <div className="mt-2 text-[10px] text-slate-500 font-bold px-1 truncate">
                             Contacto: <span className="text-slate-700">{ticket.contact_name}</span> {ticket.contact_email && <span className="text-slate-400 font-semibold">({ticket.contact_email})</span>}
                          </div>
                       )}
                    </div>
                    <div className="mt-5 pt-3.5 border-t border-slate-100 flex items-center justify-between">
                       <div className="text-[10px] text-slate-400 font-bold">
                          Creado {new Date(ticket.created_at).toLocaleDateString()}
                       </div>
                       <div className="flex gap-2">
                          <button 
                             onClick={() => handleOpenTicketDetails(ticket.id)}
                             className="bg-indigo-50 hover:bg-indigo-100 text-indigo-650 text-xs font-black px-3.5 py-1.5 rounded-xl border border-indigo-100 transition-colors"
                          >
                             Atender Ticket
                          </button>
                          {isAdmin && (
                             <button 
                                onClick={() => handleDeleteTicket(ticket.id)}
                                className="text-rose-500 hover:text-rose-700 p-1.5 rounded-lg border border-transparent hover:bg-rose-50 transition-all"
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
          <h3 className="text-2xl font-black text-slate-800 mb-6">Configurar Precios</h3>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-bold text-slate-700 block mb-1">Portal de Vacantes (MXN/mes)</label>
              <input type="number" value={saasPricing.reclutamiento} onChange={(e) => updateSaaSPricing('reclutamiento', Number(e.target.value))} className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 font-bold" />
            </div>
            <div>
              <label className="text-sm font-bold text-slate-700 block mb-1">Academia Interna (MXN/mes)</label>
              <input type="number" value={saasPricing.academia} onChange={(e) => updateSaaSPricing('academia', Number(e.target.value))} className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 font-bold" />
            </div>
            <div>
              <label className="text-sm font-bold text-slate-700 block mb-1">Reportes Avanzados (MXN/mes)</label>
              <input type="number" value={saasPricing.reportes} onChange={(e) => updateSaaSPricing('reportes', Number(e.target.value))} className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 font-bold" />
            </div>
          </div>
          <button onClick={() => setIsPricingModalOpen(false)} className="w-full mt-8 bg-indigo-600 text-white font-black py-3 rounded-xl shadow-lg hover:bg-indigo-700 transition-colors">Guardar y Cerrar</button>
        </div>
      </div>
    )}
 
    {/* MODAL: CONFIGURAR DATOS BANCARIOS (SPEI) */}
    {isBankConfigOpen && (
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 overflow-y-auto">
        <div className="bg-white rounded-3xl p-8 max-w-lg w-full shadow-2xl my-8">
          <div className="flex justify-between items-start mb-6">
            <div>
              <h3 className="text-2xl font-black text-slate-800 flex items-center gap-2">
                <CreditCard className="text-emerald-600" size={24} />
                Configurar Datos Bancarios (SPEI)
              </h3>
              <p className="text-xs text-slate-500 font-semibold mt-1">
                Establece la cuenta bancaria donde los clientes realizarán transferencias para pagar el servicio.
              </p>
            </div>
            <button 
              onClick={() => setIsBankConfigOpen(false)}
              className="p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 hover:text-slate-600 transition-colors"
            >
              <X size={20} />
            </button>
          </div>

          <div className="space-y-4 pr-1">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-xs font-bold text-slate-700 block mb-1.5">Banco Receptor</label>
                <input 
                  type="text" 
                  value={bankConfigData.bank_name} 
                  onChange={(e) => setBankConfigData({...bankConfigData, bank_name: e.target.value})} 
                  placeholder="Ej. BBVA Bancomer" 
                  className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-semibold text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:bg-white transition-all" 
                />
              </div>
              <div>
                <label className="text-xs font-bold text-slate-700 block mb-1.5">Titular de la Cuenta</label>
                <input 
                  type="text" 
                  value={bankConfigData.account_holder} 
                  onChange={(e) => setBankConfigData({...bankConfigData, account_holder: e.target.value})} 
                  placeholder="Ej. Talent 360 SA de CV" 
                  className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-semibold text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:bg-white transition-all" 
                />
              </div>
            </div>

            <div>
              <label className="text-xs font-bold text-slate-700 block mb-1.5">CLABE Interbancaria (18 dígitos)</label>
              <input 
                type="text" 
                maxLength={18}
                value={bankConfigData.clabe} 
                onChange={(e) => setBankConfigData({...bankConfigData, clabe: e.target.value.replace(/\D/g, '').slice(0, 18)})} 
                placeholder="Ej. 012180004512345678" 
                className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-black text-sm text-slate-800 tracking-wider focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:bg-white transition-all" 
              />
            </div>

            <div>
              <label className="text-xs font-bold text-slate-700 block mb-1.5">Número de Tarjeta (16 dígitos, opcional)</label>
              <input 
                type="text" 
                maxLength={16}
                value={bankConfigData.card_number} 
                onChange={(e) => setBankConfigData({...bankConfigData, card_number: e.target.value.replace(/\D/g, '').slice(0, 16)})} 
                placeholder="Ej. 4152313412345678" 
                className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-bold text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:bg-white transition-all" 
              />
            </div>

            <div>
              <label className="text-xs font-bold text-slate-700 block mb-1.5">Instrucciones Especiales</label>
              <textarea 
                rows={3}
                value={bankConfigData.instructions} 
                onChange={(e) => setBankConfigData({...bankConfigData, instructions: e.target.value})} 
                placeholder="Ej. Una vez hecha tu transferencia SPEI, reporta tu comprobante al correo facturacion@talent360.com para la activación inmediata." 
                className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-medium text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:bg-white transition-all resize-none" 
              />
            </div>

            <div className="flex items-center justify-between bg-slate-50 border border-slate-200/60 p-4 rounded-2xl mt-4">
              <div>
                <span className="text-xs font-bold text-slate-800 block">Habilitar en Checkout</span>
                <span className="text-[10px] text-slate-500 block">Mostrar este método de transferencia como alternativa en la pasarela.</span>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input 
                  type="checkbox" 
                  checked={bankConfigData.is_active} 
                  onChange={(e) => setBankConfigData({...bankConfigData, is_active: e.target.checked})}
                  className="sr-only peer" 
                />
                <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
              </label>
            </div>
          </div>

          <div className="flex gap-3 mt-8">
            <button 
              onClick={() => setIsBankConfigOpen(false)} 
              className="w-1/3 border border-slate-200 text-slate-500 font-bold py-3 rounded-xl hover:bg-slate-50 transition-colors text-sm"
            >
              Cancelar
            </button>
            <button 
              onClick={handleSaveBankConfig} 
              disabled={isSavingBank}
              className="flex-1 bg-emerald-600 text-white font-black py-3 rounded-xl shadow-lg shadow-emerald-600/10 hover:bg-emerald-700 transition-colors text-sm flex items-center justify-center gap-1.5"
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
              <h3 className="text-2xl font-black text-slate-800 flex items-center gap-2">
                <Settings className="text-indigo-600" size={24} />
                Plan Gratuito y Prueba Global
              </h3>
              <p className="text-xs text-slate-500 font-semibold mt-1">Configura las limitaciones y el periodo de prueba para nuevas cuentas.</p>
            </div>
            <button 
              onClick={() => setIsFreemiumConfigOpen(false)}
              className="p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 hover:text-slate-600 transition-colors"
            >
              <X size={20} />
            </button>
          </div>

          <div className="space-y-6 max-h-[60vh] overflow-y-auto pr-2 custom-scrollbar">
            {/* Sección 1: Días de Prueba */}
            <div className="bg-indigo-50/50 border border-indigo-100/80 rounded-2xl p-4">
              <label className="text-sm font-bold text-slate-800 block mb-1.5 flex items-center gap-1.5">
                <span>⏱</span> Días de Periodo de Prueba Global
              </label>
              <p className="text-[11px] text-slate-500 font-medium mb-3">Establece la cantidad de días que las nuevas empresas registradas tendrán acceso ilimitado de prueba antes de bloquearse y degradarse a la versión gratuita básica.</p>
              <input 
                type="number" 
                min={0}
                value={globalTrialDays} 
                onChange={(e) => setGlobalTrialDays(Math.max(0, parseInt(e.target.value) || 0))} 
                className="w-32 bg-white border border-slate-200 rounded-xl px-4 py-2 font-black text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" 
              />
            </div>

            {/* Sección 2: Módulos del Sistema */}
            <div>
              <h4 className="text-sm font-black text-slate-800 uppercase tracking-wider mb-3 pb-1 border-b border-slate-100 flex items-center gap-1.5">
                <span>📦</span> Módulos Incluidos en el Plan Gratuito
              </h4>
              <p className="text-[11px] text-slate-500 font-semibold mb-4">Selecciona cuáles de los siguientes módulos principales serán totalmente gratuitos para siempre (Freemium):</p>
              
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {[
                  { id: 'rrhh', label: 'Recursos Humanos', desc: 'Directorio y expediente básico' },
                  { id: 'reloj', label: 'Reloj Checador', desc: 'Control de turnos y checador básico' },
                  { id: 'operativo', label: 'Rutinas y Tareas', desc: 'Tickets y checklists de tareas' },
                  { id: 'ats', label: 'Reclutamiento ATS', desc: 'Embudo de selección y candidatos' },
                  { id: 'reportes', label: 'Reportes y Analítica', desc: 'BI, prenóminas e incidencias' },
                  { id: 'portal', label: 'Portal Web', desc: 'Bolsa de trabajo pública' },
                  { id: 'academia', label: 'Academia 360', desc: 'Capacitación y cursos LMS' },
                  { id: 'documentos', label: 'Documentos', desc: 'Expediente digital y políticas de empresa' }
                ].map(mod => (
                  <label 
                    key={mod.id}
                    className={`flex items-start gap-3 p-3.5 rounded-2xl border transition-all cursor-pointer select-none ${
                      freemiumModules.includes(mod.id)
                        ? 'border-indigo-500 bg-indigo-50/30'
                        : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                    }`}
                  >
                    <input 
                      type="checkbox"
                      checked={freemiumModules.includes(mod.id)}
                      onChange={() => toggleFreemiumModule(mod.id)}
                      className="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500"
                    />
                    <div>
                      <span className="text-xs font-bold text-slate-800 block">{mod.label}</span>
                      <span className="text-[10px] text-slate-500 font-medium">{mod.desc}</span>
                    </div>
                  </label>
                ))}
              </div>
            </div>

            {/* Sección 3: Funciones Especiales */}
            <div>
              <h4 className="text-sm font-black text-slate-800 uppercase tracking-wider mb-3 pb-1 border-b border-slate-100 flex items-center gap-1.5">
                <span>⚡</span> Funciones Especiales Desbloqueadas en Gratis
              </h4>
              <p className="text-[11px] text-slate-500 font-semibold mb-4">Activa funcionalidades específicas que se considerarán libres de costo en la versión gratuita:</p>
              
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {[
                  { id: 'voice_assistant', label: 'Asistente de Voz AI', desc: 'Creación de tareas mediante comandos de voz AI' },
                  { id: 'routines_management', label: 'Gestión de Rutinas', desc: 'Organización de tareas recurrentes en checklists' },
                  { id: 'supervisor_validation', label: 'Aprobación de Tareas', desc: 'Exigir validación del supervisor' },
                  { id: 'gps_validation', label: 'Validación GPS de Checadas', desc: 'Restricción de ubicación geográfica al checar' },
                  { id: 'face_validation', label: 'Selfie Checador', desc: 'Checar obligatoriamente con selfie' },
                  { id: 'system_backups', label: 'Respaldos JSON', desc: 'Exportación de la BD de empresa' },
                  { id: 'custom_logo', label: 'Logotipo Personalizado', desc: 'Establecer logotipo propio del workspace' },
                  { id: 'meal_reservation', label: 'Reserva de Comida', desc: 'Agenda de comedor con cupo controlado' },
                  { id: 'roll_call', label: 'Pase de Lista Masivo', desc: 'Asistencia masiva controlada por supervisor' },
                  { id: 'key_delegation', label: 'Entrega de Llaves', desc: 'Delegar el rol de apertura/cierre de sucursal' }
                ].map(feat => (
                  <label 
                    key={feat.id}
                    className={`flex items-start gap-3 p-3.5 rounded-2xl border transition-all cursor-pointer select-none ${
                      freemiumFeatures.includes(feat.id)
                        ? 'border-indigo-500 bg-indigo-50/30'
                        : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                    }`}
                  >
                    <input 
                      type="checkbox"
                      checked={freemiumFeatures.includes(feat.id)}
                      onChange={() => toggleFreemiumFeature(feat.id)}
                      className="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500"
                    />
                    <div>
                      <span className="text-xs font-bold text-slate-800 block">{feat.label}</span>
                      <span className="text-[10px] text-slate-500 font-medium">{feat.desc}</span>
                    </div>
                  </label>
                ))}
              </div>
            </div>
          </div>

          <div className="flex gap-3 mt-8 border-t border-slate-100 pt-6">
            <button 
              onClick={() => setIsFreemiumConfigOpen(false)} 
              className="flex-1 py-3 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition-colors text-sm"
            >
              Cancelar
            </button>
            <button 
              onClick={handleSaveFreemiumConfig}
              disabled={isSavingFreemium}
              className="flex-1 py-3 rounded-xl font-black text-white bg-indigo-600 hover:bg-indigo-700 shadow-lg shadow-indigo-600/25 transition-all text-sm flex items-center justify-center gap-2"
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
              <h3 className="text-xl font-black text-slate-800 flex items-center gap-2">
                <Monitor className="text-indigo-600" size={22} />
                Ajustes del Simulador Landing
              </h3>
              <p className="text-xs text-slate-500 font-semibold mt-1">Configura las dimensiones y contenidos de la simulación en la página de inicio.</p>
            </div>
            <button 
              onClick={() => setIsSimulatorConfigOpen(false)}
              className="p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 hover:text-slate-600 transition-colors border-none bg-transparent cursor-pointer"
            >
              <X size={20} />
            </button>
          </div>

          <div className="space-y-4 text-left">
            <div>
              <label className="text-xs font-bold text-slate-700 block mb-1.5">Escala del Reloj (Porcentaje)</label>
              <div className="flex items-center gap-2">
                <input 
                  type="range" 
                  min="50" 
                  max="150" 
                  value={simulatorConfig.scale} 
                  onChange={(e) => setSimulatorConfig({...simulatorConfig, scale: parseInt(e.target.value)})}
                  className="flex-1 accent-indigo-650 cursor-pointer" 
                />
                <span className="text-xs font-black text-slate-700 w-10 text-right">{simulatorConfig.scale}%</span>
              </div>
              <p className="text-[10px] text-slate-400 mt-1 font-medium">Permite reducir o agrandar el smartphone del simulador en la landing page para que encaje mejor.</p>
            </div>

            <div>
              <label className="text-xs font-bold text-slate-700 block mb-1.5">Nombre del Colaborador</label>
              <input 
                type="text" 
                value={simulatorConfig.emp_name} 
                onChange={(e) => setSimulatorConfig({...simulatorConfig, emp_name: e.target.value})}
                placeholder="Francisco Vega"
                className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-medium text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all" 
              />
              <p className="text-[10px] text-slate-400 mt-1 font-medium">El nombre ficticio del empleado que se mostrará en el simulador.</p>
            </div>

            <div>
              <label className="text-xs font-bold text-slate-700 block mb-1.5">Nombre de la Sucursal</label>
              <input 
                type="text" 
                value={simulatorConfig.store_name} 
                onChange={(e) => setSimulatorConfig({...simulatorConfig, store_name: e.target.value})}
                placeholder="Decorarte 365"
                className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-medium text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all" 
              />
              <p className="text-[10px] text-slate-400 mt-1 font-medium">El nombre de la sucursal ficticia que se mostrará en el simulador.</p>
            </div>
          </div>

          <div className="flex gap-3 mt-8 border-t border-slate-100 pt-6">
            <button 
              onClick={() => setIsSimulatorConfigOpen(false)} 
              className="flex-1 py-3 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition-colors text-sm border-none cursor-pointer"
            >
              Cancelar
            </button>
            <button 
              onClick={handleSaveSimulatorConfig}
              disabled={isSavingSimulator}
              className="flex-1 py-3 rounded-xl font-black text-white bg-indigo-600 hover:bg-indigo-700 shadow-lg shadow-indigo-600/25 transition-all text-sm flex items-center justify-center gap-2 border-none cursor-pointer"
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
              <div className="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-4 border border-emerald-100">
                <ShieldCheck size={24} className="text-emerald-500" />
              </div>
              <h3 className="text-2xl font-black text-slate-800 mb-2">¡Empresa Creada!</h3>
              <p className="text-sm text-slate-500 mb-6">Guarda estas credenciales para poder iniciar sesión en la empresa creada.</p>
              
              <div className="bg-slate-50 border border-slate-200 rounded-2xl p-4 text-left space-y-3 mb-6 text-sm">
                <div>
                  <span className="block text-xs font-bold text-slate-400 uppercase">Empresa</span>
                  <span className="font-bold text-slate-800">{createdTenantData.tenant.name}</span>
                </div>
                <div>
                  <span className="block text-xs font-bold text-slate-400 uppercase">Subdominio / Slug</span>
                  <span className="font-mono text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded">{createdTenantData.tenant.subdomain}</span>
                </div>
                <div>
                  <span className="block text-xs font-bold text-slate-400 uppercase">Usuario Administrador</span>
                  <span className="font-bold text-slate-800">{createdTenantData.user.email}</span>
                </div>
                <div>
                  <span className="block text-xs font-bold text-slate-400 uppercase">Contraseña</span>
                  <span className="font-mono font-bold text-slate-800">{createdTenantData.password}</span>
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
              <h3 className="text-2xl font-black text-slate-800 mb-6">Simular Nueva Empresa</h3>
              <div className="space-y-4">
                <div>
                  <label className="text-sm font-bold text-slate-700 block mb-1">Nombre de la Empresa</label>
                  <input type="text" value={newTenantName} onChange={(e) => setNewTenantName(e.target.value)} placeholder="Ej. Constructora del Norte" className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 font-bold text-slate-800" />
                </div>
                <div>
                  <label className="text-sm font-bold text-slate-700 block mb-1">Plan a Contratar</label>
                  <select value={newTenantPlan} onChange={(e) => setNewTenantPlan(e.target.value)} className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 font-bold text-slate-800">
                    <option value="Freemium">Freemium (Gratis)</option>
                    <option value="PRO">PRO</option>
                    <option value="Enterprise">Enterprise</option>
                  </select>
                </div>
              </div>
              <div className="flex gap-4 mt-8">
                <button onClick={() => { setIsNewTenantModalOpen(false); setNewTenantName(''); }} className="flex-1 bg-slate-100 text-slate-700 font-black py-3 rounded-xl hover:bg-slate-200 transition-colors">Cancelar</button>
                <button onClick={handleCreateTenant} disabled={isLoading} className="flex-1 bg-blue-600 text-white font-black py-3 rounded-xl shadow-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
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
        
        <div className="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
          <div className="pointer-events-auto w-screen max-w-lg transform bg-white shadow-2xl transition-all duration-300 ease-in-out border-l border-slate-200 flex flex-col h-full animate-in slide-in-from-right duration-300">
            {/* Header del Slide-over */}
            <div className="bg-slate-900 px-6 py-6 text-white flex items-center justify-between shadow-md">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-slate-800 rounded-xl border border-slate-700">
                  <Building2 size={24} className="text-indigo-400" />
                </div>
                <div>
                  <h2 className="text-lg font-black leading-tight truncate max-w-[280px]">
                    {isDetailLoading ? 'Cargando...' : tenantDetail?.tenant?.name}
                  </h2>
                  <p className="text-xs text-slate-400 font-medium">
                    {isDetailLoading ? '' : `${tenantDetail?.tenant?.subdomain}.talent360.com`}
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
                    <Settings size={18} className={isEditing ? "text-indigo-400 animate-spin-slow" : ""} />
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
                  <Loader2 className="animate-spin mb-3 text-indigo-600" size={32} />
                  <p className="text-sm font-bold">Cargando información del inquilino...</p>
                </div>
              ) : (
                <>
                  {isEditing ? (
                    <div className="space-y-5 animate-in fade-in duration-200">
                      {/* Formulario de Edición */}
                      <div className="bg-slate-50 border border-slate-200 rounded-2xl p-5 space-y-4">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Datos de la Empresa</h3>
                        
                        <div>
                          <label className="block text-[10px] font-black text-slate-500 uppercase mb-1">Nombre de la Empresa</label>
                          <input 
                            type="text" 
                            value={editTenantName} 
                            onChange={(e) => setEditTenantName(e.target.value)} 
                            className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors"
                          />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <label className="block text-[10px] font-black text-slate-500 uppercase mb-1">Plan</label>
                            <select 
                              value={editTenantPlan} 
                              onChange={(e) => setEditTenantPlan(e.target.value)} 
                              className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors"
                            >
                              <option value="freemium">Freemium</option>
                              <option value="pro">Pro</option>
                              <option value="enterprise">Enterprise</option>
                            </select>
                          </div>
                          <div>
                            <label className="block text-[10px] font-black text-slate-500 uppercase mb-1">Límite Usuarios</label>
                            <input 
                              type="number" 
                              value={editMaxUsers} 
                              onChange={(e) => setEditMaxUsers(Number(e.target.value))} 
                              className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors"
                            />
                          </div>
                        </div>
                      </div>

                      <div className="bg-slate-50 border border-slate-200 rounded-2xl p-5 space-y-4">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Datos del Administrador</h3>
                        
                        <div>
                          <label className="block text-[10px] font-black text-slate-500 uppercase mb-1">Nombre Completo</label>
                          <input 
                            type="text" 
                            value={editAdminName} 
                            onChange={(e) => setEditAdminName(e.target.value)} 
                            className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors"
                          />
                        </div>

                        <div>
                           <label className="block text-[10px] font-black text-slate-500 uppercase mb-1">Correo de Acceso</label>
                           <input 
                             type="email" 
                             value={editAdminEmail} 
                             onChange={(e) => setEditAdminEmail(e.target.value)} 
                             className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors focus:ring-0"
                           />
                         </div>

                         <div>
                           <label className="block text-[10px] font-black text-slate-500 uppercase mb-1">Teléfono WhatsApp</label>
                           <div className="flex border border-slate-200 rounded-xl overflow-hidden focus-within:ring-1 focus-within:ring-indigo-500 focus-within:border-indigo-500 bg-white">
                             <div className="bg-slate-50 px-3 py-2 text-xs text-slate-500 font-bold border-r border-slate-200 flex items-center gap-1.5 select-none">
                               <span>🇲🇽</span>
                               <span>+52</span>
                             </div>
                             <input 
                               type="text" 
                               value={formatPhoneVisual(editAdminPhone)} 
                               onChange={(e) => setEditAdminPhone(getCleanDbPhone(e.target.value))} 
                               className="w-full px-3 py-2 text-sm font-bold outline-none text-slate-800 font-mono"
                               placeholder="10 dígitos (ej: 55 1234 5678)"
                             />
                           </div>
                         </div>

                        <div className="border-t border-slate-200 pt-4 mt-2">
                          <label className="block text-[10px] font-black text-slate-500 uppercase mb-1">Contraseña Temporal (Opcional)</label>
                          <div className="flex gap-2">
                            <input 
                              type="text" 
                              value={editAdminPassword} 
                              onChange={(e) => setEditAdminPassword(e.target.value)} 
                              placeholder="Dejar vacío para mantener actual"
                              className="flex-1 bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors"
                            />
                            <button 
                              type="button"
                              onClick={generateTemporaryPassword}
                              className="bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-xs font-black px-3 py-2 rounded-xl transition-colors border border-indigo-150"
                            >
                              Generar
                            </button>
                          </div>
                        </div>
                      </div>

                      <div className="flex gap-3 pt-4">
                        <button 
                          onClick={() => setIsEditing(false)} 
                          className="flex-1 bg-slate-100 text-slate-700 font-bold py-3 rounded-xl hover:bg-slate-200 transition-colors text-xs"
                        >
                          Cancelar
                        </button>
                        <button 
                          onClick={handleSaveTenantEdit} 
                          disabled={isSavingEdit}
                          className="flex-1 bg-indigo-600 text-white font-black py-3 rounded-xl shadow-lg shadow-indigo-500/10 hover:bg-indigo-700 transition-colors text-xs flex items-center justify-center gap-1.5"
                        >
                          {isSavingEdit ? 'Guardando...' : 'Guardar Cambios'}
                        </button>
                      </div>
                    </div>
                  ) : (
                    <>
                      {/* Banner de Suspensión si no está activo */}
                      {!tenantDetail?.tenant?.is_active && (
                        <div className="p-4 bg-rose-50 border border-rose-100 rounded-2xl text-rose-800 flex items-start gap-3">
                          <ShieldX size={20} className="shrink-0 text-rose-500 mt-0.5" />
                          <div>
                            <p className="text-sm font-extrabold leading-tight">Empresa Suspendida</p>
                            <p className="text-xs mt-1 text-rose-700/90 font-bold">
                              Motivo: <span className="underline">{tenantDetail?.tenant?.suspension_reason || 'No especificado'}</span>
                            </p>
                            {tenantDetail?.tenant?.suspended_at && (
                              <p className="text-[10px] text-rose-500 mt-2 font-medium">
                                Suspendida el {new Date(tenantDetail.tenant.suspended_at).toLocaleString()}
                              </p>
                            )}
                          </div>
                        </div>
                      )}

                      {/* General Info Card */}
                      <div className="bg-slate-50 border border-slate-200 rounded-2xl p-5 space-y-3.5">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Información del Plan</h3>
                        <div className="flex justify-between items-center text-sm">
                          <span className="font-semibold text-slate-500">Plan Contratado:</span>
                          <span className={`px-2.5 py-1 rounded-md text-xs font-black ${
                            tenantDetail?.tenant?.plan === 'pro' ? 'bg-amber-100 text-amber-700 border border-amber-200' :
                            tenantDetail?.tenant?.plan === 'enterprise' ? 'bg-indigo-100 text-indigo-700 border border-indigo-200' :
                            'bg-slate-100 text-slate-700 border border-slate-200'
                          }`}>
                            {tenantDetail?.tenant?.plan}
                          </span>
                        </div>
                        <div className="flex justify-between items-center text-sm">
                          <span className="font-semibold text-slate-500">Estado de Facturación:</span>
                          <span className={`px-2 py-0.5 rounded text-xs font-bold uppercase ${
                            tenantDetail?.tenant?.subscription_status === 'active' ? 'bg-emerald-100 text-emerald-800' :
                            tenantDetail?.tenant?.subscription_status === 'trial' ? 'bg-blue-100 text-blue-800' :
                            tenantDetail?.tenant?.subscription_status === 'past_due' ? 'bg-amber-100 text-amber-800' :
                            'bg-slate-100 text-slate-800'
                          }`}>
                            {tenantDetail?.tenant?.subscription_status}
                          </span>
                        </div>
                        {tenantDetail?.tenant?.trial_ends_at && (
                          <div className="flex justify-between items-center text-sm border-t border-slate-200/50 pt-2">
                            <span className="font-semibold text-slate-500">Periodo de Prueba Finaliza:</span>
                            <span className="font-bold text-slate-700">{new Date(tenantDetail.tenant.trial_ends_at).toLocaleDateString()}</span>
                          </div>
                        )}
                        {tenantDetail?.tenant?.current_period_end && (
                          <div className="flex justify-between items-center text-sm border-t border-slate-200/50 pt-2">
                            <span className="font-semibold text-slate-500">Próximo Cobro / Fin Ciclo:</span>
                            <span className="font-bold text-slate-700">{new Date(tenantDetail.tenant.current_period_end).toLocaleDateString()}</span>
                          </div>
                        )}
                      </div>

                      {/* Consumo y Recursos */}
                      <div className="border border-slate-200 rounded-2xl p-5 space-y-4">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Consumo de Recursos</h3>
                        
                        {/* Barra de usuarios */}
                        <div>
                          <div className="flex justify-between text-xs font-bold mb-1.5 text-slate-600">
                            <span>Usuarios Creados</span>
                            <span>{tenantDetail?.metrics?.users_count} / {tenantDetail?.tenant?.max_users}</span>
                          </div>
                          <div className="w-full bg-slate-100 rounded-full h-2">
                            <div 
                              className={`h-2 rounded-full transition-all duration-500 ${
                                (tenantDetail?.metrics?.users_count / tenantDetail?.tenant?.max_users) >= 0.9 
                                  ? 'bg-rose-500' 
                                  : (tenantDetail?.metrics?.users_count / tenantDetail?.tenant?.max_users) >= 0.7 
                                  ? 'bg-amber-500' 
                                  : 'bg-indigo-600'
                              }`}
                              style={{ width: `${Math.min(100, (tenantDetail?.metrics?.users_count / tenantDetail?.tenant?.max_users) * 100)}%` }}
                            ></div>
                          </div>
                        </div>

                        {/* Vacantes */}
                        <div className="flex justify-between items-center text-sm border-t border-slate-100 pt-3">
                          <span className="font-semibold text-slate-500">Vacantes Publicadas:</span>
                          <span className="font-black text-slate-800 bg-slate-100 px-3 py-1 rounded-lg border border-slate-200">{tenantDetail?.metrics?.vacancies_count}</span>
                        </div>
                      </div>

                      {/* Accesos Administrativos */}
                      <div className="border border-slate-200 rounded-2xl p-5 space-y-4">
                        <div className="flex items-center justify-between">
                          <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Acceso Administrador</h3>
                          <span className="text-[10px] font-black text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">Owner</span>
                        </div>
                        {tenantDetail?.admin ? (
                          <div className="space-y-3">
                            <div className="text-sm">
                              <span className="block text-[10px] font-bold text-slate-400 uppercase">Nombre Completo</span>
                              <span className="font-extrabold text-slate-800">{tenantDetail.admin.name}</span>
                            </div>
                            <div className="text-sm">
                              <span className="block text-[10px] font-bold text-slate-400 uppercase">Correo Electrónico</span>
                              <span className="font-mono font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded select-all break-all">{tenantDetail.admin.email}</span>
                            </div>
                            <div className="text-sm">
                              <span className="block text-[10px] font-bold text-slate-400 uppercase">Teléfono WhatsApp</span>
                              <span className="font-mono font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded select-all break-all">{tenantDetail.admin.phone || 'No registrado'}</span>
                            </div>

                            {/* Modificar Contraseña */}
                            <div className="border-t border-slate-150 pt-3 mt-2">
                              {!isResetFormVisible ? (
                                <button 
                                  onClick={() => setIsResetFormVisible(true)}
                                  className="text-xs font-bold text-indigo-600 hover:text-indigo-800 hover:underline flex items-center gap-1"
                                >
                                  <KeyRound size={12} />
                                  Cambiar / Restablecer Contraseña
                                </button>
                              ) : (
                                <div className="space-y-3 p-3 bg-slate-50 border border-slate-200 rounded-xl animate-in fade-in duration-200">
                                  <label className="block text-[10px] font-black text-slate-500 uppercase">Nueva Contraseña</label>
                                  <div className="flex gap-2">
                                    <input 
                                      type="text" 
                                      value={newPassword}
                                      onChange={(e) => setNewPassword(e.target.value)}
                                      placeholder="Min. 6 caracteres"
                                      className="flex-1 bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-bold outline-none text-slate-800"
                                    />
                                    <button 
                                      onClick={handleResetPassword}
                                      disabled={isResetting || !newPassword.trim()}
                                      className="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg disabled:opacity-50 transition-colors"
                                    >
                                      {isResetting ? 'Guardando...' : 'Aplicar'}
                                    </button>
                                    <button 
                                      onClick={() => { setIsResetFormVisible(false); setNewPassword(''); }}
                                      className="text-slate-400 hover:text-slate-600 p-1 text-xs"
                                    >
                                      Cancelar
                                    </button>
                                  </div>
                                </div>
                              )}
                            </div>
                          </div>
                        ) : (
                          <p className="text-xs text-rose-500 font-bold bg-rose-50 p-3 rounded-xl border border-rose-100">Advertencia: No se encontró ningún usuario administrador asignado a esta empresa.</p>
                        )}
                      </div>

                      {/* Acciones del Slide-over */}
                      <div className="grid grid-cols-2 gap-3 border-t border-slate-200 pt-6">
                        <button 
                          onClick={() => handleImpersonate(tenantDetail.tenant.id)}
                          className="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-xl font-bold shadow-lg shadow-blue-500/10 transition-colors text-xs"
                        >
                          <LogIn size={14} />
                          Entrar como Admin
                        </button>

                        {tenantDetail.tenant.id !== 1 && tenantDetail.tenant.subdomain !== 'talent360' ? (
                          <button 
                            onClick={() => handleToggleStatus(tenantDetail.tenant.id, tenantDetail.tenant.name, tenantDetail.tenant.is_active)}
                            className={`w-full flex items-center justify-center gap-2 py-3 rounded-xl font-bold transition-colors text-xs border ${
                              tenantDetail.tenant.is_active 
                                ? 'bg-rose-50 hover:bg-rose-100 text-rose-600 border-rose-200' 
                                : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-600 border-emerald-200'
                            }`}
                          >
                            <Ban size={14} />
                            {tenantDetail.tenant.is_active ? 'Suspender Empresa' : 'Activar Empresa'}
                          </button>
                        ) : (
                          <div className="col-span-1 text-center py-3 bg-slate-50 border border-slate-200 text-slate-400 font-bold italic rounded-xl text-xs flex items-center justify-center">
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
        <div className="bg-white rounded-3xl p-7 max-w-md w-full shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-150">
          <div className="w-12 h-12 bg-rose-50 text-rose-500 rounded-full flex items-center justify-center mb-4 border border-rose-100">
            <ShieldAlert size={24} />
          </div>
          <h3 className="text-xl font-black text-slate-800 mb-2">Suspender Empresa</h3>
          <p className="text-sm text-slate-500 mb-5 leading-normal">
            Estás a punto de suspender el acceso de la empresa <span className="font-extrabold text-slate-800">"{suspensionTenantName}"</span>. Sus usuarios no podrán usar la aplicación ni loguearse.
          </p>

          <div className="space-y-4 mb-6">
            <div>
              <label className="text-xs font-bold text-slate-600 block mb-1.5 uppercase">Motivo de la Suspensión</label>
              <select 
                value={suspensionReason} 
                onChange={(e) => setSuspensionReason(e.target.value)} 
                className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-bold text-slate-800 focus:border-indigo-500 outline-none text-sm"
              >
                <option value="Falta de pago">Falta de pago (Adeudo/Facturación)</option>
                <option value="Término del periodo de prueba">Término del periodo de prueba</option>
                <option value="Incumplimiento de términos">Violación de términos y condiciones</option>
                <option value="Otro">Otro (Especificar motivo)</option>
              </select>
            </div>

            {suspensionReason === 'Otro' && (
              <div className="animate-in slide-in-from-top-2 duration-150">
                <label className="text-xs font-bold text-slate-600 block mb-1.5 uppercase">Especificar Razón</label>
                <textarea 
                  value={customSuspensionReason}
                  onChange={(e) => setCustomSuspensionReason(e.target.value)}
                  placeholder="Detalla la razón de la suspensión..."
                  className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 font-bold text-slate-800 text-sm focus:border-indigo-500 outline-none h-20"
                />
              </div>
            )}
          </div>

          <div className="flex gap-3">
            <button 
              onClick={() => setIsSuspensionModalOpen(false)} 
              className="flex-1 bg-slate-100 text-slate-700 font-bold py-3 rounded-xl hover:bg-slate-200 transition-colors text-xs"
            >
              Cancelar
            </button>
            <button 
              onClick={handleConfirmSuspension}
              className="flex-1 bg-rose-600 text-white font-bold py-3 rounded-xl shadow-lg hover:bg-rose-700 transition-colors text-xs flex items-center justify-center gap-1.5"
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
        <div className="bg-white rounded-3xl p-7 max-w-md w-full shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-150 relative">
          <button 
            onClick={() => setSelectedAuditModule(null)}
            className="absolute top-5 right-5 p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 hover:text-slate-600 transition-colors"
          >
            <X size={18} />
          </button>
          
          <div className="flex items-center gap-3 mb-4">
            <div className={`w-10 h-10 rounded-xl flex items-center justify-center font-bold text-lg ${
              selectedAuditModule.score >= 8 ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' :
              selectedAuditModule.score >= 6 ? 'bg-amber-50 text-amber-600 border border-amber-100' :
              'bg-rose-50 text-rose-600 border border-rose-100'
            }`}>
              {selectedAuditModule.score}
            </div>
            <div>
              <h3 className="text-lg font-black text-slate-800 leading-tight">{selectedAuditModule.name}</h3>
              <span className={`inline-block text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded mt-0.5 ${
                selectedAuditModule.details.status === 'Excelente' ? 'bg-emerald-100 text-emerald-800' :
                selectedAuditModule.details.status === 'Estable' ? 'bg-blue-100 text-blue-800' :
                'bg-amber-100 text-amber-800'
              }`}>
                Estado: {selectedAuditModule.details.status}
              </span>
            </div>
          </div>

          <p className="text-xs text-slate-500 leading-relaxed mb-6 font-semibold bg-slate-50 p-3.5 rounded-xl border border-slate-100">
            {selectedAuditModule.description}
          </p>

          <h4 className="text-xs font-black text-slate-700 uppercase tracking-wider mb-3">Métricas Técnicas de Auditoría</h4>
          <div className="space-y-3.5 text-xs font-bold text-slate-600 mb-7">
            {selectedAuditModule.details.meta && (
              <div className="flex justify-between items-center gap-4 pb-2.5 border-b border-slate-50 text-emerald-600 font-extrabold bg-emerald-50/30 px-2.5 py-1.5 rounded-xl">
                <span className="text-slate-500 font-semibold shrink-0">Monitoreo en Vivo:</span>
                <span className="text-right">{selectedAuditModule.details.meta}</span>
              </div>
            )}
            <div className="flex justify-between items-start gap-4 pb-2.5 border-b border-slate-50">
              <span className="text-slate-400 font-medium shrink-0">Cobertura de Código:</span>
              <span className="text-slate-800 text-right">{selectedAuditModule.details.coverage}</span>
            </div>
            <div className="flex justify-between items-start gap-4 pb-2.5 border-b border-slate-50">
              <span className="text-slate-400 font-medium shrink-0">Base de Datos / Rendimiento:</span>
              <span className="text-slate-800 text-right">{selectedAuditModule.details.performance}</span>
            </div>
            <div className="flex justify-between items-start gap-4">
              <span className="text-slate-400 font-medium shrink-0">Seguridad & Multitenant:</span>
              <span className="text-slate-800 text-right">{selectedAuditModule.details.security}</span>
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
        <div className="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
          <div className="pointer-events-auto w-screen max-w-lg transform bg-white shadow-2xl transition-all duration-350 ease-in-out border-l border-slate-200 flex flex-col h-full animate-in slide-in-from-right duration-300">
            {/* Header del Drawer */}
            <div className="bg-slate-950 px-6 py-6 text-white flex items-center justify-between shadow-md">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-slate-800 rounded-xl border border-slate-700">
                  <LifeBuoy size={24} className="text-indigo-400" />
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
                  <Loader2 className="animate-spin mb-3 text-indigo-650" size={32} />
                  <p className="text-xs font-bold">Cargando detalles del ticket...</p>
                </div>
              ) : (
                <div className="space-y-6 flex-1">
                   {/* Detalles Generales */}
                   <div className="bg-slate-50 border border-slate-200 rounded-2xl p-5 space-y-4">
                      <div>
                         <span className="block text-[10px] font-black text-slate-400 uppercase">Descripción del Reporte</span>
                         <span className="text-xs text-slate-700 font-bold block mt-1 leading-relaxed bg-white border border-slate-150 rounded-xl p-3 select-all whitespace-pre-wrap">{ticketDetailData?.description}</span>
                      </div>
                      
                      <div className="grid grid-cols-2 gap-4 pt-2 border-t border-slate-200/50">
                         <div>
                            <span className="block text-[10px] font-black text-slate-400 uppercase">Contacto</span>
                            <span className="text-xs text-slate-800 font-extrabold block mt-0.5">{ticketDetailData?.contact_name || 'N/A'}</span>
                            {ticketDetailData?.contact_email && <span className="text-[11px] text-indigo-600 font-bold block leading-tight truncate select-all">{ticketDetailData?.contact_email}</span>}
                         </div>
                         <div>
                            <span className="block text-[10px] font-black text-slate-400 uppercase">Empresa Cliente</span>
                            <span className="text-xs text-slate-800 font-extrabold block mt-0.5">{ticketDetailData?.tenant?.name || 'N/A'}</span>
                            {ticketDetailData?.tenant?.subdomain && <span className="text-[10px] text-slate-500 font-medium block truncate">{ticketDetailData?.tenant?.subdomain}.talent360.com</span>}
                         </div>
                      </div>
                   </div>

                   {/* Modificar Atributos */}
                   <div className="border border-slate-200 rounded-2xl p-5 space-y-4">
                      <h3 className="text-xs font-black uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-2">Acciones y Estado</h3>
                      
                      <div className="grid grid-cols-2 gap-3">
                         <div>
                            <label className="block text-[10px] font-black text-slate-500 uppercase mb-1.5">Estado</label>
                            <select 
                               value={ticketDetailData?.status || 'open'} 
                               onChange={(e) => handleUpdateTicketStatus(e.target.value)} 
                               className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors"
                            >
                               <option value="open">Abierto (Open)</option>
                               <option value="in_progress">En Proceso</option>
                               <option value="resolved">Resueltos</option>
                               <option value="closed">Cerrados</option>
                            </select>
                         </div>
                         <div>
                            <label className="block text-[10px] font-black text-slate-500 uppercase mb-1.5">Prioridad</label>
                            <select 
                               value={ticketDetailData?.priority || 'medium'} 
                               onChange={(e) => handleUpdateTicketPriority(e.target.value)} 
                               className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors"
                            >
                               <option value="low">Baja</option>
                               <option value="medium">Media</option>
                               <option value="high">Alta</option>
                            </select>
                         </div>
                      </div>

                      <div>
                         <label className="block text-[10px] font-black text-slate-500 uppercase mb-1.5">Agente Asignado</label>
                         <select 
                            value={ticketDetailData?.assigned_to || ''} 
                            onChange={(e) => handleUpdateTicketAssignment(e.target.value)} 
                            className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold outline-none text-slate-800 focus:border-indigo-500 transition-colors"
                         >
                            <option value="">-- Sin asignar --</option>
                            {agentsList.map(agent => (
                               <option key={agent.id} value={agent.id}>{agent.name} ({agent.role === 'platform_admin' ? 'Super Admin' : 'Soporte'})</option>
                            ))}
                         </select>
                      </div>
                   </div>

                   {/* Notas Internas / Bitácora */}
                   <div className="border border-slate-200 rounded-2xl p-5 space-y-4">
                      <h3 className="text-xs font-black uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-2 flex items-center gap-1">
                         <MessageSquare size={12} />
                         Notas Internas del Call Center
                      </h3>
                      
                      <div className="space-y-3.5 max-h-48 overflow-y-auto pr-1">
                         {(!ticketDetailData?.notes || ticketDetailData.notes.length === 0) ? (
                            <p className="text-[11px] text-slate-400 font-bold italic py-2">No hay notas internas todavía. Escribe una nota para dar seguimiento.</p>
                         ) : (
                            ticketDetailData.notes.map((note: any) => (
                               <div key={note.id} className="bg-slate-50 border border-slate-150/80 rounded-xl p-3 text-xs leading-relaxed">
                                  <div className="flex justify-between items-center mb-1 text-[10px] font-bold text-slate-400">
                                     <span className="text-indigo-600 font-extrabold">{note.user_name}</span>
                                     <span>{new Date(note.created_at).toLocaleString()}</span>
                                  </div>
                                  <p className="text-slate-700 font-semibold">{note.note}</p>
                               </div>
                            ))
                         )}
                      </div>

                      <div className="pt-2">
                         <textarea 
                            value={newNoteText}
                            onChange={(e) => setNewNoteText(e.target.value)}
                            placeholder="Escribe una nota de seguimiento interna..."
                            className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-xs font-bold text-slate-800 placeholder-slate-400 outline-none h-16 focus:border-indigo-500 transition-colors"
                         />
                         <div className="flex gap-2 mt-2">
                            <button 
                               type="button"
                               onClick={handleSuggestResponseWithIA}
                               disabled={isSuggestingIA}
                               className="flex-1 bg-gradient-to-r from-violet-600 to-indigo-600 disabled:opacity-50 text-white font-extrabold py-2 px-3 rounded-xl text-xs transition-all active:scale-95 shadow-md flex items-center justify-center gap-1.5 border-none outline-none cursor-pointer"
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
          <div className="flex justify-between items-center mb-5 border-b border-slate-100 pb-3">
             <h3 className="text-lg font-black text-slate-800 flex items-center gap-1.5">
                <LifeBuoy className="text-indigo-650" size={20} />
                Registrar Ticket de Soporte
             </h3>
             <button type="button" onClick={() => setIsNewTicketModalOpen(false)} className="p-1 hover:bg-slate-100 rounded-lg text-slate-400 hover:text-slate-600 transition-all"><X size={18} /></button>
          </div>

          <div className="space-y-4 max-h-[60vh] overflow-y-auto pr-1">
             <div>
                <label className="text-xs font-bold text-slate-650 block mb-1">Título / Asunto</label>
                <input 
                   type="text" 
                   value={newTicketTitle} 
                   onChange={(e) => setNewTicketTitle(e.target.value)} 
                   placeholder="Ej. Falla en sincronización de reloj" 
                   className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-xs font-bold text-slate-800"
                />
             </div>
             <div>
                <label className="text-xs font-bold text-slate-650 block mb-1">Descripción del Problema</label>
                <textarea 
                   value={newTicketDesc} 
                   onChange={(e) => setNewTicketDesc(e.target.value)} 
                   placeholder="Describe los detalles del problema reportado por el cliente..." 
                   className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-xs font-bold text-slate-800 h-24"
                />
             </div>
             <div className="grid grid-cols-2 gap-3.5">
                <div>
                   <label className="text-xs font-bold text-slate-650 block mb-1">Prioridad</label>
                   <select 
                      value={newTicketPriority} 
                      onChange={(e) => setNewTicketPriority(e.target.value)} 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800"
                   >
                      <option value="low">Baja</option>
                      <option value="medium">Media</option>
                      <option value="high">Alta</option>
                   </select>
                </div>
                <div>
                   <label className="text-xs font-bold text-slate-650 block mb-1">Asignar Agente</label>
                   <select 
                      value={newTicketAssignedTo} 
                      onChange={(e) => setNewTicketAssignedTo(e.target.value)} 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800"
                   >
                      <option value="">-- Sin asignar --</option>
                      {agentsList.map(agent => (
                         <option key={agent.id} value={agent.id}>{agent.name}</option>
                      ))}
                   </select>
                </div>
             </div>

             <div>
                <label className="text-xs font-bold text-slate-650 block mb-1">Empresa Relacionada (Opcional)</label>
                <select 
                   value={newTicketTenantId} 
                   onChange={(e) => setNewTicketTenantId(e.target.value)} 
                   className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-800"
                >
                   <option value="">-- Ninguna --</option>
                   {tenantsList.map(tenant => (
                      <option key={tenant.id} value={tenant.id}>{tenant.name}</option>
                   ))}
                </select>
             </div>

             <div className="border-t border-slate-100 pt-3 mt-1">
                <span className="block text-[10px] font-black text-slate-400 uppercase mb-2">Datos de Contacto del Reporte</span>
                <div className="grid grid-cols-2 gap-3.5">
                   <div>
                      <label className="text-[10px] font-bold text-slate-500 block mb-1">Nombre</label>
                      <input 
                         type="text" 
                         value={newTicketContactName} 
                         onChange={(e) => setNewTicketContactName(e.target.value)} 
                         placeholder="Juan López" 
                         className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-bold text-slate-805"
                      />
                   </div>
                   <div>
                      <label className="text-[10px] font-bold text-slate-500 block mb-1">Correo</label>
                      <input 
                         type="email" 
                         value={newTicketContactEmail} 
                         onChange={(e) => setNewTicketContactEmail(e.target.value)} 
                         placeholder="juan@empresa.com" 
                         className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-bold text-slate-805"
                      />
                   </div>
                </div>
             </div>
          </div>

          <div className="flex gap-3 mt-6 border-t border-slate-100 pt-4">
             <button type="button" onClick={() => setIsNewTicketModalOpen(false)} className="flex-1 bg-slate-100 text-slate-700 font-bold py-2.5 rounded-xl hover:bg-slate-200 transition-colors text-xs">Cancelar</button>
             <button 
                type="button"
                onClick={handleCreateTicket} 
                disabled={isCreatingTicket} 
                className="flex-1 bg-indigo-600 text-white font-bold py-2.5 rounded-xl shadow-lg hover:bg-indigo-700 transition-colors text-xs flex items-center justify-center gap-1.5"
             >
                {isCreatingTicket ? 'Registrando...' : 'Registrar Ticket'}
             </button>
          </div>
        </div>
      </div>
    )}

      {activeTab === 'security_logs' && (
        <div className="space-y-6 animate-in fade-in duration-300">
          <div className="bg-slate-900 p-8 rounded-3xl shadow-xl border border-slate-800 text-white flex flex-col md:flex-row justify-between items-center gap-6 relative overflow-hidden">
            <div className="absolute top-0 right-0 p-8 opacity-5">
               <ShieldCheck size={200} />
            </div>
            <div className="text-left relative z-10">
              <span className="text-[10px] font-black uppercase text-indigo-400 tracking-widest bg-indigo-500/10 px-3 py-1 rounded-full">Ciberseguridad SaaS</span>
              <h1 className="text-3xl font-black tracking-tight mt-2">Bitácora de <span className="text-transparent bg-clip-text bg-gradient-to-r from-violet-400 to-indigo-400">Seguridad y Auditoría</span></h1>
              <p className="text-slate-400 text-xs font-semibold mt-1">Historial de accesos, intentos de autenticación, timbrados SAT CFDI 4.0 y eventos del sistema.</p>
            </div>
            
            <button
              type="button"
              onClick={fetchSecurityLogs}
              disabled={isLogsLoading}
              className="bg-white hover:bg-slate-50 text-slate-900 px-5 py-2.5 rounded-full font-black text-xs uppercase tracking-wider transition-all shadow-md active:scale-95 border-none outline-none cursor-pointer"
            >
              {isLogsLoading ? 'Recargando...' : '🔄 Actualizar Bitácora'}
            </button>
          </div>

          {/* Filtros */}
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-5 shadow-md flex flex-wrap gap-4 items-center">
            <div className="flex-1 min-w-[200px]">
              <label className="text-[10px] font-black text-slate-400 dark:text-slate-500 uppercase block mb-1">Filtrar por Empresa</label>
              <select
                value={logsTenantFilter}
                onChange={(e) => {
                  setLogsTenantFilter(e.target.value);
                }}
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 dark:text-slate-200 outline-none"
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
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 dark:text-slate-200 outline-none"
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
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-md overflow-hidden">
            {isLogsLoading ? (
              <div className="p-20 text-center flex flex-col items-center justify-center gap-3">
                <div className="w-10 h-10 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                <p className="text-xs font-bold text-slate-500">Cargando registros de auditoría de seguridad...</p>
              </div>
            ) : securityLogs.length === 0 ? (
              <div className="p-20 text-center">
                <p className="text-sm font-bold text-slate-400 italic">No se encontraron eventos en la bitácora de seguridad con los filtros actuales.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left text-xs font-sans">
                  <thead>
                    <tr className="bg-slate-50 dark:bg-slate-950/40 text-slate-450 border-b border-slate-100 dark:border-slate-800/60 uppercase font-black tracking-widest text-[9.5px]">
                      <th className="px-6 py-4">Evento / ID</th>
                      <th className="px-6 py-4">Empresa</th>
                      <th className="px-6 py-4">Usuario</th>
                      <th className="px-6 py-4">Descripción del Suceso</th>
                      <th className="px-6 py-4">Dirección IP / Navegador</th>
                      <th className="px-6 py-4">Fecha y Hora</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800/40">
                    {securityLogs.map((log: any) => {
                      let badgeColor = 'bg-slate-100 text-slate-600 border-slate-200';
                      if (log.event_type === 'auth_login') badgeColor = 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20';
                      else if (log.event_type === 'auth_failed') badgeColor = 'bg-rose-500/10 text-rose-500 border-rose-500/20';
                      else if (log.event_type === 'cfdi_signed') badgeColor = 'bg-violet-500/10 text-violet-600 border-violet-500/20';
                      else if (log.event_type === 'stripe_webhook') badgeColor = 'bg-indigo-500/10 text-indigo-500 border-indigo-500/20';

                      return (
                        <tr key={log.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-950/20 font-medium text-slate-700 dark:text-slate-300">
                          <td className="px-6 py-4.5">
                            <span className={`inline-flex px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wide border ${badgeColor}`}>
                              {log.event_type}
                            </span>
                            <span className="block text-[9px] text-slate-400 mt-1 font-mono">ID: #{log.id}</span>
                          </td>
                          <td className="px-6 py-4.5 font-bold text-slate-850 dark:text-slate-205">{log.tenant_name}</td>
                          <td className="px-6 py-4.5 font-bold text-indigo-600 dark:text-indigo-400">{log.user_name}</td>
                          <td className="px-6 py-4.5 max-w-xs truncate leading-relaxed" title={log.description}>{log.description}</td>
                          <td className="px-6 py-4.5">
                            <span className="font-mono text-slate-600 dark:text-slate-400 font-bold block">{log.ip_address}</span>
                            <span className="block text-[9.5px] text-slate-400 truncate max-w-[150px] mt-0.5" title={log.user_agent}>{log.user_agent}</span>
                          </td>
                          <td className="px-6 py-4.5 text-slate-500 dark:text-slate-400">
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
    </div>
  );
};
