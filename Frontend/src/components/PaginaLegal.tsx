import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { LEGAL_TABS, LegalBody, type LegalDocType } from './LegalModal';

/**
 * Página pública del aviso de privacidad — `/privacidad` (2026-09-05).
 *
 * POR QUÉ EXISTE: el propio aviso promete, por escrito, que sus actualizaciones se publican en
 * `https://talent360.com.mx/privacidad`. Esa URL no existía: caía en el comodín de rutas y pintaba
 * la landing. Una promesa legal contra una dirección inexistente es de las cosas que un abogado
 * contrario encuentra primero.
 *
 * SIN SESIÓN, a propósito: un colaborador que entra por el kiosco con su PIN nunca pasa por el
 * login, y es justamente quien tiene derecho a leerlo. Mismo criterio que `/certificado`.
 *
 * EL TEXTO NO VIVE AQUÍ. Se importa de `LegalModal` (`LegalBody` + `LEGAL_TABS`), que es la única
 * copia. Si algún día el abogado cambia una frase, cambia en el modal y en esta página a la vez,
 * o en ninguna.
 */
export const PaginaLegal: React.FC<{ pestanaInicial?: LegalDocType }> = ({ pestanaInicial = 'privacy' }) => {
  const [pestana, setPestana] = useState<LegalDocType>(pestanaInicial);
  const navigate = useNavigate();

  return (
    <div className="min-h-[100dvh] bg-slate-950 text-slate-100">
      <header className="border-b border-slate-800 bg-slate-900/60 backdrop-blur">
        <div className="max-w-4xl mx-auto px-5 py-4 flex items-center justify-between gap-4">
          <div>
            <p className="text-[10px] font-bold uppercase tracking-[0.18em] text-indigo-400">Talent 360</p>
            <h1 className="text-lg font-black text-white">Aviso de Privacidad y Términos</h1>
          </div>
          {/* A /inicio y NO a /app: en el dominio, la ruta /app la enruta Caddy al websocket
              de tiempo real, no a la aplicación. */}
          <button
            type="button"
            onClick={() => navigate('/inicio')}
            className="flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-700 text-slate-300 hover:text-white hover:bg-slate-800 text-xs font-bold transition cursor-pointer"
          >
            <ArrowLeft className="w-3.5 h-3.5" /> Volver
          </button>
        </div>
      </header>

      <nav className="max-w-4xl mx-auto px-5 pt-5">
        <div className="flex gap-2 overflow-x-auto pb-1">
          {LEGAL_TABS.map(({ id, label, Icono }) => (
            <button
              key={id}
              type="button"
              onClick={() => setPestana(id)}
              className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap ${
                pestana === id
                  ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60 border border-slate-800'
              }`}
            >
              <Icono className="w-4 h-4" />
              {label}
            </button>
          ))}
        </div>
      </nav>

      <main className="max-w-4xl mx-auto px-5 py-6">
        <LegalBody tab={pestana} />
      </main>

      <footer className="border-t border-slate-800 mt-8">
        <div className="max-w-4xl mx-auto px-5 py-5 flex flex-wrap items-center justify-between gap-3">
          <p className="text-[10px] text-slate-500 font-medium">
            Talent360 © 2026 — Plataforma Cumplimiento LFPDPPP &amp; LFT
          </p>
          <a
            href="mailto:privacidad@talent360.com.mx?subject=Solicitud%20ARCO%20-%20Talent360"
            className="text-[11px] font-bold text-indigo-400 hover:text-indigo-300"
          >
            privacidad@talent360.com.mx
          </a>
        </div>
      </footer>
    </div>
  );
};

export default PaginaLegal;
