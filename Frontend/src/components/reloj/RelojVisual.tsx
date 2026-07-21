// @ts-nocheck
import React, { useState, useEffect, useRef } from 'react';
import { Clock, CheckSquare, GraduationCap, Settings, Star, DollarSign, Key, WifiOff, ClipboardList, UserX, AlertTriangle, Fingerprint, Lock, Check, Play, Menu, LogIn, Coffee, Utensils, LogOut, Hourglass, Store, Sun, AlertCircle, CheckCircle, Network, X, Upload, Armchair, MessageSquare, AlertOctagon, Sparkles, Bot, Send, Trophy, ListTodo, User, Users } from 'lucide-react';
import { useAppStore } from '../../store/useAppStore';
import { ColorMap } from '../SaaSAccountSettings';
import { useTaskStore } from '../../store/useTaskStore';
import { useClockContext } from '../store/ClockContext';
import Academia from './Academia';
import Evaluacion360 from './Evaluacion360';
import NominaColaborador from './NominaColaborador';
import axiosInstance from '../../lib/axios';
import { TaskRunner } from '../tareas_rutinas/TaskRunner';
import { MobileBottomNav } from './MobileBottomNav';
const RecursosHumanos = React.lazy(() => import('../RecursosHumanos'));
import DialPrincipal from './DialPrincipal';

export default function RelojVisual({ 
  isMobileFrame = false,
  isSimulated = false,
  simulatedTier = 'pro',
  setSimulatedTier
}: { 
  isMobileFrame?: boolean;
  isSimulated?: boolean;
  simulatedTier?: 'free' | 'pro';
  setSimulatedTier?: (tier: 'free' | 'pro') => void;
}) {
  const { currentTier, isFeatureUnlocked: isFeatureUnlockedReal, isSandboxMode } = useAppStore();
  const isFeatureUnlocked = (featureId: string) => {
    if (isSimulated) {
      if (simulatedTier === 'pro') return true;
      if (['gps_validation', 'face_validation', 'meal_reservation', 'roll_call', 'store_opening'].includes(featureId)) {
        return false;
      }
      return true;
    }
    return isFeatureUnlockedReal(featureId);
  };
  const isPro = isSimulated ? (simulatedTier === 'pro') : (currentTier === 'pro' || currentTier === 'enterprise');

  const clockContextReal = useClockContext();

  // Estados de simulación local en memoria para el modo demostrativo/landing
  const [simClockState, setSimClockState] = useState('inactive');
  const [simPhoneTab, setSimPhoneTab] = useState('checador');
  const [simInnerTool, setSimInnerTool] = useState(null);
  const [simTask1Done, setSimTask1Done] = useState(true);
  const [simTask2Done, setSimTask2Done] = useState(false);
  const [simIsDark, setSimIsDark] = useState(false);

  const mockProps: any = {
    isSimulated: true,
    simulatedTier,
    setSimulatedTier,
    clockState: simClockState,
    currentUser: {
      id: 99,
      name: 'Francisco Vega',
      avatar: 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=80&h=80&fit=crop&crop=faces',
      tenant: { name: 'Decorarte 365' },
      role: 'empleado',
      pin_code: '1234'
    },
    currentSimTime: 540,
    formattedTime: '09:00:00',
    ampm: 'AM',
    btnProps: { isIncidenceReport: false },
    systemSettings: {
      clockOpConfig: {
        allowManualCheckIn: true,
        gpsValidationEnabled: simulatedTier === 'pro' ? true : false
      }
    },
    workedHours: 6.5,
    progressPercent: simClockState === 'active' ? 25 : ['break_active', 'break_done'].includes(simClockState) ? 50 : ['lunch_active', 'lunch_done'].includes(simClockState) ? 75 : 100,
    phoneTab: simPhoneTab,
    setPhoneTab: setSimPhoneTab,
    innerTool: simInnerTool,
    setInnerTool: setSimInnerTool,
    currentDay: 'Lunes',
    DIAS_SEMANA: ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
    shiftConfigs: {
      99: { restDay: 'Domingo', shiftStart: '09:00', shiftEnd: '18:00' }
    },
    chatMessages: [],
    pendingKeyTransfers: [],
    lateUsers: [],
    absentUsers: [],
    contingencyLogs: [],
    arrivalTimes: {},
    checkInTimes: {},
    checkOutTimes: {},
    breaksTaken: {},
    showCustomAlert: (msg: string) => console.log('Mock Alert:', msg),
    updateClockState: (state: string) => setSimClockState(state),
    handleAction: (action: string) => {
      // Simular transiciones de estado
      if (action === 'iniciar_jornada') setSimClockState('active');
      else if (action === 'iniciar_descanso') setSimClockState('break_active');
      else if (action === 'terminar_descanso') setSimClockState('break_done');
      else if (action === 'iniciar_comida') setSimClockState('lunch_active');
      else if (action === 'terminar_comida') setSimClockState('lunch_done');
      else if (action === 'terminar_jornada') setSimClockState('finished');
    }
  };

  const context = (isSimulated || !clockContextReal) 
    ? new Proxy(mockProps, {
        get: (target, prop) => {
          if (prop in target) {
            return target[prop];
          }
          const propStr = prop.toString();
          if (propStr.startsWith('set') || propStr.startsWith('handle') || propStr.startsWith('submit') || propStr.startsWith('request') || propStr.startsWith('approve') || propStr.startsWith('reject')) {
            return () => {};
          }
          if (propStr.includes('List') || propStr.includes('Messages') || propStr.includes('Alarms') || ['chatMessages', 'pendingKeyTransfers', 'lateUsers', 'absentUsers', 'contingencyLogs', 'pendingBreakRequests', 'syncQueue', 'dailyHistory'].includes(propStr)) {
            return [];
          }
          if (propStr.includes('Show') || propStr.includes('show')) {
            return false;
          }
          if (['timeBankConfigs', 'leySillaConfig', 'mealSettings', 'buddyAlerts', 'reservedMeals', 'userReservedMealSlots', 'activeTimers', 'arrivalTimes', 'checkInTimes', 'checkOutTimes', 'breaksTaken', 'breakStartTimes', 'breakEndTimes', 'mealStartTimes', 'mealEndTimes', 'hasReservedMeal', 'shiftConfigs', 'userSettings', 'adminConfigs', 'openingSettings', 'btnProps', 'reportForm'].includes(propStr)) {
            return {};
          }
          return undefined;
        }
      })
    : clockContextReal;

  const {
    currentSimTime,
    DIAS_SEMANA,
    absenceReason,
    absentUsers,
    activeEncargadoId,
    activePushNotification,
    activeTimers,
    adminConfigs,
    amnestyActive,
    ampm,
    arrivalTimes,
    breaksTaken,
    btnProps,
    buddyAlerts,
    calculateDailyStats,
    checkInTimes,
    checkOutTimes,
    clockState,
    confirmMealReservation,
    cancelMealReservation,
    swapMealSlots,
    contingencyLogs,
    currentDay,
    currentUser,
    setCurrentUser,
    dailyHistory,
    dbPermissions,
    dbRolePermissions,
    designatedCloserId,
    displayHours,
    evalStars,
    featureFlags,
    formattedTime,
    getButtonProps,
    globalClockStates,
    globalPermissions,
    globalRoles,
    globalUsers,
    setGlobalUsers,
    systemSettings,
    updateSetting,
    handleAction,
    handleAperturaForzosa,
    handleBreakStart,
    handleClockOutRequest,
    handleContingency,
    handleKeyDelegation,
    handleKioscoAdd,
    handleOpenStore,
    handleSubmitPaseLista,
    hasReservedMeal,
    initPaseLista,
    innerTool,
    nextDayEncargadoId,
    parseTimeToMins,
    paseListaDone,
    paseListaEmployees,
    toggleSelectAll,
    kioscoInput,
    setKioscoInput,
    phoneTab,
    playAlarm,
    playedAlarms,
    privateMessages,
    processFinalClockOut,
    realSeconds,
    removeAlert,
    reportForm,
    requireEvaluation,
    reservedMeals,
    resetSimulator,
    setAbsenceReason,
    setAbsentUsers,
    setActiveEncargadoId,
    setActivePushNotification,
    setActiveTimers,
    setAmnestyActive,
    setArrivalTimes,
    setBreaksTaken,
    setCheckInTimes,
    setContingencyLogs,
    setCurrentDay,
    setDesignatedCloserId,
    setEvalStars,
    setHasReservedMeal,
    setInnerTool,
    setNextDayEncargadoId,
    setPaseListaDone,
    setPaseListaEmployees,
    setPhoneTab,
    setPlayedAlarms,
    setReportForm,
    setRequireEvaluation,
    setReservedMeals,
    setStoreStatus,
    setUserSettings,
    userReservedMealSlots,
    userSettings,
    storeStatus,
    submitEvaluation,
    submitReport,
    showAbsenceModal,
    showAmnestyModal,
    showEvalModal,
    showForzosaModal,
    showJustificanteModal,
    showKeyDelegationModal,
    showMealReservationModal,
    showPaseListaModal,
    showReportModal,
    setShowAbsenceModal,
    setShowAmnestyModal,
    setShowEvalModal,
    setShowForzosaModal,
    setShowJustificanteModal,
    setShowKeyDelegationModal,
    setShowMealReservationModal,
    setShowPaseListaModal,
    setShowReportModal,
    updateClockState,
    shiftConfigs,
    setShiftConfigs,
    justificanteText,
    setJustificanteText,
    timeBankConfigs,
    simMins,
    globalToast,
    showCustomAlert,
    lateUsers,
    syncQueue,
    gpsStatus,
    setGpsStatus,
    setGpsCoordinates,
    requestGPS,
    isWithinPerimeter,
    isGpsValidationBypassed,
    gpsDistance,
    isSimulatedOffline,
    setIsSimulatedOffline,
    breakStartTimes,
    breakEndTimes,
    mealStartTimes,
    mealEndTimes,
    globalBroadcastMessage,
    openingSettings,
    openingStatus,
    openingChecklistCompleted,
    setOpeningChecklistCompleted,
    openingRollCallCompleted,
    setOpeningRollCallCompleted,
    handleOpenStorePremium,
    handleReportAbsencePremium,
    handleReportLatePremium,
    handleReportStoreStillClosedPremium,
    isOpeningPremium,
    mealSettings,
    leySillaConfig,
    chatMessages,
    chatLoading,
    pendingKeyTransfers,
    fetchChatMessages,
    sendChatMessage,
    sendEmployeeReport,
    sendAnonymousFeedback,
    initiateKeyTransfer,
    checkPendingKeyTransfers,
    respondToKeyTransfer,
    reportAbandonment,
    pendingBreakRequests,
    requestBreak,
    approveBreakRequest,
    rejectBreakRequest,
    showBreakSeatModal,
    setShowBreakSeatModal,
    showTempExitModal,
    setShowTempExitModal,
    showPanicModal,
    setShowPanicModal,
    isPanicActive,
    setIsPanicActive,
    showMealSwapModal,
    setShowMealSwapModal,
    isHandoverCompleted,
    setIsHandoverCompleted,
    startBreakWithSittingTask,
    startTempExit,
    endTempExit,
    triggerPanic,
    resolvePanic,
    completeHandover,
    cashCount,
    setCashCount,
    userClockPrefs,
    setUserClockPrefs,
    showAlarmSettingsModal,
    setShowAlarmSettingsModal,
    pendingTasksBlocker,
    setPendingTasksBlocker,
    supervisorPin,
    setSupervisorPin,
    supervisorQrToken,
    setSupervisorQrToken,
    showEarlyDepartureModal,
    setShowEarlyDepartureModal,
    earlyDepartureReason,
    setEarlyDepartureReason,
    handleEarlyDepartureClick,
    submitEarlyDeparture,
    isEarlyDepartureValidation,
    isOvertimeValidation,
    isLateEntryValidation,
    setIsLateEntryValidation,
    isSimulatedHoliday,
    setIsSimulatedHoliday,
    handleOvertimeClick,
    hasMealReservation,
    authorizeClockOutWithPendingTasks
  } = context;

  // Estados locales para nuevas herramientas operativas
  const [chatMessageText, setChatMessageText] = useState('');
  const [selectedAccusedId, setSelectedAccusedId] = useState('');
  const [soplonReportType, setSoplonReportType] = useState('');
  const [soplonReportDetails, setSoplonReportDetails] = useState('');
  const [buzonFeedbackType, setBuzonFeedbackType] = useState('');
  const [buzonFeedbackContent, setBuzonFeedbackContent] = useState('');
  const [transferNotes, setTransferNotes] = useState('');
  const [selectedTransferReceiverId, setSelectedTransferReceiverId] = useState('');
  const chatBottomRef = useRef<HTMLDivElement>(null);

  const [showPerformanceModal, setShowPerformanceModal] = useState(false);

  const [weeklyPerformanceScore, setWeeklyPerformanceScore] = useState<number | null>(null);
  const [weeklyPayrollData, setWeeklyPayrollData] = useState<any>(null);

  // Helper to check if store is closed based on schedule and pre-opening access window
  const getStoreClosedInfo = () => {
    const openTimeStr = systemSettings?.storeSchedule?.openTime || '08:00';
    const closeTimeStr = systemSettings?.storeSchedule?.closeTime || '18:00';
    const preOpeningAccessMins = systemSettings?.clockOpConfig?.preOpeningAccessMins ?? 60;
    
    const [oh, om] = openTimeStr.split(':').map(Number);
    const [ch, cm] = closeTimeStr.split(':').map(Number);
    const baseOpenMins = oh * 60 + om;
    const closeMins = ch * 60 + cm;
    
    // Extend the opening time backwards by preOpeningAccessMins
    let openMins = baseOpenMins - preOpeningAccessMins;
    if (openMins < 0) {
      openMins += 24 * 60;
    }
    
    let isClosed = false;
    if (openMins < closeMins) {
      isClosed = currentSimTime < openMins || currentSimTime > closeMins;
    } else {
      isClosed = currentSimTime > closeMins && currentSimTime < openMins;
    }

    let remainingMins = 0;
    if (isClosed) {
      if (currentSimTime < openMins) {
        remainingMins = openMins - currentSimTime;
      } else {
        remainingMins = (24 * 60 - currentSimTime) + openMins;
      }
    }

    const hours = Math.floor(remainingMins / 60);
    const minsFinal = remainingMins % 60;
    let waitTimeText = '';
    if (hours > 0) {
      waitTimeText = `${hours} ${hours === 1 ? 'hora' : 'horas'} y ${minsFinal} ${minsFinal === 1 ? 'minuto' : 'minutos'}`;
    } else {
      waitTimeText = `${minsFinal} ${minsFinal === 1 ? 'minuto' : 'minutos'}`;
    }

    return { isClosed, waitTimeText };
  };

  const { isClosed: isStoreClosed, waitTimeText } = getStoreClosedInfo();

  // Redirect to academia if store is closed and they are on a blocked tab
  useEffect(() => {
    if (isStoreClosed && (phoneTab === 'tareas' || phoneTab === 'nomina' || phoneTab === 'herramientas' || phoneTab === 'evaluacion360' || phoneTab === 'organigrama')) {
      setPhoneTab('academia');
    }
  }, [isStoreClosed, phoneTab, setPhoneTab]);

  useEffect(() => {
    if (!isSimulated) {
      axiosInstance.get('/employee/payroll-weekly')
        .then(res => {
          if (res.data && res.data.success) {
            setWeeklyPayrollData(res.data.data);
            if (res.data.data.performance) {
              setWeeklyPerformanceScore(res.data.data.performance.performance_score);
            }
          }
        })
        .catch(err => console.error('Error fetching weekly score:', err));
    } else {
      setWeeklyPerformanceScore(88);
      setWeeklyPayrollData({
        incidents: { total_absences: 0, lates: 1 },
        performance: { meal_overtime_mins: 12, task_performance_pct: 95, performance_score: 88 }
      });
    }
  }, [isSimulated, clockState]);

  const [pendingCoursesCount, setPendingCoursesCount] = useState(0);

  useEffect(() => {
    if (isSimulated) {
      setPendingCoursesCount(currentUser?.has_completed_induction ? 0 : 2);
    } else {
      axiosInstance.get('/academy/courses')
        .then(res => {
          const data = res.data;
          if (data && Array.isArray(data.courses)) {
            const filtered = data.courses.filter((c: any) => !c.target_job_role_id || c.target_job_role_id === currentUser?.job_role_id);
            const completed = (data.user_progress || []).filter((up: any) => up.status === 'completed').map((up: any) => up.course_id);
            const pending = filtered.filter((c: any) => !completed.includes(c.id));
            setPendingCoursesCount(pending.length);
          }
        })
        .catch(err => console.error('Error fetching academy courses for notifications:', err));
    }
  }, [isSimulated, currentUser?.id, currentUser?.has_completed_induction, clockState]);

  // Auto-scroll para el chat de equipo
  useEffect(() => {
    if (innerTool === 'chat' && chatBottomRef.current) {
      chatBottomRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [chatMessages, innerTool]);

  // --- RENDERS AUXILIARES DE NUEVAS PANTALLAS DE HERRAMIENTAS ---

  const renderToolChat = () => {
    return (
      <div className={`flex flex-col h-[400px] rounded-2xl border p-3 ${isDark ? 'border-slate-800 bg-slate-950/60' : 'border-slate-200 bg-white'} shadow-inner`}>
        <div className="flex justify-between items-center border-b pb-2 mb-2 dark:border-slate-800">
          <h5 className="font-black text-sm text-indigo-500 flex items-center gap-1.5">
            <span className="animate-pulse">🟢</span> Chat Grupal de Sucursal
          </h5>
          <span className="text-[9px] text-slate-400 italic">Mensajes expiran en 7 días</span>
        </div>
        
        {/* Historial de mensajes */}
        <div className="flex-grow overflow-y-auto space-y-3 pr-1 scrollbar-thin flex flex-col">
          {chatLoading && chatMessages.length === 0 ? (
            <div className="text-center py-8 text-xs text-slate-400 my-auto">Cargando historial...</div>
          ) : chatMessages.length === 0 ? (
            <div className="text-center py-8 text-xs text-slate-400 italic my-auto">No hay mensajes recientes. ¡Sé el primero en escribir!</div>
          ) : (
            <div className="space-y-3 flex-grow overflow-y-auto pr-1">
              {chatMessages.map((msg) => {
                const isMe = msg.user_id === currentUser.id;
                return (
                  <div key={msg.id} className={`flex gap-2 max-w-[85%] ${isMe ? 'ml-auto flex-row-reverse text-right' : 'mr-auto text-left'}`}>
                    {!isMe && (
                      <img 
                        src={msg.user?.avatar || 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=80&h=80'} 
                        alt="Avatar" 
                        className="w-7 h-7 rounded-full border border-slate-200 shrink-0" 
                      />
                    )}
                    <div>
                      {!isMe && (
                        <span className="text-[9px] font-bold text-slate-400 block mb-0.5">
                          {msg.user?.name} <span className="font-medium text-indigo-400">({msg.user?.role})</span>
                        </span>
                      )}
                      <div className={`p-2.5 rounded-2xl text-xs font-medium leading-relaxed ${
                        isMe 
                          ? 'bg-indigo-600 text-white rounded-tr-none' 
                          : (isDark ? 'bg-slate-800 text-slate-100' : 'bg-slate-100 text-slate-800') + ' rounded-tl-none'
                      }`}>
                        {msg.message}
                      </div>
                      <span className="text-[8px] text-slate-400 block mt-1">
                        {new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                      </span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
          <div ref={chatBottomRef} />
        </div>

        {/* Input de envío */}
        <form 
          onSubmit={(e) => {
            e.preventDefault();
            if (!chatMessageText.trim()) return;
            sendChatMessage(chatMessageText);
            setChatMessageText('');
          }} 
          className="mt-3 flex gap-2 pt-2 border-t dark:border-slate-800"
        >
          <input 
            type="text" 
            placeholder="Escribe un mensaje seguro..." 
            className={`flex-grow p-2.5 rounded-xl text-xs outline-none focus:ring-2 focus:ring-indigo-500 ${
              isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
            } border`}
            value={chatMessageText}
            onChange={(e) => setChatMessageText(e.target.value)}
          />
          <button 
            type="submit" 
            className="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 rounded-xl text-xs shadow-md transition-colors"
          >
            Enviar
          </button>
        </form>
      </div>
    );
  };

  const renderToolSoplon = () => {
    const activePeers = (globalUsers || []).filter(u => u.id !== currentUser.id);
    return (
      <div className={`rounded-2xl border p-5 space-y-4 text-left ${isDark ? 'border-slate-800 bg-slate-900/40' : 'border-slate-200 bg-white'} shadow-sm`}>
        <div className="border-b pb-2 mb-2 dark:border-slate-800">
          <h5 className="font-black text-sm text-rose-500 flex items-center gap-1.5">
            📢 Reportar Falta (El Soplón)
          </h5>
          <p className="text-[10px] text-slate-500">Notifica de forma confidencial ausencias o mala conducta en el turno.</p>
        </div>

        <div className="space-y-3.5">
          <div>
            <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Compañero involucrado</label>
            <select 
              className={`w-full border rounded-xl p-2.5 text-xs outline-none focus:ring-2 focus:ring-rose-500 ${
                isDark ? 'bg-slate-950 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
              }`}
              value={selectedAccusedId}
              onChange={e => setSelectedAccusedId(e.target.value)}
            >
              <option value="">Selecciona un colaborador...</option>
              {activePeers.map(u => (
                <option key={u.id} value={u.id}>{u.name} ({u.role})</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Tipo de Falta</label>
            <select 
              className={`w-full border rounded-xl p-2.5 text-xs outline-none focus:ring-2 focus:ring-rose-500 ${
                isDark ? 'bg-slate-950 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
              }`}
              value={soplonReportType}
              onChange={e => setSoplonReportType(e.target.value)}
            >
              <option value="">Selecciona una opción...</option>
              <option value="abandono">Abandono de puesto de trabajo</option>
              <option value="retardo">Retardo / Impuntualidad constante</option>
              <option value="conducta">Conducta inapropiada en sucursal</option>
              <option value="dano">Daño a equipo o instalaciones</option>
              <option value="otro">Otro motivo (describir abajo)</option>
            </select>
          </div>

          <div>
            <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Detalles e Incidencias</label>
            <textarea 
              className={`w-full border rounded-xl p-2.5 text-xs outline-none focus:ring-2 focus:ring-rose-500 ${
                isDark ? 'bg-slate-950 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
              }`}
              rows={3}
              placeholder="Describe lo ocurrido detalladamente..."
              value={soplonReportDetails}
              onChange={e => setSoplonReportDetails(e.target.value)}
            />
          </div>

          <button 
            onClick={async () => {
              if (!selectedAccusedId || !soplonReportType || !soplonReportDetails.trim()) {
                showCustomAlert("Por favor completa todos los campos del reporte.");
                return;
              }
              const ok = await sendEmployeeReport(parseInt(selectedAccusedId), soplonReportType, soplonReportDetails);
              if (ok) {
                setSelectedAccusedId('');
                setSoplonReportType('');
                setSoplonReportDetails('');
                setInnerTool(null);
              }
            }} 
            className="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold py-3 rounded-xl text-xs shadow-md transition-colors"
          >
            Enviar Reporte de Falta
          </button>
        </div>
      </div>
    );
  };

  const renderToolBuzon = () => {
    return (
      <div className={`rounded-2xl border p-5 space-y-4 text-left ${isDark ? 'border-slate-800 bg-slate-900/40' : 'border-slate-200 bg-white'} shadow-sm`}>
        <div className="border-b pb-2 mb-2 dark:border-slate-800">
          <h5 className="font-black text-sm text-sky-500 flex items-center gap-1.5">
            🕵️ Buzón Anónimo de RRHH
          </h5>
          <p className="text-[10px] text-slate-500">Envía tus quejas o sugerencias. Tu identidad no será grabada en el servidor.</p>
        </div>

        <div className="space-y-3.5">
          <div>
            <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Categoría de Feedback</label>
            <select 
              className={`w-full border rounded-xl p-2.5 text-xs outline-none focus:ring-2 focus:ring-sky-500 ${
                isDark ? 'bg-slate-950 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
              }`}
              value={buzonFeedbackType}
              onChange={e => setBuzonFeedbackType(e.target.value)}
            >
              <option value="">Selecciona una categoría...</option>
              <option value="sugerencia">Propuesta de mejora o sugerencia</option>
              <option value="ambiente">Ambiente de trabajo o clima laboral</option>
              <option value="acoso">Reporte de acoso o discriminación</option>
              <option value="seguridad">Seguridad e higiene</option>
              <option value="otro">Otro tema confidencial</option>
            </select>
          </div>

          <div>
            <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Contenido de tu Mensaje</label>
            <textarea 
              className={`w-full border rounded-xl p-2.5 text-xs outline-none focus:ring-2 focus:ring-sky-500 ${
                isDark ? 'bg-slate-950 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
              }`}
              rows={4}
              placeholder="Escribe tu mensaje confidencial para el equipo de Recursos Humanos aquí..."
              value={buzonFeedbackContent}
              onChange={e => setBuzonFeedbackContent(e.target.value)}
            />
          </div>

          <button 
            onClick={async () => {
              if (!buzonFeedbackType || !buzonFeedbackContent.trim()) {
                showCustomAlert("Por favor completa los campos del feedback.");
                return;
              }
              const ok = await sendAnonymousFeedback(buzonFeedbackType, buzonFeedbackContent);
              if (ok) {
                setBuzonFeedbackType('');
                setBuzonFeedbackContent('');
                setInnerTool(null);
              }
            }} 
            className="w-full bg-sky-600 hover:bg-sky-700 text-white font-bold py-3 rounded-xl text-xs shadow-md transition-colors"
          >
            Enviar Comentarios de Forma Segura
          </button>
        </div>
      </div>
    );
  };

  const renderToolTransfer = () => {
    const activePeers = (globalUsers || []).filter(u => u.id !== currentUser.id);
    return (
      <div className={`rounded-2xl border p-5 space-y-4 text-left ${isDark ? 'border-slate-800 bg-slate-900/40' : 'border-slate-200 bg-white'} shadow-sm`}>
        <div className="border-b pb-2 mb-2 dark:border-slate-800">
          <h5 className="font-black text-sm text-indigo-500 flex items-center gap-1.5">
            🔑 Transferir Cierre (Custodia de Llaves)
          </h5>
          <p className="text-[10px] text-slate-500">Delega la responsabilidad del cierre a un compañero. El sistema requiere su aceptación.</p>
        </div>

        <div className="space-y-3.5">
          <div>
            <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Compañero receptor</label>
            <select 
              className={`w-full border rounded-xl p-2.5 text-xs outline-none focus:ring-2 focus:ring-indigo-500 ${
                isDark ? 'bg-slate-950 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
              }`}
              value={selectedTransferReceiverId}
              onChange={e => setSelectedTransferReceiverId(e.target.value)}
            >
              <option value="">Selecciona un compañero...</option>
              {activePeers.map(u => (
                <option key={u.id} value={u.id}>{u.name} ({u.role})</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Notas de Entrega</label>
            <textarea 
              className={`w-full border rounded-xl p-2.5 text-xs outline-none focus:ring-2 focus:ring-indigo-500 ${
                isDark ? 'bg-slate-950 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
              }`}
              rows={2}
              placeholder="Instrucciones adicionales para el cierre..."
              value={transferNotes}
              onChange={e => setTransferNotes(e.target.value)}
            />
          </div>

          <button 
            onClick={async () => {
              if (!selectedTransferReceiverId) {
                showCustomAlert("Por favor selecciona al compañero que recibirá el cierre.");
                return;
              }
              const ok = await initiateKeyTransfer(parseInt(selectedTransferReceiverId), transferNotes);
              if (ok) {
                setSelectedTransferReceiverId('');
                setTransferNotes('');
                setInnerTool(null);
              }
            }} 
            className="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl text-xs shadow-md transition-colors"
          >
            Enviar Solicitud de Traspaso
          </button>
        </div>
      </div>
    );
  };

  const renderToolHuida = () => {
    return (
      <div className={`rounded-2xl border p-6 text-center space-y-4 ${isDark ? 'border-slate-800 bg-slate-900/40 text-white' : 'border-slate-200 bg-white text-slate-800'} shadow-sm`}>
        <div className="text-4xl">🏃⚠️</div>
        <h5 className="font-black text-base text-rose-600">Simulación de Abandono (Pérdida de Wi-Fi)</h5>
        <p className="text-xs text-slate-500 leading-relaxed max-w-sm mx-auto">
          Al activar esta simulación, registrarás que te retiraste de la sucursal de manera imprevista sin traspasar formalmente las llaves de cierre a tu respaldo.
        </p>
        <div className="bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900 rounded-xl p-3.5 text-rose-700 dark:text-rose-400 text-[10px] leading-normal text-left">
          <strong>⚠️ Alerta de Seguridad:</strong> Esta acción registrará una incidencia crítica en la bitácora de auditoría del servidor (`audit_logs`) con una penalización simulada.
        </div>
        <div className="flex gap-2">
          <button 
            onClick={async () => {
              await reportAbandonment();
              setInnerTool(null);
            }} 
            className="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-3 rounded-xl text-xs shadow-md transition-colors"
          >
            Simular Huida / Desconexión
          </button>
          <button 
            onClick={() => setInnerTool(null)} 
            className={`flex-1 font-bold py-3 rounded-xl text-xs border ${
              isDark ? 'border-slate-800 text-slate-350 hover:bg-slate-800' : 'border-slate-200 text-slate-600 hover:bg-slate-50'
            }`}
          >
            Cancelar
          </button>
        </div>
      </div>
    );
  };
    const renderStatusText = (text: string) => {
    if (!text) return null;
    if (text.toLowerCase().includes('disponible a las')) {
      const parts = text.split(' a las ');
      const timePart = (parts[1] || '').replace(/AM/g, 'am').replace(/PM/g, 'pm');
      return (
        <div className="flex flex-col items-center leading-tight">
          <span className="text-[12px] md:text-[13.5px] font-black uppercase text-slate-400 tracking-wider">Disponible</span>
          
          <div className="flex items-center gap-1.5 mt-1 justify-center">
            {/* Small Animated Hourglass (scaled down slightly) */}
            <div className="w-[20px] h-[20px] flex items-center justify-center shrink-0">
              <svg className="w-full h-full text-violet-500 animate-hourglass" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M 25 15 C 25 15, 25 35, 45 48 C 47.5 49.5, 47.5 50.5, 45 52 C 25 65, 25 85, 25 85 L 75 85 C 75 85, 75 65, 55 52 C 52.5 50.5, 52.5 49.5, 55 48 C 75 35, 75 15, 75 15 Z" stroke="currentColor" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round" fill="white" />
                <path d="M 28 18 C 28 18, 32 35, 50 46 C 68 35, 72 18, 72 18 Z" fill="currentColor" className="top-sand text-violet-400" />
                <path d="M 38 82 C 30 76, 28 82, 50 82 C 72 82, 70 76, 62 82 Z" fill="currentColor" className="bottom-sand text-violet-600" />
                <line x1="50" y1="46" x2="50" y2="82" stroke="currentColor" strokeWidth="5" strokeLinecap="round" strokeDasharray="4 4" className="animate-sand text-violet-505" />
              </svg>
            </div>
            {/* Hour time (scaled down slightly) */}
            <span className="text-[14.5px] md:text-[16.5px] font-extrabold text-slate-800 tracking-wide">{timePart}</span>
          </div>
        </div>
      );
    }
    return (
      <span className="text-[10px] md:text-[11.5px] font-black uppercase tracking-wider text-slate-655 block text-center max-w-[150px] leading-tight mx-auto">
        {text}
      </span>
    );
  };

    const renderBarraCronologica = (isMobile: boolean) => {
    if (!hasCheckedIn) return null;
    // Check for any deviations in the current turn
    const isLateIn = lateUsers[currentUser.id] || (clockState === 'inactive' && currentSimTime > parseTimeToMins(shiftConfigs[currentUser.id]?.start || '09:00') + 10);
    const breakInfoObj = getBreakInfo();
    const isBreakExceeded = breakInfoObj && breakInfoObj.extra > 0;
    const mealInfoObj = getMealInfo();
    const isMealExceeded = mealInfoObj && !mealInfoObj.isReserved && mealInfoObj.extra > 0;
    
    const hasAnyDeviation = isLateIn || isBreakExceeded || isMealExceeded;

    const tStart = Math.min(
      parseTimeToMins(shiftConfigs[currentUser.id]?.start || '09:00'),
      checkInTimes[currentUser.id] !== undefined ? checkInTimes[currentUser.id] : 99999
    );
    const tEnd = Math.max(
      parseTimeToMins(shiftConfigs[currentUser.id]?.end || '18:00'),
      checkOutTimes[currentUser.id] !== undefined ? checkOutTimes[currentUser.id] : 0,
      currentSimTime
    );

    const tDuration = tEnd - tStart;
    const limitPos = checkOutTimes[currentUser.id] !== undefined ? checkOutTimes[currentUser.id] : currentSimTime;
    const elapsedTotal = limitPos - tStart;

    const eventsList: { start: number; end: number; type: 'break' | 'meal' }[] = [];
    
    // Break time
    const bStart = breakStartTimes[currentUser.id];
    if (bStart !== undefined) {
      const bEnd = breakEndTimes[currentUser.id] !== undefined 
        ? breakEndTimes[currentUser.id] 
        : (clockState === 'short_break' ? currentSimTime : bStart + (leySillaConfig?.breakMinutes || 15));
      eventsList.push({ start: bStart, end: bEnd, type: 'break' });
    }

    // Meal time
    const mStart = mealStartTimes[currentUser.id];
    if (mStart !== undefined) {
      const mEnd = mealEndTimes[currentUser.id] !== undefined 
        ? mealEndTimes[currentUser.id] 
        : (clockState === 'meal' ? currentSimTime : mStart + (shiftConfigs[currentUser.id]?.mealMinutes || 45));
      eventsList.push({ start: mStart, end: mEnd, type: 'meal' });
    }

    // Sort by start minutes
    eventsList.sort((a, b) => a.start - b.start);

    // Build chronological segments
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
      <div className={isMobile ? "py-2 px-1 text-left w-full select-none shrink-0" : "flex flex-col gap-4 w-full text-left py-2 select-none"}>
        {/* Two-Column Status Bar: Store Status (Left) & Employee Shift Status (Right) */}
        <div className={`flex justify-between items-center w-full font-bold uppercase tracking-wider ${isMobile ? 'text-[9.5px] mb-4 px-1' : 'text-[11px] mb-2 tracking-wider'}`}>
          {/* Left: Store status */}
          <div className="flex items-center select-none">
            {storeStatus === 'open' ? (
              <span className="text-emerald-600 dark:text-emerald-450 font-black flex items-center gap-1.5">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                <span>🏪 Sucursal Abierta</span>
              </span>
            ) : (
              <span className="text-rose-600 dark:text-rose-455 font-black flex items-center gap-1.5">
                <span className="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                <span>🔒 Sucursal Cerrada</span>
              </span>
            )}
          </div>

          {/* Right: Employee Shift status */}
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
        
        {/* responsively spaced icons (divided in four equal spaces) */}
        <div className="flex w-full z-10 relative px-0 mb-0 mt-1">
          {/* Entrada Node */}
          {(() => {
            return (
              <div 
                onClick={() => hasCheckedIn && setShowEntryDetailsModal(true)}
                className={`w-1/4 flex flex-col items-center relative transition-all duration-300 transform ${
                  hasCheckedIn ? 'cursor-pointer hover:scale-105' : 'cursor-default'
                }`}
              >
                {/* Upper label */}
                <span className="text-[9px] font-black uppercase tracking-wider mb-0.5 text-indigo-600 dark:text-indigo-400">Entrada</span>
                
                <div className={`rounded-full flex items-center justify-center transition-all border-2 relative shadow-md hover:scale-110 active:scale-95 duration-300 ${
                  isMobile ? 'w-11 h-11' : 'w-12 h-12'
                } ${
                  hasCheckedIn 
                    ? 'border-indigo-500 bg-indigo-500 text-white font-extrabold scale-105 shadow-indigo-500/20' 
                    : 'border-slate-200 bg-white text-slate-400 shadow-sm'
                }`}>
                  <LogIn size={isMobile ? 18 : 20} className={!hasCheckedIn ? "animate-pulse" : ""} />
                  {hasCheckedIn && (
                    isLateIn ? (
                      <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-amber-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm" title="Retardo">⚠️</div>
                    ) : (
                      <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm" title="Puntual">✓</div>
                    )
                  )}
                </div>
              </div>
            );
          })()}

          {/* Descanso Node */}
          {(() => {
            const isDone = (breaksTaken[currentUser.id] || 0) > 0 || breakEndTimes[currentUser.id] !== undefined;
            const isActive = clockState === 'short_break';
            const isAccessible = isBreakDone || isActive;
            return (
              <div 
                onClick={() => setShowBreakDetailsModal(true)}
                className={`w-1/4 flex flex-col items-center relative transition-all duration-300 transform ${
                  isAccessible ? 'cursor-pointer hover:scale-105' : 'cursor-default'
                }`}
              >
                {/* Upper label */}
                <span className="text-[9px] font-black uppercase tracking-wider mb-0.5 text-purple-600 dark:text-purple-400">Descanso</span>

                <div className={`rounded-full flex items-center justify-center transition-all border-2 relative shadow-md hover:scale-110 active:scale-95 duration-300 ${
                  isMobile ? 'w-11 h-11' : 'w-12 h-12'
                } ${
                  isAccessible
                    ? 'border-purple-500 bg-purple-500 text-white font-extrabold scale-105 shadow-purple-500/20' 
                    : 'border-slate-200 bg-white text-slate-400 shadow-sm'
                }`}>
                  <Armchair size={isMobile ? 18 : 20} className={isActive ? "animate-bounce" : (hasCheckedIn && !isBreakDone ? "animate-pulse" : "")} />
                  {isDone && (
                    isBreakExceeded ? (
                      <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-rose-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm" title="Límite excedido">⚠️</div>
                    ) : (
                      <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm" title="Dentro de límite">✓</div>
                    )
                  )}
                </div>
              </div>
            );
          })()}

          {/* Comida Node */}
          {(() => {
            const isDone = hasReservedMeal[currentUser.id] || mealEndTimes[currentUser.id] !== undefined;
            const isActive = clockState === 'meal';
            const isAccessible = isMealDone || isActive;
            return (
              <div 
                onClick={() => setShowMealDetailsModal(true)}
                className={`w-1/4 flex flex-col items-center relative transition-all duration-300 transform ${
                  isAccessible ? 'cursor-pointer hover:scale-105' : 'cursor-default'
                }`}
              >
                {/* Upper label */}
                <span className="text-[9px] font-black uppercase tracking-wider mb-0.5 text-amber-600 dark:text-amber-400">Comida</span>

                <div className={`rounded-full flex items-center justify-center transition-all border-2 relative shadow-md hover:scale-110 active:scale-95 duration-300 focus:outline-none ${
                  isMobile ? 'w-11 h-11' : 'w-12 h-12'
                } ${
                  isAccessible
                    ? 'border-amber-500 bg-amber-500 text-white font-extrabold scale-105 shadow-amber-500/20' 
                    : 'border-slate-200 bg-white text-slate-400 shadow-sm'
                }`}>
                  <Utensils size={isMobile ? 18 : 20} className={isActive ? "animate-bounce" : (hasCheckedIn && !isMealDone ? "animate-pulse" : "")} />
                  {isDone && (
                    isMealExceeded ? (
                      <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-rose-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm" title="Límite excedido">⚠️</div>
                    ) : (
                      <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm" title="Dentro de límite">✓</div>
                    )
                  )}
                </div>
              </div>
            );
          })()}

          {/* Salida Node */}
          {(() => {
            const isDone = hasCheckedOut;
            const isAccessible = hasCheckedOut;
            return (
              <div 
                onClick={() => isAccessible && setShowExitDetailsModal(true)}
                className={`w-1/4 flex flex-col items-center relative transition-all duration-300 transform ${
                  isAccessible ? 'cursor-pointer hover:scale-105' : 'cursor-default'
                }`}
              >
                {/* Upper label */}
                <span className="text-[9px] font-black uppercase tracking-wider mb-0.5 text-emerald-600 dark:text-emerald-400">Salida</span>

                <div className={`rounded-full flex items-center justify-center transition-all border-2 relative shadow-md hover:scale-110 active:scale-95 duration-300 ${
                  isMobile ? 'w-11 h-11' : 'w-12 h-12'
                } ${
                  isAccessible
                    ? 'border-emerald-500 bg-emerald-500 text-white font-extrabold scale-105 shadow-emerald-500/20' 
                    : 'border-slate-200 bg-white text-slate-400 shadow-sm'
                }`}>
                  <LogOut size={isMobile ? 18 : 20} />
                  {isDone && (
                    hasAnyDeviation ? (
                      <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-rose-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm" title="Turno con desvíos">⚠️</div>
                    ) : (
                      <div className="absolute -top-1 -right-1 w-4.5 h-4.5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[9px] font-black shadow-sm" title="Turno excelente">✓</div>
                    )
                  )}
                </div>
              </div>
            );
          })()}
        </div>

        {/* Timeline Bar - Thicker, Larger, and Innovatively Styled (Glassmorphism & Contrast Text) */}
        <div className={`relative w-full z-0 ${isMobile ? 'px-2 mb-0.5 mt-1' : 'px-4 mb-2 mt-1.5'}`}>
          {/* Progress Container (Slimmer size h-5 - Softest styled gray track with bevel shadow) */}
          <div className="relative w-full h-5 bg-slate-50/20 dark:bg-slate-800/10 rounded-2xl border border-slate-200/20 dark:border-slate-700/15 shadow-[inset_0_1.5px_3px_rgba(0,0,0,0.03),_0_1px_2px_rgba(0,0,0,0.02)] overflow-hidden">
            
            {/* Elapsed Proportional Progress Segment Container */}
            {hasCheckedIn && elapsedTotal > 0 && (
              <div 
                className="absolute top-0 left-0 h-full rounded-2xl overflow-hidden flex transition-all duration-700 ease-out"
                style={{ width: `${progressPercent}%` }}
              >
                {segmentsList.map((seg, sIdx) => {
                  const segWidth = (seg.mins / elapsedTotal) * 100;
                  let segColor = 'bg-emerald-500'; // Active Work
                  if (seg.type === 'break') segColor = 'bg-purple-400'; // Rest Day/Seat Break
                  if (seg.type === 'meal') segColor = 'bg-amber-400'; // Meal break (yellow)
                  
                  return (
                    <div 
                      key={sIdx}
                      className={`h-full ${segColor} transition-all duration-300`}
                      style={{ width: `${segWidth}%` }}
                    />
                  );
                })}
              </div>
            )}

            {/* Absolute Overlay for Extremes Timestamps inside the bar */}
            <div className="absolute inset-0 flex justify-between items-center px-3 pointer-events-none z-10 text-[10.5px] font-mono font-bold text-slate-500 dark:text-slate-400">
              {/* Left & Right extremes using uniform slate-500 gray text */}
              <span>
                {hasCheckedIn 
                  ? formatMinsToTimeClean(checkInTimes[currentUser.id]) 
                  : formatStringToTimeClean(shiftConfigs[currentUser.id]?.start || '09:00')
                }
              </span>
              <span>
                {hasCheckedOut 
                  ? formatMinsToTimeClean(checkOutTimes[currentUser.id]) 
                  : formatStringToTimeClean(shiftConfigs[currentUser.id]?.end || '18:00')
                }
              </span>
            </div>
          </div>
        </div>
      </div>
    );
  };


  const renderModuloNotificaciones = (isMobile: boolean) => {
    const isDark = false; // Forced light mode by system policy
    const pendingCount = assignments.filter(a => a.userId === currentUser.id && ['pending', 'in_progress'].includes(a.status)).length;
    const sameMealRes = Object.values(reservedMeals).flat().some(r => r.userId === currentUser.id);
    const needsMealRes = clockState === 'active' && !sameMealRes && hasMealReservation;
    const keyTransfer = pendingKeyTransfers && pendingKeyTransfers.length > 0 ? pendingKeyTransfers[0] : null;
    const needsChecklist = isOpeningPremium && storeStatus === 'open' && openingStatus && Number(currentUser.id) === Number(openingStatus.current_responsible_employee_id) && openingSettings.require_opening_checklist && !openingChecklistCompleted;
    const needsRollCall = isOpeningPremium && storeStatus === 'open' && openingStatus && Number(currentUser.id) === Number(openingStatus.current_responsible_employee_id) && openingSettings.require_opening_roll_call && !openingRollCallCompleted;

    const myRole = (globalRoles || []).find((r: any) => r.id === currentUser?.job_role_id);
    const userPositionName = myRole ? myRole.name : (currentUser?.role === 'admin' ? 'Administrador' : currentUser?.role === 'supervisor' ? 'Supervisor' : 'Colaborador');
    const isSupervisor = currentUser?.role?.toLowerCase()?.includes('sup') || 
                          currentUser?.role?.toLowerCase()?.includes('admin') || 
                          currentUser?.role?.toLowerCase()?.includes('gerente') || 
                          ['Encargado Titular', 'Segundo Encargado', 'Supervisor', 'Administrador / Gerente'].includes(userPositionName);
    const pendingBreakReqs = isSupervisor ? Object.entries(pendingBreakRequests || {}) : [];
    const pm = privateMessages[currentUser.id];
    const bAlerts = buddyAlerts[currentUser.id] || [];

    const notificationsList: any[] = [];

    if (pm) {
      notificationsList.push({
        id: 'admin_pm',
        type: 'error',
        title: 'Administración',
        desc: pm,
        icon: <AlertOctagon className="text-rose-500 w-4 h-4" />
      });
    }

    if (keyTransfer) {
      notificationsList.push({
        id: 'key_transfer',
        type: 'warning',
        title: 'Custodia de Llaves',
        desc: `${keyTransfer.sender?.name} te propuso cederte las llaves de la sucursal.`,
        icon: <Key className="text-amber-505 w-4 h-4" />,
        buttons: [
          { text: 'Aceptar', onClick: () => respondToKeyTransfer(keyTransfer.id, 'accepted'), variant: 'success' },
          { text: 'Rechazar', onClick: () => respondToKeyTransfer(keyTransfer.id, 'danger'), variant: 'secondary' }
        ]
      });
    }

    if (isSupervisor && pendingBreakReqs.length > 0) {
      pendingBreakReqs.forEach(([empId, req]: any) => {
        const emp = globalUsers.find(u => String(u.id) === String(empId));
        if (emp) {
          const empCheckInMins = checkInTimes[emp.id];
          const elapsedMins = empCheckInMins !== undefined ? Math.max(0, currentSimTime - empCheckInMins) : 0;
          notificationsList.push({
            id: `break_req_${empId}`,
            type: 'info',
            title: 'Descanso Ley Silla',
            desc: `${emp.name} solicita descanso (De pie: ${elapsedMins} min).`,
            icon: <Coffee className="text-purple-500 w-4 h-4" />,
            buttons: [
              { text: '✓ Aprobar', onClick: () => approveBreakRequest(emp.id), variant: 'success' },
              { text: '✕ Rechazar', onClick: () => rejectBreakRequest(emp.id), variant: 'danger' }
            ]
          });
        }
      });
    }

    if (needsChecklist) {
      notificationsList.push({
        id: 'opening_checklist',
        type: 'warning',
        title: 'Checklist de Apertura',
        desc: 'Tienes tareas de apertura pendientes.',
        icon: <ClipboardList className="text-amber-500 w-4 h-4" />,
        action: () => setShowOpeningChecklistModal(true),
        actionText: 'Completar'
      });
    }

    if (needsRollCall) {
      notificationsList.push({
        id: 'opening_rollcall',
        type: 'warning',
        title: 'Pase de Lista',
        desc: 'Pase de lista de apertura pendiente.',
        icon: <ClipboardList className="text-amber-500 w-4 h-4" />,
        action: () => initPaseLista(false),
        actionText: 'Iniciar'
      });
    }

    if (bAlerts.length > 0) {
      bAlerts.forEach((ba: any, idx: number) => {
        notificationsList.push({
          id: `buddy_alert_${idx}`,
          type: 'error',
          title: 'Alerta Operativa',
          desc: ba.msg,
          icon: <AlertTriangle className="text-rose-500 w-4 h-4" />
        });
      });
    }

    if (needsMealRes) {
      notificationsList.push({
        id: 'meal_res',
        type: 'warning',
        title: 'Reserva de Comida',
        desc: 'Aparta tu horario de comida hoy.',
        icon: <Utensils className="text-amber-505 w-4 h-4" />,
        action: () => setShowMealReservationModal(true),
        actionText: 'Reservar'
      });
    }

    if (pendingCount > 0) {
      notificationsList.push({
        id: 'tasks_pending',
        type: 'warning',
        title: 'Tareas Pendientes',
        desc: `Tienes ${pendingCount} ${pendingCount === 1 ? 'tarea' : 'tareas'} por realizar hoy.`,
        icon: <CheckSquare className="text-rose-500 w-4 h-4" />,
        action: () => { setInnerTool(null); setPhoneTab('tareas'); },
        actionText: 'Ver tareas'
      });
    } else {
      notificationsList.push({
        id: 'tasks_all_done',
        type: 'success',
        title: 'Tareas al Día',
        desc: 'Todas tus tareas están al día.',
        icon: <CheckSquare className="text-emerald-505 w-4 h-4" />,
        action: () => { setInnerTool(null); setPhoneTab('tareas'); },
        actionText: 'Ir a tareas'
      });
    }

    if (currentUser?.has_completed_induction === false) {
      notificationsList.push({
        id: 'induction_pending',
        type: 'warning',
        title: 'Inducción Pendiente',
        desc: 'Completa tu inducción en la Academia.',
        icon: <GraduationCap className="text-amber-505 w-4 h-4" />,
        action: () => { setInnerTool(null); setPhoneTab('academia'); },
        actionText: 'Completar'
      });
    } else if (pendingCoursesCount > 0) {
      notificationsList.push({
        id: 'courses_pending',
        type: 'warning',
        title: 'Cursos Pendientes',
        desc: `Tienes ${pendingCoursesCount} ${pendingCoursesCount === 1 ? 'curso pendiente' : 'cursos pendientes'} de tu plan.`,
        icon: <GraduationCap className="text-amber-555 w-4 h-4" />,
        action: () => { setInnerTool(null); setPhoneTab('academia'); },
        actionText: 'Estudiar'
      });
    }

    let shiftStatusText = 'Turno programado en progreso.';
    if (clockState === 'inactive') {
      shiftStatusText = `Inicio programado: ${formatStringToTimeClean(shiftConfigs[currentUser.id]?.start || '09:00')}`;
    } else if (clockState === 'active') {
      shiftStatusText = 'Hora sugerida de comida: 02:00 pm.';
    } else if (clockState === 'short_break') {
      shiftStatusText = 'Descanso activo: Regresa en 15 minutos.';
    } else if (clockState === 'meal') {
      shiftStatusText = 'Comida activa: Regresa en 30 minutos.';
    } else if (hasCheckedOut) {
      shiftStatusText = 'Jornada del día completada 🎉';
    }

    notificationsList.push({
      id: 'shift_status',
      type: 'info',
      title: 'Jornada y Horarios',
      desc: shiftStatusText,
      icon: <Clock className="text-blue-500 w-4 h-4" />
    });

    return (
      <div className={`w-full border rounded-2xl p-4 flex flex-col gap-2.5 text-left transition-all ${
        isDark 
          ? 'bg-slate-900/60 border-slate-800 text-white' 
          : 'bg-white/80 border-slate-200/60 text-slate-800 shadow-sm'
      }`}>
        <div className="flex justify-between items-center border-b pb-2 dark:border-slate-800">
          <div className="flex items-center gap-2">
            <span className="relative flex h-2 w-2">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-violet-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-2 w-2 bg-violet-500"></span>
            </span>
            <h5 className="font-extrabold text-[11px] uppercase tracking-wider text-violet-600 dark:text-violet-400">
              Notificaciones de Turno
            </h5>
          </div>
          <span className="text-[9px] text-slate-400 font-bold">
            {notificationsList.length} activa{notificationsList.length !== 1 && 's'}
          </span>
        </div>

        <div className="space-y-2 max-h-[220px] overflow-y-auto pr-0.5 scrollbar-thin">
          {notificationsList.length === 0 ? (
            <p className="text-[11px] text-slate-400 italic text-center py-4">Sin notificaciones de turno.</p>
          ) : (
            notificationsList.map((item) => {
              const bgSeverity = item.type === 'error' ? 'bg-rose-50 border-rose-100 text-rose-800' :
                                item.type === 'warning' ? 'bg-amber-50 border-amber-100 text-amber-800' :
                                item.type === 'success' ? 'bg-emerald-50 border-emerald-100 text-emerald-800' :
                                'bg-blue-50 border-blue-100 text-blue-800';
              
              const isClickable = !!item.action;

              return (
                <div 
                  key={item.id}
                  onClick={() => isClickable && item.action()}
                  className={`flex flex-col gap-2 p-2.5 border rounded-xl transition-all duration-200 text-[11px] leading-tight ${bgSeverity} ${
                    isClickable ? 'cursor-pointer hover:scale-[1.01] hover:border-slate-350 active:scale-[0.99]' : ''
                  }`}
                >
                  <div className="flex items-start gap-2">
                    <span className="p-1 rounded-lg bg-white/80 shrink-0 border border-slate-200/10 shadow-xs">
                      {item.icon}
                    </span>
                    <div className="flex-1 min-w-0">
                      <p className="font-extrabold uppercase text-[9px] tracking-wider opacity-90">{item.title}</p>
                      <p className="font-semibold text-[11px] mt-0.5 opacity-95">{item.desc}</p>
                    </div>
                    {isClickable && item.actionText && (
                      <span className="bg-white/90 font-black text-[8px] uppercase tracking-wider px-2 py-1 rounded-md shrink-0 border border-slate-200/20 shadow-xs hover:bg-white text-slate-750">
                        {item.actionText} →
                      </span>
                    )}
                  </div>

                  {item.buttons && item.buttons.length > 0 && (
                    <div className="flex gap-1.5 justify-end mt-1">
                      {item.buttons.map((btn: any, bIdx: number) => {
                        const btnColor = btn.variant === 'success' ? 'bg-emerald-600 hover:bg-emerald-700 text-white' :
                                         btn.variant === 'danger' ? 'bg-rose-600 hover:bg-rose-700 text-white' :
                                         'bg-slate-200 hover:bg-slate-300 text-slate-700';
                        return (
                          <button
                            key={bIdx}
                            onClick={(e) => { e.stopPropagation(); btn.onClick(); }}
                            className={`font-black text-[9px] uppercase tracking-wider px-2.5 py-1 rounded-lg border-none shadow-xs transition-all active:scale-95 ${btnColor}`}
                          >
                            {btn.text}
                          </button>
                        );
                      })}
                    </div>
                  )}
                </div>
              );
            })
          )}
        </div>
      </div>
    );
  };


  const getDialIcon = (size = 76) => {
    if (btnProps.isIncidenceReport) {
      return <AlertTriangle size={size} className="text-amber-500 animate-pulse shrink-0" />;
    }
    const isRestDay = shiftConfigs[currentUser?.id]?.restDay === currentDay;
    if (isRestDay) {
      return <Sun size={size} className="text-slate-400 shrink-0" />;
    }
    if (clockState === 'active') {
      return <Fingerprint size={size} className="text-emerald-500 shrink-0" />;
    }
    if (clockState === 'short_break') {
      return <Armchair size={size} className="text-purple-500 animate-pulse shrink-0" />;
    }
    if (clockState === 'meal') {
      return <Utensils size={size} className="text-amber-500 animate-pulse shrink-0" />;
    }
    if (clockState === 'finished') {
      return <CheckCircle size={size} className="text-teal-500 shrink-0" />;
    }
    if (clockState === 'absent') {
      return <AlertCircle size={size} className="text-rose-500 shrink-0" />;
    }
    
    if (isOpeningPremium && storeStatus === 'closed') {
      const responsibleId = openingStatus ? openingStatus.current_responsible_employee_id : 1;
      if (Number(currentUser?.id) === Number(responsibleId)) {
        return <Store size={size} className="text-violet-505 animate-pulse shrink-0" />;
      }
      return <Hourglass size={size} className="text-slate-400 animate-pulse shrink-0" />;
    }

    if (clockState === 'inactive' && storeStatus === 'open') {
      return <Fingerprint size={size} className="text-blue-500 animate-pulse shrink-0" />;
    }

    return <Fingerprint size={size} className="text-slate-300 shrink-0" />;
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

  const [isFabOpen, setIsFabOpen] = useState(false);

  const isEmployeeView = window.location.pathname === '/empleado';
  const [windowWidth, setWindowWidth] = useState(window.innerWidth);

  useEffect(() => {
    const handleResize = () => setWindowWidth(window.innerWidth);
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  const isMobileScreen = windowWidth < 768;
  const isScrollableMobile = isMobileScreen || isMobileFrame;
  const [isProfileMenuOpen, setIsProfileMenuOpen] = useState(false);
  const [showProfileMenu, setShowProfileMenu] = useState(false);
  const profileMenuRef = useRef<HTMLDivElement>(null);
  
  const [showSettingsModal, setShowSettingsModal] = useState(false);
  const [showEtaSelector, setShowEtaSelector] = useState(false);
  const [etaTime, setEtaTime] = useState('08:45');
  const [showOpeningChecklistModal, setShowOpeningChecklistModal] = useState(false);
  const [showDesktopOrgModal, setShowDesktopOrgModal] = useState(false);
  const [editUsername, setEditUsername] = useState('');
  const [editPassword, setEditPassword] = useState('');
  const [editRestDay, setEditRestDay] = useState('');
  const [isSessionLocked, setIsSessionLocked] = useState(false);
  const [unlockPin, setUnlockPin] = useState('');
  const [isUploadingAvatar, setIsUploadingAvatar] = useState(false);
  const avatarFileInputRef = useRef<HTMLInputElement>(null);

  const handleAvatarFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setIsUploadingAvatar(true);
    const formData = new FormData();
    formData.append('avatar', file);

    try {
      const res = await axiosInstance.post('/me/upload-avatar', formData, {
        headers: {
          'Content-Type': 'multipart/form-data'
        }
      });
      if (res.data && res.data.avatar_url) {
        const avatarUrl = res.data.avatar_url;
        const updatedUser = { ...currentUser, avatar: avatarUrl };
        setCurrentUser(updatedUser);
        setGlobalUsers(globalUsers.map(u => u.id === currentUser.id ? updatedUser : u));
        showCustomAlert("📸 Foto de perfil subida exitosamente.");
      }
    } catch (err: any) {
      console.error("Error al subir avatar:", err);
      showCustomAlert(err.response?.data?.error || "Error al subir la imagen. Intenta con un archivo más ligero (máx 5MB).");
    } finally {
      setIsUploadingAvatar(false);
    }
  };

  // Estados interactivos para el Perfil de Usuario y PWA
  const [showQrModal, setShowQrModal] = useState(false);
  const [appLanguage, setAppLanguage] = useState('es');
  const [notifShift, setNotifShift] = useState(true);
  const [notifMeal, setNotifMeal] = useState(true);
  const [biometricEnabled, setBiometricEnabled] = useState(false);
  const [twoFactorEnabled, setTwoFactorEnabled] = useState(false);
  const [pwaInstallPrompt, setPwaInstallPrompt] = useState<any>(null);
  const [showPwaBanner, setShowPwaBanner] = useState(false);
  const [showMealDetailsModal, setShowMealDetailsModal] = useState(false);
  const [pendingSwapPartner, setPendingSwapPartner] = useState<any>(null);
  const [isSwappingLoading, setIsSwappingLoading] = useState<boolean>(false);
  const [showEntryDetailsModal, setShowEntryDetailsModal] = useState(false);
  const [showBreakDetailsModal, setShowBreakDetailsModal] = useState(false);
  const [showExitDetailsModal, setShowExitDetailsModal] = useState(false);

  // Time format helper showing full hh:mm format
  const formatMinsToTimeClean = (mins: number) => {
    if (mins === undefined || mins === null) return '--:--';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    const ampmStr = h >= 12 ? 'pm' : 'am';
    const displayH = h > 12 ? h - 12 : h === 0 ? 12 : h;
    return `${displayH}:${m.toString().padStart(2, '0')} ${ampmStr}`;
  };

  const formatStringToTimeClean = (timeStr: string) => {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    const h = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10) || 0;
    const ampmStr = h >= 12 ? 'pm' : 'am';
    const displayH = h > 12 ? h - 12 : h === 0 ? 12 : h;
    return `${displayH}:${m.toString().padStart(2, '0')} ${ampmStr}`;
  };

  const renderBreakApprovalBanner = () => {
    const myRole = (globalRoles || []).find((r: any) => r.id === currentUser?.job_role_id);
    const userPositionName = myRole ? myRole.name : (currentUser?.role === 'admin' ? 'Administrador' : currentUser?.role === 'supervisor' ? 'Supervisor' : 'Colaborador');
    const isSupervisor = currentUser?.role?.toLowerCase()?.includes('sup') || 
                          currentUser?.role?.toLowerCase()?.includes('admin') || 
                          currentUser?.role?.toLowerCase()?.includes('gerente') || 
                          ['Encargado Titular', 'Segundo Encargado', 'Supervisor', 'Administrador / Gerente'].includes(userPositionName);

    const pendingRequests = pendingBreakRequests || {};
    const pendingCount = Object.keys(pendingRequests).length;

    if (!isSupervisor || pendingCount === 0) return null;

    return (
      <div className="bg-purple-650 text-white rounded-2xl p-4 shadow-lg mb-3 flex flex-col gap-3 text-left border border-purple-500/20">
        <div className="flex items-center gap-2">
          <span className="text-xl">🧘</span>
          <div>
            <p className="font-black text-xs sm:text-sm">Solicitudes de Descanso Pendientes (Ley Silla)</p>
            <p className="text-[9px] sm:text-[10px] text-purple-100 opacity-90 leading-tight">Colaboradores solicitando descanso activo</p>
          </div>
        </div>
        <div className="space-y-2 max-h-40 overflow-y-auto pr-1">
          {Object.entries(pendingRequests).map(([empId, req]: any) => {
            const emp = globalUsers.find(u => String(u.id) === String(empId));
            if (!emp) return null;
            
            const empCheckInMins = checkInTimes[emp.id];
            const elapsedMins = empCheckInMins !== undefined ? Math.max(0, currentSimTime - empCheckInMins) : 0;
            
            return (
              <div key={empId} className="bg-purple-700/40 border border-purple-500/30 rounded-xl p-2.5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2.5">
                <div className="flex flex-col text-left">
                  <span className="text-xs font-black text-white">{emp.name}</span>
                  <span className="text-[10px] text-purple-100">Trabajado: <strong className="text-white font-bold">{elapsedMins} min</strong> de pie hoy</span>
                </div>
                <div className="flex gap-2 shrink-0 w-full sm:w-auto justify-end">
                  <button 
                    onClick={() => approveBreakRequest(emp.id)}
                    className="bg-emerald-500 hover:bg-emerald-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 flex items-center gap-1"
                  >
                    ✓ Aprobar
                  </button>
                  <button 
                    onClick={() => rejectBreakRequest(emp.id)}
                    className="bg-rose-500 hover:bg-rose-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 flex items-center gap-1"
                  >
                    ✕ Rechazar
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    );
  };

  const getUserKeysIcon = (userId: number) => {
    try {
      const isSandbox = useAppStore.getState().isSandboxMode;
      const savedAss = localStorage.getItem('store_opening_assignments');
      const assignments = savedAss ? JSON.parse(savedAss) : (
        isSandbox ? [
          { id: 1, employee_id: 1, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
          { id: 2, employee_id: 2, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
          { id: 3, employee_id: 3, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
        ] : [
          { id: 11, employee_id: 11, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
          { id: 12, employee_id: 12, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
          { id: 13, employee_id: 13, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
        ]
      );
      const match = assignments.find((a: any) => Number(a.employee_id) === Number(userId) && a.is_active && a.can_open_store);
      if (match) {
        return match.priority_order === 1 ? ' 🔑' : ' 🔑🔑';
      }
    } catch {}
    return '';
  };

  useEffect(() => {
    const handleBeforeInstallPrompt = (e: Event) => {
      e.preventDefault();
      setPwaInstallPrompt(e);
      
      const isInstalled = localStorage.getItem('pwa_installed') === 'true';
      const isDismissed = sessionStorage.getItem('pwa_dismissed') === 'true';
      if (!isInstalled && !isDismissed) {
        setShowPwaBanner(true);
      }
    };
    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    return () => window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
  }, []);

  useEffect(() => {
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
    if (isStandalone) {
      localStorage.setItem('pwa_installed', 'true');
      setShowPwaBanner(false);
      return;
    }

    const isInstalled = localStorage.getItem('pwa_installed') === 'true';
    const isDismissed = sessionStorage.getItem('pwa_dismissed') === 'true';
    if (isInstalled || isDismissed) {
      setShowPwaBanner(false);
      return;
    }
    
    const timer = setTimeout(() => {
      setShowPwaBanner(true);
    }, 1500);
    return () => clearTimeout(timer);
  }, []);

  useEffect(() => {
    const handleAppInstalled = () => {
      localStorage.setItem('pwa_installed', 'true');
      setShowPwaBanner(false);
    };
    window.addEventListener('appinstalled', handleAppInstalled);
    return () => window.removeEventListener('appinstalled', handleAppInstalled);
  }, []);

  const handlePwaInstall = async () => {
    if (!pwaInstallPrompt) {
      showCustomAlert('ℹ️ Para instalar Talent 360 en tu computadora:\n\n1. Haz clic en el ícono de instalación (un monitor con una flecha o "+") en la barra de direcciones de tu navegador.\n2. O abre el menú del navegador y selecciona "Instalar Talent 360".');
      localStorage.setItem('pwa_installed', 'true');
      setShowPwaBanner(false);
      return;
    }
    try {
      pwaInstallPrompt.prompt();
      const { outcome } = await pwaInstallPrompt.userChoice;
      console.log(`PWA install outcome: ${outcome}`);
      if (outcome === 'accepted') {
        localStorage.setItem('pwa_installed', 'true');
      }
      setPwaInstallPrompt(null);
      setShowPwaBanner(false);
    } catch (err) {
      console.error('Error prompting PWA installation:', err);
    }
  };

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (profileMenuRef.current && !profileMenuRef.current.contains(event.target as Node)) {
        setIsProfileMenuOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  // Resolve user official job title from globalRoles based on job_role_id
  const myRole = (globalRoles || []).find((r: any) => r.id === currentUser?.job_role_id);
  const userPositionName = myRole ? myRole.name : (currentUser?.role === 'admin' ? 'Administrador' : currentUser?.role === 'supervisor' ? 'Supervisor' : 'Colaborador');

  useEffect(() => {
    if (currentUser) {
      setBiometricEnabled(!!currentUser.biometric_key);
      setTwoFactorEnabled(!!currentUser.two_factor_enabled);
    }
  }, [currentUser]);

  const { assignments, tasks, startTask, completeTask, grabTaskFromPool } = useTaskStore();

  const [openingAssignments, setOpeningAssignments] = useState<any[]>([]);
  const [loadingAssignments, setLoadingAssignments] = useState(false);

  const fetchOpeningAssignments = async () => {
    setLoadingAssignments(true);
    try {
      const res = await axiosInstance.get('/task-assignments');
      setOpeningAssignments(res.data || []);
    } catch (e) {
      console.error("Error fetching opening assignments", e);
    } finally {
      setLoadingAssignments(false);
    }
  };

  useEffect(() => {
    if (showOpeningChecklistModal) {
      fetchOpeningAssignments();
    }
  }, [showOpeningChecklistModal]);

  useEffect(() => {
    if (storeStatus === 'open' && currentUser) {
      axiosInstance.get('/task-assignments')
        .then(res => {
          const assignmentsData = res.data || [];
          if (assignmentsData.length > 0) {
            const allDone = assignmentsData.every((a: any) => a.status === 'completed' || a.status === 'awaiting_validation' || a.status === 'omitted');
            if (allDone) {
              setOpeningChecklistCompleted(true);
              localStorage.setItem('opening_checklist_completed', 'true');
            } else {
              setOpeningChecklistCompleted(false);
              localStorage.removeItem('opening_checklist_completed');
            }
          }
        })
        .catch(err => console.error("Error checking tasks on check-in", err));
    }
  }, [storeStatus, currentUser?.id]);

  // Mode detections
  const isDark = false; // Forzado a modo claro por políticas globales de la plataforma
 
  const renderGPSView = (dialSize = 76, isMobile = true) => {
    const clockOpConfig = systemSettings?.clockOpConfig || {};
    const isGpsBypassed = clockOpConfig.gpsValidationEnabled === false || !!clockOpConfig.allowManualCheckIn;
    if (isGpsBypassed) {
      return null;
    }

    if (gpsStatus === 'seeking') {
      return (
        <div className="flex flex-col items-center justify-center p-8 text-center animate-pulse min-h-[220px]">
          <div className="w-14 h-14 bg-violet-100 dark:bg-violet-955/20 rounded-full flex items-center justify-center text-violet-600 dark:text-violet-400 mb-4 shadow-sm">
            <Clock size={28} className="animate-spin" />
          </div>
          <p className="text-xs font-bold text-slate-600 dark:text-slate-400">Buscando señal GPS de alta precisión...</p>
        </div>
      );
    }
 
    if (gpsStatus === 'error') {
      return (
        <div className={`bg-rose-500/10 border-2 border-rose-500/20 rounded-3xl p-5 text-center max-w-sm mx-auto mb-4 animate-in fade-in zoom-in-95 backdrop-blur-sm shadow-xl`}>
          <div className="w-12 h-12 bg-rose-500/15 rounded-full flex items-center justify-center text-rose-500 mx-auto mb-3 shadow-inner">
            <AlertTriangle size={24} className="animate-bounce" />
          </div>
          <h3 className="text-xs font-black text-rose-600 dark:text-rose-400 uppercase tracking-widest mb-1.5">Permiso de GPS Requerido</h3>
          <p className="text-[11px] text-slate-600 dark:text-slate-400 mb-4 leading-relaxed">
            Para cumplir con las normas del SAT 2027 y validar tu perímetro de trabajo en DecorArte, es obligatorio activar la ubicación.
          </p>
          
          <div className="text-left bg-white/60 dark:bg-slate-900/60 border border-slate-100 dark:border-slate-800/80 rounded-2xl p-3.5 text-[10.5px] space-y-2.5 mb-4 shadow-sm">
            <p className="font-bold text-slate-700 dark:text-slate-350 font-sans">¿Cómo dar permiso de ubicación?</p>
            <div className="flex gap-2 text-slate-600 dark:text-slate-400 font-sans">
              <span className="font-black text-violet-600">1.</span>
              <span>Haz clic en el candado 🔒 (junto a la URL del navegador).</span>
            </div>
            <div className="flex gap-2 text-slate-600 dark:text-slate-400 font-sans">
              <span className="font-black text-violet-600">2.</span>
              <span>Cambia el permiso de <strong>Ubicación</strong> a <strong>Permitir</strong>.</span>
            </div>
            <div className="flex gap-2 text-slate-600 dark:text-slate-400 font-sans">
              <span className="font-black text-violet-600">3.</span>
              <span>Presiona reintentar abajo.</span>
            </div>
          </div>
          
          <div className="flex flex-col gap-2">
            <button 
              type="button"
              onClick={requestGPS}
              className="w-full py-2.5 bg-violet-600 hover:bg-violet-750 text-white font-bold text-xs uppercase tracking-wider rounded-xl shadow-md transition-all active:scale-95 border-none outline-none cursor-pointer"
            >
              Reintentar Solicitar GPS
            </button>
          </div>
        </div>
      );
    }
 
    return null;
  };

  // Helper format methods
  const formatMinsToTime = (mins: number) => {
    if (mins === undefined || mins === null) return '--:--';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    const ampmStr = h >= 12 ? 'pm' : 'am';
    const displayH = h > 12 ? h - 12 : h === 0 ? 12 : h;
    return `${displayH.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')} ${ampmStr}`;
  };

  // State detection
  const hasCheckedIn = checkInTimes[currentUser.id] !== undefined && checkInTimes[currentUser.id] !== null;
  const hasCheckedOut = hasCheckedIn && clockState === 'inactive';

  // Math metrics for timeline
  const shiftStartMins = parseTimeToMins(shiftConfigs[currentUser.id]?.start || '09:00');
  const shiftEndMins = parseTimeToMins(shiftConfigs[currentUser.id]?.end || '18:00');
  const shiftDuration = shiftEndMins - shiftStartMins;
  const checkInMins = checkInTimes[currentUser.id] || shiftStartMins;
  const elapsedMins = hasCheckedOut ? shiftDuration : Math.max(0, currentSimTime - checkInMins);
  
  // Discrete progress percentage matching the landing page design steps
  const isBreakDone = (breaksTaken[currentUser.id] || 0) > 0 || breakEndTimes[currentUser.id] !== undefined;
  const isMealDone = hasReservedMeal[currentUser.id] || mealEndTimes[currentUser.id] !== undefined;
  const progressPercent = hasCheckedOut ? 100 : 
                          !hasCheckedIn ? 0 : 
                          (clockState === 'meal' || isMealDone) ? 75 : 
                          (clockState === 'short_break' || isBreakDone) ? 50 : 25;
                          
  const workedHours = (elapsedMins / 60).toFixed(1);
  const shiftHours = (shiftDuration / 60).toFixed(1);

  // Desfase Comida computation
  const allowedMealMins = shiftConfigs[currentUser.id]?.mealMinutes || timeBankConfigs.mealMinutes || 45;
  const activeTimer = activeTimers[currentUser.id];
  const stats = calculateDailyStats(currentUser, currentDay);
  const isMealExceeded = clockState === 'meal' && activeTimer && (currentSimTime - activeTimer.startSimTime > allowedMealMins);
  const isMealExceededHist = stats.mealTaken > allowedMealMins;
  const hasMealDesfase = isMealExceeded || isMealExceededHist;

  // Chronological visibility helpers
  const isEntradaNodeVisible = hasCheckedIn;
  const isDescansoNodeVisible = hasCheckedIn && (breakStartTimes[currentUser.id] !== undefined || clockState === 'short_break');
  const isComidaNodeVisible = hasCheckedIn && (mealStartTimes[currentUser.id] !== undefined || hasReservedMeal[currentUser.id] || clockState === 'meal');
  const isSalidaNodeVisible = hasCheckedOut;

  const getBreakInfo = () => {
    const start = breakStartTimes[currentUser.id];
    const end = breakEndTimes[currentUser.id];
    const isActive = clockState === 'short_break';
    const limit = leySillaConfig?.breakMinutes || 15;
    
    if (start === undefined) return null;
    
    let duration = 0;
    if (isActive) {
      duration = Math.max(0, currentSimTime - start);
    } else if (end !== undefined) {
      duration = Math.max(0, end - start);
    } else {
      duration = limit; // default fallback
    }
    
    const extra = duration - limit;
    return {
      start,
      end,
      isActive,
      duration,
      extra: extra > 0 ? extra : 0
    };
  };

  const getMealInfo = () => {
    const start = mealStartTimes[currentUser.id];
    const end = mealEndTimes[currentUser.id];
    const isActive = clockState === 'meal';
    const limit = shiftConfigs[currentUser.id]?.mealMinutes || timeBankConfigs.mealMinutes || 45;
    const isReserved = hasReservedMeal[currentUser.id];
    const reservedSlots = userReservedMealSlots[currentUser.id] || [];
    
    if (start === undefined) {
      if (isReserved) {
        return { isReserved: true, reservedText: reservedSlots.join('-') };
      }
      return null;
    }
    
    let duration = 0;
    if (isActive) {
      duration = Math.max(0, currentSimTime - start);
    } else if (end !== undefined) {
      duration = Math.max(0, end - start);
    } else {
      duration = limit; // default fallback
    }
    
    const extra = duration - limit;
    return {
      isReserved: false,
      start,
      end,
      isActive,
      duration,
      extra: extra > 0 ? extra : 0
    };
  };

  const getActiveTabHeader = () => {
    switch (phoneTab) {
      case 'checador':
        return {
          title: 'Reloj Checador 2',
          desc: 'Control de Asistencia (V2)',
          icon: <Clock className="w-8 h-8 text-emerald-500" />,
          badge: storeStatus === 'open' ? 'SUCURSAL ACTIVA' : 'SUCURSAL INACTIVA'
        };
      case 'tareas':
        return {
          title: 'Tareas y Rutinas',
          desc: 'Gestión y seguimiento de asignaciones operativas',
          icon: <ListTodo className="w-8 h-8 text-indigo-500" />,
          badge: null
        };
      case 'academia':
        return {
          title: 'Academia Talent 360',
          desc: 'Entrenamientos, inducción y desarrollo profesional',
          icon: <GraduationCap className="w-8 h-8 text-violet-500 animate-bounce" />,
          badge: null
        };
      case 'herramientas':
        return {
          title: 'Caja de Herramientas',
          desc: 'Simulador de eventos, CCTV y bitácoras operativas',
          icon: <Settings className="w-8 h-8 text-slate-500 animate-spin" style={{ animationDuration: '6s' }} />,
          badge: null
        };
      case 'perfil':
        return {
          title: 'Mi Perfil & Ajustes',
          desc: 'Credencial digital, historial de asistencia y configuración local',
          icon: <ClipboardList className="w-8 h-8 text-blue-500" />,
          badge: null
        };
      default:
        return {
          title: 'Reloj Checador 2',
          desc: 'Control de Asistencia (V2)',
          icon: <Clock className="w-8 h-8 text-emerald-500" />,
          badge: null
        };
    }
  };

  // Render unified profile panel function
  const renderProfilePanel = (isMobile: boolean) => {
    return (
      <div className={`flex flex-col gap-4 text-left ${isMobile ? 'h-full overflow-y-auto pb-8' : ''}`}>
        {isMobile && (
          <div className={`rounded-3xl p-5 border text-left transition-colors ${
            isDark ? 'bg-slate-900/40 border-slate-900' : 'bg-white border-slate-200 shadow-sm'
          }`}>
            <h4 className="font-extrabold text-[10px] text-slate-400 uppercase tracking-widest mb-3.5 flex items-center gap-1.5">
              <span>🚀</span> Menú Principal
            </h4>
            <div className="grid grid-cols-2 gap-3 text-left">
              <button 
                onClick={() => { setInnerTool(null); setPhoneTab('checador'); }}
                className={`p-3 rounded-xl border flex flex-col items-center justify-center gap-1 transition-all active:scale-95 ${
                  isDark ? 'bg-slate-950/40 border-slate-900 hover:bg-slate-900/30 text-slate-200' : 'bg-slate-50 border-slate-205 hover:bg-slate-100 text-slate-700'
                }`}
              >
                <Clock size={20} className="text-violet-500" />
                <span className="text-[10px] font-black uppercase tracking-wider mt-1">Reloj</span>
              </button>
              
              <button 
                onClick={() => { setInnerTool(null); setPhoneTab('tareas'); }}
                className={`p-3 rounded-xl border flex flex-col items-center justify-center gap-1 transition-all active:scale-95 ${
                  isDark ? 'bg-slate-955/40 border-slate-900 hover:bg-slate-900/30 text-slate-200' : 'bg-slate-50 border-slate-205 hover:bg-slate-100 text-slate-700'
                }`}
              >
                <ListTodo size={20} className="text-emerald-500" />
                <span className="text-[10px] font-black uppercase tracking-wider mt-1">Tareas</span>
              </button>

              <button 
                onClick={() => { setInnerTool(null); setPhoneTab('academia'); }}
                className={`p-3 rounded-xl border flex flex-col items-center justify-center gap-1 transition-all active:scale-95 ${
                  isDark ? 'bg-slate-955/40 border-slate-900 hover:bg-slate-900/30 text-slate-200' : 'bg-slate-50 border-slate-205 hover:bg-slate-100 text-slate-700'
                }`}
              >
                <GraduationCap size={20} className="text-indigo-500" />
                <span className="text-[10px] font-black uppercase tracking-wider mt-1">Academia</span>
              </button>

              <button 
                onClick={() => { setInnerTool(null); setPhoneTab('herramientas'); }}
                className={`p-3 rounded-xl border flex flex-col items-center justify-center gap-1 transition-all active:scale-95 ${
                  isDark ? 'bg-slate-955/40 border-slate-900 hover:bg-slate-900/30 text-slate-200' : 'bg-slate-50 border-slate-205 hover:bg-slate-100 text-slate-700'
                }`}
              >
                <Settings size={20} className="text-amber-500" />
                <span className="text-[10px] font-black uppercase tracking-wider mt-1">Herramientas</span>
              </button>
            </div>
          </div>
        )}
        
        {/* 1. DIGITAL ID CARD */}
        <div className={`rounded-3xl p-5 border relative overflow-hidden transition-colors ${
          isDark 
            ? 'bg-slate-900/40 border-slate-900 text-white shadow-xl' 
            : 'bg-white border-slate-200 shadow-md text-slate-800'
        }`}>
          <div className="absolute top-0 right-0 w-24 h-24 bg-violet-500/5 rounded-bl-[80px]"></div>
          
          <div className="flex items-center gap-4 text-left">
            <img src={currentUser?.avatar || "https://i.pravatar.cc/150?img=11"} alt="Avatar" className="w-14 h-14 rounded-full border-2 border-violet-500/50 object-cover shrink-0" />
            <div>
              <h4 className="font-black text-sm leading-tight text-slate-900 dark:text-slate-100">{currentUser?.name}{getUserKeysIcon(currentUser?.id)}</h4>
              <span className="bg-violet-500/10 text-violet-500 text-[10px] font-black px-2.5 py-0.5 rounded-full capitalize border border-violet-500/20 mt-1.5 inline-block">
                {userPositionName}
              </span>
              <p className="text-[10px] text-slate-500 mt-1 font-mono">
                ID: {currentUser?.employee_id || `EMP-${currentUser?.id.toString().padStart(4, '0')}`}
              </p>
            </div>
          </div>

          {/* Barcode representation */}
          <div className={`p-2.5 rounded-xl border flex flex-col items-center justify-center gap-1 mt-4 ${
            isDark ? 'bg-slate-950/60 border-slate-900' : 'bg-slate-50 border-slate-200'
          }`}>
            <div className="w-full h-8 rounded opacity-80 flex items-center justify-around px-2 py-1 select-none bg-slate-900">
              {Array.from({ length: 24 }).map((_, i) => (
                <div 
                  key={i} 
                  className="bg-slate-450" 
                  style={{ 
                    width: `${(i % 3 === 0 ? 3 : i % 2 === 0 ? 1 : 2)}px`, 
                    height: '100%',
                    opacity: i % 5 === 0 ? 0.3 : 0.9
                  }} 
                />
              ))}
            </div>
            <span className="text-[9px] font-mono text-slate-500 tracking-[0.25em]">TALENT360-SECURE</span>
          </div>
        </div>

        {/* 2. SHIFT DETAILS */}
        <div className={`rounded-3xl p-5 border text-left transition-colors ${
          isDark ? 'bg-slate-900/40 border-slate-900' : 'bg-white border-slate-200 shadow-sm'
        }`}>
          <h4 className="font-extrabold text-[10px] text-slate-400 uppercase tracking-widest mb-3.5 flex items-center gap-1.5">
            <span>🗓️</span> Horarios y Turno
          </h4>
          <div className="grid grid-cols-2 gap-3 text-left">
            <div className={`p-3 rounded-xl border ${isDark ? 'bg-slate-950/40 border-slate-900' : 'bg-slate-50 border-slate-200'}`}>
              <p className="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Entrada</p>
              <p className="font-black text-xs text-slate-700 dark:text-slate-200 mt-0.5 font-mono">{shiftConfigs[currentUser?.id]?.start || '09:00'}</p>
            </div>
            <div className={`p-3 rounded-xl border ${isDark ? 'bg-slate-955/40 border-slate-900' : 'bg-slate-50 border-slate-200'}`}>
              <p className="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Salida</p>
              <p className="font-black text-xs text-slate-700 dark:text-slate-200 mt-0.5 font-mono">{shiftConfigs[currentUser?.id]?.end || '18:00'}</p>
            </div>
            <div className={`p-3 rounded-xl border ${isDark ? 'bg-slate-955/40 border-slate-900' : 'bg-slate-50 border-slate-200'}`}>
              <p className="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Día Descanso</p>
              <p className="font-black text-xs text-violet-500 mt-0.5 uppercase">{shiftConfigs[currentUser?.id]?.restDay || 'Domingo'}</p>
            </div>
            <div className={`p-3 rounded-xl border ${isDark ? 'bg-slate-955/40 border-slate-900' : 'bg-slate-50 border-slate-200'}`}>
              <p className="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Comida</p>
              <p className="font-black text-xs text-slate-700 dark:text-slate-200 mt-0.5 font-mono">{shiftConfigs[currentUser?.id]?.mealMinutes || 45} min</p>
            </div>
          </div>

          <div className={`mt-3.5 p-3 rounded-xl border flex items-center justify-between ${isDark ? 'bg-slate-950/40 border-slate-900' : 'bg-slate-50 border-slate-200'}`}>
            <div>
              <p className="text-[11px] font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1.5">🔑 Jerarquía de Llaves</p>
              <p className="text-[10px] text-slate-500 leading-tight">Acceso para apertura/cierre de tienda</p>
            </div>
            <span className={`text-[9px] font-black px-2.5 py-1 rounded-lg uppercase ${
              shiftConfigs[currentUser?.id]?.portadorLlaves !== 'ninguno' 
                ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' 
                : 'bg-slate-800 text-slate-400 border border-slate-700'
            }`}>
              {shiftConfigs[currentUser?.id]?.portadorLlaves || 'Ninguno'}
            </span>
          </div>
        </div>

        {/* 3. SETTINGS & LOCAL POLICIES */}
        <div className={`rounded-3xl p-5 border text-left transition-colors ${
          isDark ? 'bg-slate-900/40 border-slate-900' : 'bg-white border-slate-200 shadow-sm'
        }`}>
          <h4 className="font-extrabold text-[10px] text-slate-400 uppercase tracking-widest mb-3.5 flex items-center gap-1.5">
            <span>⚙️</span> Ajustes de Estación
          </h4>
          
          <div className="space-y-4">
            <div>
              <label className="block text-[9px] font-bold uppercase text-slate-500 mb-1.5">Tema de Aplicación</label>
              <div className="flex rounded-lg p-1 border bg-slate-100 border-slate-200">
                <button 
                  type="button"
                  className="flex-1 py-1.5 text-[10px] font-black rounded-md bg-white text-slate-800 shadow-sm"
                  disabled
                >
                  ☀️ Claro (Forzado)
                </button>
                <button 
                  type="button"
                  className="flex-1 py-1.5 text-[10px] font-bold rounded-md text-slate-400 opacity-50 cursor-not-allowed"
                  disabled
                >
                  🌙 Oscuro (Deshabilitado)
                </button>
              </div>
              <p className="text-[9px] text-slate-500 mt-1 font-bold">Modo oscuro deshabilitado por políticas globales de Talent 360</p>
            </div>

            <div>
              <label className="block text-[9px] font-bold uppercase text-slate-500 mb-1.5">Validaciones de Asistencia</label>
              <div className={`space-y-2 p-3 rounded-xl border text-[11px] ${isDark ? 'bg-slate-950/40 border-slate-905' : 'bg-slate-50 border-slate-200'}`}>
                <div className="flex justify-between items-center">
                  <span className="text-slate-500">📍 Geolocalización (GPS):</span>
                  <span className={`font-bold ${isFeatureUnlocked('gps_validation') ? 'text-emerald-450' : 'text-slate-400'}`}>
                    {isFeatureUnlocked('gps_validation') ? 'Activo (Geocerca)' : 'Desactivado'}
                  </span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-slate-500">📷 Validación Facial (Selfie):</span>
                  <span className={`font-bold ${isFeatureUnlocked('face_validation') ? 'text-emerald-455' : 'text-slate-400'}`}>
                    {isFeatureUnlocked('face_validation') ? 'Obligatoria' : 'Desactivado'}
                  </span>
                </div>
              </div>
            </div>

            <div>
              <button
                type="button"
                onClick={() => setShowAlarmSettingsModal(true)}
                className={`w-full py-2.5 px-4 rounded-xl border font-bold text-[10px] uppercase tracking-wider transition-all cursor-pointer flex items-center justify-center gap-1.5 ${
                  isDark
                    ? 'bg-slate-900 border-slate-800 text-slate-200 hover:bg-slate-800'
                    : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                }`}
              >
                <span>🔔</span> Ajustes de Alarmas y Alertas
              </button>
            </div>

            <button
              onClick={() => {
                localStorage.removeItem('talent_auth_token');
                window.location.href = '/login';
              }}
              className="w-full bg-rose-600 hover:bg-rose-700 text-white font-extrabold py-3.5 rounded-xl text-xs uppercase tracking-wider text-center mt-2 focus:outline-none transition-colors shadow-lg shadow-rose-600/10"
            >
              🚪 Cerrar Sesión del Dispositivo
            </button>
          </div>
        </div>
      </div>
    );
  };

  // Estados para el botón flotante, la ventana emergente de operaciones, copiloto y creador de tareas
  const [isFabSheetOpen, setIsFabSheetOpen] = useState(false);
  const [isTaskCreatorOpen, setIsTaskCreatorOpen] = useState(false);
  const [isCopilotChatOpen, setIsCopilotChatOpen] = useState(false);
  const [newTaskTitle, setNewTaskTitle] = useState('');
  const [newTaskMins, setNewTaskMins] = useState(15);
  const [newTaskPriority, setNewTaskPriority] = useState<'normal' | 'bloqueante'>('normal');
  const [newTaskRoleTarget, setNewTaskRoleTarget] = useState(currentUser?.role_id || 6);

  // Estado del chat local del Copiloto AI
  const [copilotMessages, setCopilotMessages] = useState([
    {
      sender: 'bot',
      text: '¡Hola! Soy tu Copiloto de Soporte de Talent 360. 🤖 ¿En qué puedo ayudarte hoy? Puedo resolver dudas sobre el Reloj Checador V2, geolocalización, sincronización offline, planes de suscripción o la facturación CFDI 4.0.',
      timestamp: new Date()
    }
  ]);
  const [copilotInput, setCopilotInput] = useState('');
  const [copilotLoading, setCopilotLoading] = useState(false);
  const copilotEndRef = useRef<HTMLDivElement>(null);

  // Auto-scroll del chat del copiloto
  useEffect(() => {
    if (isCopilotChatOpen && copilotEndRef.current) {
      copilotEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [copilotMessages, isCopilotChatOpen]);

  const handleSendCopilot = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!copilotInput.trim() || copilotLoading) return;

    const userMsg = copilotInput.trim();
    setCopilotInput('');
    setCopilotMessages(prev => [...prev, { sender: 'user', text: userMsg, timestamp: new Date() }]);
    setCopilotLoading(true);

    try {
      const response = await axiosInstance.post('/support/copilot', {
        question: userMsg,
        context: `Usuario actual: ${currentUser?.name || 'Desconocido'}, Email: ${currentUser?.email || 'N/A'}, Rol: ${currentUser?.role || 'N/A'}`
      });

      const botAnswer = response.data.answer || 'Lo siento, no he podido procesar tu solicitud.';
      setCopilotMessages(prev => [...prev, { sender: 'bot', text: botAnswer, timestamp: new Date() }]);
    } catch (error) {
      console.error('Error in Copilot support chat:', error);
      setCopilotMessages(prev => [
        ...prev,
        {
          sender: 'bot',
          text: '⚠️ Ocurrió un error al conectar con el servidor de inteligencia artificial. Por favor, inténtalo de nuevo.',
          timestamp: new Date()
        }
      ]);
    } finally {
      setCopilotLoading(false);
    }
  };

  const renderStoreClosedScreen = (isMobile: boolean) => {
    return (
      <div className={`flex flex-col items-center justify-center text-center p-8 transition-all animate-fade-in ${
        isMobile 
          ? 'flex-1 pt-[100px] pb-[100px] px-6' 
          : `w-full border rounded-3xl p-16 ${isDark ? 'bg-slate-900/20 border-slate-900/40' : 'bg-white border-slate-202 shadow-md'}`
      }`}>
        <div className={`rounded-full flex items-center justify-center shadow-lg border-2 border-white dark:border-slate-800 bg-gradient-to-tr from-amber-500 to-rose-500 text-white animate-pulse ${
          isMobile ? 'w-20 h-20 text-3xl mb-6' : 'w-28 h-28 text-5xl mb-8'
        }`}>
          🔒
        </div>
        <h2 className={`font-black text-slate-850 dark:text-slate-100 ${
          isMobile ? 'text-xl mb-2' : 'text-3xl mb-3'
        }`}>
          Empresa Cerrada
        </h2>
        <p className={`text-slate-505 dark:text-slate-400 font-medium leading-relaxed max-w-md mx-auto ${
          isMobile ? 'text-xs mb-8' : 'text-sm mb-10'
        }`}>
          Por el momento la empresa se encuentra cerrada. Para poder visualizar este reloj checador, deberás de esperar.
        </p>
        
        <div className={`w-full max-w-sm rounded-2xl border p-4.5 text-center transition-all ${
          isDark 
            ? 'bg-slate-950/60 border-slate-850 shadow-inner' 
            : 'bg-slate-50 border-slate-200 shadow-inner'
        }`}>
          <span className="text-[10px] font-black uppercase tracking-wider text-slate-450 block mb-1">
            Tiempo de espera restante
          </span>
          <div className="text-2xl font-black text-indigo-605 dark:text-indigo-400 tracking-tight animate-pulse">
            ⏳ {waitTimeText}
          </div>
        </div>
      </div>
    );
  };

  const renderUnifiedMobileHeader = () => {
    let title = '';
    let desc = '';
    let icon = null;
    let badgeText = '';

    switch (phoneTab) {
      case 'checador':
        title = 'Reloj Checador';
        desc = 'Control de Asistencia';
        icon = <Clock className="text-[#2dce89] animate-spin-once" />;
        badgeText = 'v4.3';
        break;
      case 'tareas':
        title = 'Tareas y Rutinas';
        desc = 'Gestión y seguimiento operativo';
        icon = <ListTodo className="text-blue-600 animate-wiggle-once" />;
        badgeText = 'v1.5';
        break;
      case 'academia':
        title = 'Academia';
        desc = 'Capacitación y desarrollo';
        icon = <GraduationCap className="text-violet-500 animate-bounce-twice" />;
        badgeText = 'v2.8';
        break;
      case 'nomina':
        title = 'Nómina';
        desc = 'Recibos y timbrados CFDI';
        icon = <DollarSign className="text-emerald-500 animate-pulse-once" />;
        badgeText = 'v1.0';
        break;
      case 'herramientas':
        title = 'Herramientas';
        desc = 'Simulador y bitácoras';
        icon = <Settings className="text-slate-500 animate-spin-once" />;
        badgeText = 'v1.0';
        break;
      case 'evaluacion360':
        title = 'Evaluación 360';
        desc = 'Evaluación de compañeros';
        icon = <Star className="text-amber-500 animate-pulse-once" />;
        badgeText = 'v1.1';
        break;
      case 'organigrama':
        title = 'Organigrama';
        desc = 'Estructura de la empresa';
        icon = <Network className="text-emerald-500 animate-wiggle-once" />;
        badgeText = 'v1.3';
        break;
      case 'perfil':
        title = 'Mi Perfil';
        desc = 'Credencial y ajustes';
        icon = <ClipboardList className="text-blue-500 animate-pulse-once" />;
        badgeText = 'v1.2';
        break;
      default:
        title = 'Reloj Checador';
        desc = 'Control de Asistencia';
        icon = <Clock className="text-[#2dce89]" />;
        badgeText = 'v4.3';
    }

    let moduleId = 'asistencia';
    switch (phoneTab) {
      case 'checador': moduleId = 'asistencia'; break;
      case 'tareas': moduleId = 'operativo'; break;
      case 'academia': moduleId = 'academia'; break;
      case 'nomina': moduleId = 'facturacion'; break;
      case 'herramientas': moduleId = 'matrix'; break;
      case 'evaluacion360': moduleId = 'reportes'; break;
      case 'organigrama': moduleId = 'rrhh'; break;
      case 'perfil': moduleId = 'settings'; break;
    }

    const getModuleColor = (modId: string) => {
      if (modId === 'operativo') {
        return ColorMap.blue;
      }
      const cust = systemSettings?.moduleCustomizations?.[modId];
      if (cust?.color && ColorMap[cust.color]) {
        return ColorMap[cust.color];
      }
      switch (modId) {
        case 'asistencia': return ColorMap.emerald;
        case 'operativo': return ColorMap.blue;
        case 'academia': return ColorMap.violet;
        case 'facturacion': return ColorMap.rose;
        default: return ColorMap.slate;
      }
    };
    const activeColor = getModuleColor(moduleId);

    return (
      <div className={`fixed top-3 left-3 right-3 z-[75] flex items-center justify-between px-3 xs:px-4 py-2.5 xs:py-3.5 text-left rounded-[1.25rem] xs:rounded-2xl border transition-all duration-200 select-none ${
        isDark 
          ? 'bg-slate-955/80 backdrop-blur-md border-violet-900/40 shadow-[0_8px_32px_rgba(124,58,237,0.15)] text-slate-100' 
          : 'bg-white/80 backdrop-blur-md border-violet-100/50 shadow-[0_8px_32px_rgba(124,58,237,0.06)] text-slate-900'
      }`}>
        {/* Columna Izquierda: Info de Módulo */}
        <div className="flex items-center gap-2.5 xs:gap-3.5 min-w-0">
          <div className="shrink-0 flex items-center justify-center">
            {icon && React.cloneElement(icon, { 
              key: phoneTab,
              className: `w-8 h-8 xs:w-10 xs:h-10 ${icon.props.className || ''}`,
              style: { color: activeColor.hex }
            })}
          </div>
          <div className="flex flex-col min-w-0 justify-center text-left">
            <div className="flex items-center gap-1.5 flex-wrap">
              <h3 className={`text-[13px] xs:text-[14.5px] font-black tracking-tight leading-tight transition-colors truncate max-w-[90px] xxs:max-w-[120px] xs:max-w-[150px] ${
                isDark ? 'text-white' : 'text-slate-900'
              }`}>
                {title}
              </h3>
              {badgeText && (
                <span 
                  className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[7.5px] font-black tracking-wider uppercase border border-solid"
                  style={{ 
                    backgroundColor: activeColor.hex + '10', 
                    color: activeColor.hex, 
                    borderColor: activeColor.hex + '20' 
                  }}
                >
                  {badgeText}
                </span>
              )}
            </div>
            <p className={`text-[8.5px] xs:text-[9.5px] font-bold mt-0.5 leading-none truncate transition-colors max-w-[110px] xs:max-w-[160px] ${
              isDark ? 'text-slate-400' : 'text-[#525f7f]'
            }`}>
              {desc}
            </p>
          </div>
        </div>

        {/* Columna Derecha: Perfil de Usuario */}
        <div className="flex items-center gap-2 xs:gap-3 shrink-0 min-w-0">
          {/* Manual Pass List Trigger inside the header for Supervisors */}
          {storeStatus === 'open' && Number(currentUser?.id) === Number(activeEncargadoId) && (
            <button 
              onClick={() => initPaseLista(false)}
              className="bg-violet-600 hover:bg-violet-755 text-white font-extrabold text-[8px] xs:text-[9px] uppercase px-2 py-1 rounded-lg flex items-center gap-1 shadow-sm border-none cursor-pointer active:scale-95 transition-all select-none shrink-0"
            >
              <span>📋</span>
              <span className="hidden xxs:inline">Lista</span>
            </button>
          )}

          <div 
            className="flex items-center gap-1.5 xs:gap-2.5 text-right cursor-pointer min-w-0"
            onClick={() => {
              setEditUsername(currentUser?.name || 'Francisco');
              setEditPassword(currentUser?.pin_code || '1234');
              setEditRestDay(shiftConfigs[currentUser?.id]?.restDay || 'Domingo');
              setShowProfileMenu(true);
            }}
          >
            <div className="flex flex-col min-w-0 text-right justify-center leading-tight">
              <span className={`text-[10px] xs:text-[11.5px] font-black uppercase tracking-wider transition-colors max-w-[80px] xxs:max-w-[105px] xs:max-w-[130px] ${
                isDark ? 'text-violet-400' : 'text-[#8a2be2]'
              }`}>
                {currentUser?.tenant?.name || 'Decorarte 365'}
              </span>
              <span className={`text-[9.5px] xs:text-[10.5px] font-bold truncate transition-colors max-w-[80px] xxs:max-w-[105px] xs:max-w-[130px] mt-0.5 ${
                isDark ? 'text-slate-100' : 'text-slate-900'
              }`}>
                {currentUser?.name || 'Colaborador'}
              </span>
              <span className="text-[7.5px] xs:text-[8px] font-extrabold text-slate-450 dark:text-slate-500 uppercase tracking-widest truncate mt-0.5 max-w-[80px] xxs:max-w-[105px] xs:max-w-[130px]">
                {userPositionName}
              </span>
            </div>
            
            <div className="relative shrink-0">
              <img 
                src={currentUser?.avatar || "https://i.pravatar.cc/150?img=11"} 
                alt="Avatar" 
                className={`w-10 h-10 xs:w-12 xs:h-12 rounded-full object-cover border-2 shadow-md hover:scale-105 transition-transform ${
                  isDark ? 'border-slate-700' : 'border-slate-202'
                }`} 
              />
              <span className={`absolute bottom-0 right-0 w-2.5 h-2.5 xs:w-3.5 xs:h-3.5 rounded-full border ${isDark ? 'border-slate-900' : 'border-white'} ${hasCheckedIn && !hasCheckedOut ? 'bg-[#2dce89]' : 'bg-slate-400'}`}></span>
            </div>
          </div>
        </div>
      </div>
    );
  };

  const renderFloatingActionButton = () => {
    return (
      <div className="fixed bottom-24 right-6 z-[80] font-sans">
        <button
          type="button"
          onClick={() => setIsFabSheetOpen(true)}
          className="w-14 h-14 bg-gradient-to-tr from-violet-600 via-indigo-600 to-violet-600 text-white rounded-full flex items-center justify-center shadow-2xl hover:scale-105 transition-all active:scale-95 duration-300 relative group cursor-pointer border-none outline-none animate-pulse-radial"
          title="Menú de Operaciones y Soporte AI"
        >
          <div className="absolute inset-0 bg-violet-400 rounded-full blur-[8px] opacity-40 group-hover:opacity-75 transition-opacity pointer-events-none"></div>
          <Sparkles size={24} className="relative z-10 text-white" />
          <span className="absolute -top-1 -right-1 w-3.5 h-3.5 bg-emerald-500 rounded-full border-2 border-white animate-ping"></span>
          <span className="absolute -top-1 -right-1 w-3.5 h-3.5 bg-emerald-500 rounded-full border-2 border-white"></span>
        </button>
      </div>
    );
  };

  const renderFabOperationsSheet = () => {
    if (!isFabSheetOpen) return null;

    return (
      <div className="fixed inset-0 z-[90] flex items-end justify-center">
        {/* Backdrop overlay */}
        <div 
          onClick={() => setIsFabSheetOpen(false)}
          className="absolute inset-0 bg-slate-955/40 backdrop-blur-sm transition-opacity"
        ></div>

        {/* Sheet Content */}
        <div className={`relative w-full max-h-[85vh] rounded-t-[2rem] border-t shadow-2xl z-10 flex flex-col transition-transform duration-300 animate-slide-up pb-8 ${
          isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-202 text-slate-850'
        }`}>
          {/* Drag Handle */}
          <div className="w-12 h-1.5 bg-slate-300 dark:bg-slate-700 rounded-full mx-auto my-3 shrink-0"></div>

          <div className="px-5 pb-2 border-b dark:border-slate-800 text-left shrink-0 flex justify-between items-center">
            <div>
              <h3 className="text-base font-black tracking-tight">Operaciones & Soporte AI</h3>
              <p className="text-[11px] text-slate-400 font-medium">Accesos rápidos y caja de herramientas operativas</p>
            </div>
            <button 
              onClick={() => setIsFabSheetOpen(false)}
              className="w-8 h-8 rounded-full bg-slate-105 dark:bg-slate-800 flex items-center justify-center hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors border-none cursor-pointer font-bold"
            >
              &times;
            </button>
          </div>

          <div className="flex-grow overflow-y-auto p-5 space-y-6 scrollbar-none text-left">
            {/* Section 1: AI Assistant & Actions */}
            <div className="space-y-3">
              <h4 className="font-extrabold text-[10px] text-slate-400 uppercase tracking-widest">Asistencia & Creación</h4>
              <div className="grid grid-cols-2 gap-3">
                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setIsCopilotChatOpen(true);
                  }}
                  className={`p-4 rounded-2xl border flex flex-col items-center justify-center gap-2 text-center transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
                    isDark ? 'border-slate-800 bg-slate-950/40 hover:bg-slate-950/80 text-white' : 'border-slate-202 bg-slate-50 hover:bg-slate-105 text-slate-850'
                  }`}
                >
                  <div className="w-10 h-10 rounded-2xl bg-gradient-to-tr from-violet-600 to-indigo-600 text-white flex items-center justify-center shadow-md">
                    <Bot size={20} className="animate-pulse" />
                  </div>
                  <div>
                    <p className="font-bold text-xs">Copiloto AI</p>
                    <p className="text-[9px] text-slate-400 mt-0.5">Soporte Técnico</p>
                  </div>
                </button>

                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setIsTaskCreatorOpen(true);
                  }}
                  className={`p-4 rounded-2xl border flex flex-col items-center justify-center gap-2 text-center transition-all active:scale-95 border-none bg-transparent cursor-pointer ${
                    isDark ? 'border-slate-800 bg-slate-950/40 hover:bg-slate-950/80 text-white' : 'border-slate-202 bg-slate-50 hover:bg-slate-105 text-slate-850'
                  }`}
                >
                  <div className="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-orange-500 text-white flex items-center justify-center shadow-md">
                    <Play size={20} />
                  </div>
                  <div>
                    <p className="font-bold text-xs">Crear Tarea</p>
                    <p className="text-[9px] text-slate-400 mt-0.5">Lanzar on-the-fly</p>
                  </div>
                </button>
              </div>
            </div>

            {/* Section 2: Caja de Herramientas Operativa */}
            <div className="space-y-3">
              <h4 className="font-extrabold text-[10px] text-slate-400 uppercase tracking-widest">Caja de Herramientas</h4>
              <div className="grid grid-cols-1 gap-2.5">
                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('herramientas');
                    setInnerTool('chat');
                    fetchChatMessages();
                  }} 
                  className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-950/20 hover:bg-slate-950/40 text-slate-200' : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-800'}`}
                >
                  <div className="w-9 h-9 rounded-xl bg-slate-955/50 text-indigo-400 flex items-center justify-center shrink-0">
                    <MessageSquare size={16} />
                  </div>
                  <div>
                    <p className="font-bold text-xs">Chat de Equipo 💬</p>
                    <p className="text-[9.5px] text-slate-505">Mensajes temporales entre colaboradores de la sucursal</p>
                  </div>
                </button>

                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('herramientas');
                    setInnerTool('soplon');
                  }} 
                  className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-950/20 hover:bg-slate-955/40 text-slate-200' : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-800'}`}
                >
                  <div className="w-9 h-9 rounded-xl bg-slate-955/50 text-rose-400 flex items-center justify-center shrink-0">
                    <AlertOctagon size={16} />
                  </div>
                  <div>
                    <p className="font-bold text-xs">El Soplón 📢</p>
                    <p className="text-[9.5px] text-slate-505">Reportar ausencias o faltas de compañeros de forma directa</p>
                  </div>
                </button>

                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('herramientas');
                    setInnerTool('buzon');
                  }} 
                  className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-955/20 hover:bg-slate-955/40 text-slate-200' : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-800'}`}
                >
                  <div className="w-9 h-9 rounded-xl bg-slate-955/50 text-sky-400 flex items-center justify-center shrink-0">
                    <Fingerprint size={16} />
                  </div>
                  <div>
                    <p className="font-bold text-xs">Buzón Anónimo RRHH 🕵️</p>
                    <p className="text-[9.5px] text-slate-505">Enviar sugerencias o reportes generales 100% privados</p>
                  </div>
                </button>

                {shiftConfigs[currentUser?.id]?.portadorLlaves !== 'Ninguno' && shiftConfigs[currentUser?.id]?.portadorLlaves !== 'ninguno' && (
                  <button 
                    onClick={() => {
                      setIsFabSheetOpen(false);
                      setPhoneTab('herramientas');
                      setInnerTool('transfer');
                    }} 
                    className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-955/20 hover:bg-slate-955/40 text-slate-200' : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-800'}`}
                  >
                    <div className="w-9 h-9 rounded-xl bg-slate-955/50 text-indigo-400 flex items-center justify-center shrink-0">
                      <Key size={16} />
                    </div>
                    <div>
                      <p className="font-bold text-xs">Transferir Cierre 🔑</p>
                      <p className="text-[9.5px] text-slate-550">Ceder llaves de la sucursal a un compañero</p>
                    </div>
                  </button>
                )}

                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('herramientas');
                    setInnerTool('huida');
                  }} 
                  className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-955/20 hover:bg-slate-955/40 text-slate-200' : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-800'}`}
                >
                  <div className="w-9 h-9 rounded-xl bg-slate-955/50 text-amber-500 flex items-center justify-center shrink-0">
                    <WifiOff size={16} />
                  </div>
                  <div>
                    <p className="font-bold text-xs">Simular Desconexión (Huida) 🏃</p>
                    <p className="text-[9.5px] text-slate-550">Detección de desconexión sin entregar el turno</p>
                  </div>
                </button>

                {isPro && clockState === 'active' && (
                  <button 
                    onClick={() => {
                      setIsFabSheetOpen(false);
                      setShowTempExitModal(true);
                    }} 
                    className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-950/20 hover:bg-slate-950/40 text-slate-200' : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-800'}`}
                  >
                    <div className="w-9 h-9 rounded-xl bg-teal-50/50 text-teal-650 flex items-center justify-center shrink-0">
                      <LogOut size={16} />
                    </div>
                    <div>
                      <p className="font-bold text-xs">Pase de Salida Temporal 🚪</p>
                      <p className="text-[9.5px] text-slate-505 font-medium">Registrar una salida temporal de la tienda con GPS activo</p>
                    </div>
                  </button>
                )}

                {isPro && clockState === 'active' && mealStartTimes[currentUser.id] === undefined && (
                  <button 
                    onClick={() => {
                      setIsFabSheetOpen(false);
                      setShowMealSwapModal(true);
                    }} 
                    className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-955/20 hover:bg-slate-955/40 text-slate-200' : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-800'}`}
                  >
                    <div className="w-9 h-9 rounded-xl bg-amber-50/50 text-amber-600 flex items-center justify-center shrink-0">
                      <Utensils size={16} />
                    </div>
                    <div>
                      <p className="font-bold text-xs">Intercambio de Comida 🍽️</p>
                      <p className="text-[9.5px] text-slate-505 font-medium">Intercambiar rápidamente tu horario de comida con un compañero</p>
                    </div>
                  </button>
                )}

                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setShowPanicModal(true);
                  }} 
                  className="p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-rose-50 hover:bg-rose-100/50 text-rose-800 cursor-pointer"
                >
                  <div className="w-9 h-9 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">
                    <AlertTriangle size={16} className="animate-pulse" />
                  </div>
                  <div>
                    <p className="font-bold text-xs text-rose-900">🚨 BOTÓN DE PÁNICO</p>
                    <p className="text-[9.5px] text-rose-700 font-medium">Declarar una emergencia crítica inmediata en la sucursal</p>
                  </div>
                </button>

                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('evaluacion360');
                  }} 
                  className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-955/20 hover:bg-slate-955/40 text-slate-200' : 'border-slate-202 bg-white hover:bg-slate-50 text-slate-800'}`}
                >
                  <div className="w-9 h-9 rounded-xl bg-slate-955/50 text-amber-450 flex items-center justify-center shrink-0">
                    <Star size={16} />
                  </div>
                  <div>
                    <p className="font-bold text-xs">Evaluación de Compañeros ⭐</p>
                    <p className="text-[9.5px] text-slate-550">Evaluar desempeño y puntualidad en el turno</p>
                  </div>
                </button>

                <button 
                  onClick={() => {
                    setIsFabSheetOpen(false);
                    setPhoneTab('organigrama');
                  }} 
                  className={`p-3.5 rounded-2xl border flex items-center gap-3 text-left transition-all active:scale-98 border-none bg-transparent cursor-pointer ${isDark ? 'border-slate-800 bg-slate-955/20 hover:bg-slate-955/40 text-slate-200' : 'border-slate-202 bg-white hover:bg-slate-50 text-slate-800'}`}
                >
                  <div className="w-9 h-9 rounded-xl bg-slate-955/50 text-emerald-450 flex items-center justify-center shrink-0">
                    <Network size={16} />
                  </div>
                  <div>
                    <p className="font-bold text-xs">Organigrama de la Empresa 🕸️</p>
                    <p className="text-[9.5px] text-slate-550">Consulta los puestos y responsabilidades</p>
                  </div>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    );
  };

  const renderTaskCreatorModal = () => {
    if (!isTaskCreatorOpen) return null;

    return (
      <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
        {/* Backdrop overlay */}
        <div 
          onClick={() => setIsTaskCreatorOpen(false)}
          className="absolute inset-0 bg-slate-955/65 backdrop-blur-sm transition-opacity"
        ></div>

        {/* Modal Content */}
        <div className={`relative w-full max-w-md rounded-3xl p-6 shadow-2xl border text-left animate-in zoom-in-95 duration-200 ${
          isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-202 text-slate-850'
        }`}>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-base font-black flex items-center gap-1.5 text-indigo-500">
              ⚡ Crear Tarea Rápida
            </h3>
            <button 
              onClick={() => setIsTaskCreatorOpen(false)}
              className="text-slate-400 hover:text-slate-600 dark:hover:text-white font-bold bg-transparent border-none cursor-pointer text-lg leading-none"
            >
              &times;
            </button>
          </div>

          <form onSubmit={(e) => {
            e.preventDefault();
            if (!newTaskTitle.trim()) return;
            const { createDynamicTask } = useTaskStore.getState();
            createDynamicTask(
              newTaskTitle.trim(),
              newTaskRoleTarget,
              Number(newTaskMins),
              newTaskPriority
            );
            setNewTaskTitle('');
            setIsTaskCreatorOpen(false);
            setGlobalToast('¡Tarea rápida creada con éxito y enviada a la Bolsa de Trabajo! ⚡');
            setTimeout(() => setGlobalToast(null), 4000);
          }} className="space-y-4">
            <div>
              <label className="block text-[10px] font-black uppercase text-slate-400 tracking-wider mb-1">Título de la Tarea</label>
              <input 
                type="text" 
                value={newTaskTitle}
                onChange={(e) => setNewTaskTitle(e.target.value)}
                placeholder="Ej. Limpiar mesa de corte o Contar stock"
                required
                className={`w-full px-3.5 py-2.5 rounded-xl border text-xs font-semibold focus:outline-none transition-colors ${
                  isDark ? 'bg-slate-950 border-slate-800 text-white focus:border-indigo-500' : 'bg-slate-50 border-slate-200 text-slate-800 focus:border-indigo-400'
                }`}
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-[10px] font-black uppercase text-slate-400 tracking-wider mb-1">Tiempo Estimado</label>
                <select 
                  value={newTaskMins}
                  onChange={(e) => setNewTaskMins(Number(e.target.value))}
                  className={`w-full px-3 py-2.5 rounded-xl border text-xs font-semibold focus:outline-none transition-colors ${
                    isDark ? 'bg-slate-955 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
                  }`}
                >
                  <option value={15}>15 Minutos</option>
                  <option value={30}>30 Minutos</option>
                  <option value={45}>45 Minutos</option>
                  <option value={60}>1 Hora</option>
                  <option value={120}>2 Horas</option>
                </select>
              </div>

              <div>
                <label className="block text-[10px] font-black uppercase text-slate-405 tracking-wider mb-1">Prioridad</label>
                <select 
                  value={newTaskPriority}
                  onChange={(e) => setNewTaskPriority(e.target.value as any)}
                  className={`w-full px-3 py-2.5 rounded-xl border text-xs font-semibold focus:outline-none transition-colors ${
                    isDark ? 'bg-slate-955 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
                  }`}
                >
                  <option value="normal">Normal</option>
                  <option value="bloqueante">Crítica (Bloqueante)</option>
                </select>
              </div>
            </div>

            <div>
              <label className="block text-[10px] font-black uppercase text-slate-405 tracking-wider mb-1">Puesto Dirigido (Rol)</label>
              <select 
                value={newTaskRoleTarget}
                onChange={(e) => setNewTaskRoleTarget(Number(e.target.value))}
                className={`w-full px-3 py-2.5 rounded-xl border text-xs font-semibold focus:outline-none transition-colors ${
                  isDark ? 'bg-slate-955 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'
                }`}
              >
                <option value={6}>Ayudante Integral (General)</option>
                <option value={5}>Supervisor de Sucursal</option>
                <option value={4}>Cajero / Cobros</option>
                <option value={3}>Asesor de Ventas</option>
              </select>
            </div>

            <button 
              type="submit"
              className="w-full py-3 bg-gradient-to-r from-indigo-600 to-violet-600 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-lg shadow-indigo-600/20 active:scale-95 transition-all cursor-pointer border-none"
            >
              Lanzar a la Bolsa de Trabajo
            </button>
          </form>
        </div>
      </div>
    );
  };

  const renderCopilotChatDrawer = () => {
    if (!isCopilotChatOpen) return null;

    return (
      <div className="fixed inset-0 z-[100] flex items-end sm:items-center justify-center p-4">
        {/* Backdrop overlay */}
        <div 
          onClick={() => setIsCopilotChatOpen(false)}
          className="absolute inset-0 bg-slate-955/65 backdrop-blur-sm transition-opacity"
        ></div>

        {/* Chat Drawer Content */}
        <div className={`relative w-full max-w-md h-[80vh] sm:h-[600px] rounded-t-[2rem] sm:rounded-3xl shadow-2xl border flex flex-col overflow-hidden animate-in slide-in-from-bottom-10 duration-300 ${
          isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-202 text-slate-850'
        }`}>
          {/* Header */}
          <div className="bg-gradient-to-r from-violet-600 via-indigo-600 to-violet-600 px-5 py-4 flex items-center justify-between text-white shrink-0 shadow-md">
            <div className="flex items-center gap-3 text-left">
              <div className="w-10 h-10 rounded-2xl bg-white/10 flex items-center justify-center">
                <Bot size={22} className="animate-pulse" />
              </div>
              <div>
                <h3 className="text-sm font-black tracking-wide uppercase leading-none">Copiloto AI</h3>
                <p className="text-[10px] text-violet-200 mt-1 flex items-center gap-1">
                  <Sparkles size={10} className="animate-bounce" />
                  Soporte Técnico Activo
                </p>
              </div>
            </div>
            <button
              onClick={() => setIsCopilotChatOpen(false)}
              className="bg-white/10 hover:bg-white/20 text-white/90 p-1.5 rounded-full transition-colors border-none cursor-pointer outline-none"
            >
              <X size={18} />
            </button>
          </div>

          {/* Messages area */}
          <div className="flex-grow p-4 overflow-y-auto space-y-3.5 bg-slate-50/50 dark:bg-slate-950/20 scrollbar-none">
            {copilotMessages.map((msg, idx) => (
              <div
                key={idx}
                className={`flex ${msg.sender === 'user' ? 'justify-end' : 'justify-start'} animate-in fade-in-50 duration-200`}
              >
                <div
                  className={`max-w-[85%] px-4 py-3 rounded-2xl text-xs leading-relaxed text-left ${
                    msg.sender === 'user'
                      ? 'bg-violet-600 text-white rounded-br-none shadow-md shadow-violet-600/10'
                      : 'bg-white dark:bg-slate-800 text-slate-850 dark:text-slate-200 border rounded-bl-none shadow-sm border-slate-100 dark:border-slate-700/80'
                  }`}
                >
                  {msg.text}
                </div>
              </div>
            ))}
            {copilotLoading && (
              <div className="flex justify-start">
                <div className="bg-white dark:bg-slate-800 text-slate-500 border border-slate-100 dark:border-slate-700/80 px-4 py-3 rounded-2xl rounded-bl-none shadow-sm flex items-center gap-2">
                  <span className="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-bounce" style={{ animationDelay: '0ms' }}></span>
                  <span className="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-bounce" style={{ animationDelay: '150ms' }}></span>
                  <span className="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-bounce" style={{ animationDelay: '300ms' }}></span>
                </div>
              </div>
            )}
            <div ref={copilotEndRef} />
          </div>

          {/* Input Form */}
          <form onSubmit={handleSendCopilot} className="p-3.5 border-t dark:border-slate-800 shrink-0 bg-white dark:bg-slate-900 flex gap-2">
            <input
              type="text"
              value={copilotInput}
              onChange={(e) => setCopilotInput(e.target.value)}
              placeholder="Escribe tu duda de soporte aquí..."
              className={`flex-grow px-4 py-2.5 rounded-xl border text-xs font-semibold focus:outline-none ${
                isDark ? 'bg-slate-955 border-slate-800 text-white focus:border-violet-500' : 'bg-slate-50 border-slate-200 text-slate-800 focus:border-violet-400'
              }`}
            />
            <button
              type="submit"
              disabled={copilotLoading}
              className="bg-violet-600 hover:bg-violet-750 text-white p-2.5 rounded-xl flex items-center justify-center transition-colors shadow-md border-none cursor-pointer"
            >
              <Send size={16} />
            </button>
          </form>
        </div>
      </div>
    );
  };

  // --- HTML MAIN VIEW RENDERING ---
  return (
    <div className={`w-full relative select-none font-sans transition-colors ${
      isScrollableMobile 
        ? (isMobileFrame ? 'h-full' : 'h-[100dvh]') + ' flex flex-col justify-between overflow-hidden p-4 ' + (isDark ? 'bg-slate-955 text-slate-100' : 'bg-slate-50 text-slate-850')
        : 'min-h-screen ' + (isDark ? 'bg-[#090d16] text-slate-100' : 'bg-[#f8fafc] text-slate-850')
    }`}>
      
      {/* Global CSS hourglass animations */}
      <style>{`
        @keyframes hourglass-flip {
          0%, 90% { transform: rotate(0deg); }
          100% { transform: rotate(180deg); }
        }
        @keyframes sand-flow {
          0% { stroke-dashoffset: 0; }
          100% { stroke-dashoffset: -20; }
        }
        @keyframes pulse-radial {
          0% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0.3); }
          70% { box-shadow: 0 0 0 15px rgba(124, 58, 237, 0); }
          100% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0); }
        }
        @keyframes top-sand-drain {
          0%, 10% { transform: scaleY(1); }
          90%, 100% { transform: scaleY(0); }
        }
        @keyframes bottom-sand-fill {
          0%, 10% { transform: scaleY(0.1); }
          90%, 100% { transform: scaleY(1); }
        }
        .animate-hourglass {
          animation: hourglass-flip 6s ease-in-out infinite;
          transform-origin: center center;
        }
        .animate-sand {
          animation: sand-flow 1.5s linear infinite;
        }
        .animate-pulse-radial {
          animation: pulse-radial 2.5s infinite;
        }
        .top-sand {
          animation: top-sand-drain 6s ease-in-out infinite;
          transform-origin: bottom center;
        }
        .bottom-sand {
          animation: bottom-sand-fill 6s ease-in-out infinite;
          transform-origin: bottom center;
        }
        .scrollbar-none::-webkit-scrollbar {
          display: none;
        }
        .scrollbar-none {
          -ms-overflow-style: none;
          scrollbar-width: none;
        }
        @keyframes shimmer-glow {
          0%, 100% {
            opacity: 0.4;
            transform: scale(0.99);
          }
          50% {
            opacity: 0.85;
            transform: scale(1.04);
          }
        }
        .animate-shimmer-glow {
          animation: shimmer-glow 3s ease-in-out infinite;
        }
      `}</style>

      {/* Decorative background spots */}
      <div className="absolute top-10 left-10 w-32 h-32 bg-violet-500/5 rounded-full blur-3xl pointer-events-none"></div>
      <div className="absolute bottom-20 right-10 w-32 h-32 bg-emerald-500/5 rounded-full blur-3xl pointer-events-none"></div>

      {/* GLOBAL TOAST DENTRO DEL COMPONENTE */}
      {globalToast && (
        <div className={`z-[100] backdrop-blur-md font-semibold px-4 py-3 rounded-2xl shadow-xl animate-fade-in border text-xs flex items-center gap-3 ${
          isDark ? 'bg-slate-900/90 text-white border-slate-805' : 'bg-white/95 text-slate-800 border-slate-200'
        } ${isScrollableMobile ? 'absolute top-4 left-4 right-4' : 'fixed top-20 left-4 right-4 md:left-auto md:right-6 md:w-96'}`}>
          <div className="bg-violet-600 rounded-lg p-1 flex-shrink-0">
            <span role="img" aria-label="alert" className="text-white text-xs">🔔</span>
          </div>
          <span className="text-left font-bold">{globalToast}</span>
        </div>
      )}

      {/* PWA INSTALL BANNER */}
      {showPwaBanner && (
        <div className={`z-[110] backdrop-blur-md font-semibold px-4 py-3.5 rounded-2xl shadow-xl animate-fade-in border text-xs flex items-center justify-between gap-3 ${
          isDark ? 'bg-slate-900/90 text-white border-slate-800' : 'bg-white/95 text-slate-800 border-slate-200'
        } ${isScrollableMobile ? 'absolute top-4 left-4 right-4' : 'fixed top-20 left-4 right-4 md:left-auto md:right-6 md:w-96'}`}>
          <div className="flex items-center gap-2 min-w-0">
            <div className="bg-violet-600 rounded-lg p-1.5 flex-shrink-0 text-white flex items-center justify-center">
              📲
            </div>
            <div className="text-left min-w-0">
              <p className="font-extrabold text-xs">Instalar Talent 360 App</p>
              <p className="text-[10px] text-slate-500 truncate">Accede directo desde tu pantalla de inicio</p>
            </div>
          </div>
          <div className="flex gap-1.5 shrink-0">
            <button 
              onClick={() => {
                setShowPwaBanner(false);
                sessionStorage.setItem('pwa_dismissed', 'true');
              }}
              className="px-2 py-1 rounded-md text-[10px] font-bold text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800"
            >
              Cerrar
            </button>
            <button 
              onClick={handlePwaInstall}
              className="bg-violet-600 hover:bg-violet-700 text-white font-extrabold text-[10px] px-3 py-1.5 rounded-xl transition-all"
            >
              Instalar
            </button>
          </div>
        </div>
      )}

      {/* PUSH NOTIFICATIONS (Actionable) */}
      {currentUser && clockState !== 'inactive' && clockState !== 'waiting_room' && activePushNotification && (
        <div 
          onClick={activePushNotification.action}
          className={`z-[105] backdrop-blur-lg font-semibold px-4 py-3.5 rounded-2xl shadow-2xl animate-fade-in border text-xs flex flex-col gap-1.5 cursor-pointer hover:scale-[1.01] transition-transform ${
            isDark ? 'bg-slate-900/95 text-white border-slate-805' : 'bg-white/95 text-slate-800 border-slate-200'
          } ${isScrollableMobile ? 'absolute top-4 left-4 right-4' : 'fixed top-20 left-4 right-4 md:left-auto md:right-6 md:w-96'}`}
        >
          <div className="flex justify-between items-center w-full">
            <div className="flex items-center gap-2">
              <div className="bg-rose-500 rounded-lg p-1 flex-shrink-0">
                <span role="img" aria-label="alert" className="text-white text-[10px]">📲</span>
              </div>
              <span className="font-extrabold text-[10px] uppercase tracking-wider text-slate-400">Notificación</span>
            </div>
            <button 
              onClick={(e) => {
                e.stopPropagation();
                setActivePushNotification(null);
              }}
              className="text-slate-400 hover:text-slate-600 dark:hover:text-white text-sm font-bold bg-transparent border-none cursor-pointer outline-none p-1 leading-none"
            >
              &times;
            </button>
          </div>
          <p className="pl-6 font-bold text-left text-slate-700 dark:text-slate-200 leading-snug">{activePushNotification.text}</p>
          <div className="text-[10px] text-violet-500 font-extrabold text-right mt-0.5">Toca para abrir &rarr;</div>
        </div>
      )}

      {/* RENDER DESKTOP NAVIGATION VIEW */}
      {!isScrollableMobile && (
        <>
          {isEmployeeView ? (
            <header className={`bg-white border-b border-slate-200 shadow-sm relative z-30 flex-shrink-0 h-20 dark:bg-slate-900 dark:border-slate-800`}>
              <div className="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 h-full flex items-center justify-between">
                
                {/* Left Section: Active Tab Icon, Title & Description */}
                <div className="flex items-center gap-3 min-w-0">
                  <div className="shrink-0 flex items-center justify-center">
                    {getActiveTabHeader().icon}
                  </div>
                  <div className="flex flex-col min-w-0 text-left justify-center">
                    <div className="flex items-center gap-2">
                      <h2 className="text-base font-black text-slate-900 dark:text-slate-100 tracking-tight leading-tight truncate">
                        {getActiveTabHeader().title}
                      </h2>
                      {getActiveTabHeader().badge && (
                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black tracking-wider uppercase border ${storeStatus === 'open' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-rose-500/10 text-rose-455 border-rose-500/20'}`}>
                          <span className={`w-1 h-1 rounded-full ${storeStatus === 'open' ? 'bg-emerald-505 animate-pulse' : 'bg-rose-500'}`}></span>
                          {storeStatus === 'open' ? 'Abierto' : 'Cerrado'}
                        </span>
                      )}
                    </div>
                    <p className="text-[10px] text-slate-500 font-bold mt-1 truncate leading-none">
                      {getActiveTabHeader().desc}
                    </p>
                  </div>
                </div>

                {/* Center Section: Navigation Tabs */}
                <nav className={`flex items-center gap-1 p-1 rounded-2xl border ${isDark ? 'bg-slate-950 border-slate-850' : 'bg-slate-100 border-slate-250'}`}>
                  <button 
                    onClick={() => { setInnerTool(null); setPhoneTab('checador'); }} 
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${phoneTab === 'checador' ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                  >
                    ⏱️ Checador
                  </button>
                  {!isStoreClosed && (
                    <button 
                      onClick={() => { setInnerTool(null); setPhoneTab('tareas'); }} 
                      className={`px-3 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${phoneTab === 'tareas' ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                    >
                      ✅ Tareas
                    </button>
                  )}
                  <button 
                    onClick={() => { setInnerTool(null); setPhoneTab('academia'); }} 
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${phoneTab === 'academia' ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                  >
                    🎓 Academia
                  </button>
                  {!isStoreClosed && (
                    <button 
                      onClick={() => { setInnerTool(null); setPhoneTab('herramientas'); }} 
                      className={`px-3 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${['herramientas', 'evaluacion360'].includes(phoneTab) ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                    >
                      🛠️ Herramientas
                    </button>
                  )}
                  <button 
                    onClick={() => { setInnerTool(null); setPhoneTab('perfil'); }} 
                    className={`px-3 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${phoneTab === 'perfil' ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                  >
                    🪪 Perfil & Ajustes
                  </button>
                </nav>

                {/* Right Section: Profile & Anonymous Audit */}
                <div className="flex items-center gap-3 shrink-0">
                  {storeStatus === 'open' && Number(currentUser?.id) === Number(activeEncargadoId) && (
                    <button 
                      onClick={() => initPaseLista(false)}
                      className="bg-violet-600 hover:bg-violet-750 text-white font-extrabold text-[11px] px-3.5 py-2 rounded-xl flex items-center gap-1.5 shadow-md border-none outline-none select-none cursor-pointer active:scale-95 transition-all"
                    >
                      <span>📋</span>
                      <span>Pase Lista</span>
                    </button>
                  )}
                  <button 
                    onClick={() => setShowReportModal(true)}
                    className={`w-9 h-9 rounded-xl flex items-center justify-center border transition-colors ${
                      isDark ? 'bg-slate-955 hover:bg-slate-900 border-slate-800' : 'bg-slate-50 hover:bg-slate-100 border-slate-200'
                    }`}
                    title="Auditoría Anónima"
                  >
                    🛡️
                  </button>
                  
                  <div className="relative" ref={profileMenuRef}>
                    <button 
                      onClick={() => setIsProfileMenuOpen(!isProfileMenuOpen)}
                      className="flex items-center gap-2.5 p-1 rounded-2xl transition-colors focus:outline-none select-none text-left"
                    >
                      <div className="hidden lg:flex flex-col items-end mr-1 text-right leading-tight">
                        <span className="text-xs font-bold text-slate-800 dark:text-slate-250">{currentUser?.name || 'Colaborador'}{getUserKeysIcon(currentUser?.id)}</span>
                        <span className="text-[9px] text-violet-500 font-extrabold bg-violet-500/10 px-2 py-0.5 rounded-md mt-1 capitalize border border-violet-500/10">
                          {userPositionName}
                        </span>
                      </div>
                      
                      <div className="w-10 h-10 rounded-full bg-slate-200 border-2 border-white dark:border-slate-800 shadow-sm overflow-hidden relative shrink-0">
                        <img src={currentUser?.avatar || "https://i.pravatar.cc/150?img=11"} alt="Avatar" className="w-full h-full object-cover" />
                        <div className="absolute bottom-0 right-0 w-2.5 h-2.5 bg-emerald-500 rounded-full border-2 border-white dark:border-slate-800"></div>
                      </div>
                    </button>

                    {isProfileMenuOpen && (
                      <div className="absolute right-0 mt-2 w-52 sm:w-56 bg-white border border-slate-200 rounded-2xl shadow-xl py-2 z-50 animate-in fade-in slide-in-from-top-2 duration-200">
                        <div className="px-4 py-2 border-b border-slate-100 text-left">
                          <p className="text-xs sm:text-sm font-bold text-slate-800">Sesión Activa</p>
                          <p className="text-[10px] sm:text-xs text-slate-500 font-medium truncate">{currentUser?.email || 'empleado@decorarte360.com'}</p>
                        </div>
                        <div className="p-1.5 space-y-0.5 text-left">
                          <button 
                            onClick={() => { 
                              setEditUsername(currentUser?.name || 'Francisco');
                              setEditPassword(currentUser?.pin_code || '1234');
                              setEditRestDay(shiftConfigs[currentUser?.id]?.restDay || 'Domingo');
                              setShowSettingsModal(true);
                              setIsProfileMenuOpen(false); 
                            }}
                            className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center gap-2 focus:outline-none"
                          >
                            <Users size={14} className="text-slate-400" />
                            Mi Cuenta
                          </button>
                          
                          <button 
                            onClick={() => {
                              setInnerTool(null);
                              setPhoneTab('nomina');
                              setIsProfileMenuOpen(false);
                            }}
                            className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center gap-2 focus:outline-none"
                          >
                            <DollarSign size={14} className="text-slate-400" />
                            Historial de Nómina
                          </button>

                          <div className="border-t border-slate-100 my-1 sm:my-1.5"></div>
                          <button 
                            onClick={() => {
                              localStorage.removeItem('talent_auth_token');
                              useAppStore.getState().setCurrentUser(null as any);
                              setIsProfileMenuOpen(false);
                              window.location.href = '/login';
                            }}
                            className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-bold text-rose-600 hover:bg-rose-50 transition-colors flex items-center gap-2 focus:outline-none"
                          >
                            <Lock size={14} className="text-rose-500" />
                            Cerrar Sesión
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            </header>
          ) : (
            <header className="bg-white border-b border-slate-200 shadow-sm relative z-30 flex-shrink-0 dark:bg-slate-900 dark:border-slate-800 mb-4 rounded-xl">
              <div className="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-center">
                <nav className={`flex items-center gap-1 p-1 rounded-2xl border ${isDark ? 'bg-slate-950 border-slate-855' : 'bg-slate-105 border-slate-250'}`}>
                  <button 
                    onClick={() => { setInnerTool(null); setPhoneTab('checador'); }} 
                    className={`px-3.5 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${phoneTab === 'checador' ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-950 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                  >
                    ⏱️ Checador
                  </button>
                  {!isStoreClosed && (
                    <button 
                      onClick={() => { setInnerTool(null); setPhoneTab('tareas'); }} 
                      className={`px-3.5 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${phoneTab === 'tareas' ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                    >
                      ✅ Tareas
                    </button>
                  )}
                  <button 
                    onClick={() => { setInnerTool(null); setPhoneTab('academia'); }} 
                    className={`px-3.5 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${phoneTab === 'academia' ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                  >
                    🎓 Academia
                  </button>
                  {!isStoreClosed && (
                    <button 
                      onClick={() => { setInnerTool(null); setPhoneTab('herramientas'); }} 
                      className={`px-3.5 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${['herramientas', 'evaluacion360'].includes(phoneTab) ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                    >
                      🛠️ Herramientas
                    </button>
                  )}
                  <button 
                    onClick={() => { setInnerTool(null); setPhoneTab('perfil'); }} 
                    className={`px-3.5 py-1.5 text-xs font-bold rounded-xl transition-all focus:outline-none ${phoneTab === 'perfil' ? (isDark ? 'bg-slate-900 text-white shadow-sm font-black' : 'bg-white text-slate-955 shadow-sm font-black') : 'text-slate-500 hover:text-slate-750'}`}
                  >
                    🪪 Perfil & Ajustes
                  </button>
                </nav>
              </div>
            </header>
          )}
        </>
      )}

      {/* --- CONTENT LAYOUTS --- */}
      
      {isScrollableMobile && (
        <div className="flex-1 flex flex-col justify-between h-full overflow-hidden">
          {/* UNIFIED MOBILE HEADER (Anchor at the top, consistent styling) */}
          {renderUnifiedMobileHeader()}

          {/* MOBILE CONTENT AREA (Scrollable/Overflow depending on module) */}
          {phoneTab === 'checador' && (
            isStoreClosed ? (
              renderStoreClosedScreen(true)
            ) : (shiftConfigs[currentUser?.id]?.restDay === currentDay || isSimulatedHoliday) && !isOvertimeUnlocked[currentUser?.id] ? (
              <div className="flex-1 flex flex-col items-center justify-center px-8 text-center animate-fade-in-up pt-[82px] pb-[100px] gap-4">
                <div className="w-20 h-20 bg-indigo-50 dark:bg-indigo-950/40 rounded-full flex items-center justify-center text-3xl mb-1 shadow-inner border-2 border-white dark:border-slate-800">
                  {isSimulatedHoliday ? '📅' : '🌴'}
                </div>
                <h2 className="text-xl font-extrabold text-slate-850 dark:text-slate-200 mb-1">
                  {isSimulatedHoliday ? 'Día Feriado Obligatorio (LFT)' : 'Día de Descanso'}
                </h2>
                <p className="text-slate-500 dark:text-slate-400 text-xs font-medium max-w-sm leading-relaxed mb-2">
                  {isSimulatedHoliday 
                    ? 'Hoy conmemoramos el Natalicio de Benito Juárez de acuerdo a la Ley Federal del Trabajo. ¡Disfruta tu descanso de ley! 🇲🇽'
                    : '¡Es tu derecho a la desconexión digital! Relájate, recarga energías y disfruta tu día. Tu equipo te cubre hoy. 🌟'}
                </p>
                <button
                  type="button"
                  onClick={handleOvertimeClick}
                  className="bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold px-5 py-2.5 rounded-2xl shadow-md border-none cursor-pointer text-xs transition-all active:scale-95"
                >
                  {isSimulatedHoliday ? 'Laborar Día Feriado' : 'Laborar Horas Extras'}
                </button>
              </div>
            ) : (
              <div className="flex-1 overflow-hidden px-4 pt-[82px] pb-[100px] flex flex-col justify-between gap-1.5 scrollbar-none select-none">
                {/* Banners Superiores (No empujan el Dial, se agrupan al inicio) */}
                <div className="w-full shrink-0 flex flex-col gap-1.5">
                  {/* Banner de transferencia de llaves pendiente */}
                  {pendingKeyTransfers && pendingKeyTransfers.length > 0 && (
                    <div className="bg-gradient-to-r from-amber-500 to-orange-600 text-white rounded-2xl p-4 shadow-lg mb-1.5 shrink-0 flex flex-col gap-2.5 text-left border border-amber-400/20">
                      <div className="flex items-start gap-2.5">
                        <span className="text-xl mt-0.5 shrink-0">🔑</span>
                        <div>
                          <p className="font-black text-xs">Propuesta de Transferencia de Cierre</p>
                          <p className="text-[10px] text-amber-50/90 leading-normal mt-0.5">
                            {pendingKeyTransfers[0].sender?.name} te ha propuesto cederte la custodia de llaves de la sucursal.
                          </p>
                          {pendingKeyTransfers[0].notes && (
                            <p className="text-[9px] bg-black/10 rounded px-1.5 py-1 mt-1.5 italic">
                              Nota: "{pendingKeyTransfers[0].notes}"
                            </p>
                          )}
                        </div>
                      </div>
                      <div className="flex gap-2 justify-end shrink-0">
                        <button 
                          onClick={() => respondToKeyTransfer(pendingKeyTransfers[0].id, 'accepted')}
                          className="bg-white hover:bg-slate-50 text-orange-600 font-extrabold text-[9.5px] px-3 py-1.5 rounded-lg shadow-sm transition-colors border-none cursor-pointer"
                        >
                          Aceptar Llaves
                        </button>
                        <button 
                          onClick={() => respondToKeyTransfer(pendingKeyTransfers[0].id, 'rejected')}
                          className="bg-transparent hover:bg-black/10 text-white border border-white/50 font-bold text-[9.5px] px-3 py-1.5 rounded-lg transition-colors cursor-pointer"
                        >
                          Rechazar
                        </button>
                      </div>
                    </div>
                  )}
                  
                  {/* Reminders of Premium opening - Mobile */}
                  {isOpeningPremium && storeStatus === 'open' && openingStatus && Number(currentUser.id) === Number(openingStatus.current_responsible_employee_id) && openingSettings.require_opening_checklist && !openingChecklistCompleted && (
                    <div className="bg-emerald-600 text-white px-4 py-3 rounded-2xl flex items-center justify-between shadow-md mb-1 flex-row text-left shrink-0">
                      <div className="flex items-center gap-2">
                        <span className="text-sm">📋</span>
                        <span className="text-xs font-bold">Checklist de apertura pendiente.</span>
                      </div>
                      <button 
                        onClick={() => setShowOpeningChecklistModal(true)} 
                        className="bg-white text-emerald-750 hover:bg-slate-50 font-black text-[9px] px-2.5 py-1 rounded-lg border-none cursor-pointer shrink-0"
                      >
                        Completar
                      </button>
                    </div>
                  )}

                  {isOpeningPremium && storeStatus === 'open' && openingStatus && Number(currentUser.id) === Number(openingStatus.current_responsible_employee_id) && openingSettings.require_opening_roll_call && !openingRollCallCompleted && (
                    <div className="bg-violet-600 text-white px-4 py-3 rounded-2xl flex items-center justify-between shadow-md mb-1 flex-row text-left shrink-0">
                      <div className="flex items-center gap-2">
                        <span className="text-sm">📋</span>
                        <span className="text-xs font-bold">Pase de lista de apertura pendiente.</span>
                      </div>
                      <button 
                        onClick={() => initPaseLista(false)} 
                        className="bg-white text-violet-750 hover:bg-slate-50 font-black text-[9px] px-2.5 py-1 rounded-lg border-none cursor-pointer shrink-0"
                      >
                        Iniciar
                      </button>
                    </div>
                  )}

                  {renderBreakApprovalBanner()}
                </div>
                
                {/* Grupo Centrado del Dial y Alertas (Fijos, sin scroll en el medio de las barras) */}
                <div className="flex-1 flex flex-col justify-center items-center w-full gap-2 shrink-0 max-w-[350px] mx-auto">
                  {/* Timeline Progress Line (Borderless/No Rectangular Box - Redesigned) */}
                  {renderBarraCronologica(true)}

                  {/* Fila Horizontal: Desempeño (Izquierda) - DialPrincipal (Centro) - Pánico (Derecha) */}
                  <div className="flex flex-row items-center justify-center gap-3.5 w-full shrink-0 relative my-2">
                    
                    {/* Botón de Desempeño (Copa Trophy) */}
                    <button
                      type="button"
                      onClick={() => {
                        if (!hasCheckedIn) {
                          showCustomAlert("⚠️ Para ver tu desempeño semanal, primero debes registrar tu entrada.");
                          return;
                        }
                        setShowPerformanceModal(true);
                      }}
                      className={`w-12 h-12 rounded-full flex items-center justify-center transition-all shadow-md relative shrink-0 border ${
                        !hasCheckedIn
                          ? 'bg-slate-100 border-slate-200 text-slate-300 cursor-not-allowed opacity-50'
                          : isDark 
                            ? 'bg-slate-800 border-slate-700 text-amber-400 hover:bg-slate-750 active:scale-95 cursor-pointer' 
                            : 'bg-white border-slate-202 text-amber-500 hover:bg-slate-50 active:scale-95 cursor-pointer'
                      }`}
                    >
                      <Trophy size={20} />
                      {hasCheckedIn && weeklyPerformanceScore !== null && (
                        <span className="absolute -top-1.5 -right-1 bg-violet-650 text-white text-[8px] font-black px-1.5 py-0.5 rounded-full shadow-xs border border-white">
                          {weeklyPerformanceScore}%
                        </span>
                      )}
                    </button>

                    {/* Central Clock Circle Action Button (Middle) */}
                    <div className="shrink-0">
                      <DialPrincipal
                        isMobile={true}
                        isOpeningPremium={isOpeningPremium}
                        storeStatus={storeStatus}
                        openingStatus={openingStatus}
                        currentUser={currentUser}
                        isWithinPerimeter={isWithinPerimeter}
                        globalUsers={globalUsers}
                        clockState={clockState}
                        formattedTime={formattedTime}
                        btnProps={btnProps}
                        lateUsers={lateUsers}
                        currentDay={currentDay}
                        currentSimTime={currentSimTime}
                        shiftConfigs={shiftConfigs}
                        parseTimeToMins={parseTimeToMins}
                        handleAction={handleAction}
                        gpsStatus={gpsStatus}
                        onRequestGPS={requestGPS}
                        isGpsValidationBypassed={isGpsValidationBypassed}
                        hasMealReservation={hasMealReservation}
                        onMealSwapClick={() => setShowMealSwapModal(true)}
                        onEarlyDepartureClick={handleEarlyDepartureClick}
                        onOvertimeClick={handleOvertimeClick}
                        onPanicClick={() => setShowPanicModal(true)}
                        onCallManagerClick={() => {
                          const respUser = globalUsers.find((u: any) => u.id === (openingStatus?.current_responsible_employee_id || 1));
                          showCustomAlert(`📞 Contactando al Encargado: ${respUser?.name || 'Titular'}`);
                        }}
                      />
                    </div>

                    {/* Botón de Pánico (Alerta de Emergencia) */}
                    <button
                      type="button"
                      onClick={() => setShowPanicModal(true)}
                      className={`w-12 h-12 rounded-full flex items-center justify-center transition-all active:scale-95 shadow-md relative shrink-0 border cursor-pointer ${
                        isDark 
                          ? 'bg-rose-950/40 border-rose-900 text-rose-400 hover:bg-rose-900/30' 
                          : 'bg-rose-50 border-rose-100 text-rose-600 hover:bg-rose-100/50'
                      }`}
                    >
                      <AlertOctagon size={20} className="animate-pulse" />
                    </button>

                  </div>

                  {/* Módulo de Notificaciones del Turno - Activo una vez registrada la entrada */}
                  {hasCheckedIn && renderModuloNotificaciones(true)}
                </div>
              </div>
            )
          )}

          {phoneTab !== 'checador' && (
            <div className="flex-1 overflow-hidden p-4 pt-[82px] pb-[100px] scrollbar-none flex flex-col">
              {isSimulated && simulatedTier === 'free' ? (
                <div className="flex-1 flex flex-col items-center justify-center p-6 text-center space-y-4 animate-in zoom-in-95 duration-200">
                  <div className="w-12 h-12 bg-rose-50 border border-rose-100 rounded-2xl flex items-center justify-center text-rose-505 shadow-sm shrink-0">
                    <Lock size={22} className="text-rose-500" />
                  </div>
                  <div className="space-y-1">
                    <h5 className="text-[9px] font-black text-rose-800 uppercase tracking-widest leading-none">Exclusivo Plan Pro</h5>
                    <h4 className="text-[11px] font-black text-slate-805 dark:text-slate-200 leading-tight">Módulo Bloqueado</h4>
                    <p className="text-[8.5px] text-slate-500 font-semibold leading-relaxed max-w-[170px] mx-auto">
                      La gestión de {phoneTab === 'tareas' ? 'Tareas' : phoneTab === 'academia' ? 'Academia' : 'Herramientas'} requiere la Versión Pro del Reloj Checador.
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => {
                      if (typeof setSimulatedTier === 'function') {
                        setSimulatedTier('pro');
                      }
                    }}
                    className="bg-slate-900 hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700 text-white font-black py-2 px-3 rounded-xl text-[8.5px] uppercase tracking-wider transition-all shadow-md active:scale-95 border-none outline-none cursor-pointer mt-1"
                  >
                    Probar Versión Pro
                  </button>
                </div>
              ) : isSimulated ? (
                <div className="flex-1 flex flex-col justify-between">
                  {phoneTab === 'tareas' && (
                    <div className="p-1 text-left animate-in fade-in duration-200 flex-grow flex flex-col justify-between">
                      <div>
                        <div className="flex justify-between items-center mb-3">
                          <h5 className="text-[9.5px] font-black uppercase text-slate-800 dark:text-slate-200 tracking-wider">Tareas del Colaborador</h5>
                          <span className="text-[8px] font-black bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 px-1.5 py-0.5 rounded-full">
                            {((simTask1Done ? 1 : 0) + (simTask2Done ? 1 : 0))} / 2
                          </span>
                        </div>

                        <div className="space-y-2">
                          {/* Tarea 1 */}
                          <label className={`p-2.5 rounded-xl border flex items-center gap-2.5 cursor-pointer transition-all select-none ${
                            simTask1Done ? 'bg-slate-50/50 dark:bg-slate-900/30 border-slate-150 dark:border-slate-800 text-slate-400 dark:text-slate-500' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-350 shadow-sm'
                          }`}>
                            <input 
                              type="checkbox" 
                              checked={simTask1Done}
                              onChange={() => setSimTask1Done(!simTask1Done)}
                              className="rounded border-slate-350 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5 cursor-pointer"
                            />
                            <div className="leading-tight text-left">
                              <p className="text-[8.5px] font-bold">Limpieza General Sucursal</p>
                              <p className="text-[7.5px] text-slate-450">Sanitizar mostradores y barrer entrada</p>
                            </div>
                          </label>

                          {/* Tarea 2 */}
                          <label className={`p-2.5 rounded-xl border flex items-center gap-2.5 cursor-pointer transition-all select-none ${
                            simTask2Done ? 'bg-slate-50/50 dark:bg-slate-900/30 border-slate-150 dark:border-slate-800 text-slate-400 dark:text-slate-500' : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-350 shadow-sm'
                          }`}>
                            <input 
                              type="checkbox" 
                              checked={simTask2Done}
                              onChange={() => setSimTask2Done(!simTask2Done)}
                              className="rounded border-slate-350 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5 cursor-pointer"
                            />
                            <div className="leading-tight text-left">
                              <p className="text-[8.5px] font-bold">Arqueo de Caja y Cierre</p>
                              <p className="text-[7.5px] text-slate-450">Conciliar ventas del día en terminal</p>
                            </div>
                          </label>
                        </div>
                      </div>

                      <div className="bg-blue-50 dark:bg-blue-950/40 border border-blue-100 dark:border-blue-900/30 rounded-xl p-2 text-[7.5px] text-blue-800 dark:text-blue-300 font-medium leading-normal mt-3">
                        💡 Pulsa sobre cada casilla de verificación para marcar o desmarcar las tareas y simular la productividad del checador.
                      </div>
                    </div>
                  )}

                  {phoneTab === 'academia' && (
                    <div className="p-1 text-left animate-in fade-in duration-200 space-y-3">
                      <h5 className="text-[9.5px] font-black uppercase text-slate-800 dark:text-slate-200 tracking-wider">Cursos de Inducción</h5>
                      
                      {/* Curso 1 */}
                      <div className="bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800 p-2.5 rounded-xl space-y-1.5">
                        <div className="flex justify-between items-center">
                          <span className="text-[8.5px] font-black text-slate-800 dark:text-slate-200 uppercase tracking-wide truncate max-w-[120px]">Inducción Básica 360</span>
                          <span className="text-[8px] font-bold text-emerald-600">75%</span>
                        </div>
                        <div className="w-full h-1 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden">
                          <div className="h-full bg-emerald-500 rounded-full" style={{ width: '75%' }}></div>
                        </div>
                      </div>

                      {/* Curso 2 */}
                      <div className="bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800 p-2.5 rounded-xl space-y-1.5">
                        <div className="flex justify-between items-center">
                          <span className="text-[8.5px] font-black text-slate-800 dark:text-slate-200 uppercase tracking-wide truncate max-w-[120px]">Políticas y Valores</span>
                          <span className="text-[8px] font-bold text-blue-600">10%</span>
                        </div>
                        <div className="w-full h-1 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden">
                          <div className="h-full bg-blue-500 rounded-full" style={{ width: '10%' }}></div>
                        </div>
                      </div>

                      {/* Curso 3 */}
                      <div className="bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-800 p-2.5 rounded-xl space-y-1.5 opacity-55">
                        <div className="flex justify-between items-center">
                          <span className="text-[8.5px] font-black text-slate-800 dark:text-slate-200 uppercase tracking-wide truncate max-w-[120px]">Prevención y Seguridad</span>
                          <span className="text-[8px] font-bold text-slate-400">Pendiente</span>
                        </div>
                        <div className="w-full h-1 bg-slate-200 dark:bg-slate-800 rounded-full"></div>
                      </div>
                    </div>
                  )}

                  {phoneTab === 'herramientas' && (
                    <div className="p-1 text-left animate-in fade-in duration-200 space-y-3">
                      <h5 className="text-[9.5px] font-black uppercase text-slate-800 dark:text-slate-200 tracking-wider">Herramientas</h5>
                      
                      <div className="grid grid-cols-2 gap-2">
                        <button type="button" className="p-2.5 bg-slate-50 dark:bg-slate-900/40 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-800 rounded-xl text-center flex flex-col items-center justify-center gap-1 transition-all active:scale-[0.98] border-none bg-transparent cursor-pointer">
                          <span className="text-sm">🏖️</span>
                          <span className="text-[7.5px] font-black text-slate-750 dark:text-slate-300 uppercase leading-none">Solicitar Vacaciones</span>
                        </button>

                        <button type="button" className="p-2.5 bg-slate-50 dark:bg-slate-900/40 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-800 rounded-xl text-center flex flex-col items-center justify-center gap-1 transition-all active:scale-[0.98] border-none bg-transparent cursor-pointer">
                          <span className="text-sm">📄</span>
                          <span className="text-[7.5px] font-black text-slate-750 dark:text-slate-300 uppercase leading-none">Recibos Nómina</span>
                        </button>

                        <button type="button" className="p-2.5 bg-slate-50 dark:bg-slate-900/40 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-800 rounded-xl text-center flex flex-col items-center justify-center gap-1 transition-all active:scale-[0.98] border-none bg-transparent cursor-pointer">
                          <span className="text-sm">🤕</span>
                          <span className="text-[7.5px] font-black text-slate-750 dark:text-slate-300 uppercase leading-none">Nueva Incidencia</span>
                        </button>

                        <button type="button" className="p-2.5 bg-slate-50 dark:bg-slate-900/40 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-800 rounded-xl text-center flex flex-col items-center justify-center gap-1 transition-all active:scale-[0.98] border-none bg-transparent cursor-pointer">
                          <span className="text-sm">🔑</span>
                          <span className="text-[7.5px] font-black text-slate-750 dark:text-slate-300 uppercase leading-none">Cambiar PIN</span>
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              ) : (
                <>
                  {phoneTab === 'tareas' && (
                    <TaskRunner currentUser={currentUser} onBack={() => setPhoneTab('checador')} hideHeader={true} />
                  )}
                  {phoneTab === 'academia' && (
                    <Academia onBack={() => setPhoneTab('checador')} />
                  )}
                  {phoneTab === 'nomina' && (
                    <NominaColaborador isDark={isDark} />
                  )}
                  {phoneTab === 'evaluacion360' && (
                    <Evaluacion360 onBack={() => setPhoneTab('herramientas')} />
                  )}
                  {phoneTab === 'organigrama' && (
                    <div className="h-full w-full bg-white dark:bg-slate-955 rounded-3xl p-4 overflow-y-auto shadow-inner">
                      <React.Suspense fallback={<div className="p-4 text-center text-xs text-slate-450">Cargando Organigrama...</div>}>
                        <RecursosHumanos readOnly={true} initialTab="organigrama" />
                      </React.Suspense>
                    </div>
                  )}
                  {phoneTab === 'perfil' && renderProfilePanel(true)}
                  {phoneTab === 'herramientas' && (
                    <div className="space-y-4">
                      {innerTool === null ? (
                        <div className="text-center py-12">
                          <p className="text-slate-400 font-bold text-sm">Cargando Caja de Herramientas...</p>
                          <p className="text-xs text-slate-500 mt-2">Usa el botón flotante 🛠️ para ver las herramientas rápidas.</p>
                        </div>
                      ) : (
                        <div className="flex flex-col h-full bg-transparent">
                          <button onClick={() => setInnerTool(null)} className="text-xs font-bold text-slate-505 mb-4 flex items-center gap-1">
                            ← Volver a Herramientas
                          </button>
                          {innerTool === 'chat' && renderToolChat()}
                          {innerTool === 'soplon' && renderToolSoplon()}
                          {innerTool === 'buzon' && renderToolBuzon()}
                          {innerTool === 'transfer' && renderToolTransfer()}
                          {innerTool === 'huida' && renderToolHuida()}
                        </div>
                      )}
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {/* A3. MOBILE BOTTOM NAVIGATION */}
          <MobileBottomNav phoneTab={phoneTab} setPhoneTab={setPhoneTab} setInnerTool={setInnerTool} isDark={isDark} clockState={clockState} showCustomAlert={showCustomAlert} isStoreClosed={isStoreClosed} />
        </div>
      )}

      {/* RENDER OPERATIONAL MODALS FOR MOBILE */}
      {isScrollableMobile && !isStoreClosed && renderFloatingActionButton()}
      {isScrollableMobile && renderFabOperationsSheet()}
      {isScrollableMobile && renderTaskCreatorModal()}
      {isScrollableMobile && renderCopilotChatDrawer()}

      {/* B. DESKTOP VIEW LAYOUT (isScrollableMobile is false) */}
      {!isScrollableMobile && (
        <div className="max-w-7xl w-full mx-auto flex flex-col gap-6">
          
          {phoneTab === 'checador' && (
            isStoreClosed ? (
              renderStoreClosedScreen(false)
            ) : (shiftConfigs[currentUser?.id]?.restDay === currentDay || isSimulatedHoliday) && !isOvertimeUnlocked[currentUser?.id] ? (
              <div className={`w-full border rounded-3xl p-12 flex flex-col items-center justify-center text-center transition-colors gap-4 ${
                isDark ? 'bg-slate-900/20 border-slate-900/40' : 'bg-white border-slate-202 shadow-md'
              }`}>
                <div className="w-24 h-24 bg-indigo-50 dark:bg-indigo-950/40 rounded-full flex items-center justify-center text-4xl mb-2 shadow-inner border-4 border-white dark:border-slate-800">
                  {isSimulatedHoliday ? '📅' : '🌴'}
                </div>
                <h2 className="text-3xl font-black text-slate-850 dark:text-slate-100 mb-1">
                  {isSimulatedHoliday ? 'Día Feriado Obligatorio (LFT)' : 'Día de Descanso'}
                </h2>
                <p className="text-slate-500 dark:text-slate-400 text-base font-medium max-w-lg leading-relaxed font-sans mb-4">
                  {isSimulatedHoliday 
                    ? 'Hoy conmemoramos el Natalicio de Benito Juárez de acuerdo a la Ley Federal del Trabajo. ¡Disfruta tu descanso de ley! 🇲🇽'
                    : '¡Es tu derecho a la desconexión digital! Relájate, recarga energías y disfruta tu día de descanso. Tu equipo de trabajo te cubre hoy. 🌟'}
                </p>
                <button
                  type="button"
                  onClick={handleOvertimeClick}
                  className="bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold px-6 py-3 rounded-2xl shadow-md border-none cursor-pointer text-sm transition-all active:scale-95 animate-pulse"
                >
                  {isSimulatedHoliday ? 'Laborar Día Feriado' : 'Laborar Horas Extras'}
                </button>
              </div>
            ) : (
              <div className="grid grid-cols-12 gap-8 items-start">
              
              {/* Left Column (span 7): Clock, Timeline, Actions */}
              <div className={`col-span-7 flex flex-col gap-6 p-6 rounded-3xl border transition-colors ${
                isDark ? 'bg-slate-900/20 border-slate-900' : 'bg-white border-slate-202 shadow-sm'
              }`}>
              
              {/* Banner de transferencia de llaves pendiente - Desktop */}
              {pendingKeyTransfers && pendingKeyTransfers.length > 0 && (
                <div className="bg-gradient-to-r from-amber-500 to-orange-600 text-white rounded-2xl p-5 shadow-lg mb-2 flex flex-col sm:flex-row items-center justify-between gap-4 text-left border border-amber-400/20">
                  <div className="flex items-start gap-3">
                    <span className="text-2xl mt-0.5 shrink-0">🔑</span>
                    <div>
                      <p className="font-black text-sm">Propuesta de Transferencia de Cierre</p>
                      <p className="text-xs text-amber-50/90 leading-normal mt-1">
                        {pendingKeyTransfers[0].sender?.name} te ha propuesto cederte la custodia de llaves de la sucursal.
                      </p>
                      {pendingKeyTransfers[0].notes && (
                        <p className="text-xs bg-black/10 rounded px-2 py-1.5 mt-2 italic">
                          Nota: "{pendingKeyTransfers[0].notes}"
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="flex gap-2.5 w-full sm:w-auto justify-end shrink-0">
                    <button 
                      onClick={() => respondToKeyTransfer(pendingKeyTransfers[0].id, 'accepted')}
                      className="bg-white hover:bg-slate-50 text-orange-600 font-extrabold text-xs px-4 py-2 rounded-xl shadow-md transition-colors border-none cursor-pointer"
                    >
                      Aceptar Llaves
                    </button>
                    <button 
                      onClick={() => respondToKeyTransfer(pendingKeyTransfers[0].id, 'rejected')}
                      className="bg-transparent hover:bg-black/10 text-white border border-white/50 font-bold text-xs px-4 py-2 rounded-xl transition-colors cursor-pointer"
                    >
                      Rechazar
                    </button>
                  </div>
                </div>
              )}

            {/* Reminders of Premium opening - Desktop */}
            {isOpeningPremium && storeStatus === 'open' && openingStatus && Number(currentUser.id) === Number(openingStatus.current_responsible_employee_id) && openingSettings.require_opening_checklist && !openingChecklistCompleted && (
              <div className="bg-emerald-600 text-white px-5 py-3.5 rounded-2xl flex items-center justify-between shadow-md mb-2 shrink-0 animate-pulse text-left">
                <div className="flex items-center gap-2.5">
                  <span className="text-base">📋</span>
                  <span className="text-xs font-black">Checklist de apertura pendiente. Completa las tareas operativas para abrir la sucursal de hoy.</span>
                </div>
                <button 
                  onClick={() => setShowOpeningChecklistModal(true)} 
                  className="bg-white text-emerald-750 hover:bg-slate-50 font-black text-xs px-4 py-2 rounded-xl border-none cursor-pointer shadow-sm transition-all active:scale-95"
                >
                  Completar Checklist
                </button>
              </div>
            )}

            {isOpeningPremium && storeStatus === 'open' && openingStatus && Number(currentUser.id) === Number(openingStatus.current_responsible_employee_id) && openingSettings.require_opening_roll_call && !openingRollCallCompleted && (
              <div className="bg-violet-600 text-white px-5 py-3.5 rounded-2xl flex items-center justify-between shadow-md mb-2 shrink-0 animate-pulse text-left">
                <div className="flex items-start gap-2.5">
                  <span className="text-base">📋</span>
                  <span className="text-xs font-black">Pase de lista de apertura pendiente. Pasa asistencia al equipo de apertura.</span>
                </div>
                <button 
                  onClick={() => initPaseLista(false)} 
                  className="bg-white text-violet-750 hover:bg-slate-50 font-black text-xs px-4 py-2 rounded-xl border-none cursor-pointer shadow-sm transition-all active:scale-95"
                >
                  Iniciar Pase Lista
                </button>
              </div>
            )}
                
                {renderBreakApprovalBanner()}
                
                {/* Timeline Progress (No outer rectangular border - Unified) */}
                {renderBarraCronologica(false)}


                {/* Fading Divider below timeline section */}
                <div className="w-3/4 mx-auto h-[1px] bg-gradient-to-r from-transparent via-slate-200 to-transparent my-5"></div>

                {/* Clock Dial Area */}
                <div className="flex flex-col items-center justify-center py-2 mt-0 relative">
                  {isOpeningPremium && storeStatus === 'closed' && (
                    <div className={`px-5 py-2.5 rounded-full flex items-center justify-center gap-1.5 shadow-inner border mb-5 select-none shrink-0 text-center animate-fade-in ${
                      Number(currentUser.id) === Number(openingStatus ? openingStatus.current_responsible_employee_id : 1) && !isWithinPerimeter
                        ? 'bg-violet-50 dark:bg-violet-955/20 border-violet-300 dark:border-violet-800/50 text-violet-750 dark:text-violet-300 font-black animate-pulse'
                        : 'bg-slate-100 dark:bg-slate-800/40 text-slate-600 dark:text-slate-400 border-slate-200/50'
                    }`}>
                      <span className="animate-pulse text-sm">⏳</span>
                      <span className="text-[11px] font-extrabold uppercase tracking-wide">
                        {(() => {
                          const responsibleId = openingStatus ? openingStatus.current_responsible_employee_id : 1;
                          if (Number(currentUser.id) === Number(responsibleId)) {
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

                    <DialPrincipal
                      isMobile={false}
                      isOpeningPremium={isOpeningPremium}
                      storeStatus={storeStatus}
                      openingStatus={openingStatus}
                      currentUser={currentUser}
                      isWithinPerimeter={isWithinPerimeter}
                      globalUsers={globalUsers}
                      clockState={clockState}
                      formattedTime={formattedTime}
                      btnProps={btnProps}
                      lateUsers={lateUsers}
                      currentDay={currentDay}
                      currentSimTime={currentSimTime}
                      shiftConfigs={shiftConfigs}
                      parseTimeToMins={parseTimeToMins}
                      handleAction={handleAction}
                      gpsStatus={gpsStatus}
                      onRequestGPS={requestGPS}
                      isGpsValidationBypassed={isGpsValidationBypassed}
                      hasMealReservation={hasMealReservation}
                      onMealSwapClick={() => setShowMealSwapModal(true)}
                      onEarlyDepartureClick={handleEarlyDepartureClick}
                      onOvertimeClick={handleOvertimeClick}
                      onPanicClick={() => setShowPanicModal(true)}
                      onCallManagerClick={() => {
                        const respUser = globalUsers.find((u: any) => u.id === (openingStatus?.current_responsible_employee_id || 1));
                        showCustomAlert(`📞 Contactando al Encargado: ${respUser?.name || 'Titular'}`);
                      }}
                    />

                    {/* Módulo de Notificaciones de Turno para Desktop */}
                    {hasCheckedIn && (
                      <div className="w-full max-w-[340px] mt-6 mx-auto animate-fade-in">
                        {renderModuloNotificaciones(false)}
                      </div>
                    )}
                  
                  {/* Desktop Wait Queue & Absence Helpers - Side by Side */}
                  <div className="flex items-center justify-center gap-3 mt-5.5 w-full max-w-[340px] mx-auto">

                  </div>
                </div>
              </div>
                {/* Bottom Row (span 12): Tasks & Alerts Hub */}
              <div className={`col-span-12 border rounded-3xl p-5 flex flex-col gap-3 h-[200px] mt-4 transition-colors ${
                isDark ? 'bg-slate-900/20 border-slate-905' : 'bg-white border-slate-202 shadow-md'
              }`}>
                
                {/* Dynamic Alert Banner */}
                {(() => {
                  if (clockState === 'inactive' || clockState === 'waiting_room') {
                    return null;
                  }
                  const alerts = buddyAlerts[currentUser.id] || [];
                  const sameMealRes = Object.values(reservedMeals).flat().some(r => r.userId === currentUser.id);
                  const needsMealRes = clockState === 'active' && !sameMealRes;
                  
                  let alertMsg = null;
                  let alertBg = isDark ? "bg-violet-500/10 border-violet-500/20 text-violet-400" : "bg-violet-50 border-violet-100 text-violet-600";
                  
                  if (privateMessages[currentUser.id]) {
                    alertMsg = `🚨 Mensaje del Admin: ${privateMessages[currentUser.id]}`;
                    alertBg = "bg-rose-500/10 border-rose-500/20 text-rose-505 animate-pulse";
                  } else if (alerts.length > 0) {
                    alertMsg = `⚠️ ${alerts[0].msg}`;
                    alertBg = "bg-rose-500/10 border-rose-500/20 text-rose-505";
                  } else if (needsMealRes) {
                    alertMsg = "🍔 Tienes pendiente apartar tu comida del día.";
                    alertBg = "bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400";
                  } else if (currentUser?.has_completed_induction === false) {
                    alertMsg = "💡 Sugerencia: Puedes registrar tu entrada normalmente, pero te recomendamos completar tu Inducción en la Academia para habilitar más opciones.";
                    alertBg = isDark ? "bg-amber-500/10 border-amber-500/25 text-amber-400" : "bg-amber-50 border-amber-200 text-amber-700";
                  }

                  if (!alertMsg) return null;

                  return (
                    <div className={`px-4 py-2.5 rounded-xl border text-xs font-bold text-left flex items-start gap-2 shrink-0 ${alertBg}`}>
                      <span>📢</span>
                      <span className="flex-1 whitespace-normal">{alertMsg}</span>
                    </div>
                  );
                })()}

                {/* Hub Columns Headers */}
                <div className="flex justify-between items-center text-xs font-extrabold text-slate-400 uppercase tracking-widest border-b border-slate-800/10 dark:border-slate-800/60 pb-2 shrink-0">
                  <span className="flex items-center gap-1.5">📋 Tareas del Día (Asignaciones Operativas)</span>
                  <span>Bolsa de Trabajo (Tomar Tareas Libres)</span>
                </div>

                <div className="grid grid-cols-2 gap-6 flex-1 min-h-0">
                  
                  {/* Left Column: Tareas del Día */}
                  <div className="flex flex-col gap-2 overflow-y-auto pr-1 scrollbar-none text-left">
                    {(() => {
                      const myAssignments = assignments.filter(a => a.userId === currentUser.id && ['pending', 'in_progress', 'paused', 'completed', 'awaiting_validation'].includes(a.status));
                      if (myAssignments.length === 0) {
                        return (
                          <div className="flex items-center justify-center h-full text-xs text-slate-400 italic text-center font-medium">
                            No hay tareas de rutina asignadas para ti en este turno.
                          </div>
                        );
                      }
                      return myAssignments.map(assignment => {
                        const task = tasks.find(t => t.id === assignment.taskId);
                        if (!task) return null;
                        
                        return (
                          <div key={assignment.id} className={`flex items-center justify-between p-3 rounded-xl text-left gap-2 border ${
                            isDark ? 'bg-slate-955/20 border-slate-900/60' : 'bg-slate-50 border-slate-202/60'
                          }`}>
                            <div className="min-w-0 flex-1 text-left">
                              <p className="text-xs font-extrabold text-slate-800 dark:text-slate-202 truncate leading-snug">{task.title}</p>
                              <p className="text-[9px] text-slate-455 uppercase font-black tracking-wider mt-0.5">{task.priority === 'bloqueante' ? '🚨 Urgente' : 'Rutina'}</p>
                            </div>
                            
                            <div className="flex-shrink-0">
                              {assignment.status === 'pending' && (
                                <button 
                                  onClick={() => startTask(assignment.id, currentSimTime)}
                                  className="w-7 h-7 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-505 flex items-center justify-center hover:bg-emerald-500/20 active:scale-90 transition-all focus:outline-none"
                                >
                                  <Play size={11} fill="currentColor" className="ml-0.5" />
                                </button>
                              )}
                              {assignment.status === 'in_progress' && (
                                <button 
                                  onClick={() => completeTask(assignment.id, currentSimTime, 'Completado')}
                                  className="w-7 h-7 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-605 dark:text-amber-400 flex items-center justify-center hover:bg-amber-500/20 active:scale-90 transition-all focus:outline-none"
                                >
                                  <Check size={11} strokeWidth={3} />
                                </button>
                              )}
                              {(assignment.status === 'completed' || assignment.status === 'awaiting_validation') && (
                                <span className="w-6.5 h-6.5 rounded-full bg-emerald-500/15 border border-emerald-500/20 text-emerald-505 flex items-center justify-center text-[10px] font-black font-sans">
                                  ✓
                                </span>
                              )}
                            </div>
                          </div>
                        );
                      });
                    })()}
                  </div>

                  {/* Right Column: Bolsa de Trabajo */}
                  <div className="relative flex flex-col min-h-0 border-l border-slate-800/10 dark:border-slate-800/60 pl-6">
                    {(() => {
                      const isFreemium = currentTier === 'freemium';
                      if (isFreemium) {
                        return (
                          <div className={`absolute inset-0 backdrop-blur-xs flex flex-col items-center justify-center text-center p-3 rounded-xl z-20 border ${
                            isDark ? 'bg-slate-955/85 border-slate-900' : 'bg-white/90 border-slate-202'
                          }`}>
                            <Lock size={18} className="text-violet-500 mb-1 animate-pulse" />
                            <p className="text-[10px] font-black text-violet-505 uppercase tracking-widest leading-none">Bolsa Pro</p>
                            <p className="text-[9px] text-slate-500 dark:text-slate-400 leading-tight mt-1 max-w-[200px]">
                              Actualiza tu plan de suscripción SaaS corporativo para desbloquear la Bolsa de Trabajo.
                            </p>
                          </div>
                        );
                      }

                      const poolAssignments = assignments.filter(a => {
                        if (a.userId !== null || a.status !== 'pending') return false;
                        const t = tasks.find(tsk => tsk.id === a.taskId);
                        if (!t) return false;
                        return t.targetId === null || t.targetId === undefined || Number(t.targetId) === Number(currentUser.job_role_id);
                      });

                      if (poolAssignments.length === 0) {
                        return (
                          <div className="flex items-center justify-center h-full text-xs text-slate-400 italic text-center">
                            No hay ofertas de trabajo disponibles en la bolsa hoy.
                          </div>
                        );
                      }

                      return (
                        <div className="flex flex-col gap-2 overflow-y-auto pr-1 scrollbar-none flex-1">
                          {poolAssignments.map(assignment => {
                            const task = tasks.find(t => t.id === assignment.taskId);
                            if (!task) return null;
                            
                            return (
                              <div key={assignment.id} className={`flex items-center justify-between p-3 rounded-xl text-left gap-2 border ${
                                isDark ? 'bg-slate-955/20 border-slate-900/60' : 'bg-slate-50 border-slate-202/60'
                              }`}>
                                <div className="min-w-0 flex-1 text-left">
                                  <p className="text-xs font-bold text-slate-805 dark:text-slate-202 truncate leading-snug">{task.title}</p>
                                  <p className="text-[9px] text-emerald-500 dark:text-emerald-450 font-extrabold mt-0.5 font-sans">+{task.points || 15} pts</p>
                                </div>
                                <button 
                                  onClick={() => grabTaskFromPool(assignment.id, currentUser.id, currentSimTime)}
                                  className="flex-shrink-0 bg-violet-650 hover:bg-violet-750 text-white font-extrabold text-[10px] px-3.5 py-1 rounded-lg active:scale-90 transition-all focus:outline-none"
                                >
                                  Tomar Tarea
                                </button>
                              </div>
                            );
                          })}
                        </div>
                      );
                    })()}
                  </div>
                </div>
              </div>

              </div>
            )
          )}

          {/* Desktop Tareas runner */}
          {phoneTab === 'tareas' && (
            <div className={`w-full mx-auto border flex flex-col animate-fade-in-up p-6 md:p-8 rounded-3xl max-w-4xl transition-colors ${
              isDark ? 'bg-slate-900/20 border-slate-900' : 'bg-white border-slate-250 shadow-sm'
            }`}>
              <h3 className="font-extrabold text-xl text-slate-800 dark:text-slate-100 mb-6 flex items-center gap-2">
                <span>✅</span> Tareas y Rutinas Asignadas
              </h3>
              <TaskRunner currentUser={currentUser} onBack={() => setPhoneTab('checador')} hideHeader={true} />
            </div>
          )}

          {/* Desktop Academia */}
          {phoneTab === 'academia' && (
            <div className={`w-full mx-auto border flex flex-col animate-fade-in-up p-6 md:p-8 rounded-3xl max-w-5xl transition-colors ${
              isDark ? 'bg-slate-900/20 border-slate-900' : 'bg-white border-slate-250 shadow-sm'
            }`}>
              <Academia onBack={() => setPhoneTab('checador')} />
            </div>
          )}

          {/* Desktop Nómina */}
          {phoneTab === 'nomina' && (
            <div className={`w-full mx-auto border flex flex-col animate-fade-in-up p-6 md:p-8 rounded-3xl max-w-4xl transition-colors ${
              isDark ? 'bg-slate-900/20 border-slate-900' : 'bg-white border-slate-250 shadow-sm'
            }`}>
              <h3 className="font-extrabold text-xl text-slate-800 dark:text-slate-100 mb-6 flex items-center gap-2">
                <span>💵</span> Resumen y Liquidación de Pagos
              </h3>
              <NominaColaborador isDark={isDark} />
            </div>
          )}

          {/* Desktop Evaluacion360 */}
          {phoneTab === 'evaluacion360' && (
            <div className="w-full mx-auto bg-slate-900 rounded-3xl shadow-2xl p-6 md:p-8 max-w-3xl border border-slate-800">
              <Evaluacion360 onBack={() => setPhoneTab('herramientas')} />
            </div>
          )}

          {/* Desktop Herramientas list */}
          {phoneTab === 'herramientas' && (
            <div className={`w-full mx-auto border flex flex-col gap-4 animate-fade-in text-left p-6 md:p-8 rounded-3xl max-w-3xl transition-colors ${
              isDark ? 'bg-slate-900/20 border-slate-900' : 'bg-white border-slate-255 shadow-sm'
            }`}>
              {innerTool === null ? (
                <>
                  <h4 className="font-extrabold text-xl text-slate-805 dark:text-slate-100 mb-4">Caja de Herramientas</h4>
                  
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <button 
                      onClick={() => { setInnerTool('chat'); fetchChatMessages(); }} 
                      className={`p-5 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${
                        isDark ? 'border-slate-800 bg-slate-955/40 hover:bg-slate-900/40' : 'border-slate-205 bg-white hover:bg-slate-50'
                      }`}
                    >
                      <div className="w-12 h-12 rounded-full bg-slate-800 text-violet-400 flex items-center justify-center shrink-0">
                        <MessageSquare size={20} />
                      </div>
                      <div>
                        <p className="font-bold text-sm text-slate-850 dark:text-slate-100">Chat de Equipo 💬</p>
                        <p className="text-xs text-slate-500 mt-0.5">Mensajes temporales entre colaboradores (Se borran cada semana)</p>
                      </div>
                    </button>

                    <button 
                      onClick={() => setInnerTool('soplon')} 
                      className={`p-5 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${
                        isDark ? 'border-slate-800 bg-slate-955/40 hover:bg-slate-900/40' : 'border-slate-205 bg-white hover:bg-slate-50'
                      }`}
                    >
                      <div className="w-12 h-12 rounded-full bg-slate-800 text-rose-400 flex items-center justify-center shrink-0">
                        <AlertOctagon size={20} />
                      </div>
                      <div>
                        <p className="font-bold text-sm text-slate-850 dark:text-slate-100">El Soplón 📢</p>
                        <p className="text-xs text-slate-500 mt-0.5">Reportar ausencias o faltas de compañeros de forma directa</p>
                      </div>
                    </button>

                    <button 
                      onClick={() => setInnerTool('buzon')} 
                      className={`p-5 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${
                        isDark ? 'border-slate-800 bg-slate-955/40 hover:bg-slate-900/40' : 'border-slate-205 bg-white hover:bg-slate-50'
                      }`}
                    >
                      <div className="w-12 h-12 rounded-full bg-slate-800 text-sky-400 flex items-center justify-center shrink-0">
                        <Fingerprint size={20} />
                      </div>
                      <div>
                        <p className="font-bold text-sm text-slate-850 dark:text-slate-100">Buzón Anónimo RRHH 🕵️</p>
                        <p className="text-xs text-slate-500 mt-0.5">Enviar sugerencias o reportes generales 100% privados</p>
                      </div>
                    </button>

                    {shiftConfigs[currentUser?.id]?.portadorLlaves !== 'Ninguno' && shiftConfigs[currentUser?.id]?.portadorLlaves !== 'ninguno' && (
                      <button 
                        onClick={() => setInnerTool('transfer')} 
                        className={`p-5 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${
                          isDark ? 'border-slate-800 bg-slate-955/40 hover:bg-slate-900/40' : 'border-slate-205 bg-white hover:bg-slate-50'
                        }`}
                      >
                        <div className="w-12 h-12 rounded-full bg-slate-800 text-indigo-400 flex items-center justify-center shrink-0">
                          <Key size={20} />
                        </div>
                        <div>
                          <h5 className="font-bold text-sm text-slate-850 dark:text-slate-100">Transferir Cierre 🔑</h5>
                          <p className="text-xs text-slate-500 mt-0.5">Ceder llaves de la sucursal a un compañero</p>
                        </div>
                      </button>
                    )}

                    <button 
                      onClick={() => setInnerTool('huida')} 
                      className={`p-5 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${
                        isDark ? 'border-slate-800 bg-slate-955/40 hover:bg-slate-900/40' : 'border-slate-205 bg-white hover:bg-slate-50'
                      }`}
                    >
                      <div className="w-12 h-12 rounded-full bg-slate-800 text-amber-500 flex items-center justify-center shrink-0">
                        <WifiOff size={20} />
                      </div>
                      <div>
                        <p className="font-bold text-sm text-slate-850 dark:text-slate-100">Simular Desconexión (Huida) 🏃</p>
                        <p className="text-xs text-slate-500 mt-0.5">Detección de desconexión sin entregar el turno</p>
                      </div>
                    </button>

                    <button 
                      onClick={() => setPhoneTab('evaluacion360')} 
                      className={`p-5 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${
                        isDark ? 'border-slate-800 bg-slate-950/40 hover:bg-slate-900/40' : 'border-slate-205 bg-white hover:bg-slate-50'
                      }`}
                    >
                      <div className="w-12 h-12 rounded-full bg-slate-800 text-amber-400 flex items-center justify-center shrink-0">
                        <Star size={20} />
                      </div>
                      <div>
                        <p className="font-bold text-sm text-slate-850 dark:text-slate-100">Evaluación de Compañeros ⭐</p>
                        <p className="text-xs text-slate-500 mt-0.5">Evaluar desempeño y puntualidad en el turno</p>
                      </div>
                    </button>

                    <button 
                      onClick={() => setShowDesktopOrgModal(true)} 
                      className={`p-5 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${
                        isDark ? 'border-slate-800 bg-slate-955/40 hover:bg-slate-900/40' : 'border-slate-205 bg-white hover:bg-slate-50'
                      }`}
                    >
                      <div className="w-12 h-12 rounded-full bg-slate-800 text-emerald-400 flex items-center justify-center shrink-0">
                        <Network size={20} />
                      </div>
                      <div>
                        <p className="font-bold text-sm text-slate-855 dark:text-slate-100">Organigrama de la Empresa 🕸️</p>
                        <p className="text-xs text-slate-500 mt-0.5">Consulta los puestos y responsabilidades</p>
                      </div>
                    </button>
                  </div>
                </>
              ) : (
                <div className="flex flex-col h-full bg-transparent">
                  <button onClick={() => setInnerTool(null)} className="text-xs font-bold text-slate-500 mb-4 flex items-center gap-1">
                    ← Volver a Herramientas
                  </button>
                  {innerTool === 'chat' && renderToolChat()}
                  {innerTool === 'soplon' && renderToolSoplon()}
                  {innerTool === 'buzon' && renderToolBuzon()}
                  {innerTool === 'transfer' && renderToolTransfer()}
                  {innerTool === 'huida' && renderToolHuida()}
                </div>
              )}
            </div>
          )}

          {/* Desktop Perfil & Ajustes View */}
          {phoneTab === 'perfil' && (
            <div className="max-w-xl mx-auto w-full">
              {renderProfilePanel(false)}
            </div>
          )}
        </div>
      )}

          {/* ========================================================================= */}
          {/* ======================= RESTORED SYSTEM MODALS ========================== */}
          {/* ========================================================================= */}

          {/* Modal Pase de Lista */}
          {showPaseListaModal && (
            <div className="absolute inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex flex-col pt-12 pb-6 px-4 animate-fade-in-up">
              <div className="bg-white rounded-3xl p-5 w-full flex-grow flex flex-col shadow-2xl relative overflow-hidden text-slate-800">
                <div className="flex justify-between items-center mb-4">
                  <h3 className="font-extrabold text-xl text-slate-800">Pase de Lista</h3>
                  <button onClick={toggleSelectAll} className="text-xs bg-indigo-50 text-indigo-700 font-bold px-3 py-1.5 rounded-full border border-indigo-100 hover:bg-indigo-100">
                    Seleccionar Todos
                  </button>
                </div>
                
                <div className="text-xs text-slate-500 mb-4 bg-slate-50 p-3 rounded-lg border border-slate-200">
                  <p>Verifica visualmente quién está en la puerta. El sistema validará su puntualidad basado en los horarios de cada uno.</p>
                </div>

                <div className="flex-grow overflow-y-auto space-y-2 mb-4">
                  {paseListaEmployees.length === 0 && (
                    <div className="text-center p-6 text-slate-400 text-sm italic">
                      Nadie ha presionado "Ya llegué" en su celular aún.
                    </div>
                  )}
                  {paseListaEmployees.map((emp, index) => (
                    <div key={index} className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-100">
                      <div className="flex items-center gap-3">
                        <div 
                          onClick={() => {
                            const updated = [...paseListaEmployees];
                            updated[index].selected = !updated[index].selected;
                            setPaseListaEmployees(updated);
                          }}
                          className={`w-6 h-6 rounded-md flex items-center justify-center cursor-pointer transition-colors ${emp.selected ? 'bg-indigo-600' : 'bg-slate-200'}`}
                        >
                          {emp.selected && <span className="text-white text-xs">✓</span>}
                        </div>
                        <div>
                          <p className="font-bold text-sm text-slate-800">{emp.name}</p>
                          <p className="text-[10px] text-slate-500 mb-1">
                            Límite: {Math.floor(emp.toleranceEndMins/60)}:{(emp.toleranceEndMins%60).toString().padStart(2,'0')}
                          </p>
                          {emp.onTime !== undefined && (
                            <span className={`text-[9px] font-bold px-2 py-0.5 rounded-md ${emp.onTime ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>
                              {emp.onTime ? `✓ En Tolerancia (${emp.statusLabel})` : `⚠️ Fuera de Tolerancia (${emp.statusLabel})`}
                            </span>
                          )}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="mb-4 pt-3 border-t border-slate-100">
                  <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Añadir Manual (Olvido de Celular)</p>
                  <div className="flex gap-2">
                    <input 
                      type="text" 
                      placeholder="Nombre del empleado..." 
                      className="flex-grow bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      value={kioscoInput}
                      onChange={(e) => setKioscoInput(e.target.value)}
                    />
                    <button onClick={handleKioscoAdd} className="bg-slate-800 text-white font-bold px-4 rounded-xl hover:bg-slate-900">+</button>
                  </div>
                </div>

                <button onClick={handleSubmitPaseLista} className="w-full bg-indigo-600 text-white font-extrabold py-4 rounded-2xl shadow-lg mt-auto hover:bg-indigo-700 active:scale-95 transition-transform">
                  Confirmar Accesos a Tienda
                </button>
              </div>
            </div>
          )}

          {/* Modal Forzosa */}
          {showForzosaModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
              <div className="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl animate-fade-in-up border-4 border-orange-500 text-slate-800">
                <h3 className="font-black text-xl text-orange-600 mb-2">⚠️ Apertura Forzosa</h3>
                <p className="text-sm text-slate-600 mb-4">El Titular no avisó de su ausencia. Si tomas el control, se generará una alerta de seguridad.</p>
                <textarea className="w-full bg-orange-50 border border-orange-200 rounded-xl p-3 text-sm mb-4 outline-none focus:ring-2 focus:ring-orange-500" rows={3} placeholder="Ej. El titular olvidó su celular..."></textarea>
                <button onClick={handleAperturaForzosa} className="w-full bg-orange-600 text-white font-bold py-4 rounded-2xl mb-2 shadow-lg">Tomar el Control y Abrir</button>
                <button onClick={() => setShowForzosaModal(false)} className="w-full bg-slate-100 text-slate-700 font-bold py-3 rounded-2xl">Cancelar</button>
              </div>
            </div>
          )}

          {/* Modal Eval */}
          {showEvalModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
              <div className="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl animate-fade-in-up text-slate-800">
                <div className="text-center mb-6">
                  <span className="text-4xl">🌟</span>
                  <h3 className="font-bold text-xl text-slate-800 mt-3">Evaluación 360</h3>
                  <p className="text-sm text-slate-500 mt-2 bg-slate-50 p-3 rounded-xl border border-slate-100">
                    🔒 <strong className="text-slate-700">Tus respuestas son 100% anónimas para tus compañeros.</strong> Evalúa con honestidad.
                  </p>
                </div>
                <div className="mb-6">
                  <div className="flex justify-between items-center bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    {[1, 2, 3, 4, 5].map(star => (
                      <button key={star} onClick={() => setEvalStars(star)} className={`text-3xl transition-transform hover:scale-125 ${evalStars >= star ? 'text-amber-400' : 'text-slate-300 grayscale'}`}>⭐</button>
                    ))}
                  </div>
                </div>
                <button onClick={submitEvaluation} disabled={evalStars === 0} className={`w-full font-bold py-4 rounded-2xl transition-colors ${evalStars > 0 ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-400'}`}>Enviar y Registrar Salida</button>
              </div>
            </div>
          )}

          {/* Modal Amnesty */}
          {showAmnestyModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-6 animate-fade-in-up">
              <div className="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl text-slate-800">
                <h3 className="font-bold text-lg text-slate-800">Justificación de Amnistía</h3>
                <textarea className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm my-4 outline-none focus:ring-2 focus:ring-indigo-500" rows={3} placeholder="Motivo..."></textarea>
                <button onClick={isOpeningPremium ? handleOpenStorePremium : () => handleOpenStore(true)} className="w-full bg-indigo-600 text-white font-bold py-4 rounded-2xl shadow-lg">Decretar Amnistía y Continuar</button>
              </div>
            </div>
          )}

          {/* Modal Absence */}
          {showAbsenceModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-55 flex items-center justify-center p-4">
              <div className="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl animate-fade-in-up text-slate-800 text-left">
                <div className="flex justify-between items-center mb-4 pb-2 border-b border-slate-100">
                  <h3 className="font-black text-lg text-rose-600">🏥 Reportar Incidencia de Asistencia</h3>
                  <button onClick={() => { setShowAbsenceModal(false); setShowEtaSelector(false); }} className="bg-transparent border-none text-slate-400 hover:text-slate-600 text-lg cursor-pointer">✕</button>
                </div>
                
                {!showEtaSelector ? (
                  <>
                    <p className="text-xs text-slate-500 mb-4 bg-rose-50/50 p-3 rounded-xl border border-rose-100/50">
                      {isOpeningPremium ? (
                        <>⚠️ **Aviso de Encargado:** Reportar inasistencia cederá automáticamente la apertura y las llaves de la sucursal al suplente en la jerarquía.</>
                      ) : (
                        <>⚠️ **Aviso de Colaborador:** Reportar inasistencia o retardo notificará a tus supervisores y liberará tus reservas de comida asignadas de hoy.</>
                      )}
                    </p>
                    
                    <label className="block text-xs font-bold text-slate-500 mb-1.5 uppercase">Motivo o Justificación</label>
                    <textarea 
                       className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm mb-4 outline-none focus:ring-2 focus:ring-indigo-500 text-slate-850" 
                       rows={3} 
                       placeholder="Describe detalladamente el motivo..."
                       value={absenceReason}
                       onChange={(e) => setAbsenceReason(e.target.value)}
                    ></textarea>
                    
                    <div className="grid grid-cols-2 gap-3 mb-2">
                      <button 
                        onClick={() => {
                          if (!absenceReason.trim()) {
                            showCustomAlert("Por favor, escribe el motivo.");
                            return;
                          }
                          setShowEtaSelector(true);
                        }} 
                        className="bg-amber-500 hover:bg-amber-600 text-white font-extrabold py-3.5 rounded-2xl flex flex-col items-center justify-center gap-1 border-none cursor-pointer active:scale-95 transition-transform"
                      >
                        <span className="text-lg">⏳</span>
                        <span className="text-xs">Llegaré tarde</span>
                      </button>
                      <button 
                        onClick={async () => {
                          if (!absenceReason.trim()) {
                            showCustomAlert("Por favor, escribe el motivo.");
                            return;
                          }
                          if (isOpeningPremium) {
                            await handleReportAbsencePremium();
                          } else {
                            await handleContingency('absent');
                          }
                          setShowAbsenceModal(false);
                        }} 
                        className="bg-rose-600 hover:bg-rose-700 text-white font-extrabold py-3.5 rounded-2xl flex flex-col items-center justify-center gap-1 border-none cursor-pointer active:scale-95 transition-transform"
                      >
                        <span className="text-lg">❌</span>
                        <span className="text-xs">No asistiré</span>
                      </button>
                    </div>
                  </>
                ) : (
                  <>
                    <p className="text-xs text-slate-500 mb-4">
                      Especifica la **hora estimada de llegada** a la sucursal para determinar si se requiere cesión al suplente:
                    </p>
                    
                    <label className="block text-xs font-bold text-slate-500 mb-1.5 uppercase">Hora Estimada de Llegada</label>
                    <input 
                      type="time" 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-3 text-sm mb-4 outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-slate-800"
                      value={etaTime}
                      onChange={(e) => setEtaTime(e.target.value)}
                    />
                    
                    <button 
                      onClick={async () => {
                        if (isOpeningPremium) {
                          await handleReportLatePremium(etaTime);
                        } else {
                          await handleContingency('late', etaTime);
                        }
                        setShowAbsenceModal(false);
                        setShowEtaSelector(false);
                      }}
                      className="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-black py-4 rounded-2xl shadow-md border-none cursor-pointer active:scale-95 transition-all"
                    >
                      Confirmar Retardo
                    </button>
                  </>
                )}
              </div>
            </div>
          )}

          {/* Modal de Checklist de Apertura */}
          {showOpeningChecklistModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-55 flex items-center justify-center p-4">
              <div className="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl animate-fade-in-up text-slate-800 text-left">
                <div className="flex justify-between items-center mb-4 pb-2 border-b border-slate-100">
                  <h3 className="font-black text-lg text-slate-800">📋 Checklist de Apertura</h3>
                  <button onClick={() => setShowOpeningChecklistModal(false)} className="bg-transparent border-none text-slate-400 hover:text-slate-600 text-lg cursor-pointer">✕</button>
                </div>
                <p className="text-xs text-slate-500 mb-4">Completa las siguientes tareas operativas obligatorias para habilitar el inicio de la sucursal de hoy:</p>
                
                {loadingAssignments ? (
                  <div className="flex flex-col items-center py-8 animate-pulse">
                    <p className="text-sm text-slate-500 font-semibold">Cargando tareas operativas...</p>
                  </div>
                ) : openingAssignments.length === 0 ? (
                  <div className="text-center py-6">
                    <p className="text-xs text-amber-600 bg-amber-50 p-3 rounded-xl font-medium mb-4">
                      ⚠️ No se encontraron tareas de apertura asignadas para ti hoy en la base de datos.
                    </p>
                    <button
                      onClick={async () => {
                        setOpeningChecklistCompleted(true);
                        localStorage.setItem('opening_checklist_completed', 'true');
                        setShowOpeningChecklistModal(false);
                        showCustomAlert("Checklist completado (sin tareas asignadas).");
                      }}
                      className="w-full bg-slate-900 text-white font-bold py-3.5 rounded-xl cursor-pointer hover:bg-slate-800 transition-colors border-none"
                    >
                      Habilitar Sucursal Directamente
                    </button>
                  </div>
                ) : (
                  <>
                    <div className="space-y-3.5 mb-6 max-h-60 overflow-y-auto pr-1">
                      {openingAssignments.map((assignment: any) => {
                        const isCompleted = assignment.status === 'completed' || assignment.status === 'awaiting_validation';
                        return (
                          <label key={assignment.id} className="flex items-start gap-3 p-3 rounded-xl hover:bg-slate-55 cursor-pointer border border-slate-150 transition-colors bg-slate-50/50">
                            <input 
                              type="checkbox"
                              checked={isCompleted}
                              onChange={async (e) => {
                                const newStatus = e.target.checked ? 'completed' : 'pending';
                                try {
                                  await axiosInstance.put(`/task-assignments/${assignment.id}`, {
                                    status: newStatus
                                  });
                                  setOpeningAssignments(prev => prev.map(a => a.id === assignment.id ? { ...a, status: newStatus } : a));
                                } catch (err) {
                                  console.error("Error updating assignment status", err);
                                  showCustomAlert("❌ Error al actualizar la tarea en el servidor.");
                                }
                              }}
                              className="rounded text-emerald-600 focus:ring-emerald-500 mt-0.5 cursor-pointer"
                            />
                            <div className="flex flex-col min-w-0 flex-1">
                              <span className="text-xs font-bold text-slate-700 truncate">{assignment.task?.title || 'Tarea Operativa'}</span>
                              {assignment.task?.estimated_mins && (
                                <span className="text-[10px] text-slate-400 font-semibold">{assignment.task.estimated_mins} min estimados</span>
                              )}
                            </div>
                          </label>
                        );
                      })}
                    </div>

                    <button 
                      id="btn-finish-checklist"
                      disabled={!openingAssignments.every(a => a.status === 'completed' || a.status === 'awaiting_validation' || a.status === 'omitted')}
                      onClick={() => {
                        setOpeningChecklistCompleted(true);
                        localStorage.setItem('opening_checklist_completed', 'true');
                        setShowOpeningChecklistModal(false);
                        showCustomAlert("✅ Checklist de apertura completado y registrado con éxito.");
                      }}
                      className={`w-full font-black py-4 rounded-2xl transition-all shadow-md cursor-pointer border-none ${
                        openingAssignments.every(a => a.status === 'completed' || a.status === 'awaiting_validation' || a.status === 'omitted')
                          ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                          : 'bg-slate-100 text-slate-400 cursor-not-allowed'
                      }`}
                    >
                      Finalizar Checklist
                    </button>
                  </>
                )}
              </div>
            </div>
          )}
          
          {/* Modal Justificante */}
          {showJustificanteModal && (
            <div className="absolute inset-0 bg-slate-900/80 backdrop-blur-md z-50 flex flex-col justify-center p-6 animate-fade-in text-slate-800">
              <div className="bg-white rounded-3xl p-6 w-full shadow-2xl border-4 border-rose-500 overflow-hidden relative max-w-md mx-auto">
                <div className="absolute top-0 left-0 right-0 bg-rose-500 text-white text-center py-2 font-black tracking-widest text-xs uppercase">
                  Acceso Bloqueado
                </div>
                <div className="mt-6 flex flex-col items-center text-center">
                  <span className="text-5xl mb-4">⛔</span>
                  <h3 className="font-black text-slate-800 text-xl mb-2 uppercase">Retardo Crítico Detectado</h3>
                  <p className="text-sm text-slate-600 mb-6 bg-rose-50 p-3 rounded-xl">Has superado la tolerancia máxima. Para desbloquear el acceso y registrar tu entrada, debes proveer una justificación válida.</p>
                  
                  <div className="w-full text-left">
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-2">Justificación (Obligatorio)</label>
                    <textarea 
                       className="w-full bg-slate-50 border border-slate-200 rounded-xl p-4 text-sm mb-6 outline-none focus:ring-2 focus:ring-rose-500 text-slate-800" 
                       rows={3} 
                       placeholder="Escribe el motivo detallado..."
                       value={justificanteText}
                       onChange={(e) => setJustificanteText(e.target.value)}
                    />
                  </div>
                  
                  <button 
                     onClick={() => {
                        if(justificanteText.trim().length > 10){
                           updateClockState(currentUser.id, 'active');
                           setContingencyLogs(prev => [{ id: Date.now(), userId: currentUser.id, userName: currentUser.name, type: 'late', reason: `JUSTIFICANTE: ${justificanteText}`, time: formattedTime }, ...prev]);
                           showCustomAlert(`✅ Fichaje manual registrado a las ${formattedTime} con justificante.`);
                           setShowJustificanteModal(false);
                           setJustificanteText("");
                        } else {
                           showCustomAlert("La justificación debe ser detallada (mínimo 10 caracteres).");
                        }
                     }}
                     className="w-full bg-rose-600 text-white font-bold py-4 rounded-2xl shadow-lg hover:bg-rose-700 active:scale-95 transition-transform"
                  >
                    Firmar y Entrar
                  </button>
                  <button onClick={() => setShowJustificanteModal(false)} className="w-full mt-3 text-slate-500 font-bold py-2 text-sm hover:text-slate-700">Cancelar</button>
                </div>
              </div>
            </div>
          )}

          {/* Modal KeyDelegation / Entrega de Turno (Shift Handover) */}
          {showKeyDelegationModal && !isHandoverCompleted && (
            <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex flex-col justify-end">
              <div className="bg-white rounded-t-3xl p-6 pb-12 w-full animate-fade-in-up text-slate-800">
                <h3 className="font-bold text-cyan-600 mb-2 text-xl flex items-center gap-2"><span>🗝️</span> Entrega de Turno</h3>
                <p className="text-sm text-slate-650 mb-4 bg-cyan-50 p-3 rounded-xl border border-cyan-100">
                  Para registrar tu salida, debes realizar el arqueo de caja y delegar las llaves de la sucursal.
                </p>
                
                <div className="mb-5">
                  <label className="block text-xs font-bold text-slate-505 uppercase tracking-wider mb-2">Arqueo de Caja (Efectivo en Caja)</label>
                  <div className="relative">
                    <span className="absolute left-4 top-3 font-bold text-slate-400">$</span>
                    <input 
                      type="number" 
                      placeholder="0.00" 
                      value={cashCount} 
                      onChange={(e) => setCashCount(e.target.value)} 
                      className="w-full pl-8 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-cyan-500 font-mono text-lg font-bold"
                    />
                  </div>
                </div>

                <div className="mb-5">
                  <label className="block text-xs font-bold text-slate-505 uppercase tracking-wider mb-2">Entregar llaves a:</label>
                  <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
                    {globalUsers.filter(u => u.is_active_employee !== false && u.id !== currentUser.id).map(u => (
                      <button
                        key={u.id}
                        onClick={() => setNextDayEncargadoId(u.id)}
                        className={`w-full p-3.5 rounded-xl border-2 flex items-center justify-between transition-all ${nextDayEncargadoId === u.id ? 'border-cyan-500 bg-cyan-50' : 'border-slate-100 bg-white hover:bg-slate-50'}`}
                      >
                        <div className="flex items-center gap-3">
                          <img src={u.avatar} alt="Avatar" className="w-8 h-8 rounded-full" />
                          <div className="text-left">
                            <p className="font-bold text-slate-800 text-xs">{u.name}</p>
                            <p className="text-[9px] text-slate-505">{u.role}</p>
                          </div>
                        </div>
                        <div className={`w-4 h-4 rounded-full border-2 flex items-center justify-center ${nextDayEncargadoId === u.id ? 'border-cyan-500' : 'border-slate-300'}`}>
                          {nextDayEncargadoId === u.id && <div className="w-2 h-2 bg-cyan-500 rounded-full"></div>}
                        </div>
                      </button>
                    ))}
                  </div>
                </div>
                
                <div className="flex gap-3">
                  <button 
                    onClick={() => setShowKeyDelegationModal(false)}
                    className="w-1/3 border border-slate-200 text-slate-500 font-bold py-4 rounded-2xl"
                  >
                    Cancelar
                  </button>
                  <button 
                    onClick={() => completeHandover(nextDayEncargadoId, parseFloat(cashCount) || 0)} 
                    disabled={!nextDayEncargadoId || !cashCount}
                    className="w-2/3 bg-cyan-600 hover:bg-cyan-700 text-white font-bold py-4 rounded-2xl shadow-md disabled:opacity-50 transition-opacity"
                  >
                    Confirmar Entrega
                  </button>
                </div>
              </div>
            </div>
          )}

          {showKeyDelegationModal && isHandoverCompleted && (
            <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex flex-col justify-end">
              <div className="bg-white rounded-t-3xl p-6 pb-12 w-full animate-fade-in-up text-slate-800">
                <h3 className="font-bold text-indigo-600 mb-2 text-xl flex items-center gap-2"><span>🔑</span> Entregar Llaves</h3>
                <p className="text-sm text-slate-665 mb-4 bg-indigo-50 p-3 rounded-xl border border-indigo-100">Mañana es tu día de descanso. Selecciona al encargado que abrirá la sucursal mañana.</p>
                
                <div className="space-y-2 mb-6">
                  {globalUsers.filter(u => u.is_active_employee !== false && u.id !== currentUser.id).map(u => (
                    <button
                      key={u.id}
                      onClick={() => setNextDayEncargadoId(u.id)}
                      className={`w-full p-4 rounded-xl border-2 flex items-center justify-between transition-all ${nextDayEncargadoId === u.id ? 'border-indigo-500 bg-indigo-50' : 'border-slate-100 bg-white hover:bg-slate-50'}`}
                    >
                      <div className="flex items-center gap-3">
                        <img src={u.avatar} alt="Avatar" className="w-8 h-8 rounded-full" />
                        <div className="text-left">
                          <p className="font-bold text-slate-800 text-sm">{u.name}</p>
                          <p className="text-[10px] text-slate-505">{u.role}</p>
                        </div>
                      </div>
                      <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center ${nextDayEncargadoId === u.id ? 'border-indigo-500' : 'border-slate-300'}`}>
                        {nextDayEncargadoId === u.id && <div className="w-2.5 h-2.5 bg-indigo-500 rounded-full"></div>}
                      </div>
                    </button>
                  ))}
                  {globalUsers.filter(u => u.is_active_employee !== false && u.id !== currentUser.id).length === 0 && (
                    <p className="text-rose-500 text-sm text-center py-4 bg-rose-50 rounded-xl">No hay empleados autorizados para recibir llaves. Configura los permisos en la Matrix.</p>
                  )}
                </div>
                
                <button 
                  onClick={handleKeyDelegation} 
                  className="w-full bg-indigo-600 text-white font-bold py-4 rounded-2xl shadow-md disabled:opacity-50 transition-opacity"
                >
                  Confirmar Entrega y Salir
                </button>
              </div>
            </div>
          )}

          {/* Modal Ley Silla Task Selection */}
          {showBreakSeatModal && (
            <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[9999] select-none animate-fade-in text-slate-800">
              <div className="bg-white w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
                <h3 className="font-extrabold text-purple-700 mb-2 text-xl flex items-center gap-2"><span>🧘</span> Descanso Ley Silla</h3>
                <p className="text-sm text-slate-600 mb-5">
                  De acuerdo con la <strong>Ley Silla</strong>, puedes tomar un descanso de 15 minutos. Selecciona una tarea que puedas realizar sentado durante este periodo:
                </p>
                
                <div className="space-y-2.5 mb-6 max-h-60 overflow-y-auto pr-1">
                  {useTaskStore.getState().tasks.filter(t => t.canBeDoneSitting).map(t => (
                    <button
                      key={t.id}
                      onClick={() => startBreakWithSittingTask(t.id)}
                      className="w-full p-4 rounded-2xl border border-purple-100 bg-purple-50/30 hover:bg-purple-50 text-left transition-colors flex justify-between items-center"
                    >
                      <div>
                        <p className="font-bold text-purple-950 text-sm">{t.title}</p>
                        <p className="text-[10px] text-purple-700 mt-0.5">⏱️ {t.estimatedMins} min | 🏆 {t.points} pts</p>
                      </div>
                      <span className="text-purple-650 bg-purple-100 p-1.5 rounded-full">🪑</span>
                    </button>
                  ))}
                  
                  <button
                    onClick={() => startBreakWithSittingTask(9999)}
                    className="w-full p-4 rounded-2xl border border-slate-200 bg-slate-50 hover:bg-slate-100 text-left transition-colors flex justify-between items-center"
                  >
                    <div>
                      <p className="font-bold text-slate-800 text-sm">Monitoreo de seguridad desde silla</p>
                      <p className="text-[10px] text-slate-500 mt-0.5">⏱️ 15 min | Tarea predeterminada</p>
                    </div>
                    <span className="text-slate-505 bg-slate-200 p-1.5 rounded-full">🪑</span>
                  </button>
                </div>
                
                <button 
                  onClick={() => setShowBreakSeatModal(false)}
                  className="w-full bg-slate-100 hover:bg-slate-200 text-slate-655 font-bold py-3.5 rounded-2xl transition-colors border-none"
                >
                  Cancelar Descanso
                </button>
              </div>
            </div>
          )}

          {/* Modal Pase de Salida Temporal */}
          {showTempExitModal && (
            <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[9999] select-none animate-fade-in text-slate-800">
              <div className="bg-white w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
                <h3 className="font-extrabold text-teal-700 mb-2 text-xl flex items-center gap-2"><span>🚪</span> Pase de Salida Temporal</h3>
                <p className="text-sm text-slate-600 mb-5">
                  Selecciona el motivo de tu salida temporal. El sistema monitoreará tu ubicación GPS y alertará en caso de sobrepasar el límite configurado.
                </p>
                
                <div className="space-y-2 mb-6">
                  {['Depósito Bancario', 'Trámite de Tienda', 'Consulta Médica', 'Comida Externa', 'Otro'].map(reason => (
                    <button
                      key={reason}
                      onClick={() => startTempExit(reason)}
                      className="w-full p-4 rounded-2xl border border-slate-100 hover:border-teal-200 bg-white hover:bg-teal-50/35 text-slate-800 font-bold text-sm text-left transition-all flex justify-between items-center"
                    >
                      <span>{reason}</span>
                      <span className="text-xs text-teal-650 font-normal">Solicitar ➡️</span>
                    </button>
                  ))}
                </div>
                
                <button 
                  onClick={() => setShowTempExitModal(false)}
                  className="w-full bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-3.5 rounded-2xl transition-colors border-none"
                >
                  Cancelar
                </button>
              </div>
            </div>
          )}

          {/* Modal Botón de Pánico */}
          {showPanicModal && (
            <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[9999] select-none animate-fade-in text-slate-800">
              <div className="bg-white w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
                <h3 className="font-extrabold text-rose-655 mb-2 text-xl flex items-center gap-2"><span>🚨</span> Botón de Pánico</h3>
                <p className="text-sm text-slate-600 mb-5">
                  Reporta una emergencia crítica en la sucursal de forma inmediata. Se bloqueará la pantalla y se avisará a todos los supervisores.
                </p>
                
                <div className="space-y-2.5 mb-6">
                  {['Fallo General de Energía', 'Robo / Asalto', 'Incendio', 'Emergencia Médica'].map(emergency => (
                    <button
                      key={emergency}
                      onClick={() => triggerPanic(emergency, `Reportado por ${currentUser.name}`)}
                      className="w-full p-4 rounded-2xl border border-rose-100 hover:border-rose-300 bg-rose-50/20 hover:bg-rose-50 text-rose-955 font-bold text-sm text-left transition-all animate-pulse"
                    >
                      🚨 {emergency}
                    </button>
                  ))}
                </div>
                
                <button 
                  onClick={() => setShowPanicModal(false)}
                  className="w-full bg-slate-100 hover:bg-slate-200 text-slate-605 font-bold py-3.5 rounded-2xl transition-colors border-none"
                >
                  Cancelar Reporte
                </button>
              </div>
            </div>
          )}

          {/* Modal Intercambio Rápido de Comida */}
          {showMealSwapModal && (
            <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[9999] select-none animate-fade-in text-slate-800">
              <div className="bg-white w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
                <h3 className="font-extrabold text-amber-700 mb-2 text-xl flex items-center gap-2"><span>🔄</span> Intercambio de Comida</h3>
                <p className="text-sm text-slate-605 mb-5">
                  Selecciona un compañero de turno que tenga reservación de comida para intercambiar su horario por el tuyo de forma rápida.
                </p>
                
                <div className="space-y-2 mb-6 max-h-60 overflow-y-auto pr-1">
                  {globalUsers.filter(u => u.is_active_employee !== false && u.id !== currentUser.id && hasReservedMeal[u.id]).map(u => (
                    <button
                      key={u.id}
                      onClick={async () => {
                        await swapMealSlots(currentUser.id, u.id);
                        setShowMealSwapModal(false);
                      }}
                      className="w-full p-3.5 rounded-2xl border border-slate-100 hover:border-amber-250 bg-white hover:bg-amber-50/20 text-left transition-colors flex justify-between items-center"
                    >
                      <div className="flex items-center gap-3">
                        <img src={u.avatar} alt="Avatar" className="w-8 h-8 rounded-full" />
                        <div>
                          <p className="font-bold text-slate-800 text-xs">{u.name}</p>
                          <p className="text-[9px] text-slate-550">Slot: {userReservedMealSlots[u.id]?.[0] || 'Reservado'}</p>
                        </div>
                      </div>
                      <span className="text-[10px] bg-amber-100 text-amber-800 font-bold px-2 py-1 rounded-md">Intercambiar</span>
                    </button>
                  ))}
                  {globalUsers.filter(u => u.is_active_employee !== false && u.id !== currentUser.id && hasReservedMeal[u.id]).length === 0 && (
                    <p className="text-slate-500 text-sm text-center py-4 bg-slate-55 rounded-2xl">No hay compañeros con reservas activas hoy para realizar intercambio.</p>
                  )}
                </div>
                
                <button 
                  onClick={() => setShowMealSwapModal(false)}
                  className="w-full bg-slate-100 hover:bg-slate-200 text-slate-605 font-bold py-3.5 rounded-2xl transition-colors border-none"
                >
                  Cerrar
                </button>
              </div>
            </div>
          )}

          {/* Modal Ajustes de Alarmas y Alertas */}
          {showAlarmSettingsModal && (
            <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[99999] select-none text-slate-800">
              <div className="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 dark:border-slate-800 animate-in zoom-in-95 duration-200">
                <h3 className="font-extrabold text-violet-750 dark:text-violet-400 mb-2 text-lg flex items-center gap-2">
                  <span>🔔</span> Alertas y Alarmas
                </h3>
                <p className="text-xs text-slate-500 mb-5">
                  Personaliza tus recordatorios de entrada, comida y Ley Silla.
                </p>

                <div className="space-y-4 mb-6 text-left">
                  <div className="flex justify-between items-center bg-slate-50 dark:bg-slate-950 p-3 rounded-2xl">
                    <div>
                      <p className="font-bold text-xs text-slate-800 dark:text-slate-200">Activar Alertas</p>
                      <p className="text-[10px] text-slate-500">Tonos de audio para eventos</p>
                    </div>
                    <input 
                      type="checkbox" 
                      checked={userClockPrefs.alarmsEnabled} 
                      onChange={e => setUserClockPrefs(prev => ({ ...prev, alarmsEnabled: e.target.checked }))}
                      className="w-4 h-4 accent-violet-650 cursor-pointer"
                    />
                  </div>

                  {userClockPrefs.alarmsEnabled && (
                    <div className="bg-slate-50 dark:bg-slate-950 p-3 rounded-2xl">
                      <label className="font-bold text-xs text-slate-800 dark:text-slate-200 block mb-2">Melodía</label>
                      <div className="grid grid-cols-2 gap-2">
                        {['classic', 'cheerful', 'urgent', 'chime'].map(tone => (
                          <button
                            key={tone}
                            type="button"
                            onClick={() => {
                              setUserClockPrefs(prev => ({ ...prev, selectedTone: tone }));
                              setTimeout(() => {
                                playAlarm('ya_llegue');
                              }, 100);
                            }}
                            className={`py-2 px-3 text-[10px] uppercase font-black rounded-xl border text-center transition-all cursor-pointer ${
                              userClockPrefs.selectedTone === tone
                                ? 'bg-violet-650 text-white border-violet-650 shadow-md'
                                : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-800 hover:bg-slate-100'
                            }`}
                          >
                            🎵 {tone}
                          </button>
                        ))}
                      </div>
                    </div>
                  )}

                  <div className="space-y-2">
                    <div className="flex justify-between items-center p-1 text-[11px]">
                      <span className="font-bold text-slate-600 dark:text-slate-400">Recordatorio entrada:</span>
                      <select 
                        value={userClockPrefs.preShiftReminderMins} 
                        onChange={e => setUserClockPrefs(prev => ({ ...prev, preShiftReminderMins: Number(e.target.value) }))}
                        className="p-1 text-xs border rounded-md dark:bg-slate-900 text-slate-800 dark:text-slate-200"
                      >
                        <option value="15">15 min antes</option>
                        <option value="30">30 min antes</option>
                        <option value="60">60 min antes</option>
                        <option value="90">90 min antes</option>
                      </select>
                    </div>

                    <div className="flex justify-between items-center p-1 text-[11px]">
                      <span className="font-bold text-slate-600 dark:text-slate-400">Alerta Ley Silla:</span>
                      <input 
                        type="checkbox" 
                        checked={userClockPrefs.leySillaAlert} 
                        onChange={e => setUserClockPrefs(prev => ({ ...prev, leySillaAlert: e.target.checked }))}
                        className="w-4 h-4 accent-violet-650 cursor-pointer"
                      />
                    </div>

                    <div className="flex justify-between items-center p-1 text-[11px]">
                      <span className="font-bold text-slate-600 dark:text-slate-400">Notificar Tarea Nueva:</span>
                      <input 
                        type="checkbox" 
                        checked={userClockPrefs.newTaskAlert} 
                        onChange={e => setUserClockPrefs(prev => ({ ...prev, newTaskAlert: e.target.checked }))}
                        className="w-4 h-4 accent-violet-650 cursor-pointer"
                      />
                    </div>
                  </div>
                </div>

                <button 
                  onClick={() => setShowAlarmSettingsModal(false)}
                  className="w-full bg-violet-650 hover:bg-violet-700 text-white font-bold py-3 rounded-2xl transition-colors border-none cursor-pointer text-xs"
                >
                  Guardar
                </button>
              </div>
            </div>
          )}

          {/* Modal Bloqueo de Salida: Validación de Supervisor */}
          {pendingTasksBlocker && (
            <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-md flex items-center justify-center p-4 z-[99999] select-none text-slate-800">
              <div className="bg-white w-full max-w-sm rounded-[2rem] p-6 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
                <div className={`w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-sm border ${
                  isOvertimeValidation 
                    ? 'bg-amber-50 border-amber-100 text-amber-500' 
                    : 'bg-rose-50 border-rose-100 text-rose-500'
                }`}>
                  {isOvertimeValidation ? (
                    <Fingerprint size={22} className="animate-pulse" />
                  ) : isEarlyDepartureValidation ? (
                    <LogOut size={22} className="animate-bounce" />
                  ) : (
                    <AlertCircle size={22} className="animate-bounce" />
                  )}
                </div>

                <h3 className="font-extrabold text-slate-900 text-center tracking-tight mb-2 text-base">
                  {isOvertimeValidation 
                    ? (isSimulatedHoliday ? "📅 Autorizar Labor en Feriado" : "⏰ Autorizar Horas Extras")
                    : isEarlyDepartureValidation 
                      ? "🚪 Autorización de Salida Anticipada" 
                      : isLateEntryValidation
                        ? "🔑 Autorizar Entrada Tardía"
                        : "⚠️ Tareas Pendientes Detectadas"}
                </h3>
                <p className="text-[10.5px] text-slate-500 text-center leading-relaxed mb-5 px-2 font-bold">
                  {isPro 
                    ? (isOvertimeValidation 
                        ? (isSimulatedHoliday 
                            ? "El colaborador se encuentra en su día de descanso obligatorio (Día Feriado LFT). Para habilitar su entrada a laborar (Pago Triple LFT), escanea tu código QR dinámico de 60s."
                            : "El colaborador se encuentra en su día de descanso. Para habilitar su entrada a laborar horas extras, escanea tu código QR dinámico de 60s.")
                        : isEarlyDepartureValidation
                          ? "El colaborador está registrando una salida antes de su horario programado. Solicita la validación de tu supervisor escaneando su QR dinámico de 60s."
                          : isLateEntryValidation
                            ? "Se superó el límite de tolerancia de entrada. Solicita la validación de tu supervisor escaneando su QR dinámico de 60s para permitir tu ingreso (Sujeto a deducción salarial)."
                            : "No puedes registrar tu salida porque tienes tareas operativas asignadas sin completar. Conclúyelas o solicita la validación de tu supervisor escaneando su QR dinámico de 60s.")
                    : (isOvertimeValidation
                        ? (isSimulatedHoliday
                            ? "El colaborador se encuentra en su día de descanso obligatorio (Día Feriado LFT). Para habilitar su entrada a laborar (Pago Triple LFT), ingresa tu PIN de supervisor."
                            : "El colaborador se encuentra en su día de descanso. Para habilitar su entrada a laborar horas extras, ingresa tu PIN de supervisor.")
                        : isEarlyDepartureValidation
                          ? "El colaborador está registrando una salida antes de su horario programado. Solicita la validación de tu supervisor ingresando su PIN."
                          : isLateEntryValidation
                            ? "Se superó el límite de tolerancia de entrada. Solicita la validación de tu supervisor ingresando su PIN para permitir tu ingreso (Sujeto a deducción salarial)."
                            : "No puedes registrar tu salida porque tienes tareas operativas asignadas sin completar. Conclúyelas o solicita la validación de tu supervisor ingresando su PIN.")}
                </p>

                {isPro ? (
                  <div className="space-y-3 mb-5">
                    <label className="text-[10px] font-black text-slate-550 uppercase tracking-wider block text-left">Token QR del Supervisor</label>
                    <div className="flex gap-2">
                      <input
                        type="text"
                        value={supervisorQrToken}
                        onChange={e => setSupervisorQrToken(e.target.value)}
                        placeholder="qr_..."
                        className="flex-1 py-3 px-4 border border-slate-200 rounded-2xl font-mono text-center text-xs focus:outline-none focus:border-violet-500"
                      />
                      <button
                        onClick={async () => {
                          try {
                            const res = await axiosInstance.post('/sync/supervisor/generate-qr');
                            if (res.data && res.data.token) {
                              setSupervisorQrToken(res.data.token);
                            }
                          } catch (e) {
                            alert("Error al simular QR");
                          }
                        }}
                        className="bg-violet-650 hover:bg-violet-750 text-white font-bold px-3 py-2 rounded-2xl border-none cursor-pointer text-[10px]"
                        title="Simular escaneo de QR"
                        type="button"
                      >
                        [Escaneo Sim.]
                      </button>
                    </div>
                    <p className="text-[9px] text-rose-500 font-extrabold text-center uppercase">
                      ⚠️ Nota: Omitir tareas afectará negativamente las métricas de productividad.
                    </p>
                  </div>
                ) : (
                  <div className="space-y-3 mb-5">
                    <label className="text-[10px] font-black text-slate-550 uppercase tracking-wider block text-left">PIN del Supervisor</label>
                    <input
                      type="password"
                      maxLength={6}
                      value={supervisorPin}
                      onChange={e => setSupervisorPin(e.target.value.replace(/\D/g, ''))}
                      placeholder="••••"
                      className="w-full py-3 px-4 border border-slate-200 rounded-2xl font-mono text-center tracking-[0.5em] text-lg focus:outline-none focus:border-rose-500"
                    />
                    <p className="text-[9px] text-rose-500 font-extrabold text-center uppercase">
                      ⚠️ Nota: Omitir tareas afectará negativamente las métricas de productividad.
                    </p>
                  </div>
                )}

                <div className="flex gap-2">
                  <button 
                    onClick={() => setPendingTasksBlocker(false)}
                    className="w-1/2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-3.5 rounded-2xl transition-colors border-none cursor-pointer text-xs"
                  >
                    Regresar
                  </button>
                  <button 
                    onClick={authorizeClockOutWithPendingTasks}
                    className="w-1/2 bg-gradient-to-r from-rose-500 to-rose-600 hover:from-rose-600 hover:to-rose-700 text-white font-black py-3.5 rounded-2xl shadow-md transition-all border-none cursor-pointer text-xs uppercase tracking-wider"
                  >
                    Autorizar
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Modal de Salida Anticipada */}
          {showEarlyDepartureModal && (
            <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-md flex items-center justify-center p-4 z-[99999] select-none text-slate-800 animate-fade-in">
              <div className="bg-white w-full max-w-sm rounded-[2rem] p-6 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
                <div className="w-12 h-12 bg-rose-50 border border-rose-100 rounded-2xl flex items-center justify-center text-rose-500 mx-auto mb-4 shadow-sm">
                  <LogOut size={22} className="animate-pulse" />
                </div>

                <h3 className="font-extrabold text-slate-900 text-center tracking-tight mb-2 text-base">
                  🚪 Registrar Salida Anticipada
                </h3>
                <p className="text-[10.5px] text-slate-500 text-center leading-relaxed mb-5 px-2 font-bold">
                  ¿Por qué necesitas retirarte antes de finalizar tu jornada?
                </p>

                <div className="space-y-3 mb-5">
                  <label className="text-[10px] font-black text-slate-550 uppercase tracking-wider block text-left">Motivo de Salida</label>
                  <select
                    value={earlyDepartureReason}
                    onChange={e => setEarlyDepartureReason(e.target.value)}
                    className="w-full py-3 px-4 border border-slate-200 rounded-2xl text-xs font-bold text-slate-705 bg-white focus:outline-none focus:border-rose-500 cursor-pointer"
                  >
                    <option value="Enfermedad">Enfermedad / Malestar</option>
                    <option value="Urgencia Familiar">Urgencia Familiar</option>
                    <option value="Asunto Personal">Asunto Personal</option>
                    <option value="Otro">Otro Motivo</option>
                  </select>
                  <p className="text-[9.5px] text-rose-500 font-extrabold text-center uppercase">
                    {isPro 
                      ? "⚠️ Se requiere la validación del QR del supervisor para confirmar."
                      : "ℹ️ Confirmar registrará tu salida de inmediato."}
                  </p>
                </div>

                <div className="flex gap-2">
                  <button 
                    onClick={() => setShowEarlyDepartureModal(false)}
                    className="w-1/2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-3.5 rounded-2xl transition-colors border-none cursor-pointer text-xs"
                  >
                    Cancelar
                  </button>
                  <button 
                    onClick={submitEarlyDeparture}
                    className="w-1/2 bg-gradient-to-r from-rose-500 to-rose-600 hover:from-rose-600 hover:to-rose-700 text-white font-black py-3.5 rounded-2xl shadow-md transition-all border-none cursor-pointer text-xs uppercase tracking-wider"
                  >
                    Confirmar
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Overlay de Bloqueo de Pánico */}
          {isPanicActive && (
            <div className="fixed inset-0 bg-rose-955/90 backdrop-blur-md z-[99999] flex flex-col items-center justify-center p-6 text-center animate-fade-in">
              <div className="w-24 h-24 bg-rose-600 rounded-full flex items-center justify-center animate-ping absolute opacity-20"></div>
              <div className="bg-rose-900 border-4 border-rose-500 text-white rounded-full p-6 mb-6 animate-pulse shadow-[0_0_50px_rgba(239,68,68,0.6)]">
                <AlertOctagon size={48} />
              </div>
              <h2 className="text-3xl font-black text-rose-500 mb-2 tracking-wide uppercase animate-pulse">SUCURSAL EN PARO DE EMERGENCIA</h2>
              <p className="text-rose-200 text-sm max-w-md mb-8">
                Se ha activado el botón de pánico de la sucursal. Los sistemas de fichaje y control están bloqueados temporalmente por motivos de seguridad.
              </p>
              
              <button 
                onClick={resolvePanic} 
                className="bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold px-8 py-4 rounded-2xl shadow-xl transition-all hover:scale-105 border-none cursor-pointer text-sm uppercase tracking-wider"
              >
                🔓 Desactivar Alerta (Administración)
              </button>
            </div>
          )}

          {/* Modal MealDetailsModal */}
          {showMealDetailsModal && (
            <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[9999] select-none animate-fade-in text-slate-800">
              <div className="bg-white w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
                {(() => {
                  const mySlots = userReservedMealSlots[currentUser?.id] || [];
                  const hasFinishedEating = mealEndTimes[currentUser?.id] !== undefined;
                  const isActive = clockState === 'meal';
                  const isMealReservationUnlocked = useAppStore.getState().isFeatureUnlocked('meal_reservation');
                  
                  const info = getMealInfo();
                  const limit = shiftConfigs[currentUser.id]?.mealMinutes || timeBankConfigs.mealMinutes || 45;
                  const hasExceeded = info && !info.isReserved && info.extra > 0;

                  const candidateColleagues = globalUsers.filter((u: any) => 
                    u.id !== currentUser?.id && 
                    u.is_active_employee !== false && 
                    Number(u.job_role_id) === Number(currentUser?.job_role_id)
                  );

                  let modalState: 'active' | 'completed' | 'reserved_pending' | 'unreserved_pending' = 'unreserved_pending';
                  if (isActive) {
                    modalState = 'active';
                  } else if (hasFinishedEating) {
                    modalState = 'completed';
                  } else if (mySlots.length > 0) {
                    modalState = 'reserved_pending';
                  }

                  return (
                    <div className="space-y-4">
                      {/* Minimal Header */}
                      <div className="flex justify-between items-start mb-1 pb-2.5 border-b border-slate-100">
                        <div className="flex items-center gap-2 text-left">
                          <Utensils className="w-5 h-5 text-amber-550 shrink-0" />
                          <h3 className="font-black text-slate-800 text-sm">
                            Horario de Almuerzo
                          </h3>
                        </div>
                        <div className="flex items-center gap-2 shrink-0">
                          <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase border ${
                            modalState === 'active' ? 'bg-amber-50 border-amber-100 text-amber-650' :
                            modalState === 'completed' ? (hasExceeded ? 'bg-rose-50 border-rose-100 text-rose-650' : 'bg-emerald-50 border-emerald-100 text-emerald-650') :
                            modalState === 'reserved_pending' ? 'bg-indigo-50 border-indigo-100 text-indigo-650' :
                            'bg-slate-50 border-slate-150 text-slate-500'
                          }`}>
                            {modalState === 'active' ? '⏳ En curso' :
                             modalState === 'completed' ? (hasExceeded ? '⚠️ Límite Excedido' : '✓ Cumplido') :
                             modalState === 'reserved_pending' ? '📅 Reservado' : '🍽️ Sin Registro'}
                          </span>
                          <button onClick={() => setShowMealDetailsModal(false)} className="bg-transparent border-none text-slate-400 hover:text-slate-600 text-sm cursor-pointer ml-1 select-none">✕</button>
                        </div>
                      </div>

                      {/* Colored status container */}
                      <div className={`p-4 rounded-2xl border text-left leading-relaxed text-xs font-semibold ${
                        modalState === 'active' ? 'bg-amber-50/40 border-amber-100/60 text-amber-900' :
                        modalState === 'completed' ? (hasExceeded ? 'bg-rose-50/40 border-rose-100/60 text-rose-900' : 'bg-emerald-50/40 border-emerald-100/60 text-emerald-900') :
                        modalState === 'reserved_pending' ? 'bg-indigo-50/40 border-indigo-100/60 text-indigo-900' :
                        'bg-slate-50/50 border-slate-150 text-slate-700'
                      }`}>
                        {modalState === 'active' ? (
                          <>¡Hola, <strong className="font-black text-slate-955">{currentUser?.name}</strong>! Buen provecho. Actualmente te encuentras en tu tiempo de comida reservado. Recuerda registrar tu reingreso a tiempo.</>
                        ) : modalState === 'completed' ? (
                          hasExceeded ? (
                            <>Hola, <strong className="font-black text-slate-955">{currentUser?.name}</strong>. Hoy tu almuerzo duró <strong className="text-rose-600 font-bold">{info?.duration} minutos</strong> (tu límite regular es de {limit} minutos), lo cual representa un exceso de <strong className="text-rose-600 font-bold">{info?.extra} minutos</strong>. ⚠️</>
                          ) : (
                            <>¡Hola, <strong className="font-black text-slate-955">{currentUser?.name}</strong>! Tu almuerzo de hoy duró <strong className="text-emerald-650 font-bold">{info?.duration} minutos</strong> (dentro del límite regular de {limit} minutos). ¡Excelente coordinación! 🌟</>
                          )
                        ) : modalState === 'reserved_pending' ? (
                          <>Hola, tu horario de comida es a las <strong className="text-indigo-600 font-bold">{mySlots[0]}</strong>.</>
                        ) : (
                          <>Hola, <strong className="font-black text-slate-900">{currentUser?.name}</strong>. Aún no has elegido tu horario de comida para hoy. Te sugerimos hacerlo pronto para coordinar el aforo con tus compañeros.</>
                        )}
                      </div>

                      {/* Booking / Swaps y Acciones */}
                      {(modalState === 'active' || modalState === 'completed') ? (
                        <div className="space-y-4">
                          {mySlots.length > 0 && (
                            <div className="p-3 bg-amber-50/45 border border-amber-100/60 rounded-xl text-center">
                              <span className="text-[10px] font-extrabold uppercase text-amber-700 block">Horario Reservado</span>
                              <span className="text-xs font-black text-amber-660">{mySlots.join(' - ')}</span>
                            </div>
                          )}

                          {candidateColleagues.length > 0 && modalState === 'active' ? (
                            <div className="p-3 bg-slate-50 border border-slate-100 rounded-xl text-left">
                              <h4 className="text-[10px] font-black text-slate-700 uppercase tracking-wider mb-2 flex items-center gap-1">
                                <span>🔄</span> Intercambiar con un compañero
                              </h4>
                              <select 
                                id="swap-colleague-select"
                                defaultValue=""
                                onChange={async (e) => {
                                  const val = e.target.value;
                                  if (val) {
                                    const partnerId = Number(val);
                                    const partner = globalUsers.find((u: any) => u.id === partnerId);
                                    if (partner && confirm(`¿Confirmas que deseas intercambiar tu horario de comida con ${partner.name}?`)) {
                                      await swapMealSlots(currentUser.id, partnerId);
                                      setShowMealDetailsModal(false);
                                    }
                                  }
                                }}
                                className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-blue-500/20 outline-none select-none text-slate-800"
                              >
                                <option value="" disabled>Selecciona un compañero...</option>
                                {candidateColleagues.map((partner: any) => {
                                  const pSlots = userReservedMealSlots[partner.id] || [];
                                  const pSlotsDesc = pSlots.length > 0 ? pSlots.join(' - ') : 'Sin Reserva';
                                  return (
                                    <option key={partner.id} value={partner.id}>
                                      {partner.name} ({pSlotsDesc})
                                    </option>
                                  );
                                })}
                              </select>
                            </div>
                          ) : null}
                        </div>
                      ) : modalState === 'unreserved_pending' ? (
                        <div className="space-y-4">
                          {isMealReservationUnlocked ? (
                            <button 
                              onClick={() => {
                                setShowMealDetailsModal(false);
                                setShowMealReservationModal(true);
                              }}
                              className="w-full bg-amber-500 hover:bg-amber-600 text-white font-extrabold py-3.5 rounded-xl text-xs transition-colors uppercase tracking-wider border-none cursor-pointer shadow-sm active:scale-98"
                            >
                              Apartar Horario de Comida
                            </button>
                          ) : (
                            <div className="space-y-3">
                              <button 
                                onClick={async () => {
                                  confirmMealReservation(0);
                                  setShowMealDetailsModal(false);
                                }}
                                className="w-full bg-amber-500 hover:bg-amber-600 text-white font-extrabold py-3.5 rounded-xl text-xs transition-colors uppercase tracking-wider border-none cursor-pointer shadow-sm active:scale-98"
                              >
                                Registrar Salida a Comer
                              </button>
                            </div>
                          )}
                        </div>
                      ) : null}
                    </div>
                  );
                })()}
              </div>
            </div>
          )}

      {showEntryDetailsModal && (
        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[9999] select-none animate-fade-in text-slate-800">
          <div className="bg-white w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
            {(() => {
              const isLate = lateUsers[currentUser.id] || (clockState === 'inactive' && currentSimTime > parseTimeToMins(shiftConfigs[currentUser.id]?.start || '09:00') + 10);
              const actualIn = checkInTimes[currentUser.id];
              const expectedInStr = shiftConfigs[currentUser.id]?.start || '09:00';
              const expectedInMins = parseTimeToMins(expectedInStr);
              const delayMins = actualIn !== undefined ? Math.max(0, actualIn - expectedInMins) : 0;

              return (
                <div className="space-y-4">
                  {/* Minimal Header */}
                  <div className="flex justify-between items-start mb-1 pb-2.5 border-b border-slate-100">
                    <div className="flex items-center gap-2 text-left">
                      <LogIn className="w-5 h-5 text-indigo-600 shrink-0" />
                      <h3 className="font-black text-slate-800 text-sm">
                        Registro de Entrada
                      </h3>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase border ${
                        isLate ? 'bg-rose-50 border-rose-100 text-rose-650' : 'bg-emerald-50 border-emerald-100 text-emerald-650'
                      }`}>
                        {isLate ? '⚠️ Retardo' : '✓ Puntual'}
                      </span>
                      <button onClick={() => setShowEntryDetailsModal(false)} className="bg-transparent border-none text-slate-400 hover:text-slate-600 text-sm cursor-pointer ml-1 select-none">✕</button>
                    </div>
                  </div>

                  {/* Colored status container */}
                  <div className={`p-4 rounded-2xl border text-left leading-relaxed text-xs font-semibold ${
                    isLate ? 'bg-rose-50/40 border-rose-100/60 text-rose-900' : 'bg-emerald-50/40 border-emerald-100/60 text-emerald-900'
                  }`}>
                    {isLate ? (
                      <>Hola, <strong className="font-black text-slate-950">{currentUser?.name}</strong>. Buen día. Registraste tu entrada hoy a las <strong className="text-rose-600 font-bold">{actualIn !== undefined ? formatMinsToTimeClean(actualIn) : '--:--'}</strong> (tu entrada regular es a las {formatStringToTimeClean(expectedInStr)}), acumulando un retardo de <strong className="text-rose-600 font-bold">{delayMins} minutos</strong>. Recuerda ingresar a tiempo para proteger tu bono de puntualidad mensual. ¡Mucho éxito en el turno de hoy! 💪</>
                    ) : (
                      <>¡Hola, <strong className="font-black text-slate-955">{currentUser?.name}</strong>! Buen día. Registraste tu entrada de forma puntual hoy a las <strong className="text-emerald-650 font-bold">{actualIn !== undefined ? formatMinsToTimeClean(actualIn) : '--:--'}</strong> (tu entrada regular es a las {formatStringToTimeClean(expectedInStr)}). ¡Excelente inicio de jornada! Sigue así para asegurar tu bono de puntualidad. ⭐</>
                    )}
                  </div>
                </div>
              );
            })()}
          </div>
        </div>
      )}

      {showBreakDetailsModal && (
        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[9999] select-none animate-fade-in text-slate-800">
          <div className="bg-white w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
            {(() => {
              const info = getBreakInfo();
              const limit = leySillaConfig?.breakMinutes || 15;
              if (!info) {
                const hasCheckedInUser = checkInTimes[currentUser.id] !== undefined;
                const elapsedMins = hasCheckedInUser ? Math.max(0, currentSimTime - checkInTimes[currentUser.id]) : 0;
                const consecutiveMinutes = leySillaConfig?.consecutiveMinutes || 120;
                const remainingMins = Math.max(0, consecutiveMinutes - elapsedMins);
                
                const isRequested = !!pendingBreakRequests[currentUser.id];

                return (
                  <div className="space-y-4 text-left">
                    <div className="flex justify-between items-start mb-1 pb-2.5 border-b border-slate-100">
                      <div className="flex items-center gap-2">
                        <Armchair className="w-5 h-5 text-purple-600 shrink-0" />
                        <h3 className="font-black text-slate-800 text-sm">
                          Solicitud de Descanso (Ley Silla)
                        </h3>
                      </div>
                      <button onClick={() => setShowBreakDetailsModal(false)} className="bg-transparent border-none text-slate-400 hover:text-slate-600 text-sm cursor-pointer select-none">✕</button>
                    </div>

                    <div className="p-4 rounded-2xl border bg-purple-50/40 border-purple-100/60 text-purple-900 leading-relaxed text-xs font-semibold">
                      <p className="mb-2">
                        La <strong>Ley Silla</strong> establece tu derecho a tomar un descanso periódico para mitigar la fatiga laboral de pie.
                      </p>
                      {hasCheckedInUser ? (
                        <div className="space-y-1 mt-2">
                          <p>⏱️ Tiempo de pie acumulado hoy: <strong className="text-purple-700">{elapsedMins} minutos</strong>.</p>
                          {remainingMins > 0 ? (
                            <p>⏳ Tiempo restante para el descanso de ley: <strong className="text-amber-600">{remainingMins} minutos</strong>.</p>
                          ) : (
                            <p className="text-emerald-650 font-black">✓ Ya tienes derecho a tomar tu descanso de {leySillaConfig?.breakMinutes || 15} min.</p>
                          )}
                        </div>
                      ) : (
                        <p className="text-rose-600 font-black mt-2">⚠️ Registra tu entrada primero para calcular tu tiempo acumulado.</p>
                      )}
                    </div>

                    {/* Sugerencias de Tareas en Silla */}
                    <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-2">
                      <h4 className="text-[11px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-1">
                        <span>💡</span> Sugerencia de Tareas en Silla:
                      </h4>
                      <p className="text-[10px] text-slate-500 leading-relaxed">
                        Si necesitas descansar pero prefieres seguir activo, puedes solicitar al supervisor realizar temporalmente tareas sentado, tales como:
                      </p>
                      <ul className="text-[10.5px] text-slate-600 font-semibold space-y-1 list-disc pl-4 leading-tight">
                        <li>Responder correos y mensajes de clientes.</li>
                        <li>Capturar reportes, bitácoras e inventario.</li>
                        <li>Realizar capacitaciones teóricas en la <strong>Academia LMS</strong>.</li>
                        <li>Organizar documentación administrativa.</li>
                      </ul>
                    </div>

                    {/* Botón de Acción */}
                    {hasCheckedInUser && (
                      <div className="pt-2">
                        {isRequested ? (
                          <div className="w-full bg-slate-100 border border-slate-200 text-slate-500 text-xs font-bold py-3.5 rounded-xl text-center flex items-center justify-center gap-2 animate-pulse">
                            <span>⏳</span> Esperando validación del supervisor...
                          </div>
                        ) : (
                          <button
                            onClick={async () => {
                              await requestBreak(currentUser.id);
                              setShowBreakDetailsModal(false);
                            }}
                            className="w-full bg-purple-600 hover:bg-purple-750 text-white font-extrabold py-3.5 rounded-xl text-xs transition-all uppercase tracking-wider border-none cursor-pointer shadow-md shadow-purple-500/10 active:scale-98"
                          >
                            Solicitar Descanso al Supervisor
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                );
              }

              const hasExceeded = info.extra > 0;

              return (
                <div className="space-y-4">
                  {/* Minimal Header */}
                  <div className="flex justify-between items-start mb-1 pb-2.5 border-b border-slate-100">
                    <div className="flex items-center gap-2 text-left">
                      <Armchair className="w-5 h-5 text-purple-600 shrink-0" />
                      <h3 className="font-black text-slate-800 text-sm">
                        Registro de Descanso
                      </h3>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase border ${
                        info.isActive ? 'bg-purple-50 border-purple-100 text-purple-650' :
                        hasExceeded ? 'bg-rose-50 border-rose-100 text-rose-650' :
                        'bg-emerald-50 border-emerald-100 text-emerald-650'
                      }`}>
                        {info.isActive ? '⏳ En curso' :
                         hasExceeded ? '⚠️ Límite Excedido' :
                         '✓ Cumplido'}
                      </span>
                      <button onClick={() => setShowBreakDetailsModal(false)} className="bg-transparent border-none text-slate-400 hover:text-slate-600 text-sm cursor-pointer ml-1 select-none">✕</button>
                    </div>
                  </div>

                  {/* Colored status container */}
                  <div className={`p-4 rounded-2xl border text-left leading-relaxed text-xs font-semibold ${
                    info.isActive ? 'bg-purple-50/40 border-purple-100/60 text-purple-900' :
                    hasExceeded ? 'bg-rose-50/40 border-rose-100/60 text-rose-900' :
                    'bg-emerald-50/40 border-emerald-100/60 text-emerald-900'
                  }`}>
                    {info.isActive ? (
                      <>¡Hola, <strong className="font-black text-slate-955">{currentUser?.name}</strong>! Actualmente te encuentras en tu descanso de hoy, iniciado a las <strong className="text-purple-600 font-bold">{formatMinsToTimeClean(info.start)}</strong>. Disfruta tu café o estiramiento. Recuerda registrar tu reingreso a tiempo.</>
                    ) : hasExceeded ? (
                      <>Hola, <strong className="font-black text-slate-955">{currentUser?.name}</strong>. Tu descanso (iniciado a las {formatMinsToTimeClean(info.start)}) duró <strong className="text-rose-600 font-bold">{info.duration} minutos</strong> (tu límite regular es de {limit} minutos), lo cual representa un exceso de <strong className="text-rose-600 font-bold">{info.extra} minutos</strong>. Te sugerimos cuidar más tus tiempos en tus siguientes descansos. ⚠️</>
                    ) : (
                      <>¡Hola, <strong className="font-black text-slate-955">{currentUser?.name}</strong>! Tu descanso (iniciado a las {formatMinsToTimeClean(info.start)}) duró <strong className="text-emerald-650 font-bold">{info.duration} minutos</strong> (dentro de tu límite regular de {limit} minutos). ¡Excelente coordinación con tus tiempos de descanso! ☕</>
                    )}
                  </div>
                </div>
              );
            })()}
          </div>
        </div>
      )}

      {showExitDetailsModal && (
        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4 z-[9999] select-none animate-fade-in text-slate-800">
          <div className="bg-white w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
            {(() => {
              const isLate = lateUsers[currentUser.id];
              const breakInfo = getBreakInfo();
              const mealInfo = getMealInfo();
              const hasBreakExceeded = breakInfo && breakInfo.extra > 0;
              const hasMealExceeded = mealInfo && !mealInfo.isReserved && mealInfo.extra > 0;
              
              const hasAnyDeviation = isLate || hasBreakExceeded || hasMealExceeded;

              // Build dynamic deviations list
              const devList: string[] = [];
              if (isLate) devList.push("un retardo de ingreso");
              if (hasBreakExceeded) devList.push(`exceso de descanso (+${breakInfo.extra}m)`);
              if (hasMealExceeded) devList.push(`exceso de almuerzo (+${mealInfo.extra}m)`);

              return (
                <div className="space-y-4">
                  {/* Minimal Header */}
                  <div className="flex justify-between items-start mb-1 pb-2.5 border-b border-slate-100">
                    <div className="flex items-center gap-2 text-left">
                      <LogOut className="w-5 h-5 text-teal-600 shrink-0" />
                      <h3 className="font-black text-slate-800 text-sm">
                        Resumen de Turno
                      </h3>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase border ${
                        hasAnyDeviation ? 'bg-amber-50 border-amber-100 text-amber-650' : 'bg-emerald-50 border-emerald-100 text-emerald-650'
                      }`}>
                        {hasAnyDeviation ? '⚠️ Con Novedad' : '🏆 Impecable'}
                      </span>
                      <button onClick={() => setShowExitDetailsModal(false)} className="bg-transparent border-none text-slate-400 hover:text-slate-600 text-sm cursor-pointer ml-1 select-none">✕</button>
                    </div>
                  </div>

                  {/* Colored status container */}
                  <div className={`p-4 rounded-2xl border text-left leading-relaxed text-xs font-semibold ${
                    hasAnyDeviation ? 'bg-amber-50/40 border-amber-100/60 text-amber-900' : 'bg-emerald-50/40 border-emerald-100/60 text-emerald-900'
                  }`}>
                    {hasAnyDeviation ? (
                      <>Hola, <strong className="font-black text-slate-950">{currentUser?.name}</strong>. Concluiste tu turno con <strong className="text-slate-950">{workedHours} horas</strong> registradas (entrada a las {checkInTimes[currentUser.id] !== undefined ? formatMinsToTimeClean(checkInTimes[currentUser.id]) : '--:--'} y salida a las {checkOutTimes[currentUser.id] !== undefined ? formatMinsToTimeClean(checkOutTimes[currentUser.id]) : '--:--'}). Registramos algunas novedades: <strong className="text-rose-600">{devList.join(', ')}</strong>. ¡No te preocupes, mañana será una gran oportunidad para retomar tu récord de puntualidad! Buen descanso. 🌟</>
                    ) : (
                      <>¡Hola, <strong className="font-black text-slate-955">{currentUser?.name}</strong>! Excelente trabajo hoy. Finalizaste tu jornada con <strong className="text-emerald-650 font-bold">{workedHours} horas</strong> laboradas (entrada a las {checkInTimes[currentUser.id] !== undefined ? formatMinsToTimeClean(checkInTimes[currentUser.id]) : '--:--'} y salida a las {checkOutTimes[currentUser.id] !== undefined ? formatMinsToTimeClean(checkOutTimes[currentUser.id]) : '--:--'}). Cumpliste perfectamente con tus horarios y límites de asistencia. ¡Muchas gracias por tu compromiso y descansa! 🏆</>
                    )}
                  </div>
                </div>
              );
            })()}
          </div>
        </div>
      )}

                              {/* Modal MealReservation */}
          {showMealReservationModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
              <div className="bg-white rounded-3xl p-6 w-full max-w-md shadow-2xl animate-fade-in-up text-slate-800 text-left relative overflow-hidden">
                {isSwappingLoading && pendingSwapPartner ? (
                  <div className="flex flex-col items-center justify-center py-12 text-center space-y-4">
                    <div className="w-12 h-12 rounded-full border-4 border-amber-500 border-t-transparent animate-spin mx-auto"></div>
                    <h3 className="font-bold text-slate-800 text-lg">Enviando Solicitud...</h3>
                    <p className="text-xs text-slate-500 max-w-xs leading-relaxed mx-auto">
                      Se ha enviado una notificación a <strong>{pendingSwapPartner.name}</strong> para intercambiar tu horario de comida por su horario reservado (<strong>{userReservedMealSlots[pendingSwapPartner.id]?.[0] || 'Reservado'}</strong>).
                    </p>
                  </div>
                ) : (
                  (() => {
                    const isFreemiumExpired = !isFeatureUnlocked('meal_reservation');

                    const areRolesCompatibleForSwap = (roleA: string, roleB: string) => {
                      if (!roleA || !roleB) return false;
                      const rA = roleA.toLowerCase();
                      const rB = roleB.toLowerCase();
                      if (rA === rB) return true;
                      const isSupA = rA.includes('supervisor') || rA.includes('sup.');
                      const isSupB = rB.includes('supervisor') || rB.includes('sup.');
                      if (isSupA || isSupB) {
                        return isSupA && isSupB;
                      }
                      const isOperativeA = rA.includes('ayudante') || rA.includes('asesor') || rA.includes('ventas') || rA.includes('atencion') || rA.includes('cliente') || rA.includes('almacenista');
                      const isOperativeB = rB.includes('ayudante') || rB.includes('asesor') || rB.includes('ventas') || rB.includes('atencion') || rB.includes('cliente') || rB.includes('almacenista');
                      if (isOperativeA && isOperativeB) return true;
                      return false;
                    };

                    if (isFreemiumExpired) {
                      return (
                        <>
                          <h3 className="font-bold text-amber-600 mb-2 text-xl flex items-center gap-2"><span>🍔</span> Registro de Almuerzo</h3>
                          
                          <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl mb-6">
                            <p className="text-xs text-slate-600 leading-relaxed mb-4 text-left">
                              Como usuario del plan gratuito, puedes registrar tu salida a comer directamente sin reserva de horario previa.
                            </p>
                            
                            <button 
                              onClick={() => {
                                confirmMealReservation(0);
                                setShowMealReservationModal(false);
                              }}
                              className="w-full bg-amber-500 hover:bg-amber-600 text-white font-bold py-4 rounded-xl shadow-md transition-colors border-none cursor-pointer"
                            >
                              Registrar Salida a Comer
                            </button>
                          </div>

                          <div className="p-4 bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-150 rounded-2xl mb-6 flex items-start gap-3">
                            <span className="text-lg">⭐</span>
                            <div className="text-left">
                              <h4 className="font-black text-blue-900 text-xs uppercase tracking-wider mb-0.5">Accede al Comedor Pro</h4>
                              <p className="text-blue-700 text-[10px] leading-relaxed">
                                El plan gratuito no incluye la cuadrícula interactiva de comedor, aforo por horarios ni prevención de choque de puestos. ¡Pásate al plan Profesional!
                              </p>
                            </div>
                          </div>
                          
                          <button onClick={() => setShowMealReservationModal(false)} className="w-full bg-slate-100 text-slate-700 font-bold py-4 rounded-2xl border-none cursor-pointer">Cancelar</button>
                        </>
                      );
                    }

                    // Candidatos compatibles para el intercambio rápido
                    const swapCandidates = globalUsers.filter(u => 
                      u.is_active_employee !== false && 
                      u.id !== currentUser.id && 
                      hasReservedMeal[u.id] && 
                      areRolesCompatibleForSwap(currentUser.role, u.role)
                    );

                    return (
                      <>
                        <h3 className="font-bold text-amber-600 mb-2 text-xl flex items-center gap-2"><span>🍔</span> Aparta tu Comida</h3>
                        <p className="text-xs text-slate-500 mb-4 bg-amber-50 p-3 rounded-xl border border-amber-100/60 leading-normal">
                          Selecciona tu horario o solicita un intercambio con un compañero compatible de tu mismo rango. (Aforo máximo: {mealSettings?.maxChairs || 3})
                        </p>
                        
                        <div className="grid grid-cols-2 gap-2.5 mb-5 max-h-36 overflow-y-auto custom-scrollbar pr-2">
                          {(() => {
                            const safeStart = mealSettings?.startHour ?? 13;
                            const safeEnd = mealSettings?.endHour ?? 17;
                            const safeStep = mealSettings?.stepMins ?? 15;
                            const totalLength = Math.max(0, (safeEnd - safeStart) * (60 / safeStep));
                            
                            return Array.from({length: totalLength}).map((_, i) => {
                              const totalMins = safeStart * 60 + (i * safeStep);
                              const h = Math.floor(totalMins / 60);
                              const m = totalMins % 60;
                              const ampm = h >= 12 ? 'PM' : 'AM';
                              const slotStr = `${h > 12 ? h - 12 : h}:${m.toString().padStart(2,'0')} ${ampm}`;
                              
                              const userMealMinutes = currentUser?.mealMinutes || timeBankConfigs?.mealMinutes || 60;
                              const stepMins = mealSettings?.stepMins || 15;
                              const neededBlocks = Math.ceil(userMealMinutes / stepMins);
                              const totalPossibleBlocks = ((mealSettings?.endHour ?? 17) - (mealSettings?.startHour ?? 13)) * (60 / stepMins);
                              
                              let canReserve = true;
                              let blockReason = '';
                              let firstBlockReservations = reservedMeals[slotStr] || [];
                              
                              if (i + neededBlocks > totalPossibleBlocks) {
                                 canReserve = false;
                                 blockReason = 'Tiempo insuficiente';
                              } else {
                                 for(let j=0; j<neededBlocks; j++) {
                                    const checkMins = (mealSettings?.startHour ?? 13) * 60 + ((i + j) * stepMins);
                                    const ch = Math.floor(checkMins / 60);
                                    const cm = checkMins % 60;
                                    const campm = ch >= 12 ? 'PM' : 'AM';
                                    const checkSlotStr = `${ch > 12 ? ch - 12 : ch}:${cm.toString().padStart(2,'0')} ${campm}`;
                                    
                                    const res = reservedMeals[checkSlotStr] || [];
                                    if (res.length >= (mealSettings?.maxChairs || 3)) {
                                       canReserve = false;
                                       blockReason = 'Aforo Lleno';
                                       break;
                                    }
                                    if (mealSettings?.preventRoleOverlap && res.some(r => r.role === currentUser.role)) {
                                       canReserve = false;
                                       blockReason = `Choque: ${currentUser.role}`;
                                       break;
                                    }
                                 }
                              }
                              
                              const disabled = !canReserve;

                              if (disabled && mealSettings?.hideFullSlots && (blockReason === 'Aforo Lleno' || blockReason.startsWith('Choque'))) {
                                 return null;
                              }
                              
                              return (
                                <button 
                                  key={slotStr}
                                  onClick={() => confirmMealReservation(i)}
                                  disabled={disabled}
                                  className={`p-2.5 rounded-xl border-2 flex flex-col items-center justify-center transition-colors border-none cursor-pointer ${disabled ? 'bg-slate-100 border-slate-200 opacity-60 cursor-not-allowed text-slate-400' : 'bg-white border-amber-200 hover:border-amber-400 active:bg-amber-50 text-slate-800'}`}
                                >
                                  <span className="font-bold text-xs">{slotStr}</span>
                                  <span className="text-[9px] mt-0.5 font-bold text-center leading-tight">
                                    {disabled ? `🔒 ${blockReason}` : `🪑 Disp: ${(mealSettings?.maxChairs || 3) - firstBlockReservations.length}`}
                                  </span>
                                </button>
                              );
                            });
                          })()}
                        </div>

                        {/* Listado Deslizable de Intercambio Filtrado */}
                        <div className="border-t border-slate-100 pt-3.5 mb-4">
                          <h4 className="text-[10px] font-black text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-1">
                            <span>🔄</span> ¿Intercambiar horario con un compañero?
                          </h4>
                          {swapCandidates.length > 0 ? (
                            <div className="max-h-28 overflow-y-auto pr-1 space-y-1.5 custom-scrollbar">
                              {swapCandidates.map(u => {
                                const pSlots = userReservedMealSlots[u.id] || [];
                                const pSlotsDesc = pSlots.length > 0 ? pSlots.join(' - ') : 'Reservado';
                                return (
                                  <div 
                                    key={u.id}
                                    onClick={() => {
                                      setPendingSwapPartner(u);
                                      setIsSwappingLoading(true);
                                      setTimeout(async () => {
                                        await swapMealSlots(currentUser.id, u.id);
                                        setIsSwappingLoading(false);
                                        setPendingSwapPartner(null);
                                        setShowMealReservationModal(false);
                                        showCustomAlert(`🟢 ${u.name} ha aceptado tu solicitud de intercambio de comida.`);
                                      }, 3000);
                                    }}
                                    className="p-2 rounded-xl border border-slate-100 hover:border-amber-300 bg-slate-50/50 hover:bg-amber-50/20 transition-all flex justify-between items-center cursor-pointer"
                                  >
                                    <div className="flex items-center gap-2.5 min-w-0">
                                      <img src={u.avatar} alt="Avatar" className="w-6 h-6 rounded-full shrink-0" />
                                      <div className="min-w-0">
                                        <p className="font-bold text-slate-700 text-xs truncate leading-tight">{u.name}</p>
                                        <p className="text-[9px] text-slate-400 font-semibold truncate leading-tight mt-0.5">{u.role} • {pSlotsDesc}</p>
                                      </div>
                                    </div>
                                    <button className="text-[9px] bg-amber-100 text-amber-800 font-extrabold px-2.5 py-1 rounded-lg border-none shrink-0 cursor-pointer hover:bg-amber-200 transition-colors">
                                      Intercambiar
                                    </button>
                                  </div>
                                );
                              })}
                            </div>
                          ) : (
                            <p className="text-slate-400 text-[10.5px] font-semibold text-center py-4 bg-slate-50 border border-slate-100 rounded-2xl leading-relaxed select-none">
                              No hay compañeros compatibles con reservas hoy para realizar intercambio.
                            </p>
                          )}
                        </div>
                        
                        <button 
                          onClick={() => setShowMealReservationModal(false)} 
                          className="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3 rounded-xl border-none cursor-pointer transition-colors text-xs uppercase tracking-wider"
                        >
                          Cerrar
                        </button>
                      </>
                    );
                  })()
                )}
              </div>
            </div>
          )}

          {/* Modal de Desempeño Semanal */}
          {showPerformanceModal && weeklyPayrollData && (
            <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex flex-col justify-end text-slate-800">
              <div 
                className="absolute inset-0" 
                onClick={() => setShowPerformanceModal(false)}
              />
              <div className="bg-white rounded-t-3xl p-5 pb-8 w-full animate-fade-in-up relative z-10 text-left border-t border-slate-100 max-w-md mx-auto">
                <div className="flex items-center justify-between mb-4">
                  <div className="flex items-center gap-2">
                    <div className="p-2 bg-amber-50 text-amber-500 rounded-xl">
                      <Trophy size={20} className="animate-bounce" />
                    </div>
                    <h3 className="font-black text-slate-800 text-base">Mi Desempeño Semanal</h3>
                  </div>
                  <button 
                    onClick={() => setShowPerformanceModal(false)}
                    className="p-1 bg-slate-100 hover:bg-slate-200 text-slate-400 rounded-full border-none cursor-pointer"
                  >
                    <X size={16} />
                  </button>
                </div>

                <p className="text-[11px] text-slate-500 mb-4 font-medium">
                  Resumen de tu rendimiento acumulado de Lunes a Domingo de esta semana en DecorArte:
                </p>

                {/* Score Circular */}
                <div className="flex flex-col items-center justify-center p-4 bg-slate-50 rounded-2xl border border-slate-100 mb-4">
                  <div className="relative flex items-center justify-center">
                    <svg className="w-24 h-24">
                      <circle 
                        className="text-slate-200" 
                        strokeWidth="8" 
                        stroke="currentColor" 
                        fill="transparent" 
                        r="38" 
                        cx="48" 
                        cy="48" 
                      />
                      <circle 
                        className={
                          weeklyPerformanceScore >= 85 
                            ? 'text-emerald-500' 
                            : weeklyPerformanceScore >= 60 
                              ? 'text-amber-500' 
                              : 'text-rose-500'
                        }
                        strokeWidth="8" 
                        strokeDasharray={2 * Math.PI * 38}
                        strokeDashoffset={2 * Math.PI * 38 * (1 - weeklyPerformanceScore / 100)}
                        strokeLinecap="round" 
                        stroke="currentColor" 
                        fill="transparent" 
                        r="38" 
                        cx="48" 
                        cy="48" 
                      />
                    </svg>
                    <div className="absolute text-center">
                      <span className="text-xl font-black text-slate-800">{weeklyPerformanceScore}%</span>
                      <span className="text-[8px] font-bold text-slate-400 block uppercase leading-none">Score</span>
                    </div>
                  </div>
                  <span className={`text-xs font-black mt-2.5 px-3 py-1 rounded-full ${
                    weeklyPerformanceScore >= 85 
                      ? 'bg-emerald-50 text-emerald-700' 
                      : weeklyPerformanceScore >= 60 
                        ? 'bg-amber-50 text-amber-700' 
                        : 'bg-rose-50 text-rose-700'
                  }`}>
                    {weeklyPerformanceScore >= 85 
                      ? 'Desempeño Excelente ✓' 
                      : weeklyPerformanceScore >= 60 
                        ? 'Rendimiento Regular' 
                        : 'Atención Requerida ⚠️'}
                  </span>
                </div>

                {/* Grid de Incidencias */}
                <div className="space-y-2.5">
                  <div className="flex items-center justify-between p-2.5 bg-white border border-slate-150 rounded-xl">
                    <div className="flex items-center gap-2">
                      <span className="text-sm">📅</span>
                      <div className="leading-none">
                        <span className="text-[11px] font-black text-slate-800">Faltas Registradas</span>
                        <span className="text-[9px] text-slate-400 block mt-0.5">Penaliza asistencia</span>
                      </div>
                    </div>
                    <span className="text-xs font-extrabold text-slate-700">{weeklyPayrollData.incidents?.total_absences || 0} faltas</span>
                  </div>

                  <div className="flex items-center justify-between p-2.5 bg-white border border-slate-150 rounded-xl">
                    <div className="flex items-center gap-2">
                      <span className="text-sm">⏱️</span>
                      <div className="leading-none">
                        <span className="text-[11px] font-black text-slate-800">Retardos Acumulados</span>
                        <span className="text-[9px] text-slate-400 block mt-0.5">Penaliza puntualidad</span>
                      </div>
                    </div>
                    <span className="text-xs font-extrabold text-slate-700">{weeklyPayrollData.incidents?.lates || 0} retardos</span>
                  </div>

                  <div className="flex items-center justify-between p-2.5 bg-white border border-slate-150 rounded-xl">
                    <div className="flex items-center gap-2">
                      <span className="text-sm">🍔</span>
                      <div className="leading-none">
                        <span className="text-[11px] font-black text-slate-800">Excesos en Tiempo Comida</span>
                        <span className="text-[9px] text-slate-400 block mt-0.5">Minutos excedidos del límite</span>
                      </div>
                    </div>
                    <span className="text-xs font-extrabold text-slate-700">{weeklyPayrollData.performance?.meal_overtime_mins || 0} mins</span>
                  </div>

                  <div className="flex items-center justify-between p-2.5 bg-white border border-slate-150 rounded-xl">
                    <div className="flex items-center gap-2">
                      <span className="text-sm">☕</span>
                      <div className="leading-none">
                        <span className="text-[11px] font-black text-slate-800">Excesos en Descansos</span>
                        <span className="text-[9px] text-slate-400 block mt-0.5">Ley Silla / Pausas activas</span>
                      </div>
                    </div>
                    <span className="text-xs font-extrabold text-slate-700">{weeklyPayrollData.performance?.break_overtime_mins || 0} mins</span>
                  </div>

                  <div className="flex items-center justify-between p-2.5 bg-white border border-slate-150 rounded-xl">
                    <div className="flex items-center gap-2">
                      <span className="text-sm">✅</span>
                      <div className="leading-none">
                        <span className="text-[11px] font-black text-slate-800">Eficiencia en Tareas</span>
                        <span className="text-[9px] text-slate-400 block mt-0.5">Completadas a tiempo</span>
                      </div>
                    </div>
                    <span className="text-xs font-extrabold text-emerald-600">{weeklyPayrollData.performance?.task_performance_pct || 100}%</span>
                  </div>
                </div>

                <button 
                  onClick={() => setShowPerformanceModal(false)}
                  className="w-full mt-5 py-3 bg-[#8a2be2] hover:bg-violet-750 text-white rounded-xl text-xs font-black uppercase tracking-wider shadow-sm transition-all border-none cursor-pointer"
                >
                  Entendido
                </button>
              </div>
            </div>
          )}

          {/* Modal de Alerta de Pánico */}
          {showPanicModal && (
            <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex flex-col justify-end text-slate-800">
              <div 
                className="absolute inset-0" 
                onClick={() => setShowPanicModal(false)}
              />
              <div className="bg-white rounded-t-3xl p-5 pb-8 w-full animate-fade-in-up relative z-10 text-left border-t border-slate-100 max-w-md mx-auto">
                <div className="flex items-center gap-2 mb-4">
                  <div className="p-2 bg-rose-50 text-rose-600 rounded-xl">
                    <AlertOctagon size={20} className="animate-pulse" />
                  </div>
                  <h3 className="font-black text-rose-900 text-base">Alerta de Emergencia (Pánico)</h3>
                </div>

                <p className="text-[11.5px] text-slate-600 mb-5 leading-relaxed font-medium">
                  ¿Deseas activar la alerta de pánico en DecorArte? Al activarla, se enviará una notificación de auxilio inmediata con tu ubicación GPS en tiempo real a la central de control administrativo y de seguridad.
                </p>

                <div className="flex gap-3">
                  <button 
                    onClick={() => setShowPanicModal(false)}
                    className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-black uppercase tracking-wider border-none cursor-pointer"
                  >
                    Cancelar
                  </button>
                  <button 
                    onClick={() => {
                      setShowPanicModal(false);
                      showToast('🚨 Alerta de pánico enviada con éxito. Soporte en camino.', 'error');
                    }}
                    className="flex-1 py-3 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-black uppercase tracking-wider shadow-md shadow-rose-600/10 border-none cursor-pointer"
                  >
                    Confirmar Alerta
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Modal Report */}
          {showReportModal && (
            <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex flex-col justify-end text-slate-800">
              <div className="bg-white rounded-t-3xl p-6 pb-12 w-full animate-fade-in-up">
                <div className="flex items-center gap-2 mb-4">
                  <span className="text-2xl">📢</span>
                  <h3 className="font-bold text-slate-800 text-lg">Reporte 100% Anónimo</h3>
                </div>
                <p className="text-xs text-slate-500 mb-4 bg-slate-50 p-2 rounded-lg">Este reporte se enviará de forma confidencial a la administración. Nadie sabrá que fuiste tú.</p>
                
                <div className="space-y-3 mb-6">
                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">¿A quién reportas?</label>
                    <select 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-amber-500 text-slate-800"
                      value={reportForm.targetId}
                      onChange={e => setReportForm({...reportForm, targetId: e.target.value})}
                    >
                      <option value="">Selecciona un compañero...</option>
                      {globalUsers.filter(u => u.is_active_employee !== false && u.id !== currentUser.id).map(u => (
                        <option key={u.id} value={u.id}>{u.name}</option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Motivo del reporte</label>
                    <select 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-amber-500 text-slate-800"
                      value={reportForm.type}
                      onChange={e => setReportForm({...reportForm, type: e.target.value})}
                    >
                      <option value="">Selecciona un motivo...</option>
                      <option value="retardo">Retardos repetitivos</option>
                      <option value="abandono">Abandono de puesto</option>
                      <option value="conducta">Conducta inapropiada</option>
                      <option value="otro">Otro (describir abajo)</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Detalle o Comentarios</label>
                    <textarea 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-amber-500 text-slate-800"
                      rows={3}
                      placeholder="Escribe los detalles aquí..."
                      value={reportForm.description}
                      onChange={e => setReportForm({...reportForm, description: e.target.value})}
                    />
                  </div>
                </div>

                <div className="flex gap-3">
                  <button onClick={submitReport} className="flex-1 bg-amber-500 hover:bg-amber-600 text-white font-bold py-3.5 rounded-xl shadow-md">Enviar Reporte</button>
                  <button onClick={() => setShowReportModal(false)} className="flex-1 bg-slate-100 text-slate-700 font-bold py-3.5 rounded-xl">Cancelar</button>
                </div>
              </div>
            </div>
          )}

          {/* Menú de Perfil Flotante (Popover Contextual debajo del Header) */}
          {showProfileMenu && (
            <div 
              className="fixed inset-0 z-[85] bg-black/10 backdrop-blur-xs"
              onClick={() => setShowProfileMenu(false)}
            >
              <div 
                className="fixed top-[76px] right-3.5 w-52 sm:w-56 z-[90] bg-white border border-slate-200 rounded-2xl shadow-xl py-2 animate-in fade-in slide-in-from-top-3 duration-200 text-left"
                onClick={(e) => e.stopPropagation()}
              >
                {/* Header del menú */}
                <div className="px-4 py-2 border-b border-slate-100 text-left">
                  <p className="text-xs sm:text-sm font-bold text-slate-800">Sesión Activa</p>
                  <p className="text-[10px] sm:text-xs text-slate-500 font-medium truncate">{currentUser?.email || 'empleado@decorarte360.com'}</p>
                </div>

                {/* Lista de opciones */}
                <div className="p-1.5 space-y-0.5">
                  <button 
                    onClick={() => {
                      setShowProfileMenu(false);
                      setShowSettingsModal(true);
                    }}
                    className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center gap-2 focus:outline-none bg-transparent border-none cursor-pointer"
                  >
                    <Users size={14} className="text-slate-400" />
                    Mi Cuenta
                  </button>

                  <button 
                    onClick={() => {
                      setShowProfileMenu(false);
                      setInnerTool(null);
                      setPhoneTab('nomina');
                    }}
                    className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center gap-2 focus:outline-none bg-transparent border-none cursor-pointer"
                  >
                    <DollarSign size={14} className="text-slate-400" />
                    Historial de Nómina
                  </button>

                  <div className="border-t border-slate-100 my-1 sm:my-1.5"></div>
                  
                  {/* Tema del Dispositivo */}
                  <button 
                    onClick={() => setIsDark(!isDark)}
                    className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center justify-between focus:outline-none bg-transparent border-none cursor-pointer"
                  >
                    <div className="flex items-center gap-2">
                      <Sun size={14} className="text-slate-400" />
                      <span>Tema Oscuro</span>
                    </div>
                    <span className="text-[10px] font-extrabold text-slate-500 capitalize">{isDark ? 'Sí' : 'No'}</span>
                  </button>

                  {/* Sandbox toggle for admin/supervisor */}
                  {(currentUser?.role === 'admin' || currentUser?.role === 'supervisor') && (
                    <button 
                      onClick={() => {
                        useAppStore.getState().setIsSandboxMode(!isSandboxMode);
                        showCustomAlert(`🛠️ Modo Sandbox ${!isSandboxMode ? 'activado' : 'desactivado'}.`);
                      }}
                      className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-100 transition-colors flex items-center justify-between focus:outline-none bg-transparent border-none cursor-pointer"
                    >
                      <div className="flex items-center gap-2">
                        <Settings size={14} className="text-slate-400" />
                        <span>Modo Simulador</span>
                      </div>
                      <span className={`text-[8px] font-black uppercase px-1.5 py-0.2 rounded border ${isSandboxMode ? 'bg-emerald-50 border-emerald-250 text-emerald-700' : 'bg-slate-50 border-slate-200 text-slate-400'}`}>
                        {isSandboxMode ? 'ON' : 'OFF'}
                      </span>
                    </button>
                  )}

                  <div className="border-t border-slate-100 my-1 sm:my-1.5"></div>

                  <button 
                    onClick={() => {
                      localStorage.removeItem('talent_auth_token');
                      useAppStore.getState().setCurrentUser(null as any);
                      setShowProfileMenu(false);
                      window.location.href = '/login';
                    }}
                    className="w-full text-left px-2.5 py-1.5 sm:px-3 sm:py-2 rounded-lg sm:rounded-xl text-xs sm:text-sm font-bold text-rose-600 hover:bg-rose-50 transition-colors flex items-center gap-2 focus:outline-none bg-transparent border-none cursor-pointer"
                  >
                    <Lock size={14} className="text-rose-500" />
                    Cerrar Sesión
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* settings Modal (User Profile & App Settings) */}
          {showSettingsModal && (
            <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[100] flex items-center justify-center p-4">
              <div className={`rounded-3xl p-6 w-full max-w-md shadow-2xl animate-fade-in-up border text-left ${isDark ? 'bg-slate-900 border-slate-800 text-white' : 'bg-white border-slate-200 text-slate-800'}`}>
                <div className="flex justify-between items-center mb-4">
                  <h3 className="font-black text-lg flex items-center gap-2">
                    <span>⚙️</span> Ajustes de Perfil
                  </h3>
                  <button onClick={() => setShowSettingsModal(false)} className="text-slate-400 hover:text-slate-600 font-extrabold text-sm focus:outline-none">✕</button>
                </div>
                
                <div className="space-y-4">
                  {/* Photo / Avatar Selector */}
                  <div className="flex items-center gap-4 bg-slate-50 dark:bg-slate-950 p-3.5 rounded-2xl border border-slate-100 dark:border-slate-800 mb-2">
                    <div 
                      onClick={() => avatarFileInputRef.current?.click()}
                      className="w-14 h-14 rounded-full bg-slate-200 dark:bg-slate-800 border-2 border-white dark:border-slate-700 shadow-sm overflow-hidden relative group shrink-0 cursor-pointer"
                      title="Haz clic para cambiar tu foto"
                    >
                      <input 
                        type="file" 
                        ref={avatarFileInputRef} 
                        onChange={handleAvatarFileChange} 
                        className="hidden" 
                        accept="image/png, image/jpeg" 
                      />
                      <img 
                        src={currentUser?.avatar || "https://i.pravatar.cc/150?img=11"} 
                        alt="Current Avatar" 
                        className="w-full h-full object-cover" 
                      />
                      <div className="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                        {isUploadingAvatar ? (
                          <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                        ) : (
                          <Upload size={14} className="text-white" />
                        )}
                      </div>
                    </div>
                    <div className="leading-tight text-left">
                      <h4 className="font-bold text-xs text-slate-800 dark:text-slate-200">{currentUser?.name}</h4>
                      <p className="text-[10px] text-slate-400 font-extrabold uppercase tracking-wide mt-1">
                        {userPositionName}
                      </p>
                    </div>
                  </div>
                  {/* Username / Name */}
                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Nombre de Usuario</label>
                    <input 
                      type="text" 
                      className={`w-full border rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500 ${isDark ? 'bg-slate-950 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'}`}
                      value={editUsername}
                      onChange={e => setEditUsername(e.target.value)}
                    />
                  </div>

                  {/* Password / PIN */}
                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Contraseña (PIN)</label>
                    <input 
                      type="text" 
                      className={`w-full border rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500 ${isDark ? 'bg-slate-955 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'}`}
                      value={editPassword}
                      onChange={e => setEditPassword(e.target.value)}
                    />
                  </div>

                  {/* Día de descanso */}
                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Día de Descanso</label>
                    <select 
                      className={`w-full border rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500 ${isDark ? 'bg-slate-955 border-slate-800 text-white' : 'bg-slate-50 border-slate-200 text-slate-800'}`}
                      value={editRestDay}
                      onChange={e => setEditRestDay(e.target.value)}
                    >
                      {['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'].map(d => (
                        <option key={d} value={d}>{d}</option>
                      ))}
                    </select>
                  </div>

                  {/* App Settings Toggles */}
                  <div className="pt-3 border-t border-slate-850/10 dark:border-slate-800/30 space-y-3">
                    <p className="text-[10px] font-bold text-slate-400 uppercase">Ajustes de la Aplicación</p>
                    
                    {/* Dark Mode */}
                    <div className="flex items-center justify-between opacity-50">
                      <span className="text-xs font-semibold text-slate-400">Tema Oscuro (No disponible)</span>
                      <button 
                        disabled
                        type="button"
                        className="w-11 h-6 rounded-full transition-colors relative flex items-center px-0.5 bg-slate-200 cursor-not-allowed"
                      >
                        <div className="w-5 h-5 rounded-full bg-white shadow-sm transform transition-transform duration-200 translate-x-0"></div>
                      </button>
                    </div>

                    {/* Notifications */}
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-semibold">Notificaciones de Turno</span>
                      <button 
                        onClick={() => setNotifShift(!notifShift)}
                        className={`w-11 h-6 rounded-full transition-colors relative flex items-center px-0.5 ${notifShift ? 'bg-violet-650' : 'bg-slate-300'}`}
                      >
                        <div className={`w-5 h-5 rounded-full bg-white shadow-sm transform transition-transform duration-200 ${notifShift ? 'translate-x-5' : 'translate-x-0'}`}></div>
                      </button>
                    </div>

                    {/* Simulated Holiday LFT */}
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-semibold">Simular Día Feriado (LFT)</span>
                      <button 
                        type="button"
                        onClick={() => {
                          const newVal = !isSimulatedHoliday;
                          localStorage.setItem('is_simulated_holiday', newVal ? 'true' : 'false');
                          setIsSimulatedHoliday(newVal);
                          showCustomAlert(newVal ? '📅 Día Feriado (LFT) activado en el checador.' : '📅 Día Feriado (LFT) desactivado.');
                        }}
                        className={`w-11 h-6 rounded-full transition-colors relative flex items-center px-0.5 ${isSimulatedHoliday ? 'bg-indigo-600' : 'bg-slate-300'}`}
                      >
                        <div className={`w-5 h-5 rounded-full bg-white shadow-sm transform transition-transform duration-200 ${isSimulatedHoliday ? 'translate-x-5' : 'translate-x-0'}`}></div>
                      </button>
                    </div>
                  </div>
                </div>

                <div className="mt-6 flex flex-col gap-2">
                  <button 
                    onClick={async () => {
                      if (!editUsername.trim() || !editPassword.trim()) {
                        showCustomAlert('Por favor, completa el usuario y la contraseña.');
                        return;
                      }
                      
                      const updatedUser = {
                        ...currentUser,
                        name: editUsername,
                        pin_code: editPassword
                      };

                      if (!isSandboxMode) {
                        try {
                          await axiosInstance.post('/me/update-profile', {
                            name: editUsername,
                            avatar: currentUser.avatar
                          });
                        } catch (err: any) {
                          console.error("Error al persistir cambios de perfil:", err);
                        }
                      }

                      setCurrentUser(updatedUser);
                      setGlobalUsers(globalUsers.map(u => u.id === currentUser.id ? updatedUser : u));
                      setShiftConfigs({
                        ...shiftConfigs,
                        [currentUser.id]: {
                          ...shiftConfigs[currentUser.id],
                          restDay: editRestDay
                        }
                      });
                      showCustomAlert('✅ Cambios guardados con éxito.');
                      setShowSettingsModal(false);
                    }}
                    className="w-full bg-violet-600 hover:bg-violet-755 text-white font-extrabold py-3.5 rounded-2xl text-xs uppercase tracking-wider shadow-md transition-all active:scale-95 border-none outline-none cursor-pointer"
                  >
                    Guardar Cambios
                  </button>

                  <button 
                    onClick={() => {
                      localStorage.removeItem('talent_auth_token');
                      useAppStore.getState().setCurrentUser(null as any);
                      setShowSettingsModal(false);
                      window.location.href = '/login';
                    }}
                    className="w-full bg-rose-600 hover:bg-rose-700 text-white font-extrabold py-3.5 rounded-2xl text-xs uppercase tracking-wider shadow-md transition-all active:scale-95 border-none outline-none mt-1 cursor-pointer"
                  >
                    Cerrar Sesión
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Simulated Lockscreen / Login Screen */}
          {isSessionLocked && (
            <div className="absolute inset-0 bg-slate-950/90 backdrop-blur-xl z-[999] flex flex-col items-center justify-center p-6 animate-fade-in text-white">
              <div className="w-full max-w-sm flex flex-col items-center text-center">
                {/* Logo */}
                <div className="w-16 h-16 rounded-3xl bg-violet-500/10 flex items-center justify-center text-violet-400 mb-6 border border-violet-500/20 shadow-lg animate-pulse">
                  <Clock size={36} />
                </div>
                
                {/* Avatar */}
                <div className="relative mb-4">
                  <img 
                    src={currentUser?.avatar || "https://i.pravatar.cc/150?img=11"} 
                    alt="Avatar" 
                    className="w-24 h-24 rounded-full border-4 border-violet-500 object-cover shadow-2xl" 
                  />
                  <div className="absolute bottom-1 right-1 w-5 h-5 bg-rose-500 border-4 border-slate-950 rounded-full"></div>
                </div>

                <h2 className="text-xl font-black">{currentUser?.name || 'Colaborador'}</h2>
                <p className="text-xs text-slate-400 uppercase tracking-widest mt-1">Sesión Bloqueada</p>
                
                {/* PIN Input */}
                <div className="w-full mt-8">
                  <label className="block text-[10px] font-bold text-slate-500 uppercase mb-2">Ingresa tu contraseña (PIN)</label>
                  <input 
                    type="password" 
                    placeholder="••••" 
                    className="w-full bg-slate-900 border border-slate-800 rounded-2xl px-4 py-3 text-center text-xl font-bold tracking-widest text-white focus:outline-none focus:ring-2 focus:ring-violet-500"
                    value={unlockPin}
                    onChange={e => setUnlockPin(e.target.value)}
                    onKeyDown={e => {
                      if (e.key === 'Enter') {
                        const requiredPin = currentUser?.pin_code || '1234';
                        if (unlockPin === requiredPin) {
                          setIsSessionLocked(false);
                          setUnlockPin('');
                          showCustomAlert('🔓 Sesión desbloqueada con éxito.');
                        } else {
                          showCustomAlert('❌ PIN / Contraseña incorrecta.');
                        }
                      }
                    }}
                  />
                </div>

                <button 
                  onClick={() => {
                    const requiredPin = currentUser?.pin_code || '1234';
                    if (unlockPin === requiredPin) {
                      setIsSessionLocked(false);
                      setUnlockPin('');
                      showCustomAlert('🔓 Sesión desbloqueada con éxito.');
                    } else {
                      showCustomAlert('❌ PIN / Contraseña incorrecta.');
                    }
                  }}
                  className="w-full bg-violet-600 hover:bg-violet-755 text-white font-extrabold py-3.5 rounded-2xl text-xs uppercase tracking-wider mt-4 shadow-lg active:scale-95 transition-all border-none outline-none cursor-pointer"
                >
                  Desbloquear
                </button>

                <p className="text-[10px] text-slate-500 mt-6 font-mono">
                  Sugerencia: Usa el PIN/Contraseña que configuraste en los ajustes (por defecto: {currentUser?.pin_code || '1234'})
                </p>
              </div>
            </div>
          )}

          {/* Desktop Organigrama Modal */}
          {showDesktopOrgModal && (
            <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
              <div className="bg-slate-50 dark:bg-slate-950 w-full max-w-6xl h-[85vh] rounded-3xl overflow-hidden flex flex-col shadow-2xl border border-slate-200 dark:border-slate-800">
                <div className="flex items-center justify-between px-6 py-4 bg-white dark:bg-slate-900 border-b border-slate-100 dark:border-slate-800">
                  <div className="flex items-center gap-2">
                    <Network className="text-emerald-500" size={24} />
                    <h3 className="text-lg font-black text-slate-850 dark:text-slate-100 uppercase tracking-wider">Organigrama de la Empresa</h3>
                  </div>
                  <button 
                    onClick={() => setShowDesktopOrgModal(false)}
                    className="p-2 rounded-full hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
                  >
                    <X size={20} />
                  </button>
                </div>
                <div className="flex-1 overflow-y-auto p-6">
                  <React.Suspense fallback={<div className="p-4 text-center text-xs text-slate-450">Cargando Organigrama...</div>}>
                    <RecursosHumanos readOnly={true} initialTab="organigrama" />
                  </React.Suspense>
                </div>
              </div>
            </div>
          )}

    </div>
  );
}
