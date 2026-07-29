import React, { useState, useEffect } from 'react';
import { Users, ListTodo, Briefcase } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { echoInstance } from '../lib/echo';
import { useAppStore } from '../store/useAppStore';

interface HeaderStatsProps {
  activeModule: string;
  setActiveModule: (mod: string) => void;
}

interface HeaderStatsData {
  active_users: number;
  vacancies: number;
  tasks: number;
  courses: number;
  retardos_hoy: number;
  cumplimiento: number;
  candidates_count: number;
  candidates_recent_activity: boolean;
  employees_health: 'green' | 'amber' | 'red' | 'gray';
  employees_clocked_in_count: number;
  employees_idle_count: number;
  tasks_health: 'green' | 'amber' | 'red' | 'gray';
  tasks_pending_count: number;
  tasks_completed_count: number;
  tasks_total_today: number;
  prospects_health: 'green' | 'amber' | 'red' | 'gray';
  prospects_count: number;
}

const DEFAULT_STATS: HeaderStatsData = {
  active_users: 0,
  vacancies: 0,
  tasks: 0,
  courses: 0,
  retardos_hoy: 0,
  cumplimiento: 100,
  candidates_count: 0,
  candidates_recent_activity: false,
  employees_health: 'gray',
  employees_clocked_in_count: 0,
  employees_idle_count: 0,
  tasks_health: 'gray',
  tasks_pending_count: 0,
  tasks_completed_count: 0,
  tasks_total_today: 0,
  prospects_health: 'gray',
  prospects_count: 0
};

export const HeaderStats: React.FC<HeaderStatsProps> = ({ activeModule, setActiveModule }) => {
  const [stats, setStats] = useState<HeaderStatsData>(DEFAULT_STATS);
  const [hoveredPill, setHoveredPill] = useState<'rrhh' | 'operativo' | 'ats' | null>(null);
  const { currentUser } = useAppStore();

  const fetchStats = async () => {
    try {
      const response = await axiosInstance.get('/admin/dashboard/stats');
      if (response.status === 200 && response.data.data) {
        setStats(response.data.data);
      }
    } catch (error) {
      console.error('Error fetching header stats:', error);
    }
  };

  useEffect(() => {
    fetchStats();

    // Configurar Laravel Echo para actualizaciones en tiempo real
    const token = localStorage.getItem('talent_auth_token');
    if (echoInstance && echoInstance.options && echoInstance.options.auth) {
      echoInstance.options.auth.headers.Authorization = `Bearer ${token}`;
    }

    let channelName = '';
    if (currentUser?.tenant_id) {
      channelName = `tenant.${currentUser.tenant_id}`;
      echoInstance.private(channelName)
        .listen('.MonitorUpdated', () => {
          fetchStats();
        });
    }

    // Polling secundario
    const interval = setInterval(fetchStats, 20000);

    return () => {
      clearInterval(interval);
      if (channelName && echoInstance) {
        echoInstance.leave(channelName);
      }
    };
  }, [currentUser]);

  // Paleta de salud dinámica
  const getHealthBadge = (health: 'green' | 'amber' | 'red' | 'gray') => {
    switch (health) {
      case 'green':
        return {
          bg: 'bg-emerald-500',
          pulse: 'bg-emerald-400',
          border: 'border-emerald-200 hover:border-emerald-400',
          text: 'text-emerald-700',
          lightBg: 'bg-emerald-50 text-emerald-700 border-emerald-200',
          label: 'Óptimo',
          glow: 'shadow-emerald-500/20'
        };
      case 'amber':
        return {
          bg: 'bg-amber-500',
          pulse: 'bg-amber-400',
          border: 'border-amber-200 hover:border-amber-400',
          text: 'text-amber-700',
          lightBg: 'bg-amber-50 text-amber-700 border-amber-200',
          label: 'Atención',
          glow: 'shadow-amber-500/20'
        };
      case 'red':
        return {
          bg: 'bg-rose-500',
          pulse: 'bg-rose-400',
          border: 'border-rose-200 hover:border-rose-400',
          text: 'text-rose-700',
          lightBg: 'bg-rose-50 text-rose-700 border-rose-200',
          label: 'Alerta',
          glow: 'shadow-rose-500/20'
        };
      case 'gray':
      default:
        return {
          bg: 'bg-slate-400',
          pulse: 'bg-slate-300',
          border: 'border-slate-200 hover:border-slate-300',
          text: 'text-slate-500',
          lightBg: 'bg-slate-100 text-slate-600 border-slate-200',
          label: 'Sin Actividad',
          glow: 'shadow-slate-500/10'
        };
    }
  };

  const renderHealthIndicator = (health: 'green' | 'amber' | 'red' | 'gray') => {
    const badge = getHealthBadge(health);
    return (
      <span className="relative flex h-2.5 w-2.5 shrink-0 items-center justify-center">
        {health !== 'gray' && (
          <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${badge.pulse}`}></span>
        )}
        <span className={`relative inline-flex rounded-full h-2.5 w-2.5 ${badge.bg}`}></span>
      </span>
    );
  };

  const displayEmployees = stats.active_users;
  const displayTasks = stats.tasks_pending_count;
  const displayProspects = stats.prospects_count;

  return (
    <div className="flex items-center gap-2.5 bg-slate-100/70 p-1.5 rounded-3xl border border-slate-200/80 shadow-xs max-w-full overflow-x-auto scrollbar-none shrink-0 relative">
      {/* 1. EMPLEADOS PILL */}
      <div className="relative" onMouseEnter={() => setHoveredPill('rrhh')} onMouseLeave={() => setHoveredPill(null)}>
        <button 
          onClick={() => setActiveModule('rrhh')}
          className={`flex items-center gap-2 px-3.5 py-2 rounded-2xl border text-xs sm:text-sm font-black transition-all duration-300 select-none cursor-pointer active:scale-95 ${
            activeModule === 'rrhh'
              ? 'border-blue-600 bg-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.02]'
              : 'border-slate-200 bg-white text-slate-800 hover:bg-slate-50 hover:border-blue-300 hover:shadow-sm'
          }`}
        >
          <Users size={16} className={activeModule === 'rrhh' ? "text-white" : "text-blue-600"} />
          <span>Empleados</span>
          <span className={`px-2 py-0.5 rounded-lg text-xs font-black leading-none shrink-0 transition-colors ${
            activeModule === 'rrhh'
              ? 'bg-white/20 text-white border border-white/30'
              : 'bg-blue-50 text-blue-700 border border-blue-100'
          }`}>
            {displayEmployees}
          </span>
          {renderHealthIndicator(stats.employees_health)}
        </button>

        {/* Hover Tooltip Card */}
        {hoveredPill === 'rrhh' && (
          <div className="absolute top-full left-0 mt-2 w-56 bg-slate-900/95 text-white p-3 rounded-2xl shadow-2xl border border-slate-700/60 backdrop-blur-xl z-50 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between border-b border-slate-800 pb-2 mb-2">
              <span className="text-xs font-bold text-slate-300 flex items-center gap-1.5">
                <Users size={14} className="text-blue-400" /> Plantilla Laboral
              </span>
              <span className={`text-[10px] font-black px-2 py-0.5 rounded-full uppercase border ${getHealthBadge(stats.employees_health).lightBg}`}>
                {getHealthBadge(stats.employees_health).label}
              </span>
            </div>
            <div className="space-y-1 text-xs">
              <div className="flex justify-between text-slate-300">
                <span>Activos Totales:</span>
                <strong className="text-white font-black">{stats.active_users}</strong>
              </div>
              <div className="flex justify-between text-slate-300">
                <span>En Turno Hoy:</span>
                <strong className="text-emerald-400 font-black">{stats.employees_clocked_in_count || 0}</strong>
              </div>
              <div className="flex justify-between text-slate-300">
                <span>Sin Tarea Asignada:</span>
                <strong className={stats.employees_idle_count > 0 ? "text-rose-400 font-black" : "text-slate-400"}>
                  {stats.employees_idle_count || 0}
                </strong>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* 2. TAREAS PILL */}
      <div className="relative" onMouseEnter={() => setHoveredPill('operativo')} onMouseLeave={() => setHoveredPill(null)}>
        <button 
          onClick={() => setActiveModule('operativo')}
          className={`flex items-center gap-2 px-3.5 py-2 rounded-2xl border text-xs sm:text-sm font-black transition-all duration-300 select-none cursor-pointer active:scale-95 ${
            activeModule === 'operativo'
              ? 'border-indigo-600 bg-indigo-600 text-white shadow-md shadow-indigo-500/25 scale-[1.02]'
              : 'border-slate-200 bg-white text-slate-800 hover:bg-slate-50 hover:border-indigo-300 hover:shadow-sm'
          }`}
        >
          <ListTodo size={16} className={activeModule === 'operativo' ? "text-white" : "text-indigo-600"} />
          <span>Tareas</span>
          <span className={`px-2 py-0.5 rounded-lg text-xs font-black leading-none shrink-0 transition-colors ${
            activeModule === 'operativo'
              ? 'bg-white/20 text-white border border-white/30'
              : 'bg-purple-50 text-purple-700 border border-purple-100'
          }`}>
            {displayTasks}
          </span>
          {renderHealthIndicator(stats.tasks_health)}
        </button>

        {/* Hover Tooltip Card */}
        {hoveredPill === 'operativo' && (
          <div className="absolute top-full left-0 mt-2 w-56 bg-slate-900/95 text-white p-3 rounded-2xl shadow-2xl border border-slate-700/60 backdrop-blur-xl z-50 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between border-b border-slate-800 pb-2 mb-2">
              <span className="text-xs font-bold text-slate-300 flex items-center gap-1.5">
                <ListTodo size={14} className="text-purple-400" /> Operación Diaria
              </span>
              <span className={`text-[10px] font-black px-2 py-0.5 rounded-full uppercase border ${getHealthBadge(stats.tasks_health).lightBg}`}>
                {getHealthBadge(stats.tasks_health).label}
              </span>
            </div>
            <div className="space-y-1 text-xs">
              <div className="flex justify-between text-slate-300">
                <span>Pendientes Hoy:</span>
                <strong className="text-amber-400 font-black">{stats.tasks_pending_count || 0}</strong>
              </div>
              <div className="flex justify-between text-slate-300">
                <span>Completadas:</span>
                <strong className="text-emerald-400 font-black">{stats.tasks_completed_count || 0}</strong>
              </div>
              <div className="flex justify-between text-slate-300">
                <span>Total Asignadas:</span>
                <strong className="text-white font-black">{stats.tasks_total_today || 0}</strong>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* 3. PROSPECTOS PILL */}
      <div className="relative" onMouseEnter={() => setHoveredPill('ats')} onMouseLeave={() => setHoveredPill(null)}>
        <button 
          onClick={() => setActiveModule('ats')}
          className={`flex items-center gap-2 px-3.5 py-2 rounded-2xl border text-xs sm:text-sm font-black transition-all duration-300 select-none cursor-pointer active:scale-95 ${
            activeModule === 'ats'
              ? 'border-emerald-600 bg-emerald-600 text-white shadow-md shadow-emerald-500/25 scale-[1.02]'
              : 'border-slate-200 bg-white text-slate-800 hover:bg-slate-50 hover:border-emerald-300 hover:shadow-sm'
          }`}
        >
          <Briefcase size={16} className={activeModule === 'ats' ? "text-white" : "text-emerald-600"} />
          <span>Prospectos</span>
          <span className={`px-2 py-0.5 rounded-lg text-xs font-black leading-none shrink-0 transition-colors ${
            activeModule === 'ats'
              ? 'bg-white/20 text-white border border-white/30'
              : 'bg-emerald-50 text-emerald-700 border border-emerald-100'
          }`}>
            {displayProspects}
          </span>
          {renderHealthIndicator(stats.prospects_health)}
        </button>

        {/* Hover Tooltip Card */}
        {hoveredPill === 'ats' && (
          <div className="absolute top-full right-0 sm:right-auto sm:left-0 mt-2 w-56 bg-slate-900/95 text-white p-3 rounded-2xl shadow-2xl border border-slate-700/60 backdrop-blur-xl z-50 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between border-b border-slate-800 pb-2 mb-2">
              <span className="text-xs font-bold text-slate-300 flex items-center gap-1.5">
                <Briefcase size={14} className="text-emerald-400" /> Reclutamiento ATS
              </span>
              <span className={`text-[10px] font-black px-2 py-0.5 rounded-full uppercase border ${getHealthBadge(stats.prospects_health).lightBg}`}>
                {getHealthBadge(stats.prospects_health).label}
              </span>
            </div>
            <div className="space-y-1 text-xs">
              <div className="flex justify-between text-slate-300">
                <span>En Proceso:</span>
                <strong className="text-white font-black">{stats.prospects_count || 0}</strong>
              </div>
              <div className="flex justify-between text-slate-300">
                <span>Actividad (24h):</span>
                <strong className={stats.candidates_recent_activity ? "text-emerald-400 font-black" : "text-amber-400 font-black"}>
                  {stats.candidates_recent_activity ? 'Reciente' : 'Sin cambios'}
                </strong>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};


export default HeaderStats;
