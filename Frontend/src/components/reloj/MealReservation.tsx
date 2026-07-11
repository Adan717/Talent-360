import React, { useState, useEffect, useCallback } from 'react';
import { Utensils, Clock, Users, CheckCircle, XCircle, ArrowLeftRight, Loader2, AlertTriangle, Coffee } from 'lucide-react';
import axiosInstance from '../../lib/axios';

interface MealSlot {
  slot_start: string;
  slot_end: string;
  capacity: number;
  booked: number;
  available: number;
  is_full: boolean;
  same_role_blocked: boolean;
  is_my_reservation: boolean;
}

interface Reservation {
  id: number;
  slot_start: string;
  slot_end: string;
  status: string;
}

interface MealReservationProps {
  currentUserId?: number;
  onClose?: () => void;
}

export const MealReservation: React.FC<MealReservationProps> = ({ onClose }) => {
  const [slots, setSlots] = useState<MealSlot[]>([]);
  const [myReservation, setMyReservation] = useState<Reservation | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isProcessing, setIsProcessing] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const today = new Date().toISOString().split('T')[0];

  const fetchSlots = useCallback(async () => {
    try {
      setIsLoading(true);
      setError('');
      const res = await axiosInstance.get('/meal-reservations/slots', { params: { date: today } });
      if (res.data.success) {
        setSlots(res.data.slots || []);
        setMyReservation(res.data.my_reservation || null);
      }
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Error al cargar horarios del comedor.');
    } finally {
      setIsLoading(false);
    }
  }, [today]);

  useEffect(() => {
    fetchSlots();
  }, [fetchSlots]);

  const handleReserve = async (slot: MealSlot) => {
    if (slot.is_full || slot.same_role_blocked) return;
    setIsProcessing(true);
    setError('');
    setSuccess('');
    try {
      const res = await axiosInstance.post('/meal-reservations', {
        date: today,
        slot_start: slot.slot_start,
      });
      if (res.data.success) {
        setSuccess(res.data.message);
        fetchSlots();
      }
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Error al reservar.');
    } finally {
      setIsProcessing(false);
    }
  };

  const handleCancel = async () => {
    if (!myReservation) return;
    setIsProcessing(true);
    setError('');
    try {
      const res = await axiosInstance.delete(`/meal-reservations/${myReservation.id}`);
      if (res.data.success) {
        setSuccess(res.data.message);
        setMyReservation(null);
        fetchSlots();
      }
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Error al cancelar.');
    } finally {
      setIsProcessing(false);
    }
  };

  const getSlotColor = (slot: MealSlot) => {
    if (slot.is_my_reservation) return 'border-violet-500 bg-violet-500/20';
    if (slot.is_full || slot.same_role_blocked) return 'border-gray-700 bg-gray-800/50 opacity-60';
    return 'border-emerald-500/40 bg-emerald-500/10 hover:bg-emerald-500/20 cursor-pointer';
  };

  const getSlotStatus = (slot: MealSlot) => {
    if (slot.is_my_reservation) return { icon: <CheckCircle size={16} className="text-violet-400" />, text: 'Tu reserva', color: 'text-violet-400' };
    if (slot.same_role_blocked) return { icon: <AlertTriangle size={16} className="text-amber-400" />, text: 'Mismo puesto ocupado', color: 'text-amber-400' };
    if (slot.is_full) return { icon: <XCircle size={16} className="text-red-400" />, text: 'Lleno', color: 'text-red-400' };
    return { icon: <Coffee size={16} className="text-emerald-400" />, text: `${slot.available} lugares`, color: 'text-emerald-400' };
  };

  return (
    <div className="flex flex-col h-full bg-[#0A0A0F] text-white">
      {/* Header */}
      <div className="flex items-center justify-between p-4 border-b border-white/10">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-violet-500/20 flex items-center justify-center">
            <Utensils size={20} className="text-violet-400" />
          </div>
          <div>
            <h2 className="font-bold text-lg leading-tight">Comedor</h2>
            <p className="text-xs text-white/50">Reserva tu horario de comida</p>
          </div>
        </div>
        {onClose && (
          <button onClick={onClose} className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center hover:bg-white/20 transition-colors">
            <XCircle size={16} />
          </button>
        )}
      </div>

      {/* Mi Reserva Activa */}
      {myReservation && (
        <div className="mx-4 mt-4 p-3 rounded-xl bg-violet-500/10 border border-violet-500/30 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <CheckCircle size={18} className="text-violet-400 flex-shrink-0" />
            <div>
              <p className="text-sm font-semibold text-violet-300">Reserva activa</p>
              <p className="text-xs text-white/60">{myReservation.slot_start} - {myReservation.slot_end}</p>
            </div>
          </div>
          <button
            onClick={handleCancel}
            disabled={isProcessing}
            className="text-xs px-3 py-1.5 rounded-lg bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30 transition-colors disabled:opacity-50"
          >
            Cancelar
          </button>
        </div>
      )}

      {/* Mensajes */}
      {error && (
        <div className="mx-4 mt-3 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-sm text-red-400 flex items-center gap-2">
          <AlertTriangle size={16} className="flex-shrink-0" /> {error}
        </div>
      )}
      {success && (
        <div className="mx-4 mt-3 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-400 flex items-center gap-2">
          <CheckCircle size={16} className="flex-shrink-0" /> {success}
        </div>
      )}

      {/* Grid de Slots */}
      <div className="flex-1 overflow-y-auto p-4 space-y-3">
        {isLoading ? (
          <div className="flex flex-col items-center justify-center h-40 gap-3">
            <Loader2 size={32} className="text-violet-400 animate-spin" />
            <p className="text-sm text-white/50">Cargando horarios...</p>
          </div>
        ) : slots.length === 0 ? (
          <div className="text-center py-10 text-white/40">
            <Utensils size={40} className="mx-auto mb-3 opacity-30" />
            <p>No hay horarios de comedor configurados.</p>
            <p className="text-xs mt-1">Contacta a tu supervisor.</p>
          </div>
        ) : (
          slots.map((slot) => {
            const status = getSlotStatus(slot);
            const canReserve = !slot.is_full && !slot.same_role_blocked && !slot.is_my_reservation && !myReservation;
            return (
              <button
                key={slot.slot_start}
                onClick={() => canReserve && handleReserve(slot)}
                disabled={!canReserve || isProcessing}
                className={`w-full p-4 rounded-2xl border-2 transition-all duration-200 text-left ${getSlotColor(slot)} ${canReserve ? 'active:scale-[0.98]' : ''}`}
              >
                <div className="flex items-center justify-between">
                  {/* Hora */}
                  <div className="flex items-center gap-3">
                    <Clock size={20} className={slot.is_my_reservation ? 'text-violet-400' : slot.is_full ? 'text-gray-500' : 'text-emerald-400'} />
                    <div>
                      <p className="font-bold text-base">{slot.slot_start} - {slot.slot_end}</p>
                      <div className="flex items-center gap-1.5 mt-0.5">
                        {status.icon}
                        <span className={`text-xs ${status.color}`}>{status.text}</span>
                      </div>
                    </div>
                  </div>

                  {/* Aforo */}
                  <div className="flex items-center gap-1.5">
                    <Users size={14} className="text-white/40" />
                    <span className="text-xs text-white/40">{slot.booked}/{slot.capacity}</span>
                    {canReserve && (
                      <div className="ml-2 px-3 py-1 rounded-full bg-emerald-500/30 text-emerald-300 text-xs font-semibold">
                        Reservar
                      </div>
                    )}
                  </div>
                </div>

                {/* Barra de aforo */}
                <div className="mt-3 h-1.5 rounded-full bg-white/10 overflow-hidden">
                  <div
                    className={`h-full rounded-full transition-all duration-500 ${slot.is_full ? 'bg-red-500' : 'bg-emerald-500'}`}
                    style={{ width: `${(slot.booked / slot.capacity) * 100}%` }}
                  />
                </div>
              </button>
            );
          })
        )}
      </div>

      {/* Footer — Swap */}
      {myReservation && (
        <div className="p-4 border-t border-white/10">
          <button className="w-full py-3 rounded-xl bg-white/5 border border-white/10 text-sm text-white/60 flex items-center justify-center gap-2 hover:bg-white/10 transition-colors">
            <ArrowLeftRight size={16} />
            Intercambiar horario con un compañero
          </button>
        </div>
      )}
    </div>
  );
};

export default MealReservation;
