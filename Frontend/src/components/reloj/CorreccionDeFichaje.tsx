import React, { useEffect, useState } from 'react';
import axiosInstance from '../../lib/axios';

/**
 * CORRECCIÓN DE FICHAJES — la cara visible de la bitácora inmutable (Capa 3, 2026-08-25).
 *
 * Tres piezas que se usan en las dos pantallas donde se ven fichajes, porque las dos audiencias
 * necesitan cosas distintas del mismo hecho:
 *
 *   · `EtiquetaCorregido`  — el aviso "⚠️ Corregido". Lo ve TAMBIÉN el colaborador, y no es un
 *      adorno: la persona tiene derecho a saber que le movieron un registro de su asistencia. Es
 *      la mitad visible de la transparencia que la ley espera; la otra mitad es el aviso privado
 *      que el servidor le manda a su reloj.
 *   · `HistoriaDeFichaje`  — quién lo corrigió, cuándo y por qué. Se puede LEER sin poder corregir:
 *      ver la evidencia no es moverla.
 *   · `BotonCorregirFichaje` — sólo se pinta con la capacidad `manage_punch_corrections`. Ocultar
 *      el botón no es la seguridad (ésa la pone el servidor con 403): es no ofrecer algo que va a
 *      ser rechazado, que es de las cosas que más confunden a quien usa el sistema.
 */

export const CAPACIDAD_CORREGIR = 'manage_punch_corrections';

/**
 * ¿Este usuario puede corregir fichajes? MISMA regla que el servidor
 * (`PermissionMiddleware::usuarioTiene`): el admin dueño pasa siempre —no puede quedarse fuera de
 * su propia empresa— y los demás por la capacidad concedida a su puesto.
 *
 * Se escribe una vez aquí y no en cada pantalla: dos copias de una regla de permisos acaban
 * discrepando, y entonces la pantalla ofrece un botón que el servidor rechaza (o al revés).
 */
export function puedeCorregirFichajes(currentUser: any, globalPermissions: string[] = []): boolean {
  if (!currentUser) return false;
  if (currentUser.role === 'admin' || currentUser.system_role === 'platform_admin') return true;
  return Array.isArray(globalPermissions) && globalPermissions.includes(CAPACIDAD_CORREGIR);
}

/** Un fichaje viene de una corrección si nació de ella. */
export function esFichajeCorregido(fichaje: any): boolean {
  return !!(fichaje?.creado_por_correccion_id ?? fichaje?.creadoPorCorreccionId);
}

// ---------------------------------------------------------------- la etiqueta

export const EtiquetaCorregido: React.FC<{ onVerHistoria?: () => void; compacta?: boolean }> = ({
  onVerHistoria,
  compacta = false,
}) => {
  const contenido = (
    <>
      <span aria-hidden="true">⚠️</span>
      <span>Corregido</span>
    </>
  );

  const clases =
    'inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-50 font-bold text-amber-700 ' +
    (compacta ? 'px-1.5 py-0.5 text-[9px]' : 'px-2 py-0.5 text-[10px]');

  if (!onVerHistoria) {
    return (
      <span className={clases} title="Este registro fue corregido por un administrador">
        {contenido}
      </span>
    );
  }

  return (
    <button
      type="button"
      onClick={onVerHistoria}
      className={clases + ' cursor-pointer hover:bg-amber-100 transition-colors'}
      title="Ver quién lo corrigió, cuándo y por qué"
    >
      {contenido}
    </button>
  );
};

// ---------------------------------------------------------------- la historia

interface HistoriaProps {
  fichajeId: number;
  onCerrar: () => void;
  isDark?: boolean;
}

export const HistoriaDeFichaje: React.FC<HistoriaProps> = ({ fichajeId, onCerrar, isDark = false }) => {
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [datos, setDatos] = useState<any>(null);

  useEffect(() => {
    let vivo = true;
    (async () => {
      try {
        const res = await axiosInstance.get(`/admin/punch-corrections/${fichajeId}/historia`);
        if (vivo) setDatos(res.data);
      } catch (e: any) {
        // Se dice qué pasó en vez de dejar el panel en blanco: un modal vacío parece un sistema
        // roto, y aquí lo que se está mirando es evidencia.
        if (vivo) setError(e?.response?.data?.message || 'No se pudo cargar la historia de este fichaje.');
      } finally {
        if (vivo) setCargando(false);
      }
    })();
    return () => {
      vivo = false;
    };
  }, [fichajeId]);

  const correccionPorId = (id: any) =>
    (datos?.correcciones || []).find((c: any) => Number(c.id) === Number(id));

  return (
    <div className="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4" onClick={onCerrar}>
      <div
        className={`w-full max-w-lg max-h-[80vh] overflow-y-auto rounded-2xl p-5 shadow-2xl ${
          isDark ? 'bg-slate-900 text-slate-100' : 'bg-white text-slate-800'
        }`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between mb-4">
          <div>
            <h3 className="font-black text-base">Historia de este registro</h3>
            <p className="text-xs text-slate-500 mt-0.5">
              Todo cambio queda registrado: es la evidencia con la que la empresa responde.
            </p>
          </div>
          <button
            type="button"
            onClick={onCerrar}
            aria-label="Cerrar"
            className="text-slate-400 hover:text-slate-600 text-xl leading-none border-none bg-transparent cursor-pointer"
          >
            ×
          </button>
        </div>

        {cargando && <p className="text-xs text-slate-500 py-6 text-center">Cargando…</p>}
        {error && <p className="text-xs text-rose-600 py-6 text-center">{error}</p>}

        {!cargando && !error && datos && (
          <ol className="space-y-3">
            {(datos.fichajes || []).map((f: any) => {
              const nacio = correccionPorId(f.creado_por_correccion_id);
              const murio = correccionPorId(f.anulado_por_correccion_id);

              return (
                <li
                  key={f.id}
                  className={`rounded-xl border p-3 ${
                    f.vigente
                      ? 'border-emerald-300 bg-emerald-50/60'
                      : isDark
                        ? 'border-slate-700 bg-slate-800/50'
                        : 'border-slate-200 bg-slate-50'
                  }`}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-black text-sm">
                      {String(f.time).slice(0, 5)}{' '}
                      <span className="font-medium text-slate-500 text-xs">{f.type}</span>
                    </span>
                    <span
                      className={`text-[10px] font-black px-2 py-0.5 rounded-full ${
                        f.vigente ? 'bg-emerald-600 text-white' : 'bg-slate-400 text-white'
                      }`}
                    >
                      {f.vigente ? 'VIGENTE' : 'anulado'}
                    </span>
                  </div>

                  {nacio && (
                    <p className="text-[11px] mt-2 leading-relaxed">
                      <span className="font-bold">Se creó por una corrección.</span>{' '}
                      {nacio.autorizado_por_nombre ? `Autorizó ${nacio.autorizado_por_nombre}. ` : ''}
                      Motivo: <span className="italic">{nacio.motivo}</span>
                    </p>
                  )}

                  {murio && (
                    <p className="text-[11px] mt-2 leading-relaxed text-slate-500">
                      <span className="font-bold">Se anuló.</span>{' '}
                      {murio.autorizado_por_nombre ? `Autorizó ${murio.autorizado_por_nombre}. ` : ''}
                      Motivo: <span className="italic">{murio.motivo}</span>
                    </p>
                  )}

                  {!nacio && !murio && (
                    <p className="text-[11px] mt-2 text-slate-500">Registro original del reloj, sin correcciones.</p>
                  )}
                </li>
              );
            })}
          </ol>
        )}
      </div>
    </div>
  );
};

// ---------------------------------------------------------------- corregir

interface BotonProps {
  fichaje: any;
  onCorregido?: () => void;
  isDark?: boolean;
}

export const BotonCorregirFichaje: React.FC<BotonProps> = ({ fichaje, onCorregido, isDark = false }) => {
  const [abierto, setAbierto] = useState(false);
  const [hora, setHora] = useState(String(fichaje?.time || '').slice(0, 5));
  const [motivo, setMotivo] = useState('');
  const [anular, setAnular] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const motivoCorto = motivo.trim().length < 10;

  const enviar = async () => {
    setEnviando(true);
    setError(null);
    try {
      await axiosInstance.post('/admin/punch-corrections', {
        time_entry_id: fichaje.id,
        motivo: motivo.trim(),
        ...(anular ? { anular: true } : { time: hora }),
      });
      setAbierto(false);
      setMotivo('');
      onCorregido?.();
    } catch (e: any) {
      setError(
        e?.response?.data?.errors?.motivo?.[0] ||
          e?.response?.data?.message ||
          'No se pudo corregir el fichaje.'
      );
    } finally {
      setEnviando(false);
    }
  };

  if (!abierto) {
    return (
      <button
        type="button"
        onClick={() => setAbierto(true)}
        className="text-[10px] font-bold text-indigo-600 hover:text-indigo-800 underline border-none bg-transparent cursor-pointer px-1"
      >
        Corregir
      </button>
    );
  }

  return (
    <div className="fixed inset-0 z-[200] flex items-center justify-center bg-black/50 p-4">
      <div
        className={`w-full max-w-md rounded-2xl p-5 shadow-2xl ${
          isDark ? 'bg-slate-900 text-slate-100' : 'bg-white text-slate-800'
        }`}
      >
        <h3 className="font-black text-base mb-1">Corregir registro de asistencia</h3>
        <p className="text-[11px] text-slate-500 mb-4 leading-relaxed">
          El registro original <strong>no se borra</strong>: queda archivado junto al motivo, y al
          colaborador se le avisa. Así es como esto sirve de prueba.
        </p>

        <label className="flex items-center gap-2 text-xs mb-3">
          <input type="checkbox" checked={anular} onChange={(e) => setAnular(e.target.checked)} />
          <span>Anular sin sustituir (fichaje duplicado o que no debió existir)</span>
        </label>

        {!anular && (
          <label className="block text-xs font-bold mb-3">
            Hora correcta
            <input
              type="time"
              value={hora}
              onChange={(e) => setHora(e.target.value)}
              className={`mt-1 w-full rounded-xl border px-3 py-2 text-sm ${
                isDark ? 'bg-slate-800 border-slate-700' : 'bg-white border-slate-300'
              }`}
            />
          </label>
        )}

        <label className="block text-xs font-bold mb-1">
          Motivo <span className="font-normal text-slate-500">(obligatorio, mínimo 10 caracteres)</span>
          <textarea
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            rows={3}
            placeholder="Ej.: el reloj de la sucursal iba 3 minutos adelantado."
            className={`mt-1 w-full rounded-xl border px-3 py-2 text-sm ${
              isDark ? 'bg-slate-800 border-slate-700' : 'bg-white border-slate-300'
            }`}
          />
        </label>
        <p className="text-[10px] text-slate-500 mb-3">
          Esto lo va a leer quien audite esta nómina. “ok” o “error” no explican nada.
        </p>

        {error && <p className="text-[11px] text-rose-600 mb-3">{error}</p>}

        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={() => setAbierto(false)}
            className="px-3 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-transparent cursor-pointer"
          >
            Cancelar
          </button>
          <button
            type="button"
            onClick={enviar}
            disabled={enviando || motivoCorto}
            className="px-4 py-2 rounded-xl text-xs font-black text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed border-none cursor-pointer"
          >
            {enviando ? 'Guardando…' : anular ? 'Anular registro' : 'Corregir registro'}
          </button>
        </div>
      </div>
    </div>
  );
};
