import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, Lock, RefreshCw, Save, ShieldCheck } from 'lucide-react';
import axiosInstance from '../lib/axios';

/**
 * Matriz de permisos por puesto (Plan A4, 2026-09-07).
 *
 * El motor existía desde §65 (`GET`/`PUT /admin/permissions/matrix`, `PermissionMatrixController`)
 * pero NINGUNA pantalla lo consumía: la migración de julio repartió capacidades sólo a las empresas
 * de ese día y a las nuevas no se las daba nadie, así que un supervisor recién ascendido nacía sin
 * poder ver el Monitor ni validar tareas, y el admin no tenía dónde arreglarlo. Esta pantalla es ese
 * "dónde".
 *
 * Reglas que vienen del servidor y aquí sólo se PINTAN (no se hacen cumplir desde el navegador):
 *  - Sólo el admin dueño puede leer y escribir la matriz (`role:admin`, indelegable). Un 403 se
 *    muestra como tal, no se esconde.
 *  - Las capacidades indelegables (facturación, suscripción, permisos, reserva legal…) se muestran
 *    bloqueadas: el servidor las ignora aunque se manden.
 *  - Guardar REEMPLAZA las capacidades de cada puesto enviado; por eso se manda la matriz completa
 *    y no sólo el puesto que cambió.
 */

interface Capacidad {
  name: string;
  description: string;
  delegable: boolean;
}

interface Puesto {
  id: number;
  name: string;
}

type Matriz = Record<number, string[]>;

/** Laravel devuelve `[]` cuando no hay filas y un objeto `{ "<id>": [...] }` cuando sí. */
export function normalizarMatriz(cruda: unknown, puestos: Puesto[]): Matriz {
  const salida: Matriz = {};
  for (const p of puestos) salida[p.id] = [];
  if (cruda && typeof cruda === 'object' && !Array.isArray(cruda)) {
    for (const [id, caps] of Object.entries(cruda as Record<string, unknown>)) {
      if (Array.isArray(caps)) salida[Number(id)] = caps.map(String);
    }
  }
  return salida;
}

export default function MatrizDePermisos() {
  const [capacidades, setCapacidades] = useState<Capacidad[]>([]);
  const [indelegables, setIndelegables] = useState<Capacidad[]>([]);
  const [baseSupervisor, setBaseSupervisor] = useState<string[]>([]);
  const [puestos, setPuestos] = useState<Puesto[]>([]);
  const [matriz, setMatriz] = useState<Matriz>({});
  const [guardada, setGuardada] = useState<Matriz>({});
  const [cargando, setCargando] = useState(true);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [aviso, setAviso] = useState<string | null>(null);
  const [notas, setNotas] = useState<string[]>([]);

  const cargar = useCallback(async () => {
    setCargando(true);
    setError(null);
    try {
      const { data } = await axiosInstance.get('/admin/permissions/matrix');
      const puestosServidor: Puesto[] = data.job_roles || [];
      const m = normalizarMatriz(data.matrix, puestosServidor);
      setCapacidades(data.capabilities || []);
      setIndelegables(data.indelegable || []);
      setBaseSupervisor(data.supervisor_defaults || []);
      setPuestos(puestosServidor);
      setMatriz(m);
      setGuardada(m);
    } catch (e: any) {
      const estado = e?.response?.status;
      setError(
        estado === 403
          ? 'Sólo el administrador dueño de la empresa puede ver y editar los permisos por puesto.'
          : e?.response?.data?.message || 'No se pudo cargar la matriz de permisos.'
      );
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const hayCambios = useMemo(() => {
    for (const p of puestos) {
      const a = [...(matriz[p.id] || [])].sort().join(',');
      const b = [...(guardada[p.id] || [])].sort().join(',');
      if (a !== b) return true;
    }
    return false;
  }, [matriz, guardada, puestos]);

  const tiene = (puestoId: number, cap: string) => (matriz[puestoId] || []).includes(cap);

  const alternar = (puestoId: number, cap: string) => {
    setAviso(null);
    setMatriz(prev => {
      const actuales = prev[puestoId] || [];
      const siguientes = actuales.includes(cap) ? actuales.filter(c => c !== cap) : [...actuales, cap];
      return { ...prev, [puestoId]: siguientes };
    });
  };

  const aplicarBaseSupervisor = (puestoId: number) => {
    setAviso(null);
    setMatriz(prev => ({ ...prev, [puestoId]: [...baseSupervisor] }));
  };

  const guardar = async () => {
    setGuardando(true);
    setError(null);
    setAviso(null);
    setNotas([]);
    try {
      const cuerpo: Record<string, string[]> = {};
      for (const p of puestos) cuerpo[String(p.id)] = matriz[p.id] || [];
      const { data } = await axiosInstance.put('/admin/permissions/matrix', { matrix: cuerpo });
      setNotas(Array.isArray(data?.notes) ? data.notes : []);
      setAviso(data?.message || 'Matriz de permisos actualizada.');
      // Se recarga del servidor en vez de dar por buena la copia local: lo que vale es lo que
      // quedó guardado (el servidor puede haber ignorado algo, y lo dice en `notes`).
      await cargar();
    } catch (e: any) {
      const estado = e?.response?.status;
      setError(
        estado === 403
          ? 'Sólo el administrador dueño de la empresa puede editar los permisos por puesto.'
          : e?.response?.data?.message || 'No se pudieron guardar los permisos.'
      );
    } finally {
      setGuardando(false);
    }
  };

  if (cargando) {
    return (
      <div className="flex flex-col items-center justify-center py-16 text-slate-500">
        <RefreshCw size={22} className="animate-spin mb-3 text-indigo-500" />
        <span className="text-xs font-bold">Cargando permisos por puesto...</span>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-in fade-in duration-200">
      <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-4 border-b border-slate-100 pb-4">
        <div>
          <h3 className="text-xl font-black text-slate-800 tracking-tight flex items-center gap-2">
            <ShieldCheck className="text-indigo-600" size={24} />
            Permisos por puesto
          </h3>
          <p className="text-xs text-slate-500 font-medium mt-0.5 max-w-2xl">
            Qué puede hacer cada puesto dentro de la empresa. El administrador dueño lo puede todo;
            aquí decides qué le delegas a cada puesto (por ejemplo, que un encargado valide tareas
            o vea reportes). Los cambios aplican a todas las personas con ese puesto.
          </p>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <button
            type="button"
            onClick={cargar}
            className="p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl border-none cursor-pointer"
            title="Recargar"
          >
            <RefreshCw size={16} />
          </button>
          <button
            type="button"
            onClick={guardar}
            disabled={!hayCambios || guardando || !!error}
            className="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white rounded-xl text-xs font-black flex items-center gap-2 border-none cursor-pointer"
          >
            {guardando ? <RefreshCw size={14} className="animate-spin" /> : <Save size={14} />}
            {guardando ? 'Guardando...' : 'Guardar cambios'}
          </button>
        </div>
      </div>

      {error && (
        <div role="alert" className="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl flex items-center gap-3 text-xs font-bold">
          <AlertTriangle size={18} className="shrink-0 text-rose-600" />
          {error}
        </div>
      )}

      {aviso && (
        <div role="status" className="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center gap-3 text-xs font-bold">
          <CheckCircle2 size={18} className="shrink-0 text-emerald-600" />
          {aviso}
        </div>
      )}

      {notas.length > 0 && (
        <div className="p-4 bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl text-xs space-y-1">
          <div className="font-black flex items-center gap-2"><AlertTriangle size={14} /> El servidor ignoró algo:</div>
          <ul className="list-disc pl-5">
            {notas.map((n, i) => <li key={i}>{n}</li>)}
          </ul>
        </div>
      )}

      {!error && puestos.length === 0 && (
        <div className="p-8 text-center text-slate-500 text-xs font-bold bg-slate-50 rounded-2xl border border-slate-200">
          Todavía no hay puestos en la empresa. Crea los puestos en Directorio Digital → Puestos y vuelve aquí.
        </div>
      )}

      {!error && puestos.length > 0 && (
        <div className="overflow-x-auto rounded-2xl border border-slate-200">
          <table className="w-full border-collapse text-left text-xs">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200">
                <th className="py-3 px-4 font-black text-slate-500 uppercase tracking-wider min-w-[260px]">Capacidad</th>
                {puestos.map(p => (
                  <th key={p.id} className="py-3 px-3 font-black text-slate-700 text-center align-bottom min-w-[120px]">
                    <div className="mb-1">{p.name}</div>
                    <button
                      type="button"
                      onClick={() => aplicarBaseSupervisor(p.id)}
                      className="text-[10px] font-bold text-indigo-600 hover:text-indigo-800 bg-transparent border-none cursor-pointer underline"
                      title={'Deja a "' + p.name + '" con la base de un supervisor: ' + baseSupervisor.join(', ')}
                    >
                      base de supervisor
                    </button>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {capacidades.map(cap => (
                <tr key={cap.name} className="hover:bg-slate-50/60">
                  <td className="py-3 px-4 align-top">
                    <div className="font-bold text-slate-800">{cap.description}</div>
                    <div className="text-[10px] text-slate-400 font-mono">{cap.name}</div>
                  </td>
                  {puestos.map(p => (
                    <td key={p.id} className="py-3 px-3 text-center align-top">
                      <input
                        type="checkbox"
                        aria-label={cap.name + ' para ' + p.name}
                        checked={tiene(p.id, cap.name)}
                        onChange={() => alternar(p.id, cap.name)}
                        className="w-4 h-4 rounded border-slate-300 text-indigo-600 cursor-pointer"
                      />
                    </td>
                  ))}
                </tr>
              ))}
              {indelegables.map(cap => (
                <tr key={cap.name} className="bg-slate-50/80 text-slate-400">
                  <td className="py-3 px-4 align-top">
                    <div className="font-bold flex items-center gap-1.5"><Lock size={12} /> {cap.description}</div>
                    <div className="text-[10px] font-mono">{cap.name} · sólo el administrador dueño</div>
                  </td>
                  {puestos.map(p => (
                    <td key={p.id} className="py-3 px-3 text-center align-top">
                      <input
                        type="checkbox"
                        aria-label={cap.name + ' para ' + p.name + ' (indelegable)'}
                        checked={false}
                        disabled
                        readOnly
                        className="w-4 h-4 rounded border-slate-200 cursor-not-allowed"
                      />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!error && puestos.length > 0 && (
        <p className="text-[11px] text-slate-400 font-medium">
          Las filas con candado son indelegables: sólo el administrador dueño de la empresa las tiene y no se pueden otorgar a un puesto.
          Un puesto sin ninguna capacidad marcada sólo puede fichar y usar su propia app.
        </p>
      )}
    </div>
  );
}
