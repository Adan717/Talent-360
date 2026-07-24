import React, { useCallback, useEffect, useRef, useState } from 'react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';

/**
 * Panel del admin/supervisor para resolver JUSTIFICANTES de retardo (R82, la UI de la lógica de
 * nómina de T1.3). Componente TIPADO y autocontenido (patrón LateAuthorizationsPanel R57): sondea las
 * pendientes y aprueba/rechaza. Un justificante APROBADO hace que la nómina no deduzca ese retardo.
 *
 * Se monta sólo para quien tiene acceso admin/supervisor (el llamador lo condiciona) y el backend
 * gatea las rutas igual.
 *
 * Consume: GET /admin/late-justifications · POST /admin/late-justifications/{id}/resolve (R82).
 */

interface LateJustification {
  id: number;
  user_id: number;
  date: string;
  reason: string;
  requested_late_minutes: number | null;
  employee_name: string | null;
}

const POLL_MS = 15000;

export const LateJustificationsPanel = () => {
  const [requests, setRequests] = useState<LateJustification[]>([]);
  const [resolvingId, setResolvingId] = useState<number | null>(null);
  const mounted = useRef(true);
  // R75: nadie resuelve su PROPIO justificante (el backend responde 403). Se necesita saber quién soy
  // para no ofrecer botones que van a fallar.
  const { currentUser } = useAppStore();

  const fetchPending = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/late-justifications');
      if (mounted.current && Array.isArray(res.data)) {
        setRequests(res.data);
      }
    } catch {
      // Error transitorio: se CONSERVA la lista previa; el próximo sondeo se recupera.
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

  const resolve = async (id: number, status: 'approved' | 'rejected') => {
    setResolvingId(id);
    // Optimista: se quita de la lista al instante; si falla, el próximo sondeo la reintegra.
    setRequests(prev => prev.filter(r => r.id !== id));
    try {
      await axiosInstance.post(`/admin/late-justifications/${id}/resolve`, { status });
    } catch {
      fetchPending();
    } finally {
      if (mounted.current) setResolvingId(null);
    }
  };

  if (requests.length === 0) return null;

  return (
    <div className="bg-amber-600 text-white rounded-2xl p-4 shadow-lg mb-3 flex flex-col gap-3 text-left border border-amber-400/20">
      <div className="flex items-center gap-2">
        <span className="text-xl">📝</span>
        <div>
          <p className="font-black text-xs sm:text-sm">Justificantes de Retardo</p>
          <p className="text-[9px] sm:text-[10px] text-amber-50 opacity-90 leading-tight">
            Aprobar exime el descuento de ese retardo en la nómina
          </p>
        </div>
      </div>
      <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
        {requests.map(r => (
          <div
            key={r.id}
            className="bg-amber-700/40 border border-amber-400/30 rounded-xl p-2.5 flex flex-col gap-2"
          >
            <div className="flex flex-col text-left">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-black text-white">{r.employee_name || 'Colaborador'}</span>
                <span className="text-[9px] text-amber-100 shrink-0">
                  {r.date}
                  {r.requested_late_minutes != null && <> · {r.requested_late_minutes} min</>}
                </span>
              </div>
              <span className="text-[10px] text-amber-50 italic leading-snug mt-0.5">"{r.reason}"</span>
            </div>
            {Number(r.user_id) === Number(currentUser?.id) ? (
              <span className="text-[9.5px] font-bold text-amber-50 bg-amber-800/40 border border-amber-400/30 rounded-lg px-2.5 py-1.5">
                Tu justificante · debe resolverlo otro admin o supervisor
              </span>
            ) : (
              <div className="flex gap-2 justify-end">
                <button
                  onClick={() => resolve(r.id, 'approved')}
                  disabled={resolvingId === r.id}
                  className="bg-emerald-500 hover:bg-emerald-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 disabled:opacity-50"
                >
                  ✓ Aprobar
                </button>
                <button
                  onClick={() => resolve(r.id, 'rejected')}
                  disabled={resolvingId === r.id}
                  className="bg-rose-500 hover:bg-rose-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 disabled:opacity-50"
                >
                  ✕ Rechazar
                </button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
};

export default LateJustificationsPanel;
