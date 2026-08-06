import { useEffect, useState, useCallback } from 'react';
import { GraduationCap, AlertTriangle, CheckCircle2, RefreshCw, Users } from 'lucide-react';
import axiosInstance from '../lib/axios';

/**
 * Tablero de pendientes del encargado (decisión de producto 2026-08-05/06).
 *
 * El criterio del jefe fue **nada bloquea, todo avisa**: ni traer la inducción pendiente ni
 * reprobar un examen le impiden nada al colaborador, pero el encargado tiene que enterarse para
 * acercarse a la persona. *"El que está en piso es quien puede acercarse, no el de arriba."*
 *
 * Es una CONSULTA, no mensajería: el panel pregunta `GET /supervisor/pendientes` y pinta. No se
 * usa el Chat Operativo del Monitor porque ése es un canal entre personas y meterle avisos
 * automáticos lo ensuciaría.
 *
 * Vive en Recursos Humanos —"ahí está el organigrama, ahí está la gestión de gente"— y no en un
 * tablero nuevo. A quién le toca cada caso sale del organigrama: el encargado ve a quienes ocupan
 * un puesto que reporta al suyo; el admin, a toda la empresa.
 */

interface InduccionPendiente {
  user_id: number;
  nombre: string;
  puesto: string | null;
  hire_date: string | null;
  dias_sin_induccion: number | null;
  urge: boolean;
}

interface CursoReprobado {
  progress_id: number;
  user_id: number;
  nombre: string | null;
  course_id: number;
  curso: string | null;
  intentos: number;
  ultimo_score: number;
  atendido: boolean;
}

export default function PendientesDeMiEquipo() {
  const [induccion, setInduccion] = useState<InduccionPendiente[]>([]);
  const [reprobados, setReprobados] = useState<CursoReprobado[]>([]);
  const [plazo, setPlazo] = useState(3);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [atendiendo, setAtendiendo] = useState<number | null>(null);

  const cargar = useCallback(async () => {
    try {
      setError(null);
      const { data } = await axiosInstance.get('/supervisor/pendientes');
      setInduccion(data.induccion_pendiente || []);
      setReprobados(data.cursos_reprobados || []);
      if (data.dias_de_plazo) setPlazo(data.dias_de_plazo);
    } catch (e: any) {
      setError(e?.response?.data?.message || 'No se pudieron cargar los pendientes de tu equipo.');
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const marcarAtendido = async (caso: CursoReprobado) => {
    setAtendiendo(caso.progress_id);
    try {
      await axiosInstance.post(`/supervisor/pendientes/${caso.progress_id}/atendido`);
      // Se recarga en vez de tocar el estado a mano: si mientras tanto la persona reprobó otra
      // vez, el caso tiene que reaparecer, y eso sólo lo sabe el servidor.
      await cargar();
    } catch (e: any) {
      setError(e?.response?.data?.message || 'No se pudo marcar el caso como atendido.');
    } finally {
      setAtendiendo(null);
    }
  };

  const pendientesReales = reprobados.filter(c => !c.atendido);
  const atendidos = reprobados.filter(c => c.atendido);
  const total = induccion.length + pendientesReales.length;

  if (cargando) {
    return (
      <div className="py-16 text-center text-slate-400 font-bold text-sm">
        Cargando los pendientes de tu equipo…
      </div>
    );
  }

  return (
    <div>
      <div className="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h3 className="text-2xl font-extrabold text-slate-800 flex items-center gap-2">
            <Users className="text-indigo-600" /> Pendientes de mi equipo
          </h3>
          <p className="text-slate-500 text-sm mt-1">
            Quién trae la inducción pendiente y quién se atoró con un curso. Nada de esto le
            bloquea nada al colaborador: es para que tú te acerques a tiempo.
          </p>
        </div>
        <button
          onClick={cargar}
          className="self-start md:self-auto shrink-0 flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black uppercase tracking-wider transition-colors"
        >
          <RefreshCw size={14} /> Actualizar
        </button>
      </div>

      {error && (
        <div className="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-bold">
          {error}
        </div>
      )}

      {total === 0 && !error && (
        <div className="py-14 text-center bg-emerald-50/60 rounded-3xl border border-emerald-100">
          <CheckCircle2 size={40} className="text-emerald-500 mx-auto mb-3" />
          <p className="font-black text-emerald-800">Tu equipo está al corriente.</p>
          <p className="text-emerald-700/70 text-xs font-semibold mt-1">
            Nadie trae inducción pendiente ni cursos atorados.
          </p>
        </div>
      )}

      {/* ---------------- Inducción pendiente ---------------- */}
      {induccion.length > 0 && (
        <section className="mb-10">
          <h4 className="text-xs font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
            <GraduationCap size={15} /> Inducción pendiente ({induccion.length})
          </h4>
          <div className="space-y-2.5">
            {induccion.map(p => (
              <div
                key={p.user_id}
                className={`rounded-2xl p-4 border flex items-center justify-between gap-4 ${
                  p.urge
                    ? 'bg-rose-50 border-rose-200'
                    : 'bg-white border-slate-200'
                }`}
              >
                <div className="min-w-0">
                  <p className="font-black text-slate-800 text-sm truncate">{p.nombre}</p>
                  <p className="text-[11px] font-semibold text-slate-500 truncate">
                    {p.puesto || 'Sin puesto asignado'}
                    {p.hire_date && <> · ingresó el {p.hire_date}</>}
                  </p>
                </div>
                <div className="shrink-0 text-right">
                  {p.dias_sin_induccion === null ? (
                    // El expediente no tiene fecha de ingreso (alta vieja): se muestra igual,
                    // pero sin inventar una cuenta de días.
                    <span className="text-[11px] font-bold text-slate-400">sin fecha de ingreso</span>
                  ) : (
                    <>
                      <p className={`text-lg font-black leading-none ${p.urge ? 'text-rose-600' : 'text-slate-700'}`}>
                        {p.dias_sin_induccion}
                      </p>
                      <p className={`text-[10px] font-black uppercase tracking-wider ${p.urge ? 'text-rose-500' : 'text-slate-400'}`}>
                        {p.dias_sin_induccion === 1 ? 'día' : 'días'}
                      </p>
                    </>
                  )}
                </div>
              </div>
            ))}
          </div>
          <p className="text-[11px] text-slate-400 font-semibold mt-2.5">
            Se marcan en rojo al cumplir {plazo} días desde su ingreso: ahí conviene que hables tú
            con la persona.
          </p>
        </section>
      )}

      {/* ---------------- Cursos reprobados ---------------- */}
      {(pendientesReales.length > 0 || atendidos.length > 0) && (
        <section>
          <h4 className="text-xs font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
            <AlertTriangle size={15} /> Se atoraron con un curso ({pendientesReales.length})
          </h4>

          <div className="space-y-2.5">
            {pendientesReales.map(c => (
              <div key={c.progress_id} className="rounded-2xl p-4 border bg-amber-50 border-amber-200 flex items-center justify-between gap-4">
                <div className="min-w-0">
                  <p className="font-black text-slate-800 text-sm truncate">{c.nombre}</p>
                  <p className="text-[11px] font-semibold text-slate-500 truncate">
                    {c.curso} · {c.intentos} intentos reprobados
                  </p>
                </div>
                <button
                  onClick={() => marcarAtendido(c)}
                  disabled={atendiendo === c.progress_id}
                  className="shrink-0 px-3.5 py-2 rounded-xl bg-white hover:bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-black transition-colors disabled:opacity-50"
                >
                  {atendiendo === c.progress_id ? 'Guardando…' : 'Ya hablé con él'}
                </button>
              </div>
            ))}
          </div>

          {atendidos.length > 0 && (
            <div className="mt-4">
              <p className="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">
                Ya atendidos ({atendidos.length})
              </p>
              <div className="space-y-1.5">
                {atendidos.map(c => (
                  <div key={c.progress_id} className="rounded-xl px-4 py-2.5 border border-slate-150 bg-slate-50 flex items-center gap-2 text-slate-500">
                    <CheckCircle2 size={14} className="text-emerald-500 shrink-0" />
                    <span className="text-[11.5px] font-bold truncate">{c.nombre}</span>
                    <span className="text-[11px] truncate">— {c.curso}</span>
                  </div>
                ))}
              </div>
              <p className="text-[11px] text-slate-400 font-semibold mt-2">
                Si vuelve a reprobar, el caso reaparece arriba.
              </p>
            </div>
          )}
        </section>
      )}
    </div>
  );
}
