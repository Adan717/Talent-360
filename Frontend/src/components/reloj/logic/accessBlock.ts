/**
 * Regla pura del candado "Acceso Bloqueado" por tolerancia vencida (Retardo Extremo).
 *
 * Se extrajo de `useClockEngine.tsx` al corregir dos defectos encontrados probando el Reloj
 * en la instancia V2 (2026-07-29), porque la condición estaba enterrada en una cadena de
 * ~500 líneas de `if`s dentro del hook y no había forma de verificarla sin abrir la app a la
 * hora exacta del escenario:
 *
 *  - **H6**: el backend YA deja fichar a quien tiene la entrada tardía autorizada
 *    (`ClockService` consulta `late_authorization_requests` con status `approved`), pero el
 *    dial no conocía ese estado y seguía mostrando el candado: el colaborador autorizado se
 *    quedaba sin poder registrar su entrada aunque el servidor sí se lo permitía.
 *
 *  - **H7 (deadlock de apertura)**: el candado se evaluaba ANTES de la rama de tienda
 *    cerrada, así que el encargado de apertura que llegaba fuera de tolerancia nunca
 *    alcanzaba el botón de abrir la sucursal → nadie abría, y con la tienda cerrada NADIE
 *    del equipo podía fichar. El primer día de una empresa —o cualquier día en que el
 *    encargado llegue tarde— el Reloj quedaba inoperable hasta intervenir por backend.
 *
 * El retardo se sigue registrando server-side al fichar; lo que estas reglas evitan es
 * mostrar un candado que no ofrece ninguna salida.
 */

export interface AccessBlockInput {
  /** ¿El colaborador ya registró su entrada hoy? */
  hasCheckedIn: boolean;
  /** ¿Viene con retardo según la tolerancia de su puesto? */
  isLate: boolean;
  /** Estado del motor: sólo aplica en 'inactive' (aún no arranca la jornada). */
  clockState: string;
  /** Estado de la sucursal ('closed' = todavía nadie la abrió hoy). */
  storeStatus?: string;
  /** Id del colaborador que mira el dial. */
  currentUserId?: number | string | null;
  /** Id del responsable de la apertura de hoy. */
  responsibleId?: number | string | null;
  /** ¿Su puesto lo faculta para abrir la sucursal? (job_roles.esAperturador) */
  esAperturador?: boolean;
  /** Ids con autorización de entrada tardía APROBADA hoy (de /sync/state). */
  lateAuthorizedUserIds?: Array<number | string> | null;
}

/** ¿Este colaborador puede destrabar la sucursal cerrada abriéndola él mismo? */
export function canOpenClosedStore(input: AccessBlockInput): boolean {
  if (input.storeStatus !== 'closed') return false;
  const soyElResponsable =
    input.currentUserId != null &&
    input.responsibleId != null &&
    Number(input.currentUserId) === Number(input.responsibleId);
  return soyElResponsable || input.esAperturador === true;
}

/** ¿Tiene la entrada tardía ya autorizada por un mando? */
export function hasApprovedLateAuthorization(input: AccessBlockInput): boolean {
  if (input.currentUserId == null) return false;
  const ids = input.lateAuthorizedUserIds || [];
  return ids.some(id => Number(id) === Number(input.currentUserId));
}

/**
 * Decisión final: ¿se muestra el candado "Acceso Bloqueado / Tolerancia vencida"?
 */
export function shouldBlockForLateTolerance(input: AccessBlockInput): boolean {
  const aplicaElCandado =
    !input.hasCheckedIn && input.isLate && input.clockState === 'inactive';
  if (!aplicaElCandado) return false;

  // H6: ya lo autorizaron → el servidor lo deja fichar, el dial también.
  if (hasApprovedLateAuthorization(input)) return false;

  // H7: es quien puede abrir la tienda cerrada → pasa a la rama de apertura,
  // que es la acción que destraba a todo el equipo.
  if (canOpenClosedStore(input)) return false;

  return true;
}
