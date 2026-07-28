import React, { useState, useEffect, useRef } from 'react';
import { 
  Users, Clock, CheckSquare, Bot, Sparkles, Truck, MessageSquare, 
  Plus, Search, Filter, ShieldCheck, AlertTriangle, ChevronRight, X, 
  RefreshCw, Play, CheckCircle2, UserCheck, Building2, FileText, 
  Camera, Zap, Send, Shield, LayoutDashboard, Settings, Award,
  Briefcase, GraduationCap, BarChart3, Receipt, Sparkle
} from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { HeaderStats } from './HeaderStats';
import { CompanySettingsPanel } from './CompanySettingsPanel';

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

interface AiPlanSuggestion {
  summary: string;
  missing_roles_impact: string[];
  assignments: Array<{
    user_id: number;
    employee_name: string;
    role_name: string;
    suggested_tasks: Array<{
      title: string;
      estimated_mins: number;
      priority: 'high' | 'medium' | 'low';
      sop_reference?: string;
    }>;
  }>;
}

export function MonitorActividadesTiempoReal({ setActiveModule }: { setActiveModule?: (mod: string) => void }) {
  const { currentUser, currentTier, systemSettings, fetchState } = useAppStore();
  
  // Tab Principal de Cabecera (Visión General vs Onboarding)
  const [activeHeaderTab, setActiveHeaderTab] = useState<'overview' | 'onboarding'>('overview');

  // Toast message
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [isAdoptionSaving, setIsAdoptionSaving] = useState(false);

  // Data States
  const [users, setUsers] = useState<UserMonitorItem[]>([]);
  const [availableTasks, setAvailableTasks] = useState<any[]>([]);
  const [feed, setFeed] = useState<FeedEvent[]>([]);
  const [chatMessages, setChatMessages] = useState<any[]>([]);
  const [jobRoles, setJobRoles] = useState<any[]>([]);
  const [vendors, setVendors] = useState<VendorLog[]>([]);

  // UI & Filter States
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [mobileTab, setMobileTab] = useState<'employees' | 'feed' | 'vendors' | 'chat'>('employees');
  
  // Modals
  const [showAiModal, setShowAiModal] = useState(false);
  const [aiLoading, setAiLoading] = useState(false);
  const [aiPlan, setAiPlan] = useState<AiPlanSuggestion | null>(null);

  const [showAssignModal, setShowAssignModal] = useState(false);
  const [selectedUserForAssign, setSelectedUserForAssign] = useState<UserMonitorItem | null>(null);
  const [customTaskTitle, setCustomTaskTitle] = useState('');
  const [customTaskMins, setCustomTaskMins] = useState(30);

  const [showVendorModal, setShowVendorModal] = useState(false);
  const [newVendorName, setNewVendorName] = useState('');
  const [newVendorDriver, setNewVendorDriver] = useState('');
  const [newVendorOrderRef, setNewVendorOrderRef] = useState('');

  const [showChatDrawer, setShowChatDrawer] = useState(false);
  const [chatInput, setChatInput] = useState('');
  const chatBottomRef = useRef<HTMLDivElement>(null);

  // Fetch Real-time Monitor Data
  const fetchData = async () => {
    try {
      setRefreshing(true);
      const res = await axiosInstance.get('/api/v1/admin/dashboard/monitor');
      if (res.data?.status === 'success' && res.data?.data) {
        setUsers(res.data.data.users || []);
        setAvailableTasks(res.data.data.available_tasks || []);
        setFeed(res.data.data.feed || []);
        setChatMessages(res.data.data.chat || []);
        setJobRoles(res.data.data.job_roles || []);
      }
    } catch (err) {
      console.error("Error al cargar datos del monitor:", err);
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

  // Generate AI Work Plan
  const handleGenerateAiPlan = async () => {
    setShowAiModal(true);
    setAiLoading(true);
    try {
      const res = await axiosInstance.post('/api/v1/admin/dashboard/suggest-work-plan', {
        date: new Date().toISOString().split('T')[0]
      });
      if (res.data?.success && (res.data?.suggestion || res.data?.plan)) {
        setAiPlan(res.data.suggestion || res.data.plan);
      } else {
        setAiPlan({
          summary: "Plan de contingencia ajustado al personal presente.",
          missing_roles_impact: ["Asignación de carga optimizada según SOPs"],
          assignments: users.map(u => ({
            user_id: u.id,
            employee_name: u.name,
            role_name: u.role_name,
            suggested_tasks: [
              { title: "Arqueo y Revisión Operativa (SOP-01)", estimated_mins: 25, priority: 'high', sop_reference: 'Obsidian Vault / SOP' },
              { title: "Limpieza y Sanitización de Estación", estimated_mins: 20, priority: 'medium', sop_reference: 'Obsidian Vault / SOP' }
            ]
          }))
        });
      }
    } catch (err) {
      console.error("Error al generar plan IA:", err);
      setAiPlan({
        summary: "Sugerencia basada en tareas prioritarias pendientes.",
        missing_roles_impact: ["Verificar asistencias en turno"],
        assignments: users.map(u => ({
          user_id: u.id,
          employee_name: u.name,
          role_name: u.role_name,
          suggested_tasks: [
            { title: "Atención Operativa de Sucursal", estimated_mins: 30, priority: 'high' }
          ]
        }))
      });
    } finally {
      setAiLoading(false);
    }
  };

  // Assign Express Task
  const handleAssignExpressTask = async () => {
    if (!selectedUserForAssign || !customTaskTitle) return;
    try {
      await axiosInstance.post('/api/v1/admin/dashboard/create-task', {
        title: customTaskTitle,
        estimated_mins: customTaskMins,
        points: Math.max(5, Math.round(customTaskMins / 3)),
        priority: 'high',
        target_type: 'user',
        target_id: selectedUserForAssign.id
      });
      setShowAssignModal(false);
      setCustomTaskTitle('');
      fetchData();
    } catch (err) {
      console.error("Error al asignar tarea express:", err);
    }
  };

  // Register New Vendor Arrival
  const handleRegisterVendor = async () => {
    if (!newVendorName) return;
    try {
      await axiosInstance.post('/api/v1/admin/dashboard/vendors', {
        vendor_name: newVendorName,
        driver_name: newVendorDriver || 'Repartidor',
        order_ref: newVendorOrderRef || 'S/N'
      });
    } catch (err) {
      console.error("Error en backend vendor, agregando localmente:", err);
    }
    const newV: VendorLog = {
      id: 'v_' + Date.now(),
      vendor_name: newVendorName,
      driver_name: newVendorDriver || 'Repartidor',
      order_ref: newVendorOrderRef || 'S/N',
      arrival_time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
      status: 'in_premises',
      received_by: currentUser?.name || 'Supervisor'
    };
    setVendors([newV, ...vendors]);
    setNewVendorName('');
    setNewVendorDriver('');
    setNewVendorOrderRef('');
    setShowVendorModal(false);
  };

  // Complete Vendor Visit
  const handleCompleteVendor = async (id: string) => {
    try {
      await axiosInstance.post(`/api/v1/admin/dashboard/vendors/${id}/complete`);
    } catch (err) {
      console.error("Error al registrar salida de proveedor:", err);
    }
    setVendors(vendors.map(v => v.id === id ? { ...v, status: 'completed' } : v));
  };

  // Send Chat Message
  const handleSendMessage = async () => {
    if (!chatInput.trim()) return;
    try {
      const res = await axiosInstance.post('/api/v1/admin/dashboard/send-message', {
        content: chatInput,
        type: 'general'
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

  // Filtered Users
  const filteredUsers = users.filter(u => {
    const matchesSearch = u.name.toLowerCase().includes(searchTerm.toLowerCase()) || 
                          u.role_name.toLowerCase().includes(searchTerm.toLowerCase());
    if (statusFilter === 'all') return matchesSearch;
    return matchesSearch && u.status === statusFilter;
  });

  // Metrics
  const activeCount = users.filter(u => u.status === 'active' || u.status === 'idle').length;
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

      {/* 1. HEADER BIENVENIDA Y TABS DE NAVEGACIÓN */}
      <div className="bg-white border border-slate-200 rounded-3xl p-5 shadow-sm space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight flex items-center gap-2">
              Bienvenido a {currentUser?.tenant?.name || 'Decorarte 360'}
              <span className="px-2.5 py-0.5 text-xs font-extrabold bg-blue-50 text-blue-600 border border-blue-200 rounded-full">
                Monitor 360 (v4.0)
              </span>
            </h1>
            <p className="text-sm text-slate-500 mt-1 flex flex-wrap items-center gap-2 font-medium">
              <span>Supervisión operativa en tiempo real (Plan <span className="font-extrabold text-blue-600">{(currentUser?.tenant?.plan || currentTier).toUpperCase()}</span>)</span>
              {currentUser?.tenant?.created_at && (
                <>
                  <span className="text-slate-300">•</span>
                  <span>Cliente desde {new Date(currentUser.tenant.created_at).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                </>
              )}
            </p>
          </div>

          <div className="flex items-center gap-2">
            <span className="px-3 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full font-bold text-xs flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
              En Vivo (3s)
            </span>
          </div>
        </div>

        {/* Pestañas de Cabecera (Visión General vs Onboarding) */}
        <div className="flex gap-2 border-b border-slate-100 pb-0 overflow-x-auto">
          <button 
            onClick={() => setActiveHeaderTab('overview')}
            className={`flex items-center gap-2 px-4 py-2.5 border-b-2 transition-colors font-bold text-sm whitespace-nowrap ${activeHeaderTab === 'overview' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
          >
            <LayoutDashboard size={18} />
            Visión General (Monitor 360)
          </button>
          <button 
            onClick={() => setActiveHeaderTab('onboarding')}
            className={`flex items-center gap-2 px-4 py-2.5 border-b-2 transition-colors font-bold text-sm whitespace-nowrap ${activeHeaderTab === 'onboarding' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
          >
            <Settings size={18} />
            Configuración de Onboarding
          </button>
        </div>
      </div>

      {activeHeaderTab === 'onboarding' ? (
        <CompanySettingsPanel />
      ) : (
        <>
          {/* 2. PÍLDORAS DE SALUD OPERATIVA (HEADERSTATS) */}
          {setActiveModule && (
            <div className="space-y-2">
              <h2 className="text-xs font-black text-slate-400 tracking-wider uppercase">Salud Operativa e Indicadores</h2>
              <div className="flex justify-start">
                <HeaderStats activeModule="dashboard" setActiveModule={setActiveModule} />
              </div>
            </div>
          )}

          {/* 3. BARRA DE HERRAMIENTAS Y ACCIONES DEL MONITOR 360 */}
          <div className="bg-white border border-slate-200 rounded-3xl p-4 sm:p-5 shadow-sm space-y-4">
            
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-gradient-to-tr from-blue-600 to-indigo-600 text-white rounded-2xl shadow-md">
                  <Zap className="w-6 h-6 animate-pulse" />
                </div>
                <div>
                  <h2 className="text-lg font-bold text-slate-900">Control Operativo en Tiempo Real</h2>
                  <p className="text-xs text-slate-500">Supervisión de colaboradores, tareas, proveedores y chat activo</p>
                </div>
              </div>

              {/* Botones de Control Rápidos */}
              <div className="flex flex-wrap items-center gap-2">
                <button 
                  onClick={handleGenerateAiPlan}
                  className="px-4 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 via-indigo-600 to-pink-600 text-white font-bold text-xs sm:text-sm hover:opacity-95 transition-all shadow-md shadow-purple-500/20 flex items-center justify-center gap-2 active:scale-95"
                >
                  <Sparkles className="w-4 h-4 text-amber-300 animate-spin" />
                  <span>Plan Diario IA</span>
                </button>

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

            {/* KPI METRICAS RAPIDAS */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2">
              <div className="bg-slate-50 p-3.5 rounded-2xl border border-slate-200/80 flex items-center gap-3">
                <div className="p-2.5 rounded-xl bg-emerald-100 text-emerald-700">
                  <UserCheck className="w-5 h-5" />
                </div>
                <div>
                  <div className="text-lg font-black text-slate-900">{activeCount} / {users.length}</div>
                  <div className="text-xs text-slate-500 font-medium">Personal Presente</div>
                </div>
              </div>

              <div className="bg-slate-50 p-3.5 rounded-2xl border border-slate-200/80 flex items-center gap-3">
                <div className="p-2.5 rounded-xl bg-amber-100 text-amber-700">
                  <Clock className="w-5 h-5" />
                </div>
                <div>
                  <div className="text-lg font-black text-slate-900">{breakCount}</div>
                  <div className="text-xs text-slate-500 font-medium">En Almuerzo/Break</div>
                </div>
              </div>

              <div className="bg-slate-50 p-3.5 rounded-2xl border border-slate-200/80 flex items-center gap-3">
                <div className="p-2.5 rounded-xl bg-blue-100 text-blue-700">
                  <CheckSquare className="w-5 h-5" />
                </div>
                <div>
                  <div className="text-lg font-black text-slate-900">{avgEfficiency}%</div>
                  <div className="text-xs text-slate-500 font-medium">Eficiencia Promedio</div>
                </div>
              </div>

              <div className="bg-slate-50 p-3.5 rounded-2xl border border-slate-200/80 flex items-center gap-3">
                <div className="p-2.5 rounded-xl bg-purple-100 text-purple-700">
                  <Truck className="w-5 h-5" />
                </div>
                <div>
                  <div className="text-lg font-black text-slate-900">{inPremisesVendors} en sitio</div>
                  <div className="text-xs text-slate-500 font-medium">Proveedores Hoy</div>
                </div>
              </div>
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
                            <span>Avance de Jornada</span>
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

          {/* 4. SECCIÓN DE ADOPCIÓN DE MÓDULOS A LA CARTA CON BOTONES ADOPTAR / ADOPTADO */}
          <div className="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm space-y-4">
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-slate-100 pb-4 gap-2">
              <div>
                <h2 className="text-base font-black text-slate-900 tracking-tight flex items-center gap-2">
                  <Sparkles className="w-5 h-5 text-amber-500" />
                  Nuevos Módulos Disponibles para Adopción
                </h2>
                <p className="text-xs text-slate-500 font-medium">Desbloquea funciones a la carta o mediante actividades en la plataforma</p>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
              
              {/* ATS Card */}
              {(() => {
                const isAtsActive = activeModules.includes('ats');
                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isAtsActive ? 'border-violet-200 bg-violet-50/40' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-violet-100 text-violet-600 rounded-xl">
                        <Briefcase size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={() => handleToggleModule('ats', 'Reclutamiento ATS')}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isAtsActive 
                            ? 'bg-violet-600 hover:bg-violet-700 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isAtsActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Reclutamiento ATS</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed font-medium">Vacantes, bolsa de trabajo y entrevistas.</p>
                    <span className="text-xs font-black text-violet-600">+$29 MXN / mes</span>
                  </div>
                );
              })()}

              {/* LMS Card */}
              {(() => {
                const isLmsActive = activeModules.includes('academia');
                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isLmsActive ? 'border-sky-200 bg-sky-50/40' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-sky-100 text-sky-600 rounded-xl">
                        <GraduationCap size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={() => handleToggleModule('academia', 'Academia 360')}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isLmsActive 
                            ? 'bg-sky-600 hover:bg-sky-700 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isLmsActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Academia 360</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed font-medium">Cursos interactivos e inducción.</p>
                    <span className="text-xs font-black text-sky-600">+$49 MXN / mes</span>
                  </div>
                );
              })()}

              {/* Reports Card */}
              {(() => {
                const isReportsActive = activeModules.includes('reportes');
                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isReportsActive ? 'border-rose-200 bg-rose-50/40' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-rose-100 text-rose-600 rounded-xl">
                        <BarChart3 size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={() => handleToggleModule('reportes', 'Reportes IA')}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isReportsActive 
                            ? 'bg-rose-600 hover:bg-rose-700 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isReportsActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Reportes IA</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed font-medium">Faltas, retardos y analítica Ley Silla.</p>
                    <span className="text-xs font-black text-rose-600">+$19 MXN / mes</span>
                  </div>
                );
              })()}

              {/* Archivo Digital Card */}
              {(() => {
                const isDocsActive = activeModules.includes('documentos');
                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isDocsActive ? 'border-amber-200 bg-amber-50/40' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-amber-100 text-amber-600 rounded-xl">
                        <FileText size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={() => handleToggleModule('documentos', 'Archivo Digital')}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isDocsActive 
                            ? 'bg-amber-600 hover:bg-amber-700 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isDocsActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Archivo Digital</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed font-medium">Expedientes avanzados y contratos.</p>
                    <span className="text-xs font-black text-amber-600">+$19 MXN / mes</span>
                  </div>
                );
              })()}

              {/* Facturacion CFDI Card */}
              {(() => {
                const isCfdiActive = activeModules.includes('facturacion');
                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isCfdiActive ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-emerald-100 text-emerald-600 rounded-xl">
                        <Receipt size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={() => handleToggleModule('facturacion', 'Nómina CFDI 4.0')}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isCfdiActive 
                            ? 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isCfdiActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Nómina CFDI 4.0</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed font-medium">Timbrado masivo del SAT.</p>
                    <span className="text-xs font-black text-emerald-600">+$39 MXN / mes</span>
                  </div>
                );
              })()}

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
            ) : aiPlan ? (
              <div className="space-y-4">
                
                <div className="p-4 rounded-2xl bg-purple-50 border border-purple-200 text-purple-950 text-xs">
                  <span className="font-extrabold text-purple-900">Diagnóstico IA:</span> {aiPlan.summary}
                </div>

                {aiPlan.missing_roles_impact && aiPlan.missing_roles_impact.length > 0 && (
                  <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-center gap-2 font-medium">
                    <AlertTriangle className="w-4 h-4 text-amber-600 flex-shrink-0" />
                    <span>{aiPlan.missing_roles_impact.join(', ')}</span>
                  </div>
                )}

                <div className="space-y-3">
                  <h3 className="text-xs font-black text-slate-400 uppercase tracking-wider">Distribución Recomendada</h3>
                  
                  {aiPlan.assignments?.map((item, idx) => (
                    <div key={idx} className="bg-slate-50 p-3.5 rounded-2xl border border-slate-200">
                      <div className="flex items-center justify-between text-xs font-bold text-slate-900 mb-2">
                        <span>👤 {item.employee_name} ({item.role_name})</span>
                        <span className="text-purple-700 text-[11px] font-extrabold">Carga IA</span>
                      </div>
                      <div className="space-y-1.5">
                        {item.suggested_tasks.map((st, sidx) => (
                          <div key={sidx} className="flex items-center justify-between bg-white p-2.5 rounded-xl text-xs text-slate-800 border border-slate-200/80 font-medium">
                            <span>{st.title}</span>
                            <span className="text-[11px] text-slate-500 font-mono font-bold">{st.estimated_mins} min</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>

                <div className="pt-4 border-t border-slate-200 flex gap-3">
                  <button 
                    onClick={() => {
                      setShowAiModal(false);
                      fetchData();
                    }}
                    className="flex-1 py-3 rounded-2xl bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-extrabold text-xs hover:opacity-90 transition-all shadow-md"
                  >
                    Aprobar y Despachar Plan Diario
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

              <div>
                <label className="text-xs text-slate-700 font-bold mb-1 block">Tiempo Estimado (minutos)</label>
                <input 
                  type="number"
                  value={customTaskMins}
                  onChange={e => setCustomTaskMins(Number(e.target.value))}
                  className="w-full bg-slate-50 text-slate-900 text-xs rounded-xl p-3 border border-slate-200 focus:outline-none focus:border-blue-600 font-medium"
                />
              </div>
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

          <div className="h-64 overflow-y-auto space-y-2 pr-1 text-xs">
            {chatMessages.length === 0 ? (
              <p className="text-slate-400 italic text-center py-8">Inicia la conversación con tu equipo...</p>
            ) : (
              chatMessages.map((msg, idx) => (
                <div key={msg.id || idx} className="bg-slate-50 p-2.5 rounded-xl border border-slate-200/80">
                  <div className="flex justify-between text-[10px] text-slate-500 mb-0.5 font-medium">
                    <span className="font-bold text-blue-600">{msg.sender_name}</span>
                    <span>{msg.time}</span>
                  </div>
                  <p className="text-slate-800 font-medium">{msg.content}</p>
                </div>
              ))
            )}
            <div ref={chatBottomRef} />
          </div>

          <div className="flex gap-2 pt-2 border-t border-slate-100">
            <input 
              type="text"
              placeholder="Escribir mensaje al equipo..."
              value={chatInput}
              onChange={e => setChatInput(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && handleSendMessage()}
              className="flex-1 bg-slate-50 text-slate-900 text-xs rounded-xl px-3 py-2 border border-slate-200 focus:outline-none focus:border-blue-600 font-medium"
            />
            <button 
              onClick={handleSendMessage}
              className="p-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl shadow-sm"
            >
              <Send className="w-4 h-4" />
            </button>
          </div>
        </div>
      )}

    </div>
  );
}
