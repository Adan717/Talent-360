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
  isStoreClosed?: boolean;
  isMobileFrame?: boolean;
}

const NOTCH_RADIUS_PX = 34; // = mitad del botón flotante (68px de diámetro)
const NOTCH_CENTER_FROM_RIGHT_PX = 34; // comparte el mismo extremo derecho

export function MobileBottomNav({ 
  phoneTab, 
  setPhoneTab, 
  setInnerTool, 
  isDark, 
  clockState, 
  showCustomAlert, 
  isStoreClosed = false, 
  isMobileFrame = false 
}: MobileBottomNavProps) {
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

  const notchMaskImage = `radial-gradient(circle ${NOTCH_RADIUS_PX}px at calc(100% - ${NOTCH_CENTER_FROM_RIGHT_PX}px) 50%, transparent ${NOTCH_RADIUS_PX}px, black ${NOTCH_RADIUS_PX + 1}px)`;

  return (
    <nav
      className={`z-[75] h-16 flex items-center justify-around pl-4 pr-20 border backdrop-blur-md rounded-full transition-all duration-300 ${
        isMobileFrame 
          ? 'absolute bottom-3 left-2.5 right-2.5' 
          : 'fixed bottom-3 left-2.5 right-2.5 shadow-[0_-8px_32px_rgba(0,0,0,0.18)]'
      } ${
        isDark
          ? 'bg-slate-950/90 border-violet-900/40 text-slate-400'
          : 'bg-white/95 border-slate-200/90 text-slate-600 shadow-xl shadow-slate-900/10'
      }`}
      style={{ WebkitMaskImage: notchMaskImage, maskImage: notchMaskImage }}
    >

      {/* 1. RELOJ */}
      <button
        onClick={() => handleTabClick('checador', false, '')}
        className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-1"
        style={phoneTab === 'checador' ? { color: checadorColor } : {}}
      >
        <div className={`w-10 h-10 rounded-full flex items-center justify-center transition-all ${
          phoneTab === 'checador'
            ? 'bg-emerald-500/15 border-2 border-emerald-500 shadow-md shadow-emerald-500/20 scale-105'
            : 'bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60'
        }`}>
          <Clock size={20} className={phoneTab === 'checador' ? 'animate-pulse text-emerald-500' : 'text-slate-400'} />
        </div>
        <span className={`text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
          phoneTab === 'checador' ? 'font-black text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500'
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
          className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-1"
          style={phoneTab === 'tareas' ? { color: tareasColor } : {}}
        >
          <div className={`w-10 h-10 rounded-full flex items-center justify-center transition-all ${
            phoneTab === 'tareas'
              ? 'bg-blue-500/15 border-2 border-blue-500 shadow-md shadow-blue-500/20 scale-105'
              : `bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60 ${isTareasBlocked ? 'opacity-40' : ''}`
          }`}>
            <ListTodo size={20} className={phoneTab === 'tareas' ? 'text-blue-500' : 'text-slate-400'} />
          </div>
          <span className={`text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
            phoneTab === 'tareas' ? 'font-black text-blue-600 dark:text-blue-400' : 'text-slate-400 dark:text-slate-500'
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
        className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-1"
        style={phoneTab === 'academia' ? { color: academiaColor } : {}}
      >
        <div className={`w-10 h-10 rounded-full flex items-center justify-center transition-all ${
          phoneTab === 'academia'
            ? 'bg-violet-500/15 border-2 border-violet-600 dark:border-violet-500 shadow-md shadow-violet-500/20 scale-105'
            : `bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60 ${isAcademiaBlocked ? 'opacity-40' : ''}`
        }`}>
          <GraduationCap size={20} className={phoneTab === 'academia' ? 'text-violet-600' : 'text-slate-400'} />
        </div>
        <span className={`text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
          phoneTab === 'academia' ? 'font-black text-violet-600 dark:text-violet-400' : 'text-slate-400 dark:text-slate-500'
        } ${isAcademiaBlocked ? 'opacity-40' : ''}`}>Academia</span>
      </button>

      {/* 4. NÓMINA */}
      {!isStoreClosed && (
        <button
          onClick={() => handleTabClick('nomina', isNominaBlocked, '⚠️ Debes registrar tu entrada laboral para acceder a este módulo.')}
          className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-1"
          style={phoneTab === 'nomina' ? { color: nominaColor } : {}}
        >
          <div className={`w-10 h-10 rounded-full flex items-center justify-center transition-all ${
            phoneTab === 'nomina'
              ? 'bg-rose-500/15 border-2 border-rose-500 shadow-md shadow-rose-500/20 scale-105'
              : `bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60 ${isNominaBlocked ? 'opacity-40' : ''}`
          }`}>
            <DollarSign size={20} className={phoneTab === 'nomina' ? 'text-rose-500' : 'text-slate-400'} />
          </div>
          <span className={`text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
            phoneTab === 'nomina' ? 'font-black text-rose-600 dark:text-rose-400' : 'text-slate-400 dark:text-slate-500'
          } ${isNominaBlocked ? 'opacity-40' : ''}`}>Nómina</span>
        </button>
      )}

    </nav>
  );
}
