import React, { useState, useEffect } from 'react';
import { 
  LogIn, LogOut, Armchair, Utensils, Clock, Briefcase, 
  GraduationCap, Settings, MapPin, Camera, Lock, Check,
  AlertTriangle, Star, ShieldCheck, HeartHandshake, Award, Send
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

  // Datos simulados de tiempo
  const [currentSimTime, setCurrentSimTime] = useState(540); // 09:00 AM
  const [formattedTime, setFormattedTime] = useState('09:00:00 AM');

  // Estados de fichajes simulados para Francisco Vega (ID: 99)
  const [arrivalTimes, setArrivalTimes] = useState<Record<number, number>>({});
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

  // Transiciones de estado del dial
  const handleAction = () => {
    if (clockState === 'inactive') {
      setClockState('active');
      setCheckInTimes({ 99: 545 }); // Entrada 09:05 AM
      setCurrentSimTime(545);
      setFormattedTime('09:05:00 AM');
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
    setClockState('finished');
    setCheckOutTimes({ 99: 1080 }); // Salida 06:00 PM
    setCurrentSimTime(1080);
    setFormattedTime('06:00:00 PM');
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

  // Ayudantes de conversión
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

  // Variables para render de barra cronológica
  const hasCheckedIn = checkInTimes[99] !== undefined;
  const hasCheckedOut = checkOutTimes[99] !== undefined;

  const renderBarraCronologica = () => {
    const isLateIn = false;
    const isBreakExceeded = false;
    const isMealExceeded = false;
    const hasAnyDeviation = false;

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
        <div className="flex justify-between items-center w-full font-bold uppercase tracking-wider text-[9.5px] mb-4 px-1">
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

        {/* Nodos de Fichaje */}
        <div className="flex w-full z-10 relative px-0 mb-0 mt-1">
          {/* Entrada Node */}
          <div className="w-1/4 flex flex-col items-center relative transition-all duration-300">
            <span className="text-[9px] font-black uppercase tracking-wider mb-0.5 text-indigo-650 dark:text-indigo-400">Entrada</span>
            <div className={`rounded-full flex items-center justify-center border-2 relative shadow-md w-11 h-11 ${
              hasCheckedIn 
                ? 'border-indigo-500 bg-indigo-500 text-white font-extrabold scale-105' 
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
              <div className="w-1/4 flex flex-col items-center relative transition-all duration-300">
                <span className="text-[9px] font-black uppercase tracking-wider mb-0.5 text-purple-650 dark:text-purple-400">Descanso</span>
                <div className={`rounded-full flex items-center justify-center border-2 relative shadow-md w-11 h-11 ${
                  isDone || isActive
                    ? 'border-purple-500 bg-purple-500 text-white font-extrabold scale-105' 
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
              <div className="w-1/4 flex flex-col items-center relative transition-all duration-300">
                <span className="text-[9px] font-black uppercase tracking-wider mb-0.5 text-amber-650 dark:text-amber-400">Comida</span>
                <div className={`rounded-full flex items-center justify-center border-2 relative shadow-md w-11 h-11 ${
                  isDone || isActive
                    ? 'border-amber-500 bg-amber-500 text-white font-extrabold scale-105' 
                    : 'border-slate-200 bg-white text-slate-400'
                }`}>
                  <Utensils size={18} className={isActive ? "animate-bounce" : ""} />
                  {isDone && <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm">✓</div>}
                </div>
              </div>
            );
          })()}

          {/* Salida Node */}
          <div className="w-1/4 flex flex-col items-center relative transition-all duration-300">
            <span className="text-[9px] font-black uppercase tracking-wider mb-0.5 text-emerald-650 dark:text-emerald-400">Salida</span>
            <div className={`rounded-full flex items-center justify-center border-2 relative shadow-md w-11 h-11 ${
              hasCheckedOut 
                ? 'border-emerald-500 bg-emerald-500 text-white font-extrabold scale-105' 
                : 'border-slate-200 bg-white text-slate-400'
            }`}>
              <LogOut size={18} />
              {hasCheckedOut && <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm">✓</div>}
            </div>
          </div>
        </div>

        {/* Timeline Bar - Thicker, Larger, and Innovatively Styled */}
        <div className="relative w-full z-0 px-2 mb-2 mt-1.5">
          <div className="relative w-full h-5 bg-slate-200/50 dark:bg-slate-800/50 rounded-2xl border border-slate-350/20 shadow-inner overflow-hidden">
            {hasCheckedIn && elapsedTotal > 0 && (
              <div 
                className="absolute top-0 left-0 h-full rounded-2xl overflow-hidden flex transition-all duration-700 ease-out"
                style={{ width: `${progressPercent}%` }}
              >
                {segmentsList.map((seg, sIdx) => {
                  let segBg = 'bg-gradient-to-r from-emerald-400 to-teal-500';
                  if (seg.type === 'break') segBg = 'bg-gradient-to-r from-purple-500 to-indigo-650';
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

            <div className="absolute inset-0 flex justify-between items-center px-3 pointer-events-none z-10 text-[10.5px] font-mono font-bold text-slate-500 dark:text-slate-400">
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

  return (
    <div className={`w-full h-full flex flex-col justify-between overflow-hidden relative ${isDark ? 'bg-slate-950 text-slate-100' : 'bg-slate-50 text-slate-850'}`}>
      
      {/* Contenido Principal con Scrollable */}
      <div className="flex-1 flex flex-col justify-between h-full overflow-hidden">
        
        {/* Header Móvil Uniforme */}
        <div className={`w-full px-4 pt-4 pb-3 flex justify-between items-center shrink-0 border-b relative z-30 transition-colors duration-300 ${
          isDark ? 'bg-slate-900/90 border-slate-800' : 'bg-white/90 border-slate-100'
        } backdrop-blur-md`}>
          <div className="flex flex-col text-left">
            <h1 className="text-xs font-black uppercase tracking-widest text-indigo-500">Talent 360</h1>
            <p className="text-[10px] text-slate-400 font-bold mt-0.5">Sucursal: Decorarte 365</p>
          </div>
          <button 
            onClick={() => setIsDark(!isDark)}
            className={`p-1.5 rounded-xl border transition-all text-xs ${
              isDark ? 'border-slate-800 bg-slate-800 text-amber-400' : 'border-slate-100 bg-slate-50 text-slate-600'
            }`}
          >
            {isDark ? '☀️' : '🌙'}
          </button>
        </div>

        {/* Zona de Contenido Móvil */}
        {phoneTab === 'checador' && (
          <div className="flex-1 overflow-y-auto px-4 pt-3 pb-[82px] flex flex-col justify-between gap-2 scrollbar-none">
            
            {/* Credencial Empleado */}
            <div className={`p-3 rounded-2xl border transition-all ${
              isDark ? 'bg-slate-900/60 border-slate-800' : 'bg-white border-slate-200'
            } shadow-sm flex items-center gap-3 text-left`}>
              <img 
                src={currentUser.avatar} 
                alt="Avatar" 
                className="w-10 h-10 rounded-full border-2 border-emerald-500 object-cover"
              />
              <div className="flex-grow">
                <h4 className="text-xs font-bold leading-tight">{currentUser.name}</h4>
                <p className="text-[9px] text-slate-400 font-medium">Colaborador • Decorarte 365</p>
              </div>
              <span className="px-2 py-0.5 text-[8px] font-black uppercase rounded bg-indigo-500/10 text-indigo-500 border border-indigo-500/20">
                {tier.toUpperCase()}
              </span>
            </div>

            {/* Cronómetro Digital */}
            <div className="text-center py-2 shrink-0">
              <div className="text-2xl font-black font-mono tracking-tight">{formattedTime}</div>
              <p className="text-[8px] font-black uppercase text-slate-400 tracking-widest mt-1">
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
            <div className="flex flex-col items-center justify-center shrink-0">
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

              {/* Botones complementarios del flujo */}
              {clockState === 'active' && (
                <div className="flex gap-2 mt-4">
                  <button
                    onClick={handleStartMealSim}
                    className="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-[9px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-amber-500/10"
                  >
                    Ir a Comida
                  </button>
                  <button
                    onClick={handleClockOutSim}
                    className="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white text-[9px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-rose-600/10"
                  >
                    Fichar Salida
                  </button>
                </div>
              )}

              {clockState === 'finished' && (
                <button
                  onClick={handleResetSim}
                  className="mt-4 px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-[9px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-indigo-600/10"
                >
                  Reiniciar Simulación
                </button>
              )}
            </div>

            {/* Alertas dinámicas bajo el dial */}
            <div className="space-y-2 mt-4 shrink-0">
              <div className={`p-3 border rounded-2xl flex items-center gap-3 text-left ${
                isDark ? 'bg-rose-955/20 border-rose-900/40 text-rose-300' : 'bg-rose-50/70 border-rose-100/60 text-rose-800'
              }`}>
                <span className="text-sm shrink-0">⚠️</span>
                <div>
                  <p className="text-[9px] font-black uppercase tracking-widest text-rose-700 dark:text-rose-450">Alerta de Tareas</p>
                  <p className="text-[11px] font-bold mt-0.5 dark:text-slate-300">Tienes 1 tarea operativa pendiente para hoy.</p>
                </div>
              </div>

              <div className={`p-3 border rounded-2xl flex items-center gap-3 text-left ${
                isDark ? 'bg-blue-955/20 border-blue-900/40 text-blue-300' : 'bg-blue-50/70 border-blue-100/60 text-blue-800'
              }`}>
                <span className="text-sm shrink-0">📅</span>
                <div>
                  <p className="text-[9px] font-black uppercase tracking-widest text-blue-800 dark:text-blue-450">Jornada / Horario</p>
                  <p className="text-[11px] font-bold mt-0.5 dark:text-slate-300">Hora sugerida de comida: 02:00 pm</p>
                </div>
              </div>
            </div>

          </div>
        )}

        {phoneTab === 'tareas' && (
          <div className="flex-1 overflow-y-auto px-4 pt-3 pb-[82px] text-left">
            <h4 className="text-[11px] font-black uppercase text-indigo-500 tracking-wider border-b pb-2 dark:border-slate-800">Tablero de Tareas</h4>
            
            <div className="space-y-2 mt-3">
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
          <div className="flex-1 overflow-y-auto px-4 pt-3 pb-[82px] text-left">
            <h4 className="text-[11px] font-black uppercase text-indigo-500 tracking-wider border-b pb-2 dark:border-slate-800">Academia y Capacitación</h4>
            
            <div className={`p-3 rounded-xl border mt-3 ${
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
          <div className="flex-1 overflow-y-auto px-4 pt-3 pb-[82px] text-left">
            <h4 className="text-[11px] font-black uppercase text-indigo-500 tracking-wider border-b pb-2 dark:border-slate-800">Menú de Herramientas</h4>
            
            <div className="grid grid-cols-2 gap-2 mt-3">
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

        {/* Menú de Navegación Inferior (Importado de producción) */}
        <div className="absolute bottom-0 inset-x-0 z-30 shrink-0">
          <MobileBottomNav 
            phoneTab={phoneTab} 
            setPhoneTab={setPhoneTab} 
            setInnerTool={setInnerTool} 
            isDark={isDark} 
          />
        </div>

      </div>

    </div>
  );
};
