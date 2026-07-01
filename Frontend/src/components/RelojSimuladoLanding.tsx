import React, { useState, useEffect } from 'react';
import { 
  LogIn, LogOut, Armchair, Utensils, Clock, Briefcase, 
  GraduationCap, Settings, MapPin, Camera, Lock, Check,
  AlertTriangle, Star, ShieldCheck, HeartHandshake, Award, 
  Send, Sparkles, CheckSquare, ClipboardList, Network, Bot, 
  Play, MessageSquare, AlertOctagon, HelpCircle, X, ChevronRight, User
} from 'lucide-react';
import DialPrincipal from './reloj2/DialPrincipal';
import { MobileBottomNav } from './reloj2/MobileBottomNav';

interface RelojSimuladoLandingProps {
  tier: 'free' | 'pro';
  setTier?: (tier: 'free' | 'pro') => void;
}

export const RelojSimuladoLanding: React.FC<RelojSimuladoLandingProps> = ({
  tier,
  setTier
}) => {
  const [clockState, setClockState] = useState<'inactive' | 'active' | 'short_break' | 'meal' | 'finished'>('inactive');
  const [phoneTab, setPhoneTab] = useState<string>('checador');
  const [innerTool, setInnerTool] = useState<string | null>(null);
  const [isDark, setIsDark] = useState(false);

  // Estados de validación PRO temporales
  const [isVerifying, setIsVerifying] = useState(false);
  const [verifyingStep, setVerifyingStep] = useState<'gps' | 'selfie'>('gps');

  // Control de Hojas inferiores y Modales
  const [isFabSheetOpen, setIsFabSheetOpen] = useState(false);
  const [isCopilotOpen, setIsCopilotOpen] = useState(false);
  const [activeModal, setActiveModal] = useState<'entry' | 'break' | 'meal' | 'exit' | null>(null);

  // Datos simulados de tiempo
  const [currentSimTime, setCurrentSimTime] = useState(540); // 09:00 AM
  const [formattedTime, setFormattedTime] = useState('09:00:00 AM');

  // Copiloto AI chat simulado
  const [chatMessages, setChatMessages] = useState<{sender: 'user' | 'bot', text: string}[]>([
    { sender: 'bot', text: '¡Hola! Soy tu Copiloto AI Talent 360. ¿En qué puedo ayudarte hoy en tu turno?' }
  ]);
  const [newMsg, setNewMsg] = useState('');

  // Estados de fichajes simulados para Francisco Vega (ID: 99)
  const [checkInTimes, setCheckInTimes] = useState<Record<number, number>>({});
  const [checkOutTimes, setCheckOutTimes] = useState<Record<number, number>>({});
  const [breakStartTimes, setBreakStartTimes] = useState<Record<number, number>>({});
  const [breakEndTimes, setBreakEndTimes] = useState<Record<number, number>>({});
  const [mealStartTimes, setMealStartTimes] = useState<Record<number, number>>({});
  const [mealEndTimes, setMealEndTimes] = useState<Record<number, number>>({});
  const [breaksTaken, setBreaksTaken] = useState<Record<number, number>>({});
  const [hasReservedMeal, setHasReservedMeal] = useState<Record<number, boolean>>({});

  const currentUser = {
    id: 99,
    name: 'Francisco Vega',
    avatar: 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=80&h=80&fit=crop&crop=faces',
    role: 'empleado',
    job_role_id: 1,
    tenant: { name: 'Decorarte 365' }
  };

  const shiftConfigs = {
    99: { restDay: 'Domingo', start: '09:00', end: '18:00', mealMinutes: 45 }
  };

  const currentDay = 'Lunes';

  // Simular avance del tiempo
  useEffect(() => {
    let timer: any;
    if (clockState === 'active') {
      timer = setInterval(() => {
        setCurrentSimTime(prev => {
          const next = prev + 1;
          const hrs = Math.floor(next / 60);
          const mins = next % 60;
          const displayHrs = hrs > 12 ? hrs - 12 : hrs === 0 ? 12 : hrs;
          const ampm = hrs >= 12 ? 'PM' : 'AM';
          setFormattedTime(`${displayHrs.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:00 ${ampm}`);
          return next;
        });
      }, 4000);
    }
    return () => clearInterval(timer);
  }, [clockState]);

  // Ejecuta la validación de fichaje (GPS + Selfie en Plan PRO)
  const runProVerification = (onComplete: () => void) => {
    if (tier === 'pro') {
      setIsVerifying(true);
      setVerifyingStep('gps');
      
      // Pasar a Selfie tras 1.2 segundos
      setTimeout(() => {
        setVerifyingStep('selfie');
        
        // Terminar validación tras otro 1.2 segundos
        setTimeout(() => {
          setIsVerifying(false);
          onComplete();
        }, 1200);
      }, 1200);
    } else {
      onComplete();
    }
  };

  // Transiciones de estado del dial
  const handleAction = () => {
    if (clockState === 'inactive') {
      runProVerification(() => {
        setClockState('active');
        setCheckInTimes({ 99: 545 }); // Entrada 09:05 AM
        setCurrentSimTime(545);
        setFormattedTime('09:05:00 AM');
      });
    } else if (clockState === 'active') {
      // Simular descanso
      setClockState('short_break');
      setBreakStartTimes({ 99: 720 }); // Descanso 12:00 PM
      setCurrentSimTime(720);
      setFormattedTime('12:00:00 PM');
    } else if (clockState === 'short_break') {
      // Regresar descanso
      setClockState('active');
      setBreakEndTimes({ 99: 735 }); // Regreso 12:15 PM
      setBreaksTaken({ 99: 1 });
      setCurrentSimTime(735);
      setFormattedTime('12:15:00 PM');
    } else if (clockState === 'meal') {
      // Regresar comida
      setClockState('active');
      setMealEndTimes({ 99: 885 }); // Regreso 02:45 PM
      setHasReservedMeal({ 99: true });
      setCurrentSimTime(885);
      setFormattedTime('02:45:00 PM');
    }
  };

  const handleStartMealSim = () => {
    setClockState('meal');
    setMealStartTimes({ 99: 840 }); // Comida 02:00 PM
    setCurrentSimTime(840);
    setFormattedTime('02:00:00 PM');
  };

  const handleClockOutSim = () => {
    runProVerification(() => {
      setClockState('finished');
      setCheckOutTimes({ 99: 1080 }); // Salida 06:00 PM
      setCurrentSimTime(1080);
      setFormattedTime('06:00:00 PM');
    });
  };

  const handleResetSim = () => {
    setClockState('inactive');
    setCheckInTimes({});
    setCheckOutTimes({});
    setBreakStartTimes({});
    setBreakEndTimes({});
    setMealStartTimes({});
    setMealEndTimes({});
    setBreaksTaken({});
    setHasReservedMeal({});
    setCurrentSimTime(540);
    setFormattedTime('09:00:00 AM');
  };

  // Ayudantes de conversión y formateo
  const parseTimeToMins = (timeStr: string) => {
    const [h, m] = timeStr.split(':').map(Number);
    return h * 60 + m;
  };

  const formatStringToTimeClean = (timeStr: string) => {
    const [h, m] = timeStr.split(':');
    const hrs = Number(h);
    const ampm = hrs >= 12 ? 'pm' : 'am';
    const displayHrs = hrs > 12 ? hrs - 12 : hrs === 0 ? 12 : hrs;
    return `${displayHrs}:${m} ${ampm}`;
  };

  const formatMinsToTimeClean = (mins: number) => {
    const hrs = Math.floor(mins / 60);
    const m = mins % 60;
    const displayHrs = hrs > 12 ? hrs - 12 : hrs === 0 ? 12 : hrs;
    const ampm = hrs >= 12 ? 'pm' : 'am';
    return `${displayHrs}:${m.toString().padStart(2, '0')} ${ampm}`;
  };

  // Propiedades dinámicas del dial
  const getDialProps = () => {
    if (clockState === 'inactive') {
      return { disabled: false, text: 'Registrar Entrada', subtext: 'Francisco Vega' };
    }
    if (clockState === 'active') {
      const hasBreak = breaksTaken[99] !== undefined;
      const hasMeal = mealEndTimes[99] !== undefined;
      if (!hasBreak) {
        return { disabled: false, text: 'Descanso Ley Silla', subtext: 'Tomar 15 Minutos' };
      }
      if (!hasMeal) {
        return { disabled: false, text: 'Iniciar Horario de Comida', subtext: 'Tomar 45 Minutos' };
      }
      return { disabled: false, text: 'Registrar Salida', subtext: 'Fichaje de Salida' };
    }
    if (clockState === 'short_break') {
      return { disabled: false, text: 'Regresar de Descanso', subtext: 'Retomar Turno' };
    }
    if (clockState === 'meal') {
      return { disabled: false, text: 'Regresar de Comida', subtext: 'Retomar Turno' };
    }
    return { disabled: true, text: 'Jornada Finalizada', subtext: 'Turno Completado' };
  };

  const btnProps = getDialProps();

  // Segmentos de la barra cronológica proporcional
  const hasCheckedIn = checkInTimes[99] !== undefined;
  const hasCheckedOut = checkOutTimes[99] !== undefined;

  const renderUnifiedMobileHeader = () => {
    let title = '';
    let desc = '';
    let icon = null;
    let badgeText = '';
    let badgeColorClass = 'bg-[#e6f4ea] text-[#137333] border border-[#ceead6]/20';

    switch (phoneTab) {
      case 'checador':
        title = 'Reloj Checador';
        desc = 'Control de Asistencia';
        icon = <Clock className="text-[#2dce89]" />;
        badgeText = tier === 'pro' ? 'v4.2-pro' : 'Gratuito';
        badgeColorClass = tier === 'pro' 
          ? 'bg-[#e6f4ea] text-[#137333] border border-[#ceead6]/20' 
          : 'bg-slate-100 text-slate-600 border border-slate-200';
        break;
      case 'tareas':
        title = 'Tareas y Rutinas';
        desc = 'Gestión operativa';
        icon = <CheckSquare className="text-indigo-500" />;
        badgeText = 'Tareas';
        badgeColorClass = 'bg-indigo-50 dark:bg-indigo-955/40 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-900/30';
        break;
      case 'academia':
        title = 'Academia';
        desc = 'Desarrollo de personal';
        icon = <GraduationCap className="text-violet-500 animate-bounce" />;
        badgeText = 'Cursos';
        badgeColorClass = 'bg-violet-50 dark:bg-violet-955/40 text-violet-600 dark:text-violet-400 border border-violet-100 dark:border-violet-900/30';
        break;
      case 'herramientas':
        title = 'Herramientas';
        desc = 'Bitácoras rápidas';
        icon = <Settings className="text-slate-500 animate-spin" style={{ animationDuration: '6s' }} />;
        badgeText = 'Menú';
        badgeColorClass = 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700';
        break;
      default:
        title = 'Reloj Checador';
        desc = 'Control de Asistencia';
        icon = <Clock className="text-[#2dce89]" />;
        badgeText = 'Talent 360';
        badgeColorClass = 'bg-[#e6f4ea] text-[#137333] border';
    }

    return (
      <div className={`absolute top-3 left-3 right-3 z-[75] flex items-center justify-between px-3.5 py-2.5 text-left rounded-2xl border transition-all duration-200 select-none ${
        isDark 
          ? 'bg-slate-900/90 backdrop-blur-md border-slate-800 shadow-[0_8px_32px_rgba(0,0,0,0.3)] text-slate-100' 
          : 'bg-white/95 backdrop-blur-md border-slate-100 shadow-[0_8px_32px_rgba(0,0,0,0.05)] text-slate-900'
      }`}>
        <div className="flex items-center gap-2.5 min-w-0">
          <div className="shrink-0 flex items-center justify-center">
            {icon && React.cloneElement(icon, { className: 'w-8 h-8' })}
          </div>
          <div className="flex flex-col min-w-0 justify-center text-left">
            <div className="flex items-center gap-1.5 flex-wrap">
              <h3 className="text-xs font-black tracking-tight leading-none truncate max-w-[120px]">
                {title}
              </h3>
              {badgeText && (
                <span className={`inline-flex items-center px-1.5 py-0.5 rounded-full text-[7px] font-black tracking-wider uppercase ${badgeColorClass}`}>
                  {badgeText}
                </span>
              )}
            </div>
            <p className={`text-[8.5px] font-bold mt-0.5 leading-none truncate ${
              isDark ? 'text-slate-400' : 'text-[#525f7f]'
            }`}>
              {desc}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 shrink-0 min-w-0">
          <div className="flex flex-col min-w-0 text-right justify-center leading-tight">
            <span className={`text-[9.5px] font-black uppercase tracking-wider ${
              isDark ? 'text-indigo-400' : 'text-[#8a2be2]'
            }`}>
              Decorarte 365
            </span>
            <span className="text-[9px] font-bold truncate max-w-[90px]">
              {currentUser.name}
            </span>
          </div>
          <img 
            src={currentUser.avatar} 
            alt="Avatar" 
            className={`w-9 h-9 rounded-full object-cover border-2 shadow-sm ${
              isDark ? 'border-slate-700' : 'border-slate-200'
            }`} 
          />
        </div>
      </div>
    );
  };

  const renderBarraCronologica = () => {
    const tStart = 540; // 09:00 AM
    const tEnd = 1080;  // 06:00 PM
    const tDuration = tEnd - tStart;
    const limitPos = hasCheckedOut ? 1080 : currentSimTime;
    const elapsedTotal = limitPos - tStart;

    const eventsList: { start: number; end: number; type: 'break' | 'meal' }[] = [];
    const bStart = breakStartTimes[99];
    if (bStart !== undefined) {
      const bEnd = breakEndTimes[99] !== undefined ? breakEndTimes[99] : (clockState === 'short_break' ? currentSimTime : bStart + 15);
      eventsList.push({ start: bStart, end: bEnd, type: 'break' });
    }
    const mStart = mealStartTimes[99];
    if (mStart !== undefined) {
      const mEnd = mealEndTimes[99] !== undefined ? mealEndTimes[99] : (clockState === 'meal' ? currentSimTime : mStart + 45);
      eventsList.push({ start: mStart, end: mEnd, type: 'meal' });
    }

    eventsList.sort((a, b) => a.start - b.start);
    const segmentsList: { mins: number; type: 'work' | 'break' | 'meal' }[] = [];
    let currentPos = tStart;

    eventsList.forEach(ev => {
      const evStart = Math.max(currentPos, Math.min(ev.start, limitPos));
      const evEnd = Math.max(evStart, Math.min(ev.end, limitPos));
      if (evStart > currentPos) {
        segmentsList.push({ mins: evStart - currentPos, type: 'work' });
      }
      if (evEnd > evStart) {
        segmentsList.push({ mins: evEnd - evStart, type: ev.type });
      }
      currentPos = evEnd;
    });

    if (limitPos > currentPos) {
      segmentsList.push({ mins: limitPos - currentPos, type: 'work' });
    }

    const progressPercent = tDuration > 0 ? Math.min(100, Math.max(0, (elapsedTotal / tDuration) * 100)) : 0;

    return (
      <div className="py-2 px-1 text-left w-full select-none shrink-0">
        <div className="flex justify-between items-center w-full font-bold uppercase tracking-wider text-[9px] mb-3 px-1">
          <div className="flex items-center select-none">
            <span className="text-emerald-600 dark:text-emerald-450 font-black flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
              <span>🏪 Sucursal Abierta</span>
            </span>
          </div>

          <div className="flex items-center select-none">
            {hasCheckedOut ? (
              <span className="text-emerald-600 dark:text-emerald-450 font-black flex items-center gap-1.5">
                <span>Turno Finalizado ✓</span>
              </span>
            ) : hasCheckedIn ? (
              <span className="text-emerald-600 dark:text-emerald-455 font-black flex items-center gap-1.5 animate-pulse">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span>Turno Activo ✓</span>
              </span>
            ) : (
              <span className="text-slate-400 dark:text-slate-550 font-black flex items-center gap-1.5">
                <span className="w-1.5 h-1.5 rounded-full bg-slate-350 dark:bg-slate-600"></span>
                <span>Turno Inactivo</span>
              </span>
            )}
          </div>
        </div>

        {/* Nodos de Fichaje Interactivos */}
        <div className="flex w-full z-10 relative px-0 mb-0 mt-1">
          {/* Entrada Node */}
          <div 
            onClick={() => hasCheckedIn && setActiveModal('entry')}
            className={`w-1/4 flex flex-col items-center relative transition-all duration-300 transform ${
              hasCheckedIn ? 'cursor-pointer hover:scale-105 active:scale-95' : 'cursor-default'
            }`}
          >
            <span className="text-[8.5px] font-black uppercase tracking-wider mb-0.5 text-indigo-650 dark:text-indigo-400">Entrada</span>
            <div className={`rounded-full flex items-center justify-center border-2 relative shadow-md w-11 h-11 transition-all ${
              hasCheckedIn 
                ? 'border-indigo-500 bg-indigo-500 text-white font-extrabold shadow-indigo-500/20' 
                : 'border-slate-200 bg-white text-slate-400'
            }`}>
              <LogIn size={18} className={!hasCheckedIn ? "animate-pulse" : ""} />
              {hasCheckedIn && <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm">✓</div>}
            </div>
          </div>

          {/* Descanso Node */}
          {(() => {
            const isDone = breaksTaken[99] !== undefined;
            const isActive = clockState === 'short_break';
            return (
              <div 
                onClick={() => (isDone || isActive) && setActiveModal('break')}
                className={`w-1/4 flex flex-col items-center relative transition-all duration-300 transform ${
                  isDone || isActive ? 'cursor-pointer hover:scale-105 active:scale-95' : 'cursor-default'
                }`}
              >
                <span className="text-[8.5px] font-black uppercase tracking-wider mb-0.5 text-purple-650 dark:text-purple-400">Descanso</span>
                <div className={`rounded-full flex items-center justify-center border-2 relative shadow-md w-11 h-11 transition-all ${
                  isDone || isActive
                    ? 'border-purple-500 bg-purple-500 text-white font-extrabold shadow-purple-500/20' 
                    : 'border-slate-200 bg-white text-slate-400'
                }`}>
                  <Armchair size={18} className={isActive ? "animate-bounce" : ""} />
                  {isDone && <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm">✓</div>}
                </div>
              </div>
            );
          })()}

          {/* Comida Node */}
          {(() => {
            const isDone = mealEndTimes[99] !== undefined;
            const isActive = clockState === 'meal';
            return (
              <div 
                onClick={() => (isDone || isActive) && setActiveModal('meal')}
                className={`w-1/4 flex flex-col items-center relative transition-all duration-300 transform ${
                  isDone || isActive ? 'cursor-pointer hover:scale-105 active:scale-95' : 'cursor-default'
                }`}
              >
                <span className="text-[8.5px] font-black uppercase tracking-wider mb-0.5 text-amber-650 dark:text-amber-400">Comida</span>
                <div className={`rounded-full flex items-center justify-center border-2 relative shadow-md w-11 h-11 transition-all ${
                  isDone || isActive
                    ? 'border-amber-500 bg-amber-500 text-white font-extrabold shadow-amber-500/20' 
                    : 'border-slate-200 bg-white text-slate-400'
                }`}>
                  <Utensils size={18} className={isActive ? "animate-bounce" : ""} />
                  {isDone && <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm">✓</div>}
                </div>
              </div>
            );
          })()}

          {/* Salida Node */}
          <div 
            onClick={() => hasCheckedOut && setActiveModal('exit')}
            className={`w-1/4 flex flex-col items-center relative transition-all duration-300 transform ${
              hasCheckedOut ? 'cursor-pointer hover:scale-105 active:scale-95' : 'cursor-default'
            }`}
          >
            <span className="text-[8.5px] font-black uppercase tracking-wider mb-0.5 text-emerald-650 dark:text-emerald-400">Salida</span>
            <div className={`rounded-full flex items-center justify-center border-2 relative shadow-md w-11 h-11 transition-all ${
              hasCheckedOut 
                ? 'border-emerald-500 bg-emerald-500 text-white font-extrabold shadow-emerald-500/20' 
                : 'border-slate-200 bg-white text-slate-400'
            }`}>
              <LogOut size={18} />
              {hasCheckedOut && <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm">✓</div>}
            </div>
          </div>
        </div>

        {/* Barra cronológica proporcional */}
        <div className="relative w-full z-0 px-1 mb-2 mt-2">
          <div className="relative w-full h-4 bg-slate-200/50 dark:bg-slate-800/50 rounded-2xl border border-slate-350/20 shadow-inner overflow-hidden">
            {hasCheckedIn && elapsedTotal > 0 && (
              <div 
                className="absolute top-0 left-0 h-full rounded-2xl overflow-hidden flex transition-all duration-750 ease-out"
                style={{ width: `${progressPercent}%` }}
              >
                {segmentsList.map((seg, sIdx) => {
                  let segBg = 'bg-gradient-to-r from-emerald-450 to-teal-500';
                  if (seg.type === 'break') segBg = 'bg-gradient-to-r from-purple-500 to-indigo-600';
                  if (seg.type === 'meal') segBg = 'bg-gradient-to-r from-amber-500 to-orange-500';
                  return (
                    <div 
                      key={sIdx}
                      style={{ width: `${(seg.mins / elapsedTotal) * 100}%` }}
                      className={`h-full ${segBg} shrink-0`}
                    ></div>
                  );
                })}
              </div>
            )}

            <div className="absolute inset-0 flex justify-between items-center px-3 pointer-events-none z-10 text-[9px] font-mono font-bold text-slate-500 dark:text-slate-400">
              <span>
                {hasCheckedIn ? formatMinsToTimeClean(545) : '09:00 am'}
              </span>
              <span>
                {hasCheckedOut ? formatMinsToTimeClean(1080) : '06:00 pm'}
              </span>
            </div>
          </div>
        </div>
      </div>
    );
  };

  // Enviar mensaje en el chat copiloto
  const handleSendMsg = () => {
    if (!newMsg.trim()) return;
    const userMessage = newMsg;
    setChatMessages(prev => [...prev, { sender: 'user', text: userMessage }]);
    setNewMsg('');

    setTimeout(() => {
      let botResponse = 'Entendido. Procesando solicitud en tu turno...';
      if (userMessage.toLowerCase().includes('ayuda') || userMessage.toLowerCase().includes('soporte')) {
        botResponse = 'Para solicitar soporte, puedes reportar una bitácora en la sección Menú o comunicarte con tu administrador.';
      } else if (userMessage.toLowerCase().includes('tarea') || userMessage.toLowerCase().includes('rutina')) {
        botResponse = 'Hecho. La tarea operativa ha sido generada y asignada al tablero de Francisco Vega.';
      } else if (userMessage.toLowerCase().includes('llave')) {
        botResponse = 'La transferencia de llaves de la sucursal Centro requiere la firma digital de supervisor.';
      }
      setChatMessages(prev => [...prev, { sender: 'bot', text: botResponse }]);
    }, 1000);
  };

  return (
    <div className={`w-full h-full flex flex-col justify-between overflow-hidden relative ${isDark ? 'bg-slate-950 text-slate-100' : 'bg-slate-50 text-slate-850'}`}>
      
      {/* RENDER UNIFIED MOBILE HEADER */}
      {renderUnifiedMobileHeader()}

      {/* ZONA DE CONTENIDO MÓVIL (Con padding para evitar colisionar con Header y Footer) */}
      <div className="flex-1 overflow-y-auto px-4 pt-[74px] pb-[72px] flex flex-col justify-between gap-2 scrollbar-none relative z-10">
        
        {phoneTab === 'checador' && (
          <div className="flex-grow flex flex-col justify-between gap-2 py-2">
            
            {/* Cronómetro/Hora Digital (Único reloj del panel) */}
            <div className="text-center py-2 shrink-0">
              <div className="text-2xl font-black font-mono tracking-tight">{formattedTime}</div>
              <p className="text-[8.5px] font-black uppercase text-slate-400 tracking-widest mt-1">
                {clockState === 'inactive' && 'Jornada Sin Iniciar'}
                {clockState === 'active' && '⏱️ Registrando Jornada'}
                {clockState === 'short_break' && '💤 En Descanso Corto'}
                {clockState === 'meal' && '🍲 Horario de Comida'}
                {clockState === 'finished' && '🏁 Jornada Finalizada'}
              </p>
            </div>

            {/* Barra Cronológica Proporcional Real */}
            {renderBarraCronologica()}

            {/* Dial Central Principal (Importado de producción) */}
            <div className="flex flex-col items-center justify-center shrink-0 my-auto">
              <DialPrincipal
                isMobile={true}
                isOpeningPremium={true}
                storeStatus="open"
                openingStatus={null}
                currentUser={currentUser}
                isWithinPerimeter={true}
                globalUsers={[]}
                clockState={clockState}
                formattedTime={formattedTime}
                btnProps={{
                  disabled: clockState === 'finished',
                  text: btnProps.text,
                  subtext: btnProps.subtext
                }}
                lateUsers={{}}
                currentDay={currentDay}
                currentSimTime={currentSimTime}
                shiftConfigs={shiftConfigs}
                parseTimeToMins={parseTimeToMins}
                handleAction={handleAction}
              />

              {/* Botones de flujo complementario */}
              {clockState === 'active' && (
                <div className="flex gap-2 mt-4">
                  <button
                    onClick={handleStartMealSim}
                    className="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-[9px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-amber-500/10 cursor-pointer"
                  >
                    Ir a Comida
                  </button>
                  <button
                    onClick={handleClockOutSim}
                    className="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white text-[9px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-rose-600/10 cursor-pointer"
                  >
                    Fichar Salida
                  </button>
                </div>
              )}

              {clockState === 'finished' && (
                <button
                  onClick={handleResetSim}
                  className="mt-4 px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-[9px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-indigo-600/10 cursor-pointer"
                >
                  Reiniciar Simulación
                </button>
              )}
            </div>

            {/* Alertas dinámicas bajo el dial */}
            <div className="space-y-2 mt-2 shrink-0">
              <div className={`p-3 border rounded-2xl flex items-center gap-3 text-left ${
                isDark ? 'bg-rose-955/20 border-rose-900/40 text-rose-300' : 'bg-rose-50/70 border-rose-100/60 text-rose-800'
              }`}>
                <span className="text-sm shrink-0">⚠️</span>
                <div>
                  <p className="text-[9px] font-black uppercase tracking-widest text-rose-700 dark:text-rose-455">Alerta de Tareas</p>
                  <p className="text-[11px] font-bold mt-0.5 dark:text-slate-300">Tienes 1 tarea operativa pendiente para hoy.</p>
                </div>
              </div>

              <div className={`p-3 border rounded-2xl flex items-center gap-3 text-left ${
                isDark ? 'bg-blue-955/20 border-blue-900/40 text-blue-300' : 'bg-blue-50/70 border-blue-100/60 text-blue-800'
              }`}>
                <span className="text-sm shrink-0">📅</span>
                <div>
                  <p className="text-[9px] font-black uppercase tracking-widest text-blue-800 dark:text-blue-455">Jornada / Horario</p>
                  <p className="text-[11px] font-bold mt-0.5 dark:text-slate-300">Hora sugerida de comida: 02:00 pm</p>
                </div>
              </div>
            </div>

          </div>
        )}

        {phoneTab === 'tareas' && (
          <div className="flex-grow text-left py-2">
            <div className="space-y-2">
              <div className={`p-3 rounded-xl border flex items-center justify-between ${
                isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
              }`}>
                <div>
                  <h5 className="text-[10px] font-bold">Limpieza y sanitización de barra</h5>
                  <p className="text-[8px] text-slate-400 mt-0.5">Prioridad Alta • 10 pts</p>
                </div>
                <div className="w-5 h-5 rounded border-2 border-emerald-500 bg-emerald-500 text-white flex items-center justify-center text-[10px] font-bold">✓</div>
              </div>

              <div className={`p-3 rounded-xl border flex items-center justify-between ${
                isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
              }`}>
                <div>
                  <h5 className="text-[10px] font-bold">Arqueo de caja y terminales</h5>
                  <p className="text-[8px] text-slate-400 mt-0.5">Pendiente de firma de supervisor</p>
                </div>
                <div className="w-5 h-5 rounded border border-slate-300"></div>
              </div>
            </div>

            {tier !== 'pro' && (
              <div className="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl mt-4 flex items-start gap-2">
                <Lock className="text-amber-500 shrink-0 mt-0.5" size={14} />
                <p className="text-[9px] text-amber-500 leading-normal font-medium">
                  <strong>Las Rutinas y Checklists Obligatorios</strong> se bloquean en la versión gratuita. Contrata el Plan PRO para forzar a tus empleados a completar sus tareas diarias.
                </p>
              </div>
            )}
          </div>
        )}

        {phoneTab === 'academia' && (
          <div className="flex-grow text-left py-2">
            <div className={`p-3 rounded-xl border ${
              isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
            }`}>
              <span className="text-[8px] font-black uppercase tracking-wider text-amber-500">Módulo 1</span>
              <h5 className="text-[10px] font-bold mt-0.5">Inducción y Atención al Cliente Premium</h5>
              <div className="w-full bg-slate-250 dark:bg-slate-800 h-1.5 rounded-full mt-2 overflow-hidden">
                <div className="bg-indigo-500 h-full w-[45%]"></div>
              </div>
              <span className="text-[7px] text-slate-400 block mt-1 font-bold">Avance: 45% (2 / 5 lecciones)</span>
            </div>

            {tier !== 'pro' && (
              <div className="p-3 bg-rose-500/10 border border-rose-500/20 rounded-xl mt-4 flex items-start gap-2">
                <Lock className="text-rose-500 shrink-0 mt-0.5" size={14} />
                <p className="text-[9px] text-rose-500 leading-normal font-medium">
                  <strong>La Academia Integrada de Aprendizaje</strong> con certificados automáticos y caminos de carrera al estilo Duolingo requiere una suscripción PRO activa.
                </p>
              </div>
            )}
          </div>
        )}

        {phoneTab === 'herramientas' && (
          <div className="flex-grow text-left py-2">
            <div className="grid grid-cols-2 gap-2">
              {[
                { label: 'Chat de Sucursal', icon: Send, pro: true },
                { label: 'Ley Silla', icon: Star, pro: false },
                { label: 'Incidencias', icon: AlertTriangle, pro: false },
                { label: 'Control de Llaves', icon: ShieldCheck, pro: true }
              ].map((tool, idx) => {
                const ToolIcon = tool.icon;
                const isLocked = tool.pro && tier !== 'pro';
                return (
                  <div 
                    key={idx} 
                    className={`p-3 rounded-xl border flex flex-col justify-between aspect-square transition-all ${
                      isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
                    } ${isLocked ? 'opacity-65' : 'hover:scale-[1.02] cursor-pointer'}`}
                  >
                    <div className="flex justify-between items-start">
                      <div className={`p-1.5 rounded-lg ${isDark ? 'bg-slate-850' : 'bg-slate-100'} text-indigo-500`}>
                        <ToolIcon size={14} />
                      </div>
                      {isLocked && <Lock size={12} className="text-slate-400" />}
                    </div>
                    <span className="text-[9px] font-black leading-tight uppercase mt-2">{tool.label}</span>
                  </div>
                );
              })}
            </div>
          </div>
        )}

      </div>

      {/* RENDER MOBILE BOTTOM NAVIGATION */}
      <div className="absolute bottom-0 inset-x-0 z-30 shrink-0">
        <MobileBottomNav 
          phoneTab={phoneTab} 
          setPhoneTab={setPhoneTab} 
          setInnerTool={setInnerTool} 
          isDark={isDark} 
        />
      </div>

      {/* BOTÓN FLOTANTE OPERATIVO (🛠️ Sparkles) */}
      <div className="absolute bottom-20 right-4 z-40">
        <button
          type="button"
          onClick={() => setIsFabSheetOpen(true)}
          className="w-11 h-11 bg-gradient-to-tr from-violet-600 via-indigo-600 to-violet-600 text-white rounded-full flex items-center justify-center shadow-lg hover:scale-105 active:scale-95 transition-all duration-300 relative border-none outline-none cursor-pointer"
        >
          <Sparkles size={18} className="text-white" />
          <span className="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-emerald-500 rounded-full border-2 border-white animate-ping"></span>
          <span className="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-emerald-500 rounded-full border-2 border-white"></span>
        </button>
      </div>

      {/* SHEET OPERATIVO FLOTANTE (MENÚ DE OPERACIONES FAB) */}
      {isFabSheetOpen && (
        <div className="absolute inset-0 z-50 flex items-end justify-center">
          <div 
            onClick={() => setIsFabSheetOpen(false)}
            className="absolute inset-0 bg-black/40 backdrop-blur-xs transition-opacity"
          ></div>

          <div className={`relative w-full rounded-t-3xl border-t shadow-2xl z-10 flex flex-col pb-6 max-h-[80%] animate-slide-up ${
            isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-100 text-slate-850'
          }`}>
            <div className="w-10 h-1 bg-slate-300 dark:bg-slate-700 rounded-full mx-auto my-2.5 shrink-0"></div>
            
            <div className="px-4 pb-2 border-b dark:border-slate-800 text-left flex justify-between items-center">
              <div>
                <h3 className="text-xs font-black">Operaciones & Soporte AI</h3>
                <p className="text-[9px] text-slate-400">Accesos y herramientas rápidas (Simulado)</p>
              </div>
              <button 
                onClick={() => setIsFabSheetOpen(false)}
                className="w-6 h-6 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center border-none cursor-pointer text-xs"
              >
                ✕
              </button>
            </div>

            <div className="overflow-y-auto p-4 space-y-4 text-left">
              <div className="grid grid-cols-2 gap-3">
                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setIsCopilotOpen(true);
                  }}
                  className={`p-3 rounded-2xl border flex flex-col items-center justify-center gap-1.5 text-center cursor-pointer border-none ${
                    isDark ? 'bg-slate-950/40 text-white' : 'bg-slate-50 text-slate-850'
                  }`}
                >
                  <div className="w-8 h-8 rounded-xl bg-gradient-to-tr from-violet-600 to-indigo-600 text-white flex items-center justify-center">
                    <Bot size={16} />
                  </div>
                  <span className="font-bold text-[10px]">Copiloto AI</span>
                </button>

                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('tareas');
                  }}
                  className={`p-3 rounded-2xl border flex flex-col items-center justify-center gap-1.5 text-center cursor-pointer border-none ${
                    isDark ? 'bg-slate-950/40 text-white' : 'bg-slate-50 text-slate-850'
                  }`}
                >
                  <div className="w-8 h-8 rounded-xl bg-gradient-to-tr from-amber-500 to-orange-500 text-white flex items-center justify-center">
                    <Play size={16} />
                  </div>
                  <span className="font-bold text-[10px]">Crear Tarea</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* DRAWER INTERACTIVO COPILOTO AI */}
      {isCopilotOpen && (
        <div className="absolute inset-0 z-50 flex items-end justify-center">
          <div onClick={() => setIsCopilotOpen(false)} className="absolute inset-0 bg-black/40 backdrop-blur-xs"></div>
          
          <div className={`relative w-full h-[75%] rounded-t-3xl border-t shadow-2xl z-10 flex flex-col pb-4 ${
            isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-100 text-slate-850'
          }`}>
            <div className="w-10 h-1 bg-slate-300 dark:bg-slate-700 rounded-full mx-auto my-2.5 shrink-0"></div>
            
            <div className="px-4 pb-2 border-b dark:border-slate-800 text-left flex justify-between items-center shrink-0">
              <div className="flex items-center gap-2">
                <Bot size={18} className="text-indigo-500" />
                <h3 className="text-xs font-black">Copiloto AI de Turno</h3>
              </div>
              <button onClick={() => setIsCopilotOpen(false)} className="w-6 h-6 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center border-none cursor-pointer text-xs">✕</button>
            </div>

            {/* Chat Body */}
            <div className="flex-1 overflow-y-auto p-4 space-y-3 text-left">
              {chatMessages.map((msg, mIdx) => (
                <div key={mIdx} className={`flex ${msg.sender === 'user' ? 'justify-end' : 'justify-start'}`}>
                  <div className={`max-w-[75%] p-2.5 rounded-2xl text-[10.5px] leading-relaxed ${
                    msg.sender === 'user' 
                      ? 'bg-indigo-600 text-white rounded-tr-none' 
                      : (isDark ? 'bg-slate-800 text-slate-200 rounded-tl-none' : 'bg-slate-100 text-slate-800 rounded-tl-none')
                  }`}>
                    {msg.text}
                  </div>
                </div>
              ))}
            </div>

            {/* Chat Input */}
            <div className="p-3 border-t dark:border-slate-800 flex gap-2 shrink-0">
              <input 
                type="text" 
                value={newMsg}
                onChange={e => setNewMsg(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && handleSendMsg()}
                placeholder="Escribe al asistente..."
                className={`flex-1 px-3 py-1.5 text-xs rounded-xl border outline-none ${
                  isDark ? 'bg-slate-800 border-slate-750 text-white' : 'bg-slate-50 border-slate-200'
                }`}
              />
              <button 
                onClick={handleSendMsg}
                className="p-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl flex items-center justify-center border-none cursor-pointer"
              >
                <Send size={14} />
              </button>
            </div>
          </div>
        </div>
      )}

      {/* PANTALLAS DE VALIDACIÓN PRO SIMULADAS (Selfie + GPS) */}
      {isVerifying && (
        <div className="absolute inset-0 bg-slate-950/90 z-80 flex flex-col items-center justify-center p-6 text-white text-center font-sans">
          {verifyingStep === 'gps' ? (
            <div className="space-y-4 animate-pulse">
              <div className="w-16 h-16 rounded-full bg-indigo-500/20 border-2 border-indigo-500 flex items-center justify-center mx-auto shadow-[0_0_20px_rgba(99,102,241,0.5)]">
                <MapPin size={32} className="text-indigo-400 animate-bounce" />
              </div>
              <h4 className="text-xs font-black uppercase tracking-wider text-indigo-400">Verificando Geolocalización</h4>
              <p className="text-[10px] text-slate-400">Confirmando que te encuentras dentro del perímetro permitido de la sucursal...</p>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="relative w-24 h-24 rounded-full border-2 border-emerald-500 flex items-center justify-center mx-auto overflow-hidden">
                <div className="absolute inset-x-0 h-1 bg-emerald-500 animate-[bounce_2s_infinite]"></div>
                <Camera size={40} className="text-emerald-400" />
              </div>
              <h4 className="text-xs font-black uppercase tracking-wider text-emerald-400">Fichaje Seguro con Selfie</h4>
              <p className="text-[10px] text-slate-400">Capturando y validando fotografía de rostro del colaborador...</p>
            </div>
          )}
        </div>
      )}

      {/* MODALES DE DETALLE DE FICHAJE DE LA BARRA CRONOLÓGICA */}
      {activeModal && (
        <div className="absolute inset-0 z-[100] flex items-center justify-center p-4">
          <div onClick={() => setActiveModal(null)} className="absolute inset-0 bg-black/60 backdrop-blur-xs"></div>
          
          <div className={`relative w-full max-w-[280px] rounded-3xl p-5 border text-center shadow-2xl animate-scale-up ${
            isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-200 text-slate-850'
          }`}>
            <button 
              onClick={() => setActiveModal(null)}
              className="absolute top-3 right-3 text-slate-400 hover:text-slate-650 cursor-pointer border-none bg-transparent font-bold text-sm"
            >
              ✕
            </button>

            {activeModal === 'entry' && (
              <div className="space-y-3.5 text-left">
                <div className="flex items-center gap-2">
                  <LogIn className="text-indigo-500" size={18} />
                  <h4 className="text-xs font-black uppercase tracking-wider">Detalles de Entrada</h4>
                </div>
                
                <div className="space-y-2 text-[10px]">
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Hora de Registro</span>
                    <span className="font-mono font-bold">09:05:12 AM</span>
                  </div>
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Tolerancia</span>
                    <span className="text-amber-500 font-bold">Retardo (5 min)</span>
                  </div>
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">GPS (Geocerca)</span>
                    <span className="text-emerald-500 font-bold">Válido (Dentro)</span>
                  </div>
                  {tier === 'pro' && (
                    <div className="flex items-center justify-between border-b pb-1 dark:border-slate-800">
                      <span className="text-slate-400">Foto Selfie</span>
                      <img src={currentUser.avatar} alt="Selfie" className="w-5 h-5 rounded object-cover border border-emerald-500" />
                    </div>
                  )}
                </div>
              </div>
            )}

            {activeModal === 'break' && (
              <div className="space-y-3.5 text-left">
                <div className="flex items-center gap-2">
                  <Armchair className="text-purple-500" size={18} />
                  <h4 className="text-xs font-black uppercase tracking-wider">Detalles de Descanso</h4>
                </div>
                
                <div className="space-y-2 text-[10px]">
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Inicio de Descanso</span>
                    <span className="font-mono font-bold">12:00:10 PM</span>
                  </div>
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Fin de Descanso</span>
                    <span className="font-mono font-bold">12:15:05 PM</span>
                  </div>
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Duración</span>
                    <span className="text-emerald-500 font-bold">15 min (Dentro del límite)</span>
                  </div>
                </div>
              </div>
            )}

            {activeModal === 'meal' && (
              <div className="space-y-3.5 text-left">
                <div className="flex items-center gap-2">
                  <Utensils className="text-amber-500" size={18} />
                  <h4 className="text-xs font-black uppercase tracking-wider">Detalles de Comida</h4>
                </div>
                
                <div className="space-y-2 text-[10px]">
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Inicio de Comida</span>
                    <span className="font-mono font-bold">02:00:08 PM</span>
                  </div>
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Fin de Comida</span>
                    <span className="font-mono font-bold">02:45:00 PM</span>
                  </div>
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Duración</span>
                    <span className="text-emerald-500 font-bold">45 min (Exacto)</span>
                  </div>
                </div>
              </div>
            )}

            {activeModal === 'exit' && (
              <div className="space-y-3.5 text-left">
                <div className="flex items-center gap-2">
                  <LogOut className="text-emerald-500" size={18} />
                  <h4 className="text-xs font-black uppercase tracking-wider">Detalles de Salida</h4>
                </div>
                
                <div className="space-y-2 text-[10px]">
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Hora de Registro</span>
                    <span className="font-mono font-bold">06:00:00 PM</span>
                  </div>
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Tiempo Trabajado</span>
                    <span className="font-bold">8 horas 55 minutos</span>
                  </div>
                  <div className="flex justify-between border-b pb-1 dark:border-slate-800">
                    <span className="text-slate-400">Resumen de Turno</span>
                    <span className="text-emerald-500 font-bold">Excelente (Sin desvíos)</span>
                  </div>
                </div>
              </div>
            )}

            <button 
              onClick={() => setActiveModal(null)}
              className="mt-4 w-full py-1.5 bg-indigo-650 hover:bg-indigo-700 text-white text-[10px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md cursor-pointer border-none"
            >
              Entendido
            </button>
          </div>
        </div>
      )}

    </div>
  );
};
