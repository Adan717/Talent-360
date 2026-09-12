import React from 'react';
import { ShieldCheck } from 'lucide-react';

/**
 * Los enlaces al aviso de privacidad, en un solo sitio (2026-09-05).
 *
 * POR QUÉ EXISTE: el aviso sólo se veía en pantallas PÚBLICAS (login, landing, portal de empleo).
 * Quien entra por el kiosco con su PIN nunca pasa por el login y podía trabajar meses sin verlo; y
 * el reloj abre la CÁMARA (foto de comedor) y la UBICACIÓN (geocerca) sin una sola línea que
 * dijera para qué. Estas dos piezas son las que se reparten por la aplicación.
 *
 * EL TEXTO NO VIVE AQUÍ: vive en `LegalModal.tsx` y se lee completo en `/privacidad` (pública, sin
 * sesión, a propósito). Aquí sólo se enlaza — nada de resúmenes propios que puedan contradecirlo.
 *
 * Siempre en PESTAÑA NUEVA: estos enlaces salen en medio de un fichaje o de una captura de foto;
 * navegar fuera perdería lo que la persona estaba haciendo.
 */
export const RUTA_AVISO = '/privacidad';

export const EnlaceAlAviso: React.FC<{ className?: string; children?: React.ReactNode }> = ({
  className = '',
  children = 'Aviso de Privacidad',
}) => (
  <a
    href={RUTA_AVISO}
    target="_blank"
    rel="noopener noreferrer"
    className={`underline underline-offset-2 font-bold hover:opacity-80 transition-opacity ${className}`}
  >
    {children}
  </a>
);

/**
 * La línea corta y NO BLOQUEANTE que acompaña a la cámara y a la ubicación.
 *
 * No pide permiso ni frena nada (el criterio del dueño es "nada bloquea, todo avisa", y frenar un
 * fichaje por un texto legal sería cobrarle a la persona un trámite): sólo dice qué se recoge y
 * para qué, con el enlace al aviso completo al lado.
 */
export const NotaDeDatos: React.FC<{ texto: string; className?: string }> = ({ texto, className = '' }) => (
  <p
    className={`flex items-start gap-1.5 text-[10px] leading-snug text-text-3 ${className}`}
    data-testid="nota-de-datos"
  >
    <ShieldCheck className="w-3 h-3 shrink-0 mt-[1px] text-slate-400" />
    <span>
      {texto} <EnlaceAlAviso className="text-accent">Ver el Aviso de Privacidad</EnlaceAlAviso>
    </span>
  </p>
);
