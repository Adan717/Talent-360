import React, { useState, useEffect } from 'react';
import { 
  Fingerprint, Clock, Armchair, Utensils, AlertTriangle, 
  Check, Lock, Star, GraduationCap, Briefcase, Settings, 
  MapPin, Camera, Send, ShieldCheck
} from 'lucide-react';

interface RelojSimuladoLandingProps {
  tier: 'free' | 'pro';
  setTier?: (tier: 'free' | 'pro') => void;
}

export const RelojSimuladoLanding: React.FC<RelojSimuladoLandingProps> = ({
  tier,
  setTier
}) => {
  const [clockState, setClockState] = useState<'inactive' | 'active' | 'break' | 'meal' | 'finished'>('inactive');
  const [phoneTab, setPhoneTab] = useState<'reloj' | 'tareas' | 'academia' | 'herramientas'>('reloj');
  const [simulatedTime, setSimulatedTime] = useState('09:00:00 AM');
  const [simulatedMins, setSimulatedMins] = useState(540); // 09:00 AM
  const [isDark, setIsDark] = useState(false);
  const [simTask1Done, setSimTask1Done] = useState(true);
  const [simTask2Done, setSimTask2Done] = useState(false);

  // Simular avance del tiempo en el checador
  useEffect(() => {
    let interval: any;
    if (clockState === 'active') {
      interval = setInterval(() => {
        setSimulatedMins(prev => {
          const next = prev + 1;
          const hrs = Math.floor(next / 60);
          const mins = next % 60;
          const displayHrs = hrs > 12 ? hrs - 12 : hrs === 0 ? 12 : hrs;
          const ampm = hrs >= 12 ? 'PM' : 'AM';
          setSimulatedTime(`${displayHrs.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:00 ${ampm}`);
          return next;
        });
      }, 3000); // 1 minuto simulado cada 3 segundos
    }
    return () => clearInterval(interval);
  }, [clockState]);

  const handleDialClick = () => {
    const stateStr = clockState as string;
    if (stateStr === 'inactive') {
      setClockState('active');
      setSimulatedMins(545); // 09:05 AM
      setSimulatedTime('09:05:00 AM');
    } else if (stateStr === 'active') {
      setClockState('break');
      setSimulatedMins(720); // 12:00 PM
      setSimulatedTime('12:00:00 PM');
    } else if (stateStr === 'break') {
      setClockState('active');
      setSimulatedMins(735); // 12:15 PM
      setSimulatedTime('12:15:00 PM');
    } else if (stateStr === 'active' || stateStr === 'finished') {
      setClockState('meal');
      setSimulatedMins(840); // 02:00 PM
      setSimulatedTime('02:00:00 PM');
    } else if (stateStr === 'meal') {
      setClockState('active');
      setSimulatedMins(885); // 02:45 PM
      setSimulatedTime('02:45:00 PM');
    }
  };

  const handleClockOut = () => {
    setClockState('finished');
    setSimulatedMins(1080); // 06:00 PM
    setSimulatedTime('06:00:00 PM');
  };

  const handleReset = () => {
    setClockState('inactive');
    setSimulatedMins(540); // 09:00 AM
    setSimulatedTime('09:00:00 AM');
  };

  // Cálculos para la barra de progreso
  const startMins = 540; // 09:00 AM
  const endMins = 1080;  // 06:00 PM
  const totalMins = endMins - startMins;
  
  const getProgressPercent = () => {
    if (clockState === 'inactive') return 0;
    if (clockState === 'finished') return 100;
    const currentDiff = Math.max(0, simulatedMins - startMins);
    return Math.min(100, Math.round((currentDiff / totalMins) * 100));
  };

  const progressPercent = getProgressPercent();

  return (
    <div className={`w-full h-full flex flex-col font-sans transition-colors duration-300 ${isDark ? 'bg-slate-950 text-slate-100' : 'bg-slate-50 text-slate-800'}`}>
      
      {/* Barra superior de pestañas (Estilo RelojChecador2) */}
      <div className={`px-3 pt-3 pb-2 border-b flex justify-between items-center ${isDark ? 'bg-slate-900 border-slate-850' : 'bg-white border-slate-200'} shadow-sm`}>
        <div className="flex gap-1 overflow-x-auto scrollbar-none w-full max-w-[80%]">
          {[
            { id: 'reloj', label: 'Reloj', icon: Clock },
            { id: 'tareas', label: 'Tareas', icon: Briefcase },
            { id: 'academia', label: 'Academia', icon: GraduationCap },
            { id: 'herramientas', label: 'Menú', icon: Settings },
          ].map(t => {
            const Icon = t.icon;
            const isActive = phoneTab === t.id;
            return (
              <button
                key={t.id}
                onClick={() => setPhoneTab(t.id as any)}
                className={`flex items-center gap-1 px-1.5 py-1 rounded text-[9px] font-black uppercase tracking-wider transition-all duration-300 ${
                  isActive 
                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/10' 
                    : (isDark ? 'text-slate-400 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')
                }`}
              >
                <Icon size={10} />
                <span>{t.label}</span>
              </button>
            );
          })}
        </div>

        {/* Botón de Modo Oscuro */}
        <button 
          onClick={() => setIsDark(!isDark)}
          className={`p-1.5 rounded-lg border transition-all ${
            isDark ? 'border-slate-800 bg-slate-800 text-amber-400' : 'border-slate-200 bg-slate-100 text-slate-600'
          }`}
        >
          {isDark ? '☀️' : '🌙'}
        </button>
      </div>

      {/* Contenido Dinámico de la Pestaña */}
      <div className="flex-grow overflow-y-auto p-4 custom-scrollbar">
        
        {phoneTab === 'reloj' && (
          <div className="space-y-4">
            
            {/* Credencial del Empleado */}
            <div className={`p-3 rounded-2xl border transition-all ${
              isDark ? 'bg-slate-900/60 border-slate-800' : 'bg-white border-slate-200'
            } shadow-sm flex items-center gap-3`}>
              <div className="relative">
                <img 
                  src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=80&h=80&fit=crop&crop=faces" 
                  alt="Avatar" 
                  className="w-10 h-10 rounded-full object-cover border-2 border-emerald-500 shadow"
                />
                <span className="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-white rounded-full"></span>
              </div>
              <div className="flex-grow text-left">
                <h4 className="text-xs font-bold leading-tight">Francisco Vega</h4>
                <p className="text-[9px] text-slate-400 font-medium">Colaborador • Decorarte 365</p>
              </div>
              <span className="px-2 py-0.5 text-[8px] font-black uppercase rounded bg-indigo-500/10 text-indigo-500 border border-indigo-500/20">
                {tier.toUpperCase()}
              </span>
            </div>

            {/* Cronómetro/Hora */}
            <div className="text-center py-2">
              <div className="text-2xl font-black tracking-tight font-mono leading-none">
                {simulatedTime}
              </div>
              <p className="text-[9px] text-slate-400 uppercase tracking-widest mt-1">
                {clockState === 'inactive' && 'Jornada Sin Iniciar'}
                {clockState === 'active' && '⏱️ Registrando Jornada'}
                {clockState === 'break' && '💤 En Descanso Corto'}
                {clockState === 'meal' && '🍲 Horario de Comida'}
                {clockState === 'finished' && '🏁 Jornada Finalizada'}
              </p>
            </div>

            {/* Barra Cronológica Proporcional Ampliada */}
            <div className={`p-4 rounded-3xl border transition-all ${
              isDark ? 'bg-slate-900/40 border-slate-800' : 'bg-white border-slate-200'
            } shadow-inner`}>
              <div className="flex justify-between items-center text-[8px] font-bold text-slate-400 uppercase tracking-wider mb-2">
                <span>Línea Cronológica</span>
                <span className="text-indigo-500 font-black">{progressPercent}%</span>
              </div>

              {/* Contenedor de la barra de progreso */}
              <div className="relative h-6 w-full bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden shadow-inner mb-2 flex items-center justify-between px-3">
                <div 
                  className="absolute inset-y-0 left-0 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 transition-all duration-500 ease-out"
                  style={{ width: `${progressPercent}%` }}
                ></div>

                {/* Marcadores e Hitos proporcionales */}
                <div className="absolute inset-0 flex justify-between items-center px-4 pointer-events-none z-10 text-[9px] font-mono font-bold text-slate-500 dark:text-slate-400">
                  <span>09:00</span>
                  <span>12:00</span>
                  <span>14:00</span>
                  <span>18:00</span>
                </div>
              </div>

              {/* Leyendas explicativas */}
              <div className="grid grid-cols-4 gap-1 text-center text-[7px] font-black uppercase text-slate-400 mt-1">
                <span className={clockState !== 'inactive' ? 'text-indigo-500' : ''}>Entrada</span>
                <span className={['break', 'meal', 'finished'].includes(clockState) ? 'text-purple-500' : ''}>Descanso</span>
                <span className={['meal', 'finished'].includes(clockState) ? 'text-pink-500' : ''}>Comida</span>
                <span className={clockState === 'finished' ? 'text-emerald-500' : ''}>Salida</span>
              </div>
            </div>

            {/* Dial Principal Interactivo */}
            <div className="flex flex-col items-center justify-center py-4">
              <button
                onClick={handleDialClick}
                disabled={clockState === 'finished'}
                className={`w-28 h-28 rounded-full flex flex-col items-center justify-center transition-all duration-300 transform active:scale-95 shadow-xl ${
                  clockState === 'inactive' && 'bg-gradient-to-tr from-slate-700 to-slate-800 hover:from-slate-600 hover:to-slate-700 text-white shadow-slate-900/20'
                } ${
                  clockState === 'active' && 'bg-gradient-to-tr from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-white shadow-emerald-500/20 animate-pulse'
                } ${
                  clockState === 'break' && 'bg-gradient-to-tr from-purple-500 to-purple-600 hover:from-purple-400 hover:to-purple-500 text-white shadow-purple-500/20 animate-pulse'
                } ${
                  clockState === 'meal' && 'bg-gradient-to-tr from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-white shadow-amber-500/20 animate-pulse'
                } ${
                  clockState === 'finished' && 'bg-slate-300 dark:bg-slate-800 text-slate-400 dark:text-slate-600 cursor-not-allowed shadow-none'
                }`}
              >
                {clockState === 'inactive' && <Fingerprint size={38} className="stroke-[2]" />}
                {clockState === 'active' && <Fingerprint size={38} className="stroke-[2.5]" />}
                {clockState === 'break' && <Armchair size={38} />}
                {clockState === 'meal' && <Utensils size={38} />}
                {clockState === 'finished' && <Check size={38} />}

                <span className="text-[8px] font-black uppercase tracking-widest mt-2">
                  {clockState === 'inactive' && 'Entrada'}
                  {clockState === 'active' && 'Fichar'}
                  {clockState === 'break' && 'Volver'}
                  {clockState === 'meal' && 'Volver'}
                  {clockState === 'finished' && 'Terminado'}
                </span>
              </button>

              {/* Botón secundario para salida */}
              {clockState === 'active' && (
                <button
                  onClick={handleClockOut}
                  className="mt-3 px-4 py-1.5 bg-rose-600 hover:bg-rose-500 text-white text-[9px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-rose-900/10"
                >
                  Fichar Salida
                </button>
              )}

              {/* Reset de simulación */}
              {clockState === 'finished' && (
                <button
                  onClick={handleReset}
                  className="mt-3 px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white text-[9px] font-black uppercase tracking-wider rounded-xl transition-all shadow-md shadow-indigo-900/10"
                >
                  Reiniciar Simulación
                </button>
              )}
            </div>

            {/* Validaciones de Seguridad (GPS, Selfie) */}
            <div className={`p-3 rounded-2xl border transition-all ${
              isDark ? 'bg-slate-900/30 border-slate-800/80' : 'bg-slate-100/50 border-slate-200'
            } text-left`}>
              <h5 className="text-[9px] font-black uppercase text-slate-400 tracking-wider mb-2">Validaciones del Fichaje</h5>
              
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-[10px] font-bold flex items-center gap-1.5">
                    <MapPin size={12} className={tier === 'pro' ? 'text-indigo-500' : 'text-slate-400'} />
                    Cercanía GPS (Geocerca)
                  </span>
                  {tier === 'pro' ? (
                    <span className="text-[9px] text-emerald-500 font-bold flex items-center gap-0.5">
                      <Check size={10} className="stroke-[3]" /> Activo
                    </span>
                  ) : (
                    <span className="text-[9px] text-slate-400 font-bold flex items-center gap-0.5">
                      <Lock size={10} /> Plan PRO
                    </span>
                  )}
                </div>

                <div className="flex items-center justify-between">
                  <span className="text-[10px] font-bold flex items-center gap-1.5">
                    <Camera size={12} className={tier === 'pro' ? 'text-indigo-500' : 'text-slate-400'} />
                    Foto Selfie (Fichaje Seguro)
                  </span>
                  {tier === 'pro' ? (
                    <span className="text-[9px] text-emerald-500 font-bold flex items-center gap-0.5">
                      <Check size={10} className="stroke-[3]" /> Obligatorio
                    </span>
                  ) : (
                    <span className="text-[9px] text-slate-400 font-bold flex items-center gap-0.5">
                      <Lock size={10} /> Plan PRO
                    </span>
                  )}
                </div>
              </div>
            </div>

          </div>
        )}

        {phoneTab === 'tareas' && (
          <div className="space-y-3 text-left">
            <div className="flex justify-between items-center border-b pb-2 mb-2 dark:border-slate-800">
              <h4 className="text-[11px] font-black uppercase text-indigo-500 tracking-wider">Tablero de Tareas</h4>
              <span className="text-[8px] font-bold px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-500">1 Pendiente</span>
            </div>

            {/* Listado de tareas simulado */}
            <div className="space-y-2">
              <div className={`p-3 rounded-xl border flex items-center justify-between ${
                isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
              }`}>
                <div>
                  <h5 className="text-[10px] font-bold">Limpieza y sanitización de barra</h5>
                  <p className="text-[8px] text-slate-400 mt-0.5">Prioridad Alta • 10 pts</p>
                </div>
                <input 
                  type="checkbox" 
                  checked={simTask1Done} 
                  onChange={() => setSimTask1Done(!simTask1Done)}
                  className="rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                />
              </div>

              <div className={`p-3 rounded-xl border flex items-center justify-between ${
                isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
              }`}>
                <div>
                  <h5 className="text-[10px] font-bold">Arqueo de caja y terminales</h5>
                  <p className="text-[8px] text-slate-400 mt-0.5">Requiere foto de evidencia • 20 pts</p>
                </div>
                <input 
                  type="checkbox" 
                  checked={simTask2Done} 
                  onChange={() => setSimTask2Done(!simTask2Done)}
                  className="rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4"
                />
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
          <div className="space-y-3 text-left">
            <div className="flex justify-between items-center border-b pb-2 mb-2 dark:border-slate-800">
              <h4 className="text-[11px] font-black uppercase text-indigo-500 tracking-wider">Academia de Capacitación</h4>
              <span className="text-[8px] font-bold px-1.5 py-0.5 rounded bg-indigo-500/10 text-indigo-500">Curso Activo</span>
            </div>

            <div className={`p-3 rounded-xl border ${
              isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
            }`}>
              <span className="text-[8px] font-black uppercase tracking-wider text-amber-500">Módulo 1</span>
              <h5 className="text-[10px] font-bold mt-0.5">Inducción y Atención al Cliente Premium</h5>
              <div className="w-full bg-slate-200 dark:bg-slate-800 h-1 rounded-full mt-2 overflow-hidden">
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
          <div className="space-y-3 text-left">
            <h4 className="text-[11px] font-black uppercase text-indigo-500 tracking-wider border-b pb-2 dark:border-slate-800">Caja de Herramientas</h4>
            
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
                    } ${isLocked ? 'opacity-60' : 'hover:scale-[1.02] cursor-pointer'}`}
                  >
                    <div className="flex justify-between items-start">
                      <div className={`p-1.5 rounded-lg ${isDark ? 'bg-slate-800' : 'bg-slate-100'} text-indigo-500`}>
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

      {/* iOS Home Indicator Bar en la base del frame */}
      <div className={`py-1.5 border-t text-center text-[7.5px] font-bold text-slate-400 uppercase tracking-widest ${
        isDark ? 'bg-slate-950 border-slate-900' : 'bg-slate-100 border-slate-200'
      }`}>
        Simulador Móvil Talent 360
      </div>

    </div>
  );
};
