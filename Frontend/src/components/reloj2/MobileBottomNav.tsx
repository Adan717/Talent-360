import React from 'react';
import { Clock, CheckSquare, GraduationCap } from 'lucide-react';

export interface MobileBottomNavProps {
  phoneTab: string;
  setPhoneTab: (tab: string) => void;
  setInnerTool: (tool: string | null) => void;
  isDark: boolean;
}

export function MobileBottomNav({ phoneTab, setPhoneTab, setInnerTool, isDark }: MobileBottomNavProps) {
  return (
    <nav className={`absolute bottom-3 left-3 right-3 z-[75] flex items-center justify-around py-2 px-1.5 border backdrop-blur-md rounded-full shadow-[0_8px_32px_rgba(0,0,0,0.08)] transition-all duration-200 ${
      isDark 
        ? 'bg-slate-955/80 border-violet-900/40 shadow-[0_-8px_32px_rgba(124,58,237,0.12),0_8px_32px_rgba(124,58,237,0.1)] text-slate-400' 
        : 'bg-white/80 border-violet-100/50 shadow-[0_-8px_32px_rgba(124,58,237,0.06),0_8px_32px_rgba(124,58,237,0.04)] text-slate-500'
    }`}>
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('checador'); }}
        className={`flex flex-col items-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'checador' ? 'text-[#8a2be2] font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
      >
        <Clock size={19} className={phoneTab === 'checador' ? 'animate-pulse text-[#8a2be2]' : ''} />
        <span className="text-[8px] uppercase tracking-wider font-extrabold mt-0.5">Reloj</span>
      </button>
      
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('tareas'); }}
        className={`flex flex-col items-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'tareas' ? 'text-[#8a2be2] font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
      >
        <CheckSquare size={19} className={phoneTab === 'tareas' ? 'text-[#8a2be2]' : ''} />
        <span className="text-[8px] uppercase tracking-wider font-extrabold mt-0.5">Tareas</span>
      </button>
      
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('academia'); }}
        className={`flex flex-col items-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'academia' ? 'text-[#8a2be2] font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
      >
        <GraduationCap size={19} className={phoneTab === 'academia' ? 'text-[#8a2be2]' : ''} />
        <span className="text-[8px] uppercase tracking-wider font-extrabold mt-0.5">Academia</span>
      </button>
    </nav>
  );
}
