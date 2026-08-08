// @ts-nocheck
import React from 'react';
import { Store } from 'lucide-react';
import { useAppStore } from '../../store/useAppStore';
import { avatarDe } from '../../lib/avatar';

/**
 * Reloj R95 (Fase 5 / T5.2 · N6): widget "¿Quién está en tienda?".
 *
 * Presencia EN VIVO del equipo, self-contained desde el store: `globalClockStates` (user_id → estado)
 * lo computa `useAppStore.fetchState` desde `/sync/state` y se refresca en cada punch/evento de tienda,
 * así que no hace falta endpoint nuevo. "En tienda" = estado ≠ `inactive`. Sólo muestra nombre + estado
 * (nada sensible). Funciona igual en el reloj real y en el simulador (que puebla todo el tenant).
 */
const PRESENCE = {
  active: { label: 'En turno', dot: 'bg-emerald-500' },
  meal: { label: 'En comida', dot: 'bg-amber-500' },
  short_break: { label: 'En descanso', dot: 'bg-sky-500' },
  waiting_room: { label: 'Esperando', dot: 'bg-slate-400' },
  contingency: { label: 'Contingencia', dot: 'bg-orange-500' },
};

export default function QuienEstaEnTienda({ isDark = false }) {
  const globalUsers = useAppStore(s => s.globalUsers);
  const globalClockStates = useAppStore(s => s.globalClockStates);

  // R95-F2 (liveness): `globalClockStates` sólo se refresca en las acciones PROPIAS del empleado, no
  // cuando un compañero ficha. Un poll ligero mientras el widget está montado (sólo en la pestaña de
  // nómina) mantiene la presencia razonablemente al día sin cablear Echo. Se limpia al desmontar.
  React.useEffect(() => {
    const id = setInterval(() => {
      try { useAppStore.getState().fetchState?.(); } catch {}
    }, 30000);
    return () => clearInterval(id);
  }, []);

  const present = (globalUsers || [])
    .map(u => ({ id: u.id, name: u.name, avatar: u.avatar, presence: globalClockStates?.[u.id] }))
    .filter(u => u.presence && u.presence !== 'inactive');

  return (
    <div className={`p-4 rounded-2xl border ${isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'}`}>
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-1.5">
          <Store size={14} className="text-blue-600" />
          <h4 className="text-[11px] font-black uppercase tracking-wider text-slate-400">¿Quién está en tienda?</h4>
        </div>
        <span className="text-[10px] font-black text-slate-500">
          {present.length} presente{present.length === 1 ? '' : 's'}
        </span>
      </div>

      {present.length === 0 ? (
        <p className="text-[10.5px] text-slate-400 font-medium text-center py-3">Nadie ha iniciado turno todavía.</p>
      ) : (
        <div className="space-y-1.5">
          {present.map(u => {
            const p = PRESENCE[u.presence] || { label: u.presence, dot: 'bg-slate-400' };
            return (
              <div key={u.id} className="flex items-center gap-2.5 p-2 rounded-xl bg-slate-50 dark:bg-slate-950/20 border border-slate-100 dark:border-slate-800/60">
                <img
                  src={avatarDe(u)}
                  alt=""
                  className="w-7 h-7 rounded-full object-cover bg-slate-200 shrink-0"
                />
                <p className="text-[11px] font-bold text-slate-700 dark:text-slate-200 truncate flex-1 min-w-0">{u.name}</p>
                <div className="flex items-center gap-1.5 shrink-0">
                  <span className={`w-2 h-2 rounded-full ${p.dot}`} />
                  <span className="text-[9.5px] font-bold text-slate-500">{p.label}</span>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
