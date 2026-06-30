import React, { useState, useEffect } from 'react';
import { Share2, PlusSquare, X } from 'lucide-react';

export default function IOSInstallGuide() {
  const [showGuide, setShowGuide] = useState(false);

  useEffect(() => {
    // Detect if it is iOS (iPhone/iPad/iPod)
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !(window as any).MSStream;
    
    // Check if not in standalone (installed) mode
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || (navigator as any).standalone === true;
    
    // Check if user dismissed it in this session
    const isDismissed = sessionStorage.getItem('ios_install_guide_dismissed') === 'true';

    if (isIOS && !isStandalone && !isDismissed) {
      setShowGuide(true);
    }
  }, []);

  const handleDismiss = () => {
    sessionStorage.setItem('ios_install_guide_dismissed', 'true');
    setShowGuide(false);
  };

  if (!showGuide) return null;

  return (
    <div className="fixed bottom-4 left-4 right-4 z-[99] bg-slate-900/95 backdrop-blur border border-slate-750 text-white rounded-3xl p-5 shadow-2xl animate-fade-in-up flex flex-col gap-4 pointer-events-auto">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div className="flex items-center gap-3">
          <div className="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-2xl shadow-md border border-indigo-400/30">
            ⏳
          </div>
          <div>
            <h4 className="font-extrabold text-sm text-slate-100">Instala Talent360 en tu iPhone</h4>
            <p className="text-slate-400 text-[10px] mt-0.5">Ficha sin abrir el navegador</p>
          </div>
        </div>
        <button 
          onClick={handleDismiss}
          className="p-1.5 rounded-full bg-slate-800 hover:bg-slate-750 text-slate-400 transition"
        >
          <X className="w-3.5 h-3.5" />
        </button>
      </div>

      {/* Steps */}
      <div className="flex flex-col gap-3.5 text-[11px] border-t border-slate-800 pt-3.5">
        <div className="flex items-center gap-3">
          <div className="w-5 h-5 rounded-full bg-slate-800 flex items-center justify-center font-bold text-slate-300">
            1
          </div>
          <p className="text-slate-300 flex-1 leading-relaxed">
            Presiona el botón de **Compartir** <Share2 className="w-3.5 h-3.5 inline mx-0.5 text-sky-400" /> en Safari.
          </p>
        </div>
        
        <div className="flex items-center gap-3">
          <div className="w-5 h-5 rounded-full bg-slate-800 flex items-center justify-center font-bold text-slate-300">
            2
          </div>
          <p className="text-slate-300 flex-1 leading-relaxed">
            Desplázate y selecciona **"Añadir a la pantalla de inicio"** <PlusSquare className="w-3.5 h-3.5 inline mx-0.5 text-emerald-400" />.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <div className="w-5 h-5 rounded-full bg-slate-800 flex items-center justify-center font-bold text-slate-300">
            3
          </div>
          <p className="text-slate-300 flex-1 leading-relaxed">
            Abre **Talent360** directamente desde tu pantalla de inicio.
          </p>
        </div>
      </div>

      {/* Button to confirm */}
      <button 
        onClick={handleDismiss}
        className="w-full py-2.5 bg-slate-850 hover:bg-slate-800 text-slate-300 font-bold rounded-xl text-xs transition border border-slate-750 active:scale-98"
      >
        Entendido
      </button>
    </div>
  );
}
