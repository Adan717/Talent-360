import React from 'react';
import { Clock, CheckSquare, GraduationCap, Settings } from 'lucide-react';

export interface MobileBottomNavProps {
  phoneTab: string;
  setPhoneTab: (tab: string) => void;
  setInnerTool: (tool: string | null) => void;
  isDark: boolean;
}

export function MobileBottomNav({ phoneTab, setPhoneTab, setInnerTool, isDark }: MobileBottomNavProps) {
  return (
    <nav className={`mt-auto flex items-center justify-around py-3.5 px-2 border-t z-30 backdrop-blur-md rounded-2xl shrink-0 ${
      isDark 
        ? 'bg-slate-900/80 border-slate-805 text-slate-400' 
        : 'bg-white/80 border-slate-200 shadow-lg text-slate-500'
    }`}>
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('checador'); }}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'checador' ? 'text-[#8a2be2] font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
      >
        <Clock size={22} className={phoneTab === 'checador' ? 'animate-pulse text-[#8a2be2]' : ''} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Reloj</span>
      </button>
      
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('tareas'); }}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'tareas' ? 'text-[#8a2be2] font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
      >
        <CheckSquare size={22} className={phoneTab === 'tareas' ? 'text-[#8a2be2]' : ''} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Tareas</span>
      </button>
      
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('academia'); }}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          phoneTab === 'academia' ? 'text-[#8a2be2] font-extrabold scale-105' : 'hover:text-slate-850 dark:hover:text-slate-200'
        }`}
      >
        <GraduationCap size={22} className={phoneTab === 'academia' ? 'text-[#8a2be2]' : ''} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Academia</span>
      </button>
      
      <button 
        onClick={() => { setInnerTool(null); setPhoneTab('herramientas'); }}
        className={`flex flex-col items-center gap-1 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
          ['herramientas', 'evaluacion360'].includes(phoneTab) ? 'text-[#8a2be2] font-extrabold scale-105' : 'hover:text-slate-855 dark:hover:text-slate-200'
        }`}
      >
        <Settings size={22} className={['herramientas', 'evaluacion360'].includes(phoneTab) ? 'text-[#8a2be2]' : ''} />
        <span className="text-[9.5px] uppercase tracking-wider font-extrabold mt-0.5">Herramientas</span>
      </button>
    </nav>
  );
}
