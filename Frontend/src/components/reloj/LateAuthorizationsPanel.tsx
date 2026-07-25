import React, { useCallback, useEffect, useRef, useState } from 'react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';

/**
 * Panel del admin/supervisor para resolver solicitudes de autorización de entrada tardía (R57, la UI
 * de R56). Componente TIPADO y autocontenido (no vive en los @ts-nocheck): sondea las pendientes y
 * aprueba/rechaza. Se monta sólo para quien tiene autoridad (el llamador lo condiciona), y además el
 * backend gatea las rutas a admin/supervisor.
 *
 * Consume: GET /admin/late-authorizations · POST /admin/late-authorizations/{id}/resolve (R56).
 */

interface LateAuthRequest {
  id: number;
  user_id: number;
  date: string;
  requested_late_minutes: number | null;
  employee_name: string | null;
}

const POLL_MS = 15000;

export const LateAuthorizationsPanel = () => {
  const [requests, setRequests] = useState<LateAuthRequest[]>([]);
  const [resolvingId, setResolvingId] = useState<number | null>(null);
  const mounted = useRef(true);
  // R75: nadie resuelve su PROPIA solicitud (el backend responde 403). Se necesita saber quién soy
  // para no ofrecer botones que van a fallar y explicar por qué.
  const { currentUser } = useAppStore();

  const fetchPending = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/late-authorizations');
      if (mounted.current && Array.isArray(res.data)) {
        setRequests(res.data);
      }
    } catch {
      // Error transitorio (500/timeout): se CONSERVA la lista previa en vez de vaciarla — el panel
      // sólo lo montan admin/supervisor (guard en RelojVisual), así que un fallo de red no debe
      // hacer desaparecer sus pendientes; el próximo sondeo se recupera.
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
      await axiosInstance.post(`/admin/late-authorizations/${id}/resolve`, { status });
    } catch {
      fetchPending();
    } finally {
      if (mounted.current) setResolvingId(null);
    }
  };

  if (requests.length === 0) return null;

  return (
    <div className="bg-sky-700 text-white rounded-2xl p-4 shadow-lg mb-3 flex flex-col gap-3 text-left border border-sky-500/20">
      <div className="flex items-center gap-2">
        <span className="text-xl">⏰</span>
        <div>
          <p className="font-black text-xs sm:text-sm">Solicitudes de Autorización de Entrada</p>
          <p className="text-[9px] sm:text-[10px] text-sky-100 opacity-90 leading-tight">
            Colaboradores con retardo pidiendo autorización para registrar entrada
          </p>
        </div>
      </div>
      <div className="space-y-2 max-h-40 overflow-y-auto pr-1">
        {requests.map(r => (
          <div
            key={r.id}
            className="bg-sky-800/40 border border-sky-500/30 rounded-xl p-2.5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2.5"
          >
            <div className="flex flex-col text-left">
              <span className="text-xs font-black text-white">{r.employee_name || 'Colaborador'}</span>
              <span className="text-[10px] text-sky-100">
                Retardo de <strong className="text-white font-bold">{r.requested_late_minutes ?? '—'} min</strong>
              </span>
            </div>
            {Number(r.user_id) === Number(currentUser?.id) ? (
              // Tu propia solicitud: se muestra (para que sepas que está en curso) pero sin acciones.
              <span className="text-[9.5px] font-bold text-sky-100 bg-sky-900/40 border border-sky-500/30 rounded-lg px-2.5 py-1.5 shrink-0">
                Tu solicitud · debe autorizarla otro admin o supervisor
              </span>
            ) : (
              <div className="flex gap-2 shrink-0 w-full sm:w-auto justify-end">
                <button
                  onClick={() => resolve(r.id, 'approved')}
                  disabled={resolvingId === r.id}
                  className="bg-emerald-500 hover:bg-emerald-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 disabled:opacity-50"
                >
                  ✓ Autorizar
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

export default LateAuthorizationsPanel;
