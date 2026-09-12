import React, { useState } from 'react';
import { createPortal } from 'react-dom';
import { Plus, X } from 'lucide-react';

export interface MobileDockItem {
  id: string;
  label: string;
  icon: React.ReactNode;
  badge?: string | number;
}

export interface FabSubAction {
  id: string;
  label: string;
  icon: React.ReactNode;
  onClick: () => void;
  colorClass?: string;
}

export type ColorTheme = 'blue' | 'indigo' | 'purple' | 'sky' | 'amber' | 'emerald';

export interface MobileModuleBottomDockProps {
  items: MobileDockItem[];
  activeTab: string;
  onSelectTab: (id: string) => void;
  colorTheme?: ColorTheme;
  fabIcon?: React.ReactNode;
  onFabClick?: () => void;
  subActions?: FabSubAction[];
  fabTitle?: string;
}

// Navigation and primary actions share one functional palette, including legacy theme props.
const navyTheme = {
  stroke: 'stroke-accent dark:stroke-navy-200',
  activeBg: 'bg-accent-soft',
  activeBorder: 'border-navy-300',
  activeShadow: 'shadow-accent/10',
  activeText: 'text-navy-800',
  fabGradient: 'from-accent via-accent to-accent hover:from-accent-hover hover:to-accent-hover',
  fabShadow: 'shadow-lg shadow-accent/20',
  fabGlowBg: 'bg-transparent',
};

export function MobileModuleBottomDock({
  items,
  activeTab,
  onSelectTab,
  colorTheme = 'indigo',
  fabIcon,
  onFabClick,
  subActions,
  fabTitle = 'Acciones rápidas'
}: MobileModuleBottomDockProps) {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const theme = navyTheme;
  const hasSubActions = subActions && subActions.length > 0;

  const handleFabPress = () => {
    if (hasSubActions) {
      setIsMenuOpen(!isMenuOpen);
    } else if (onFabClick) {
      onFabClick();
    }
  };

  return (
    <>
      {/* Full-screen Glassmorphism Backdrop when subActions menu is open */}
      {isMenuOpen && createPortal(
        <div
          className="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-md transition-all duration-300 animate-in fade-in cursor-pointer pointer-events-auto"
          onClick={() => setIsMenuOpen(false)}
        />,
        document.body
      )}

      <div
        className="fixed bottom-1 inset-x-0 z-40 shrink-0 flex items-center justify-center px-2 sm:hidden pointer-events-none"
        style={{ transform: 'scale(0.92)', transformOrigin: 'bottom center' }}
      >
        <div className="relative w-full max-w-sm flex items-center justify-between pointer-events-auto filter drop-shadow-[0_10px_25px_rgba(0,0,0,0.15)]">

        {/* SVG Container with Concave Right Notch for Floating Action Button */}
        <svg
          viewBox="0 0 420 64"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          className="absolute inset-0 w-full h-full preserve-3d"
          preserveAspectRatio="none"
        >
          <path
            d="M 32 0 L 325 0 C 300 0, 300 64, 325 64 L 32 64 A 32 32 0 0 1 32 0 Z"
            className={`fill-white/95 dark:fill-slate-900/95 backdrop-blur-2xl stroke-[2.5] ${theme.stroke}`}
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
                onClick={() => {
                  setIsMenuOpen(false);
                  onSelectTab(item.id);
                }}
                className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1 shrink-0"
              >
                <div className={`w-9 h-9 xs:w-10 xs:h-10 rounded-full flex items-center justify-center transition-all ${
                  isActive
                    ? `${theme.activeBg} border-2 ${theme.activeBorder} shadow-md ${theme.activeShadow} scale-105`
                    : 'bg-page/80 dark:bg-slate-900 border border-border/50 dark:border-slate-800/50 hover:bg-slate-200/60 dark:hover:bg-slate-800/60'
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
        {(onFabClick || hasSubActions) && (
          <div className="absolute right-[2px] bottom-[2px] z-40">

            {/* Popover Menu with Sub-Actions */}
            {isMenuOpen && subActions && (
              <div className="absolute bottom-[80px] right-1 flex flex-col items-end gap-2.5 z-50 animate-in fade-in slide-in-from-bottom-3 duration-200 pointer-events-auto">
                {subActions.map((action) => (
                  <button
                    key={action.id}
                    type="button"
                    onClick={() => {
                      setIsMenuOpen(false);
                      action.onClick();
                    }}
                    className="flex items-center gap-2.5 px-4 py-2.5 rounded-full bg-white dark:bg-slate-800 text-text-1 dark:text-slate-100 shadow-xl border border-border/80 dark:border-slate-700 active:scale-95 transition-all text-xs font-bold whitespace-nowrap cursor-pointer"
                  >
                    <span>{action.label}</span>
                    <div className={`w-8 h-8 rounded-full flex items-center justify-center ${action.colorClass || 'bg-accent-soft text-accent'}`}>
                      {action.icon}
                    </div>
                  </button>
                ))}
              </div>
            )}

            <button
              type="button"
              onClick={handleFabPress}
              className={`w-[70px] h-[70px] bg-gradient-to-tr ${theme.fabGradient} text-white rounded-full ${theme.fabShadow} flex items-center justify-center transition-all hover:scale-105 active:scale-95 border-2 border-white/70 cursor-pointer outline-none relative shrink-0 z-40`}
              title={fabTitle}
            >
              <span className={`absolute -inset-1.5 rounded-full ${theme.fabGlowBg} blur-md animate-pulse pointer-events-none`}></span>
              {isMenuOpen ? (
                <X size={30} className="text-white relative z-10 animate-in spin-in-90 duration-200" />
              ) : (
                fabIcon || <Plus size={30} className="text-white relative z-10 animate-pulse" />
              )}
            </button>
          </div>
        )}
      </div>
    </div>
    </>
  );
}
