import React from 'react';
import { Plus } from 'lucide-react';

export interface MobileDockItem {
  id: string;
  label: string;
  icon: React.ReactNode;
  badge?: string | number;
}

export type ColorTheme = 'blue' | 'indigo' | 'purple' | 'sky' | 'amber' | 'emerald';

export interface MobileModuleBottomDockProps {
  items: MobileDockItem[];
  activeTab: string;
  onSelectTab: (id: string) => void;
  colorTheme?: ColorTheme;
  fabIcon?: React.ReactNode;
  onFabClick?: () => void;
  fabTitle?: string;
}

const themeStyles: Record<ColorTheme, {
  stroke: string;
  activeBg: string;
  activeBorder: string;
  activeShadow: string;
  activeText: string;
  fabGradient: string;
  fabShadow: string;
  fabGlowBg: string;
}> = {
  blue: {
    stroke: 'stroke-blue-400/80 dark:stroke-blue-400/50',
    activeBg: 'bg-blue-500/15',
    activeBorder: 'border-blue-500',
    activeShadow: 'shadow-blue-500/20',
    activeText: 'text-blue-600 dark:text-blue-400',
    fabGradient: 'from-blue-600 via-blue-500 to-indigo-600 hover:from-blue-500 hover:to-indigo-500',
    fabShadow: 'shadow-[0_0_35px_rgba(37,99,235,0.7)]',
    fabGlowBg: 'bg-blue-600/40'
  },
  indigo: {
    stroke: 'stroke-indigo-400/80 dark:stroke-indigo-400/50',
    activeBg: 'bg-indigo-500/15',
    activeBorder: 'border-indigo-500',
    activeShadow: 'shadow-indigo-500/20',
    activeText: 'text-indigo-600 dark:text-indigo-400',
    fabGradient: 'from-indigo-600 via-indigo-500 to-violet-600 hover:from-indigo-500 hover:to-violet-500',
    fabShadow: 'shadow-[0_0_35px_rgba(99,102,241,0.7)]',
    fabGlowBg: 'bg-indigo-600/40'
  },
  purple: {
    stroke: 'stroke-purple-400/80 dark:stroke-purple-400/50',
    activeBg: 'bg-purple-500/15',
    activeBorder: 'border-purple-500',
    activeShadow: 'shadow-purple-500/20',
    activeText: 'text-purple-600 dark:text-purple-400',
    fabGradient: 'from-violet-600 via-purple-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500',
    fabShadow: 'shadow-[0_0_35px_rgba(168,85,247,0.7)]',
    fabGlowBg: 'bg-purple-600/40'
  },
  sky: {
    stroke: 'stroke-sky-400/80 dark:stroke-sky-400/50',
    activeBg: 'bg-sky-500/15',
    activeBorder: 'border-sky-500',
    activeShadow: 'shadow-sky-500/20',
    activeText: 'text-sky-600 dark:text-sky-400',
    fabGradient: 'from-sky-600 via-sky-500 to-blue-600 hover:from-sky-500 hover:to-blue-500',
    fabShadow: 'shadow-[0_0_35px_rgba(14,165,233,0.7)]',
    fabGlowBg: 'bg-sky-600/40'
  },
  amber: {
    stroke: 'stroke-amber-400/80 dark:stroke-amber-400/50',
    activeBg: 'bg-amber-500/15',
    activeBorder: 'border-amber-500',
    activeShadow: 'shadow-amber-500/20',
    activeText: 'text-amber-600 dark:text-amber-400',
    fabGradient: 'from-amber-600 via-amber-500 to-orange-600 hover:from-amber-500 hover:to-orange-500',
    fabShadow: 'shadow-[0_0_35px_rgba(245,158,11,0.7)]',
    fabGlowBg: 'bg-amber-600/40'
  },
  emerald: {
    stroke: 'stroke-emerald-400/80 dark:stroke-emerald-400/50',
    activeBg: 'bg-emerald-500/15',
    activeBorder: 'border-emerald-500',
    activeShadow: 'shadow-emerald-500/20',
    activeText: 'text-emerald-600 dark:text-emerald-400',
    fabGradient: 'from-emerald-600 via-emerald-500 to-teal-600 hover:from-emerald-500 hover:to-teal-500',
    fabShadow: 'shadow-[0_0_35px_rgba(16,185,129,0.7)]',
    fabGlowBg: 'bg-emerald-600/40'
  }
};

export function MobileModuleBottomDock({
  items,
  activeTab,
  onSelectTab,
  colorTheme = 'blue',
  fabIcon,
  onFabClick,
  fabTitle = 'Acción Principal'
}: MobileModuleBottomDockProps) {
  const theme = themeStyles[colorTheme] || themeStyles.blue;

  return (
    <div style={{ transform: 'scale(0.92)', transformOrigin: 'bottom center' }} className="fixed bottom-1 inset-x-0 z-40 shrink-0 flex items-center justify-center px-2 sm:hidden pointer-events-none font-sans">
      <div className="w-full max-w-[420px] relative pointer-events-auto filter drop-shadow-[0_10px_25px_rgba(0,0,0,0.15)] select-none">
        {/* Background SVG Vector Shape (Clean white glass + theme border outline with concave notch for FAB) */}
        <svg 
          className="absolute inset-0 w-full h-full pointer-events-none overflow-visible" 
          viewBox="0 0 420 64" 
          preserveAspectRatio="none"
        >
          <path 
            d="M 32 0 L 325 0 C 300 0, 300 64, 325 64 L 32 64 A 32 32 0 0 1 32 0 Z" 
            className={`fill-white/95 dark:fill-slate-950/95 ${theme.stroke} backdrop-blur-2xl`}
            strokeWidth="1.5"
          />
        </svg>

        {/* Navigation Items Container */}
        <nav className="relative z-10 pl-4 pr-24 py-1.5 flex items-center justify-around w-full min-h-[64px]">
          {items.map((item) => {
            const isActive = activeTab === item.id;
            return (
              <button
                key={item.id}
                type="button"
                onClick={() => onSelectTab(item.id)}
                className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1 shrink-0"
              >
                <div className={`w-9 h-9 xs:w-10 xs:h-10 rounded-full flex items-center justify-center transition-all ${
                  isActive 
                    ? `${theme.activeBg} border-2 ${theme.activeBorder} shadow-md ${theme.activeShadow} scale-105` 
                    : 'bg-slate-100/80 dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60'
                }`}>
                  {React.isValidElement(item.icon) 
                    ? React.cloneElement(item.icon as React.ReactElement<any>, {
                        size: 19,
                        className: isActive ? `animate-pulse ${theme.activeText} font-bold` : 'text-slate-400 dark:text-slate-500'
                      })
                    : item.icon}
                </div>
                <span className={`text-[8px] xs:text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
                  isActive ? `font-black ${theme.activeText}` : 'text-slate-400 dark:text-slate-500'
                }`}>
                  {item.label} {item.badge !== undefined && `(${item.badge})`}
                </span>
              </button>
            );
          })}
        </nav>

        {/* Floating Action Button (FAB) nested right in the concave SVG notch */}
        {onFabClick && (
          <div className="absolute right-[2px] bottom-[2px] z-40">
            <button
              type="button"
              onClick={onFabClick}
              className={`w-[70px] h-[70px] bg-gradient-to-tr ${theme.fabGradient} text-white rounded-full ${theme.fabShadow} flex items-center justify-center transition-all hover:scale-105 active:scale-95 border-2 border-white/70 cursor-pointer outline-none relative shrink-0`}
              title={fabTitle}
            >
              <span className={`absolute -inset-1.5 rounded-full ${theme.fabGlowBg} blur-md animate-pulse pointer-events-none`}></span>
              {fabIcon || <Plus size={30} className="text-white relative z-10 animate-pulse" />}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
