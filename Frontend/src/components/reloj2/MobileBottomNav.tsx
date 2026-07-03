import React from 'react';
import { Clock, CheckSquare, GraduationCap, DollarSign } from 'lucide-react';
import { useAppStore } from '../../store/useAppStore';
import { ColorMap } from '../SaaSAccountSettings';

export interface MobileBottomNavProps {
  phoneTab: string;
  setPhoneTab: (tab: string) => void;
  setInnerTool: (tool: string | null) => void;
  isDark: boolean;
}

export function MobileBottomNav({ phoneTab, setPhoneTab, setInnerTool, isDark }: MobileBottomNavProps) {
  const { systemSettings } = useAppStore();

  const getModuleColorHex = (modId: string, defaultHex: string) => {
    const cust = systemSettings?.moduleCustomizations?.[modId];
    if (cust?.color && ColorMap[cust.color]) {
      return ColorMap[cust.color].hex;
    }
    switch (modId) {
      case 'asistencia': return '#10b981'; // emerald
      case 'operativo': return '#2563eb'; // blue
      case 'academia': return '#0ea5e9'; // sky
      case 'facturacion': return '#10b981'; // emerald
      default: return defaultHex;
    }
  };

  const checadorColor = getModuleColorHex('asistencia', '#8a2be2');
  const tareasColor = getModuleColorHex('operativo', '#8a2be2');
  const academiaColor = getModuleColorHex('academia', '#8a2be2');
  const nominaColor = getModuleColorHex('facturacion', '#8a2be2');

  return (
    <nav className={`absolute bottom-3 left-3 right-3 z-[75] flex items-center justify-around py-3 px-2 border backdrop-blur-md rounded-full shadow-[0_8px_32px_rgba(0,0,0,0.08)] transition-all duration-200 ${
      isDark 
        ? 'bg-slate-955/80 border-violet-900/40 shadow-[0_-8px_32px_rgba(124,58,237,0.12),0_8px_32px_rgba(124,58,237,0.1)] text-slate-400' 
        : 'bg-white/80 border-violet-100/50 shadow-[0_-8px_32px_rgba(124,58,237,0.06),0_8px_32px_rgba(124,58,237,0.04)] text-slate-500'
    }`}>
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('checador'); }}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'checador' ? 'font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
        style={phoneTab === 'checador' ? { color: checadorColor } : {}}
      >
        <Clock size={22} className={phoneTab === 'checador' ? 'animate-pulse' : ''} style={phoneTab === 'checador' ? { color: checadorColor } : {}} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Reloj</span>
      </button>
      
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('tareas'); }}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'tareas' ? 'font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
        style={phoneTab === 'tareas' ? { color: tareasColor } : {}}
      >
        <CheckSquare size={22} className="" style={phoneTab === 'tareas' ? { color: tareasColor } : {}} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Tareas</span>
      </button>
      
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('academia'); }}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'academia' ? 'font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
        style={phoneTab === 'academia' ? { color: academiaColor } : {}}
      >
        <GraduationCap size={22} className="" style={phoneTab === 'academia' ? { color: academiaColor } : {}} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Academia</span>
      </button>
      
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('nomina'); }}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'nomina' ? 'font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
        style={phoneTab === 'nomina' ? { color: nominaColor } : {}}
      >
        <DollarSign size={22} className="" style={phoneTab === 'nomina' ? { color: nominaColor } : {}} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Nómina</span>
      </button>
    </nav>
  );
}
