// @ts-nocheck
import React, { useState, useEffect } from 'react';
import { useAppStore } from '../../store/useAppStore';
import axiosInstance from '../../lib/axios';

// Reloj Normal
import { ClockContext } from '../store/ClockContext';
import { useClockEngine as useClockEngineNormal } from './useClockEngine';
import RelojVisualNormal from './RelojVisual';

// Reloj Clone (V2)
import { ClockContext2 } from '../store/ClockContext2';
import { useClockEngine as useClockEngineClone } from '../reloj2/useClockEngine';
import RelojVisualClone from '../reloj2/RelojVisual';

function MiniaturaCelularNormal({ user, scale }: { user: any; scale: number }) {
  const engine = useClockEngineNormal(user);

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
    <ClockContext.Provider value={engine}>
      <div className="flex flex-col h-full bg-slate-900 select-none w-full">
        {/* Simulation Controls Overlay */}
        <div className="bg-slate-800 border-b border-slate-700/80 p-2 flex justify-between items-center text-xs shrink-0 z-20">
          {/* Offline Simulation Toggle */}
          <button 
            onClick={() => engine.setIsSimulatedOffline(!engine.isSimulatedOffline)}
            className={`px-2 py-0.5 rounded font-black text-[9px] uppercase tracking-wider transition-all active:scale-95 border cursor-pointer select-none ${
              engine.isSimulatedOffline 
                ? 'bg-rose-500/20 text-rose-400 border-rose-500/40 animate-pulse' 
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
              label = '📍 En Tienda (5m)';
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

        <div className="flex-grow overflow-hidden relative">
          <div 
            className="w-[400px] h-[850px] transform origin-top-left pointer-events-auto"
            style={{ transform: `scale(${scale})` }}
          >
            <RelojVisualNormal />
          </div>
        </div>
      </div>
    </ClockContext.Provider>
  );
}

function MiniaturaCelularClone({ user, scale }: { user: any; scale: number }) {
  const engine = useClockEngineClone(user);

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
    <ClockContext2.Provider value={engine}>
      <div className="flex flex-col h-full bg-slate-900 select-none w-full">
        {/* Simulation Controls Overlay */}
        <div className="bg-slate-800 border-b border-slate-700/80 p-2 flex justify-between items-center text-xs shrink-0 z-20">
          {/* Offline Simulation Toggle */}
          <button 
            onClick={() => engine.setIsSimulatedOffline(!engine.isSimulatedOffline)}
            className={`px-2 py-0.5 rounded font-black text-[9px] uppercase tracking-wider transition-all active:scale-95 border cursor-pointer select-none ${
              engine.isSimulatedOffline 
                ? 'bg-rose-500/20 text-rose-400 border-rose-500/40 animate-pulse' 
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
              label = '📍 En Tienda (5m)';
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

        <div className="flex-grow overflow-hidden relative">
          <div 
            className="w-[400px] h-[850px] transform origin-top-left pointer-events-auto"
            style={{ transform: `scale(${scale})` }}
          >
            <RelojVisualClone isMobileFrame={true} />
          </div>
        </div>
      </div>
    </ClockContext2.Provider>
  );
}

export default function PanelSimulador() {
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
    globalClockStates,
    globalSimDay,
    setGlobalSimDay,
    currentTier,
    simulatedTierOverride,
    setSimulatedTierOverride
  } = useAppStore();

  // Estados del Simulador QA Matrix
  const [clockVersion, setClockVersion] = useState<'normal' | 'clone'>('clone');
  const [phoneScale, setPhoneScale] = useState(0.5); // Default a 50% para ver más celulares simultáneamente
  const [searchQuery, setSearchQuery] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [simIntervalMs, setSimIntervalMs] = useState(1000); // Frecuencia por defecto de 1s (1000ms)

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
          
          // Watchdog: Alerta de retraso de apertura a las 8:15 AM (495 minutos)
          if (!hasAlertedStoreDelay && nextTime >= 495 && storeStatus === 'closed') {
             addMatrixEvent(
                'Alerta Crítica: Retraso de Apertura',
                'El reloj cruzó las 8:15 AM y la sucursal aún permanece cerrada físicamente. Se recomienda contactar al Encargado.',
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
      }, simIntervalMs);
    }
    return () => clearInterval(interval);
  }, [globalSimRunning, globalSimSpeed, simIntervalMs, hasAlertedStoreDelay, storeStatus]);

  const toggleStore = async () => {
    const isOpening = storeStatus === 'closed';
    try {
      await axiosInstance.post('/sync/store_log', {
        type: isOpening ? 'open' : 'close',
        date: new Date().toLocaleDateString('sv-SE'),
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
        await resetGlobalSimulation();
        alert('Simulación y registros del día reiniciados con éxito.');
      } catch (err) {
        console.error('Error al reiniciar la simulación:', err);
        alert('Error al conectar con el servidor backend para reiniciar la simulación.');
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

  // Obtener roles únicos de la lista global de usuarios (solo activos)
  const activeGlobalUsers = globalUsers.filter((user: any) => user.is_active_employee !== false);

  const uniqueRoles = Array.from(
    new Set(activeGlobalUsers.map((user: any) => user.role).filter(Boolean))
  );

  // Filtrado de colaboradores
  const filteredUsers = activeGlobalUsers.filter((user: any) => {
    // Filtro de búsqueda por nombre
    const matchesSearch = user.name.toLowerCase().includes(searchQuery.toLowerCase());
    
    // Filtro por Puesto
    const matchesRole = !roleFilter || user.role === roleFilter;
    
    // Filtro por Estado Simulado
    const state = globalClockStates[user.id] || 'inactive';
    const matchesStatus = statusFilter === 'all' || state === statusFilter;

    return matchesSearch && matchesRole && matchesStatus;
  }).sort((a: any, b: any) => {
    if (a.jerarquiaLlaves === b.jerarquiaLlaves) {
      return a.name.localeCompare(b.name);
    }
    return (a.jerarquiaLlaves || 0) - (b.jerarquiaLlaves || 0);
  });

  return (
    <div className="flex flex-col xl:flex-row h-[90vh] bg-slate-900 rounded-3xl overflow-hidden border border-slate-700 shadow-2xl gap-4">
      
      {/* PANEL IZQUIERDO: CONTROLES MATRIX Y CELULARES */}
      <div className="flex-1 flex flex-col min-w-0 border-r border-slate-700">
        
        {/* HEADER MATRIX, TOOLBAR & TIME MACHINE */}
        <div className="bg-slate-800 border-b border-slate-700 p-6 flex flex-col gap-6">
          <div className="flex flex-col lg:flex-row lg:justify-between lg:items-center gap-4">
            <div>
              <h2 className="text-2xl font-black text-emerald-400 flex items-center gap-2">
                <span className="text-3xl">🖥️</span> Matrix QA (Multi-Celular)
              </h2>
              <p className="text-slate-400 text-sm">
                Pruebas simultáneas en tiempo real • Mostrando {filteredUsers.length} de {activeGlobalUsers.length} colaboradores
              </p>
            </div>
            
            <div className="flex flex-wrap gap-3">
              <div className="px-5 py-2.5 rounded-xl font-bold text-sm bg-emerald-500/20 text-emerald-400 border border-emerald-500/35 flex items-center gap-2">
                <span>🟢 BD REAL: POSTGRES</span>
              </div>
              
              <button onClick={handleReset} className="px-5 py-2.5 rounded-xl font-bold text-sm transition-colors bg-slate-700 text-slate-300 border border-slate-600 hover:bg-slate-600">
                🧹 Limpiar y Reiniciar
              </button>
              <button onClick={toggleStore} className={`px-5 py-2.5 rounded-xl font-bold text-sm transition-colors ${storeStatus === 'open' ? 'bg-rose-500/20 text-rose-400 border border-rose-500/50 hover:bg-rose-500/40' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50 hover:bg-emerald-500/40'}`}>
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

          {/* BARRA DE HERRAMIENTAS: CAMBIO DE VERSIÓN, ZOOM Y FILTROS */}
          <div className="bg-slate-900/60 p-4 rounded-2xl border border-slate-700/60 flex flex-wrap gap-4 items-end">
            
            {/* A. CAMBIADOR DE VERSIÓN DEL RELOJ */}
            <div className="flex flex-col gap-1.5">
              <span className="text-slate-400 text-xs font-bold uppercase tracking-wider">Versión del Reloj</span>
              <div className="flex bg-slate-950 p-1 rounded-xl border border-slate-700">
                <button 
                  onClick={() => setClockVersion('normal')}
                  className={`px-3 py-1.5 rounded-lg font-bold text-xs transition-all ${clockVersion === 'normal' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'}`}
                >
                  ⏱️ Normal
                </button>
                <button 
                  onClick={() => setClockVersion('clone')}
                  className={`px-3 py-1.5 rounded-lg font-bold text-xs transition-all ${clockVersion === 'clone' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'}`}
                >
                  👥 Clone (V2)
                </button>
              </div>
            </div>

            {/* B. CONTROL DE ZOOM / ESCALA */}
            <div className="flex flex-col gap-1.5">
              <span className="text-slate-400 text-xs font-bold uppercase tracking-wider">Escala ({Math.round(phoneScale * 100)}%)</span>
              <div className="flex bg-slate-950 p-1 rounded-xl border border-slate-700 gap-0.5">
                {[0.35, 0.5, 0.65, 0.8, 1.0].map((sz) => (
                  <button 
                    key={sz}
                    onClick={() => setPhoneScale(sz)}
                    className={`px-2.5 py-1.5 rounded-lg font-bold text-[10px] transition-all ${phoneScale === sz ? 'bg-emerald-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'}`}
                  >
                    {sz === 0.35 ? 'Micro' : sz === 0.5 ? 'Comp.' : sz === 0.65 ? 'Med.' : sz === 0.8 ? 'Gde.' : '100%'}
                  </button>
                ))}
              </div>
            </div>

            {/* C. BUSCADOR POR NOMBRE */}
            <div className="flex flex-col gap-1.5 flex-1 min-w-[160px]">
              <span className="text-slate-400 text-xs font-bold uppercase tracking-wider">Buscar Colaborador</span>
              <div className="relative">
                <input 
                  type="text"
                  placeholder="Filtrar por nombre..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full bg-slate-950 text-white border border-slate-700 rounded-xl pl-3 pr-8 py-1.5 text-xs outline-none focus:border-indigo-500 placeholder-slate-500"
                />
                {searchQuery && (
                  <button 
                    onClick={() => setSearchQuery('')}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-200 text-xs font-bold"
                  >
                    ✕
                  </button>
                )}
              </div>
            </div>

            {/* D. FILTRO POR PUESTO */}
            <div className="flex flex-col gap-1.5 min-w-[130px]">
              <span className="text-slate-400 text-xs font-bold uppercase tracking-wider">Puesto / Rol</span>
              <select 
                value={roleFilter}
                onChange={(e) => setRoleFilter(e.target.value)}
                className="w-full bg-slate-950 text-white border border-slate-700 rounded-xl px-2 py-1.5 text-xs outline-none focus:border-indigo-500 cursor-pointer"
              >
                <option value="">Todos los puestos</option>
                {uniqueRoles.map((role: string) => (
                  <option key={role} value={role}>{role}</option>
                ))}
              </select>
            </div>

            {/* E. FILTRO POR ESTADO SIMULADO */}
            <div className="flex flex-col gap-1.5 min-w-[130px]">
              <span className="text-slate-400 text-xs font-bold uppercase tracking-wider">Estado en Turno</span>
              <select 
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="w-full bg-slate-950 text-white border border-slate-700 rounded-xl px-2 py-1.5 text-xs outline-none focus:border-indigo-500 cursor-pointer"
              >
                <option value="all">Todos los estados</option>
                <option value="active">🟢 En Turno</option>
                <option value="meal">🟡 En Comida</option>
                <option value="waiting_room">🔵 En Puerta</option>
                <option value="inactive">⚪ Inactivo</option>
              </select>
            </div>

          </div>

          {/* CONTROLES DE LA MÁQUINA DEL TIEMPO */}
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
            
            <div className="flex justify-between items-center mt-2 flex-wrap gap-3">
              <div className="flex flex-wrap gap-2 items-center">
                <button 
                  onClick={() => setGlobalSimRunning(!globalSimRunning)}
                  className={`px-4 py-2 rounded-xl font-bold text-sm transition-all duration-200 active:scale-95 flex items-center gap-1.5 ${
                    globalSimRunning 
                      ? 'bg-rose-500 hover:bg-rose-600 text-white shadow-lg shadow-rose-500/25' 
                      : 'bg-emerald-500 hover:bg-emerald-600 text-white shadow-lg shadow-emerald-500/25'
                  }`}
                >
                  {globalSimRunning ? '⏸️ Pausar Simulación' : '▶️ Auto-Run'}
                </button>
                
                {/* Selector de Salto de Tiempo */}
                <div className="flex items-center gap-1 bg-slate-800 text-xs text-slate-300 border border-slate-600 rounded-xl px-2.5 py-1.5">
                  <span className="text-[10px] font-black uppercase text-slate-500">Salto:</span>
                  <select 
                    value={globalSimSpeed} 
                    onChange={(e) => setGlobalSimSpeed(Number(e.target.value))}
                    className="bg-transparent text-slate-300 outline-none cursor-pointer font-bold"
                  >
                    <option value={1}>+1 min</option>
                    <option value={2}>+2 min</option>
                    <option value={5}>+5 min</option>
                    <option value={10}>+10 min</option>
                    <option value={15}>+15 min</option>
                    <option value={30}>+30 min</option>
                  </select>
                </div>

                {/* Selector de Frecuencia / Intervalo */}
                <div className="flex items-center gap-1 bg-slate-800 text-xs text-slate-300 border border-slate-600 rounded-xl px-2.5 py-1.5">
                  <span className="text-[10px] font-black uppercase text-slate-500">Frecuencia:</span>
                  <select 
                    value={simIntervalMs} 
                    onChange={(e) => setSimIntervalMs(Number(e.target.value))}
                    className="bg-transparent text-slate-300 outline-none cursor-pointer font-bold"
                  >
                    <option value={250}>Cada 0.25s (Rápido)</option>
                    <option value={500}>Cada 0.5s</option>
                    <option value={1000}>Cada 1.0s (Normal)</option>
                    <option value={2000}>Cada 2.0s</option>
                    <option value={3000}>Cada 3.0s (Lento)</option>
                  </select>
                </div>
              </div>
              <span className="text-xs font-semibold text-slate-500 bg-slate-950/40 px-2.5 py-1 rounded-lg border border-slate-800/80">7:30 AM - 7:00 PM</span>
            </div>
          </div>
        </div>

        {/* GRID DE MINIATURAS */}
        <div className="flex-1 overflow-y-auto p-8 bg-[#0B1120]">
          <div className="flex flex-wrap gap-8 justify-center">
            {filteredUsers.length === 0 ? (
              <div className="text-center py-20 text-slate-500 w-full flex flex-col items-center gap-2">
                <span className="text-5xl">🔍</span>
                <p className="font-bold text-lg text-slate-300">No se encontraron colaboradores</p>
                <p className="text-sm text-slate-500">Prueba ajustando los filtros o el buscador.</p>
              </div>
            ) : (
              filteredUsers.map(user => (
                <div key={user.id} className="flex flex-col items-center transition-all duration-300">
                  <div 
                    className="bg-slate-800 px-3 py-1.5 rounded-t-2xl border-t border-l border-r border-slate-700 text-center z-10 transition-all duration-300 truncate"
                    style={{ width: `${400 * phoneScale}px` }}
                  >
                    <p 
                      className="text-emerald-400 font-bold truncate" 
                      style={{ fontSize: `${Math.max(10, Math.min(14, 14 * (phoneScale / 0.65)))}px` }}
                    >
                      {user.name}
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
                     {clockVersion === 'clone' ? (
                       <MiniaturaCelularClone user={user} scale={phoneScale} />
                     ) : (
                       <MiniaturaCelularNormal user={user} scale={phoneScale} />
                     )}
                  </div>
                </div>
              ))
            )}
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
