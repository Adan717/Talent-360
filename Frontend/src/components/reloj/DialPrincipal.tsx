import React, { useState } from 'react';
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
  Armchair,
  Key,
  MapPin,
  X
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
    subtext?: string;
  };
  lateUsers: Record<number, boolean>;
  currentDay: string;
  currentSimTime: number;
  shiftConfigs: Record<number, any>;
  parseTimeToMins: (time: string) => number;
  handleAction: () => void;
  renderGPSView?: (size: number, isMobile: boolean) => React.ReactNode;
  gpsStatus?: 'seeking' | 'success' | 'error';
  onRequestGPS?: () => void;
  isGpsValidationBypassed?: boolean;
  hasMealReservation?: boolean;
  onMealSwapClick?: () => void;
  onEarlyDepartureClick?: () => void;
  onOvertimeClick?: () => void;
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
  gpsStatus,
  onRequestGPS,
  isGpsValidationBypassed = false,
  hasMealReservation = false,
  onMealSwapClick,
  onEarlyDepartureClick,
  onOvertimeClick
}: DialPrincipalProps) {
  const size = isMobile ? 76 : 88;
  const [showGpsModal, setShowGpsModal] = useState(false);

  const isGpsError = gpsStatus === 'error' && !isGpsValidationBypassed;
  const isGpsSeeking = gpsStatus === 'seeking';

  // Effectively disable the button visually and behaviorally (without block events)
  const isEffectivelyDisabled = !isGpsError && (btnProps.disabled || (clockState === 'waiting_room' && storeStatus === 'closed'));

  const getDialColorClasses = () => {
    const isRestDay = btnProps.text === 'DÍA DE DESCANSO';
    if (isRestDay) return 'bg-white border-slate-200 text-slate-400 shadow-none hover:border-slate-300';
    if (isGpsError) return 'bg-white border-rose-350 text-rose-500 shadow-none animate-pulse hover:border-rose-450';
    if (btnProps.isIncidenceReport) return 'bg-white border-amber-500 text-amber-600 shadow-amber-500/10 animate-pulse hover:border-amber-600';

    const text = btnProps.text || '';
    if (text === 'Abrir Tienda') return 'bg-white border-violet-500 text-violet-600 shadow-violet-500/10 animate-pulse hover:border-violet-600';
    if (text === 'Registrar Entrada' || text === 'Registrar Entrada Manual') return 'bg-white border-emerald-500 text-emerald-600 shadow-emerald-500/10 hover:border-emerald-600';
    if (text === 'Reserva tu Comida') return 'bg-white border-amber-500 text-amber-600 shadow-amber-500/10 hover:border-amber-600 animate-pulse';
    if (text === 'Iniciar Comida') return 'bg-white border-amber-500 text-amber-655 shadow-amber-500/10 hover:border-amber-600';
    if (text === 'Terminar Comida') return 'bg-white border-emerald-500 text-emerald-600 shadow-emerald-500/10 animate-pulse hover:border-emerald-600';
    if (text === 'Descanso') return 'bg-white border-purple-500 text-purple-600 shadow-purple-500/10 animate-pulse hover:border-purple-600';
    if (text === 'Terminar Descanso') return 'bg-white border-indigo-500 text-indigo-600 shadow-indigo-500/10 animate-pulse hover:border-indigo-600';
    if (text === 'Entrega de Turno') return 'bg-white border-cyan-500 text-cyan-600 shadow-cyan-500/10 animate-pulse hover:border-cyan-600';
    if (text === 'Registrar Salida') return 'bg-white border-rose-500 text-rose-600 shadow-rose-500/10 hover:border-rose-600';
    if (text === 'Registrar Reingreso') return 'bg-white border-teal-500 text-teal-600 shadow-teal-500/10 hover:border-teal-600';
    if (text === 'Jornada Finalizada') return 'bg-white border-slate-300 text-slate-400 shadow-none';

    return 'bg-white border-violet-400 text-violet-655 shadow-violet-500/10 hover:border-violet-500';
  };

  const getDialGlowClasses = () => {
    const isRestDay = btnProps.text === 'DÍA DE DESCANSO';
    if (isRestDay) return null;
    if (isGpsError) return 'bg-rose-500';
    if (btnProps.isIncidenceReport) return 'bg-amber-400';

    const text = btnProps.text || '';
    if (text === 'Abrir Tienda') return 'bg-violet-400';
    if (text === 'Registrar Entrada' || text === 'Registrar Entrada Manual') return 'bg-emerald-400';
    if (text === 'Reserva tu Comida') return 'bg-amber-400';
    if (text === 'Iniciar Comida') return 'bg-amber-400';
    if (text === 'Terminar Comida') return 'bg-emerald-400';
    if (text === 'Descanso') return 'bg-purple-400';
    if (text === 'Terminar Descanso') return 'bg-indigo-400';
    if (text === 'Entrega de Turno') return 'bg-cyan-400';
    if (text === 'Registrar Salida') return 'bg-rose-400';
    if (text === 'Registrar Reingreso') return 'bg-teal-400';

    return 'bg-violet-400';
  };

  const getDialIcon = (sizeValue: number) => {
    if (isGpsError) return <MapPin size={sizeValue} className="text-rose-505 shrink-0 animate-bounce" />;
    if (btnProps.isIncidenceReport) return <AlertTriangle size={sizeValue} className="text-amber-500 animate-pulse shrink-0" />;
    const isRestDay = btnProps.text === 'DÍA DE DESCANSO';
    if (isRestDay) return <Sun size={sizeValue} className="text-slate-400 shrink-0" />;

    const text = btnProps.text || '';
    if (text === 'Abrir Tienda') return <Key size={sizeValue} className="text-violet-500 animate-pulse shrink-0" />;
    if (text === 'Registrar Entrada' || text === 'Registrar Entrada Manual') return <LogIn size={sizeValue} className="text-emerald-500 shrink-0" />;
    if (text === 'Reserva tu Comida') return <Coffee size={sizeValue} className="text-amber-500 animate-pulse shrink-0" />;
    if (text === 'Iniciar Comida') return <Coffee size={sizeValue} className="text-amber-500 shrink-0" />;
    if (text === 'Terminar Comida') return <Utensils size={sizeValue} className="text-emerald-500 animate-pulse shrink-0" />;
    if (text === 'Descanso') return <Armchair size={sizeValue} className="text-purple-505 animate-pulse shrink-0" />;
    if (text === 'Terminar Descanso') return <Armchair size={sizeValue} className="text-indigo-500 animate-pulse shrink-0" />;
    if (text === 'Entrega de Turno') return <Key size={sizeValue} className="text-cyan-500 animate-pulse shrink-0" />;
    if (text === 'Registrar Salida') return <LogOut size={sizeValue} className="text-rose-505 shrink-0" />;
    if (text === 'Registrar Reingreso') return <LogIn size={sizeValue} className="text-teal-500 shrink-0" />;
    if (text === 'Jornada Finalizada') return <CheckCircle size={sizeValue} className="text-slate-400 shrink-0" />;

    return <Fingerprint size={sizeValue} className="text-slate-300 shrink-0" />;
  };

  const getDialBottomLabel = () => {
    if (isGpsError) return 'GPS Requerido';
    if (btnProps.isIncidenceReport) return 'Reportar retraso o falta';
    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay;
    if (isRestDay) return 'Día libre';

    if (btnProps.text) {
      if (btnProps.text.toLowerCase().includes('disponible a las')) {
        return 'Fuera de horario';
      }
      return btnProps.text;
    }

    return 'Registrar entrada';
  };

  const handleDialClick = () => {
    if (isGpsError) {
      setShowGpsModal(true);
    } else {
      handleAction();
    }
  };

  const handleRetryGps = async () => {
    if (onRequestGPS) {
      onRequestGPS();
      setShowGpsModal(false);
    }
  };

  if (isGpsSeeking) {
    return (
      <div className={`flex flex-col items-center justify-center py-2 mt-0 relative ${isMobile ? 'flex-shrink-0 w-full my-3' : ''}`}>
        <div className="flex flex-col items-center justify-center p-8 text-center animate-pulse min-h-[220px]">
          <div className="w-14 h-14 bg-violet-100 dark:bg-violet-955/20 rounded-full flex items-center justify-center text-violet-600 dark:text-violet-400 mb-4 shadow-sm">
            <Hourglass size={28} className="animate-spin text-violet-600" />
          </div>
          <p className="text-xs font-bold text-slate-600 dark:text-slate-400">Buscando señal GPS de alta precisión...</p>
        </div>
      </div>
    );
  }

  return (
    <div className={`flex flex-col items-center justify-center py-2 mt-0 relative ${isMobile ? 'flex-shrink-0 my-3' : ''}`}>


      <div className="relative flex-shrink-0 flex items-center justify-center">
        {/* Landing-Page-aligned Shimmer Glow Ring */}
        {getDialGlowClasses() ? (
          <div className={`absolute w-[158px] h-[158px] rounded-full blur-[10px] animate-shimmer-glow opacity-25 pointer-events-none z-0 ${getDialGlowClasses()}`}></div>
        ) : null}

        <button 
          onClick={handleDialClick} 
          disabled={!isGpsError && (btnProps.disabled || (clockState === 'waiting_room' && storeStatus === 'closed'))} 
          className={`group relative z-10 flex flex-col items-center justify-between rounded-full transition-all transform hover:scale-[1.03] active:scale-95 select-none aspect-square flex-shrink-0 border-4 border-double shadow-2xl p-3.5 ${getDialColorClasses()} ${
            isMobile ? 'w-[185px] h-[185px]' : 'w-[200px] h-[200px]'
          } ${
            isEffectivelyDisabled
              ? 'opacity-40 cursor-not-allowed shadow-none hover:scale-100' 
              : ''
          }`}
        >
          <div className="flex flex-col items-center justify-center h-full w-full py-1.5 select-none">
            {/* UPPER ZONE: Prominent Icon */}
            <div className={`flex-grow flex items-center justify-center mt-2.5 ${isGpsError ? 'text-rose-500' : 'text-slate-800'}`}>
              {getDialIcon(size)}
            </div>

            {/* CENTRAL ZONE: Digital Time */}
            <div className={`flex items-baseline font-mono font-black tracking-tight mt-0.5 mb-1.5 ${isGpsError ? 'text-rose-600' : 'text-slate-800'} ${isMobile ? 'text-[19px]' : 'text-3xl md:text-4xl leading-none'}`}>
              <span>
                {(() => {
                  const timePart = formattedTime.split(' ')[0];
                  if (timePart.includes(':')) {
                    const [h, m] = timePart.split(':');
                    return (
                      <>
                        {h}
                        <span className={`animate-[pulse_1s_infinite] select-none mx-0.5 font-bold ${isGpsError ? 'text-rose-400' : 'text-indigo-500'}`}>:</span>
                        {m}
                      </>
                    );
                  }
                  return timePart;
                })()}
              </span>
              <span className={`font-bold ${isGpsError ? 'text-rose-450' : 'text-slate-500'} ${isMobile ? 'text-[9px] ml-1' : 'text-xs md:text-sm ml-1.5'}`}>
                {formattedTime.split(' ')[1] ? formattedTime.split(' ')[1].toLowerCase() : ''}
              </span>
            </div>

            {/* LOWER ZONE: Bottom Label */}
            <div className={`px-2 text-center w-full min-h-[32px] flex flex-col items-center justify-center mb-1.5 ${isGpsError ? 'text-rose-600' : 'text-slate-700'} ${isMobile ? 'max-w-[155px]' : 'max-w-[170px]'}`}>
              <span className={`font-black uppercase tracking-wider leading-tight block ${isMobile ? 'text-[9px] md:text-[9.5px]' : 'text-[10px] md:text-[11px]'} ${isGpsError ? 'text-rose-600 font-extrabold' : ''}`}>
                {getDialBottomLabel()}
              </span>
              {btnProps.subtext && (
                <span className={`text-[9px] font-extrabold mt-0.5 leading-none block select-none uppercase truncate max-w-full ${isGpsError ? 'text-rose-500' : 'text-slate-550 dark:text-slate-400'}`}>
                  {btnProps.subtext}
                </span>
              )}
            </div>
          </div>
        </button>
      </div>

      {clockState === 'active' && hasMealReservation && onMealSwapClick && (
        <button
          type="button"
          onClick={onMealSwapClick}
          className="mt-3.5 py-1.5 px-4 bg-amber-50 dark:bg-amber-955/20 border border-amber-250 hover:border-amber-450 text-amber-700 dark:text-amber-400 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-amber-100 transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20"
        >
          <Coffee size={12} className="text-amber-550" />
          Intercambiar Comida
        </button>
      )}

      {clockState === 'active' && onEarlyDepartureClick && (
        <button
          type="button"
          onClick={onEarlyDepartureClick}
          className="mt-2.5 py-1.5 px-4 bg-rose-50 dark:bg-rose-955/20 border border-rose-250 hover:border-rose-450 text-rose-700 dark:text-rose-400 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-rose-105 transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20 border-solid"
        >
          <LogOut size={12} className="text-rose-500" />
          Salida Anticipada
        </button>
      )}

      {btnProps.text === 'DÍA DE DESCANSO' && onOvertimeClick && (
        <button
          type="button"
          onClick={onOvertimeClick}
          className="mt-3.5 py-1.5 px-4 bg-amber-50 dark:bg-amber-955/20 border border-amber-250 hover:border-amber-450 text-amber-700 dark:text-amber-400 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-amber-100 transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20 border-solid"
        >
          <Fingerprint size={12} className="text-amber-550 animate-pulse" />
          Laborar Horas Extras
        </button>
      )}

      {/* Premium Centered GPS Instruction Modal */}
      {showGpsModal && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[100] flex items-center justify-center p-4 animate-in fade-in duration-200">
          <div className="bg-white border border-slate-200 w-full max-w-sm rounded-[2rem] p-6 shadow-2xl relative animate-in zoom-in-95 duration-200 text-left">
            
            {/* Close Button */}
            <button 
              onClick={() => setShowGpsModal(false)}
              className="absolute top-4 right-4 text-slate-400 hover:text-slate-655 p-1.5 rounded-full hover:bg-slate-100 transition-all border-none cursor-pointer"
            >
              <X size={18} />
            </button>

            {/* Modal Icon Header */}
            <div className="w-12 h-12 bg-rose-50 border border-rose-100 rounded-2xl flex items-center justify-center text-rose-500 mx-auto mb-4 shadow-sm">
              <MapPin size={22} className="animate-bounce" />
            </div>

            {/* Modal Title */}
            <h3 className="text-sm font-black text-slate-900 text-center tracking-tight mb-2">
              Ubicación Requerida
            </h3>
            
            {/* Message */}
            <p className="text-[10.5px] text-slate-500 text-center leading-relaxed mb-5 px-2 font-bold">
              Para realizar tu registro, por favor activa los datos y la ubicación en la barra de ajustes de tu celular, luego presiona el botón de abajo.
            </p>
            
            {/* Modal Actions */}
            <div className="flex flex-col gap-2">
              <button 
                type="button"
                onClick={handleRetryGps}
                className="w-full py-2.5 bg-gradient-to-r from-rose-500 to-rose-600 hover:from-rose-600 hover:to-rose-700 text-white font-black text-[10px] uppercase tracking-wider rounded-xl shadow-md transition-all duration-300 transform active:scale-95 border-none cursor-pointer flex items-center justify-center gap-1.5"
              >
                <MapPin size={12} />
                Reintentar Ubicación
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
