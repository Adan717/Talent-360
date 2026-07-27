import React, { useState, useMemo, useEffect } from 'react';
import { ShieldCheck, X, Loader2, Check } from 'lucide-react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';

/**
 * §41 — Validar una tarea con el PIN del supervisor, sin que él tenga que iniciar sesión
 * en el dispositivo del colaborador.
 *
 * Contexto (auditoría en vivo 2026-07-26): el endpoint existía desde el 22-jul y nunca se
 * cableó, así que en la práctica una tarea que requería validación se quedaba esperando a
 * que el supervisor abriera SU propia sesión — cosa que en piso no pasa.
 *
 * Endpoint (verificado contra el backend):
 *   POST /task-assignments/{id}/validate-with-pin
 *   body { supervisor_user_id: number, pin: string, status: 'completed'|'in_progress', feedback?: string }
 *
 * El backend ya trae las defensas importantes y NO hay que replicarlas aquí:
 *   - rechaza si el supervisor elegido es el propio colaborador (no hay auto-validación),
 *   - verifica el PIN contra `employees.security_pin` (hasheado),
 *   - verifica que el puesto del supervisor realmente supervise al del colaborador,
 *   - responde el MISMO mensaje genérico ante cualquier fallo, para no revelar si el error
 *     fue el PIN, el permiso o el usuario. Ese mensaje se muestra tal cual, a propósito.
 *
 * Cuidados de esta pantalla, porque el PIN se teclea en un dispositivo ajeno:
 *   - el campo es `type="password"`, nunca se ve en claro;
 *   - el PIN vive solo en estado local y se limpia SIEMPRE al terminar (éxito o error) y al
 *     desmontar — no se guarda en localStorage ni se manda a ningún otro lado;
 *   - no se registra en consola en ningún caso.
 */

interface SupervisorPinValidationProps {
  assignmentId: string;
  /** users.id del colaborador dueño de la tarea — se excluye de la lista de validadores. */
  employeeUserId?: number | null;
  taskTitle?: string;
  isDark?: boolean;
  onClose: () => void;
  onValidated?: () => void;
}

export function SupervisorPinValidation({
  assignmentId,
  employeeUserId,
  taskTitle,
  isDark = false,
  onClose,
  onValidated,
}: SupervisorPinValidationProps) {
  const { globalUsers } = useAppStore();
  const [supervisorId, setSupervisorId] = useState<string>('');
  const [pin, setPin] = useState('');
  const [feedback, setFeedback] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  // Candidatos: quien pueda supervisar. El backend hace la verificación real de jerarquía;
  // esto es solo para no ofrecer una lista absurda. Se excluye al propio colaborador.
  const candidates = useMemo(() => {
    return (globalUsers || []).filter((u: any) => {
      const isBoss = u?.role === 'supervisor' || u?.role === 'admin';
      const isSelf = employeeUserId != null && Number(u?.id) === Number(employeeUserId);
      return isBoss && !isSelf;
    });
  }, [globalUsers, employeeUserId]);

  // El PIN nunca debe sobrevivir a esta pantalla.
  useEffect(() => {
    return () => setPin('');
  }, []);

  const submit = async () => {
    if (!supervisorId || pin.trim().length === 0) {
      setError('Selecciona quién valida y escribe su PIN.');
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      await axiosInstance.post(`/task-assignments/${assignmentId}/validate-with-pin`, {
        supervisor_user_id: Number(supervisorId),
        pin,
        status: 'completed',
        feedback: feedback.trim() || null,
      });
      setPin('');
      setDone(true);
      onValidated?.();
      setTimeout(onClose, 1200);
    } catch (e: any) {
      // Mensaje genérico del backend, a propósito: no distingue PIN malo de falta de permiso.
      setError(e?.response?.data?.message || 'No se pudo validar la tarea. Verifica el PIN.');
    } finally {
      setPin('');
      setSubmitting(false);
    }
  };

  const panel = isDark ? 'bg-slate-900 border-slate-800 text-slate-100' : 'bg-white border-slate-200 text-slate-800';
  const field = isDark
    ? 'bg-slate-950 border-slate-800 text-slate-100 placeholder-slate-600'
    : 'bg-white border-slate-200 text-slate-800 placeholder-slate-400';

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
      <div className={`w-full max-w-sm rounded-3xl border shadow-2xl p-5 ${panel}`}>
        <div className="flex items-start justify-between mb-4">
          <div className="flex items-center gap-2.5">
            <div className="w-10 h-10 rounded-2xl bg-violet-100 text-violet-600 flex items-center justify-center shrink-0">
              <ShieldCheck size={20} />
            </div>
            <div className="min-w-0">
              <h3 className="text-sm font-black leading-tight">Validación del supervisor</h3>
              {taskTitle && (
                <p className={`text-[11px] font-semibold truncate ${isDark ? 'text-slate-400' : 'text-slate-500'}`}>
                  {taskTitle}
                </p>
              )}
            </div>
          </div>
          <button type="button" onClick={onClose} className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600" aria-label="Cerrar">
            <X size={18} />
          </button>
        </div>

        {done ? (
          <div className="flex flex-col items-center justify-center py-8 gap-2">
            <div className="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center">
              <Check size={24} />
            </div>
            <p className="text-sm font-black text-emerald-700">Tarea validada</p>
          </div>
        ) : (
          <>
            <p className={`text-[11px] leading-relaxed mb-4 ${isDark ? 'text-slate-400' : 'text-slate-500'}`}>
              Entrega el dispositivo a tu supervisor. Él elige su nombre y teclea su PIN — no necesita
              iniciar sesión aquí.
            </p>

            <label className="block text-[10px] font-black uppercase tracking-wider mb-1.5 text-slate-500">
              ¿Quién valida?
            </label>
            <select
              value={supervisorId}
              onChange={e => setSupervisorId(e.target.value)}
              className={`w-full mb-3 px-3 py-2.5 rounded-xl border text-sm font-medium outline-none focus:border-violet-500 ${field}`}
            >
              <option value="">Selecciona a tu supervisor</option>
              {candidates.map((u: any) => (
                <option key={u.id} value={u.id}>{u.name}</option>
              ))}
            </select>

            <label className="block text-[10px] font-black uppercase tracking-wider mb-1.5 text-slate-500">
              PIN del supervisor
            </label>
            <input
              type="password"
              inputMode="numeric"
              autoComplete="off"
              value={pin}
              onChange={e => setPin(e.target.value)}
              placeholder="••••"
              className={`w-full mb-3 px-3 py-2.5 rounded-xl border text-sm font-bold tracking-[0.3em] outline-none focus:border-violet-500 ${field}`}
            />

            <label className="block text-[10px] font-black uppercase tracking-wider mb-1.5 text-slate-500">
              Comentario (opcional)
            </label>
            <textarea
              value={feedback}
              onChange={e => setFeedback(e.target.value)}
              rows={2}
              placeholder="Observaciones sobre el trabajo realizado"
              className={`w-full mb-4 px-3 py-2.5 rounded-xl border text-sm font-medium outline-none resize-none focus:border-violet-500 ${field}`}
            />

            {error && (
              <p className="text-[11px] font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl p-2.5 mb-3">
                {error}
              </p>
            )}

            <button
              type="button"
              onClick={submit}
              disabled={submitting}
              className="w-full py-3 rounded-2xl bg-violet-600 hover:bg-violet-700 disabled:opacity-50 text-white font-black text-sm flex items-center justify-center gap-2 transition-colors"
            >
              {submitting ? <Loader2 size={16} className="animate-spin" /> : <ShieldCheck size={16} />}
              Validar tarea
            </button>
          </>
        )}
      </div>
    </div>
  );
}

export default SupervisorPinValidation;
