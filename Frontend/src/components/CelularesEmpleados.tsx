// @ts-nocheck
import React from 'react';

export const CelularesEmpleados = ({ 
  globalUsers, 
  globalClockStates, 
  simTimeMinutes, 
  currentDay,
  storeStatus,
  shiftConfigs,
  contingencyUsed,
  onCheckIn,
  onCheckOut
}: any) => {

  const formatTime = (totalMins: number) => {
    const h = Math.floor(totalMins / 60);
    const m = totalMins % 60;
    const isPM = h >= 12;
    const h12 = h % 12 || 12;
    return `${h12.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')} ${isPM ? 'PM' : 'AM'}`;
  };

  const parseTimeToMins = (timeStr: string) => {
    if (!timeStr) return 0;
    const [h, m] = timeStr.split(':');
    return parseInt(h) * 60 + parseInt(m);
  };

  const validUsers = globalUsers.filter((u: any) => u.role !== 'Admin' && u.role !== 'CEO' && u.role !== 'Administrador');

  if (!globalUsers || globalUsers.length === 0) {
    return <div className="text-white text-2xl p-10">Cargando usuarios... (0 encontrados)</div>;
  }

  if (validUsers.length === 0) {
    return <div className="text-white text-2xl p-10">No hay empleados para mostrar (Total usuarios: {globalUsers.length})</div>;
  }

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 p-6">
      {validUsers.map((u: any) => {
        const config = shiftConfigs[u.id] || { start: '08:00', end: '18:00', restDay: 'Domingo' };
        const state = globalClockStates[u.id] || 'inactive';
        
        let statusBadge = '';
        let statusColor = '';
        if (config.restDay === currentDay) {
          statusBadge = 'Día Descanso';
          statusColor = 'bg-slate-300 text-slate-600';
        } else if (state === 'active') {
          statusBadge = 'En Turno';
          statusColor = 'bg-emerald-100 text-emerald-700';
        } else if (state === 'meal') {
          statusBadge = 'En Comida';
          statusColor = 'bg-amber-100 text-amber-700';
        } else if (state === 'waiting_room') {
          statusBadge = 'En Puerta';
          statusColor = 'bg-blue-100 text-blue-700';
        } else {
          statusBadge = 'Inactivo';
          statusColor = 'bg-slate-100 text-slate-500';
        }

        return (
          <div key={u.id} className="bg-slate-900 border-[8px] border-slate-800 rounded-[2.5rem] w-full max-w-[320px] aspect-[9/19] flex flex-col overflow-hidden relative shadow-2xl mx-auto ring-4 ring-slate-900/50">
            {/* Notch and Status Bar */}
            <div className="absolute top-0 w-full h-7 bg-transparent z-20 flex justify-center">
              <div className="w-1/3 h-5 bg-black rounded-b-xl"></div>
            </div>
            
            {/* Phone Screen */}
            <div className="flex-1 bg-white flex flex-col relative pt-8">
              
              <div className="px-4 pb-4 border-b border-slate-100 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-xl overflow-hidden">
                    {u.avatar ? <img src={u.avatar} alt={u.name} /> : '👤'}
                  </div>
                  <div>
                    <p className="font-bold text-slate-800 text-sm leading-tight">{u.name}</p>
                    <p className="text-[10px] text-slate-500">{u.role}</p>
                  </div>
                </div>
                <div className={`px-2 py-1 rounded-full text-[9px] font-bold uppercase tracking-wider ${statusColor}`}>
                  {statusBadge}
                </div>
              </div>

              <div className="flex-1 overflow-y-auto p-4 flex flex-col">
                <div className="bg-slate-50 rounded-2xl p-4 flex flex-col items-center justify-center mb-6">
                  <p className="text-slate-500 font-medium text-xs mb-1 uppercase tracking-widest">{currentDay}</p>
                  <p className="text-4xl font-black text-slate-800 tracking-tighter">{formatTime(simTimeMinutes)}</p>
                </div>

                <div className="grid grid-cols-2 gap-3 mb-6">
                  <div className="bg-white border border-slate-200 rounded-xl p-3 text-center">
                    <p className="text-[10px] text-slate-400 font-bold uppercase mb-1">Entrada</p>
                    <p className="font-bold text-slate-700">{config.start}</p>
                  </div>
                  <div className="bg-white border border-slate-200 rounded-xl p-3 text-center">
                    <p className="text-[10px] text-slate-400 font-bold uppercase mb-1">Salida</p>
                    <p className="font-bold text-slate-700">{config.end}</p>
                  </div>
                </div>

                <div className="flex-1 flex items-center justify-center">
                   {config.restDay === currentDay ? (
                     <div className="text-center p-4">
                       <span className="text-5xl block mb-2">🌴</span>
                       <p className="font-bold text-slate-400">Día de Descanso</p>
                     </div>
                   ) : storeStatus === 'closed' ? (
                     <div className="text-center p-4">
                       <span className="text-5xl block mb-2">🏪</span>
                       <p className="font-bold text-slate-400">Tienda Cerrada</p>
                     </div>
                   ) : state === 'inactive' ? (
                     <button onClick={() => onCheckIn(u.id)} className="w-full h-32 rounded-3xl bg-indigo-500 text-white font-bold text-xl shadow-lg shadow-indigo-500/30 active:scale-95 transition-transform flex flex-col items-center justify-center gap-2">
                       <span className="text-3xl">👉</span>
                       Registrar Entrada
                     </button>
                   ) : state === 'waiting_room' ? (
                     <div className="text-center p-4">
                       <div className="w-16 h-16 border-4 border-indigo-200 border-t-indigo-500 rounded-full animate-spin mx-auto mb-4"></div>
                       <p className="font-bold text-indigo-900">Esperando Apertura...</p>
                     </div>
                   ) : (
                     <button onClick={() => onCheckOut(u.id)} className="w-full h-16 rounded-2xl bg-rose-500 text-white font-bold text-lg shadow-lg shadow-rose-500/30 active:scale-95 transition-transform">
                       Marcar Salida
                     </button>
                   )}
                </div>
              </div>

            </div>
          </div>
        );
      })}
    </div>
  );
};
