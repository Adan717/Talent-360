import { useCallback, useEffect, useRef, useState } from 'react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';

/**
 * M3 (auditoría 2026-07-27): panel del admin/supervisor para TAREAS INCONCLUSAS — las que el
 * proceso nocturno (tasks:flag-unfinished) dejó en awaiting_validation + flagged_incomplete
 * porque quedaron abiertas de un día anterior (celular apagado, jornada no cerrada). Sin este
 * panel, el backend de la Sección 2 #2 estaba completo pero los "3 botones del gerente" no
 * existían en ninguna pantalla.
 *
 * Patrón LateJustificationsPanel (tipado, autocontenido, sondeo + resolución optimista).
 *
 * Consume: GET /admin/task-assignments/flagged-incomplete
 *        · POST /task-assignments/{id}/resolve-incomplete (gate C1: rol + no-propias + ancla).
 */

interface FlaggedAssignment {
  id: string;
  user_id: number | null;
  date: string | null;
  status: string;
  accumulated_mins: number | null;
  task_title: string | null;
  employee_name: string | null;
}

const POLL_MS = 20000;

export const IncompleteTasksPanel = () => {
  const [rows, setRows] = useState<FlaggedAssignment[]>([]);
  const [resolvingId, setResolvingId] = useState<string | null>(null);
  const mounted = useRef(true);
  // C1: nadie resuelve su PROPIA asignación (el backend responde 403) — no ofrecer
  // botones que van a fallar.
  const { currentUser } = useAppStore();

  const fetchPending = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/task-assignments/flagged-incomplete');
      if (mounted.current && Array.isArray(res.data)) {
        setRows(res.data);
      }
    } catch {
      // Error transitorio: se conserva la lista previa; el próximo sondeo se recupera.
    }
  }, []);

  useEffect(() => {
    mounted.current = true;
    fetchPending();
    const t = setInterval(fetchPending, POLL_MS);
    return () => {
      mounted.current = false;
      clearInterval(t);
    };
  }, [fetchPending]);

  const resolve = async (id: string, action: 'approve' | 'reschedule' | 'reject') => {
    setResolvingId(id);
    // Optimista: se quita de la lista al instante; si falla, el próximo sondeo la reintegra.
    setRows(prev => prev.filter(r => r.id !== id));
    try {
      await axiosInstance.post(`/task-assignments/${id}/resolve-incomplete`, { action });
    } catch {
      fetchPending();
    } finally {
      if (mounted.current) setResolvingId(null);
    }
  };

  if (rows.length === 0) return null;

  return (
    <div className="bg-violet-700 text-white rounded-2xl p-4 shadow-lg mb-3 flex flex-col gap-3 text-left border border-violet-400/20">
      <div className="flex items-center gap-2">
        <span className="text-xl">🌙</span>
        <div>
          <p className="font-black text-xs sm:text-sm">Tareas Inconclusas de Días Anteriores</p>
          <p className="text-[9px] sm:text-[10px] text-violet-100 opacity-90 leading-tight">
            Quedaron abiertas al cierre — decide si el trabajo cuenta: aprobar paga la recompensa,
            reprogramar la trae a hoy, rechazar la omite sin pago
          </p>
        </div>
      </div>
      <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
        {rows.map(r => (
          <div
            key={r.id}
            className="bg-violet-800/40 border border-violet-400/30 rounded-xl p-2.5 flex flex-col gap-2"
          >
            <div className="flex flex-col text-left">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-black text-white">{r.employee_name || 'Colaborador'}</span>
                <span className="text-[9px] text-violet-100 shrink-0">
                  {r.date}
                  {r.accumulated_mins != null && r.accumulated_mins > 0 && <> · {r.accumulated_mins} min invertidos</>}
                </span>
              </div>
              <span className="text-[10px] text-violet-50 leading-snug mt-0.5">
                {r.task_title || 'Tarea'}
              </span>
            </div>
            {r.user_id != null && Number(r.user_id) === Number(currentUser?.id) ? (
              <span className="text-[9.5px] font-bold text-violet-50 bg-violet-900/40 border border-violet-400/30 rounded-lg px-2.5 py-1.5">
                Tu tarea · debe resolverla otro admin o supervisor
              </span>
            ) : (
              <div className="flex gap-2 justify-end flex-wrap">
                <button
                  onClick={() => resolve(r.id, 'approve')}
                  disabled={resolvingId === r.id}
                  className="bg-emerald-500 hover:bg-emerald-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 disabled:opacity-50"
                >
                  🟢 Aprobar y pagar
                </button>
                <button
                  onClick={() => resolve(r.id, 'reschedule')}
                  disabled={resolvingId === r.id}
                  className="bg-amber-500 hover:bg-amber-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 disabled:opacity-50"
                >
                  🟡 Reprogramar a hoy
                </button>
                <button
                  onClick={() => resolve(r.id, 'reject')}
                  disabled={resolvingId === r.id}
                  className="bg-rose-500 hover:bg-rose-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 disabled:opacity-50"
                >
                  🔴 Rechazar
                </button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
};

export default IncompleteTasksPanel;
