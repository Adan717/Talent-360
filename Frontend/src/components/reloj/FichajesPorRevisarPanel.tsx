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
  /** Del total, cuantos siguen vigentes y cuantos retiro una correccion. Anular no borra el patron. */
  vigentes: number;
  anulados: number;
  dias: number;
  desde: string;
  hasta: string;
}

/** Quien CORRIGE fichajes: el vector mas caro no es el empleado, es quien mueve los registros. */
interface Corrector {
  autorizado_por: number;
  nombre: string | null;
  total: number;
  altas: number;
  anulaciones: number;
  sustituciones: number;
  a_si_mismo: number;
  empleados_distintos: number;
  dias_distintos: number;
  ultima: string;
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

/**
 * La insignia de anuladas. Solo existe si hay algo que decir: nada de " · 0 anuladas" en el
 * 99 % de las filas. Que la marca siga contando aunque una correccion la haya retirado es el
 * punto entero — si anular la borrara, el tablero seria auditable solo para quien NO puede
 * corregir, que es justo al reves.
 */
const InsigniaAnuladas = ({ cuantas }: { cuantas: number }) => {
  if (!(Number(cuantas) > 0)) return null;
  return (
    <span className="ml-1 px-1.5 py-0.5 rounded bg-rose-100 text-rose-900 font-extrabold">
      {cuantas} anulad{Number(cuantas) === 1 ? 'a' : 'as'}
    </span>
  );
};

export const FichajesPorRevisarPanel = () => {
  const [fichajes, setFichajes] = useState<FichajeMarcado[]>([]);
  const [reincidencia, setReincidencia] = useState<Reincidencia[]>([]);
  const [diferidos, setDiferidos] = useState<Reincidencia[]>([]);
  const [correctores, setCorrectores] = useState<Corrector[]>([]);
  const [expandido, setExpandido] = useState(false);
  const mounted = useRef(true);

  const fetchBandeja = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/clock/flagged-punches');
      if (mounted.current && res.data?.success) {
        setFichajes(Array.isArray(res.data.data) ? res.data.data : []);
        setReincidencia(Array.isArray(res.data.reincidencia) ? res.data.reincidencia : []);
        setDiferidos(Array.isArray(res.data.diferidos) ? res.data.diferidos : []);
        setCorrectores(Array.isArray(res.data.correctores) ? res.data.correctores : []);
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

  // El patrón que importa: quien cae 2+ veces en la ventana de 90 días (mismo corte para las
  // tres señales: un accidente aislado no acusa a nadie, la repetición sí).
  // Dos marcas hacen patron; UNA sola anulada tambien, porque es la senal de que alguien
  // retiro el rastro (mismo criterio que altas/a_si_mismo en la linea de abajo).
  const reincidentes = reincidencia.filter(r => Number(r.veces) >= 2 || Number(r.anulados) > 0);
  const diferidosReincidentes = diferidos.filter(d => Number(d.veces) >= 2 || Number(d.anulados) > 0);
  const correctoresActivos = correctores.filter(c => Number(c.total) >= 2 || Number(c.a_si_mismo) > 0 || Number(c.altas) > 0);

  // (r2b) El panel ya no depende sólo de los fichajes marcados: con cero marcados puede haber
  // correcciones o fichajes diferidos que el supervisor necesita ver.
  if (fichajes.length === 0 && reincidentes.length === 0 && diferidosReincidentes.length === 0 && correctoresActivos.length === 0) return null;

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
        {fichajes.length > 0 && (
          <span className="px-2.5 py-1 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 text-xs font-extrabold">
            {fichajes.length}
          </span>
        )}
      </div>

      {reincidentes.length > 0 && (
        <div className="rounded-xl bg-rose-50 border border-rose-200 p-2.5">
          <p className="text-[10px] font-bold text-rose-800 uppercase tracking-wider mb-1">
            Reincidencia (últimos 90 días)
          </p>
          {reincidentes.some(r => Number(r.anulados) > 0) && (
            <p className="text-[10px] text-rose-700 mb-1.5 leading-tight">
              Anuladas = la marca sigue contando aunque una corrección la haya retirado. Quién la
              retiró, abajo.
            </p>
          )}
          <div className="flex flex-wrap gap-1.5">
            {reincidentes.map(r => (
              <span
                key={r.user_id}
                className="px-2 py-1 rounded-lg bg-white border border-rose-200 text-[11px] font-semibold text-rose-800"
                title={`Del ${r.desde} al ${r.hasta}` + (Number(r.anulados) > 0 ? ` · ${r.vigentes} vigentes, ${r.anulados} retiradas por una corrección` : '')}
              >
                {r.nombre ?? `Usuario ${r.user_id}`} · {r.veces} veces en {r.dias} {Number(r.dias) === 1 ? 'día' : 'días'}
                <InsigniaAnuladas cuantas={r.anulados} />
              </span>
            ))}
          </div>
        </div>
      )}

      {diferidosReincidentes.length > 0 && (
        <div className="rounded-xl bg-amber-50 border border-amber-200 p-2.5">
          <p className="text-[10px] font-bold text-amber-800 uppercase tracking-wider mb-1">
            Fichajes diferidos (sincronizados sin red, últimos 90 días)
          </p>
          <p className="text-[10px] text-amber-700 mb-1.5 leading-tight">
            No son un señalamiento: un corte de red real es normal. Lo que importa es quien ficha
            así muchos días distintos.
          </p>
          <div className="flex flex-wrap gap-1.5">
            {diferidosReincidentes.map(d => (
              <span
                key={d.user_id}
                className="px-2 py-1 rounded-lg bg-white border border-amber-200 text-[11px] font-semibold text-amber-900"
                title={`Del ${d.desde} al ${d.hasta}` + (Number(d.anulados) > 0 ? ` · ${d.vigentes} vigentes, ${d.anulados} retiradas por una corrección` : '')}
              >
                {d.nombre ?? `Usuario ${d.user_id}`} · {d.veces} en {d.dias} {Number(d.dias) === 1 ? 'día' : 'días'}
                <InsigniaAnuladas cuantas={d.anulados} />
              </span>
            ))}
          </div>
        </div>
      )}

      {correctoresActivos.length > 0 && (
        <div className="rounded-xl bg-slate-50 border border-slate-300 p-2.5">
          <p className="text-[10px] font-bold text-slate-800 uppercase tracking-wider mb-1">
            Quién corrige fichajes (últimos 90 días)
          </p>
          <p className="text-[10px] text-slate-600 mb-1.5 leading-tight">
            Anular un duplicado es higiene; dar de alta un fichaje lo CREA. Corregir la propia
            asistencia se marca aparte.
          </p>
          <div className="space-y-1">
            {correctoresActivos.map(c => (
              <div
                key={c.autorizado_por}
                className="flex items-start justify-between gap-2 text-[11px] bg-white border border-slate-200 rounded-lg px-2 py-1.5"
              >
                <div>
                  <span className="font-bold text-slate-900">
                    {c.nombre ?? `Usuario ${c.autorizado_por}`}
                  </span>
                  <span className="text-slate-500">
                    {' '}· {c.total} correcci{Number(c.total) === 1 ? 'ón' : 'ones'} sobre{' '}
                    {c.empleados_distintos} {Number(c.empleados_distintos) === 1 ? 'persona' : 'personas'}
                  </span>
                  <div className="text-[10px] text-slate-500 mt-0.5">
                    {c.anulaciones} anulaci{Number(c.anulaciones) === 1 ? 'ón' : 'ones'} ·{' '}
                    {c.sustituciones} sustituci{Number(c.sustituciones) === 1 ? 'ón' : 'ones'} ·{' '}
                    <span className={Number(c.altas) > 0 ? 'font-bold text-slate-800' : ''}>
                      {c.altas} alta{Number(c.altas) === 1 ? '' : 's'}
                    </span>
                  </div>
                </div>
                {Number(c.a_si_mismo) > 0 && (
                  <span className="px-2 py-0.5 rounded-lg bg-rose-100 text-rose-800 border border-rose-200 text-[10px] font-extrabold whitespace-nowrap">
                    {c.a_si_mismo} a sí mismo
                  </span>
                )}
              </div>
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
