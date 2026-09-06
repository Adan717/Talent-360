import { useEffect, useState } from 'react';
import axiosInstance from '../lib/axios';
import { leerTarifario, type Tarifario } from '../lib/tarifario';

/**
 * Trae el tabulador del servidor una sola vez (2026-09-05).
 *
 * `GET /public/tarifario` no pide sesión: la landing lo necesita antes de que exista cuenta.
 *
 * Si falla, el estado queda en `null` y quien lo use NO debe pintar precio alguno. Antes cada
 * pantalla llevaba sus propios números escritos a mano y por eso el mismo plan tenía cuatro
 * precios distintos según dónde se mirara; un "valor por defecto" aquí resucitaría ese defecto
 * con otro nombre.
 */
export const useTarifario = (): { tarifario: Tarifario | null; cargando: boolean } => {
  const [tarifario, setTarifario] = useState<Tarifario | null>(null);
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    let vigente = true;

    axiosInstance
      .get('/public/tarifario')
      .then(res => {
        if (vigente) setTarifario(leerTarifario(res.data));
      })
      .catch(e => {
        console.error('No se pudo leer el tarifario del servidor:', e);
      })
      .finally(() => {
        if (vigente) setCargando(false);
      });

    return () => {
      vigente = false;
    };
  }, []);

  return { tarifario, cargando };
};
