import React, { useState } from 'react';
import axiosInstance from '../../lib/axios';

/**
 * Botón "Solicitar autorización del administrador" (R57, la UI de R56). Se muestra en la pantalla de
 * bloqueo por Retardo Extremo (tolerancia vencida) como alternativa al desbloqueo con QR/PIN de
 * supervisor: el empleado pide autorización remota a un admin y, una vez aprobada, su siguiente
 * check_in ya no se bloquea (el enforcement es server-side, R56).
 *
 * Componente TIPADO y autocontenido (no vive en los @ts-nocheck). Consume:
 * POST /clock/request-late-authorization.
 */

type Estado = 'idle' | 'sending' | 'sent' | 'error';

export const SolicitarAutorizacionButton = () => {
  const [estado, setEstado] = useState<Estado>('idle');
  const [mensaje, setMensaje] = useState('');

  const solicitar = async () => {
    setEstado('sending');
    try {
      const res = await axiosInstance.post('/clock/request-late-authorization', {});
      setMensaje(res.data?.message || 'Solicitud enviada.');
      setEstado('sent');
    } catch (e: any) {
      setMensaje(e?.response?.data?.message || 'No se pudo enviar la solicitud. Intenta de nuevo.');
      setEstado('error');
    }
  };

  if (estado === 'sent') {
    return (
      <div className="mt-2 rounded-2xl bg-emerald-50 border border-emerald-100 p-3 text-center">
        <p className="text-[11px] font-black text-emerald-700">✓ Solicitud enviada</p>
        <p className="text-[10px] text-emerald-600 mt-1 leading-snug">
          Les llegó a los administradores y supervisores de tu empresa (la aprueban en Monitor 360 → Autorizaciones de entrada). Cuando la aprueben, cierra esta ventana y vuelve a registrar tu entrada.
        </p>
      </div>
    );
  }

  return (
    <div className="mt-2">
      <button
        onClick={solicitar}
        disabled={estado === 'sending'}
        className="w-full bg-sky-600 hover:bg-sky-700 text-white font-black py-3 rounded-2xl transition-colors border-none cursor-pointer text-xs uppercase tracking-wider disabled:opacity-60"
      >
        {estado === 'sending' ? 'Enviando…' : 'Solicitar autorización del administrador'}
      </button>
      {estado === 'error' && (
        <p className="text-[10px] text-rose-500 font-bold text-center mt-1.5">{mensaje}</p>
      )}
    </div>
  );
};

export default SolicitarAutorizacionButton;
