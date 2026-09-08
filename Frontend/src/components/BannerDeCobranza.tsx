import React from 'react';
import { AlertTriangle, CreditCard } from 'lucide-react';
import type { AvisoDeCobranza } from '../types';

/**
 * El banner de "pago pendiente" (Plan C4).
 *
 * NO decide nada: pinta lo que el servidor ya decidió. El texto, los días que quedan y la fecha
 * límite vienen de `/me` → `App\Support\AvisoDeCobranza`, que a su vez pregunta al MISMO motor que
 * apaga el reloj checador (`EstadoDeCobranza`). Si esta pantalla contara sus propios días de
 * gracia, acabaría prometiendo un plazo que el backend no respeta — el defecto que este proyecto
 * ya pagó con la tolerancia del reloj, con Ley Silla y con el exceso del comedor.
 *
 * Avisa, no bloquea: quien deja a la empresa sin registrar asistencia es `CheckTenantActive` con
 * `tenants.is_active`, y eso sólo lo escribe el barrido diario cuando la gracia se agotó.
 */
export const BannerDeCobranza: React.FC<{ aviso?: AvisoDeCobranza | null }> = ({ aviso }) => {
  if (!aviso) return null;

  const esApagon = aviso.tono === 'apagon';

  return (
    <div
      role="status"
      data-testid="banner-de-cobranza"
      className={`px-4 lg:px-8 py-3 border-b flex items-start gap-3 ${
        esApagon
          ? 'bg-red-50 border-red-200 text-red-900'
          : 'bg-amber-50 border-amber-200 text-amber-900'
      }`}
    >
      <div className="shrink-0 mt-0.5">
        {esApagon ? <AlertTriangle className="w-5 h-5" /> : <CreditCard className="w-5 h-5" />}
      </div>
      <div className="min-w-0">
        <p className="font-black text-sm leading-tight">{aviso.titulo}</p>
        <p className="text-xs font-medium mt-0.5 leading-snug">{aviso.mensaje}</p>
      </div>
    </div>
  );
};

export default BannerDeCobranza;
