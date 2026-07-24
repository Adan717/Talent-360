import React, { useCallback, useEffect, useRef, useState } from 'react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';

/**
 * Panel del admin/supervisor para resolver CONTINGENCIAS por fuerza mayor (R83, la UI de T1.4).
 * Componente TIPADO y autocontenido (patrón R57/R82): sondea las pendientes y aprueba/rechaza. Una
 * contingencia APROBADA hace que la nómina pague ese día al 100% (jornada causal LFT) y congele el
 * retardo. Se monta sólo para quien tiene acceso admin/supervisor; el backend gatea la ruta igual.
 *
 * Consume: GET /admin/contingencies · POST /admin/contingencies/{id}/resolve (R83).
 */

interface ContingencyDay {
  id: number;
  user_id: number;
  date: string;
  reason: string;
  employee_name: string | null;
}

const POLL_MS = 15000;

export const ContingenciesPanel = () => {
  const [requests, setRequests] = useState<ContingencyDay[]>([]);
  const [resolvingId, setResolvingId] = useState<number | null>(null);
  const mounted = useRef(true);
  const { currentUser } = useAppStore();

  const fetchPending = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/contingencies');
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
    // Aprobar paga un día completo NO trabajado al 100%: es el acto de más dinero de estos paneles.
    // Un confirm() evita el misclick (el review lo señaló); rechazar no mueve dinero y no lo pide.
    if (status === 'approved') {
      const r = requests.find(x => x.id === id);
      const quien = r?.employee_name || 'este colaborador';
      if (!window.confirm(`¿Aprobar la contingencia de ${quien} (${r?.date})? Se le pagará ese día al 100% aunque no lo haya trabajado.`)) {
        return;
      }
    }
    setResolvingId(id);
    setRequests(prev => prev.filter(r => r.id !== id));
    try {
      await axiosInstance.post(`/admin/contingencies/${id}/resolve`, { status });
    } catch {
      fetchPending();
    } finally {
      if (mounted.current) setResolvingId(null);
    }
  };

  if (requests.length === 0) return null;

  return (
    <div className="bg-slate-800 text-white rounded-2xl p-4 shadow-lg mb-3 flex flex-col gap-3 text-left border border-slate-600/30">
      <div className="flex items-center gap-2">
        <span className="text-xl">⚡</span>
        <div>
          <p className="font-black text-xs sm:text-sm">Contingencias por Fuerza Mayor</p>
          <p className="text-[9px] sm:text-[10px] text-slate-300 opacity-90 leading-tight">
            Aprobar paga la jornada al 100% (LFT) y no la cuenta como falta
          </p>
        </div>
      </div>
      <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
        {requests.map(r => (
          <div
            key={r.id}
            className="bg-slate-700/50 border border-slate-500/30 rounded-xl p-2.5 flex flex-col gap-2"
          >
            <div className="flex flex-col text-left">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-black text-white">{r.employee_name || 'Colaborador'}</span>
                <span className="text-[9px] text-slate-300 shrink-0">{r.date}</span>
              </div>
              <span className="text-[10px] text-slate-200 italic leading-snug mt-0.5">"{r.reason}"</span>
            </div>
            {Number(r.user_id) === Number(currentUser?.id) ? (
              <span className="text-[9.5px] font-bold text-slate-200 bg-slate-900/50 border border-slate-500/30 rounded-lg px-2.5 py-1.5">
                Tu contingencia · debe resolverla otro admin o supervisor
              </span>
            ) : (
              <div className="flex gap-2 justify-end">
                <button
                  onClick={() => resolve(r.id, 'approved')}
                  disabled={resolvingId === r.id}
                  className="bg-emerald-500 hover:bg-emerald-600 text-white font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 disabled:opacity-50"
                >
                  ✓ Aprobar (pago 100%)
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

export default ContingenciesPanel;
