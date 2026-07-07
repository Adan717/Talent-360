// @ts-nocheck
import React, { useState, useEffect } from 'react';
import { useAppStore } from '../../store/useAppStore';
import { ClockContext } from '../store/ClockContext';
import { useClockEngine } from './useClockEngine';
import RelojVisual from './RelojVisual';
import axiosInstance from '../../lib/axios';

class PhoneErrorBoundary extends React.Component<{ children: React.ReactNode }, { hasError: boolean; error: any }> {
  constructor(props: any) {
    super(props);
    this.state = { hasError: false, error: null };
  }
  static getDerivedStateFromError(error: any) {
    return { hasError: true, error };
  }
  componentDidCatch(error: any, errorInfo: any) {
    console.error("Phone Render Crash:", error, errorInfo);
  }
  render() {
    if (this.state.hasError) {
      return (
        <div className="flex flex-col items-center justify-center h-full bg-slate-950 p-6 text-center select-none border border-rose-500/20 overflow-y-auto">
          <span className="text-2xl mb-2">📱💥</span>
          <h4 className="text-[10px] font-black uppercase tracking-wider text-rose-400">Error de Celular</h4>
          <p className="text-[10px] text-rose-300 mt-2 font-mono leading-normal max-w-[240px] break-words">
            {this.state.error?.message || String(this.state.error)}
          </p>
          <pre className="text-[8px] text-slate-500 mt-4 font-mono text-left max-w-full overflow-x-auto leading-tight p-2 bg-slate-900 rounded border border-slate-800">
            {this.state.error?.stack || 'No stack trace available'}
          </pre>
        </div>
      );
    }
    return this.props.children;
  }
}

function MiniaturaCelular({ user, scale }: { user: any; scale: number }) {
  const engine = useClockEngine(user);

  // Sincronizar estado local simulado hacia la store global para la tabla dinámica
  const { setGlobalClockState, setGlobalCheckInTime, setGlobalArrivalTime } = useAppStore();
  
  useEffect(() => {
    if (user?.id && engine.clockState !== undefined) {
      setGlobalClockState(user.id, engine.clockState);
    }
  }, [engine.clockState, user?.id]);

  useEffect(() => {
    if (user?.id && engine.checkInTimes?.[user.id] !== undefined) {
      setGlobalCheckInTime(user.id, engine.checkInTimes[user.id]);
    }
  }, [engine.checkInTimes, user?.id]);

  useEffect(() => {
    if (user?.id && engine.arrivalTimes?.[user.id] !== undefined) {
      setGlobalArrivalTime(user.id, engine.arrivalTimes[user.id]);
    }
  }, [engine.arrivalTimes, user?.id]);

  if (engine.isGlobalLoading) {
    return (
      <div className="flex items-center justify-center h-full bg-slate-900 text-slate-500 text-xs">
        <span className="animate-pulse">Cargando cel...</span>
      </div>
    );
  }

  if (engine.dbEmpty) {
    return (
      <div className="flex items-center justify-center h-full bg-slate-900 text-rose-500 text-xs">
        <span>Error: BD vacía</span>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-full bg-slate-900 select-none">
      {/* Simulation Controls Overlay (Not Scaled) */}
      <div className="bg-slate-800 border-b border-slate-700/80 p-2 flex justify-between items-center text-xs shrink-0 z-20">
        {/* Offline Simulation Toggle */}
        <button 
          onClick={() => engine.setIsSimulatedOffline(!engine.isSimulatedOffline)}
          className={`px-2 py-0.5 rounded font-black text-[9px] uppercase tracking-wider transition-all active:scale-95 ${
            engine.isSimulatedOffline 
              ? 'bg-rose-500/20 text-rose-400 border border-rose-500/40 animate-pulse' 
              : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/35'
          }`}
        >
          {engine.isSimulatedOffline ? '📡 Offline' : '📶 Online'}
        </button>

        {/* GPS Simulation Quick-Toggle Dropdown */}
        <select
          value={engine.gpsStatus === 'error' ? 'off' : (engine.gpsCoordinates?.latitude === 19.4326 ? 'in' : 'out')}
          onChange={(e) => {
            const val = e.target.value;
            if (val === 'off') {
              engine.setGpsStatus('error');
            } else if (val === 'in') {
              engine.setGpsStatus('success');
              engine.setGpsCoordinates({ latitude: 19.4326, longitude: -99.1332 });
            } else {
              engine.setGpsStatus('success');
              engine.setGpsCoordinates({ latitude: 19.4344, longitude: -99.1332 });
            }
          }}
          className="bg-slate-900 text-slate-300 border border-slate-700 rounded px-1 py-0.5 text-[9px] font-black uppercase tracking-wider outline-none cursor-pointer"
        >
          <option value="in">📍 GPS: En Sucursal</option>
          <option value="out">🏠 GPS: En Casa</option>
          <option value="off">❌ GPS: Apagado</option>
        </select>
      </div>

      {/* Scaled Cellphone frame */}
      <div className="flex-grow overflow-hidden relative">
        <ClockContext.Provider value={engine}>
          <div 
            className="w-[400px] h-[850px] transform origin-top-left pointer-events-auto"
            style={{ transform: `scale(${scale})` }}
          >
            <RelojVisual isMobileFrame={true} />
          </div>
        </ClockContext.Provider>
      </div>
    </div>
  );
}

export default function PanelSimulador() {
  const [phoneScale, setPhoneScale] = useState(0.5); // Default a 50% para ver más celulares simultáneamente
  const [resetKey, setResetKey] = useState(0);
  const { 
    globalUsers, 
    storeStatus, 
    setStoreStatus, 
    fetchState,
    globalSimTime,
    setGlobalSimTime,
    globalSimRunning,
    setGlobalSimRunning,
    globalSimSpeed,
    setGlobalSimSpeed,
    matrixTimeline,
    addMatrixEvent,
    resetGlobalSimulation,
    hasAlertedStoreDelay,
    setHasAlertedStoreDelay,
    globalSimDay,
    setGlobalSimDay,
    currentTier,
    simulatedTierOverride,
    setSimulatedTierOverride
  } = useAppStore();

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
     useAppStore.getState().setIsSandboxMode(true);
     // Polling de 5 segundos para mantener la QA Matrix sincronizada con los fichajes reales del backend
     const interval = setInterval(() => {
         fetchState();
     }, 5000);
     return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    if ((globalUsers || []).length === 0) {
      fetchState();
    }
  }, []);

  // Time Machine Loop
  useEffect(() => {
    let interval: any;
    if (globalSimRunning) {
      interval = setInterval(() => {
        setGlobalSimTime((prev: number) => {
          const nextTime = prev + globalSimSpeed;
          
          // Watchdog: Alerta de retraso de apertura basado en storeSchedule.openTime (tolerancia de 15 minutos)
          const storeOpenTime = useAppStore.getState().systemSettings?.storeSchedule?.openTime || '08:00';
          const openTimeParts = storeOpenTime.split(':');
          const openTimeMins = parseInt(openTimeParts[0]) * 60 + parseInt(openTimeParts[1]);
          const alertTimeMins = openTimeMins + 15; // 15 minutos de tolerancia
          
          if (!hasAlertedStoreDelay && nextTime >= alertTimeMins && storeStatus === 'closed') {
             addMatrixEvent(
               'Alerta Crítica: Retraso de Apertura',
               `El reloj cruzó las ${parseMinsToTime(alertTimeMins)} y la sucursal aún permanece cerrada físicamente. Se recomienda contactar al Encargado.`,
               'error'
             );
             setHasAlertedStoreDelay(true);
          }

          if (nextTime >= 1140) { // 7:00 PM
            setGlobalSimRunning(false);
            return nextTime;
          }
          return nextTime;
        });
      }, 1000); // Avanza los minutos simulados cada segundo real
    }
    return () => clearInterval(interval);
  }, [globalSimRunning, globalSimSpeed, hasAlertedStoreDelay, storeStatus]);

  const toggleStore = async () => {
    const isOpening = storeStatus === 'closed';
    try {
      await axiosInstance.post('/sync/store_log', {
        type: isOpening ? 'open' : 'close',
        date: new Date().toISOString().split('T')[0],
        time: new Date().toTimeString().split(' ')[0],
        notes: 'Cierre Maestro/Apertura desde el panel simulador Matrix QA'
      });
      setStoreStatus(isOpening ? 'open' : 'closed');
      addMatrixEvent(
        isOpening ? 'Apertura de Sucursal' : 'Cierre de Sucursal',
        isOpening ? 'Se han abierto las puertas de la sucursal globalmente.' : 'Se han cerrado las puertas de la sucursal globalmente.',
        isOpening ? 'success' : 'warning'
      );
    } catch (err) {
      console.error('Error al cambiar estado de la tienda:', err);
      alert('Error de conexión al guardar el registro de apertura/cierre.');
    }
  };

  const handleReset = async () => {
    if (confirm('¿Estás seguro de que deseas limpiar la bitácora y reiniciar el estado de todos los empleados a las 7:30 AM?')) {
      try {
        localStorage.removeItem('clock_sync_queue');
        localStorage.removeItem('clock_break_start_times');
        localStorage.removeItem('clock_break_end_times');
        localStorage.removeItem('clock_meal_start_times');
        localStorage.removeItem('clock_meal_end_times');
        localStorage.removeItem('clock_checkout_times');
        localStorage.removeItem('store_daily_opening_status');
        localStorage.removeItem('store_opening_assignments');
        localStorage.removeItem('opening_checklist_completed');
        localStorage.removeItem('opening_roll_call_completed');
        for (let i = 0; i < 5; i++) {
          localStorage.removeItem(`open_task_${i}`);
        }

        await axiosInstance.post('/sync/reset');
        await resetGlobalSimulation();
        setResetKey(prev => prev + 1);
        alert('Simulación y base de datos reiniciadas con éxito.');
      } catch (err) {
        console.error('Error al reiniciar base de datos de simulación:', err);
        alert('Error al conectar con el servidor backend para limpiar la base de datos.');
      }
    }
  };

  const parseMinsToTime = (mins: number) => {
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    const ampm = h >= 12 ? 'pm' : 'am';
    const displayH = h > 12 ? h - 12 : h;
    return `${displayH}:${m.toString().padStart(2, '0')} ${ampm}`;
  };

  return (
    <div className="flex flex-col xl:flex-row h-[90vh] bg-slate-900 rounded-3xl overflow-hidden border border-slate-700 shadow-2xl gap-4">
      
      {/* PANEL IZQUIERDO: CONTROLES MATRIX Y CELULARES */}
      <div className="flex-1 flex flex-col min-w-0 border-r border-slate-700">
        {/* HEADER MATRIX & TIME MACHINE */}
        <div className="bg-slate-800 border-b border-slate-700 p-6 flex flex-col gap-6">
          <div className="flex justify-between items-center">
            <div>
              <h2 className="text-2xl font-black text-emerald-400 flex items-center gap-2">
                <span className="text-3xl">🖥️</span> Matrix QA (Multi-Celular)
              </h2>
              <p className="text-slate-400 text-sm">Pruebas simultáneas en tiempo real</p>
            </div>
            
            <div className="flex gap-4">
              <div className="px-6 py-3 rounded-xl font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center gap-2">
                <span>🟢 BD REAL: POSTGRES</span>
              </div>
              
              <button onClick={handleReset} className="px-6 py-3 rounded-xl font-bold transition-colors bg-slate-700 text-slate-300 border border-slate-600 hover:bg-slate-600">
                🧹 Limpiar y Reiniciar
              </button>
              <button onClick={toggleStore} className={`px-6 py-3 rounded-xl font-bold transition-colors ${storeStatus === 'open' ? 'bg-rose-500/20 text-rose-400 border border-rose-500/50 hover:bg-rose-500/40' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50 hover:bg-emerald-500/40'}`}>
                {storeStatus === 'open' ? '🔒 Cerrar Tienda (Global)' : '🔓 Abrir Tienda (Global)'}
              </button>
            </div>
          </div>

          {/* Plan Selector & Active Modules Visualizer */}
          <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center bg-slate-900/60 border border-slate-700/80 rounded-2xl p-4 gap-4">
            <div className="flex flex-col gap-1.5 shrink-0">
              <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Licencia SaaS Activa (QA Override)</span>
              <div className="flex items-center bg-slate-800 rounded-xl p-1 border border-slate-700 gap-1">
                <button
                  onClick={() => setSimulatedTierOverride('freemium')}
                  className={`px-3.5 py-1.5 rounded-lg font-black text-xs transition-all flex items-center gap-1 cursor-pointer select-none border border-transparent ${
                    currentTier === 'freemium' 
                      ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 shadow-md shadow-emerald-500/5' 
                      : 'text-slate-400 hover:text-slate-200'
                  }`}
                >
                  <span>🆓</span> Freemium (Gratuito)
                </button>
                <button
                  onClick={() => setSimulatedTierOverride('pro')}
                  className={`px-3.5 py-1.5 rounded-lg font-black text-xs transition-all flex items-center gap-1 cursor-pointer select-none border border-transparent ${
                    currentTier === 'pro' 
                      ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30 shadow-md shadow-amber-500/5' 
                      : 'text-slate-400 hover:text-slate-200'
                  }`}
                >
                  <span>⚡</span> Pro (Premium)
                </button>
                <button
                  onClick={() => setSimulatedTierOverride('enterprise')}
                  className={`px-3.5 py-1.5 rounded-lg font-black text-xs transition-all flex items-center gap-1 cursor-pointer select-none border border-transparent ${
                    currentTier === 'enterprise' 
                      ? 'bg-purple-500/20 text-purple-400 border border-purple-500/30 shadow-md shadow-purple-500/5' 
                      : 'text-slate-400 hover:text-slate-200'
                  }`}
                >
                  <span>👑</span> Enterprise (Dedicado)
                </button>
                {simulatedTierOverride && (
                  <button
                    onClick={() => setSimulatedTierOverride(null)}
                    className="px-2.5 py-1.5 rounded-lg font-bold text-[10px] text-indigo-400 hover:text-indigo-300 transition-colors border border-transparent cursor-pointer"
                    title="Restablecer al plan real de la base de datos"
                  >
                    Restablecer
                  </button>
                )}
              </div>
            </div>

            <div className="flex flex-col gap-1.5 w-full lg:w-auto">
              <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Módulos Habilitados en este Plan</span>
              <div className="flex flex-wrap gap-2">
                {[
                  { id: 'reloj', name: 'Reloj Checador', tier: 'freemium' },
                  { id: 'rrhh', name: 'Recursos Humanos', tier: 'freemium' },
                  { id: 'operativo', name: 'Tareas / Rutinas', tier: 'freemium' },
                  { id: 'reportes', name: 'Reportes y Nómina', tier: 'pro' },
                  { id: 'ats', name: 'Bolsa de Trabajo ATS', tier: 'pro' },
                  { id: 'academia', name: 'Academia LMS', tier: 'pro' },
                  { id: 'documentos', name: 'Gestor Documental', tier: 'pro' }
                ].map(mod => {
                  const isUnlocked = currentTier === 'enterprise' || 
                                     mod.tier === 'freemium' || 
                                     (currentTier === 'pro' && mod.tier === 'pro');
                  return (
                    <span 
                      key={mod.id} 
                      className={`text-[9px] font-black uppercase tracking-wider px-2 py-1 rounded-lg border flex items-center gap-1 select-none transition-all ${
                        isUnlocked 
                          ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' 
                          : 'bg-slate-800/40 text-slate-500 border-slate-700/60 line-through opacity-60'
                      }`}
                    >
                      <span>{isUnlocked ? '🟢' : '🔴'}</span>
                      {mod.name}
                    </span>
                  );
                })}
              </div>
            </div>
          </div>

          {/* TIME MACHINE CONTROLS */}
          <div className="bg-slate-900 rounded-2xl p-4 border border-slate-700 shadow-inner flex flex-col gap-4">
            <div className="flex justify-between items-center">
              <span className="text-emerald-400 font-bold uppercase tracking-widest text-xs">Máquina del Tiempo</span>
              <div className="flex items-center gap-3">
                <select
                  value={globalSimDay}
                  onChange={(e) => setGlobalSimDay(e.target.value)}
                  className="bg-slate-800 text-white border border-slate-700 rounded-lg px-2.5 py-1 font-bold text-xs outline-none cursor-pointer"
                >
                  <option value="Lunes">📅 Lunes</option>
                  <option value="Martes">📅 Martes</option>
                  <option value="Miércoles">📅 Miércoles</option>
                  <option value="Jueves">📅 Jueves</option>
                  <option value="Viernes">📅 Viernes</option>
                  <option value="Sábado">📅 Sábado</option>
                  <option value="Domingo">📅 Domingo</option>
                </select>
                <span className="text-2xl font-black text-white">{parseMinsToTime(globalSimTime)}</span>
              </div>
            </div>
            
            <input 
              type="range" 
              min={450} 
              max={1140} 
              value={globalSimTime}
              onChange={(e) => setGlobalSimTime(Number(e.target.value))}
              className="w-full accent-emerald-500 cursor-pointer"
            />
            
            <div className="flex justify-between items-center mt-2">
              <div className="flex gap-2">
                <button 
                  onClick={() => setGlobalSimRunning(!globalSimRunning)}
                  className={`px-4 py-2 rounded-lg font-bold text-sm ${globalSimRunning ? 'bg-rose-500 text-white' : 'bg-emerald-500 text-white'}`}
                >
                  {globalSimRunning ? '⏸️ Pausar Simulación' : '▶️ Auto-Run'}
                </button>
                <select 
                  value={globalSimSpeed} 
                  onChange={(e) => setGlobalSimSpeed(Number(e.target.value))}
                  className="bg-slate-800 text-slate-300 border border-slate-600 rounded-lg px-3 text-sm outline-none"
                >
                  <option value={1}>x1 minuto / seg</option>
                  <option value={2}>x2 minutos / seg</option>
                  <option value={5}>x5 minutos / seg</option>
                  <option value={15}>x15 minutos / seg</option>
                </select>
              </div>
              <span className="text-xs text-slate-500">7:30 AM - 7:00 PM</span>
            </div>

            <div className="flex justify-between items-center mt-2 border-t border-slate-800 pt-3">
              <div className="flex flex-col gap-1.5 w-full">
                <div className="flex justify-between items-center text-[10px] font-black uppercase tracking-wider text-slate-400">
                  <span>Escala de Visualización</span>
                  <span className="text-emerald-400">{Math.round(phoneScale * 100)}%</span>
                </div>
                <div className="flex flex-col sm:flex-row items-center gap-3 w-full">
                  <input 
                    type="range" 
                    min="0.35" 
                    max="1.0" 
                    step="0.05"
                    value={phoneScale}
                    onChange={(e) => setPhoneScale(Number(e.target.value))}
                    className="w-full sm:w-28 h-1 bg-slate-700 rounded-lg appearance-none cursor-pointer accent-emerald-500"
                  />
                  <div className="flex gap-1 flex-wrap justify-center sm:justify-start">
                    {[
                      { l: 'Micro', v: 0.35 },
                      { l: 'Comp.', v: 0.5 },
                      { l: 'Med.', v: 0.65 },
                      { l: 'Gde.', v: 0.8 },
                      { l: '100%', v: 1.0 }
                    ].map(btn => (
                      <button 
                        key={btn.l}
                        onClick={() => setPhoneScale(btn.v)} 
                        className={`px-2 py-1 rounded-md text-[9px] font-black uppercase tracking-wider transition-all active:scale-95 border ${
                          phoneScale === btn.v 
                            ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/40 shadow-inner font-bold' 
                            : 'bg-slate-800 text-slate-400 hover:text-white border-slate-700/60 font-bold'
                        }`}
                      >
                        {btn.l}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* GRID DE MINIATURAS */}
        <div className="flex-1 overflow-y-auto p-8 bg-[#0B1120]">
          <div className="flex flex-wrap gap-8 justify-center">
            {[...globalUsers]
              .filter(u => u.is_active_employee !== false)
              .sort((a, b) => {
                if (a.jerarquiaLlaves === b.jerarquiaLlaves) {
                  return a.name.localeCompare(b.name);
                }
                return a.jerarquiaLlaves - b.jerarquiaLlaves;
              })
              .map(user => (
              <div key={`${user.id}-${resetKey}`} className="flex flex-col items-center transition-all duration-300">
                <div 
                  className="bg-slate-800 px-3 py-1.5 rounded-t-2xl border-t border-l border-r border-slate-700 text-center z-10 transition-all duration-300 truncate"
                  style={{ width: `${400 * phoneScale}px` }}
                >
                  <p 
                    className="text-emerald-400 font-bold truncate" 
                    style={{ fontSize: `${Math.max(10, Math.min(14, 14 * (phoneScale / 0.65)))}px` }}
                  >
                    {user.name}{getUserKeysIcon(user.id)}
                  </p>
                  <p 
                    className="text-slate-500 uppercase font-black tracking-widest truncate" 
                    style={{ fontSize: `${Math.max(8, Math.min(10, 10 * (phoneScale / 0.65)))}px` }}
                  >
                    {user.role}
                  </p>
                </div>
                {/* Contenedor wrapper con dimensiones exactas que enmascara el escalado */}
                <div 
                  className="bg-slate-800 rounded-b-2xl rounded-t-none border border-slate-700 shadow-2xl overflow-hidden relative transition-all duration-300"
                  style={{ width: `${400 * phoneScale}px`, height: `${(850 * phoneScale) + 38}px` }}
                >
                  <PhoneErrorBoundary>
                    <MiniaturaCelular user={user} scale={phoneScale} />
                  </PhoneErrorBoundary>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* PANEL DERECHO: TIMELINE CRONOLÓGICO */}
      <div className="w-full xl:w-[450px] bg-slate-800 border-l border-slate-700 flex flex-col">
        <div className="p-6 border-b border-slate-700 bg-slate-800/80 backdrop-blur-sm z-10">
           <h3 className="text-xl font-black text-white flex items-center gap-2">
             ⏱️ Bitácora de la Matrix
           </h3>
           <p className="text-slate-400 text-xs mt-1">Línea de tiempo cronológica ({parseMinsToTime(globalSimTime)})</p>
        </div>
        
        <div className="flex-1 overflow-y-auto p-6 bg-slate-900/50">
          <div className="flex flex-col gap-4 relative">
            {/* Línea conectora */}
            <div className="absolute left-[21px] top-4 bottom-4 w-px bg-slate-700"></div>

            {!matrixTimeline || matrixTimeline.length === 0 ? (
              <div className="text-center py-10 text-slate-500 text-sm animate-pulse">
                La bitácora está vacía. Avanza el tiempo o interactúa con los celulares.
              </div>
            ) : (
              [...matrixTimeline]
                .sort((a, b) => b.simTime - a.simTime)
                .map((event) => {
                  let icon = '🔔';
                  let colorClass = 'bg-indigo-500/20 text-indigo-400 border-indigo-500/30';
                  
                  if (event.type === 'success') { icon = '✅'; colorClass = 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'; }
                  if (event.type === 'warning') { icon = '⚠️'; colorClass = 'bg-amber-500/20 text-amber-400 border-amber-500/30'; }
                  if (event.type === 'error') { icon = '🛑'; colorClass = 'bg-rose-500/20 text-rose-400 border-rose-500/30'; }
                  if (event.type === 'system') { icon = '⚙️'; colorClass = 'bg-slate-500/20 text-slate-300 border-slate-500/30'; }

                  const actor = (globalUsers || []).find(u => Number(u.id) === Number(event.actorId));

                return (
                  <div key={event.id} className="relative flex gap-4 animate-fade-in-up items-start">
                    <div className={`w-11 h-11 rounded-full flex items-center justify-center border-2 border-slate-800 shadow-xl shrink-0 z-10 bg-slate-800 text-sm`}>
                      {icon}
                    </div>
                    <div className={`flex-1 rounded-2xl p-4 border shadow-sm ${colorClass}`}>
                      <div className="flex justify-between items-start mb-1">
                        <span className="font-bold text-sm text-white">{event.title}</span>
                        <span className="text-[10px] font-black tracking-wider opacity-80">{event.timeStr}</span>
                      </div>
                      <p className="text-xs opacity-90 leading-relaxed mb-2">{event.description}</p>
                      {actor && (
                        <div className="flex items-center gap-2 mt-2 pt-2 border-t border-white/10">
                          <img src={actor.avatar} className="w-5 h-5 rounded-full" alt={actor.name} />
                          <span className="text-[10px] uppercase font-bold opacity-80">{actor.name} • {actor.role}</span>
                        </div>
                      )}
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
