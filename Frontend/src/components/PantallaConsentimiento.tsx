import React, { useState } from 'react';
import { ShieldCheck, AlertTriangle } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { EnlaceAlAviso } from './AvisoDePrivacidad';

/**
 * Pantalla obligatoria de un toque: aceptar el Aviso de Privacidad (2026-09-05).
 *
 * MISMO MOLDE QUE EL CAMBIO FORZADO DE CONTRASEÑA, y por la misma razón: un banner que se cierra no
 * deja constancia de nada. Ante la LFPDPPP hay que poder probar QUIÉN aceptó QUÉ VERSIÓN y CUÁNDO,
 * y eso exige un acto afirmativo — no una casilla premarcada ni un aviso que se descarta.
 *
 * El servidor la respalda: hasta que se acepte, `RequiereAvisoDePrivacidad` responde 403 a todo lo
 * demás. Esta pantalla no es la que bloquea; es la salida del bloqueo.
 *
 * NO resume el texto legal: el resumen sería una segunda versión del aviso que puede contradecir a
 * la del abogado. Dice qué datos trata el sistema —lo que la persona necesita para decidir— y
 * enlaza el aviso íntegro, que se abre en otra pestaña para no perder esta.
 */
export const PantallaConsentimiento: React.FC<{
  nombre?: string;
  version?: string;
  onAceptado: () => void;
  onSalir?: () => void;
}> = ({ nombre, version, onAceptado, onSalir }) => {
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState('');

  const aceptar = async () => {
    setEnviando(true);
    setError('');
    try {
      await axiosInstance.post('/me/consentimiento');
      onAceptado();
    } catch (err: any) {
      setError(err?.response?.data?.error || err?.response?.data?.message || 'No se pudo registrar tu aceptación. Revisa tu conexión.');
      setEnviando(false);
    }
  };

  return (
    <div className="min-h-[100dvh] bg-slate-950 flex items-center justify-center p-4">
      <div className="w-full max-w-lg bg-white rounded-3xl shadow-2xl overflow-hidden">
        <div className="bg-slate-900 px-6 py-5 flex items-center gap-3">
          <div className="w-11 h-11 rounded-2xl bg-accent/15 border border-accent/30 flex items-center justify-center text-navy-100 shrink-0">
            <ShieldCheck className="w-5 h-5" />
          </div>
          <div>
            <h1 className="text-white font-black text-base leading-tight">Antes de continuar</h1>
            <p className="text-slate-400 text-xs font-medium">
              Aviso de Privacidad{version ? ` · versión ${version}` : ''}
            </p>
          </div>
        </div>

        <div className="p-6 space-y-4 text-text-2">
          <p className="text-sm font-semibold text-text-1">
            {nombre ? `${nombre}, para` : 'Para'} usar Talent 360 necesitamos tu consentimiento sobre el tratamiento de
            tus datos personales.
          </p>

          <ul className="text-xs space-y-2 bg-page border border-border rounded-2xl p-4">
            <li className="flex gap-2"><span className="text-accent font-black">·</span> Tus datos de identificación y laborales (nombre, CURP, RFC, NSS, puesto, horario y sueldo).</li>
            <li className="flex gap-2"><span className="text-accent font-black">·</span> Tus registros de asistencia, y la <strong>ubicación</strong> del dispositivo al fichar cuando tu empresa usa geocerca.</li>
            <li className="flex gap-2"><span className="text-accent font-black">·</span> Las <strong>fotografías</strong> que tomes como evidencia (comedor, tareas) desde la cámara del dispositivo.</li>
            <li className="flex gap-2"><span className="text-accent font-black">·</span> Tu avance y resultados en la Academia.</li>
          </ul>

          <p className="text-[11px] text-text-3 leading-relaxed">
            Esto es un resumen de lo que el sistema recoge; lo que rige es el texto completo, con la finalidad de cada
            dato, los plazos y tus derechos ARCO. <EnlaceAlAviso className="text-accent">Leer el Aviso de Privacidad completo</EnlaceAlAviso> (se abre en otra pestaña).
          </p>

          {error && (
            <div className="flex items-start gap-2 bg-danger-bg border border-danger-text/20 text-danger-text rounded-xl p-3 text-xs font-semibold">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" /> {error}
            </div>
          )}

          <button
            type="button"
            onClick={aceptar}
            disabled={enviando}
            className="w-full py-4 rounded-2xl bg-accent hover:bg-accent-hover disabled:bg-slate-300 text-white font-black text-sm transition-colors shadow-lg shadow-accent/20"
          >
            {enviando ? 'Registrando…' : 'He leído y acepto el Aviso de Privacidad'}
          </button>

          {onSalir && (
            <button
              type="button"
              onClick={onSalir}
              className="w-full text-center text-xs font-bold text-slate-400 hover:text-text-2 transition-colors"
            >
              Cerrar sesión
            </button>
          )}
        </div>
      </div>
    </div>
  );
};

export default PantallaConsentimiento;
