import React, { useCallback, useEffect, useRef, useState } from 'react';
import axiosInstance from '../../lib/axios';

/**
 * Bandeja §67.C — fichajes marcados para revisión, CON su reincidencia (2026-08-28, revisión
 * externa r2-c). El endpoint existía desde §67.C pero NINGUNA pantalla lo consumía: la marca
 * `flagged_for_review` era bitácora, no control. Este panel la vuelve control: lista los
 * fichajes marcados (foto omitida por falla de cámara, hora del cliente con deriva grande) y
 * arriba el PATRÓN — quién cae una y otra vez en 90 días. Un corte de red real marca a media
 * sucursal un día; el que se quita retardos "offline" se marca solo, muchos días.
 *
 * Patrón LateJustificationsPanel: autocontenido, sondea solo, se oculta cuando no hay nada.
 * Consume: GET /admin/clock/flagged-punches.
 */

interface FichajeMarcado {
  id: number;
  user_id: number;
  employee_name_at_time: string | null;
  date: string;
  type: string;
  time: string;
  verification_method: string | null;
  photo_skipped_reason: string | null;
  details: string | null;
}

interface Reincidencia {
  user_id: number;
  nombre: string | null;
  veces: number;
  dias: number;
  desde: string;
  hasta: string;
}

const POLL_MS = 30000;

/** El porqué de la marca, legible: sale de `details` (deriva) o de la omisión de foto. */
const porQue = (f: FichajeMarcado): string => {
  try {
    const d = f.details ? JSON.parse(f.details) : null;
    if (d && typeof d.deriva_min === 'number' && d.hora_reclamada) {
      return `hora del cliente: dijo ${String(d.hora_reclamada).slice(0, 5)}, llegó al servidor ${d.deriva_min} min después`;
    }
  } catch {
    /* details ilegible: se cae al motivo de foto */
  }
  if (f.photo_skipped_reason === 'camera_unavailable') return 'entrada sin foto (cámara no disponible)';
  if (f.photo_skipped_reason === 'permission_denied') return 'entrada sin foto (permiso de cámara negado)';
  return 'marcado para revisión';
};

export const FichajesPorRevisarPanel = () => {
  const [fichajes, setFichajes] = useState<FichajeMarcado[]>([]);
  const [reincidencia, setReincidencia] = useState<Reincidencia[]>([]);
  const [expandido, setExpandido] = useState(false);
  const mounted = useRef(true);

  const fetchBandeja = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/clock/flagged-punches');
      if (mounted.current && res.data?.success) {
        setFichajes(Array.isArray(res.data.data) ? res.data.data : []);
        setReincidencia(Array.isArray(res.data.reincidencia) ? res.data.reincidencia : []);
      }
    } catch {
      // Error transitorio o sin permiso: se conserva lo previo; el próximo sondeo se recupera.
    }
  }, []);

  useEffect(() => {
    mounted.current = true;
    fetchBandeja();
    const t = setInterval(fetchBandeja, POLL_MS);
    return () => {
      mounted.current = false;
      clearInterval(t);
    };
  }, [fetchBandeja]);

  if (fichajes.length === 0) return null;

  // El patrón que importa: quien cae 2+ veces en la ventana de 90 días.
  const reincidentes = reincidencia.filter(r => Number(r.veces) >= 2);
  const visibles = expandido ? fichajes : fichajes.slice(0, 5);

  return (
    <div className="bg-white border border-rose-200 rounded-2xl p-4 shadow-sm mb-3 flex flex-col gap-3 text-left">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span className="text-xl">🚩</span>
          <div>
            <p className="font-black text-xs sm:text-sm text-slate-900">Fichajes por revisar</p>
            <p className="text-[9px] sm:text-[10px] text-slate-500 leading-tight">
              Aceptados pero marcados: hora puesta por el cliente con deriva grande, o entrada sin foto
            </p>
          </div>
        </div>
        <span className="px-2.5 py-1 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 text-xs font-extrabold">
          {fichajes.length}
        </span>
      </div>

      {reincidentes.length > 0 && (
        <div className="rounded-xl bg-rose-50 border border-rose-200 p-2.5">
          <p className="text-[10px] font-bold text-rose-800 uppercase tracking-wider mb-1.5">
            Reincidencia (últimos 90 días)
          </p>
          <div className="flex flex-wrap gap-1.5">
            {reincidentes.map(r => (
              <span
                key={r.user_id}
                className="px-2 py-1 rounded-lg bg-white border border-rose-200 text-[11px] font-semibold text-rose-800"
                title={`Del ${r.desde} al ${r.hasta}`}
              >
                {r.nombre ?? `Usuario ${r.user_id}`} · {r.veces} veces en {r.dias} {Number(r.dias) === 1 ? 'día' : 'días'}
              </span>
            ))}
          </div>
        </div>
      )}

      <div className="space-y-1.5">
        {visibles.map(f => (
          <div key={f.id} className="flex items-start justify-between gap-2 text-xs p-2 rounded-xl bg-slate-50 border border-slate-200/80">
            <div>
              <span className="font-bold text-slate-900">{f.employee_name_at_time ?? `Usuario ${f.user_id}`}</span>
              <span className="text-slate-500"> — {porQue(f)}</span>
            </div>
            <span className="text-[10px] text-slate-400 font-medium whitespace-nowrap">
              {f.date} {String(f.time).slice(0, 5)}
            </span>
          </div>
        ))}
      </div>

      {fichajes.length > 5 && (
        <button
          type="button"
          onClick={() => setExpandido(v => !v)}
          className="text-[11px] font-bold text-slate-500 hover:text-slate-700 underline border-none bg-transparent cursor-pointer self-start px-0"
        >
          {expandido ? 'Ver menos' : `Ver los ${fichajes.length}`}
        </button>
      )}
    </div>
  );
};
