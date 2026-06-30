import React from 'react';
import { Mic, X, MessageSquare } from 'lucide-react';

interface VoiceAssistantOverlayProps {
  isListening: boolean;
  activeFieldIndex: number;
  totalFields: number;
  activeFieldLabel: string;
  transcript: string;
  feedback: string;
  onClose: () => void;
}

export function VoiceAssistantOverlay({
  isListening,
  activeFieldIndex,
  totalFields,
  activeFieldLabel,
  transcript,
  feedback,
  onClose,
}: VoiceAssistantOverlayProps) {
  if (!isListening) return null;

  return (
    <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-[2px] z-50 flex items-center justify-center p-4 rounded-3xl animate-in fade-in duration-300">
      <div className="bg-white rounded-2xl p-6 max-w-sm w-full shadow-xl border border-slate-100 flex flex-col items-center text-center animate-scale-up">
        {/* Pulsing micro indicator */}
        <div className="relative mb-5 mt-2">
          <div className="absolute inset-0 bg-emerald-500/25 rounded-full animate-ping duration-1000"></div>
          <div className="w-16 h-16 bg-gradient-to-tr from-emerald-500 to-teal-500 text-white rounded-full flex items-center justify-center shadow-lg shadow-emerald-500/20 relative">
            <Mic size={28} className="animate-pulse" />
          </div>
        </div>

        {/* Step counter */}
        {activeFieldIndex >= 0 ? (
          <span className="text-[10px] bg-emerald-50 text-emerald-600 font-bold px-2 py-0.5 rounded-full uppercase tracking-wider mb-2">
            Paso {activeFieldIndex + 1} de {totalFields}
          </span>
        ) : (
          <span className="text-[10px] bg-blue-50 text-blue-600 font-bold px-2 py-0.5 rounded-full uppercase tracking-wider mb-2">
            Completado
          </span>
        )}

        {/* Header label */}
        <h3 className="font-extrabold text-slate-800 text-lg leading-tight mb-2">
          {activeFieldIndex >= 0 ? `Llenando: ${activeFieldLabel}` : 'Proceso Terminado'}
        </h3>

        {/* Feedback helper */}
        <p className="text-xs text-slate-500 bg-slate-50 border border-slate-100 p-3 rounded-xl w-full leading-normal mb-4 text-left flex items-start gap-2">
          <MessageSquare size={14} className="text-slate-400 shrink-0 mt-0.5" />
          <span>{feedback}</span>
        </p>

        {/* Live speech transcript */}
        <div className="w-full min-h-[50px] bg-slate-950 text-emerald-400 font-mono text-xs p-3.5 rounded-xl text-left border border-slate-800 flex flex-col gap-1 select-none mb-6">
          <span className="text-[9px] text-slate-600 font-sans uppercase font-bold tracking-wider">Escuchando...</span>
          <span className="italic text-slate-300">
            {transcript ? `"${transcript}"` : 'Habla ahora...'}
          </span>
        </div>

        {/* Close / cancel button */}
        <button
          onClick={onClose}
          type="button"
          className="w-full py-2.5 bg-slate-100 hover:bg-slate-200/80 active:scale-95 text-slate-700 font-bold rounded-xl transition-all duration-200 text-xs sm:text-sm flex items-center justify-center gap-1.5"
        >
          <X size={15} /> Detener Asistente
        </button>
      </div>
    </div>
  );
}
