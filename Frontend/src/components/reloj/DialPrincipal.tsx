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
  Phone,
  ShieldAlert,
  Ban,
  X,
  Camera,
  MessageSquare,
  AlertOctagon
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
  workedElapsedLabel?: string | null;
  btnProps: {
    disabled: boolean;
    isIncidenceReport?: boolean;
    isOpeningManager?: boolean;
    isMealReservationAlert?: boolean;
    text?: string;
    subtext?: string;
    // Clave semántica explícita por estado (docs/funcionamiento_del_dial.md, Matriz de 23 Estados).
    // Reemplaza la comparación frágil por texto literal que antes decidía ícono/color — un cambio de
    // copy en getButtonProps() ya no puede romper silenciosamente el ícono mostrado.
    iconKey?: string;
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
  onCallManagerClick?: () => void;
  onCallSuplenteClick?: () => void;
  onSendDoorNoticeClick?: () => void;
  onPanicClick?: () => void;
  onDeclareContingencyClick?: () => void;
  hasActiveContingency?: boolean;
  isKeyholder?: boolean;
  /** ¿El usuario es el responsable de abrir HOY? No se le ofrece llamarse a sí mismo. */
  isResponsibleToday?: boolean;
  // Auditoría reloj checador (2026-07-22), Hallazgo 1: el botón principal queda `disabled` (HTML)
  // cuando btnProps.iconKey === 'blocked', así que un onClick en el propio dial nunca dispara.
  // Este callback se renderiza aparte, como CTA secundario, para llevar al curso de puntualidad real.
  onGoToRequiredCourseClick?: () => void;
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
  workedElapsedLabel,
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
  onOvertimeClick,
  onCallManagerClick,
  onCallSuplenteClick,
  onSendDoorNoticeClick,
  onPanicClick,
  onDeclareContingencyClick,
  hasActiveContingency = false,
  isKeyholder,
  isResponsibleToday = false,
  onGoToRequiredCourseClick
}: DialPrincipalProps) {
  const size = isMobile ? 76 : 88;
  const [showGpsModal, setShowGpsModal] = useState(false);

  const isUserKeyholder = Boolean(
    isKeyholder ||
    (currentUser?.portadorLlaves && currentUser.portadorLlaves.toLowerCase() !== 'ninguno') ||
    ['titular', 'suplente'].includes(currentUser?.portadorLlaves?.toLowerCase())
  );

  const isGpsError = gpsStatus === 'error' && !isGpsValidationBypassed;
  const isGpsSeeking = gpsStatus === 'seeking';

  // Effectively disable the button visually and behaviorally (without block events)
  const isEffectivelyDisabled = !isGpsError && (btnProps.disabled || (clockState === 'waiting_room' && storeStatus === 'closed'));

  // Mapa único iconKey -> clases de color/glow/ícono. Fuente de verdad para las 3 funciones de abajo,
  // así un estado nuevo en getButtonProps() solo necesita un iconKey nuevo aquí, en un solo lugar,
  // en vez de tener que actualizar 3 switches de texto por separado (la causa original de que varios
  // estados de la Matriz de 23 Estados cayeran silenciosamente al ícono Fingerprint genérico).
  const ICON_KEY_STYLES: Record<string, { color: string; glow: string; icon: (s: number) => React.ReactNode; textColor?: string }> = {
    blocked: { color: 'bg-white border-slate-400 text-text-3 shadow-none', glow: 'bg-slate-400', icon: (s) => <Fingerprint size={s} className="text-text-3 shrink-0" /> },
    holiday: { color: 'bg-white border-navy-300 text-accent shadow-accent/10', glow: 'bg-navy-400', icon: (s) => <Sun size={s} className="text-accent shrink-0" /> },
    restday: { color: 'bg-white border-border text-slate-400 shadow-none hover:border-slate-300', glow: '', icon: (s) => <Sun size={s} className="text-slate-400 shrink-0" /> },
    incidence_report: { color: 'bg-white border-warning-text text-warning-text shadow-warning-text/10 animate-pulse hover:border-warning-text', glow: 'bg-warning-icon', icon: (s) => <AlertTriangle size={s} className="text-warning-text animate-pulse shrink-0" /> },
    in_transit: { color: 'bg-white border-warning-text text-warning-text shadow-warning-text/10 hover:border-warning-text', glow: 'bg-warning-icon', icon: (s) => <MapPin size={s} className="text-warning-text shrink-0" /> },
    arrived: { color: 'bg-white border-success-text text-success-text shadow-success-text/10 animate-pulse hover:border-success-text', glow: 'bg-success-icon', icon: (s) => <LogIn size={s} className="text-success-text animate-pulse shrink-0" /> },
    gps_locked: { color: 'bg-white border-border text-slate-400 shadow-none', glow: '', icon: (s) => <MapPin size={s} className="text-slate-400 shrink-0" /> },
    access_blocked: { color: 'bg-white border-danger-text text-danger-text shadow-danger-text/10 animate-pulse hover:border-danger-text', glow: 'bg-danger-icon', icon: (s) => <MapPin size={s} className="text-danger-text shrink-0" /> },
    waiting_opening: { color: 'bg-white border-border text-slate-400 shadow-none', glow: '', icon: (s) => <Hourglass size={s} className="text-slate-400 shrink-0" /> },
    report_store_closed: { color: 'bg-white border-warning-text text-warning-text shadow-warning-text/10 animate-pulse hover:border-warning-text', glow: 'bg-warning-icon', icon: (s) => <AlertCircle size={s} className="text-warning-text animate-pulse shrink-0" /> },
    call_suplente: { color: 'bg-white border-accent text-accent shadow-accent/10 animate-pulse hover:border-accent', glow: 'bg-navy-400', icon: (s) => <Phone size={s} className="text-accent animate-pulse shrink-0" /> },
    open_store: {
      color: 'bg-white border-accent text-accent shadow-accent/10 animate-pulse hover:border-accent',
      glow: 'bg-navy-400',
      icon: (s) => (
        <span className="relative inline-flex shrink-0">
          <Key size={s} className="text-accent animate-pulse shrink-0" />
          <Store size={Math.round(s * 0.42)} className="absolute -bottom-1 -right-1 text-accent bg-white rounded-full p-0.5" />
        </span>
      )
    },
    emergency_open: { color: 'bg-white border-danger-text text-danger-text shadow-danger-text/15 animate-pulse hover:border-danger-text', glow: 'bg-danger-icon', icon: (s) => <ShieldAlert size={s} className="text-danger-text animate-pulse shrink-0" /> },
    entrada: { color: 'bg-white border-danger-text text-danger-text shadow-danger-text/10 hover:border-danger-text animate-pulse', glow: 'bg-danger-icon', icon: (s) => <LogIn size={s} className="text-danger-text shrink-0" />, textColor: 'text-danger-text' },
    verifying_gps: { color: 'bg-white border-accent text-accent shadow-accent/10 hover:border-accent', glow: 'bg-navy-400', icon: (s) => <MapPin size={s} className="text-accent animate-bounce shrink-0" />, textColor: 'text-accent' },
    verifying_selfie: { color: 'bg-white border-accent text-accent shadow-accent/10 hover:border-accent', glow: 'bg-navy-400', icon: (s) => <Camera size={s} className="text-accent shrink-0" />, textColor: 'text-accent' },
    success_check: { color: 'bg-white border-success-text text-success-text shadow-success-text/10 hover:border-success-text shadow-lg', glow: 'bg-success-icon animate-pulse', icon: (s) => <CheckCircle size={s} className="text-success-text animate-pulse shrink-0" />, textColor: 'text-success-text' },
    meal_prompt: { color: 'bg-white border-warning-text text-warning-text shadow-warning-text/10 hover:border-warning-text animate-pulse', glow: 'bg-warning-icon', icon: (s) => <Coffee size={s} className="text-warning-text animate-pulse shrink-0" />, textColor: 'text-warning-text' },
    meal_start: { color: 'bg-white border-warning-text text-warning-text shadow-warning-text/10 hover:border-warning-text animate-pulse', glow: 'bg-warning-icon', icon: (s) => <Coffee size={s} className="text-warning-text shrink-0" />, textColor: 'text-warning-text' },
    meal_end: { color: 'bg-white border-success-text text-success-text shadow-success-text/10 animate-pulse hover:border-success-text', glow: 'bg-success-icon', icon: (s) => <Utensils size={s} className="text-success-text animate-pulse shrink-0" /> },
    break_start: { color: 'bg-white border-accent text-accent shadow-accent/10 animate-pulse hover:border-accent', glow: 'bg-navy-400', icon: (s) => <Armchair size={s} className="text-accent animate-pulse shrink-0" />, textColor: 'text-accent' },
    break_end: { color: 'bg-white border-accent text-accent shadow-accent/10 animate-pulse hover:border-accent', glow: 'bg-navy-400', icon: (s) => <Armchair size={s} className="text-accent animate-pulse shrink-0" /> },
    handover: { color: 'bg-white border-accent text-accent shadow-accent/10 animate-pulse hover:border-accent', glow: 'bg-navy-400', icon: (s) => <Key size={s} className="text-accent animate-pulse shrink-0" /> },
    exit: { color: 'bg-white border-danger-text text-danger-text shadow-danger-text/10 hover:border-danger-text animate-pulse', glow: 'bg-danger-icon', icon: (s) => <LogOut size={s} className="text-danger-text shrink-0" />, textColor: 'text-danger-text' },
    reingreso: { color: 'bg-white border-accent text-accent shadow-accent/10 hover:border-accent', glow: 'bg-navy-400', icon: (s) => <LogIn size={s} className="text-accent shrink-0" /> },
    absent: { color: 'bg-white border-danger-text/20 text-danger-text shadow-none', glow: '', icon: (s) => <Ban size={s} className="text-danger-text shrink-0" /> },
    finished: { color: 'bg-white border-slate-300 text-slate-400 shadow-none', glow: '', icon: (s) => <CheckCircle size={s} className="text-slate-400 shrink-0" /> },
  };

  const getDialColorClasses = () => {
    if (isGpsError) return 'bg-white border-danger-text/20 text-danger-text shadow-none animate-pulse hover:border-danger-text';
    const byKey = btnProps.iconKey ? ICON_KEY_STYLES[btnProps.iconKey] : undefined;
    if (byKey) return byKey.color;
    // Fallback por texto para cualquier estado que aún no tenga iconKey asignado (no debería pasar
    // para los 23 de la matriz tras esta corrección, pero evita romper estados nuevos/experimentales).
    const text = btnProps.text || '';
    if (text === 'Abrir Tienda') return 'bg-white border-accent text-accent shadow-accent/10 animate-pulse hover:border-accent';
    if (text === 'Registrar Entrada' || text === 'Registrar Entrada Manual' || text === 'Fichar Entrada') return 'bg-white border-success-text text-success-text shadow-success-text/10 hover:border-success-text';
    return 'bg-white border-navy-300 text-accent shadow-accent/10 hover:border-accent';
  };

  const getDialGlowClasses = () => {
    if (isGpsError) return 'bg-danger-icon';
    const byKey = btnProps.iconKey ? ICON_KEY_STYLES[btnProps.iconKey] : undefined;
    if (byKey) return byKey.glow || null;
    return 'bg-navy-400';
  };

  const getDialIcon = (sizeValue: number) => {
    if (isGpsError) return <MapPin size={sizeValue} className="text-danger-text shrink-0 animate-bounce" />;
    const byKey = btnProps.iconKey ? ICON_KEY_STYLES[btnProps.iconKey] : undefined;
    if (byKey) return byKey.icon(sizeValue);

    // Fallback legacy por texto (estados sin iconKey todavía).
    if (btnProps.isIncidenceReport) return <AlertTriangle size={sizeValue} className="text-warning-text animate-pulse shrink-0" />;
    const text = btnProps.text || '';
    if (text === 'Abrir Tienda') return <Key size={sizeValue} className="text-accent animate-pulse shrink-0" />;
    if (text === 'Registrar Entrada' || text === 'Registrar Entrada Manual' || text === 'Fichar Entrada') return <LogIn size={sizeValue} className="text-success-text shrink-0" />;

    return <Fingerprint size={sizeValue} className="text-slate-300 shrink-0" />;
  };

  const getDialBottomLabel = () => {
    if (isGpsError) return 'GPS Requerido';
    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay;
    if (isRestDay) return 'Día libre';

    // Formateo de etiquetas de la secuencia del dial. Textos alineados a docs/Logica Dial.md
    // (matriz de 23 estados, columna "Texto Principal") — cambio de solo texto, sin tocar
    // condiciones ni el despacho de acciones en handleAction().
    // Si el clic va a abrir la RESERVACIÓN del comedor, el dial no puede decir "Tomar Comida":
    // en la prueba del 2026-08-22 el texto prometía empezar a comer y el clic abría "Aparta tu
    // comida" — un doble clic confundido con una comida duplicada que nunca existió.
    if (btnProps.isMealReservationAlert) return 'Apartar Comida';

    switch (btnProps.iconKey) {
      case 'entrada': return 'Fichar Entrada';
      case 'verifying_gps': return 'Buscando GPS';
      case 'verifying_selfie': return 'Validando Selfie';
      case 'success_check': return 'Fichaje Registrado';
      case 'break_start':
        // BUG FIX: antes 'break_start' y 'break_end' compartían el mismo texto ("Descanso Ley Silla"),
        // sin distinguir entre "invitación a sentarse" (estado #19, "Tomar Silla") y "ya estás sentado,
        // termina cuando quieras" (estado #20, "Terminar Descanso") — inconsistente además con
        // btnProps.text, que sí ya diferenciaba ambos casos.
        return 'Tomar Silla';
      case 'break_end':
        return 'Terminar Descanso';
      case 'meal_prompt':
      case 'meal_start':
        return 'Tomar Comida';
      case 'meal_end':
        // BUG FIX: mostraba 'Fin de Comida', pero btnProps.text (y el estado #18b de la matriz) usan
        // 'Terminar Comida' — quedaban desalineados entre sí.
        return 'Terminar Comida';
      case 'exit': return 'Fichar Salida';
    }

    if (btnProps.text) {
      if (btnProps.text.toLowerCase().includes('disponible a las')) {
        return 'Fuera de horario';
      }
      return btnProps.text;
    }

    if (btnProps.isIncidenceReport) return 'Reportar Falta';

    return 'Fichar Entrada';
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
          <div className="w-14 h-14 bg-accent-soft dark:bg-brand-dark/20 rounded-full flex items-center justify-center text-accent dark:text-navy-300 mb-4 shadow-sm">
            <Hourglass size={28} className="animate-spin text-accent" />
          </div>
          <p className="text-xs font-bold text-text-2 dark:text-slate-400">Buscando señal GPS de alta precisión...</p>
        </div>
      </div>
    );
  }

  return (
    <div className={`flex flex-col items-center justify-center py-2 mt-0 relative ${isMobile ? 'flex-shrink-0 my-3' : ''}`}>
      {(() => {
        const byKey = btnProps.iconKey ? ICON_KEY_STYLES[btnProps.iconKey] : undefined;
        return (
          <div className="relative flex-shrink-0 flex items-center justify-center">
            {/* Landing-Page-aligned Shimmer Glow Ring */}
            {getDialGlowClasses() ? (
              <div className={`absolute w-[158px] h-[158px] rounded-full blur-[10px] animate-shimmer-glow opacity-25 pointer-events-none z-0 ${getDialGlowClasses()}`}></div>
            ) : null}

            <button
              onClick={handleDialClick}
              disabled={!isGpsError && (btnProps.disabled || (clockState === 'waiting_room' && storeStatus === 'closed'))}
              aria-label={btnProps.subtext ? `${btnProps.text}. ${btnProps.subtext}` : btnProps.text}
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
            <div className={`flex-grow flex items-center justify-center mt-2.5 ${isGpsError ? 'text-danger-text' : ''}`}>
              {getDialIcon(size)}
            </div>

            {/* CENTRAL ZONE: Digital Time — o cronómetro de jornada (estado #16, Logica Dial.md).
                Cuando el empleado está en turno activo y hay un workedElapsedLabel (HH:MM:SS), el centro
                del dial muestra el tiempo TRABAJADO corriendo en vivo, en vez de la hora de pared. */}
            {clockState === 'active' && workedElapsedLabel ? (
              <div
                className={`flex flex-col items-center justify-center font-mono mt-0.5 mb-1.5 ${
                  isMobile ? 'text-[22px]' : 'text-3xl md:text-[34px] leading-none'
                }`}
                aria-label={`Tiempo trabajado ${workedElapsedLabel}`}
              >
                <span className="font-black tracking-tight text-success-text tabular-nums">{workedElapsedLabel}</span>
                <span className={`font-bold uppercase tracking-wider text-success-text/70 ${isMobile ? 'text-[8px] mt-0.5' : 'text-[9px] mt-1'}`}>
                  Tiempo trabajado
                </span>
              </div>
            ) : (
            <div className={`flex items-baseline font-mono font-black tracking-tight mt-0.5 mb-1.5 ${
              isGpsError ? 'text-danger-text' : byKey?.textColor || 'text-text-1'
            } ${isMobile ? 'text-[24px]' : 'text-3xl md:text-4xl leading-none'}`}>
              <span>
                {(() => {
                  const timePart = formattedTime.split(' ')[0];
                  if (timePart.includes(':')) {
                    const [h, m] = timePart.split(':');
                    return (
                      <>
                        {h}
                        <span className={`animate-[pulse_1s_infinite] select-none mx-0.5 font-bold ${
                          isGpsError ? 'text-danger-text' : byKey?.textColor || 'text-accent'
                        }`}>:</span>
                        {m}
                      </>
                    );
                  }
                  return timePart;
                })()}
              </span>
              <span className={`font-bold ${isGpsError ? 'text-danger-text' : 'text-text-3'} ${isMobile ? 'text-[11px] ml-1' : 'text-xs md:text-sm ml-1.5'}`}>
                {formattedTime.split(' ')[1] ? formattedTime.split(' ')[1].toLowerCase() : ''}
              </span>
            </div>
            )}

            {/* LOWER ZONE: Bottom Label */}
            <div className={`px-2 text-center w-full min-h-[32px] flex flex-col items-center justify-center mb-1.5 ${isGpsError ? 'text-danger-text' : 'text-text-2'} ${isMobile ? 'max-w-[155px]' : 'max-w-[170px]'}`}>
              <span aria-live="polite" className={`font-black uppercase tracking-wider leading-tight block ${isMobile ? 'text-[11.5px] max-w-[145px] leading-[1.1]' : 'text-xs md:text-[13px]'} ${isGpsError ? 'text-danger-text font-extrabold' : ''}`}>
                {getDialBottomLabel()}
              </span>
              {btnProps.subtext && !['entrada', 'verifying_gps', 'verifying_selfie', 'success_check', 'break_start', 'break_end', 'meal_prompt', 'meal_start', 'exit'].includes(btnProps.iconKey || '') && (
                <span className={`text-[9px] font-extrabold mt-0.5 leading-none block select-none uppercase truncate max-w-full ${isGpsError ? 'text-danger-text' : 'text-text-3 dark:text-slate-400'}`}>
                  {btnProps.subtext}
                </span>
              )}
            </div>
          </div>
        </button>

        {/* Botón de Pánico redondito a un lado del dialer */}
        {onPanicClick && (
          <button
            type="button"
            onClick={onPanicClick}
            title="Botón de Pánico / Alerta de Emergencia 🚨"
            aria-label="Botón de Pánico / Alerta de Emergencia 🚨"
            className={`absolute -right-12 sm:-right-14 top-1/2 -translate-y-1/2 rounded-full flex items-center justify-center transition-all active:scale-95 shadow-md border cursor-pointer z-30 hover:scale-110 ${
              isMobile ? 'w-10 h-10' : 'w-12 h-12'
            } bg-danger-bg dark:bg-danger-text/40 border-danger-text/20 dark:border-danger-text text-danger-text dark:text-rose-400 hover:bg-danger-bg dark:hover:bg-danger-text/60`}
          >
            <AlertOctagon size={isMobile ? 18 : 20} className="animate-pulse text-danger-text dark:text-rose-400" />
          </button>
        )}
      </div>
        );
      })()}

      {btnProps.iconKey === 'blocked' && onGoToRequiredCourseClick && (
        <button
          type="button"
          onClick={onGoToRequiredCourseClick}
          aria-label="Ir al curso obligatorio de Puntualidad en la Academia para desbloquear tu fichaje"
          className="mt-3.5 py-1.5 px-4 bg-navy-50 dark:bg-brand-dark/20 border border-border hover:border-navy-300 text-accent dark:text-navy-300 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-accent-soft transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20 border-solid"
        >
          🎓 Ir a la Academia
        </button>
      )}

      {clockState === 'active' && hasMealReservation && onMealSwapClick && (
        <button
          type="button"
          onClick={onMealSwapClick}
          aria-label="Intercambiar turno de comida con un compañero"
          className="mt-3.5 py-1.5 px-4 bg-warning-bg dark:bg-warning-text/20 border border-warning-text/20 hover:border-warning-text text-warning-text dark:text-amber-400 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-warning-bg transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20"
        >
          <Coffee size={12} className="text-warning-text" />
          Intercambiar Comida
        </button>
      )}

      {clockState === 'active' && onEarlyDepartureClick && (
        <button
          type="button"
          onClick={onEarlyDepartureClick}
          aria-label="Registrar salida anticipada, antes del fin de tu turno"
          className="mt-2.5 py-1.5 px-4 bg-danger-bg dark:bg-danger-text/20 border border-danger-text/20 hover:border-danger-text text-danger-text dark:text-rose-400 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-danger-bg transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20 border-solid"
        >
          <LogOut size={12} className="text-danger-text" />
          Salida Anticipada
        </button>
      )}

      {/* BUG FIX: Laborar Horas Extras debe aparecer tanto en Día de Descanso como en Día Feriado LFT.
          Textos alineados a docs/Logica Dial.md (2026-07-22): btnProps.text ahora es 'Día Descanso'/
          'Día Feriado' — se conservan los strings viejos como resguardo por si algún estado legacy no migrado los sigue usando. */}
      {(btnProps.text === 'DÍA DE DESCANSO' || btnProps.text === 'DÍA FERIADO (LFT)' || btnProps.text === 'Día Descanso' || btnProps.text === 'Día Feriado') && onOvertimeClick && (
        <button
          type="button"
          onClick={onOvertimeClick}
          aria-label="Habilitar el fichaje para laborar horas extras en tu día de descanso o feriado"
          className="mt-3.5 py-1.5 px-4 bg-warning-bg dark:bg-warning-text/20 border border-warning-text/20 hover:border-warning-text text-warning-text dark:text-amber-400 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-warning-bg transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20 border-solid"
        >
          <Fingerprint size={12} className="text-warning-text animate-pulse" />
          Laborar Horas Extras
        </button>
      )}

      {/* REGLA: Botón de Llamar a Encargado de Llaves SOLO se muestra a titulares y suplentes de llaves cuando la tienda está cerrada o en sala de espera */}
      {(storeStatus === 'closed' || clockState === 'waiting_room' || btnProps.text === '⏳ Esperando Apertura') && isUserKeyholder && !isResponsibleToday && onCallManagerClick && (
        <button
          type="button"
          onClick={onCallManagerClick}
          aria-label="Llamar por teléfono al encargado de llaves"
          className="mt-2.5 py-1.5 px-4 bg-navy-50 dark:bg-brand-dark/20 border border-border text-accent dark:text-navy-300 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-accent-soft transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20"
        >
          <Phone size={12} className="text-accent" />
          Llamar a Encargado de Llaves
        </button>
      )}

      {/* NUEVO (estados #7 y #11 de docs/Logica Dial.md): "Enviar Mensaje" — el empleado común SIN
          llaves no puede llamar por teléfono; en su lugar avisa al encargado que ya está en puerta
          esperando la apertura. Espejo exacto de la condición del botón de llaves pero para el caso
          contrario (!isUserKeyholder). Hoy degradado a aviso in-app + registro (ver
          handleSendDoorNotice en useClockEngine.tsx); el push real depende de backend §26. */}
      {(storeStatus === 'closed' || clockState === 'waiting_room' || btnProps.text === '⏳ Esperando Apertura') && !isUserKeyholder && onSendDoorNoticeClick && (
        <button
          type="button"
          onClick={onSendDoorNoticeClick}
          aria-label="Enviar mensaje al encargado avisando que ya estás en puerta"
          className="mt-2.5 py-1.5 px-4 bg-navy-50 dark:bg-brand-dark/20 border border-border text-accent dark:text-navy-300 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-accent-soft transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20"
        >
          <MessageSquare size={12} className="text-accent" />
          Enviar Mensaje
        </button>
      )}

      {/* NUEVO (estado #5 de la matriz): "Marcar a Suplente" — visible solo para el encargado
          responsable de apertura de hoy (btnProps.isOpeningManager, seteado en VENTANA 1 de
          getButtonProps), para avisar proactivamente al siguiente suplente en la fila de prioridad
          antes de que se dispare el traspaso automático por deadline vencido. */}
      {btnProps.isOpeningManager && onCallSuplenteClick && (
        <button
          type="button"
          onClick={onCallSuplenteClick}
          aria-label="Marcar por teléfono al suplente de llaves"
          className="mt-2.5 py-1.5 px-4 bg-navy-50 dark:bg-brand-dark/20 border border-border text-accent dark:text-navy-300 font-extrabold text-[10px] uppercase tracking-wider rounded-full shadow-sm hover:bg-accent-soft transition-all cursor-pointer flex items-center gap-1.5 active:scale-95 z-20"
        >
          <Phone size={12} className="text-accent" />
          Marcar a Suplente
        </button>
      )}



      {/* Premium Centered GPS Instruction Modal */}
      {showGpsModal && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[100] flex items-center justify-center p-4 animate-in fade-in duration-200">
          <div className="bg-white border border-border w-full max-w-sm rounded-[2rem] p-6 shadow-2xl relative animate-in zoom-in-95 duration-200 text-left">

            {/* Close Button */}
            <button
              onClick={() => setShowGpsModal(false)}
              aria-label="Cerrar"
              className="absolute top-4 right-4 text-slate-400 hover:text-text-2 p-1.5 rounded-full hover:bg-page transition-all border-none cursor-pointer"
            >
              <X size={18} />
            </button>

            {/* Modal Icon Header */}
            <div className="w-12 h-12 bg-danger-bg border border-danger-text/20 rounded-2xl flex items-center justify-center text-danger-text mx-auto mb-4 shadow-sm">
              <MapPin size={22} className="animate-bounce" />
            </div>

            {/* Modal Title */}
            <h3 className="text-sm font-black text-text-1 text-center tracking-tight mb-2">
              Ubicación Requerida
            </h3>

            {/* Message */}
            <p className="text-[10.5px] text-text-3 text-center leading-relaxed mb-5 px-2 font-bold">
              Para realizar tu registro, por favor activa los datos y la ubicación en la barra de ajustes de tu celular, luego presiona el botón de abajo.
            </p>

            {/* Modal Actions */}
            <div className="flex flex-col gap-2">
              <button
                type="button"
                onClick={handleRetryGps}
                aria-label="Reintentar obtención de ubicación GPS"
                className="w-full py-2.5 bg-gradient-to-r from-danger-icon to-danger-text hover:from-danger-text hover:to-danger-text text-white font-black text-[10px] uppercase tracking-wider rounded-xl shadow-md transition-all duration-300 transform active:scale-95 border-none cursor-pointer flex items-center justify-center gap-1.5"
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
