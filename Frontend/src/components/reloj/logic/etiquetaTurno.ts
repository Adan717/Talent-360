/**
 * H16 (segunda jornada de regresión 2026-07-30): el encabezado del dial anunciaba
 * **"Turno de hoy: 09:00 - 18:00 hrs"** a un colaborador cuyo turno real era **11:20 - 19:23**.
 *
 * La causa era una discrepancia de grafía dentro del MISMO objeto: `useClockEngine` construye
 * cada configuración como `{ start, end, ... }`, y la etiqueta la leía como
 * `shiftConfigs[id]?.shiftStart` / `?.shiftEnd`. Esas dos claves nunca existen, así que la
 * etiqueta caía **siempre** a su literal `'09:00'`/`'18:00'` — para todos los colaboradores de
 * todas las empresas, sin importar su horario.
 *
 * No era cosmético: el backend calcula el retardo y la salida anticipada contra el turno REAL
 * del expediente (`employees.shiftStart/shiftEnd`). Con el turno 11:20–19:23, un colaborador que
 * leyera "18:00" en su propio dial y se fuera a esa hora se llevaba **83 minutos de salida
 * anticipada** estampados en su registro. La app le decía una hora y le cobraba por otra.
 *
 * El resto del motor ya leía bien (`?.start || currentUser?.shiftStart || '09:00'`, la cascada
 * de RelojVisual:841). Aquí se centraliza ESA cascada para que la etiqueta y los cálculos no
 * puedan volver a divergir.
 */

export interface ConfigTurno {
  start?: string | null;
  end?: string | null;
}

export interface DatosDelExpediente {
  shiftStart?: string | null;
  shiftEnd?: string | null;
}

export interface EtiquetaTurno {
  inicio: string;
  fin: string;
  /** `false` cuando no se conoce el turno y se está mostrando el valor por defecto. */
  esReal: boolean;
}

const POR_DEFECTO_INICIO = '09:00';
const POR_DEFECTO_FIN = '18:00';

/** "19:23:00" → "19:23"; el expediente los guarda con segundos y la etiqueta no los usa. */
function sinSegundos(valor?: string | null): string | null {
  if (typeof valor !== 'string') return null;
  const limpio = valor.trim();
  if (limpio === '') return null;
  const partes = limpio.split(':');
  return partes.length >= 2 ? `${partes[0]}:${partes[1]}` : limpio;
}

/**
 * Resuelve el turno a mostrar: primero lo que tenga el motor, luego el expediente del usuario y
 * sólo al final el valor por defecto.
 */
export function resolverEtiquetaTurno(
  config?: ConfigTurno | null,
  usuario?: DatosDelExpediente | null,
): EtiquetaTurno {
  const inicio = sinSegundos(config?.start) ?? sinSegundos(usuario?.shiftStart);
  const fin = sinSegundos(config?.end) ?? sinSegundos(usuario?.shiftEnd);

  return {
    inicio: inicio ?? POR_DEFECTO_INICIO,
    fin: fin ?? POR_DEFECTO_FIN,
    // Sólo es "real" si AMBOS extremos se conocen: media etiqueta real y media inventada es
    // justo lo que hacía creer que el dato era de fiar.
    esReal: inicio !== null && fin !== null,
  };
}
