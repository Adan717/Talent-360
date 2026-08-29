import React, { useRef, useState } from 'react';
import { Upload, Download, X, AlertTriangle, CheckCircle2, Info } from 'lucide-react';
import axiosInstance from '../lib/axios';

/**
 * Importación masiva de plantilla desde CSV (2026-08-28).
 *
 * Sin esto, dar de alta a un cliente de cuarenta personas es capturarlas una por una.
 *
 * EL FLUJO ES DE DOS PASOS A PROPÓSITO: primero se REVISA (el servidor no escribe nada y devuelve
 * renglón por renglón qué se daría de alta, qué está mal y qué conviene saber), y sólo entonces
 * aparece el botón de dar de alta. Sobre datos de personas, "ver antes de hacer" no es una
 * cortesía: un archivo mal armado da de alta a gente con el puesto equivocado o el sueldo de
 * otro, y deshacerlo a mano cuesta más que capturarlos.
 *
 * Consume: GET /employees/import/plantilla.csv · POST /employees/import/revisar · POST /employees/import
 */

interface Renglon {
  renglon: number;
  nombre: string;
  correo: string | null;
  puesto: string | null;
  fecha_ingreso: string | null;
  sueldo: number | null;
  problemas: string[];
  avisos: string[];
}

interface Veredicto {
  renglones: Renglon[];
  errores: string[];
  resumen: {
    en_el_archivo: number;
    listos: number;
    con_problema: number;
    con_aviso: number;
    plantilla_actual: number;
  };
}

export const ImportarPlantilla = ({ onCerrar, onImportado }: { onCerrar: () => void; onImportado: () => void }) => {
  const [csv, setCsv] = useState<string>('');
  const [nombreArchivo, setNombreArchivo] = useState<string>('');
  const [veredicto, setVeredicto] = useState<Veredicto | null>(null);
  const [trabajando, setTrabajando] = useState(false);
  const [error, setError] = useState<string>('');
  const [hecho, setHecho] = useState<string>('');
  const inputArchivo = useRef<HTMLInputElement>(null);

  const descargarPlantilla = async () => {
    try {
      const res = await axiosInstance.get('/employees/import/plantilla.csv', { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: 'text/csv;charset=utf-8' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'plantilla_colaboradores.csv';
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      setError('No se pudo descargar la plantilla. Intenta de nuevo.');
    }
  };

  const tomarArchivo = (e: React.ChangeEvent<HTMLInputElement>) => {
    const archivo = e.target.files?.[0];
    if (!archivo) return;
    setError('');
    setHecho('');
    setVeredicto(null);
    setNombreArchivo(archivo.name);
    const lector = new FileReader();
    lector.onload = () => setCsv(String(lector.result || ''));
    lector.onerror = () => setError('No se pudo leer el archivo.');
    lector.readAsText(archivo, 'UTF-8');
  };

  const revisar = async () => {
    if (!csv.trim()) {
      setError('Elige primero un archivo CSV.');
      return;
    }
    setTrabajando(true);
    setError('');
    try {
      const res = await axiosInstance.post('/employees/import/revisar', { csv });
      setVeredicto(res.data);
    } catch (e: any) {
      setError(e?.response?.data?.message || 'No se pudo revisar el archivo.');
    } finally {
      setTrabajando(false);
    }
  };

  const importar = async () => {
    setTrabajando(true);
    setError('');
    try {
      const res = await axiosInstance.post('/employees/import', { csv });
      setHecho(res.data?.message || 'Plantilla importada.');
      setVeredicto(null);
      setCsv('');
      setNombreArchivo('');
      onImportado();
    } catch (e: any) {
      // El servidor devuelve el veredicto completo con el 422: se vuelve a pintar para que
      // se vea QUÉ corregir, en vez de un "falló" a secas.
      if (e?.response?.data?.renglones) setVeredicto(e.response.data);
      setError(e?.response?.data?.message || 'No se pudo importar la plantilla.');
    } finally {
      setTrabajando(false);
    }
  };

  const puedeImportar = veredicto && veredicto.errores.length === 0 && veredicto.resumen.en_el_archivo > 0;

  return (
    <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto p-6 shadow-2xl">
        <div className="flex items-start justify-between mb-1">
          <h2 className="text-xl font-extrabold text-slate-900">Importar plantilla desde un archivo</h2>
          <button onClick={onCerrar} className="text-slate-400 hover:text-slate-700 border-none bg-transparent cursor-pointer">
            <X size={20} />
          </button>
        </div>
        <p className="text-sm text-slate-500 mb-5">
          Da de alta a todo tu equipo de una vez. Primero se revisa el archivo y se te muestra qué
          pasaría; nada se guarda hasta que tú lo confirmes.
        </p>

        <div className="flex flex-col sm:flex-row gap-2 mb-5">
          <button
            onClick={descargarPlantilla}
            className="flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-300 bg-white text-slate-700 text-sm font-bold hover:bg-slate-50 cursor-pointer"
          >
            <Download size={16} /> Descargar plantilla de ejemplo
          </button>
          <button
            onClick={() => inputArchivo.current?.click()}
            className="flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 text-white text-sm font-bold hover:bg-slate-800 cursor-pointer border-none"
          >
            <Upload size={16} /> {nombreArchivo || 'Elegir archivo CSV'}
          </button>
          <input ref={inputArchivo} type="file" accept=".csv,text/csv" onChange={tomarArchivo} className="hidden" />
        </div>

        {csv && !veredicto && (
          <button
            onClick={revisar}
            disabled={trabajando}
            className="w-full px-4 py-3 rounded-xl bg-blue-600 text-white font-bold hover:bg-blue-700 disabled:opacity-50 cursor-pointer border-none"
          >
            {trabajando ? 'Revisando…' : 'Revisar el archivo'}
          </button>
        )}

        {error && (
          <div className="mt-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm font-semibold flex items-start gap-2">
            <AlertTriangle size={16} className="mt-0.5 shrink-0" /> {error}
          </div>
        )}

        {hecho && (
          <div className="mt-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-start gap-2">
            <CheckCircle2 size={16} className="mt-0.5 shrink-0" /> {hecho}
          </div>
        )}

        {veredicto && (
          <div className="mt-5 space-y-4">
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
              {[
                ['En el archivo', veredicto.resumen.en_el_archivo, 'text-slate-900'],
                ['Listos para dar de alta', veredicto.resumen.listos, 'text-emerald-700'],
                ['Con problema', veredicto.resumen.con_problema, 'text-rose-700'],
                ['Ya en la empresa', veredicto.resumen.plantilla_actual, 'text-slate-500'],
              ].map(([etiqueta, valor, color]) => (
                <div key={String(etiqueta)} className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                  <p className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{etiqueta}</p>
                  <p className={`text-xl font-black ${color}`}>{valor}</p>
                </div>
              ))}
            </div>

            {veredicto.errores.length > 0 && (
              <div className="p-3 rounded-xl bg-rose-50 border border-rose-200">
                <p className="text-xs font-black text-rose-900 uppercase tracking-wider mb-1.5">
                  Corrige esto en el archivo y vuelve a subirlo
                </p>
                {/* Todo o nada: si un renglón está mal, no se da de alta a nadie. Media
                    plantilla dentro es peor — nadie sabría quién quedó y reintentar duplica. */}
                <p className="text-[11px] text-rose-700 mb-2">
                  Mientras haya un renglón con problema no se da de alta a nadie, para que no quede
                  media plantilla cargada.
                </p>
                <ul className="space-y-1 max-h-40 overflow-y-auto">
                  {veredicto.errores.map((e, i) => (
                    <li key={i} className="text-xs text-rose-800 font-semibold">· {e}</li>
                  ))}
                </ul>
              </div>
            )}

            <div className="border border-slate-200 rounded-xl overflow-hidden">
              <div className="overflow-x-auto max-h-64">
                <table className="w-full text-xs">
                  <thead className="bg-slate-50 sticky top-0">
                    <tr>
                      {['#', 'Nombre', 'Correo', 'Puesto', 'Ingreso', 'Sueldo', 'Notas'].map(h => (
                        <th key={h} className="text-left px-2.5 py-2 font-bold text-slate-600 whitespace-nowrap">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {veredicto.renglones.map(r => (
                      <tr key={r.renglon} className={`border-t border-slate-100 ${r.problemas.length ? 'bg-rose-50/60' : ''}`}>
                        <td className="px-2.5 py-1.5 text-slate-400 font-mono">{r.renglon}</td>
                        <td className="px-2.5 py-1.5 font-semibold text-slate-800 whitespace-nowrap">{r.nombre || '—'}</td>
                        <td className="px-2.5 py-1.5 text-slate-500">{r.correo || <span className="text-slate-400">sin correo</span>}</td>
                        <td className="px-2.5 py-1.5 text-slate-500">{r.puesto || '—'}</td>
                        <td className="px-2.5 py-1.5 text-slate-500 whitespace-nowrap">{r.fecha_ingreso || '—'}</td>
                        <td className="px-2.5 py-1.5 text-slate-500 text-right tabular-nums">
                          {r.sueldo !== null ? r.sueldo.toLocaleString('es-MX') : '—'}
                        </td>
                        <td className="px-2.5 py-1.5">
                          {r.problemas.map((p, i) => (
                            <span key={`p${i}`} className="block text-rose-700 font-semibold">{p}</span>
                          ))}
                          {r.avisos.map((a, i) => (
                            <span key={`a${i}`} className="block text-amber-700">{a}</span>
                          ))}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {veredicto.resumen.con_aviso > 0 && veredicto.errores.length === 0 && (
              <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold flex items-start gap-2">
                <Info size={14} className="mt-0.5 shrink-0" />
                Hay avisos, pero ninguno impide el alta: quien no trae correo entrará por el kiosco
                con su PIN, y a quien no trae sueldo no se le calculará pre-nómina hasta que se lo
                captures.
              </div>
            )}

            <div className="flex flex-col sm:flex-row gap-2">
              <button
                onClick={importar}
                disabled={!puedeImportar || trabajando}
                className="flex-1 px-4 py-3 rounded-xl bg-emerald-600 text-white font-bold hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer border-none"
              >
                {trabajando
                  ? 'Dando de alta…'
                  : `Dar de alta a ${veredicto.resumen.listos} colaborador(es)`}
              </button>
              <button
                onClick={() => { setVeredicto(null); setCsv(''); setNombreArchivo(''); }}
                className="px-4 py-3 rounded-xl border border-slate-300 bg-white text-slate-700 font-bold hover:bg-slate-50 cursor-pointer"
              >
                Elegir otro archivo
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
