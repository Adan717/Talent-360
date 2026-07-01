import React from 'react';
import { 
  LogIn, 
  LogOut, 
  Coffee, 
  Utensils, 
  CheckCircle, 
  AlertCircle, 
  AlertTriangle,
  Sun,
  Store,
  Hourglass,
  Fingerprint,
  Armchair
} from 'lucide-react';

interface DialPrincipalProps {
  isMobile?: boolean;
  isOpeningPremium: boolean;
  storeStatus: string;
  openingStatus: any;
  currentUser: any;
  isWithinPerimeter: boolean;
  globalUsers: any[];
  clockState: string;
  formattedTime: string;
  btnProps: {
    disabled: boolean;
    isIncidenceReport?: boolean;
    text?: string;
  };
  lateUsers: Record<number, boolean>;
  currentDay: string;
  currentSimTime: number;
  shiftConfigs: Record<number, any>;
  parseTimeToMins: (time: string) => number;
  handleAction: () => void;
  renderGPSView?: (size: number, isMobile: boolean) => React.ReactNode;
}

export default function DialPrincipal({
  isMobile = false,
  isOpeningPremium,
  storeStatus,
  openingStatus,
  currentUser,
  isWithinPerimeter,
  globalUsers,
  clockState,
  formattedTime,
  btnProps,
  lateUsers,
  currentDay,
  currentSimTime,
  shiftConfigs,
  parseTimeToMins,
  handleAction,
  renderGPSView
}: DialPrincipalProps) {
  const size = isMobile ? 76 : 88;

  const getDialColorClasses = () => {
    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay;
    if (isRestDay) {
      return 'bg-white border-slate-200 text-slate-400 shadow-none hover:border-slate-300';
    }

    if (btnProps.isIncidenceReport) {
      return 'bg-white border-amber-500 text-amber-600 shadow-amber-500/10 animate-pulse hover:border-amber-600';
    }

    const shiftStartMins = parseTimeToMins(shiftConfigs[currentUser?.id]?.start || '09:00');
    const isLate = lateUsers[currentUser?.id] || (clockState === 'inactive' && currentSimTime > shiftStartMins + 10);

    if (isLate) {
      return 'bg-white border-rose-500 text-rose-600 shadow-rose-500/10 animate-pulse hover:border-rose-600';
    }

    if (clockState === 'active') {
      return 'bg-white border-emerald-500 text-emerald-600 shadow-emerald-500/10 hover:border-emerald-600';
    }

    if (clockState === 'short_break') {
      return 'bg-white border-purple-500 text-purple-600 shadow-purple-500/10 animate-pulse hover:border-purple-600';
    }

    if (clockState === 'meal') {
      return 'bg-white border-amber-500 text-amber-600 shadow-amber-500/10 animate-pulse hover:border-amber-600';
    }

    if (clockState === 'finished') {
      return 'bg-white border-teal-500 text-teal-600 shadow-teal-500/10 hover:border-teal-600';
    }

    if (clockState === 'inactive') {
      return 'bg-white border-blue-500 text-blue-600 shadow-blue-500/10 hover:border-blue-600';
    }

    return 'bg-white border-violet-400 text-violet-655 shadow-violet-500/10 hover:border-violet-500';
  };

  const getDialGlowClasses = () => {
    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay;
    if (isRestDay) return null;

    if (btnProps.isIncidenceReport) {
      return 'bg-amber-400';
    }

    if (clockState === 'active') {
      return 'bg-emerald-400';
    }
    if (clockState === 'short_break') {
      return 'bg-purple-400';
    }
    if (clockState === 'meal') {
      return 'bg-amber-400';
    }
    if (clockState === 'finished') {
      return 'bg-teal-400';
    }

    const shiftStartMins = parseTimeToMins(shiftConfigs[currentUser?.id]?.start || '09:00');
    const isLate = lateUsers[currentUser?.id] || (clockState === 'inactive' && currentSimTime > shiftStartMins + 10);

    if (isLate) {
      return 'bg-rose-400';
    }

    if (clockState === 'inactive') {
      return 'bg-blue-400';
    }

    return 'bg-violet-400';
  };

  const getDialIcon = (sizeValue: number) => {
    if (btnProps.isIncidenceReport) {
      return <AlertTriangle size={sizeValue} className="text-amber-500 animate-pulse shrink-0" />;
    }
    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay;
    if (isRestDay) {
      return <Sun size={sizeValue} className="text-slate-400 shrink-0" />;
    }
    if (clockState === 'active') {
      return <Fingerprint size={sizeValue} className="text-emerald-500 shrink-0" />;
    }
    if (clockState === 'short_break') {
      return <Armchair size={sizeValue} className="text-purple-500 animate-pulse shrink-0" />;
    }
    if (clockState === 'meal') {
      return <Utensils size={sizeValue} className="text-amber-500 animate-pulse shrink-0" />;
    }
    if (clockState === 'finished') {
      return <CheckCircle size={sizeValue} className="text-teal-500 shrink-0" />;
    }
    if (clockState === 'absent') {
      return <AlertCircle size={sizeValue} className="text-rose-500 shrink-0" />;
    }
    
    if (isOpeningPremium && storeStatus === 'closed') {
      const responsibleId = openingStatus ? openingStatus.current_responsible_employee_id : 1;
      if (Number(currentUser?.id) === Number(responsibleId)) {
        return <Store size={sizeValue} className="text-violet-505 animate-pulse shrink-0" />;
      }
      return <Hourglass size={sizeValue} className="text-slate-400 animate-pulse shrink-0" />;
    }

    if (clockState === 'inactive' && storeStatus === 'open') {
      return <Fingerprint size={sizeValue} className="text-blue-500 animate-pulse shrink-0" />;
    }

    return <Fingerprint size={sizeValue} className="text-slate-300 shrink-0" />;
  };

  const getDialBottomLabel = () => {
    if (btnProps.isIncidenceReport) return 'Reportar retraso o falta';
    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay;
    if (isRestDay) return 'Día libre';
    if (clockState === 'active') return 'Iniciar comida';
    if (clockState === 'meal') return 'Regresar de comer';
    if (clockState === 'short_break') return 'Regresar de comer';
    if (clockState === 'finished') return 'Turno terminado';
    if (clockState === 'absent') return 'Ausente';

    if (isOpeningPremium && storeStatus === 'closed') {
      const responsibleId = openingStatus ? openingStatus.current_responsible_employee_id : 1;
      if (Number(currentUser?.id) === Number(responsibleId)) {
        return 'Abrir tienda';
      }
      const savedAss = localStorage.getItem('store_opening_assignments');
      const ass = savedAss ? JSON.parse(savedAss) : [];
      const respAss = ass.find((a: any) => a.employee_id === Number(responsibleId));
      const respName = respAss ? respAss.name || 'Encargado' : 'Encargado';
      const firstName = respName.split(' ')[0];
      return `Apertura por: ${firstName}`;
    }

    if (btnProps.text && btnProps.text.toLowerCase().includes('disponible a las')) {
      return 'Fuera de horario';
    }

    if (btnProps.text === '⚠️ Reportar Tienda Cerrada') return 'Reportar Cierre';

    return 'Registrar entrada';
  };

  return (
    <div className={`flex flex-col items-center justify-center py-2 mt-0 relative ${isMobile ? 'flex-1 min-h-[200px] mt-[-5px] mb-3' : ''}`}>
      {isOpeningPremium && storeStatus === 'closed' && (
        <div className={`px-5 py-2.5 rounded-full flex items-center justify-center gap-1.5 shadow-inner border mb-5 select-none shrink-0 text-center animate-fade-in ${
          Number(currentUser?.id) === Number(openingStatus ? openingStatus.current_responsible_employee_id : 1) && !isWithinPerimeter
            ? 'bg-violet-50 dark:bg-violet-955/20 border-violet-300 dark:border-violet-800/50 text-violet-750 dark:text-violet-300 font-black animate-pulse'
            : 'bg-slate-100 dark:bg-slate-800/40 text-slate-600 dark:text-slate-400 border-slate-200/50'
        }`}>
          <span className="animate-pulse text-sm">⏳</span>
          <span className={isMobile ? "text-[10px] font-extrabold uppercase tracking-wide" : "text-[11px] font-extrabold uppercase tracking-wide"}>
            {(() => {
              const responsibleId = openingStatus ? openingStatus.current_responsible_employee_id : 1;
              if (Number(currentUser?.id) === Number(responsibleId)) {
                return isWithinPerimeter 
                  ? 'Tienes el control de la apertura de hoy'
                  : '🗝️ Responsable de apertura. Dirígete a la sucursal para abrir.';
              } else {
                const responsibleUser = globalUsers.find((u: any) => u.id === responsibleId) || { name: 'Encargado' };
                return `Esperando apertura por: ${responsibleUser.name}`;
              }
            })()}
          </span>
        </div>
      )}

      {(renderGPSView && renderGPSView(size, isMobile)) || (
        <div className="relative flex-shrink-0 flex items-center justify-center">
          {/* Landing-Page-aligned Shimmer Glow Ring */}
          {getDialGlowClasses() ? (
            <div className={`absolute w-44 h-44 rounded-full blur-[10px] animate-shimmer-glow opacity-25 pointer-events-none z-0 ${getDialGlowClasses()}`}></div>
          ) : null}

          <button 
            onClick={handleAction} 
            disabled={btnProps.disabled || (clockState === 'waiting_room' && storeStatus === 'closed')} 
            className={`group relative z-10 flex flex-col items-center justify-between rounded-full transition-all transform hover:scale-[1.03] active:scale-95 select-none aspect-square flex-shrink-0 border-4 border-double shadow-2xl p-4 bg-white ${getDialColorClasses()} ${
              isMobile ? 'w-52 h-52' : 'w-56 h-56'
            } ${
              btnProps.disabled || (clockState === 'waiting_room' && storeStatus === 'closed') 
                ? 'opacity-40 cursor-not-allowed shadow-none hover:scale-100' 
                : ''
            }`}
          >
            <div className="flex flex-col items-center justify-center h-full w-full py-2 select-none">
              {/* ZONA SUPERIOR/CENTRO: Icono Prominente y Grande */}
              <div className="flex-grow flex items-center justify-center mt-3 text-slate-800">
                {getDialIcon(size)}
              </div>

              {/* ZONA CENTRAL: Hora digital */}
              <div className={`flex items-baseline font-mono font-black text-slate-800 tracking-tight mt-1 mb-2 ${isMobile ? 'text-3xl' : 'text-4xl md:text-5xl leading-none'}`}>
                <span>{formattedTime.split(' ')[0]}</span>
                <span className={`uppercase font-bold text-slate-500 ${isMobile ? 'text-xs ml-0.5' : 'text-xs md:text-sm ml-1.5'}`}>{formattedTime.split(' ')[1].toLowerCase()}</span>
              </div>

              {/* ZONA INFERIOR: Texto Instructivo Directo */}
              <div className={`px-2 text-center w-full min-h-[36px] flex items-center justify-center mb-2 text-slate-700 ${isMobile ? 'max-w-[170px]' : 'max-w-[190px]'}`}>
                <span className={`font-black uppercase tracking-wider leading-tight block ${isMobile ? 'text-[10px] md:text-[10.5px]' : 'text-[11px] md:text-[12px]'}`}>
                  {getDialBottomLabel()}
                </span>
              </div>
            </div>
          </button>
        </div>
      )}
    </div>
  );
}
