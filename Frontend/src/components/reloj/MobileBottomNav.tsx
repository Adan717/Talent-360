import React from 'react';
import { Clock, ListTodo, GraduationCap, DollarSign, Wrench } from 'lucide-react';
import { useAppStore } from '../../store/useAppStore';
import { ColorMap } from '../SaaSAccountSettings';

export interface MobileBottomNavProps {
  phoneTab: string;
  setPhoneTab: (tab: string) => void;
  setInnerTool: (tool: string | null) => void;
  isDark: boolean;
  clockState: string;
  showCustomAlert?: (msg: string) => void;
  isStoreClosed?: boolean;
}

export function MobileBottomNav({ phoneTab, setPhoneTab, setInnerTool, isDark, clockState, showCustomAlert, isStoreClosed = false }: MobileBottomNavProps) {
  const { systemSettings } = useAppStore();

  const getModuleColorHex = (modId: string, defaultHex: string) => {
    const cust = systemSettings?.moduleCustomizations?.[modId];
    if (cust?.color && ColorMap[cust.color]) {
      return ColorMap[cust.color].hex;
    }
    switch (modId) {
      case 'asistencia': return '#10b981'; // emerald
      case 'operativo': return '#2563eb'; // blue
      case 'academia': return '#8a2be2'; // violet
      case 'facturacion': return '#f43f5e'; // rose
      default: return defaultHex;
    }
  };

  const checadorColor = getModuleColorHex('asistencia', '#10b981');
  const tareasColor = getModuleColorHex('operativo', '#2563eb');
  const academiaColor = getModuleColorHex('academia', '#8a2be2');
  const nominaColor = getModuleColorHex('facturacion', '#f43f5e');
  const herramientasColor = '#f59e0b'; // Amber

  // Matriz de bloqueo según clockState
  const isTareasBlocked = clockState !== 'active';
  const isAcademiaBlocked = !isStoreClosed && clockState === 'active';
  const isNominaBlocked = clockState === 'inactive' || clockState === 'waiting_room';

  const handleTabClick = (tab: string, isBlocked: boolean, blockMsg: string) => {
    if (isBlocked) {
      if (showCustomAlert) {
        showCustomAlert(blockMsg);
      } else {
        alert(blockMsg);
      }
      return;
    }
    setInnerTool(null);
    setPhoneTab(tab);
  };

  return (
    <nav className={`absolute bottom-3 left-2.5 right-2.5 z-[75] flex items-center justify-between py-2.5 px-3 border backdrop-blur-md rounded-full shadow-[0_8px_32px_rgba(0,0,0,0.08)] transition-all duration-200 ${
      isDark 
        ? 'bg-slate-950/85 border-violet-900/40 shadow-[0_-8px_32px_rgba(124,58,237,0.12),0_8px_32px_rgba(124,58,237,0.1)] text-slate-400' 
        : 'bg-white/85 border-violet-100/50 shadow-[0_-8px_32px_rgba(124,58,237,0.06),0_8px_32px_rgba(124,58,237,0.04)] text-slate-500'
    }`}>
      
      {/* 1. RELOJ */}
      <button 
        onClick={() => handleTabClick('checador', false, '')}
        className="flex flex-col items-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer"
        style={phoneTab === 'checador' ? { color: checadorColor } : {}}
      >
        <div className={`w-[38px] h-[38px] rounded-full flex items-center justify-center transition-all ${
          phoneTab === 'checador' 
            ? 'bg-emerald-500/10 border-2 border-emerald-500 shadow-sm scale-105' 
            : 'bg-slate-100 dark:bg-slate-900 border border-slate-200/30 dark:border-slate-800/30 hover:bg-slate-200/50 dark:hover:bg-slate-800/50'
        }`}>
          <Clock size={19} className={phoneTab === 'checador' ? 'animate-pulse' : ''} />
        </div>
        <span className={`text-[7.5px] uppercase tracking-wider font-black mt-0.5 ${
          phoneTab === 'checador' ? 'font-black' : 'text-slate-400 dark:text-slate-500'
        }`}>Reloj</span>
      </button>

      {/* 2. TAREAS */}
      {!isStoreClosed && (
        <button 
          onClick={() => handleTabClick(
            'tareas', 
            isTareasBlocked, 
            clockState === 'meal' || clockState === 'short_break'
              ? '⚠️ Tareas Bloqueadas: Estás en tu horario de comida.'
              : '⚠️ Debes registrar tu entrada laboral para acceder a este módulo.'
          )}
          className="flex flex-col items-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer"
          style={phoneTab === 'tareas' ? { color: tareasColor } : {}}
        >
          <div className={`w-[38px] h-[38px] rounded-full flex items-center justify-center transition-all ${
            phoneTab === 'tareas' 
              ? 'bg-blue-500/10 border-2 border-blue-500 shadow-sm scale-105' 
              : `bg-slate-100 dark:bg-slate-900 border border-slate-200/30 dark:border-slate-800/30 hover:bg-slate-200/50 dark:hover:bg-slate-800/50 ${isTareasBlocked ? 'opacity-40' : ''}`
          }`}>
            <ListTodo size={19} />
          </div>
          <span className={`text-[7.5px] uppercase tracking-wider font-black mt-0.5 ${
            phoneTab === 'tareas' ? 'font-black' : 'text-slate-400 dark:text-slate-500'
          } ${isTareasBlocked ? 'opacity-40' : ''}`}>Tareas</span>
        </button>
      )}

      {/* 3. ACADEMIA */}
      <button 
        onClick={() => handleTabClick(
          'academia', 
          isAcademiaBlocked, 
          '⚠️ Academia Bloqueada: Enfócate en tus tareas de hoy. Estará disponible en tu hora de comida o fuera de turno.'
        )}
        className="flex flex-col items-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer"
        style={phoneTab === 'academia' ? { color: academiaColor } : {}}
      >
        <div className={`w-[38px] h-[38px] rounded-full flex items-center justify-center transition-all ${
          phoneTab === 'academia' 
            ? 'bg-violet-500/10 border-2 border-violet-600 dark:border-violet-500 shadow-sm scale-105' 
            : `bg-slate-100 dark:bg-slate-900 border border-slate-200/30 dark:border-slate-800/30 hover:bg-slate-200/50 dark:hover:bg-slate-800/50 ${isAcademiaBlocked ? 'opacity-40' : ''}`
        }`}>
          <GraduationCap size={19} />
        </div>
        <span className={`text-[7.5px] uppercase tracking-wider font-black mt-0.5 ${
          phoneTab === 'academia' ? 'font-black' : 'text-slate-400 dark:text-slate-500'
        } ${isAcademiaBlocked ? 'opacity-40' : ''}`}>Academia</span>
      </button>

      {/* 4. NÓMINA */}
      {!isStoreClosed && (
        <button 
          onClick={() => handleTabClick('nomina', isNominaBlocked, '⚠️ Debes registrar tu entrada laboral para acceder a este módulo.')}
          className="flex flex-col items-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer"
          style={phoneTab === 'nomina' ? { color: nominaColor } : {}}
        >
          <div className={`w-[38px] h-[38px] rounded-full flex items-center justify-center transition-all ${
            phoneTab === 'nomina' 
              ? 'bg-rose-500/10 border-2 border-rose-500 shadow-sm scale-105' 
              : `bg-slate-100 dark:bg-slate-900 border border-slate-200/30 dark:border-slate-800/30 hover:bg-slate-200/50 dark:hover:bg-slate-800/50 ${isNominaBlocked ? 'opacity-40' : ''}`
          }`}>
            <DollarSign size={19} />
          </div>
          <span className={`text-[7.5px] uppercase tracking-wider font-black mt-0.5 ${
            phoneTab === 'nomina' ? 'font-black' : 'text-slate-400 dark:text-slate-500'
          } ${isNominaBlocked ? 'opacity-40' : ''}`}>Nómina</span>
        </button>
      )}

      {/* 5. HERRAMIENTAS */}
      <button 
        onClick={() => handleTabClick('herramientas', false, '')}
        className="flex flex-col items-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer"
        style={phoneTab === 'herramientas' ? { color: herramientasColor } : {}}
      >
        <div className={`w-[38px] h-[38px] rounded-full flex items-center justify-center transition-all ${
          phoneTab === 'herramientas' 
            ? 'bg-amber-500/10 border-2 border-amber-500 shadow-sm scale-105' 
            : 'bg-slate-100 dark:bg-slate-900 border border-slate-200/30 dark:border-slate-800/30 hover:bg-slate-200/50 dark:hover:bg-slate-800/50'
        }`}>
          <Wrench size={19} />
        </div>
        <span className={`text-[7.5px] uppercase tracking-wider font-black mt-0.5 ${
          phoneTab === 'herramientas' ? 'font-black' : 'text-slate-400 dark:text-slate-500'
        }`}>Herramientas</span>
      </button>

    </nav>
  );
}
