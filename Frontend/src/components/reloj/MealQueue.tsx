import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Utensils, Clock, Users, CheckCircle, Loader2, X, Hourglass } from 'lucide-react';
import axiosInstance from '../../lib/axios';

// §24 (estado #16b de docs/Logica Dial.md): "Apartar Turno" — cola SECUENCIAL de reserva de comida.
// A diferencia de MealReservation.tsx (selección libre, cualquiera reserva cualquier slot), aquí los
// colaboradores eligen UNO A UNO en un orden (por llegada o aleatorio, lo decide el backend). El dialer
// de cada quien solo puede elegir cuando le toca (current_turn_employee_id). Convive con el modo libre:
// se abre este componente solo cuando clockOpConfig.meal_reservation_mode === 'queue'.
//
// Contrato backend §24 (ya implementado, tests verdes):
//   GET  /meal-reservations/queue?date=YYYY-MM-DD
//        → { mode, order_by, current_turn_employee_id, queue: [{ employee_id, status, slot_start?, slot_end? }] }
//   POST /meal-reservations/queue/pick { date, slot_start, slot_end }
//   GET  /meal-reservations/slots?date=YYYY-MM-DD  (mismos slots que el modo libre, para elegir)
//   Evento en vivo: MealQueueTurnChanged (canal tenant.{id}.clock). Aquí usamos polling cada 4s como
//   fallback simple mientras el modal está abierto — el backend dejó el WS como opcional.

interface QueueEntry {
  employee_id: number;
  status: 'done' | 'choosing' | 'waiting';
  slot_start?: string;
  slot_end?: string;
}

interface MealSlot {
  slot_start: string;
  slot_end: string;
  available: number;
  is_full: boolean;
  same_role_blocked: boolean;
  is_my_reservation: boolean;
}

interface MealQueueProps {
  currentUserId: number;
  globalUsers: any[];
  onClose: () => void;
}

const POLL_MS = 4000;

export default function MealQueue({ currentUserId, globalUsers, onClose }: MealQueueProps) {
  const [queue, setQueue] = useState<QueueEntry[]>([]);
  const [currentTurnId, setCurrentTurnId] = useState<number | null>(null);
  const [slots, setSlots] = useState<MealSlot[]>([]);
  const [loading, setLoading] = useState(true);
  const [picking, setPicking] = useState(false);
  const [error, setError] = useState('');
  const today = new Date().toISOString().split('T')[0];
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const nameOf = (id: number) => globalUsers.find((u: any) => Number(u.id) === Number(id))?.name || `Empleado ${id}`;

  const fetchQueue = useCallback(async () => {
    try {
      const [qRes, sRes] = await Promise.all([
        axiosInstance.get('/meal-reservations/queue', { params: { date: today } }),
        axiosInstance.get('/meal-reservations/slots', { params: { date: today } }),
      ]);
      const q = qRes.data || {};
      setQueue(q.queue || []);
      setCurrentTurnId(q.current_turn_employee_id ?? null);
      setSlots(sRes.data?.slots || []);
      setError('');
    } catch (e: any) {
      setError(e?.response?.data?.message || 'No se pudo cargar la cola de comida.');
    } finally {
      setLoading(false);
    }
  }, [today]);

  useEffect(() => {
    fetchQueue();
    pollRef.current = setInterval(fetchQueue, POLL_MS);
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, [fetchQueue]);

  const isMyTurn = currentTurnId !== null && Number(currentTurnId) === Number(currentUserId);
  const myEntry = queue.find((q) => Number(q.employee_id) === Number(currentUserId));
  const myPosition = queue.findIndex((q) => Number(q.employee_id) === Number(currentUserId));

  const pick = async (slot: MealSlot) => {
    if (!isMyTurn || slot.is_full || slot.same_role_blocked) return;
    setPicking(true);
    setError('');
    try {
      await axiosInstance.post('/meal-reservations/queue/pick', {
        date: today,
        slot_start: slot.slot_start,
        slot_end: slot.slot_end,
      });
      await fetchQueue();
    } catch (e: any) {
      // Si otro tomó el cupo o dejó el piso vacío del mismo puesto, el backend responde 409/422 y el
      // turno NO avanza — el usuario puede intentar otro horario.
      setError(e?.response?.data?.message || 'No se pudo apartar ese horario. Intenta con otro.');
      await fetchQueue();
    } finally {
      setPicking(false);
    }
  };

  const statusBadge = (status: QueueEntry['status']) => {
    if (status === 'done') return <span className="text-[9px] font-bold px-2 py-0.5 rounded-md bg-emerald-100 text-emerald-700">Ya eligió</span>;
    if (status === 'choosing') return <span className="text-[9px] font-bold px-2 py-0.5 rounded-md bg-amber-100 text-amber-700 animate-pulse">Eligiendo…</span>;
    return <span className="text-[9px] font-bold px-2 py-0.5 rounded-md bg-slate-100 text-slate-500">En espera</span>;
  };

  return (
    <div role="dialog" aria-modal="true" aria-labelledby="meal-queue-modal-title" className="absolute inset-0 bg-slate-900/70 backdrop-blur-md z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-3xl p-5 w-full max-w-md shadow-2xl animate-fade-in-up text-slate-800 text-left relative flex flex-col max-h-[85%]">
        <div className="flex justify-between items-center mb-3">
          <h3 id="meal-queue-modal-title" className="font-extrabold text-lg text-slate-800 flex items-center gap-2">
            <Utensils size={18} className="text-amber-500" /> Apartar Turno de Comida
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700" aria-label="Cerrar">
            <X size={20} />
          </button>
        </div>

        {loading ? (
          <div className="flex flex-col items-center justify-center py-12 gap-3 text-slate-400">
            <Loader2 size={28} className="animate-spin" />
            <p className="text-xs font-semibold">Cargando cola…</p>
          </div>
        ) : (
          <>
            {/* Estado del turno del usuario */}
            <div className={`rounded-2xl px-4 py-3 mb-3 border ${
              isMyTurn ? 'bg-amber-50 border-amber-200' : myEntry?.status === 'done' ? 'bg-emerald-50 border-emerald-200' : 'bg-slate-50 border-slate-200'
            }`}>
              {myEntry?.status === 'done' ? (
                <p className="text-sm font-bold text-emerald-700 flex items-center gap-2">
                  <CheckCircle size={16} /> Tu horario: {myEntry.slot_start} – {myEntry.slot_end}
                </p>
              ) : isMyTurn ? (
                <p className="text-sm font-black text-amber-700 flex items-center gap-2">
                  <Clock size={16} /> ¡Es tu turno! Elige tu horario abajo.
                </p>
              ) : myPosition >= 0 ? (
                <p className="text-sm font-bold text-slate-600 flex items-center gap-2">
                  <Hourglass size={16} className="text-slate-400" />
                  Espera tu turno {currentTurnId ? `(eligiendo: ${nameOf(currentTurnId).split(' ')[0]})` : ''}
                </p>
              ) : (
                <p className="text-xs text-slate-500">No estás en la cola de hoy (¿ya fichaste tu entrada?).</p>
              )}
            </div>

            {error && <div className="text-[11px] text-rose-600 font-semibold bg-rose-50 border border-rose-200 rounded-xl px-3 py-2 mb-3">{error}</div>}

            {/* Slots elegibles — solo interactivos cuando es mi turno */}
            {isMyTurn && (
              <div className="mb-3">
                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Elige tu horario</p>
                <div className="grid grid-cols-2 gap-2 overflow-y-auto max-h-[180px] pr-0.5">
                  {slots.map((slot) => {
                    const disabled = slot.is_full || slot.same_role_blocked || picking;
                    return (
                      <button
                        key={`${slot.slot_start}-${slot.slot_end}`}
                        onClick={() => pick(slot)}
                        disabled={disabled}
                        className={`rounded-xl border px-3 py-2 text-left transition-all active:scale-95 ${
                          disabled
                            ? 'bg-slate-50 border-slate-100 opacity-50 cursor-not-allowed'
                            : 'bg-amber-50 border-amber-200 hover:bg-amber-100 hover:border-amber-300'
                        }`}
                      >
                        <span className="block text-xs font-black text-slate-800">{slot.slot_start} – {slot.slot_end}</span>
                        <span className="flex items-center gap-1 text-[9px] font-bold text-slate-500 mt-0.5">
                          <Users size={9} />
                          {slot.same_role_blocked ? 'Mismo puesto ocupado' : slot.is_full ? 'Lleno' : `${slot.available} lugares`}
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Lista de la cola */}
            <div className="flex-grow overflow-y-auto border-t border-slate-100 pt-3">
              <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Orden de la fila</p>
              <div className="space-y-1.5">
                {queue.map((q, i) => (
                  <div
                    key={q.employee_id}
                    className={`flex items-center justify-between px-3 py-2 rounded-xl border ${
                      Number(q.employee_id) === Number(currentUserId) ? 'bg-indigo-50 border-indigo-200' : 'bg-slate-50 border-slate-100'
                    }`}
                  >
                    <span className="flex items-center gap-2 text-xs font-bold text-slate-700 truncate">
                      <span className="text-[10px] font-black text-slate-400 w-4">{i + 1}.</span>
                      {nameOf(q.employee_id)}
                      {q.slot_start && <span className="text-[9px] font-semibold text-slate-400">({q.slot_start})</span>}
                    </span>
                    {statusBadge(q.status)}
                  </div>
                ))}
                {queue.length === 0 && <p className="text-center text-xs italic text-slate-400 py-4">Aún no hay nadie en la cola.</p>}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
