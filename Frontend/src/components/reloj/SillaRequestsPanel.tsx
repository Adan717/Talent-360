import React, { useState, useEffect, useCallback } from 'react';
import { Armchair, Check, X, Loader2, RefreshCw, Inbox } from 'lucide-react';
import axiosInstance from '../../lib/axios';
import { useAppStore } from '../../store/useAppStore';

/**
 * §25b — Bandeja de solicitudes de Ley Silla para supervisores/administradores.
 *
 * Contexto (auditoría en vivo 2026-07-26): el backend implementó
 * `GET /clock/silla/requests?status=pending` desde el 21-jul justamente para que el
 * supervisor pudiera listar y aprobar solicitudes DENTRO de la app, pero nunca se cableó
 * del lado de Cowork. El resultado en operación real: la única forma de aprobar era
 * atender la notificación push en el momento exacto en que llegaba — si el supervisor la
 * descartaba, no le llegaba, o la veía tarde, la solicitud del colaborador quedaba
 * atorada para siempre y nadie se enteraba.
 *
 * Endpoints (verificados contra el backend, no asumidos):
 *   GET  /clock/silla/requests?status=pending  -> { requests: [{ id, employee_id, requested_at }] }
 *   POST /clock/silla/{id}/approve             -> body { method: 'pin'|'qr'|'remote', supervisor_pin? }
 *   POST /clock/silla/{id}/reject              -> sin body
 *
 * Nota sobre `employee_id`: pese al nombre de la columna, el backend guarda ahí el
 * **users.id** (`ClockService` línea ~543: `'employee_id' => $user->id`). Por eso los
 * nombres se resuelven contra `globalUsers` por `id` y no por `employee_id`. Es la misma
 * confusión de §29/§30 que ya causó el fichaje entre empresas de §59 — aquí se documenta
 * en vez de tropezarla otra vez.
 */

interface SillaRequestRow {
  id: number;
  employee_id: number;
  requested_at: string;
}

interface SillaRequestsPanelProps {
  isDark?: boolean;
}

const POLL_MS = 20000;

export function SillaRequestsPanel({ isDark = false }: SillaRequestsPanelProps) {
  const { globalUsers } = useAppStore();
  const [requests, setRequests] = useState<SillaRequestRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [actingOn, setActingOn] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);

  const nameOf = (userId: number) => {
    const u = globalUsers?.find((g: any) => Number(g.id) === Number(userId));
    return u?.name || `Colaborador #${userId}`;
  };

  const waitingSince = (iso: string) => {
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const mins = Math.max(0, Math.round((Date.now() - then) / 60000));
    if (mins < 1) return 'hace menos de un minuto';
    if (mins < 60) return `hace ${mins} min`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m === 0 ? `hace ${h} h` : `hace ${h} h ${m} min`;
  };

  const fetchRequests = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/clock/silla/requests', { params: { status: 'pending' } });
      setRequests(Array.isArray(res.data?.requests) ? res.data.requests : []);
      setError(null);
    } catch (e: any) {
      // 403 = el rol no puede ver esto; cualquier otro error es de red/servidor.
      setError(
        e?.response?.status === 403
          ? 'Tu puesto no tiene permiso para revisar solicitudes de Ley Silla.'
          : 'No se pudieron cargar las solicitudes. Reintenta en un momento.'
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchRequests();
    const interval = setInterval(fetchRequests, POLL_MS);
    return () => clearInterval(interval);
  }, [fetchRequests]);

  const resolve = async (id: number, action: 'approve' | 'reject') => {
    setActingOn(id);
    setFeedback(null);
    try {
      if (action === 'approve') {
        // 'remote' = el supervisor aprueba desde su propia sesión autenticada, que es
        // justamente el caso que faltaba. 'pin'/'qr' son para aprobar en el dispositivo
        // del colaborador y se cubren por otro flujo.
        await axiosInstance.post(`/clock/silla/${id}/approve`, { method: 'remote' });
        setFeedback('Solicitud aprobada.');
      } else {
        await axiosInstance.post(`/clock/silla/${id}/reject`);
        setFeedback('Solicitud rechazada.');
      }
      setRequests(prev => prev.filter(r => r.id !== id));
    } catch (e: any) {
      setFeedback(e?.response?.data?.message || 'No se pudo procesar la solicitud.');
    } finally {
      setActingOn(null);
    }
  };

  const cardBase = isDark
    ? 'bg-slate-900/60 border-slate-800 text-slate-200'
    : 'bg-white border-slate-200 text-slate-800';

  return (
    <div className={`rounded-2xl border p-4 ${cardBase}`}>
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <Armchair size={18} className="text-violet-500" />
          <div>
            <h3 className="text-sm font-black leading-tight">Solicitudes de Ley Silla</h3>
            <p className={`text-[11px] font-medium ${isDark ? 'text-slate-400' : 'text-slate-500'}`}>
              Pendientes de tu aprobación
            </p>
          </div>
        </div>
        <button
          type="button"
          onClick={fetchRequests}
          title="Actualizar"
          className={`p-2 rounded-xl border transition-colors ${
            isDark ? 'border-slate-800 hover:bg-slate-800' : 'border-slate-200 hover:bg-slate-100'
          }`}
        >
          <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
        </button>
      </div>

      {error && (
        <p className="text-[11px] font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl p-2.5 mb-3">
          {error}
        </p>
      )}

      {feedback && (
        <p className="text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-xl p-2.5 mb-3">
          {feedback}
        </p>
      )}

      {loading && requests.length === 0 && !error && (
        <div className="flex items-center justify-center py-8 gap-2 text-xs font-bold text-slate-400">
          <Loader2 size={16} className="animate-spin" /> Cargando solicitudes...
        </div>
      )}

      {!loading && requests.length === 0 && !error && (
        <div className="flex flex-col items-center justify-center py-8 gap-2 text-center">
          <Inbox size={26} className="text-slate-300" />
          <p className={`text-xs font-bold ${isDark ? 'text-slate-400' : 'text-slate-500'}`}>
            No hay solicitudes pendientes.
          </p>
        </div>
      )}

      <div className="flex flex-col gap-2">
        {requests.map(req => (
          <div
            key={req.id}
            className={`flex items-center gap-3 rounded-xl border p-3 ${
              isDark ? 'border-slate-800 bg-slate-950/40' : 'border-slate-200 bg-slate-50'
            }`}
          >
            <div className="flex-1 min-w-0">
              <p className="text-xs font-black truncate">{nameOf(req.employee_id)}</p>
              <p className={`text-[10.5px] font-semibold ${isDark ? 'text-slate-400' : 'text-slate-500'}`}>
                Solicitó {waitingSince(req.requested_at)}
              </p>
            </div>

            <button
              type="button"
              disabled={actingOn === req.id}
              onClick={() => resolve(req.id, 'reject')}
              className="px-2.5 py-2 rounded-xl border border-rose-200 text-rose-600 hover:bg-rose-50 disabled:opacity-40 transition-colors"
              title="Rechazar"
            >
              <X size={15} />
            </button>

            <button
              type="button"
              disabled={actingOn === req.id}
              onClick={() => resolve(req.id, 'approve')}
              className="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black text-[11px] flex items-center gap-1.5 disabled:opacity-40 transition-colors"
            >
              {actingOn === req.id ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
              Aprobar
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}

export default SillaRequestsPanel;
