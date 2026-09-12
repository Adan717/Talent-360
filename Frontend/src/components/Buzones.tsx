import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { AlertTriangle, Inbox, Lock, MessageSquareWarning, RefreshCw, Star, UserX } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';

/**
 * Buzones (Plan A5, 2026-09-07): la pantalla de LECTURA que faltaba.
 *
 * Desde el Reloj la plantilla manda tres cosas y el sistema prometía "enviado de forma segura":
 *  1. Denuncias de un compañero ("El Soplón", `POST /reports/employee`) — confidencial: el admin y
 *     el supervisor ven quién denuncia, la persona denunciada no.
 *  2. Buzón anónimo de RRHH (`POST /anonymous-feedback`) — anónimo DE VERDAD: la tabla no guarda
 *     autor. Sólo el administrador lo lee (aquí llegan quejas que pueden ser sobre el supervisor).
 *  3. Evaluación 360 al cierre del turno (`POST /clock/evaluations`) — el ranking del ciclo lo ven
 *     admin y supervisor; cada persona ve sus propios promedios en el Reloj ("Mis resultados").
 *
 * Hasta hoy NADIE leía nada de eso: los endpoints de lectura existían sin pantalla, y los del 360
 * ni siquiera tenían ruta. Esta pestaña vive en Directorio Digital porque ahí está la gestión de
 * gente, mismo criterio que "Mi Equipo".
 */

interface Persona { id: number; name: string; role?: string }

interface Denuncia {
  id: number;
  type: string;
  details: string;
  created_at: string;
  reporter?: Persona | null;
  accused?: Persona | null;
}

interface Anonimo {
  id: number;
  type: string;
  content: string;
  created_at: string;
}

interface Puntaje {
  user_id: number;
  name: string;
  job_role: string | null;
  evaluations_received: number;
  avg_teamwork: number | string;
  avg_attitude: number | string;
  avg_performance: number | string;
  avg_leadership: number | string;
  overall_score: number | string;
}

const ETIQUETA_DENUNCIA: Record<string, string> = {
  abandono: 'Abandono de puesto',
  retardo: 'Retardos repetitivos',
  conducta: 'Conducta inapropiada',
};

const ETIQUETA_ANONIMO: Record<string, string> = {
  sugerencia: 'Sugerencia',
  ambiente: 'Clima laboral',
  acoso: 'Acoso o discriminación',
  seguridad: 'Seguridad e higiene',
  otro: 'Otro tema',
};

export function etiquetaDe(tabla: Record<string, string>, tipo: string): string {
  return tabla[tipo] || tipo;
}

function fecha(iso: string): string {
  const d = new Date(iso);
  return isNaN(d.getTime()) ? iso : d.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

function mesActual(): string {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}

type Pestana = 'denuncias' | 'anonimo' | 'evaluacion';

export default function Buzones() {
  const currentUser = useAppStore(s => s.currentUser);
  const esAdmin = currentUser?.role === 'admin' || (currentUser as any)?.system_role === 'admin';

  const [pestana, setPestana] = useState<Pestana>('denuncias');
  const [denuncias, setDenuncias] = useState<Denuncia[]>([]);
  const [anonimos, setAnonimos] = useState<Anonimo[]>([]);
  const [puntajes, setPuntajes] = useState<Puntaje[]>([]);
  const [ciclo, setCiclo] = useState(mesActual());
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [anonimoBloqueado, setAnonimoBloqueado] = useState(false);

  const cargar = useCallback(async () => {
    setCargando(true);
    setError(null);
    try {
      const [rDenuncias, rPuntajes] = await Promise.all([
        axiosInstance.get('/reports/employee'),
        axiosInstance.get('/clock/evaluations/scores', { params: { month: ciclo } }),
      ]);
      setDenuncias(Array.isArray(rDenuncias.data) ? rDenuncias.data : []);
      setPuntajes(Array.isArray(rPuntajes.data?.scores) ? rPuntajes.data.scores : []);

      // El buzón anónimo es sólo del admin: a un supervisor el servidor le contesta 403 y aquí se
      // dice tal cual, sin fingir que está vacío.
      if (esAdmin) {
        try {
          const rAnon = await axiosInstance.get('/anonymous-feedback');
          setAnonimos(Array.isArray(rAnon.data) ? rAnon.data : []);
          setAnonimoBloqueado(false);
        } catch (e: any) {
          setAnonimoBloqueado(e?.response?.status === 403);
          if (e?.response?.status !== 403) throw e;
        }
      } else {
        setAnonimoBloqueado(true);
      }
    } catch (e: any) {
      setError(e?.response?.data?.error || e?.response?.data?.message || 'No se pudieron cargar los buzones.');
    } finally {
      setCargando(false);
    }
  }, [ciclo, esAdmin]);

  useEffect(() => { cargar(); }, [cargar]);

  const pestanas: { id: Pestana; label: string; icon: ReactNode; total: number; bloqueada?: boolean }[] = [
    { id: 'denuncias', label: 'Denuncias', icon: <UserX size={16} />, total: denuncias.length },
    { id: 'anonimo', label: 'Buzón anónimo', icon: <Inbox size={16} />, total: anonimos.length, bloqueada: anonimoBloqueado },
    { id: 'evaluacion', label: 'Evaluación 360', icon: <Star size={16} />, total: puntajes.length },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-4 border-b border-border pb-4">
        <div>
          <h3 className="text-xl font-black text-text-1 tracking-tight flex items-center gap-2">
            <MessageSquareWarning className="text-danger-text" size={24} />
            Buzones
          </h3>
          <p className="text-xs text-text-3 font-medium mt-0.5 max-w-2xl">
            Lo que la plantilla manda desde el Reloj: denuncias de compañeros (confidenciales), el buzón
            anónimo de RRHH (sin autor, sólo lo lee el administrador) y la evaluación 360 del ciclo.
          </p>
        </div>
        <button
          type="button"
          onClick={cargar}
          className="p-2.5 bg-page hover:bg-slate-200 text-text-2 rounded-xl border-none cursor-pointer shrink-0"
          title="Recargar"
        >
          <RefreshCw size={16} className={cargando ? 'animate-spin' : ''} />
        </button>
      </div>

      <div className="flex items-center gap-2 bg-page p-1.5 rounded-2xl w-full overflow-x-auto">
        {pestanas.map(p => (
          <button
            key={p.id}
            type="button"
            onClick={() => setPestana(p.id)}
            className={`flex-shrink-0 flex items-center gap-2 text-xs font-bold px-4 py-2 rounded-xl transition-all border-none cursor-pointer ${
              pestana === p.id ? 'bg-white text-text-1 shadow-sm' : 'bg-transparent text-text-3 hover:text-text-2'
            }`}
          >
            {p.bloqueada ? <Lock size={14} /> : p.icon}
            {p.label}
            {!p.bloqueada && (
              <span className="px-1.5 py-0.5 rounded-full text-[9px] font-black bg-slate-200 text-text-2">{p.total}</span>
            )}
          </button>
        ))}
      </div>

      {error && (
        <div role="alert" className="p-4 bg-danger-bg border border-danger-text/20 text-danger-text rounded-2xl flex items-center gap-3 text-xs font-bold">
          <AlertTriangle size={18} className="shrink-0 text-danger-text" />
          {error}
        </div>
      )}

      {cargando && !error && (
        <div className="py-12 flex flex-col items-center justify-center gap-3 text-text-3">
          <RefreshCw size={22} className="animate-spin text-accent" />
          <span className="text-xs font-bold">Cargando buzones...</span>
        </div>
      )}

      {!cargando && !error && pestana === 'denuncias' && (
        <div className="space-y-3">
          {denuncias.length === 0 && (
            <p className="p-8 text-center text-xs font-bold text-text-3 bg-page rounded-2xl border border-border">
              No hay denuncias registradas.
            </p>
          )}
          {denuncias.map(d => (
            <article key={d.id} className="p-4 rounded-2xl border border-border bg-white space-y-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="px-2.5 py-1 bg-danger-bg text-danger-text text-[10px] font-extrabold uppercase rounded-full border border-danger-text/60">
                  {etiquetaDe(ETIQUETA_DENUNCIA, d.type)}
                </span>
                <time className="text-[11px] text-slate-400 font-semibold">{fecha(d.created_at)}</time>
              </div>
              <p className="text-sm text-text-1 whitespace-pre-wrap">{d.details}</p>
              <p className="text-[11px] text-text-3 font-semibold">
                Sobre <strong className="text-text-2">{d.accused?.name || 'colaborador dado de baja'}</strong>
                {' · '}reporta <strong className="text-text-2">{d.reporter?.name || 'colaborador dado de baja'}</strong>
                <span className="text-slate-400"> (confidencial: la persona reportada no ve quién reporta)</span>
              </p>
            </article>
          ))}
        </div>
      )}

      {!cargando && !error && pestana === 'anonimo' && (
        <div className="space-y-3">
          {anonimoBloqueado && (
            <p className="p-8 text-center text-xs font-bold text-text-3 bg-page rounded-2xl border border-border flex items-center justify-center gap-2">
              <Lock size={14} /> El buzón anónimo sólo lo lee el administrador de la empresa.
            </p>
          )}
          {!anonimoBloqueado && anonimos.length === 0 && (
            <p className="p-8 text-center text-xs font-bold text-text-3 bg-page rounded-2xl border border-border">
              El buzón anónimo está vacío.
            </p>
          )}
          {!anonimoBloqueado && anonimos.map(a => (
            <article key={a.id} className="p-4 rounded-2xl border border-border bg-white space-y-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="px-2.5 py-1 bg-navy-50 text-accent text-[10px] font-extrabold uppercase rounded-full border border-border/60">
                  {etiquetaDe(ETIQUETA_ANONIMO, a.type)}
                </span>
                <time className="text-[11px] text-slate-400 font-semibold">{fecha(a.created_at)}</time>
              </div>
              <p className="text-sm text-text-1 whitespace-pre-wrap">{a.content}</p>
              <p className="text-[11px] text-slate-400 font-semibold">Anónimo: el sistema no guarda quién lo envió.</p>
            </article>
          ))}
        </div>
      )}

      {!cargando && !error && pestana === 'evaluacion' && (
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <label htmlFor="ciclo-360" className="text-xs font-bold text-text-2">Ciclo</label>
            <input
              id="ciclo-360"
              type="month"
              value={ciclo}
              onChange={e => setCiclo(e.target.value || mesActual())}
              className="px-3 py-1.5 border border-border rounded-xl text-xs font-bold text-text-2"
            />
            <span className="text-[11px] text-slate-400 font-semibold">Promedios de 1 a 5 · el evaluado nunca ve quién lo calificó.</span>
          </div>
          {puntajes.length === 0 && (
            <p className="p-8 text-center text-xs font-bold text-text-3 bg-page rounded-2xl border border-border">
              Nadie ha sido evaluado en este ciclo.
            </p>
          )}
          {puntajes.length > 0 && (
            <div className="overflow-x-auto rounded-2xl border border-border">
              <table className="w-full border-collapse text-left text-xs">
                <thead>
                  <tr className="bg-page border-b border-border">
                    <th className="py-3 px-4 font-black text-text-3 uppercase tracking-wider">Colaborador</th>
                    <th className="py-3 px-3 font-black text-text-3 uppercase tracking-wider text-center">Evaluaciones</th>
                    <th className="py-3 px-3 font-black text-text-3 uppercase tracking-wider text-center">Equipo</th>
                    <th className="py-3 px-3 font-black text-text-3 uppercase tracking-wider text-center">Actitud</th>
                    <th className="py-3 px-3 font-black text-text-3 uppercase tracking-wider text-center">Desempeño</th>
                    <th className="py-3 px-3 font-black text-text-3 uppercase tracking-wider text-center">Liderazgo</th>
                    <th className="py-3 px-3 font-black text-text-2 uppercase tracking-wider text-center">General</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {puntajes.map(p => (
                    <tr key={p.user_id} className="hover:bg-page/60">
                      <td className="py-3 px-4">
                        <div className="font-bold text-text-1">{p.name}</div>
                        <div className="text-[10px] text-slate-400 font-semibold">{p.job_role || 'Sin puesto'}</div>
                      </td>
                      <td className="py-3 px-3 text-center font-bold text-text-2">{p.evaluations_received}</td>
                      <td className="py-3 px-3 text-center">{p.avg_teamwork}</td>
                      <td className="py-3 px-3 text-center">{p.avg_attitude}</td>
                      <td className="py-3 px-3 text-center">{p.avg_performance}</td>
                      <td className="py-3 px-3 text-center">{p.avg_leadership}</td>
                      <td className="py-3 px-3 text-center font-black text-accent">{p.overall_score}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
