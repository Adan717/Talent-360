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
  const { systemSettings, isModuleUnlocked, currentUser } = useAppStore();

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

  // Permisos específicos asignados a este colaborador por el Admin de la Empresa
  const userAllowedModules = currentUser?.employee?.allowed_modules || currentUser?.allowed_modules;
  const isUserRestricted = Array.isArray(userAllowedModules) && 
                           userAllowedModules.length > 0 && 
                           currentUser?.role !== 'admin' && 
                           currentUser?.role !== 'platform_admin' && 
                           currentUser?.system_role !== 'platform_admin';

  const isModulePermitted = (keys: string[]) => {
    if (!isUserRestricted) return true;
    return keys.some(k => userAllowedModules.includes(k));
  };

  const isRelojPermitted = isModulePermitted(['reloj', 'asistencia']);
  const isTareasPermitted = isModulePermitted(['operativo', 'tareas']);
  const isAcademiaPermitted = isModulePermitted(['academia']);
  const isNominaPermitted = isModulePermitted(['facturacion', 'reportes', 'nomina']);

  // Matriz de bloqueo según clockState
  const isTareasBlocked = clockState !== 'active';
  const isAcademiaBlocked = !isStoreClosed && clockState === 'active';
  const isNominaBlocked = clockState === 'inactive' || clockState === 'waiting_room';

  const handleTabClick = (tabKey: string, isBlocked: boolean, blockMsg: string) => {
    if (isBlocked) {
      if (showCustomAlert) {
        showCustomAlert(blockMsg);
      } else {
        alert(blockMsg);
      }
      return;
    }
    setPhoneTab(tabKey);
    setInnerTool(null);
  };

  const NOTCH_RADIUS_PX = 32;
  const NOTCH_CENTER_FROM_RIGHT_PX = 32;

  return (
    <div className="relative w-full max-w-[420px] mx-auto select-none font-sans filter drop-shadow-[0_10px_25px_rgba(0,0,0,0.12)]">
      {/* Background SVG Vector Shape (Clean white glass + exact #8a2be2 purple border outline) */}
      <svg 
        className="absolute inset-0 w-full h-full pointer-events-none overflow-visible" 
        viewBox="0 0 420 64" 
        preserveAspectRatio="none"
      >
        <path 
          d="M 32 0 L 325 0 C 300 0, 300 64, 325 64 L 32 64 A 32 32 0 0 1 32 0 Z" 
          className="fill-white/92 dark:fill-slate-950/92 stroke-purple-300/80 dark:stroke-purple-400/50 backdrop-blur-2xl"
          strokeWidth="1.5"
        />
      </svg>

      <nav className="relative z-10 pl-5 pr-24 py-1.5 flex items-center justify-around w-full min-h-[64px]">
        {/* 1. RELOJ CHECADOR */}
        {isRelojPermitted && (
          <button
            onClick={() => {
              setPhoneTab('checador');
              setInnerTool(null);
            }}
            className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1"
            style={phoneTab === 'checador' ? { color: checadorColor } : {}}
          >
            <div className={`w-9 h-9 xs:w-10 xs:h-10 rounded-full flex items-center justify-center transition-all ${
              phoneTab === 'checador' 
                ? 'bg-emerald-500/15 border-2 border-emerald-500 shadow-md shadow-emerald-500/20 scale-105' 
                : 'bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60'
            }`}>
              <Clock size={19} className={phoneTab === 'checador' ? 'animate-pulse text-emerald-500' : 'text-slate-400'} />
            </div>
            <span className={`text-[8px] xs:text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
              phoneTab === 'checador' ? 'font-black text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500'
            }`}>Reloj</span>
          </button>
        )}

        {/* 2. TAREAS */}
        {isTareasPermitted && (
          <button
            onClick={() => handleTabClick(
              'tareas',
              !isModuleUnlocked('operativo') || isTareasBlocked,
              !isModuleUnlocked('operativo')
                ? '⚠️ Módulo Rutinas y Tareas no habilitado para tu empresa.'
                : clockState === 'meal' || clockState === 'short_break'
                  ? '⚠️ Tareas Bloqueadas: Estás en tu horario de comida.'
                  : '⚠️ Debes registrar tu entrada laboral para acceder a este módulo.'
            )}
            className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1"
            style={phoneTab === 'tareas' ? { color: tareasColor } : {}}
          >
            <div className={`w-9 h-9 xs:w-10 xs:h-10 rounded-full flex items-center justify-center transition-all ${
              phoneTab === 'tareas'
                ? 'bg-blue-500/15 border-2 border-blue-500 shadow-md shadow-blue-500/20 scale-105'
                : `bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60 ${isTareasBlocked || !isModuleUnlocked('operativo') ? 'opacity-40' : ''}`
            }`}>
              <ListTodo size={19} className={phoneTab === 'tareas' ? 'text-blue-500' : 'text-slate-400'} />
            </div>
            <span className={`text-[8px] xs:text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
              phoneTab === 'tareas' ? 'font-black text-blue-600 dark:text-blue-400' : 'text-slate-400 dark:text-slate-500'
            } ${isTareasBlocked || !isModuleUnlocked('operativo') ? 'opacity-40' : ''}`}>Tareas</span>
          </button>
        )}

        {/* 3. ACADEMIA */}
        {isAcademiaPermitted && (
          <button
            onClick={() => handleTabClick(
              'academia',
              !isModuleUnlocked('academia') || isAcademiaBlocked,
              !isModuleUnlocked('academia')
                ? '⚠️ Módulo Academia 360 no habilitado para tu empresa.'
                : '⚠️ Academia Bloqueada: Enfócate en tus tareas de hoy. Estará disponible en tu hora de comida o fuera de turno.'
            )}
            className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1"
            style={phoneTab === 'academia' ? { color: academiaColor } : {}}
          >
            <div className={`w-9 h-9 xs:w-10 xs:h-10 rounded-full flex items-center justify-center transition-all ${
              phoneTab === 'academia'
                ? 'bg-violet-500/15 border-2 border-violet-600 dark:border-violet-500 shadow-md shadow-violet-500/20 scale-105'
                : `bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60 ${isAcademiaBlocked || !isModuleUnlocked('academia') ? 'opacity-40' : ''}`
            }`}>
              <GraduationCap size={19} className={phoneTab === 'academia' ? 'text-violet-600' : 'text-slate-400'} />
            </div>
            <span className={`text-[8px] xs:text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
              phoneTab === 'academia' ? 'font-black text-violet-600 dark:text-violet-400' : 'text-slate-400 dark:text-slate-500'
            } ${isAcademiaBlocked || !isModuleUnlocked('academia') ? 'opacity-40' : ''}`}>Academia</span>
          </button>
        )}

        {/* 4. NÓMINA */}
        {isNominaPermitted && (
          <button
            onClick={() => handleTabClick(
              'nomina', 
              (!isModuleUnlocked('reportes') && !isModuleUnlocked('facturacion')) || isNominaBlocked, 
              (!isModuleUnlocked('reportes') && !isModuleUnlocked('facturacion'))
                ? '⚠️ Módulo Nómina / Reportes no habilitado para tu empresa.'
                : '⚠️ Debes registrar tu entrada laboral para acceder a este módulo.'
            )}
            className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1"
            style={phoneTab === 'nomina' ? { color: nominaColor } : {}}
          >
            <div className={`w-9 h-9 xs:w-10 xs:h-10 rounded-full flex items-center justify-center transition-all ${
              phoneTab === 'nomina'
                ? 'bg-rose-500/15 border-2 border-rose-500 shadow-md shadow-rose-500/20 scale-105'
                : `bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60 ${isNominaBlocked || (!isModuleUnlocked('reportes') && !isModuleUnlocked('facturacion')) ? 'opacity-40' : ''}`
            }`}>
              <DollarSign size={19} className={phoneTab === 'nomina' ? 'text-rose-500' : 'text-slate-400'} />
            </div>
            <span className={`text-[8px] xs:text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
              phoneTab === 'nomina' ? 'font-black text-rose-600 dark:text-rose-400' : 'text-slate-400 dark:text-slate-500'
            } ${isNominaBlocked || (!isModuleUnlocked('reportes') && !isModuleUnlocked('facturacion')) ? 'opacity-40' : ''}`}>Nómina</span>
          </button>
        )}
      </nav>
    </div>
  );
}
