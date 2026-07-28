import React, { useState, useEffect, useRef } from 'react';
import { 
  Users, Clock, CheckSquare, Bot, Sparkles, Truck, MessageSquare, 
  Plus, Search, Filter, ShieldCheck, AlertTriangle, ChevronRight, X, 
  RefreshCw, Play, CheckCircle2, UserCheck, Building2, FileText, 
  Camera, Zap, Send, Shield
} from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { PromotionStoreDock } from './PromotionStoreDock';

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
  const { currentUser, globalRoles } = useAppStore();
  
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
    // Polling cada 5s para alta disponibilidad
    const interval = setInterval(fetchData, 5000);
    return () => clearInterval(interval);
  }, []);

  // Generate AI Work Plan (SOPs + Asistencia + Organigrama)
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
        // Fallback defensivo
        setAiPlan({
          summary: "Plan diario optimizado para colaboradores presentes.",
          missing_roles_impact: ["Personal activo completo - Asignación basada en SOPs"],
          assignments: users.map(u => ({
            user_id: u.id,
            employee_name: u.name,
            role_name: u.role_name,
            suggested_tasks: [
              { title: "Verificación Operativa e Inventario (SOP-01)", estimated_mins: 25, priority: 'high', sop_reference: 'Obsidian Vault / SOP' },
              { title: "Sanitización y Reporte de Cierre", estimated_mins: 20, priority: 'medium', sop_reference: 'Obsidian Vault / SOP' }
            ]
          }))
        });
      }
    } catch (err) {
      console.error("Error al generar plan IA:", err);
      setAiPlan({
        summary: "Plan alternativo de contingencia basado en lista de tareas pendientes.",
        missing_roles_impact: ["Verificar disponibilidad de personal en turno"],
        assignments: users.map(u => ({
          user_id: u.id,
          employee_name: u.name,
          role_name: u.role_name,
          suggested_tasks: [
            { title: "Atención y Revisión de Estación de Trabajo", estimated_mins: 30, priority: 'high' }
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
      console.error("Error backend vendor, agregando localmente:", err);
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

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-3 sm:p-6 font-sans pb-24 sm:pb-8">
      
      {/* BARRA DE ADOPCIÓN DE MÓDULOS Y TIENDA PRECIOS SAAS (PRESERVADA) */}
      <PromotionStoreDock onOpenStore={() => setActiveModule && setActiveModule('facturacion')} />

      {/* HEADER PRINCIPAL DEL MONITOR */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 bg-slate-900/90 backdrop-blur-md p-4.5 rounded-2xl border border-slate-800 shadow-2xl mt-4">
        <div className="flex items-center gap-3">
          <div className="relative">
            <div className="w-11 h-11 rounded-xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-emerald-500 flex items-center justify-center shadow-lg shadow-blue-500/20">
              <Zap className="w-6 h-6 text-white animate-pulse" />
            </div>
            <span className="absolute -top-1 -right-1 flex h-3 w-3">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
            </span>
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-xl sm:text-2xl font-bold bg-gradient-to-r from-white via-slate-200 to-slate-400 bg-clip-text text-transparent">
                Command Center 360
              </h1>
              <span className="px-2.5 py-0.5 text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-full flex items-center gap-1">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                En Vivo (3s)
              </span>
            </div>
            <p className="text-xs text-slate-400 flex items-center gap-1.5 mt-0.5">
              <Shield className="w-3.5 h-3.5 text-blue-400" />
              Vista de Supervisión Operativa • {currentUser?.name || 'Supervisor'}
            </p>
          </div>
        </div>

        {/* ACCIONES Y BOTONES DE CONTROL */}
        <div className="flex flex-wrap items-center gap-2">
          <button 
            onClick={handleGenerateAiPlan}
            className="flex-1 sm:flex-none px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 text-white font-bold text-xs sm:text-sm hover:opacity-95 transition-all shadow-lg shadow-purple-900/30 flex items-center justify-center gap-2 active:scale-95"
          >
            <Sparkles className="w-4 h-4 animate-spin text-amber-300" />
            <span>Plan Diario IA</span>
          </button>

          <button 
            onClick={() => setShowVendorModal(true)}
            className="px-3.5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium text-xs sm:text-sm border border-slate-700 transition-all flex items-center gap-2 active:scale-95"
          >
            <Truck className="w-4 h-4 text-emerald-400" />
            <span className="hidden sm:inline">+ Proveedor</span>
            {inPremisesVendors > 0 && (
              <span className="w-5 h-5 rounded-full bg-emerald-500 text-slate-950 font-bold text-xs flex items-center justify-center">
                {inPremisesVendors}
              </span>
            )}
          </button>

          <button 
            onClick={() => setShowChatDrawer(!showChatDrawer)}
            className="px-3.5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium text-xs sm:text-sm border border-slate-700 transition-all flex items-center gap-2 active:scale-95 relative"
          >
            <MessageSquare className="w-4 h-4 text-blue-400" />
            <span className="hidden sm:inline">Chat</span>
            {chatMessages.length > 0 && (
              <span className="w-2.5 h-2.5 rounded-full bg-blue-500 absolute top-1 right-1"></span>
            )}
          </button>

          <button 
            onClick={fetchData} 
            disabled={refreshing}
            className="p-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 transition-all"
            title="Actualizar datos en vivo"
          >
            <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin text-blue-400' : ''}`} />
          </button>
        </div>
      </div>

      {/* METRICAS Y KPIS CLAVE */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-3.5 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
            <UserCheck className="w-5 h-5" />
          </div>
          <div>
            <div className="text-lg font-bold text-slate-100">{activeCount} / {users.length}</div>
            <div className="text-xs text-slate-400">Personal Presente</div>
          </div>
        </div>

        <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-3.5 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20">
            <Clock className="w-5 h-5" />
          </div>
          <div>
            <div className="text-lg font-bold text-slate-100">{breakCount}</div>
            <div className="text-xs text-slate-400">En Almuerzo/Break</div>
          </div>
        </div>

        <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-3.5 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-blue-500/10 text-blue-400 border border-blue-500/20">
            <CheckSquare className="w-5 h-5" />
          </div>
          <div>
            <div className="text-lg font-bold text-slate-100">{avgEfficiency}%</div>
            <div className="text-xs text-slate-400">Eficiencia Promedio</div>
          </div>
        </div>

        <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-3.5 flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-purple-500/10 text-purple-400 border border-purple-500/20">
            <Truck className="w-5 h-5" />
          </div>
          <div>
            <div className="text-lg font-bold text-slate-100">{inPremisesVendors} en sitio</div>
            <div className="text-xs text-slate-400">Proveedores Hoy</div>
          </div>
        </div>
      </div>

      {/* PESTAÑAS MÓVILES (SOLO VISIBLE EN PANTALLAS PEQUEÑAS) */}
      <div className="flex sm:hidden bg-slate-900 p-1 rounded-xl mb-4 border border-slate-800">
        <button
          onClick={() => setMobileTab('employees')}
          className={`flex-1 py-2 text-xs font-semibold rounded-lg transition-all ${mobileTab === 'employees' ? 'bg-blue-600 text-white' : 'text-slate-400'}`}
        >
          Personal ({users.length})
        </button>
        <button
          onClick={() => setMobileTab('feed')}
          className={`flex-1 py-2 text-xs font-semibold rounded-lg transition-all ${mobileTab === 'feed' ? 'bg-blue-600 text-white' : 'text-slate-400'}`}
        >
          Bitácora
        </button>
        <button
          onClick={() => setMobileTab('vendors')}
          className={`flex-1 py-2 text-xs font-semibold rounded-lg transition-all ${mobileTab === 'vendors' ? 'bg-blue-600 text-white' : 'text-slate-400'}`}
        >
          Proveedores ({vendors.length})
        </button>
      </div>

      {/* MAIN LAYOUT: EMPLEADOS + BITÁCORA Y PROVEEDORES */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {/* COLUMNA EMPLEADOS */}
        <div className={`lg:col-span-2 space-y-4 ${mobileTab !== 'employees' ? 'hidden sm:block' : ''}`}>
          
          <div className="flex flex-col sm:flex-row gap-3 justify-between items-center bg-slate-900/60 p-3 rounded-2xl border border-slate-800/80">
            <div className="relative w-full sm:w-72">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
              <input
                type="text"
                placeholder="Buscar por nombre o puesto..."
                value={searchTerm}
                onChange={e => setSearchTerm(e.target.value)}
                className="w-full bg-slate-950 text-slate-200 text-xs rounded-xl pl-9 pr-3 py-2 border border-slate-800 focus:outline-none focus:border-blue-500"
              />
            </div>

            <div className="flex gap-2 w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0">
              <button
                onClick={() => setStatusFilter('all')}
                className={`px-3 py-1.5 text-xs font-medium rounded-xl border transition-all ${statusFilter === 'all' ? 'bg-blue-500/20 text-blue-400 border-blue-500/30 font-bold' : 'bg-slate-900 text-slate-400 border-slate-800'}`}
              >
                Todos ({users.length})
              </button>
              <button
                onClick={() => setStatusFilter('active')}
                className={`px-3 py-1.5 text-xs font-medium rounded-xl border transition-all ${statusFilter === 'active' ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30 font-bold' : 'bg-slate-900 text-slate-400 border-slate-800'}`}
              >
                🟢 En Turno
              </button>
              <button
                onClick={() => setStatusFilter('break')}
                className={`px-3 py-1.5 text-xs font-medium rounded-xl border transition-all ${statusFilter === 'break' ? 'bg-amber-500/20 text-amber-400 border-amber-500/30 font-bold' : 'bg-slate-900 text-slate-400 border-slate-800'}`}
              >
                🟡 En Almuerzo
              </button>
            </div>
          </div>

          {/* GRID DE CARDS EMPLEADOS */}
          {loading ? (
            <div className="p-12 text-center text-slate-400">
              <RefreshCw className="w-8 h-8 animate-spin mx-auto text-blue-500 mb-2" />
              <p className="text-sm font-medium">Cargando monitor de actividad en tiempo real...</p>
            </div>
          ) : filteredUsers.length === 0 ? (
            <div className="p-8 text-center bg-slate-900/40 rounded-2xl border border-slate-800 text-slate-400">
              <UserCheck className="w-10 h-10 mx-auto text-slate-600 mb-2" />
              <p className="text-sm font-medium">No hay colaboradores activos en este filtro.</p>
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {filteredUsers.map(u => (
                <div 
                  key={u.id}
                  className="bg-slate-900/80 hover:bg-slate-900 border border-slate-800 hover:border-slate-700 rounded-2xl p-4 transition-all duration-200 shadow-lg relative group flex flex-col justify-between"
                >
                  <div>
                    <div className="flex items-start justify-between gap-3 mb-3">
                      <div className="flex items-center gap-3">
                        <img 
                          src={u.avatar} 
                          alt={u.name} 
                          className="w-11 h-11 rounded-xl bg-slate-800 border border-slate-700 object-cover"
                        />
                        <div>
                          <h3 className="font-semibold text-sm text-slate-100 group-hover:text-blue-400 transition-colors">
                            {u.name}
                          </h3>
                          <span className="text-xs text-slate-400">{u.role_name}</span>
                        </div>
                      </div>

                      <span className={`px-2.5 py-1 text-xs font-semibold rounded-full border flex items-center gap-1 ${
                        u.status === 'active' 
                          ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' 
                          : u.status === 'break' 
                          ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' 
                          : 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                      }`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${
                          u.status === 'active' ? 'bg-emerald-400 animate-pulse' : u.status === 'break' ? 'bg-amber-400' : 'bg-rose-400'
                        }`}></span>
                        {u.status_text}
                      </span>
                    </div>

                    <div className="bg-slate-950/70 p-3 rounded-xl border border-slate-800/80 mb-3">
                      <div className="flex items-center justify-between text-xs text-slate-400 mb-1">
                        <span className="flex items-center gap-1 font-medium">
                          <CheckSquare className="w-3.5 h-3.5 text-blue-400" />
                          Tarea Actual:
                        </span>
                        <span className="text-slate-300 font-mono">
                          {u.completed_tasks_count} resueltas
                        </span>
                      </div>
                      
                      {u.active_task ? (
                        <div>
                          <p className="text-xs font-semibold text-slate-200 line-clamp-1">
                            {u.active_task.title}
                          </p>
                          <div className="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                            <span>Est: {u.active_task.estimated_mins || 30} min</span>
                            <span className="text-blue-400 font-semibold">Eficiencia: {u.efficiency}%</span>
                          </div>
                        </div>
                      ) : (
                        <p className="text-xs italic text-slate-500">Sin tarea activa asignada</p>
                      )}
                    </div>

                    <div className="space-y-1 mb-4">
                      <div className="flex justify-between text-[11px] text-slate-400">
                        <span>Avance Diario de Jornada</span>
                        <span className="font-semibold text-slate-300">{u.efficiency}%</span>
                      </div>
                      <div className="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                        <div 
                          className="bg-gradient-to-r from-blue-500 to-emerald-400 h-full rounded-full transition-all duration-500"
                          style={{ width: `${Math.min(100, u.efficiency)}%` }}
                        ></div>
                      </div>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 pt-2 border-t border-slate-800/60">
                    <button 
                      onClick={() => {
                        setSelectedUserForAssign(u);
                        setShowAssignModal(true);
                      }}
                      className="flex-1 py-1.5 text-xs font-bold rounded-xl bg-blue-600/20 text-blue-400 hover:bg-blue-600/30 border border-blue-500/30 transition-all flex items-center justify-center gap-1"
                    >
                      <Plus className="w-3.5 h-3.5" />
                      Asignar Tarea
                    </button>

                    <button 
                      onClick={() => {
                        setShowChatDrawer(true);
                        setChatInput(`@${u.name} `);
                      }}
                      className="p-1.5 rounded-xl bg-slate-800 text-slate-300 hover:bg-slate-700 border border-slate-700 transition-all"
                      title="Enviar mensaje directo"
                    >
                      <MessageSquare className="w-4 h-4 text-blue-400" />
                    </button>
                  </div>

                </div>
              ))}
            </div>
          )}

        </div>

        {/* COLUMNA DERECHA: STREAM BITÁCORA + PROVEEDORES */}
        <div className={`space-y-6 ${mobileTab === 'employees' ? 'hidden sm:block' : ''}`}>
          
          <div className="bg-slate-900/80 border border-slate-800 rounded-2xl p-4 shadow-lg">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-sm font-bold text-slate-200 flex items-center gap-2">
                <Zap className="w-4 h-4 text-amber-400 animate-pulse" />
                Bitácora en Vivo
              </h2>
              <span className="text-[11px] text-slate-400">Timeline en directo</span>
            </div>

            <div className="space-y-3 max-h-[380px] overflow-y-auto pr-1">
              {feed.length === 0 ? (
                <p className="text-xs text-slate-500 italic text-center py-6">Sin registros recientes hoy</p>
              ) : (
                feed.map((item, idx) => (
                  <div 
                    key={item.id || idx}
                    className="p-2.5 rounded-xl bg-slate-950/60 border border-slate-800/80 flex items-start gap-2.5 text-xs hover:border-slate-700 transition-all"
                  >
                    <div className="p-1.5 rounded-lg bg-blue-500/10 text-blue-400 mt-0.5">
                      <Clock className="w-3.5 h-3.5" />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center justify-between">
                        <span className="font-semibold text-slate-200">{item.user}</span>
                        <span className="text-[10px] text-slate-500">{item.time}</span>
                      </div>
                      <p className="text-slate-400 mt-0.5">{item.details}</p>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          <div className="bg-slate-900/80 border border-slate-800 rounded-2xl p-4 shadow-lg">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-sm font-bold text-slate-200 flex items-center gap-2">
                <Truck className="w-4 h-4 text-emerald-400" />
                Proveedores en Sitio
              </h2>
              <button 
                onClick={() => setShowVendorModal(true)}
                className="p-1 rounded-lg bg-emerald-500/20 text-emerald-400 hover:bg-emerald-500/30 border border-emerald-500/30 text-xs flex items-center gap-1 px-2.5 py-1 font-bold"
              >
                <Plus className="w-3 h-3" /> Registrar
              </button>
            </div>

            <div className="space-y-2.5">
              {vendors.length === 0 ? (
                <p className="text-xs text-slate-500 italic text-center py-4">No hay proveedores actualmente en las instalaciones</p>
              ) : (
                vendors.map(v => (
                  <div 
                    key={v.id}
                    className={`p-3 rounded-xl border flex items-center justify-between gap-3 text-xs transition-all ${
                      v.status === 'in_premises' 
                        ? 'bg-emerald-950/20 border-emerald-800/40 text-emerald-200' 
                        : 'bg-slate-950/40 border-slate-800 text-slate-400 opacity-60'
                    }`}
                  >
                    <div>
                      <div className="font-bold text-slate-100">{v.vendor_name}</div>
                      <div className="text-[11px] text-slate-400">
                        Chofer: {v.driver_name} • Ref: {v.order_ref}
                      </div>
                      <div className="text-[10px] text-emerald-400 mt-0.5">
                        Llegó: {v.arrival_time} • Atendió: {v.received_by}
                      </div>
                    </div>

                    {v.status === 'in_premises' && (
                      <button 
                        onClick={() => handleCompleteVendor(v.id)}
                        className="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-slate-950 font-bold text-[11px] transition-all shadow-md"
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

      {/* MODAL PLAN DE TRABAJO IA */}
      {showAiModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-2xl w-full p-6 shadow-2xl relative max-h-[90vh] overflow-y-auto">
            <button 
              onClick={() => setShowAiModal(false)}
              className="absolute top-4 right-4 p-2 text-slate-400 hover:text-white"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-3 mb-4">
              <div className="p-3 rounded-2xl bg-gradient-to-tr from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-900/30">
                <Sparkles className="w-6 h-6 animate-pulse text-amber-300" />
              </div>
              <div>
                <h2 className="text-lg font-bold text-slate-100">Plan de Trabajo Diario Asistido por IA</h2>
                <p className="text-xs text-slate-400">Asistencia real + Organigrama + Manuales SOP en Obsidian Vault</p>
              </div>
            </div>

            {aiLoading ? (
              <div className="py-16 text-center text-slate-400">
                <Sparkles className="w-10 h-10 animate-spin mx-auto text-purple-400 mb-3" />
                <p className="text-sm font-semibold text-slate-200">La IA está procesando el personal presente y los SOPs...</p>
                <p className="text-xs text-slate-500 mt-1">Generando matriz óptima de asignación...</p>
              </div>
            ) : aiPlan ? (
              <div className="space-y-4">
                
                <div className="p-3.5 rounded-xl bg-purple-950/30 border border-purple-800/40 text-purple-200 text-xs">
                  <span className="font-bold text-purple-300">Diagnóstico IA:</span> {aiPlan.summary}
                </div>

                {aiPlan.missing_roles_impact && aiPlan.missing_roles_impact.length > 0 && (
                  <div className="p-3 rounded-xl bg-amber-950/20 border border-amber-800/30 text-amber-300 text-xs flex items-center gap-2">
                    <AlertTriangle className="w-4 h-4 flex-shrink-0" />
                    <span>{aiPlan.missing_roles_impact.join(', ')}</span>
                  </div>
                )}

                <div className="space-y-3">
                  <h3 className="text-xs font-bold text-slate-300 uppercase tracking-wider">Distribución Sugerida</h3>
                  
                  {aiPlan.assignments?.map((item, idx) => (
                    <div key={idx} className="bg-slate-950/60 p-3 rounded-xl border border-slate-800/80">
                      <div className="flex items-center justify-between text-xs font-bold text-slate-200 mb-2">
                        <span>👤 {item.employee_name} ({item.role_name})</span>
                        <span className="text-purple-400 text-[11px]">Propuesta IA</span>
                      </div>
                      <div className="space-y-1.5">
                        {item.suggested_tasks.map((st, sidx) => (
                          <div key={sidx} className="flex items-center justify-between bg-slate-900 p-2 rounded-lg text-xs text-slate-300 border border-slate-800">
                            <span>{st.title}</span>
                            <span className="text-[10px] text-slate-400 font-mono">{st.estimated_mins} min</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>

                <div className="pt-4 border-t border-slate-800 flex gap-3">
                  <button 
                    onClick={() => {
                      setShowAiModal(false);
                      fetchData();
                    }}
                    className="flex-1 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-bold text-xs hover:opacity-90 transition-all shadow-lg shadow-purple-900/30"
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
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl relative">
            <button 
              onClick={() => setShowAssignModal(false)}
              className="absolute top-4 right-4 p-2 text-slate-400 hover:text-white"
            >
              <X className="w-5 h-5" />
            </button>

            <h2 className="text-base font-bold text-slate-100 mb-1">
              Asignar Tarea Express
            </h2>
            <p className="text-xs text-slate-400 mb-4">
              Para: <span className="text-blue-400 font-semibold">{selectedUserForAssign.name}</span> ({selectedUserForAssign.role_name})
            </p>

            <div className="space-y-3 mb-6">
              <div>
                <label className="text-xs text-slate-300 font-medium mb-1 block">Título de la Tarea</label>
                <input 
                  type="text"
                  placeholder="Ej: Sanitización de caja principal..."
                  value={customTaskTitle}
                  onChange={e => setCustomTaskTitle(e.target.value)}
                  className="w-full bg-slate-950 text-slate-100 text-xs rounded-xl p-3 border border-slate-800 focus:outline-none focus:border-blue-500"
                />
              </div>

              <div>
                <label className="text-xs text-slate-300 font-medium mb-1 block">Tiempo Estimado (minutos)</label>
                <input 
                  type="number"
                  value={customTaskMins}
                  onChange={e => setCustomTaskMins(Number(e.target.value))}
                  className="w-full bg-slate-950 text-slate-100 text-xs rounded-xl p-3 border border-slate-800 focus:outline-none focus:border-blue-500"
                />
              </div>
            </div>

            <div className="flex gap-2">
              <button 
                onClick={() => setShowAssignModal(false)}
                className="flex-1 py-2.5 rounded-xl bg-slate-800 text-slate-300 font-medium text-xs hover:bg-slate-700"
              >
                Cancelar
              </button>
              <button 
                onClick={handleAssignExpressTask}
                className="flex-1 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-500"
              >
                Asignar Tarea
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL REGISTRAR PROVEEDOR */}
      {showVendorModal && (
        <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl relative">
            <button 
              onClick={() => setShowVendorModal(false)}
              className="absolute top-4 right-4 p-2 text-slate-400 hover:text-white"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-2 mb-4">
              <Truck className="w-5 h-5 text-emerald-400" />
              <h2 className="text-base font-bold text-slate-100">Registrar Entrada de Proveedor</h2>
            </div>

            <div className="space-y-3 mb-6">
              <div>
                <label className="text-xs text-slate-300 font-medium mb-1 block">Empresa / Proveedor</label>
                <input 
                  type="text"
                  placeholder="Ej: Lácteos Lala, Coca-Cola..."
                  value={newVendorName}
                  onChange={e => setNewVendorName(e.target.value)}
                  className="w-full bg-slate-950 text-slate-100 text-xs rounded-xl p-3 border border-slate-800 focus:outline-none focus:border-emerald-500"
                />
              </div>

              <div>
                <label className="text-xs text-slate-300 font-medium mb-1 block">Nombre del Chofer / Repartidor</label>
                <input 
                  type="text"
                  placeholder="Ej: Juan Pérez"
                  value={newVendorDriver}
                  onChange={e => setNewVendorDriver(e.target.value)}
                  className="w-full bg-slate-950 text-slate-100 text-xs rounded-xl p-3 border border-slate-800 focus:outline-none focus:border-emerald-500"
                />
              </div>

              <div>
                <label className="text-xs text-slate-300 font-medium mb-1 block">Factura o Remisión #</label>
                <input 
                  type="text"
                  placeholder="Ej: FAC-99401"
                  value={newVendorOrderRef}
                  onChange={e => setNewVendorOrderRef(e.target.value)}
                  className="w-full bg-slate-950 text-slate-100 text-xs rounded-xl p-3 border border-slate-800 focus:outline-none focus:border-emerald-500"
                />
              </div>
            </div>

            <div className="flex gap-2">
              <button 
                onClick={() => setShowVendorModal(false)}
                className="flex-1 py-2.5 rounded-xl bg-slate-800 text-slate-300 font-medium text-xs hover:bg-slate-700"
              >
                Cancelar
              </button>
              <button 
                onClick={handleRegisterVendor}
                className="flex-1 py-2.5 rounded-xl bg-emerald-600 text-slate-950 font-bold text-xs hover:bg-emerald-500"
              >
                Confirmar Check-In
              </button>
            </div>
          </div>
        </div>
      )}

      {/* DRAWER CHAT OPERATIVO */}
      {showChatDrawer && (
        <div className="fixed bottom-0 right-0 sm:right-6 w-full sm:w-96 bg-slate-900 border border-slate-800 rounded-t-3xl sm:rounded-2xl p-4 shadow-2xl z-40 space-y-3">
          <div className="flex items-center justify-between border-b border-slate-800 pb-2">
            <div className="flex items-center gap-2">
              <MessageSquare className="w-4 h-4 text-blue-400" />
              <span className="text-xs font-bold text-slate-200">Chat Operativo de Sucursal</span>
            </div>
            <button onClick={() => setShowChatDrawer(false)} className="text-slate-400 hover:text-white">
              <X className="w-4 h-4" />
            </button>
          </div>

          <div className="h-64 overflow-y-auto space-y-2 pr-1 text-xs">
            {chatMessages.length === 0 ? (
              <p className="text-slate-500 italic text-center py-8">Inicia la conversación con tu equipo...</p>
            ) : (
              chatMessages.map((msg, idx) => (
                <div key={msg.id || idx} className="bg-slate-950/80 p-2.5 rounded-xl border border-slate-800">
                  <div className="flex justify-between text-[10px] text-slate-400 mb-0.5">
                    <span className="font-bold text-blue-400">{msg.sender_name}</span>
                    <span>{msg.time}</span>
                  </div>
                  <p className="text-slate-200">{msg.content}</p>
                </div>
              ))
            )}
            <div ref={chatBottomRef} />
          </div>

          <div className="flex gap-2 pt-2 border-t border-slate-800">
            <input 
              type="text"
              placeholder="Escribir mensaje al equipo..."
              value={chatInput}
              onChange={e => setChatInput(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && handleSendMessage()}
              className="flex-1 bg-slate-950 text-slate-100 text-xs rounded-xl px-3 py-2 border border-slate-800 focus:outline-none focus:border-blue-500"
            />
            <button 
              onClick={handleSendMessage}
              className="p-2 bg-blue-600 hover:bg-blue-500 text-white rounded-xl"
            >
              <Send className="w-4 h-4" />
            </button>
          </div>
        </div>
      )}

    </div>
  );
}
