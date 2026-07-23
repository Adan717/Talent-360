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
}

// 2026-07-23 (a petición de Francisco): se quitó el ícono de "Herramientas" — ese espacio
// ahora lo ocupa el botón flotante único morado (ver RelojVisual.tsx, renderFloatingActionButton),
// posicionado exactamente en el mismo bottom-right que este contenedor para que ambos compartan
// el mismo eje vertical. La barra se hizo translúcida y tiene una muesca cóncava (mask-image)
// tallada del lado derecho para que el botón "encaje" en el contorno en vez de solo quedar
// pegado al lado. Si el botón cambia de tamaño/posición en RelojVisual.tsx, estos valores
// (NOTCH_RADIUS_PX / notch center) deben ajustarse junto con los de allá para que sigan alineados.
const NOTCH_RADIUS_PX = 34; // = mitad del botón flotante (68px de diámetro)
const NOTCH_CENTER_FROM_RIGHT_PX = 34; // el botón comparte el mismo `right` que esta barra

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
      className={`absolute bottom-3 left-2.5 right-2.5 z-[75] h-16 flex items-center justify-between pl-3 pr-20 border backdrop-blur-md rounded-full shadow-[0_8px_32px_rgba(0,0,0,0.08)] transition-all duration-200 ${
        isDark
          ? 'bg-slate-950/40 border-violet-900/40 shadow-[0_-8px_32px_rgba(124,58,237,0.12),0_8px_32px_rgba(124,58,237,0.1)] text-slate-400'
          : 'bg-white/40 border-violet-100/50 shadow-[0_-8px_32px_rgba(124,58,237,0.06),0_8px_32px_rgba(124,58,237,0.04)] text-slate-500'
      }`}
      style={{ WebkitMaskImage: notchMaskImage, maskImage: notchMaskImage }}
    >

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

    </nav>
  );
}
