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

    // Polling secundario por si acaso
    const interval = setInterval(fetchStats, 20000);

    return () => {
      clearInterval(interval);
      if (channelName && echoInstance) {
        echoInstance.leave(channelName);
      }
    };
  }, [currentUser]);

  // Colores de salud
  const getHealthColors = (health: 'green' | 'amber' | 'red' | 'gray') => {
    switch (health) {
      case 'green':
        return {
          bg: 'bg-emerald-500',
          pulse: 'bg-emerald-400',
          border: 'border-emerald-200 hover:border-emerald-300',
          text: 'text-emerald-700',
          bgPill: 'hover:bg-emerald-50/50'
        };
      case 'amber':
        return {
          bg: 'bg-amber-500',
          pulse: 'bg-amber-400',
          border: 'border-amber-200 hover:border-amber-300',
          text: 'text-amber-700',
          bgPill: 'hover:bg-amber-50/50'
        };
      case 'red':
        return {
          bg: 'bg-rose-500',
          pulse: 'bg-rose-400',
          border: 'border-rose-200 hover:border-rose-300',
          text: 'text-rose-700',
          bgPill: 'hover:bg-rose-50/50'
        };
      case 'gray':
      default:
        return {
          bg: 'bg-slate-400',
          pulse: 'bg-slate-300',
          border: 'border-slate-200 hover:border-slate-300',
          text: 'text-slate-500',
          bgPill: 'hover:bg-slate-50/50'
        };
    }
  };

  const renderHealthIndicator = (health: 'green' | 'amber' | 'red' | 'gray') => {
    const colors = getHealthColors(health);
    return (
      <span className="relative flex h-2.5 w-2.5 shrink-0">
        {health !== 'gray' && (
          <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${colors.pulse}`}></span>
        )}
        <span className={`relative inline-flex rounded-full h-2.5 w-2.5 ${colors.bg}`}></span>
      </span>
    );
  };

  const getPillClassName = (modId: string, health: 'green' | 'amber' | 'red' | 'gray') => {
    const isActive = activeModule === modId;
    const colors = getHealthColors(health);
    
    const baseClass = "flex items-center gap-2 px-3.5 py-2 rounded-2xl border text-xs sm:text-sm transition-all duration-300 cursor-pointer select-none font-black shadow-sm";
    
    if (isActive) {
      return `${baseClass} border-blue-600 bg-blue-50 text-blue-700 shadow-md shadow-blue-500/10 scale-102`;
    }
    
    return `${baseClass} border-slate-200 bg-white text-slate-700 ${colors.bgPill} hover:shadow-md active:scale-98`;
  };

  // Cantidad de Empleados: Activos totales
  const displayEmployees = stats.active_users;
  // Cantidad de Tareas: Pendientes
  const displayTasks = stats.tasks_pending_count;
  // Cantidad de Prospectos: Reclutamiento
  const displayProspects = stats.prospects_count;

  return (
    <div className="flex items-center gap-2 sm:gap-3 bg-slate-100/50 p-1.5 rounded-3xl border border-slate-200/60 shadow-inner max-w-full overflow-x-auto scrollbar-none shrink-0">
      {/* Empleados Pill */}
      <div 
        onClick={() => setActiveModule('rrhh')}
        className={getPillClassName('rrhh', stats.employees_health)}
        title={`Empleados activos: ${displayEmployees}. Salud: ${stats.employees_health.toUpperCase()}`}
      >
        <Users size={16} className={activeModule === 'rrhh' ? "text-blue-600" : "text-slate-400"} />
        <span>Empleados</span>
        <span className="bg-slate-100 text-slate-800 px-2 py-0.5 rounded-lg text-xs font-black leading-none shrink-0">
          {displayEmployees}
        </span>
        {renderHealthIndicator(stats.employees_health)}
      </div>

      {/* Tareas Pill */}
      <div 
        onClick={() => setActiveModule('operativo')}
        className={getPillClassName('operativo', stats.tasks_health)}
        title={`Tareas pendientes hoy: ${displayTasks}. Salud: ${stats.tasks_health.toUpperCase()}`}
      >
        <ListTodo size={16} className={activeModule === 'operativo' ? "text-blue-600" : "text-slate-400"} />
        <span>Tareas</span>
        <span className="bg-slate-100 text-slate-800 px-2 py-0.5 rounded-lg text-xs font-black leading-none shrink-0">
          {displayTasks}
        </span>
        {renderHealthIndicator(stats.tasks_health)}
      </div>

      {/* Prospectos Pill */}
      <div 
        onClick={() => setActiveModule('ats')}
        className={getPillClassName('ats', stats.prospects_health)}
        title={`Prospectos en reclutamiento: ${displayProspects}. Salud: ${stats.prospects_health.toUpperCase()}`}
      >
        <Briefcase size={16} className={activeModule === 'ats' ? "text-blue-600" : "text-slate-400"} />
        <span>Prospectos</span>
        <span className="bg-slate-100 text-slate-800 px-2 py-0.5 rounded-lg text-xs font-black leading-none shrink-0">
          {displayProspects}
        </span>
        {renderHealthIndicator(stats.prospects_health)}
      </div>
    </div>
  );
};

export default HeaderStats;
