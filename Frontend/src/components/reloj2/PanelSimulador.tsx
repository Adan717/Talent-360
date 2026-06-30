// @ts-nocheck
import React, { useState, useEffect } from 'react';
import { useAppStore } from '../../store/useAppStore';
import { ClockContext2 } from '../store/ClockContext2';
import { useClockEngine } from './useClockEngine';
import RelojVisual from './RelojVisual';
import axiosInstance from '../../lib/axios';

function MiniaturaCelular({ user }: { user: any }) {
  const engine = useClockEngine(user);

  // Sincronizar estado local simulado hacia la store global para la tabla dinámica
  const { setGlobalClockState, setGlobalCheckInTime, setGlobalArrivalTime } = useAppStore();
  
  useEffect(() => {
    if (user?.id) {
      setGlobalClockState(user.id, engine.clockState);
    }
  }, [engine.clockState, user?.id]);

  useEffect(() => {
    if (user?.id && engine.checkInTimes[user.id] !== undefined) {
      setGlobalCheckInTime(user.id, engine.checkInTimes[user.id]);
    }
  }, [engine.checkInTimes, user?.id]);

  useEffect(() => {
    if (user?.id && engine.arrivalTimes[user.id] !== undefined) {
      setGlobalArrivalTime(user.id, engine.arrivalTimes[user.id]);
    }
  }, [engine.arrivalTimes, user?.id]);

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

        {/* GPS Simulation Quick-Toggle Badge */}
        {(() => {
          let label = '';
          let colorClass = '';
          if (engine.gpsStatus === 'error') {
            label = '❌ Falla GPS';
            colorClass = 'bg-rose-500/20 text-rose-400 border-rose-500/40';
          } else if (engine.isWithinPerimeter) {
            label = '📍 En Sucursal (5m)';
            colorClass = 'bg-emerald-500/20 text-emerald-400 border-emerald-500/35';
          } else {
            label = '🏠 En Casa (200m)';
            colorClass = 'bg-amber-500/20 text-amber-400 border-amber-500/35';
          }
          
          const handleGpsToggle = () => {
            if (engine.gpsStatus === 'error') {
              engine.setGpsStatus('success');
              engine.setGpsCoordinates({ latitude: 19.4326, longitude: -99.1332 });
            } else if (engine.isWithinPerimeter) {
              engine.setGpsStatus('success');
              engine.setGpsCoordinates({ latitude: 19.4344, longitude: -99.1332 });
            } else {
              engine.setGpsStatus('error');
            }
          };

          return (
            <button
              onClick={handleGpsToggle}
              className={`px-2 py-0.5 rounded font-black text-[9px] uppercase tracking-wider transition-all active:scale-95 border cursor-pointer select-none ${colorClass}`}
            >
              {label}
            </button>
          );
        })()}
      </div>

      {/* Scaled Cellphone frame */}
      <div className="flex-grow overflow-hidden relative">
        <ClockContext2.Provider value={engine}>
          <div className="w-[400px] h-[850px] transform scale-[0.65] origin-top-left pointer-events-auto">
            <RelojVisual isMobileFrame={true} />
          </div>
        </ClockContext2.Provider>
      </div>
    </div>
  );
}

export default function PanelSimulador() {
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
    setGlobalSimDay
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
     // Polling de 5 segundos para mantener la QA Matrix sincronizada con los fichajes reales del backend
     const interval = setInterval(() => {
         fetchState();
     }, 5000);
     return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    if (globalUsers.length === 0) {
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
              <div key={`${user.id}-${resetKey}`} className="flex flex-col items-center">
                <div className="bg-slate-800 px-4 py-2 rounded-t-2xl border-t border-l border-r border-slate-700 w-[260px] text-center z-10">
                  <p className="text-emerald-400 font-bold text-sm truncate">{user.name}{getUserKeysIcon(user.id)}</p>
                  <p className="text-slate-500 text-[10px] uppercase font-black tracking-widest">{user.role}</p>
                </div>
                {/* Contenedor wrapper con dimensiones exactas que enmascara el escalado */}
                <div className="bg-slate-800 rounded-b-2xl rounded-t-none border border-slate-700 w-[260px] h-[588px] shadow-2xl overflow-hidden relative">
                   <MiniaturaCelular user={user} />
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
              matrixTimeline.map((event) => {
                let icon = '🔔';
                let colorClass = 'bg-indigo-500/20 text-indigo-400 border-indigo-500/30';
                
                if (event.type === 'success') { icon = '✅'; colorClass = 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'; }
                if (event.type === 'warning') { icon = '⚠️'; colorClass = 'bg-amber-500/20 text-amber-400 border-amber-500/30'; }
                if (event.type === 'error') { icon = '🛑'; colorClass = 'bg-rose-500/20 text-rose-400 border-rose-500/30'; }
                if (event.type === 'system') { icon = '⚙️'; colorClass = 'bg-slate-500/20 text-slate-300 border-slate-500/30'; }

                const actor = globalUsers.find(u => u.id === event.actorId);

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
