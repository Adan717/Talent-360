import React, { useState, useEffect } from 'react';
import { 
  LogIn, LogOut, Armchair, Utensils, Clock, Briefcase, 
  GraduationCap, Settings, MapPin, Camera, Lock, Check,
  AlertTriangle, Star, ShieldCheck, HeartHandshake, Award, 
  Send, Sparkles, CheckSquare, ClipboardList, Network, Bot, 
  Play, MessageSquare, AlertOctagon, HelpCircle, X, ChevronRight, User,
  DollarSign, FileText, CheckCircle2
} from 'lucide-react';
import DialPrincipal from './reloj/DialPrincipal';
import { MobileBottomNav } from './reloj/MobileBottomNav';
import { TaskRunner } from './tareas_rutinas/TaskRunner';
import { useTaskStore } from '../store/useTaskStore';
import { useAppStore } from '../store/useAppStore';

interface RelojSimuladoLandingProps {
  tier: 'free' | 'pro';
  setTier?: (tier: 'free' | 'pro') => void;
  onActionClick?: () => void;
  empName?: string;
  storeName?: string;
}

export const RelojSimuladoLanding: React.FC<RelojSimuladoLandingProps> = ({
  tier,
  setTier,
  onActionClick,
  empName = 'Francisco Vega',
  storeName = 'Decorarte 365'
}) => {
  const [clockState, setClockState] = useState<'inactive' | 'active' | 'short_break' | 'meal' | 'finished'>('inactive');
  const [phoneTab, setPhoneTab] = useState<string>('checador');
  const [innerTool, setInnerTool] = useState<string | null>(null);
  const [isDark, setIsDark] = useState(false);
  const [simTask1Done, setSimTask1Done] = useState(false);
  const [simTask2Done, setSimTask2Done] = useState(false);

  const [isVerifying, setIsVerifying] = useState(false);
  const [verifyingStep, setVerifyingStep] = useState<'gps' | 'selfie' | 'success'>('gps');
  const [dialTransition, setDialTransition] = useState<'idle' | 'taking_break' | 'taking_meal'>('idle');

  // Control de Hojas inferiores y Modales
  const [isFabSheetOpen, setIsFabSheetOpen] = useState(false);
  const [isCopilotOpen, setIsCopilotOpen] = useState(false);
  const [activeModal, setActiveModal] = useState<'entry' | 'break' | 'meal' | 'exit' | null>(null);
  const [showPromoGancho, setShowPromoGancho] = useState(false);
  const [showBlockProModal, setShowBlockProModal] = useState<string | null>(null);

  // Datos simulados de tiempo
  const [currentSimTime, setCurrentSimTime] = useState(540); // 09:00 AM
  // Formateador de tiempo interactivo de alta fidelidad sin ceros a la izquierda
  const getFormattedTimeText = (minsVal: number) => {
    const hrs = Math.floor(minsVal / 60);
    const mins = minsVal % 60;
    const displayHrs = hrs > 12 ? hrs - 12 : hrs === 0 ? 12 : hrs; // Sin padStart en horas
    const ampm = hrs >= 12 ? 'pm' : 'am';
    return `${displayHrs}:${mins.toString().padStart(2, '0')} ${ampm}`;
  };

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
    name: empName,
    avatar: 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=80&h=80&fit=crop&crop=faces',
    role: 'empleado',
    job_role_id: 1,
    tenant: { name: storeName }
  };

  const shiftConfigs = {
    99: { restDay: 'Domingo', start: '09:00', end: '18:00', mealMinutes: 45 }
  };

  const currentDay = 'Lunes';

  // Simular avance del tiempo y sincronizar con useAppStore
  useEffect(() => {
    let timer: any;
    if (clockState === 'active') {
      timer = setInterval(() => {
        setCurrentSimTime(prev => {
          const newVal = prev + 1;
          useAppStore.getState().setGlobalSimTime(newVal);
          return newVal;
        });
      }, 4000);
    }
    return () => clearInterval(timer);
  }, [clockState]);

  // Inyectar datos mock para Tareas, Usuarios y Roles en la versión PRO del simulador
  useEffect(() => {
    if (tier === 'pro') {
      const taskStore = useTaskStore.getState();
      const appStore = useAppStore.getState();

      // Inyectar usuario Francisco Vega
      appStore.setGlobalUsers([
        {
          id: 99,
          name: empName,
          email: 'francisco@talent360.com',
          role: 'colaborador',
          job_role_id: 1,
          has_completed_induction: false
        } as any
      ]);

      // Inyectar roles globales
      appStore.setGlobalRoles([
        {
          id: 1,
          name: 'Ayudante Integral'
        }
      ]);

      // Inyectar tareas demo alineadas
      taskStore.setTasks([
        {
          id: 101,
          title: 'Limpieza General Sucursal',
          description: 'Sanitizar mostradores y barrer entrada',
          estimatedMins: 15,
          priority: 'normal',
          category: 'operativo',
          targetType: 'role',
          targetId: 1,
          points: 10,
          subTasks: [
            { id: 1, text: 'Limpiar Mostradores', completed: false },
            { id: 2, text: 'Barrer Entrada', completed: false }
          ],
          assistantType: 'ninguno',
          isAutoCapture: false,
          historicalMins: []
        },
        {
          id: 102,
          title: 'Arqueo de Caja y Cierre',
          description: 'Conciliar ventas del día en terminal',
          estimatedMins: 30,
          priority: 'bloqueante',
          category: 'operativo',
          targetType: 'role',
          targetId: 1,
          points: 20,
          subTasks: [
            { id: 3, text: 'Corte de Terminal bancaria', completed: false },
            { id: 4, text: 'Contar Efectivo', completed: false }
          ],
          assistantType: 'evidencia_foto',
          isAutoCapture: false,
          historicalMins: []
        }
      ]);

      // Inyectar asignaciones demo
      taskStore.setAssignments([
        {
          id: 'asg-101',
          taskId: 101,
          userId: 99,
          status: 'pending',
          startedAtMins: null,
          completedAtMins: null,
          accumulatedMins: 0
        },
        {
          id: 'asg-102',
          taskId: 102,
          userId: 99,
          status: 'pending',
          startedAtMins: null,
          completedAtMins: null,
          accumulatedMins: 0
        }
      ]);
    }
  }, [tier, empName]);

  // Ejecuta la validación de fichaje (GPS + Selfie en Plan PRO)
  const runProVerification = (onComplete: () => void) => {
    if (tier === 'pro') {
      setIsVerifying(true);
      setVerifyingStep('gps');
      
      // Pasar a Selfie tras 1.0 segundos
      setTimeout(() => {
        setVerifyingStep('selfie');
        
        // Pasar a éxito tras 1.0 segundos
        setTimeout(() => {
          setVerifyingStep('success');
          
          // Terminar validación y proceder tras 1.2 segundos
          setTimeout(() => {
            setIsVerifying(false);
            onComplete();
          }, 1200);
        }, 1000);
      }, 1000);
    } else {
      // Feedback visual rápido de éxito en versión básica (Free)
      setIsVerifying(true);
      setVerifyingStep('success');
      setTimeout(() => {
        setIsVerifying(false);
        onComplete();
      }, 1200);
    }
  };

  // Transiciones de estado del dial
  const handleAction = () => {
    const stateStr = clockState as string;
    if (stateStr === 'inactive') {
      // Inicia verificación visual de Entrada (GPS + Selfie en Pro, Éxito visual en Free)
      runProVerification(() => {
        setClockState('active');
        setCheckInTimes({ 99: 545 }); // Entrada 09:05 AM
        setCurrentSimTime(545);
      });
    } else if (stateStr === 'active') {
      if (tier === 'free') {
        // Simular éxito visual de salida
        runProVerification(() => {
          setClockState('finished');
          setCheckOutTimes({ 99: 1080 }); // Salida 06:00 PM
          setCurrentSimTime(1080);
          setTimeout(() => {
            setShowPromoGancho(true);
          }, 800);
        });
      } else {
        const hasTakenBreak = breaksTaken[99] !== undefined;
        const hasTakenMeal = mealEndTimes[99] !== undefined;

        if (!hasTakenBreak) {
          // Iniciar animación temporal de "tomando descanso"
          setDialTransition('taking_break');
          setTimeout(() => {
            setClockState('short_break');
            setBreakStartTimes({ 99: 720 }); // Descanso 12:00 PM
            setCurrentSimTime(720);
            setDialTransition('idle');
          }, 1500);
        } else if (!hasTakenMeal) {
          // Iniciar animación temporal de "salida a comida"
          setDialTransition('taking_meal');
          setTimeout(() => {
            setClockState('meal');
            setMealStartTimes({ 99: 840 }); // Comida 02:00 PM
            setCurrentSimTime(840);
            setDialTransition('idle');
          }, 1500);
        } else {
          // Simular salida Pro (GPS + Selfie + Success)
          runProVerification(() => {
            setClockState('finished');
            setCheckOutTimes({ 99: 1080 }); // Salida 06:00 PM
            setCurrentSimTime(1080);
            setTimeout(() => {
              setShowPromoGancho(true);
            }, 800);
          });
        }
      }
    } else if (stateStr === 'short_break') {
      // Regresar de descanso
      setClockState('active');
      setBreakEndTimes({ 99: 735 }); // Regreso 12:15 PM
      setBreaksTaken({ 99: 1 });
      setCurrentSimTime(735);
    } else if (stateStr === 'meal') {
      // Regresar de comida
      setClockState('active');
      setMealEndTimes({ 99: 885 }); // Regreso 02:45 PM
      setHasReservedMeal({ 99: true });
      setCurrentSimTime(885);
    }
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
    setShowPromoGancho(false);
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
    // 1. Secuencia de validación activa (GPS, Selfie y Éxito)
    if (isVerifying) {
      if (verifyingStep === 'gps') {
        return { disabled: true, text: '📍 Buscando GPS...', subtext: 'Verificando perímetro...', iconKey: 'verifying_gps' };
      }
      if (verifyingStep === 'selfie') {
        return { disabled: true, text: '📸 Validando Selfie...', subtext: 'Identificación biométrica...', iconKey: 'verifying_selfie' };
      }
      if (verifyingStep === 'success') {
        return { disabled: true, text: '✓ Fichaje Registrado', subtext: '¡Operación exitosa!', iconKey: 'success_check' };
      }
    }

    // 2. Transiciones de estados temporales (Tomando descanso/comida)
    if (dialTransition === 'taking_break') {
      return { disabled: true, text: '☕ Tomando Descanso...', subtext: 'Registrando salida...', iconKey: 'break_start' };
    }
    if (dialTransition === 'taking_meal') {
      return { disabled: true, text: '🍱 Iniciando Comida...', subtext: 'Salida a comedor...', iconKey: 'meal_prompt' };
    }

    // 3. Estados operativos normales
    if (clockState === 'inactive') {
      return { disabled: false, text: 'Registrar Entrada', subtext: empName, iconKey: 'entrada' };
    }
    if (clockState === 'active') {
      if (tier === 'free') {
        // En básico, solo entrada y salida
        return { disabled: false, text: 'Registrar Salida', subtext: 'Fichaje de Salida', iconKey: 'exit' };
      }
      const hasBreak = breaksTaken[99] !== undefined;
      const hasMeal = mealEndTimes[99] !== undefined;
      if (!hasBreak) {
        return { disabled: false, text: 'Descanso Ley Silla', subtext: 'Tomar 15 Minutos', iconKey: 'break_start' };
      }
      if (!hasMeal) {
        return { disabled: false, text: 'Iniciar Horario de Comida', subtext: 'Tomar 45 Minutos', iconKey: 'meal_start' };
      }
      return { disabled: false, text: 'Registrar Salida', subtext: 'Fichaje de Salida', iconKey: 'exit' };
    }
    if (clockState === 'short_break') {
      return { disabled: false, text: 'Regresar de Descanso', subtext: 'Retomar Turno', iconKey: 'break_end' };
    }
    if (clockState === 'meal') {
      return { disabled: false, text: 'Regresar de Comida', subtext: 'Retomar Turno', iconKey: 'meal_end' };
    }
    return { disabled: true, text: 'Jornada Finalizada', subtext: 'Turno Completado', iconKey: 'finished' };
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
        icon = <Clock className="text-[#2dce89] animate-spin-once" />;
        badgeText = tier === 'pro' ? 'v4.3-pro' : 'Gratuito';
        badgeColorClass = tier === 'pro' 
          ? 'bg-[#e6f4ea] text-[#137333] border border-[#ceead6]/20' 
          : 'bg-slate-100 text-slate-600 border border-slate-200';
        break;
      case 'tareas':
        title = 'Tareas y Rutinas';
        desc = 'Gestión operativa';
        icon = <CheckSquare className="text-blue-600 animate-wiggle-once" />;
        badgeText = 'Tareas';
        badgeColorClass = 'bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 border border-blue-100 dark:border-blue-900/30';
        break;
      case 'academia':
        title = 'Academia';
        desc = 'Desarrollo de personal';
        icon = <GraduationCap className="text-violet-500 animate-bounce-twice" />;
        badgeText = 'Cursos';
        badgeColorClass = 'bg-violet-50 dark:bg-violet-950/40 text-violet-600 dark:text-violet-400 border border-violet-100 dark:border-violet-900/30';
        break;
      case 'nomina':
      case 'cuenta':
        title = 'Nómina y Mi Perfil';
        desc = 'Recibos y Asistencia';
        icon = <DollarSign className="text-rose-500 animate-pulse" />;
        badgeText = 'Nómina';
        badgeColorClass = 'bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 border border-rose-100 dark:border-rose-900/30';
        break;
      case 'herramientas':
        title = 'Herramientas';
        desc = 'Bitácoras rápidas';
        icon = <Settings className="text-slate-500 animate-spin-once" />;
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
      <div 
        className={`absolute top-2.5 left-2.5 right-2.5 z-[75] flex items-center justify-between px-3 py-2.5 text-left rounded-xl border transition-all duration-200 select-none ${
          isDark 
            ? 'bg-slate-900/90 backdrop-blur-md border-slate-800 shadow-[0_8px_32px_rgba(0,0,0,0.3)] text-slate-100' 
            : 'bg-white/95 backdrop-blur-md border-slate-100 shadow-[0_8px_32px_rgba(0,0,0,0.05)] text-slate-900'
        }`}
        style={{ transform: 'scale(1.05)', transformOrigin: 'top center' }}
      >
        <div className="flex items-center gap-2 min-w-0">
          <div className="shrink-0 flex items-center justify-center">
            {icon && React.cloneElement(icon, { 
              key: phoneTab,
              className: `${icon.props.className || ''} w-6 h-6` 
            })}
          </div>
          <div className="flex flex-col min-w-0 justify-center text-left">
            <div className="flex items-center gap-1.5 flex-wrap">
              <h3 className="text-[10.5px] font-black tracking-tight leading-none truncate max-w-[120px]">
                {title}
              </h3>
              {badgeText && (
                <span className={`inline-flex items-center px-1.5 py-0.5 rounded-full text-[6.5px] font-black tracking-wider uppercase ${badgeColorClass}`}>
                  {badgeText}
                </span>
              )}
            </div>
            <p className={`text-[7.5px] font-bold mt-0.5 leading-none truncate ${
              isDark ? 'text-slate-400' : 'text-[#525f7f]'
            }`}>
              {desc}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 shrink-0 min-w-0">
          <div className="flex flex-col min-w-0 text-right justify-center leading-tight">
            <span className={`text-[8.5px] font-black uppercase tracking-wider ${
              isDark ? 'text-indigo-400' : 'text-[#8a2be2]'
            }`}>
              Decorarte 365
            </span>
            <span className="text-[8px] font-bold truncate max-w-[90px]">
              {currentUser.name}
            </span>
          </div>
          <img 
            src={currentUser.avatar} 
            alt="Avatar" 
            className={`w-[28px] h-[28px] rounded-full object-cover border-2 shadow-sm ${
              isDark ? 'border-slate-700' : 'border-slate-200'
            }`} 
          />
        </div>
      </div>
    );
  };

  const renderBarraCronologica = () => {
    if (tier === 'free') {
      return (
        <div className="py-2 px-1 text-left w-full select-none shrink-0 border-b border-slate-100 dark:border-slate-800 pb-3 mb-2">
          <div className="flex justify-between items-center w-full font-bold uppercase tracking-wider text-[9px] px-1">
            <div className="flex items-center select-none">
              <span className="text-emerald-600 dark:text-emerald-400 font-black flex items-center gap-1.5">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                <span>🏪 Sucursal Abierta</span>
              </span>
            </div>

            <div className="flex items-center select-none">
              {hasCheckedOut ? (
                <span className="text-emerald-600 dark:text-emerald-500 font-black flex items-center gap-1.5">
                  <span>Turno Finalizado ✓</span>
                </span>
              ) : hasCheckedIn ? (
                <span className="text-emerald-600 dark:text-emerald-500 font-black flex items-center gap-1.5 animate-pulse">
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                  <span>Turno Activo ✓</span>
                </span>
              ) : (
                <span className="text-slate-400 dark:text-slate-500 font-black flex items-center gap-1.5">
                  <span className="w-1.5 h-1.5 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                  <span>Turno Inactivo</span>
                </span>
              )}
            </div>
          </div>
        </div>
      );
    }

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
      <div className="py-1 px-1 text-left w-full select-none shrink-0">
        <div className="flex justify-between items-center w-full font-bold uppercase tracking-wider text-[9px] mb-1.5 px-1">
          <div className="flex items-center select-none">
            <span className="text-emerald-600 dark:text-emerald-400 font-black flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
              <span>🏪 Sucursal Abierta</span>
            </span>
          </div>

          <div className="flex items-center select-none">
            {hasCheckedOut ? (
              <span className="text-emerald-600 dark:text-emerald-400 font-black flex items-center gap-1.5">
                <span>Turno Finalizado ✓</span>
              </span>
            ) : hasCheckedIn ? (
              <span className="text-emerald-600 dark:text-emerald-500 font-black flex items-center gap-1.5 animate-pulse">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span>Turno Activo ✓</span>
              </span>
            ) : (
              <span className="text-slate-400 dark:text-slate-500 font-black flex items-center gap-1.5">
                <span className="w-1.5 h-1.5 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                <span>Turno Inactivo</span>
              </span>
            )}
          </div>
        </div>

        {/* Nodos de Fichaje Interactivos */}
        <div className="flex w-full z-10 relative px-0 mb-0 mt-1">
          {/* Entrada Node */}
          <div 
            onClick={() => setActiveModal('entry')}
            className="w-1/4 flex flex-col items-center relative cursor-pointer hover:scale-105 active:scale-95 transition-all duration-300 transform"
          >
            <span className="text-[8.5px] font-black uppercase tracking-wider mb-0.5 text-indigo-600 dark:text-indigo-400">Entrada</span>
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
                onClick={() => setActiveModal('break')}
                className="w-1/4 flex flex-col items-center relative cursor-pointer hover:scale-105 active:scale-95 transition-all duration-300 transform"
              >
                <span className="text-[8.5px] font-black uppercase tracking-wider mb-0.5 text-purple-600 dark:text-purple-400">Descanso</span>
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
                onClick={() => setActiveModal('meal')}
                className="w-1/4 flex flex-col items-center relative cursor-pointer hover:scale-105 active:scale-95 transition-all duration-300 transform"
              >
                <span className="text-[8.5px] font-black uppercase tracking-wider mb-0.5 text-amber-600 dark:text-amber-400">Comida</span>
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
            onClick={() => setActiveModal('exit')}
            className="w-1/4 flex flex-col items-center relative cursor-pointer hover:scale-105 active:scale-95 transition-all duration-300 transform"
          >
            <span className="text-[8.5px] font-black uppercase tracking-wider mb-0.5 text-emerald-600 dark:text-emerald-400">Salida</span>
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
          <div className="relative w-full h-4 bg-slate-200/50 dark:bg-slate-800/50 rounded-2xl border border-slate-300/20 shadow-inner overflow-hidden">
            {hasCheckedIn && elapsedTotal > 0 && (
              <div 
                className="absolute top-0 left-0 h-full rounded-2xl overflow-hidden flex transition-all duration-750 ease-out"
                style={{ width: `${progressPercent}%` }}
              >
                {segmentsList.map((seg, sIdx) => {
                  let segBg = 'bg-gradient-to-r from-emerald-400 to-teal-500';
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
    <div className={`w-full h-full flex flex-col justify-between overflow-hidden relative ${isDark ? 'bg-slate-950 text-slate-100' : 'bg-slate-50 text-slate-800'}`}>
      
      {/* RENDER UNIFIED MOBILE HEADER */}
      {renderUnifiedMobileHeader()}

      {/* ZONA DE CONTENIDO MÓVIL (Con padding incrementado para dar holgura y no encimarse con el header) */}
      <div className="flex-1 overflow-y-auto px-4 pt-[84px] pb-[92px] flex flex-col justify-between gap-2 scrollbar-none relative z-10">
        
        {phoneTab === 'checador' && (
          <div className="flex-grow flex flex-col h-full justify-between gap-1 py-0.5">
            {/* 1. SECCIÓN SUPERIOR: Barra Cronológica con mayor altura y margin-top para airear */}
            <div className="h-[68px] mt-1.5 flex items-center justify-center shrink-0 w-full relative z-20">
              {hasCheckedIn && (
                <div style={{ transform: 'scale(0.88)', transformOrigin: 'center' }} className="w-full shrink-0 animate-in fade-in slide-in-from-top-4 duration-600 ease-out">
                  {renderBarraCronologica()}
                </div>
              )}
            </div>

            {/* 2. SECCIÓN CENTRAL: Dial Central Principal (Siempre al centro vertical y horizontal) */}
            <div className="flex-grow flex items-center justify-center w-full my-auto shrink-0 z-10">
              <div style={{ transform: 'scale(0.93)', transformOrigin: 'center' }} className="flex flex-col items-center justify-center shrink-0">
                <DialPrincipal
                  isMobile={true}
                  isOpeningPremium={true}
                  storeStatus="open"
                  openingStatus={null}
                  currentUser={currentUser}
                  isWithinPerimeter={true}
                  globalUsers={[]}
                  clockState={clockState}
                  formattedTime={getFormattedTimeText(currentSimTime)}
                  btnProps={{
                    disabled: clockState === 'finished',
                    text: btnProps.text,
                    subtext: btnProps.subtext,
                    iconKey: btnProps.iconKey
                  }}
                  lateUsers={{}}
                  currentDay={currentDay}
                  currentSimTime={currentSimTime}
                  shiftConfigs={shiftConfigs}
                  parseTimeToMins={parseTimeToMins}
                  handleAction={handleAction}
                />
              </div>
            </div>

            {/* 3. SECCIÓN INFERIOR: Alertas y Mensajes */}
            <div className="min-h-[175px] flex flex-col justify-end shrink-0 w-full pb-2">
              {hasCheckedIn && (
                <div style={{ transform: 'scale(0.9)', transformOrigin: 'bottom center' }} className="space-y-1.5 w-full animate-in fade-in slide-in-from-bottom-5 duration-700 ease-out">
                  {/* Alerta de Entrada Registrada */}
                  {hasCheckedIn && (
                    <div className={`p-2.5 border rounded-2xl flex items-center gap-2.5 text-left transition-all animate-in slide-in-from-bottom-2 duration-300 ${
                      isDark ? 'bg-emerald-950/20 border-emerald-900/40 text-emerald-300' : 'bg-emerald-50/60 border-emerald-100 text-emerald-900'
                    }`}>
                      <div className="w-8 h-8 rounded-xl bg-emerald-500/15 flex items-center justify-center text-emerald-600 dark:text-emerald-400 shrink-0 text-sm animate-pulse">
                        ✅
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="text-[8px] font-black uppercase tracking-wider text-emerald-600 dark:text-emerald-400 leading-none">Fichaje Registrado</p>
                        <p className="text-[10px] font-extrabold mt-0.5 dark:text-slate-200">
                          Entrada: {formatMinsToTimeClean(checkInTimes[99] || 545)} (Retardo de 5 min)
                        </p>
                      </div>
                    </div>
                  )}

                  {/* Alerta de Descanso Registrado */}
                  {breaksTaken[99] !== undefined && (
                    <div className={`p-2.5 border rounded-2xl flex items-center gap-2.5 text-left transition-all animate-in slide-in-from-bottom-2 duration-300 ${
                      isDark ? 'bg-purple-950/20 border-purple-900/40 text-purple-300' : 'bg-purple-50/60 border-purple-100 text-purple-900'
                    }`}>
                      <div className="w-8 h-8 rounded-xl bg-purple-500/15 flex items-center justify-center text-purple-600 dark:text-purple-400 shrink-0 text-sm animate-pulse">
                        ☕
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="text-[8px] font-black uppercase tracking-wider text-purple-600 dark:text-purple-400 leading-none">Descanso Tomado</p>
                        <p className="text-[10px] font-extrabold mt-0.5 dark:text-slate-200">
                          Salida: {formatMinsToTimeClean(breakStartTimes[99] || 720)} | Regreso: {formatMinsToTimeClean(breakEndTimes[99] || 735)}
                        </p>
                      </div>
                    </div>
                  )}

                  {/* Alerta de Comida Registrada */}
                  {mealEndTimes[99] !== undefined && (
                    <div className={`p-2.5 border rounded-2xl flex items-center gap-2.5 text-left transition-all animate-in slide-in-from-bottom-2 duration-300 ${
                      isDark ? 'bg-amber-950/20 border-amber-900/40 text-amber-300' : 'bg-amber-50/60 border-amber-100 text-amber-900'
                    }`}>
                      <div className="w-8 h-8 rounded-xl bg-amber-500/15 flex items-center justify-center text-amber-600 dark:text-amber-400 shrink-0 text-sm animate-pulse">
                        🍱
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="text-[8px] font-black uppercase tracking-wider text-amber-600 dark:text-amber-400 leading-none">Comida Completada</p>
                        <p className="text-[10px] font-extrabold mt-0.5 dark:text-slate-200">
                          Salida: {formatMinsToTimeClean(mealStartTimes[99] || 840)} | Regreso: {formatMinsToTimeClean(mealEndTimes[99] || 885)}
                        </p>
                      </div>
                    </div>
                  )}

                  {/* Alerta de Salida Registrada */}
                  {hasCheckedOut && (
                    <div className={`p-2.5 border rounded-2xl flex items-center gap-2.5 text-left transition-all animate-in slide-in-from-bottom-2 duration-300 ${
                      isDark ? 'bg-rose-950/20 border-rose-900/40 text-rose-300' : 'bg-rose-50/60 border-rose-100 text-rose-900'
                    }`}>
                      <div className="w-8 h-8 rounded-xl bg-rose-500/15 flex items-center justify-center text-rose-600 dark:text-rose-500 shrink-0 text-sm animate-pulse">
                        🚪
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="text-[8px] font-black uppercase tracking-wider text-rose-600 dark:text-rose-500 leading-none">Jornada Finalizada</p>
                        <p className="text-[10px] font-extrabold mt-0.5 dark:text-slate-200">
                          Salida registrada a las {formatMinsToTimeClean(checkOutTimes[99] || 1080)}
                        </p>
                      </div>
                    </div>
                  )}

                  <div className={`p-2.5 border rounded-2xl flex items-center gap-2.5 text-left transition-all ${
                    isDark ? 'bg-indigo-950/20 border-indigo-900/40 text-indigo-300' : 'bg-indigo-50/60 border-indigo-100 text-indigo-900'
                  }`}>
                    <div className="w-8 h-8 rounded-xl bg-indigo-500/15 flex items-center justify-center text-indigo-600 dark:text-indigo-400 shrink-0 text-sm">
                      📋
                    </div>
                    <div className="min-w-0">
                      <p className="text-[8px] font-black uppercase tracking-wider text-indigo-600 dark:text-indigo-400 leading-none">Tablero Operativo</p>
                      <p className="text-[10px] font-bold mt-0.5 dark:text-slate-300 truncate">¡1 tarea pendiente por completar hoy!</p>
                    </div>
                  </div>

                  <div className={`p-2.5 border rounded-2xl flex items-center gap-2.5 text-left transition-all ${
                    isDark ? 'bg-violet-950/20 border-violet-900/40 text-violet-300' : 'bg-violet-50/60 border-violet-100 text-violet-900'
                  }`}>
                    <div className="w-8 h-8 rounded-xl bg-violet-500/15 flex items-center justify-center text-violet-600 dark:text-violet-400 shrink-0 text-sm animate-pulse">
                      🎓
                    </div>
                    <div className="min-w-0">
                      <p className="text-[8px] font-black uppercase tracking-wider text-violet-600 dark:text-violet-400 leading-none">Capacitación Activa</p>
                      <p className="text-[10.5px] font-black mt-0.5 dark:text-slate-200 leading-tight">
                        ¡Capacítate en la academia para subir de puesto y ganar más!
                      </p>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {phoneTab === 'tareas' && (
          <div className="flex-1 flex flex-col justify-between py-2 text-left overflow-y-auto scrollbar-none">
            {tier === 'free' ? (
              <div className="flex-grow flex flex-col items-center justify-center p-6 text-center space-y-4 animate-in zoom-in-95 duration-200 my-auto">
                <div className="w-12 h-12 bg-rose-50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30 rounded-2xl flex items-center justify-center text-rose-500 shadow-sm shrink-0">
                  <Lock size={22} className="text-rose-500" />
                </div>
                <div className="space-y-1">
                  <h5 className="text-[9px] font-black text-rose-800 dark:text-rose-500 uppercase tracking-widest leading-none">Exclusivo Plan Pro</h5>
                  <h4 className="text-[11px] font-black text-slate-800 dark:text-slate-200 leading-tight">Módulo Bloqueado</h4>
                  <p className="text-[8.5px] text-slate-500 font-semibold leading-relaxed max-w-[170px] mx-auto">
                    La gestión de Tareas requiere la Versión Pro del Reloj Checador.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    if (setTier) setTier('pro');
                  }}
                  className="bg-slate-900 hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700 text-white font-black py-2 px-3 rounded-xl text-[8.5px] uppercase tracking-wider transition-all shadow-md active:scale-95 border-none outline-none cursor-pointer mt-1"
                >
                  Probar Versión Pro
                </button>
              </div>
            ) : (
              <div className="flex-grow flex flex-col min-h-0">
                <TaskRunner currentUser={currentUser} onBack={() => setPhoneTab('checador')} hideHeader={true} />
              </div>
            )}
          </div>
        )}

        {phoneTab === 'academia' && (
          <div className="flex-1 flex flex-col justify-between py-2 text-left">
            {tier === 'free' ? (
              <div className="flex-grow flex flex-col items-center justify-center p-6 text-center space-y-4 animate-in zoom-in-95 duration-200 my-auto">
                <div className="w-12 h-12 bg-rose-50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30 rounded-2xl flex items-center justify-center text-rose-500 shadow-sm shrink-0">
                  <Lock size={22} className="text-rose-500" />
                </div>
                <div className="space-y-1">
                  <h5 className="text-[9px] font-black text-rose-800 dark:text-rose-500 uppercase tracking-widest leading-none">Exclusivo Plan Pro</h5>
                  <h4 className="text-[11px] font-black text-slate-800 dark:text-slate-200 leading-tight">Módulo Bloqueado</h4>
                  <p className="text-[8.5px] text-slate-500 font-semibold leading-relaxed max-w-[170px] mx-auto">
                    La gestión de Academia requiere la Versión Pro del Reloj Checador.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    if (setTier) setTier('pro');
                  }}
                  className="bg-slate-900 hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700 text-white font-black py-2 px-3 rounded-xl text-[8.5px] uppercase tracking-wider transition-all shadow-md active:scale-95 border-none outline-none cursor-pointer mt-1"
                >
                  Probar Versión Pro
                </button>
              </div>
            ) : (
              <div className="p-1 text-left animate-in fade-in duration-200 space-y-3 flex-grow overflow-y-auto scrollbar-none">
                <div className="bg-gradient-to-r from-violet-600 to-indigo-600 text-white p-3 rounded-2xl shadow-sm space-y-1">
                  <p className="text-[8px] font-bold text-violet-200 uppercase tracking-widest">Capacitación Operativa</p>
                  <h4 className="text-[11px] font-black">Cursos Asignados para Tu Puesto</h4>
                  <p className="text-[8px] text-violet-100 font-medium">¡Completa lecciones para ganar insignias y aumentos!</p>
                </div>

                <h5 className="text-[9.5px] font-black uppercase text-slate-800 dark:text-slate-200 tracking-wider">Plan de Aprendizaje</h5>
                
                {/* Curso 1 */}
                <div className="bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800 p-2.5 rounded-xl space-y-2">
                  <div className="flex justify-between items-center">
                    <span className="text-[9px] font-black text-slate-800 dark:text-slate-200 uppercase tracking-wide truncate max-w-[140px]">Inducción Básica 360</span>
                    <span className="text-[8px] font-bold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 px-1.5 py-0.5 rounded-md border border-emerald-200/40">75%</span>
                  </div>
                  <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden">
                    <div className="h-full bg-emerald-500 rounded-full" style={{ width: '75%' }}></div>
                  </div>
                  <div className="flex justify-between items-center text-[8px] text-slate-500 pt-0.5">
                    <span>3 de 4 lecciones completadas</span>
                    <button className="text-violet-600 dark:text-violet-400 font-bold hover:underline bg-transparent border-none p-0 cursor-pointer">Continuar →</button>
                  </div>
                </div>

                {/* Curso 2 */}
                <div className="bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800 p-2.5 rounded-xl space-y-2">
                  <div className="flex justify-between items-center">
                    <span className="text-[9px] font-black text-slate-800 dark:text-slate-200 uppercase tracking-wide truncate max-w-[140px]">Atención & Caja Registradora</span>
                    <span className="text-[8px] font-bold text-blue-600 bg-blue-50 dark:bg-blue-950/40 px-1.5 py-0.5 rounded-md border border-blue-200/40">20%</span>
                  </div>
                  <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden">
                    <div className="h-full bg-blue-500 rounded-full" style={{ width: '20%' }}></div>
                  </div>
                  <div className="flex justify-between items-center text-[8px] text-slate-500 pt-0.5">
                    <span>1 de 5 lecciones completadas</span>
                    <button className="text-blue-600 dark:text-blue-400 font-bold hover:underline bg-transparent border-none p-0 cursor-pointer">Iniciar →</button>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}

        {(phoneTab === 'nomina' || phoneTab === 'cuenta') && (
          <div className="flex-1 flex flex-col justify-between py-2 text-left">
            {tier === 'free' ? (
              <div className="flex-grow flex flex-col items-center justify-center p-6 text-center space-y-4 animate-in zoom-in-95 duration-200 my-auto">
                <div className="w-12 h-12 bg-rose-50 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30 rounded-2xl flex items-center justify-center text-rose-500 shadow-sm shrink-0">
                  <Lock size={22} className="text-rose-500" />
                </div>
                <div className="space-y-1">
                  <h5 className="text-[9px] font-black text-rose-800 dark:text-rose-500 uppercase tracking-widest leading-none">Exclusivo Plan Pro</h5>
                  <h4 className="text-[11px] font-black text-slate-800 dark:text-slate-200 leading-tight">Módulo Bloqueado</h4>
                  <p className="text-[8.5px] text-slate-500 font-semibold leading-relaxed max-w-[170px] mx-auto">
                    La gestión de Nómina requiere la Versión Pro del Reloj Checador.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    if (setTier) setTier('pro');
                  }}
                  className="bg-slate-900 hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700 text-white font-black py-2 px-3 rounded-xl text-[8.5px] uppercase tracking-wider transition-all shadow-md active:scale-95 border-none outline-none cursor-pointer mt-1"
                >
                  Probar Versión Pro
                </button>
              </div>
            ) : (
              <div className="p-1 text-left animate-in fade-in duration-200 space-y-3 flex-grow overflow-y-auto scrollbar-none">
                <div className="bg-gradient-to-r from-emerald-600 to-teal-700 text-white p-3 rounded-2xl shadow-sm space-y-2">
                  <div className="flex justify-between items-start">
                    <div>
                      <p className="text-[8px] font-bold text-emerald-200 uppercase tracking-widest">Nómina Quincenal Calculada</p>
                      <h4 className="text-sm font-black mt-0.5">$4,800.00 MXN</h4>
                    </div>
                    <span className="px-2 py-0.5 bg-emerald-500/30 text-white text-[8px] font-bold rounded-full border border-emerald-300/30">
                      ✓ Pago Estimado
                    </span>
                  </div>
                  <div className="flex items-center justify-between text-[8.5px] pt-1 border-t border-emerald-500/40 text-emerald-100 font-medium">
                    <span>Horas laboradas: <strong>44 hrs</strong></span>
                    <span>Puntualidad: <strong>98%</strong></span>
                  </div>
                </div>

                <h5 className="text-[9.5px] font-black uppercase text-slate-800 dark:text-slate-200 tracking-wider pt-1">Desglose de Pago</h5>
                
                <div className="bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800 p-2.5 rounded-xl space-y-1.5 text-[9px]">
                  <div className="flex justify-between items-center text-slate-700 dark:text-slate-300">
                    <span>Sueldo Base (15 días)</span>
                    <span className="font-bold text-slate-900 dark:text-white">$4,500.00</span>
                  </div>
                  <div className="flex justify-between items-center text-emerald-600 dark:text-emerald-400">
                    <span>Bono Puntualidad & Tareas</span>
                    <span className="font-bold">+$350.00</span>
                  </div>
                  <div className="flex justify-between items-center text-rose-500">
                    <span>Retardo (1 incidencia 5m)</span>
                    <span className="font-bold">-$50.00</span>
                  </div>
                  <div className="border-t border-slate-200 dark:border-slate-800 pt-1.5 flex justify-between items-center font-black text-slate-900 dark:text-white text-[9.5px]">
                    <span>Total Neto</span>
                    <span className="text-emerald-600 dark:text-emerald-400">$4,800.00</span>
                  </div>
                </div>

                <button 
                  onClick={() => alert("Simulación: Descargando Recibo Digital PDF de Francisco Vega")}
                  className="w-full py-2 bg-slate-900 dark:bg-slate-800 hover:bg-slate-800 text-white rounded-xl text-[9px] font-black uppercase tracking-wider flex items-center justify-center gap-1.5 transition-all shadow-md cursor-pointer border-none"
                >
                  <FileText size={12} />
                  Descargar Recibo Digital (PDF)
                </button>
              </div>
            )}
          </div>
        )}

      </div>

      {/* RENDER MOBILE BOTTOM NAVIGATION + botón flotante único (2026-07-23, mismo tratamiento
          que RelojVisual.tsx): el botón vive DENTRO de este mismo contenedor con scale(0.85) para
          que se escale junto con la barra y la muesca cóncava de MobileBottomNav.tsx quede
          alineada con él sin cálculos aparte. Antes vivía afuera, a la izquierda, para no
          empalmarse con Herramientas — ya no hace falta, Herramientas se quitó de la barra. */}
      <div style={{ transform: 'scale(0.85)', transformOrigin: 'bottom center' }} className="absolute bottom-1 inset-x-0 z-30 shrink-0 flex items-center justify-center px-2">
        <div className="w-full max-w-[420px] relative">
          <MobileBottomNav
            phoneTab={phoneTab}
            setPhoneTab={(tab) => {
              if (tier === 'free' && tab !== 'checador') {
                setShowBlockProModal(tab);
              } else {
                setPhoneTab(tab);
              }
            }}
            setInnerTool={setInnerTool}
            isDark={isDark}
            clockState={clockState}
            showCustomAlert={(msg) => console.log('Landing Alert:', msg)}
          />
          <div className="absolute right-0 bottom-0 z-40">
            <button
              type="button"
              onClick={() => setIsFabSheetOpen(prev => !prev)}
              className="w-16 h-16 bg-gradient-to-tr from-violet-600 via-[#8a2be2] to-purple-700 hover:from-violet-500 hover:to-purple-600 text-white rounded-full shadow-[0_0_30px_rgba(138,43,226,0.65)] flex items-center justify-center transition-all hover:scale-105 active:scale-95 border-2 border-white/60 cursor-pointer outline-none relative shrink-0"
            >
              <span className="absolute -inset-1 rounded-full bg-purple-600/40 blur-md animate-pulse pointer-events-none"></span>
              {isFabSheetOpen ? <X size={26} className="relative z-10" /> : <Sparkles size={28} className="text-white relative z-10 animate-pulse" />}
            </button>
          </div>
        </div>
      </div>

      {/* SHEET OPERATIVO FLOTANTE (MENÚ DE OPERACIONES FAB) — traslúcido, igual que en RelojVisual.tsx */}
      {isFabSheetOpen && (
        <div className="absolute inset-0 z-50 flex items-end justify-center">
          <div
            onClick={() => setIsFabSheetOpen(false)}
            className="absolute inset-0 bg-black/40 backdrop-blur-xs transition-opacity"
          ></div>

          <div className={`relative w-full rounded-t-3xl border-t shadow-2xl z-10 flex flex-col pb-6 max-h-[80%] animate-slide-up backdrop-blur-md ${
            isDark ? 'bg-slate-900/70 border-slate-800 text-white' : 'bg-white/70 border-slate-100 text-slate-800'
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
                    isDark ? 'bg-slate-950/40 text-white' : 'bg-slate-50 text-slate-800'
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
                    isDark ? 'bg-slate-950/40 text-white' : 'bg-slate-50 text-slate-800'
                  }`}
                >
                  <div className="w-8 h-8 rounded-xl bg-gradient-to-tr from-amber-500 to-orange-500 text-white flex items-center justify-center">
                    <Play size={16} />
                  </div>
                  <span className="font-bold text-[10px]">Ver Tareas</span>
                </button>

                <button
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('nomina');
                  }}
                  className={`p-3 rounded-2xl border flex flex-col items-center justify-center gap-1.5 text-center cursor-pointer border-none ${
                    isDark ? 'bg-slate-950/40 text-white' : 'bg-slate-50 text-slate-800'
                  }`}
                >
                  <div className="w-8 h-8 rounded-xl bg-gradient-to-tr from-emerald-500 to-teal-600 text-white flex items-center justify-center">
                    <DollarSign size={16} />
                  </div>
                  <span className="font-bold text-[10px]">Recibo Nómina</span>
                </button>

                <button
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('academia');
                  }}
                  className={`p-3 rounded-2xl border flex flex-col items-center justify-center gap-1.5 text-center cursor-pointer border-none ${
                    isDark ? 'bg-slate-950/40 text-white' : 'bg-slate-50 text-slate-800'
                  }`}
                >
                  <div className="w-8 h-8 rounded-xl bg-gradient-to-tr from-indigo-500 to-purple-600 text-white flex items-center justify-center">
                    <GraduationCap size={16} />
                  </div>
                  <span className="font-bold text-[10px]">Capacitación</span>
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
            isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-100 text-slate-800'
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
                  isDark ? 'bg-slate-800 border-slate-700 text-white' : 'bg-slate-50 border-slate-200'
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



      {/* MODALES DE DETALLE DE FICHAJE DE LA BARRA CRONOLÓGICA */}
      {activeModal && (
        <div className="absolute inset-0 z-[100] flex items-center justify-center p-4">
          <div onClick={() => setActiveModal(null)} className="absolute inset-0 bg-black/60 backdrop-blur-xs"></div>
          
          <div 
            className={`relative w-full max-w-[280px] rounded-3xl p-5 border text-center shadow-2xl animate-scale-up ${
              isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-200 text-slate-800'
            }`}
            style={{ transform: 'scale(0.85)', transformOrigin: 'center' }}
          >
            <button 
              onClick={() => setActiveModal(null)}
              className="absolute top-3 right-3 text-slate-400 hover:text-slate-600 cursor-pointer border-none bg-transparent font-bold text-sm"
            >
              ✕
            </button>

            {activeModal === 'entry' && (
              <div className="space-y-4 text-left">
                <div className="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                  <div className="flex items-center gap-2">
                    <LogIn className="w-5 h-5 text-indigo-600 shrink-0" />
                    <h3 className="font-black text-slate-800 dark:text-slate-200 text-sm">
                      Registro de Entrada
                    </h3>
                  </div>
                  <span className={`px-2 py-0.5 rounded-full text-[9px] font-black uppercase border ${
                    hasCheckedIn ? 'bg-rose-50 border-rose-100 text-rose-600 dark:bg-rose-950/20 dark:border-rose-900/30 dark:text-rose-400' : 'bg-slate-100 border-slate-200 text-slate-500'
                  }`}>
                    {hasCheckedIn ? '⚠️ Retardo' : '📅 Pendiente'}
                  </span>
                </div>

                <div className={`p-4 rounded-2xl border leading-relaxed text-xs font-semibold ${
                  hasCheckedIn ? 'bg-rose-50/40 border-rose-100/60 text-rose-900 dark:bg-slate-900/40 dark:border-slate-800' : 'bg-slate-50 border-slate-100 text-slate-700 dark:bg-slate-900/40 dark:border-slate-800'
                }`}>
                  {hasCheckedIn ? (
                    <>Entrada registrada a las <strong className="text-rose-600 dark:text-rose-500 font-bold">09:05 AM</strong> (Retardo de 5 minutos).</>
                  ) : (
                    <>Entrada pendiente de registrar. Tu horario de ingreso es a las <strong className="text-slate-800 dark:text-white font-bold">09:00 AM</strong>.</>
                  )}
                </div>
              </div>
            )}

            {activeModal === 'break' && (() => {
              const isDone = breaksTaken[99] !== undefined;
              const isActive = clockState === 'short_break';
              return (
                <div className="space-y-4 text-left">
                  <div className="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                    <div className="flex items-center gap-2">
                      <Armchair className="w-5 h-5 text-purple-600 shrink-0" />
                      <h3 className="font-black text-slate-800 dark:text-slate-200 text-sm">
                        Registro de Descanso
                      </h3>
                    </div>
                    <span className={`px-2 py-0.5 rounded-full text-[9px] font-black uppercase border ${
                      isActive ? 'bg-purple-50 border-purple-100 text-purple-600' :
                      isDone ? 'bg-emerald-50 border-emerald-100 text-emerald-600 dark:bg-emerald-950/20 dark:border-emerald-900/30' :
                      'bg-slate-100 border-slate-200 text-slate-500'
                    }`}>
                      {isActive ? '⏳ En curso' : isDone ? '✓ Cumplido' : '📅 Pendiente'}
                    </span>
                  </div>

                  <div className={`p-4 rounded-2xl border leading-relaxed text-xs font-semibold ${
                    isActive ? 'bg-purple-50/40 border-purple-100/60 text-purple-900 dark:bg-slate-900/40 dark:border-slate-800' :
                    isDone ? 'bg-emerald-50/40 border-emerald-100/60 text-emerald-900 dark:bg-slate-900/40' :
                    'bg-slate-50 border-slate-100 text-slate-700 dark:bg-slate-900/40 dark:border-slate-800'
                  }`}>
                    {isActive ? (
                      <>Descanso iniciado a las <strong className="text-purple-600 font-bold">12:00 PM</strong> (Tolerancia: 15 min).</>
                    ) : isDone ? (
                      <>Descanso completado: <strong className="text-emerald-600 dark:text-emerald-500 font-bold">12:00 PM - 12:15 PM</strong> (15 minutos).</>
                    ) : (
                      <>Descanso de Ley Silla pendiente (Tolerancia regular: 15 minutos).</>
                    )}
                  </div>
                </div>
              );
            })()}

            {activeModal === 'meal' && (() => {
              const isDone = mealEndTimes[99] !== undefined;
              const isActive = clockState === 'meal';
              return (
                <div className="space-y-4 text-left">
                  <div className="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                    <div className="flex items-center gap-2">
                      <Utensils className="w-5 h-5 text-amber-600 shrink-0" />
                      <h3 className="font-black text-slate-800 dark:text-slate-200 text-sm">
                        Horario de Almuerzo
                      </h3>
                    </div>
                    <span className={`px-2 py-0.5 rounded-full text-[9px] font-black uppercase border ${
                      isActive ? 'bg-amber-50 border-amber-100 text-amber-600' :
                      isDone ? 'bg-emerald-50 border-emerald-100 text-emerald-600 dark:bg-emerald-950/20 dark:border-emerald-900/30' :
                      'bg-slate-100 border-slate-200 text-slate-500'
                    }`}>
                      {isActive ? '⏳ En curso' : isDone ? '✓ Cumplido' : '📅 Pendiente'}
                    </span>
                  </div>

                  <div className={`p-4 rounded-2xl border leading-relaxed text-xs font-semibold ${
                    isActive ? 'bg-amber-50/40 border-amber-100/60 text-amber-900 dark:bg-slate-900/40' :
                    isDone ? 'bg-emerald-50/40 border-emerald-100/60 text-emerald-900 dark:bg-slate-900/40' :
                    'bg-slate-50 border-slate-100 text-slate-700 dark:bg-slate-900/40 dark:border-slate-800'
                  }`}>
                    {isActive ? (
                      <>Almuerzo iniciado a las <strong className="text-amber-600 font-bold">02:00 PM</strong> (Tolerancia: 45 min).</>
                    ) : isDone ? (
                      <>Almuerzo completado: <strong className="text-emerald-600 dark:text-emerald-500 font-bold">02:00 PM - 02:45 PM</strong> (45 minutos).</>
                    ) : (
                      <>Almuerzo pendiente de tomar (Tolerancia regular: 45 minutos).</>
                    )}
                  </div>
                </div>
              );
            })()}

            {activeModal === 'exit' && (
              <div className="space-y-4 text-left">
                <div className="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                  <div className="flex items-center gap-2">
                    <LogOut className="w-5 h-5 text-teal-600 shrink-0" />
                    <h3 className="font-black text-slate-800 dark:text-slate-200 text-sm">
                      Resumen de Turno
                    </h3>
                  </div>
                  <span className={`px-2 py-0.5 rounded-full text-[9px] font-black uppercase border ${
                    hasCheckedOut ? 'bg-emerald-50 border-emerald-100 text-emerald-600 dark:bg-emerald-950/20 dark:border-emerald-900/30' : 'bg-slate-100 border-slate-200 text-slate-500'
                  }`}>
                    {hasCheckedOut ? '✓ Cumplido' : '📅 Pendiente'}
                  </span>
                </div>

                <div className={`p-4 rounded-2xl border leading-relaxed text-xs font-semibold ${
                  hasCheckedOut ? 'bg-emerald-50/40 border-emerald-100/60 text-emerald-900 dark:bg-slate-900/40' : 'bg-slate-50 border-slate-100 text-slate-700 dark:bg-slate-900/40 dark:border-slate-800'
                }`}>
                  {hasCheckedOut ? (
                    <>Jornada finalizada: <strong className="text-emerald-600 dark:text-emerald-500 font-bold">06:00 PM</strong> (8h 55m laborados sin desvíos).</>
                  ) : (
                    <>Salida pendiente de registrar. Tu horario regular de salida es a las <strong className="text-slate-800 dark:text-white font-bold">06:00 PM</strong>.</>
                  )}
                </div>
              </div>
            )}

            <button 
              onClick={() => setActiveModal(null)}
              className="mt-4 w-full py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md cursor-pointer border-none"
            >
              Entendido
            </button>
          </div>
        </div>
      )}

      {/* GANCHO COMERCIAL DE MARKETING (PROMO AL FINAL DE LA SIMULACIÓN) */}
      {showPromoGancho && (
        <div className="absolute inset-0 z-[120] flex items-center justify-center p-4">
          <div onClick={() => setShowPromoGancho(false)} className="absolute inset-0 bg-slate-950/80 backdrop-blur-xs"></div>
          
          <div 
            className="relative w-full max-w-[280px] rounded-3xl p-5 border text-center shadow-2xl animate-scale-up bg-slate-900 border-violet-800 text-white shadow-violet-500/10"
            style={{ transform: 'scale(0.85)', transformOrigin: 'center' }}
          >
            <button 
              onClick={() => setShowPromoGancho(false)}
              className="absolute top-3 right-3 text-slate-400 hover:text-slate-200 cursor-pointer border-none bg-transparent font-bold text-sm"
            >
              ✕
            </button>

            <div className="flex flex-col items-center gap-3">
              {/* Icono de Escudo de Seguridad / Validación */}
              <div className="w-12 h-12 rounded-full bg-violet-950 border border-violet-800 flex items-center justify-center text-violet-400 relative">
                <ShieldCheck size={26} className="text-violet-400 animate-[pulse_2s_infinite]" />
                <span className="absolute -top-0.5 -right-0.5 w-2 h-2 bg-emerald-500 rounded-full"></span>
              </div>

              <div>
                <h3 className="text-xs font-black uppercase tracking-wider text-violet-300">
                  ¡Fichaje Seguro Activo!
                </h3>
                <p className="text-[9.5px] font-bold text-slate-400 mt-1 leading-normal">
                  Has probado la validación del Reloj Checador PRO de Talent 360.
                </p>
              </div>

              <div className="w-full text-left space-y-2 border-y border-slate-800/80 py-3 my-1">
                <div className="flex items-start gap-2">
                  <span className="text-[10px] text-emerald-400 shrink-0">✓</span>
                  <span className="text-[8.5px] font-semibold text-slate-300 leading-normal">
                    <strong>Reconocimiento Facial (Selfie):</strong> Previene que un compañero cheque por otro.
                  </span>
                </div>
                <div className="flex items-start gap-2">
                  <span className="text-[10px] text-emerald-400 shrink-0">✓</span>
                  <span className="text-[8.5px] font-semibold text-slate-300 leading-normal">
                    <strong>Geolocalización GPS:</strong> Bloquea registros fuera del perímetro permitido.
                  </span>
                </div>
                <div className="flex items-start gap-2">
                  <span className="text-[10px] text-emerald-400 shrink-0">✓</span>
                  <span className="text-[8.5px] font-semibold text-slate-300 leading-normal">
                    <strong>Reportes Automatizados:</strong> Calcula retardos y horas extras al instante.
                  </span>
                </div>
              </div>

              <div className="w-full space-y-2">
                <button
                  onClick={() => {
                    setShowPromoGancho(false);
                    if (onActionClick) onActionClick();
                  }}
                  className="w-full py-2 bg-gradient-to-tr from-violet-600 to-indigo-600 hover:from-violet-700 hover:to-indigo-700 text-white text-[10px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-violet-500/10 cursor-pointer border-none"
                >
                  Probar 14 días Gratis
                </button>
                
                <button
                  onClick={handleResetSim}
                  className="w-full py-1.5 bg-slate-800/60 hover:bg-slate-800 text-slate-400 text-[9px] font-black uppercase tracking-wider rounded-xl transition-all cursor-pointer border-none"
                >
                  Reiniciar Simulación
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* MODAL DE BLOQUEO DE MÓDULO EXCLUSIVO PRO */}
      {showBlockProModal && (
        <div className="absolute inset-0 z-[120] flex items-center justify-center p-4">
          <div onClick={() => setShowBlockProModal(null)} className="absolute inset-0 bg-black/60 backdrop-blur-xs"></div>
          
          <div 
            className="relative w-full max-w-[280px] rounded-3xl p-5 border text-center shadow-2xl animate-scale-up bg-slate-900 border-violet-800 text-white shadow-violet-500/10"
            style={{ transform: 'scale(0.85)', transformOrigin: 'center' }}
          >
            <button 
              onClick={() => setShowBlockProModal(null)}
              className="absolute top-3 right-3 text-slate-400 hover:text-slate-200 cursor-pointer border-none bg-transparent font-bold text-sm"
            >
              ✕
            </button>

            <div className="flex flex-col items-center gap-3">
              <div className="w-12 h-12 rounded-full bg-violet-950 border border-violet-800 flex items-center justify-center text-violet-400">
                <Lock size={22} className="text-violet-400" />
              </div>

              <div>
                <h3 className="text-xs font-black uppercase tracking-wider text-violet-300">
                  Módulo Exclusivo PRO
                </h3>
                <p className="text-[9.5px] font-bold text-slate-400 mt-1 leading-normal">
                  El módulo de {showBlockProModal === 'tareas' ? 'Tareas & Rutinas Operativas' : 'Academia de Capacitación'} solo está disponible en la versión **PRO**.
                </p>
              </div>

              <div className="w-full space-y-2 mt-2">
                <button
                  onClick={() => {
                    setShowBlockProModal(null);
                    if (onActionClick) onActionClick();
                  }}
                  className="w-full py-2 bg-gradient-to-tr from-violet-600 to-indigo-600 hover:from-violet-700 hover:to-indigo-700 text-white text-[10px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md cursor-pointer border-none"
                >
                  Mejorar a PRO (14 días gratis)
                </button>
                
                <button
                  onClick={() => setShowBlockProModal(null)}
                  className="w-full py-1.5 bg-slate-800/60 hover:bg-slate-800 text-slate-400 text-[9px] font-black uppercase tracking-wider rounded-xl transition-all cursor-pointer border-none"
                >
                  Seguir en versión Básica
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
