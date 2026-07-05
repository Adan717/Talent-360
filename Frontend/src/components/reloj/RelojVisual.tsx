// @ts-nocheck
import React, { useState, useEffect, useRef } from 'react';
import { Clock, CheckSquare, GraduationCap, Settings, Star, Key, WifiOff, ClipboardList, UserX, AlertTriangle, ListTodo } from 'lucide-react';
import { useAppStore } from '../../store/useAppStore';
import { MOCK_USERS, MOCK_STORE } from '../../mockData';
import { TaskRunner } from '../tareas_rutinas/TaskRunner';
import { useTaskStore } from '../../store/useTaskStore';
import { GLOBAL_CONFIG } from '../../globalConfig';
import { useClockContext } from '../store/ClockContext';
import Academia from './Academia';
import Evaluacion360 from './Evaluacion360';

export default function RelojVisual() {
  const [pendingSwapPartner, setPendingSwapPartner] = useState<any>(null);
  const [isSwappingLoading, setIsSwappingLoading] = useState<boolean>(false);
  const { currentTier, isFeatureUnlocked } = useAppStore();
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
    applyPunishments,
    arrivalTimes,
    auditoryLogs,
    baseTimeMinutes,
    breaksTaken,
    broadcastInput,
    btnProps,
    buddyAlerts,
    calculateDailyStats,
    checkInTimes,
    clockState,
    confirmMealReservation,
    contingencyLogs,
    contingencyUsed,
    currentDay,
    currentUser,
    setCurrentUser,
    
    dailyHistory,
    dbPermissions,
    dbRolePermissions,
    declareEmergency,
    designatedCloserId,
    displayHours,
    evalStars,
    expandedCards,
    featureFlags,
    formattedTime,
    getButtonProps,
    globalBroadcastMessage,
    globalClockStates,
    globalPermissions,
    globalRoles,
    globalStoreShiftEnd,
    globalStoreShiftStart,
    globalTimeBank,
    globalToast,
    globalUsers,
    setGlobalUsers,
    isLoadingDB,
    setIsLoadingDB,
    systemSettings,
    updateSetting,
    fetchState,
    handleAction,
    handleAperturaForzosa,
    handleBreakStart,
    handleClockOutRequest,
    handleContingency,
    handleDayChange,
    handleKeyDelegation,
    handleKioscoAdd,
    handleOpenStore,
    handleSubmitPaseLista,
    hasReservedMeal,
    initPaseLista,
    initialShifts,
    initialState,
    innerTool,
    isDropdownOpen,
    isModulesOpen,
    isNativeMode,
    isNativeURL,
    isRealTimeMode,
    isSidebarOpen,
    justificanteText,
    keyholders,
    kioscoInput,
    lateUsers,
    leySillaConfig,
    masterClosePhase,
    matrixTab,
    mealSettings,
    nextDayEncargadoId,
    parseTimeToMins,
    paseListaDone,
    paseListaEmployees,
    phoneTab,
    playAlarm,
    playedAlarms,
    privateInput,
    privateMessages,
    privateTarget,
    processFinalClockOut,
    realSeconds,
    removeAlert,
    reportForm,
    requireEvaluation,
    reservedMeals,
    resetSimulator,
    selectedSummaryDay,
    setAbsenceReason,
    setAbsentUsers,
    setActiveEncargadoId,
    setActivePushNotification,
    setActiveTimers,
    setAdminConfigs,
    setAmnestyActive,
    setApplyPunishments,
    setArrivalTimes,
    setAuditoryLogs,
    setBreaksTaken,
    setBroadcastInput,
    setBuddyAlerts,
    setCheckInTimes,
    setContingencyLogs,
    setContingencyUsed,
    setCurrentDay,
    setDailyHistory,
    setDbPermissions,
    setDbRolePermissions,
    setDesignatedCloserId,
    setEvalStars,
    setExpandedCards,
    setFeatureFlags,
    setGlobalBroadcastMessage,
    setGlobalClockStates,
    setGlobalPermissions,
    setGlobalRoles,
    setGlobalStoreShiftEnd,
    setGlobalStoreShiftStart,
    setGlobalTimeBank,
    setGlobalToast,
    setHasReservedMeal,
    setInnerTool,
    setIsDropdownOpen,
    setIsModulesOpen,
    setIsSidebarOpen,
    setJustificanteText,
    setKeyholders,
    setKioscoInput,
    setLateUsers,
    setLeySillaConfig,
    setMasterClosePhase,
    setMatrixTab,
    setMealSettings,
    setNextDayEncargadoId,
    setPaseListaDone,
    setPaseListaEmployees,
    setPhoneTab,
    setPlayedAlarms,
    setPrivateInput,
    setPrivateMessages,
    setPrivateTarget,
    setRealSeconds,
    setReportForm,
    setRequireEvaluation,
    setReservedMeals,
    setSelectedSummaryDay,
    setShiftConfigs,
    setShowAbsenceModal,
    setShowAmnestyModal,
    setShowCCTVModal,
    setShowEvalModal,
    setShowForzosaModal,
    setShowGhostTheater,
    setShowJustificanteModal,
    setShowKeyDelegationModal,
    setShowMasterCloseModal,
    setShowMealReservationModal,
    setShowPaseListaModal,
    setShowReportModal,
    setShowTransferModal,
    setSimTimeMinutes,
    setStoreOpenLog,
    setStoreOpenSimTime,
    setStoreStatus,
    setSummaryView,
    setTasksChecked,
    setTimeBankConfigs,
    setUndoCount,
    setUserReservedMealSlots,
    setUserSettings,
    setWeeklyHistory,
    shiftConfigs,
    showAbsenceModal,
    showAmnestyModal,
    showCCTVModal,
    showCustomAlert,
    showEvalModal,
    showForzosaModal,
    showGhostTheater,
    showJustificanteModal,
    showKeyDelegationModal,
    showMasterCloseModal,
    showMealReservationModal,
    showPaseListaModal,
    showReportModal,
    showTransferModal,
    simHours,
    simMins,
    
    storeOpenLog,
    storeOpenSimTime,
    storeStatus,
    submitEvaluation,
    submitReport,
    summaryView,
    syncToBackend,
    syncToDB,
    tasksChecked,
    timeBankConfigs,
    toggleSelectAll,
    undoCount,
    updateClockState,
    urlParams,
    userReservedMealSlots,
    userSettings,
    weeklyHistory
  } = useClockContext();

  const assignments = useTaskStore(state => state.assignments);
  const tasks = useTaskStore(state => state.tasks);
  
  const myActiveAssignment = assignments.find(a => a.userId === currentUser?.id && a.status === 'in_progress');
  const myActiveTask = myActiveAssignment ? tasks.find(t => t.id === myActiveAssignment.taskId) : null;
  const isTaskOverdue = myActiveAssignment && myActiveAssignment.expectedEndTimeMins && currentSimTime >= myActiveAssignment.expectedEndTimeMins;
  
  const formatTaskTimeLeft = () => {
    if (!myActiveAssignment?.expectedEndTimeMins) return '';
    const diff = myActiveAssignment.expectedEndTimeMins - currentSimTime;
    if (diff < 0) return `Retraso de ${Math.abs(diff)} min`;
    return `${diff} min restantes`;
  };

  return (
    <div className="flex justify-center items-start w-full bg-slate-50 min-h-screen pt-4 pb-4">
{/* CORPORATE KIOSK STYLE */}
      <div className="w-full max-w-md h-[860px] bg-white rounded-[2.5rem] border-[8px] border-slate-200 shadow-2xl overflow-hidden relative flex-shrink-0 mx-auto text-slate-800">
        
        {/* GLOBAL TOAST DENTRO DEL CELULAR (Estilo iOS Notification) */}
        {globalToast && (
          <div className="absolute top-12 left-4 right-4 z-[100] bg-white/90 backdrop-blur-md text-slate-800 font-semibold px-4 py-3 rounded-2xl shadow-lg animate-fade-in-up border border-white/20 text-sm flex items-center gap-3">
            <div className="bg-indigo-500 rounded-lg p-1.5 flex-shrink-0">
              <span role="img" aria-label="alert" className="text-white text-xs">🔔</span>
            </div>
            <span>{globalToast}</span>
          </div>
        )}

        {/* PUSH NOTIFICATIONS (Actionable) */}
        {activePushNotification && (
          <div 
            onClick={activePushNotification.action}
            className="absolute top-12 left-4 right-4 z-[105] bg-white/95 backdrop-blur-lg text-slate-800 font-semibold px-4 py-4 rounded-2xl shadow-xl animate-fade-in-down border border-slate-200 text-sm flex flex-col gap-2 cursor-pointer hover:bg-slate-50 transition-colors"
          >
            <div className="flex items-center gap-3">
              <div className="bg-rose-500 rounded-lg p-1.5 flex-shrink-0">
                <span role="img" aria-label="alert" className="text-white text-xs">📲</span>
              </div>
              <span className="font-bold">Notificación del Sistema</span>
            </div>
            <p className="text-slate-600 pl-9 font-medium">{activePushNotification.text}</p>
            <div className="text-xs text-indigo-600 font-bold text-right mt-1">Toca para abrir &rarr;</div>
          </div>
        )}
        
        {/* Renderizado Dinámico de Alertas Fase 4 */}
        <div className="absolute top-[80px] left-0 right-0 z-50 flex flex-col gap-2 px-4 pointer-events-none">
          {(buddyAlerts[currentUser.id] || []).map(alert => (
             <div key={alert.id} className={`pointer-events-auto p-4 rounded-2xl shadow-xl flex items-start gap-3 animate-bounce ${alert.type === 'warning' ? 'bg-rose-500 text-white' : 'bg-emerald-500 text-white'}`}>
                <span className="text-2xl">{alert.type === 'warning' ? '⚠️' : '🔔'}</span>
                <div className="flex-1">
                   <p className="text-sm font-bold">{alert.msg}</p>
                </div>
                <button onClick={() => removeAlert(currentUser.id, alert.id)} className="bg-black/20 hover:bg-black/40 text-white rounded-full w-6 h-6 flex items-center justify-center font-bold">✕</button>
             </div>
          ))}
          
          {/* Calculo dinámico de compañeros demorados en el renderizado */}
          {(() => {
             const delayedBuddies = globalUsers.filter(u => {
                if (u.reliefBuddyId === currentUser.id && activeTimers[u.id]) {
                   const timer = activeTimers[u.id];
                   const limit = timer.type === 'meal' ? timeBankConfigs.mealMinutes : timeBankConfigs.shortBreakMinutes;
                   const passed = currentSimTime - timer.startSimTime;
                   if (passed > limit) return true;
                }
                return false;
             });
             
             return delayedBuddies.map(db => (
               <div key={`delay-${db.id}`} className="pointer-events-auto bg-amber-500 text-white p-4 rounded-2xl shadow-xl flex items-start gap-3 animate-pulse border-2 border-amber-300">
                  <span className="text-2xl">⏳</span>
                  <div className="flex-1">
                     <p className="text-sm font-bold leading-tight">¡Tu compañero {db.name} se ha demorado en su descanso!</p>
                  </div>
               </div>
             ));
          })()}
        </div>

        {/* Dynamic Header Kiosk */}
        <div className="absolute top-0 inset-x-0 h-10 bg-white/90 border-b border-slate-100 backdrop-blur-md z-30 flex items-center justify-between px-6">
          <div className="flex items-center gap-2">
            <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse shadow-sm"></div>
            <span className="text-[10px] uppercase tracking-widest text-slate-500 font-bold">Talent360 KIOSK</span>
          </div>
          <div className="text-[10px] text-slate-400 font-bold">Enterprise v2.0</div>
        </div>

        <div className="h-full w-full flex flex-col relative font-sans pt-14 bg-white text-slate-800">
          
          <div className="px-6 py-4 flex items-center justify-between">
            <button onClick={() => { setInnerTool(null); setPhoneTab('perfil'); }} className="flex items-center gap-4 text-left hover:opacity-80 transition-opacity">
              <img src={currentUser.avatar} alt="User avatar" className="w-11 h-11 rounded-full border-2 border-white shadow-sm object-cover" />
              <div>
                <div className="flex items-center gap-1">
                  <div className="font-extrabold text-base leading-tight text-slate-800 tracking-tight">
                    {currentUser.name}
                  </div>
                </div>
                <p className="text-[10px] mt-0.5 leading-none text-blue-600 uppercase tracking-wider font-bold">
                  {currentUser.role}
                </p>
              </div>
            </button>
            <div className="flex items-center gap-2">
              <button 
                onClick={() => setShowReportModal(true)}
                className="w-10 h-10 rounded-full flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors border border-slate-200"
                title="Auditoría Anónima"
              >
                <span className="text-lg">🛡️</span>
              </button>
              <button 
                onClick={() => setPhoneTab('config')}
                className={`w-10 h-10 rounded-full flex items-center justify-center transition-colors border ${phoneTab === 'config' ? 'bg-indigo-50 border-indigo-200 text-indigo-600' : 'bg-slate-100 hover:bg-slate-200 text-slate-600 border-slate-200'}`}
              >
                <span className="text-lg">⚙️</span>
              </button>
            </div>
          </div>

          {phoneTab === 'checador' && shiftConfigs[currentUser?.id]?.restDay === currentDay ? (
            <div className="flex-1 flex flex-col items-center justify-center px-8 text-center animate-fade-in-up">
              <div className="w-24 h-24 bg-emerald-100 rounded-full flex items-center justify-center text-4xl mb-6 shadow-inner border-4 border-white">
                🌴
              </div>
              <h2 className="text-2xl font-extrabold text-slate-800 mb-2">Día de Descanso</h2>
              <p className="text-slate-500 text-sm font-medium mb-8 leading-relaxed">
                ¡Es tu derecho a la desconexión digital! Relájate, recarga energías y disfruta tu día. Tu equipo te cubre hoy.
              </p>

              {privateMessages[currentUser.id] && (
                <div className="w-full bg-rose-50 border border-rose-200 rounded-3xl p-5 shadow-sm text-left animate-pulse mt-4">
                  <div className="flex items-center gap-2 text-rose-700 font-bold mb-2">
                    <span className="text-lg">🚨</span> Urgente: Mensaje del Admin
                  </div>
                  <p className="text-sm text-rose-900 leading-relaxed font-bold mb-4">
                    {privateMessages[currentUser.id]}
                  </p>
                  <button onClick={() => setPrivateMessages(prev => ({...prev, [currentUser.id]: ''}))} className="w-full py-3 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl active:scale-95 transition-colors">
                    Marcar de Enterado
                  </button>
                </div>
              )}
            </div>
          ) : phoneTab === 'checador' && (
            <>
              <div className="px-6 mt-4">
                <div className="bg-slate-50 rounded-2xl p-6 border border-slate-200 shadow-sm relative overflow-hidden">
                  <div className="absolute top-0 right-0 w-32 h-32 bg-blue-500/5 rounded-bl-full -z-0"></div>
                  <div className="relative z-10 flex justify-between items-center mb-6">
                    <div>
                      <p className="text-[10px] text-slate-500 font-extrabold uppercase tracking-widest mb-1">Estatus Operativo</p>
                      <div className={`flex items-center gap-2 font-black text-sm ${storeStatus === 'open' ? 'text-emerald-600' : 'text-slate-600'}`}>
                        <span className={`w-2 h-2 rounded-full ${storeStatus === 'open' ? 'bg-emerald-500' : 'bg-slate-400'}`}></span>
                        {storeStatus === 'open' ? 'SUCURSAL ACTIVA' : 'SUCURSAL INACTIVA'}
                      </div>
                    </div>
                  </div>
                  <div className="relative z-10 text-center bg-white p-4 rounded-xl border border-slate-100 shadow-sm">
                    <p className="text-[10px] text-slate-400 font-extrabold uppercase tracking-widest mb-2">Reloj Local (Simulado)</p>
                    <BlinkingClock displayHours={displayHours} simMins={simMins} ampm={ampm} realSeconds={realSeconds} />
                  </div>
                </div>
              </div>

              <div className="px-6 mt-6 flex flex-col gap-3 min-h-[80px]">
                {clockState === 'inactive' && shiftConfigs[currentUser?.id]?.restDay !== currentDay && currentSimTime >= (parseTimeToMins((shiftConfigs[currentUser?.id]?.start || '09:00')) - 60) && currentSimTime < parseTimeToMins((shiftConfigs[currentUser?.id]?.start || '09:00')) && !contingencyUsed[currentUser.id] && (
                  <button onClick={() => setShowAbsenceModal(true)} className="w-full bg-rose-50 text-rose-600 font-semibold p-4 rounded-2xl flex items-center justify-center gap-2 border border-rose-100 active:scale-95 transition-transform shadow-sm">
                    🚑 Reportar Ausencia o Retardo
                  </button>
                )}
                
                {storeStatus === 'closed' && currentUser.id !== activeEncargadoId && globalPermissions.includes('manage_contingencies') && (
                  <button onClick={() => setShowForzosaModal(true)} className="w-full bg-orange-50 text-orange-600 font-bold p-4 rounded-2xl flex items-center justify-center gap-2 border border-orange-200 active:scale-95 shadow-sm">
                    ⚠️ Apertura Forzosa
                  </button>
                )}
              </div>

              <div className="mt-auto p-6 pb-28 flex flex-col gap-3">
                {clockState === 'active' && 
                 !Object.values(reservedMeals).flat().some(r => r.userId === currentUser.id) && 
                 (currentSimTime >= (checkInTimes[currentUser.id] || 0) + (mealSettings.delayMinutos || 5)) && (
                  <button onClick={() => setShowMealReservationModal(true)} className="w-full bg-amber-50 text-amber-700 font-bold p-4 rounded-2xl flex items-center justify-center gap-2 border border-amber-200 shadow-sm animate-pulse">
                    🍔 ¡Tienes pendiente apartar tu comida!
                  </button>
                )}

                {clockState === 'waiting_room' && storeStatus === 'closed' && (
                  <div className="bg-blue-50 border border-blue-200 rounded-2xl p-4 flex gap-3 items-start animate-fade-in-up">
                    <span className="text-xl">📍</span>
                    <p className="text-xs text-blue-800 font-medium">Llegaste a las <b>{Math.floor(arrivalTimes[currentUser.id] / 60).toString()}:{(arrivalTimes[currentUser.id] % 60).toString().padStart(2,'0')}</b>. Tu lugar en la fila está reservado. Esperando apertura...</p>
                  </div>
                )}
                
                {clockState === 'waiting_room' && storeStatus === 'open' && !btnProps.disabled && (
                  <div className="bg-rose-50 border border-rose-200 rounded-2xl p-4 flex gap-3 items-start animate-fade-in-up">
                    <span className="text-xl">🔴</span>
                    <p className="text-xs text-rose-800 font-medium">El gerente no te seleccionó en el Pase de Lista. Ficha tu entrada manual de contingencia.</p>
                  </div>
                )}

                {currentUser?.has_completed_induction === false && (
                  <div className="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex gap-3 items-start animate-fade-in-up mt-2">
                    <span className="text-xl">⚠️</span>
                    <p className="text-xs text-amber-800 font-medium"><b>Sugerencia:</b> Puedes registrar tu entrada normalmente, pero te recomendamos completar tu Inducción en la Academia para habilitar más opciones.</p>
                  </div>
                )}
                
                {/* Geolocation and Face Recognition Status Bar */}
                <div className="flex justify-center items-center gap-4 py-2.5 px-4 bg-slate-50 border border-slate-150 rounded-2xl mb-4 text-[10px] font-bold text-slate-500">
                  <span className="flex items-center gap-1.5">
                    📍 GPS: {isFeatureUnlocked('gps_validation') ? (
                      <span className="text-emerald-600 flex items-center">Activo (En Rango)</span>
                    ) : (
                      <span className="text-slate-400 font-medium">Inactivo (Plan Libre)</span>
                    )}
                  </span>
                  <span className="h-3 w-[1px] bg-slate-200" />
                  <span className="flex items-center gap-1.5">
                    📷 Selfie: {isFeatureUnlocked('face_validation') ? (
                      <span className="text-emerald-600 flex items-center font-bold">Obligatoria</span>
                    ) : (
                      <span className="text-slate-400 font-medium">Desactivada</span>
                    )}
                  </span>
                </div>

                <button onClick={handleAction} disabled={btnProps.disabled || (clockState === 'waiting_room' && storeStatus === 'closed')} className={`w-full py-5 rounded-2xl transition-all transform hover:opacity-90 shadow-lg ${btnProps.bg.includes('emerald') ? 'bg-emerald-600 text-white hover:bg-emerald-700' : btnProps.bg.includes('rose') ? 'bg-rose-600 text-white hover:bg-rose-700' : btnProps.bg.includes('amber') ? 'bg-amber-500 text-white hover:bg-amber-600' : 'bg-indigo-600 text-white hover:bg-indigo-700'} ${btnProps.disabled || (clockState === 'waiting_room' && storeStatus === 'closed') ? 'opacity-50 cursor-not-allowed shadow-none' : ''}`}>
                  <span className="font-black text-sm tracking-[0.1em] uppercase">{btnProps.text}</span>
                </button>
                
                {/* WIDGET DE TAREA ACTIVA */}
                {myActiveTask && clockState === 'active' && (
                  <div className={`mt-2 rounded-2xl p-4 border-2 transition-all cursor-pointer shadow-sm relative overflow-hidden ${isTaskOverdue ? 'bg-rose-50 border-rose-300 animate-pulse' : 'bg-emerald-50 border-emerald-200 hover:bg-emerald-100'}`} onClick={() => setPhoneTab('tareas')}>
                    {isTaskOverdue && (
                       <div className="absolute top-0 left-0 w-full h-1 bg-rose-500"></div>
                    )}
                    <div className="flex justify-between items-start mb-1">
                      <span className="text-[10px] font-black uppercase tracking-wider text-slate-500 flex items-center gap-1">
                        <span>{isTaskOverdue ? '🚨' : '✅'}</span> Tarea Actual
                      </span>
                      <span className={`text-xs font-bold px-2 py-0.5 rounded-md ${isTaskOverdue ? 'bg-rose-200 text-rose-800' : 'bg-emerald-200 text-emerald-800'}`}>
                        {formatTaskTimeLeft()}
                      </span>
                    </div>
                    <h4 className={`font-bold text-sm ${isTaskOverdue ? 'text-rose-900' : 'text-emerald-900'}`}>{myActiveTask.title}</h4>
                  </div>
                )}
                
                {clockState === 'active' && (
                  <>
                    <button onClick={handleClockOutRequest} className="w-full py-4 mt-3 rounded-[2rem] bg-slate-200 text-slate-700 font-bold flex items-center justify-center gap-2 hover:bg-slate-300 transition-colors">
                      <span>🔴</span> Solicitar Salida (Doble Llave)
                    </button>
                    {globalPermissions.includes('take_breaks') && (
                      <button onClick={handleBreakStart} className="w-full py-4 mt-3 rounded-[2rem] bg-indigo-100 text-indigo-700 font-bold flex items-center justify-center gap-2 hover:bg-indigo-200 transition-colors">
                        <span>🧘</span> Iniciar Descanso (Ley Silla)
                      </button>
                    )}
                  </>
                )}
              </div>
            </>
          )}

          {phoneTab === 'tareas' && (
            <div className="p-6 pb-28 h-full flex flex-col animate-fade-in-up">
              <h3 className={`font-extrabold text-2xl mb-4 flex items-center gap-2 ${userSettings.theme === 'dark' ? 'text-white' : 'text-slate-800'}`}>
                <span>✅</span> Tareas
              </h3>
              <TaskRunner currentUser={currentUser} onBack={() => setPhoneTab('checador')} hideHeader={true} />
            </div>
          )}

          {phoneTab === 'historial' && (
            <div className="p-6 pb-28 h-full flex flex-col animate-fade-in-up">
              <h3 className="font-extrabold text-2xl text-slate-800 mb-6 flex items-center gap-2">
                <span>📅</span> Tu Semana
              </h3>
              
              <div className="flex-grow space-y-4">
                <div className="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm flex items-center gap-4">
                  <div className="w-12 h-12 rounded-full bg-emerald-50 flex items-center justify-center flex-shrink-0 text-xl">🟢</div>
                  <div className="flex-grow">
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Ayer (Jueves)</p>
                    <p className="text-sm font-black text-slate-800">08:00 am</p>
                    <p className="text-xs text-emerald-600 font-medium mt-0.5">A tiempo</p>
                  </div>
                </div>

                <div className="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm flex items-center gap-4">
                  <div className="w-12 h-12 rounded-full bg-amber-50 flex items-center justify-center flex-shrink-0 text-xl">🟡</div>
                  <div className="flex-grow">
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Miércoles</p>
                    <p className="text-sm font-black text-slate-800">08:15 am</p>
                    <p className="text-xs text-amber-600 font-medium mt-0.5">Amnistía / Justificado</p>
                  </div>
                </div>

                <div className="bg-white rounded-2xl p-4 border border-rose-100 shadow-sm flex items-center gap-4 relative overflow-hidden">
                  <div className="absolute top-0 right-0 w-16 h-16 bg-rose-50 rounded-bl-[100px] -z-10"></div>
                  <div className="w-12 h-12 rounded-full bg-rose-100 flex items-center justify-center flex-shrink-0 text-xl">🔴</div>
                  <div className="flex-grow z-10">
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Martes</p>
                    <p className="text-sm font-black text-slate-800">08:22 am</p>
                    <p className="text-xs text-rose-600 font-bold mt-0.5">Retardo (-$50)</p>
                  </div>
                </div>
              </div>
            </div>
          )}

          <div className="absolute bottom-0 inset-x-0 bg-white/90 backdrop-blur-md border-t border-slate-200 p-4 flex justify-around items-center z-40 shadow-[0_-10px_30px_rgba(0,0,0,0.05)]">
            <button onClick={() => { setInnerTool(null); setPhoneTab('checador'); }} className={`${phoneTab === 'checador' ? 'text-indigo-500' : 'text-slate-500 hover:text-indigo-400'} flex flex-col items-center group transition-colors gap-1.5`}>
              <Clock size={22} className={`group-active:scale-90 transition-transform ${phoneTab === 'checador' ? 'scale-110' : ''}`} />
              <span className="text-[10px] font-bold">Checador</span>
            </button>
            <button onClick={() => { setInnerTool(null); setPhoneTab('tareas'); }} className={`${phoneTab === 'tareas' ? 'text-emerald-500' : 'text-slate-500 hover:text-emerald-400'} flex flex-col items-center group transition-colors gap-1.5`}>
              <ListTodo size={22} className={`group-active:scale-90 transition-transform ${phoneTab === 'tareas' ? 'scale-110' : ''}`} />
              <span className="text-[10px] font-bold">Tareas</span>
            </button>
            <button onClick={() => { setInnerTool(null); setPhoneTab('academia'); }} className={`${phoneTab === 'academia' ? 'text-sky-500' : 'text-slate-500 hover:text-sky-400'} flex flex-col items-center group transition-colors gap-1.5`}>
              <GraduationCap size={22} className={`group-active:scale-90 transition-transform ${phoneTab === 'academia' ? 'scale-110' : ''}`} />
              <span className="text-[10px] font-bold">Academia</span>
            </button>
            <button onClick={() => { setInnerTool(null); setPhoneTab('herramientas'); }} className={`${phoneTab === 'herramientas' ? 'text-rose-500' : 'text-slate-500 hover:text-rose-400'} flex flex-col items-center group transition-colors gap-1.5`}>
              <Settings size={22} className={`group-active:scale-90 transition-transform ${phoneTab === 'herramientas' ? 'scale-110' : ''}`} />
              <span className="text-[10px] font-bold">Herramientas</span>
            </button>
          </div>

          {phoneTab === 'herramientas' && (
            <div className="p-6 h-full flex flex-col gap-4 animate-fade-in overflow-y-auto pb-24 custom-scrollbar">
               {innerTool === null ? (
                  <>
                     <h4 className={`font-extrabold text-lg mb-4 ${userSettings.theme === 'dark' ? 'text-white' : 'text-slate-800'}`}>Herramientas</h4>
                     
                     <button onClick={() => setPhoneTab('evaluacion360')} className={`mb-3 p-4 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${userSettings.theme === 'dark' ? 'bg-slate-800/80 border-slate-700 hover:border-slate-500' : 'bg-white border-slate-200 hover:border-slate-300'}`}>
                        <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${userSettings.theme === 'dark' ? 'bg-slate-700 text-amber-400' : 'bg-slate-50 text-slate-600'}`}>
                           <Star size={20} />
                        </div>
                        <div>
                          <p className={`font-bold text-sm ${userSettings.theme === 'dark' ? 'text-slate-200' : 'text-slate-800'}`}>Evaluación 360°</p>
                          <p className={`text-xs ${userSettings.theme === 'dark' ? 'text-slate-400' : 'text-slate-500'}`}>Evaluar compañeros de turno</p>
                        </div>
                     </button>
                     
                     {currentUser.id === designatedCloserId && (
                     <button onClick={() => setInnerTool('transfer')} className={`mb-3 p-4 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left ${userSettings.theme === 'dark' ? 'bg-slate-800/80 border-slate-700 hover:border-slate-500' : 'bg-white border-slate-200 hover:border-slate-300'}`}>
                        <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${userSettings.theme === 'dark' ? 'bg-slate-700 text-indigo-400' : 'bg-slate-50 text-slate-600'}`}>
                           <Key size={20} />
                        </div>
                        <div>
                           <h5 className={`font-bold text-sm ${userSettings.theme === 'dark' ? 'text-slate-200' : 'text-slate-800'}`}>Transferir Cierre</h5>
                           <p className={`text-[10px] leading-tight mt-1 ${userSettings.theme === 'dark' ? 'text-slate-400' : 'text-slate-500'}`}>Delegar responsabilidad al respaldo.</p>
                        </div>
                     </button>
                     )}
                     
                     <button onClick={() => setInnerTool('huida')} className={`mb-3 p-4 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left ${userSettings.theme === 'dark' ? 'bg-slate-800/80 border-slate-700 hover:border-slate-500' : 'bg-white border-slate-200 hover:border-slate-300'}`}>
                        <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${userSettings.theme === 'dark' ? 'bg-slate-700 text-rose-400' : 'bg-slate-50 text-slate-600'}`}>
                           <WifiOff size={20} />
                        </div>
                        <div>
                           <h5 className={`font-bold text-sm ${userSettings.theme === 'dark' ? 'text-slate-200' : 'text-slate-800'}`}>Simular Desconexión</h5>
                           <p className={`text-[10px] leading-tight mt-1 ${userSettings.theme === 'dark' ? 'text-slate-400' : 'text-slate-500'}`}>Corte de red intencional para pruebas.</p>
                        </div>
                     </button>

                     <button onClick={() => setInnerTool('tasks')} className={`mb-3 p-4 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left ${userSettings.theme === 'dark' ? 'bg-slate-800/80 border-slate-700 hover:border-slate-500' : 'bg-white border-slate-200 hover:border-slate-300'}`}>
                        <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${userSettings.theme === 'dark' ? 'bg-slate-700 text-emerald-400' : 'bg-slate-50 text-slate-600'}`}>
                           <ClipboardList size={20} />
                        </div>
                        <div>
                           <p className={`font-bold text-sm ${userSettings.theme === 'dark' ? 'text-slate-200' : 'text-slate-800'}`}>Panel de Tareas</p>
                           <p className={`text-[10px] leading-tight mt-1 ${userSettings.theme === 'dark' ? 'text-slate-400' : 'text-slate-500'}`}>Rutinas y actividades asignadas.</p>
                        </div>
                     </button>
                     
                     <button onClick={() => setInnerTool('anon')} className={`mb-3 p-4 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left ${userSettings.theme === 'dark' ? 'bg-slate-800/80 border-slate-700 hover:border-slate-500' : 'bg-white border-slate-200 hover:border-slate-300'}`}>
                        <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${userSettings.theme === 'dark' ? 'bg-slate-700 text-sky-400' : 'bg-slate-50 text-slate-600'}`}>
                           <UserX size={20} />
                        </div>
                        <div>
                           <p className={`font-bold text-sm ${userSettings.theme === 'dark' ? 'text-slate-200' : 'text-slate-800'}`}>Canal Seguro RRHH</p>
                           <p className={`text-[10px] leading-tight mt-1 ${userSettings.theme === 'dark' ? 'text-slate-400' : 'text-slate-500'}`}>Envío de feedback de forma anónima.</p>
                        </div>
                     </button>

                     <button onClick={() => setInnerTool('report')} className={`mb-3 p-4 rounded-2xl border shadow-sm flex items-center gap-4 transition-all text-left group ${userSettings.theme === 'dark' ? 'bg-slate-800/80 border-slate-700 hover:border-slate-500' : 'bg-white border-slate-200 hover:border-slate-300'}`}>
                        <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${userSettings.theme === 'dark' ? 'bg-slate-700 text-orange-400' : 'bg-slate-50 text-slate-600'}`}>
                           <AlertTriangle size={20} />
                        </div>
                        <div>
                           <p className={`font-bold text-sm ${userSettings.theme === 'dark' ? 'text-slate-200' : 'text-slate-800'}`}>Reportar Incidencia</p>
                           <p className={`text-[10px] leading-tight mt-1 ${userSettings.theme === 'dark' ? 'text-slate-400' : 'text-slate-500'}`}>Notificar ausencias o anomalías operativas.</p>
                        </div>
                     </button>
                  </>
               ) : innerTool === 'transfer' ? (
                  <div className="flex flex-col h-full animate-slide-up">
                     <button onClick={() => setInnerTool(null)} className="text-xs font-bold text-slate-500 mb-4 flex items-center gap-1">← Volver</button>
                     <h3 className="font-black text-slate-800 text-xl mb-4">Transferir Cierre 🔑</h3>
                     <p className="text-xs text-slate-500 mb-4">Selecciona al encargado de respaldo:</p>
                     <div className="space-y-2">
                        {globalUsers.filter(u => u.is_active_employee !== false && u.id !== currentUser.id).map(u => (
                           <button key={u.id} onClick={() => { setDesignatedCloserId(u.id); setInnerTool(null); showCustomAlert('✅ Cierre transferido a ' + u.name); }} className="w-full text-left p-3 rounded-xl border border-slate-200 hover:bg-indigo-50 hover:border-indigo-200 font-bold text-slate-700 shadow-sm">
                              {u.name}
                           </button>
                        ))}
                     </div>
                  </div>
               ) : innerTool === 'huida' ? (
                  <div className="flex flex-col h-full animate-slide-up">
                     <button onClick={() => setInnerTool(null)} className="text-xs font-bold text-slate-500 mb-4 flex items-center gap-1">← Volver</button>
                     <div className="bg-rose-50 p-6 rounded-2xl border border-rose-200 text-center flex-grow flex flex-col justify-center">
                        <span className="text-4xl mb-4">🏃</span>
                        <h3 className="font-black text-rose-800 text-lg mb-2">Pérdida de Wi-Fi</h3>
                        <p className="text-xs text-rose-600 mb-6">Si te vas físicamente de la tienda sin transferir el cierre, el sistema lo detectará automáticamente por GPS o Wi-Fi.</p>
                        <button onClick={() => { if(currentUser.id === designatedCloserId) { showCustomAlert('⚠️ Alerta Crítica: Abandonaste la tienda sin transferir el cierre. Se notificó a Gerencia.'); } else { showCustomAlert('Se ha cerrado tu sesión por pérdida de red.'); }; setInnerTool(null); }} className="bg-rose-600 text-white font-bold py-3 px-6 rounded-xl hover:bg-rose-700">Simular Desconexión</button>
                     </div>
                  </div>
               ) : innerTool === 'tasks' ? (
                  <TaskRunner currentUser={currentUser} onBack={() => setInnerTool(null)} />
               ) : innerTool === 'anon' ? (
                  <div className="flex flex-col h-full animate-slide-up">
                     <button onClick={() => setInnerTool(null)} className="text-xs font-bold text-slate-500 mb-4 flex items-center gap-1">← Volver</button>
                     <h3 className="font-black text-slate-800 text-lg mb-2">Buzón Anónimo 🕵️</h3>
                     <textarea className="w-full h-32 p-3 border border-slate-200 rounded-xl mb-4 text-sm focus:ring-2 focus:ring-indigo-500" placeholder="Escribe tu reporte a RRHH aquí..."></textarea>
                     <button onClick={() => { showCustomAlert('Reporte enviado anónimamente a RRHH.'); setInnerTool(null); }} className="bg-slate-900 text-white font-bold py-3 rounded-xl hover:bg-slate-800">Enviar de forma segura</button>
                  </div>
               ) : innerTool === 'report' ? (
                  <div className="flex flex-col h-full animate-slide-up">
                     <button onClick={() => setInnerTool(null)} className="text-xs font-bold text-slate-500 mb-4 flex items-center gap-1">← Volver</button>
                     <h3 className="font-black text-slate-800 text-lg mb-2">El Soplón 📢</h3>
                     <p className="text-xs text-slate-500 mb-4">¿Quién abandonó su puesto de forma irregular?</p>
                     <div className="space-y-2">
                        {globalUsers.filter(u => u.is_active_employee !== false && u.id !== currentUser.id).map(u => (
                           <button key={u.id} onClick={() => { showCustomAlert('🚨 Reporte crítico generado para ' + u.name); setInnerTool(null); }} className="w-full text-left p-3 rounded-xl border border-rose-200 bg-rose-50 hover:bg-rose-100 font-bold text-rose-700 shadow-sm">
                              Reportar a {u.name}
                           </button>
                        ))}
                     </div>
                  </div>
               ) : null}
            </div>
         )}
                           
                           {phoneTab === 'perfil' && (
            <div className="p-6 pb-28 h-full flex flex-col animate-fade-in-up overflow-y-auto">
              <h3 className={`font-extrabold text-2xl mb-6 flex items-center gap-2 ${userSettings.theme === 'dark' ? 'text-white' : 'text-slate-800'}`}>
                <span>🪪</span> Credencial Digital
              </h3>
              
              <div className={`rounded-3xl p-5 border shadow-sm space-y-4 relative overflow-hidden ${userSettings.theme === 'dark' ? 'bg-slate-800 border-slate-700' : 'bg-white border-slate-100'}`}>
                <div className="absolute top-0 right-0 w-24 h-24 bg-indigo-500/10 rounded-bl-[100px] -z-10"></div>
                
                <div className="flex items-center gap-4 mb-4 border-b border-slate-100 pb-4 dark:border-slate-700">
                   <img src={currentUser.avatar} alt="Avatar" className="w-16 h-16 rounded-full shadow-md border-2 border-indigo-100" />
                   <div>
                      <h4 className={`font-black text-lg leading-tight ${userSettings.theme === 'dark' ? 'text-white' : 'text-slate-800'}`}>{currentUser.name}</h4>
                      <p className="text-indigo-500 font-bold text-xs">{currentUser.role}</p>
                      <p className="text-[10px] text-slate-400 font-medium mt-1">ID Emp: {currentUser.employee_id || 'Pendiente'}</p>
                   </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className={`p-3 rounded-xl border ${userSettings.theme === 'dark' ? 'bg-slate-900 border-slate-700' : 'bg-slate-50 border-slate-100'}`}>
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Entrada</p>
                    <p className={`font-black text-sm ${userSettings.theme === 'dark' ? 'text-white' : 'text-slate-700'}`}>{(shiftConfigs[currentUser?.id]?.start || '09:00') || 'N/A'}</p>
                  </div>
                  <div className={`p-3 rounded-xl border ${userSettings.theme === 'dark' ? 'bg-slate-900 border-slate-700' : 'bg-slate-50 border-slate-100'}`}>
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Salida</p>
                    <p className={`font-black text-sm ${userSettings.theme === 'dark' ? 'text-white' : 'text-slate-700'}`}>{shiftConfigs[currentUser?.id]?.end || 'N/A'}</p>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className={`p-3 rounded-xl border ${userSettings.theme === 'dark' ? 'bg-slate-900 border-slate-700' : 'bg-slate-50 border-slate-100'}`}>
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Día Descanso</p>
                    <p className={`font-black text-sm text-indigo-500`}>{shiftConfigs[currentUser?.id]?.restDay || 'N/A'}</p>
                  </div>
                  <div className={`p-3 rounded-xl border ${userSettings.theme === 'dark' ? 'bg-slate-900 border-slate-700' : 'bg-slate-50 border-slate-100'}`}>
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Tiempo Comida</p>
                    <p className={`font-black text-sm ${userSettings.theme === 'dark' ? 'text-white' : 'text-slate-700'}`}>{shiftConfigs[currentUser?.id]?.mealMinutes || 0} min</p>
                  </div>
                </div>

                <div className={`p-3 rounded-xl border flex items-center justify-between ${userSettings.theme === 'dark' ? 'bg-slate-900 border-slate-700' : 'bg-slate-50 border-slate-100'}`}>
                  <div>
                    <p className={`text-xs font-bold flex items-center gap-2 ${userSettings.theme === 'dark' ? 'text-slate-200' : 'text-slate-800'}`}>🔑 Jerarquía de Llaves</p>
                    <p className="text-[9px] text-slate-500">Permiso asignado por RRHH</p>
                  </div>
                  <span className={`text-[10px] font-black px-2 py-1 rounded-md uppercase ${shiftConfigs[currentUser?.id]?.portadorLlaves !== 'ninguno' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500'}`}>
                     {shiftConfigs[currentUser?.id]?.portadorLlaves || 'Ninguno'}
                  </span>
                </div>

                <p className="text-[9px] text-slate-400 text-center italic mt-2">
                   * Si tu horario es incorrecto, contacta a Recursos Humanos. Los horarios no pueden ser modificados desde esta terminal.
                </p>
              </div>
            </div>
          )}

          {phoneTab === 'academia' && (
            <div className="h-full animate-fade-in overflow-y-auto pb-24 custom-scrollbar bg-slate-50">
              <Academia onBack={() => setPhoneTab('checador')} />
            </div>
          )}

          {phoneTab === 'evaluacion360' && (
            <div className="absolute inset-0 z-50 bg-slate-900 animate-fade-in">
              <Evaluacion360 onBack={() => setPhoneTab('herramientas')} />
            </div>
          )}

          {phoneTab === 'config' && (
            <div className="p-6 pb-28 h-full flex flex-col animate-fade-in-up overflow-y-auto">
              <h3 className={`font-extrabold text-2xl mb-6 flex items-center gap-2 ${userSettings.theme === 'dark' ? 'text-white' : 'text-slate-800'}`}>
                <span>⚙️</span> Opciones App
              </h3>
              
              <div className={`rounded-3xl p-5 border shadow-sm space-y-5 ${userSettings.theme === 'dark' ? 'bg-slate-800 border-slate-700' : 'bg-white border-slate-100'}`}>
                {/* Opcion: Tema */}
                <div>
                  <div className="flex justify-between items-center mb-2">
                    <label className={`block text-xs font-bold uppercase ${userSettings.theme === 'dark' ? 'text-slate-300' : 'text-slate-600'}`}>Modo Oscuro</label>
                    <span className="text-[10px] text-slate-400 bg-slate-100 dark:bg-slate-700 px-2 rounded-md">Visual</span>
                  </div>
                  <div className="flex bg-slate-100 dark:bg-slate-900 rounded-lg p-1">
                    <button 
                      onClick={() => adminConfigs.allowThemes && setUserSettings(s => ({...s, theme: 'light'}))}
                      className={`flex-1 py-1.5 text-xs font-bold rounded-md transition-all ${userSettings.theme === 'light' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'} ${!adminConfigs.allowThemes && 'opacity-50 cursor-not-allowed'}`}
                    >
                      ☀️ Claro
                    </button>
                    <button 
                      onClick={() => adminConfigs.allowThemes && setUserSettings(s => ({...s, theme: 'dark'}))}
                      className={`flex-1 py-1.5 text-xs font-bold rounded-md transition-all ${userSettings.theme === 'dark' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'} ${!adminConfigs.allowThemes && 'opacity-50 cursor-not-allowed'}`}
                    >
                      🌙 Oscuro
                    </button>
                  </div>
                  {!adminConfigs.allowThemes && <p className="text-[9px] text-rose-500 mt-1 font-bold">Bloqueado por el Administrador</p>}
                </div>

                {/* Opcion: Tamaño de Fuente */}
                <div>
                  <div className="flex justify-between items-center mb-2">
                    <label className={`block text-xs font-bold uppercase ${userSettings.theme === 'dark' ? 'text-slate-300' : 'text-slate-600'}`}>Tamaño de Letra</label>
                  </div>
                  <input type="range" min="1" max="3" value={userSettings.fontSize === 'normal' ? 2 : 1} onChange={() => {}} className="w-full accent-indigo-500" disabled />
                  <p className="text-[9px] text-slate-400 mt-1">Próximamente disponible</p>
                </div>

                {/* Botón Cerrar Sesión */}
                <div className="pt-4 border-t border-slate-100 dark:border-slate-700">
                  <button 
                    onClick={() => {
                      localStorage.removeItem('talent_auth_token');
                      window.location.href = '/login';
                    }}
                    className="w-full bg-rose-600 hover:bg-rose-700 text-white font-extrabold py-3.5 rounded-2xl shadow-lg shadow-rose-600/20 active:scale-95 transition-all text-sm uppercase tracking-wider text-center"
                  >
                    ❌ Cerrar Sesión
                  </button>
                </div>

              </div>
            </div>
          )}

          {/* Modal Pase de Lista */}
          {showPaseListaModal && (
            <div className="absolute inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex flex-col pt-12 pb-6 px-4 animate-fade-in-up">
              <div className="bg-white rounded-3xl p-5 w-full flex-grow flex flex-col shadow-2xl relative overflow-hidden">
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

          {/* Otros Modales */}
          {showForzosaModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
              <div className="bg-white rounded-3xl p-6 w-full shadow-2xl animate-fade-in-up border-4 border-orange-500">
                <h3 className="font-black text-xl text-orange-600 mb-2">⚠️ Apertura Forzosa</h3>
                <p className="text-sm text-slate-600 mb-4">El Titular no avisó de su ausencia. Si tomas el control, se generará una alerta de seguridad.</p>
                <textarea className="w-full bg-orange-50 border border-orange-200 rounded-xl p-3 text-sm mb-4 outline-none focus:ring-2 focus:ring-orange-500" rows="3" placeholder="Ej. El titular olvidó su celular..."></textarea>
                <button onClick={handleAperturaForzosa} className="w-full bg-orange-600 text-white font-bold py-4 rounded-2xl mb-2 shadow-lg">Tomar el Control y Abrir</button>
                <button onClick={() => setShowForzosaModal(false)} className="w-full bg-slate-100 text-slate-700 font-bold py-3 rounded-2xl">Cancelar</button>
              </div>
            </div>
          )}
          {showEvalModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-4">
              <div className="bg-white rounded-3xl p-6 w-full shadow-2xl animate-fade-in-up">
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
          {showAmnestyModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-6 animate-fade-in-up">
              <div className="bg-white rounded-3xl p-6 w-full shadow-2xl">
                <h3 className="font-bold text-lg text-slate-800">Justificación de Amnistía</h3>
                <textarea className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm my-4 outline-none focus:ring-2 focus:ring-indigo-500" rows="3" placeholder="Motivo..."></textarea>
                <button onClick={() => handleOpenStore(true)} className="w-full bg-indigo-600 text-white font-bold py-4 rounded-2xl shadow-lg">Decretar Amnistía y Continuar</button>
              </div>
            </div>
          )}
          {showAbsenceModal && (
            <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex flex-col justify-end">
              <div className="bg-white rounded-t-3xl p-6 pb-12 w-full animate-fade-in-up">
                <h3 className="font-bold text-rose-600 mb-2 text-lg">Reportar Contingencia</h3>
                <p className="text-xs text-slate-500 mb-3 bg-rose-50 p-2 rounded-lg border border-rose-100">Si eres gerente y abres sucursal, las llaves pasarán automáticamente al siguiente mando.</p>
                
                <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Motivo (Obligatorio)</label>
                <textarea 
                   className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm mb-4 outline-none focus:ring-2 focus:ring-amber-500" 
                   rows={2} 
                   placeholder="Ej. Mi camión se descompuso..."
                   value={absenceReason}
                   onChange={(e) => setAbsenceReason(e.target.value)}
                ></textarea>
                
                <div className="grid grid-cols-2 gap-3 mb-3">
                  <button onClick={() => handleContingency('late')} className="bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 rounded-xl flex flex-col items-center justify-center">
                    <span className="text-lg">⏳</span>
                    <span className="text-xs mt-1">Llegaré tarde</span>
                  </button>
                  <button onClick={() => handleContingency('absent')} className="bg-rose-600 hover:bg-rose-700 text-white font-bold py-3 rounded-xl flex flex-col items-center justify-center">
                    <span className="text-lg">❌</span>
                    <span className="text-xs mt-1">No asistiré hoy</span>
                  </button>
                </div>
                <button onClick={() => setShowAbsenceModal(false)} className="w-full bg-slate-100 text-slate-700 font-bold py-3 rounded-xl">Cancelar</button>
              </div>
            </div>
          )}
          
          {showJustificanteModal && (
            <div className="absolute inset-0 bg-slate-900/80 backdrop-blur-md z-50 flex flex-col justify-center p-6 animate-fade-in">
              <div className="bg-white rounded-3xl p-6 w-full shadow-2xl border-4 border-rose-500 overflow-hidden relative">
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
                       className="w-full bg-slate-50 border border-slate-200 rounded-xl p-4 text-sm mb-6 outline-none focus:ring-2 focus:ring-rose-500" 
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
                           showCustomAlert("La justificación debe ser detallada.");
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

          {showKeyDelegationModal && (
            <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex flex-col justify-end">
              <div className="bg-white rounded-t-3xl p-6 pb-12 w-full animate-fade-in-up">
                <h3 className="font-bold text-indigo-600 mb-2 text-xl flex items-center gap-2"><span>🔑</span> Entregar Llaves</h3>
                <p className="text-sm text-slate-600 mb-4 bg-indigo-50 p-3 rounded-xl border border-indigo-100">Mañana es tu día de descanso. Selecciona al encargado que abrirá la sucursal mañana.</p>
                
                <div className="space-y-2 mb-6">
                  {globalUsers.filter(u => u.is_active_employee !== false && keyholders.includes(u.id) && u.id !== currentUser.id).map(u => (
                    <button
                      key={u.id}
                      onClick={() => setNextDayEncargadoId(u.id)}
                      className={`w-full p-4 rounded-xl border-2 flex items-center justify-between transition-all ${nextDayEncargadoId === u.id ? 'border-indigo-500 bg-indigo-50' : 'border-slate-100 bg-white hover:bg-slate-50'}`}
                    >
                      <div className="flex items-center gap-3">
                        <img src={u.avatar} alt="Avatar" className="w-8 h-8 rounded-full" />
                        <div className="text-left">
                          <p className="font-bold text-slate-800 text-sm">{u.name}</p>
                          <p className="text-[10px] text-slate-500">{u.role}</p>
                        </div>
                      </div>
                      <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center ${nextDayEncargadoId === u.id ? 'border-indigo-500' : 'border-slate-300'}`}>
                        {nextDayEncargadoId === u.id && <div className="w-2.5 h-2.5 bg-indigo-500 rounded-full"></div>}
                      </div>
                    </button>
                  ))}
                  {globalUsers.filter(u => u.is_active_employee !== false && keyholders.includes(u.id) && u.id !== currentUser.id).length === 0 && (
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
          {showReportModal && (
            <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex flex-col justify-end">
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
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-amber-500"
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
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-amber-500"
                      value={reportForm.type}
                      onChange={e => setReportForm({...reportForm, type: e.target.value})}
                    >
                      <option value="">Selecciona un motivo...</option>
                      <option value="inactividad">Demasiada Inactividad</option>
                      <option value="abandono">Abandono de área sin avisar</option>
                      <option value="conducta">Mala conducta / Faltas de respeto</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Detalles</label>
                    <textarea 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-amber-500" 
                      rows={3} 
                      placeholder="Explica qué sucedió..."
                      value={reportForm.details}
                      onChange={e => setReportForm({...reportForm, details: e.target.value})}
                    ></textarea>
                  </div>
                </div>

                <button onClick={submitReport} className="w-full bg-amber-500 hover:bg-amber-600 text-white font-bold py-4 rounded-2xl mb-3 shadow-lg transition-colors">Enviar Reporte</button>
                <button onClick={() => setShowReportModal(false)} className="w-full bg-slate-100 text-slate-700 font-bold py-4 rounded-2xl hover:bg-slate-200 transition-colors">Cancelar</button>
              </div>
            </div>
          )}

        </div>
      </div>

    </div>
  );
}


// Subcomponente recreado
const BlinkingClock = ({ displayHours, simMins, ampm, realSeconds }: any) => {
  return (
    <div className="text-4xl font-mono tracking-widest text-slate-100 flex items-center justify-center font-bold">
      {displayHours.toString().padStart(2, '0')}
      <span className={realSeconds % 2 === 0 ? "opacity-100" : "opacity-0"}>:</span>
      {simMins.toString().padStart(2, '0')}
      <span className="text-xl ml-2 text-slate-400">{ampm}</span>
    </div>
  );
};
