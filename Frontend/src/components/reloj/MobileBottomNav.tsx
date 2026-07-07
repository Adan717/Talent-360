import React from 'react';
import { Clock, ListTodo, GraduationCap, DollarSign } from 'lucide-react';
import { useAppStore } from '../../store/useAppStore';
import { ColorMap } from '../SaaSAccountSettings';

export interface MobileBottomNavProps {
  phoneTab: string;
  setPhoneTab: (tab: string) => void;
  setInnerTool: (tool: string | null) => void;
  isDark: boolean;
  clockState: string;
  showCustomAlert?: (msg: string) => void;
}

export function MobileBottomNav({ phoneTab, setPhoneTab, setInnerTool, isDark, clockState, showCustomAlert }: MobileBottomNavProps) {
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

  const checadorColor = getModuleColorHex('asistencia', '#8a2be2');
  const tareasColor = getModuleColorHex('operativo', '#8a2be2');
  const academiaColor = getModuleColorHex('academia', '#8a2be2');
  const nominaColor = getModuleColorHex('facturacion', '#8a2be2');

  // Matriz de bloqueo según clockState
  const isTareasBlocked = clockState !== 'active';
  const isAcademiaBlocked = clockState === 'active';
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
    <nav className={`absolute bottom-3 left-3 right-3 z-[75] flex items-center justify-around py-3 px-2 border backdrop-blur-md rounded-full shadow-[0_8px_32px_rgba(0,0,0,0.08)] transition-all duration-200 ${
      isDark 
        ? 'bg-slate-955/80 border-violet-900/40 shadow-[0_-8px_32px_rgba(124,58,237,0.12),0_8px_32px_rgba(124,58,237,0.1)] text-slate-400' 
        : 'bg-white/80 border-violet-100/50 shadow-[0_-8px_32px_rgba(124,58,237,0.06),0_8px_32px_rgba(124,58,237,0.04)] text-slate-500'
    }`}>
      <button 
        onClick={() => handleTabClick('checador', false, '')}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'checador' ? 'font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
        style={phoneTab === 'checador' ? { color: checadorColor } : {}}
      >
        <Clock size={22} className={phoneTab === 'checador' ? 'animate-pulse' : ''} style={phoneTab === 'checador' ? { color: checadorColor } : {}} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Reloj</span>
      </button>
      
      <button 
        onClick={() => handleTabClick(
          'tareas', 
          isTareasBlocked, 
          clockState === 'meal' || clockState === 'short_break'
            ? '⚠️ Tareas Bloqueadas: Estás en tu horario de comida.'
            : '⚠️ Debes registrar tu entrada laboral para acceder a este módulo.'
        )}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'tareas' ? 'font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        } ${isTareasBlocked ? 'opacity-40 cursor-not-allowed' : ''}`}
        style={phoneTab === 'tareas' ? { color: tareasColor } : {}}
      >
        <ListTodo size={22} className="" style={phoneTab === 'tareas' ? { color: tareasColor } : {}} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Tareas</span>
      </button>
      
      <button 
        onClick={() => handleTabClick(
          'academia', 
          isAcademiaBlocked, 
          '⚠️ Academia Bloqueada: Enfócate en tus tareas de hoy. Estará disponible en tu hora de comida o fuera de turno.'
        )}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'academia' ? 'font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        } ${isAcademiaBlocked ? 'opacity-40 cursor-not-allowed' : ''}`}
        style={phoneTab === 'academia' ? { color: academiaColor } : {}}
      >
        <GraduationCap size={22} className="" style={phoneTab === 'academia' ? { color: academiaColor } : {}} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Academia</span>
      </button>
      
      <button 
        onClick={() => handleTabClick('nomina', isNominaBlocked, '⚠️ Debes registrar tu entrada laboral para acceder a este módulo.')}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'nomina' ? 'font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        } ${isNominaBlocked ? 'opacity-40 cursor-not-allowed' : ''}`}
        style={phoneTab === 'nomina' ? { color: nominaColor } : {}}
      >
        <DollarSign size={22} className="" style={phoneTab === 'nomina' ? { color: nominaColor } : {}} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Nómina</span>
      </button>
    </nav>
  );
}
