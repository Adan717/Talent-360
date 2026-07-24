import React, { useCallback, useEffect, useRef, useState } from 'react';
import axiosInstance from '../../lib/axios';

/**
 * Panel del admin/supervisor para ver y resolver incidentes ACTIVOS del Botón de Pánico (R80).
 * Cierra el lazo de la alerta: sin este panel, el push del mando era un callejón sin salida y un
 * incidente huérfano (reload del dispositivo, doble-tap) quedaba 'active' para siempre.
 *
 * Componente TIPADO y autocontenido (patrón LateAuthorizationsPanel, R57). El llamador lo monta
 * sólo para quien tiene autoridad; el backend gatea la ruta a admin/supervisor de todos modos.
 *
 * Consume: GET /admin/panic-incidents · POST /clock/panic/{id}/resolve (R80).
 */

interface PanicIncident {
  id: number;
  user_id: number;
  category: string;
  description: string | null;
  latitude: number | null;
  longitude: number | null;
  created_at: string;
  employee_name: string | null;
}

// Espejo del catálogo canónico del backend (PanicController::CATEGORIES).
const CATEGORY_LABELS: Record<string, string> = {
  robo_asalto: 'Robo / Asalto',
  incendio: 'Incendio',
  emergencia_medica: 'Emergencia Médica',
  fallo_energia: 'Fallo General de Energía',
};

const POLL_MS = 15000;

export const PanicIncidentsPanel = () => {
  const [incidents, setIncidents] = useState<PanicIncident[]>([]);
  const [resolvingId, setResolvingId] = useState<number | null>(null);
  const mounted = useRef(true);

  const fetchActive = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/panic-incidents');
      if (mounted.current && Array.isArray(res.data)) {
        setIncidents(res.data);
      }
    } catch {
      // Error transitorio: se CONSERVA la lista previa (una emergencia activa no debe desaparecer
      // del panel por un blip de red); el próximo sondeo se recupera.
    }
  }, []);

  useEffect(() => {
    mounted.current = true;
    fetchActive();
    const t = setInterval(fetchActive, POLL_MS);
    return () => {
      mounted.current = false;
      clearInterval(t);
    };
  }, [fetchActive]);

  const resolve = async (id: number) => {
    setResolvingId(id);
    try {
      await axiosInstance.post(`/clock/panic/${id}/resolve`);
      if (mounted.current) setIncidents(prev => prev.filter(i => i.id !== id));
    } catch {
      // 422 = ya resuelto por otro (el sondeo lo quitará); otros errores: se conserva y se
      // reintenta en el próximo sondeo. NO se quita optimista: es una emergencia, no una cola.
      fetchActive();
    } finally {
      if (mounted.current) setResolvingId(null);
    }
  };

  if (incidents.length === 0) return null;

  return (
    <div className="bg-rose-700 text-white rounded-2xl p-4 shadow-lg mb-3 flex flex-col gap-3 text-left border border-rose-500/20 animate-pulse-slow">
      <div className="flex items-center gap-2">
        <span className="text-xl">🚨</span>
        <div>
          <p className="font-black text-xs sm:text-sm">Emergencias Activas (Botón de Pánico)</p>
          <p className="text-[9px] sm:text-[10px] text-rose-100 opacity-90 leading-tight">
            Incidentes declarados desde el reloj que siguen sin resolver
          </p>
        </div>
      </div>
      <div className="space-y-2 max-h-40 overflow-y-auto pr-1">
        {incidents.map(i => (
          <div
            key={i.id}
            className="bg-rose-800/40 border border-rose-500/30 rounded-xl p-2.5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2.5"
          >
            <div className="flex flex-col text-left">
              <span className="text-xs font-black text-white">
                {CATEGORY_LABELS[i.category] || i.category}
              </span>
              <span className="text-[10px] text-rose-100">
                {i.employee_name || 'Colaborador'} ·{' '}
                {new Date(i.created_at.replace(' ', 'T')).toLocaleTimeString('es-MX', {
                  hour: '2-digit',
                  minute: '2-digit',
                })}
                {i.latitude != null && i.longitude != null && (
                  <>
                    {' · '}
                    <a
                      href={`https://www.google.com/maps?q=${i.latitude},${i.longitude}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-white underline font-bold"
                    >
                      ver ubicación
                    </a>
                  </>
                )}
              </span>
            </div>
            <button
              onClick={() => resolve(i.id)}
              disabled={resolvingId === i.id}
              className="bg-white/90 hover:bg-white text-rose-700 font-extrabold text-[9.5px] px-2.5 py-1.5 rounded-lg border-none cursor-pointer shadow-sm transition-all active:scale-95 disabled:opacity-50 shrink-0"
            >
              ✓ Marcar resuelto
            </button>
          </div>
        ))}
      </div>
    </div>
  );
};

export default PanicIncidentsPanel;
