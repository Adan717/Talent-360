import { useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, FileText, X } from 'lucide-react';

/**
 * Panel de la PROPUESTA que devuelve `POST /admin/lft/leer-reglamento` (Plan A3, 2026-09-07).
 *
 * La IA leyó el reglamento y propone valores con su cita. Aquí el admin ve, para cada regla, lo
 * que propone la IA frente a lo que tiene hoy, y decide qué cargar en el formulario. Cargar NO
 * guarda: sólo llena los campos de la pantalla de LFT; guardar sigue siendo el botón de siempre,
 * con la validación completa del servidor. "Propone la IA, confirma el admin" — nunca al revés.
 */

export interface ReglaPropuesta {
  valor: number | boolean | string;
  cita: string;
  confianza: 'alta' | 'media' | 'baja' | string;
}

export interface Propuesta {
  fuente: { nombre: string; caracteres: number };
  propuesta: Record<string, ReglaPropuesta>;
  articulos: { referencia: string; resumen: string }[];
  advertencias: string[];
  descartadas: string[];
}

export const ETIQUETAS: Record<string, string> = {
  late_tolerance_minutes: 'Tolerancia de entrada (min)',
  meal_tolerance_minutes: 'Tolerancia al volver de comer (min)',
  rest_tolerance_minutes: 'Tolerancia en descansos (min)',
  lates_per_absence: 'Retardos que hacen una falta',
  absences_for_warning: 'Faltas para llamada de atención',
  absences_for_suspension: 'Faltas para suspensión',
  deduct_absence_day: 'La falta descuenta el día',
  proportional_rest_day: 'Séptimo día proporcional',
  paid_rest_day: 'Día de descanso pagado',
  late_action_mode: 'Qué pasa con el retardo',
  overtime_weekly_cap_minutes: 'Tope de tiempo extra por semana (min)',
};

export function formatearValor(clave: string, valor: unknown): string {
  if (typeof valor === 'boolean') return valor ? 'Sí' : 'No';
  if (clave === 'late_action_mode') return valor === 'extend_shift' ? 'Se repone al final del turno' : 'Se descuenta';
  return String(valor ?? '—');
}

/** Sólo las claves que la pantalla sabe pintar, en el orden de la tabla. */
export function reglasPresentables(propuesta: Record<string, ReglaPropuesta> | undefined): [string, ReglaPropuesta][] {
  if (!propuesta) return [];
  return Object.keys(ETIQUETAS)
    .filter(clave => propuesta[clave] !== undefined)
    .map(clave => [clave, propuesta[clave]]);
}

interface Props {
  propuesta: Propuesta;
  valoresActuales: Record<string, unknown>;
  onAplicar: (seleccion: Record<string, number | boolean | string>) => void;
  onDescartar: () => void;
}

export default function PropuestaDeReglamento({ propuesta, valoresActuales, onAplicar, onDescartar }: Props) {
  const filas = useMemo(() => reglasPresentables(propuesta.propuesta), [propuesta]);

  // De fábrica se marcan las de confianza alta y media; las de confianza baja las decide el admin.
  const [marcadas, setMarcadas] = useState<Record<string, boolean>>(() => {
    const inicial: Record<string, boolean> = {};
    for (const [clave, regla] of filas) inicial[clave] = regla.confianza !== 'baja';
    return inicial;
  });

  const seleccionadas = filas.filter(([clave]) => marcadas[clave]);

  const aplicar = () => {
    const seleccion: Record<string, number | boolean | string> = {};
    for (const [clave, regla] of seleccionadas) seleccion[clave] = regla.valor;
    onAplicar(seleccion);
  };

  const colorConfianza = (c: string) =>
    c === 'alta' ? 'bg-success-bg text-success-text' : c === 'media' ? 'bg-warning-bg text-warning-text' : 'bg-slate-200 text-text-2';

  return (
    <div className="mt-4 p-4 bg-white border border-warning-text/20 rounded-2xl space-y-3 text-left text-[11px]" role="region" aria-label="Propuesta del reglamento">
      <div className="flex items-start justify-between gap-2">
        <div className="font-black text-text-1 flex items-center gap-1.5">
          <FileText size={14} className="text-warning-text" />
          Propuesta leída de: {propuesta.fuente.nombre}
          <span className="text-slate-400 font-semibold">({propuesta.fuente.caracteres.toLocaleString('es-MX')} caracteres)</span>
        </div>
        <button type="button" onClick={onDescartar} className="text-slate-400 hover:text-text-2 bg-transparent border-none cursor-pointer" title="Descartar propuesta">
          <X size={14} />
        </button>
      </div>

      <p className="text-text-3 font-medium">
        Nada se ha guardado. Marca lo que quieras cargar en el formulario, revísalo y después pulsa <strong>Guardar</strong> como siempre.
      </p>

      {filas.length === 0 && (
        <p className="p-3 bg-page rounded-xl text-text-3 font-semibold">
          La IA no encontró en el texto ninguna de las reglas que esta pantalla configura.
        </p>
      )}

      {filas.length > 0 && (
        <div className="overflow-x-auto rounded-xl border border-border">
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="bg-page border-b border-border text-[10px] uppercase tracking-wider text-text-3 font-black">
                <th className="py-2 px-3">Cargar</th>
                <th className="py-2 px-3">Regla</th>
                <th className="py-2 px-3">Propone</th>
                <th className="py-2 px-3">Hoy</th>
                <th className="py-2 px-3">Confianza</th>
                <th className="py-2 px-3">Cita del reglamento</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {filas.map(([clave, regla]) => (
                <tr key={clave}>
                  <td className="py-2 px-3">
                    <input
                      type="checkbox"
                      aria-label={'Cargar ' + ETIQUETAS[clave]}
                      checked={!!marcadas[clave]}
                      onChange={() => setMarcadas(prev => ({ ...prev, [clave]: !prev[clave] }))}
                      className="w-4 h-4 rounded border-slate-300 text-warning-text cursor-pointer"
                    />
                  </td>
                  <td className="py-2 px-3 font-bold text-text-1">{ETIQUETAS[clave]}</td>
                  <td className="py-2 px-3 font-black text-warning-text">{formatearValor(clave, regla.valor)}</td>
                  <td className="py-2 px-3 text-text-3">{formatearValor(clave, valoresActuales[clave])}</td>
                  <td className="py-2 px-3">
                    <span className={'px-1.5 py-0.5 rounded-md font-black uppercase text-[9px] ' + colorConfianza(regla.confianza)}>
                      {regla.confianza}
                    </span>
                  </td>
                  <td className="py-2 px-3 text-text-3 italic max-w-[320px]">{regla.cita ? '«' + regla.cita + '»' : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {propuesta.advertencias.length > 0 && (
        <div className="p-3 bg-warning-bg border border-warning-text/20 rounded-xl text-warning-text space-y-1">
          <div className="font-black flex items-center gap-1.5"><AlertTriangle size={12} /> Ojo:</div>
          <ul className="list-disc pl-4">
            {propuesta.advertencias.map((a, i) => <li key={i}>{a}</li>)}
          </ul>
        </div>
      )}

      {propuesta.articulos.length > 0 && (
        <details className="text-text-3">
          <summary className="cursor-pointer font-bold">Artículos que la IA identificó ({propuesta.articulos.length})</summary>
          <ul className="mt-1 space-y-0.5 pl-4 list-disc">
            {propuesta.articulos.map((art, i) => <li key={i}><strong>{art.referencia}:</strong> {art.resumen}</li>)}
          </ul>
        </details>
      )}

      <div className="flex items-center justify-end gap-2 pt-1">
        <button type="button" onClick={onDescartar} className="px-3 py-2 rounded-xl text-text-2 hover:bg-page font-bold bg-transparent border-none cursor-pointer">
          Descartar
        </button>
        <button
          type="button"
          onClick={aplicar}
          disabled={seleccionadas.length === 0}
          className="px-4 py-2 rounded-xl bg-warning-text hover:bg-warning-text disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-black flex items-center gap-1.5 border-none cursor-pointer"
        >
          <CheckCircle2 size={14} /> Cargar {seleccionadas.length} en el formulario
        </button>
      </div>
    </div>
  );
}
