import React, { useId } from 'react';

/**
 * Selector de la zona horaria de la empresa (`system_settings.timezone`).
 *
 * Existe como componente compartido porque el mismo ajuste se ofrece en DOS lugares —
 * "Perfil de la Empresa" (menú del avatar) y "Configuración → Reloj & Asistencia Global"— y
 * ambos escriben la MISMA llave con el mismo `updateSetting('timezone', ...)`. Duplicar la
 * lista de zonas en dos archivos era la vía segura a que una creciera y la otra no.
 *
 * De esta llave dependen los retardos y el corte del día en nómina (`TenantTimezone::for()`),
 * así que el servidor la valida: una zona que no existe devuelve 422 en `POST /sync/settings`.
 */
export const ZONAS_HORARIAS: ReadonlyArray<{ valor: string; etiqueta: string }> = [
  { valor: 'America/Mexico_City', etiqueta: 'Tiempo del Centro - CDMX, GDL, MTY (América/Mexico_City)' },
  { valor: 'America/Cancun', etiqueta: 'Tiempo del Sureste - Cancún, Q. Roo (América/Cancun)' },
  { valor: 'America/Tijuana', etiqueta: 'Tiempo del Noroeste - Tijuana, B.C. (América/Tijuana)' },
  { valor: 'America/Mazatlan', etiqueta: 'Tiempo del Pacífico - Mazatlán, Chihuahua, Sinaloa (América/Mazatlan)' },
  { valor: 'America/Hermosillo', etiqueta: 'Tiempo de Sonora - Hermosillo (América/Hermosillo)' },
];

interface SelectorZonaHorariaProps {
  value: string;
  onChange: (zona: string) => void;
  /** Clases del <select>; cada pantalla trae su propio estilo de formulario. */
  selectClassName?: string;
  labelClassName?: string;
  helpClassName?: string;
}

export const SelectorZonaHoraria: React.FC<SelectorZonaHorariaProps> = ({
  value,
  onChange,
  selectClassName = 'w-full px-4 py-3 bg-page border border-border rounded-xl focus:ring-2 focus-visible:ring-focus-ring focus:outline-none font-medium',
  labelClassName = 'block text-sm font-bold text-text-2 mb-2',
  helpClassName = 'text-xs text-slate-400 mt-2',
}) => {
  // Una empresa puede tener declarada una zona fuera de este catálogo (el comando
  // `tenants:fijar-zona-horaria` acepta cualquier zona IANA válida). Sin esta opción extra el
  // <select> se pintaría VACÍO y aparentaría que la empresa no tiene zona configurada.
  const fueraDelCatalogo = !!value && !ZONAS_HORARIAS.some(z => z.valor === value);

  // El <label> tiene que APUNTAR al <select>: sin htmlFor/id, pulsar la etiqueta no enfoca el
  // campo y un lector de pantalla lo anuncia sin nombre. (El control original, en el Perfil de
  // la Empresa, tampoco los tenía.)
  const idSelect = useId();

  return (
    <div>
      <label className={labelClassName} htmlFor={idSelect}>Zona Horaria del Reloj Checador</label>
      <select
        id={idSelect}
        value={value}
        onChange={e => onChange(e.target.value)}
        className={selectClassName}
      >
        {fueraDelCatalogo && <option value={value}>{value} (configurada fuera del catálogo)</option>}
        {ZONAS_HORARIAS.map(z => (
          <option key={z.valor} value={z.valor}>{z.etiqueta}</option>
        ))}
      </select>
      <p className={helpClassName}>
        Determina el huso horario oficial con el que se registrarán las entradas y salidas de los
        colaboradores. De ella dependen los retardos y el corte del día en la pre-nómina.
      </p>
    </div>
  );
};
