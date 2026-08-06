import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { ShieldCheck, ShieldX, Loader2, Search } from 'lucide-react';
import axiosInstance from '../lib/axios';

/**
 * Verificación pública de un certificado de la Academia por su folio.
 *
 * Es la mitad que le faltaba al certificado para significar algo. Antes de esto el papel era un
 * `window.print()` de un div: sin folio, sin registro y sin forma de comprobarlo — cualquiera
 * podía imprimir uno editando el HTML y la empresa no tenía cómo distinguirlo de uno real.
 * Ahora cada certificado deja registro con folio, y **quien lo recibe puede comprobarlo aquí, sin
 * cuenta ni sesión**, que es justo lo que hace falta cuando el papel lo enseña un candidato en
 * otra empresa.
 *
 * Sólo se muestra lo que YA está impreso en el papel —nombre, curso, empresa, fecha y
 * calificación—: es una página pública, no una ventana al expediente. El folio lleva 8 caracteres
 * aleatorios y la ruta va con límite de peticiones, así que no se pueden ir probando folios
 * ajenos.
 */

interface Certificado {
  valid: boolean;
  folio?: string;
  participant_name?: string;
  course_title?: string;
  company_name?: string | null;
  issued_at?: string;
  score?: number;
  message?: string;
}

export default function VerificarCertificado() {
  const { folio: folioDeLaUrl } = useParams();
  const [folio, setFolio] = useState(folioDeLaUrl || '');
  const [resultado, setResultado] = useState<Certificado | null>(null);
  const [buscando, setBuscando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const verificar = async (elFolio: string) => {
    const limpio = elFolio.trim().toUpperCase();
    if (!limpio) return;

    setBuscando(true);
    setError(null);
    setResultado(null);

    try {
      const { data } = await axiosInstance.get(`/public/certificates/${encodeURIComponent(limpio)}`);
      setResultado(data);
    } catch (e: any) {
      if (e?.response?.status === 404) {
        setResultado({ valid: false, message: e.response.data?.message });
      } else if (e?.response?.status === 429) {
        setError('Demasiadas consultas seguidas. Espera un momento y vuelve a intentar.');
      } else {
        setError('No se pudo consultar el certificado. Inténtalo de nuevo en un momento.');
      }
    } finally {
      setBuscando(false);
    }
  };

  // Con folio en la dirección se verifica solo: así el enlace se puede compartir tal cual.
  useEffect(() => {
    if (folioDeLaUrl) verificar(folioDeLaUrl);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [folioDeLaUrl]);

  const fecha = resultado?.issued_at
    ? new Date(resultado.issued_at + 'T12:00:00').toLocaleDateString('es-MX', {
        day: 'numeric', month: 'long', year: 'numeric'
      })
    : null;

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col items-center justify-center p-5">
      <div className="w-full max-w-lg">
        <div className="text-center mb-7">
          <h1 className="text-2xl font-black text-slate-900 tracking-tight">Verificar un certificado</h1>
          <p className="text-sm text-slate-500 font-medium mt-1.5 leading-relaxed">
            Escribe el folio impreso al pie del certificado para comprobar que fue emitido de verdad.
          </p>
        </div>

        <form
          onSubmit={e => { e.preventDefault(); verificar(folio); }}
          className="flex gap-2 mb-6"
        >
          <input
            value={folio}
            onChange={e => setFolio(e.target.value)}
            placeholder="TAL-2026-XXXXXXXX"
            className="flex-1 px-4 py-3.5 rounded-2xl border border-slate-200 bg-white text-slate-900 font-mono font-bold tracking-wider text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all uppercase"
          />
          <button
            type="submit"
            disabled={buscando || !folio.trim()}
            className="px-5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 text-white font-black text-sm transition-all flex items-center gap-2 shrink-0"
          >
            {buscando ? <Loader2 size={18} className="animate-spin" /> : <Search size={18} />}
            <span className="hidden sm:inline">Verificar</span>
          </button>
        </form>

        {error && (
          <div className="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-sm font-bold text-center">
            {error}
          </div>
        )}

        {resultado?.valid && (
          <div className="rounded-3xl border-2 border-emerald-200 bg-white p-6 shadow-sm animate-in fade-in duration-200">
            <div className="flex items-center gap-2.5 mb-5 pb-5 border-b border-slate-100">
              <ShieldCheck className="text-emerald-600 shrink-0" size={26} />
              <div>
                <p className="font-black text-emerald-700 leading-tight">Certificado válido</p>
                <p className="text-[11px] text-slate-400 font-mono font-bold tracking-wider">{resultado.folio}</p>
              </div>
            </div>

            <dl className="space-y-3.5">
              <div>
                <dt className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Otorgado a</dt>
                <dd className="text-slate-900 font-black text-lg leading-tight">{resultado.participant_name}</dd>
              </div>
              <div>
                <dt className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Curso</dt>
                <dd className="text-slate-800 font-bold text-sm">{resultado.course_title}</dd>
              </div>
              {resultado.company_name && (
                <div>
                  <dt className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Empresa</dt>
                  <dd className="text-slate-800 font-bold text-sm">{resultado.company_name}</dd>
                </div>
              )}
              <div className="flex gap-8">
                {fecha && (
                  <div>
                    <dt className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Expedido</dt>
                    <dd className="text-slate-800 font-bold text-sm">{fecha}</dd>
                  </div>
                )}
                <div>
                  <dt className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Calificación</dt>
                  <dd className="text-slate-800 font-bold text-sm">{resultado.score}%</dd>
                </div>
              </div>
            </dl>
          </div>
        )}

        {resultado && !resultado.valid && (
          <div className="rounded-3xl border-2 border-rose-200 bg-white p-6 text-center animate-in fade-in duration-200">
            <ShieldX className="text-rose-500 mx-auto mb-3" size={30} />
            <p className="font-black text-rose-700">No encontramos ese certificado</p>
            <p className="text-xs text-slate-500 font-semibold mt-1.5 leading-relaxed">
              Revisa que el folio esté copiado completo. Si sigue sin aparecer, no fue emitido por
              esta plataforma.
            </p>
          </div>
        )}

        <p className="text-center text-[11px] text-slate-400 font-semibold mt-7">
          Talent 360 · verificación pública de certificados
        </p>
      </div>
    </div>
  );
}
