/**
 * Asignaciones de apertura de tienda: a QUIÉN corresponde cada fila.
 *
 * POR QUÉ EXISTE (Ronda 50): `store_opening_assignments.employee_id` es FK a **employees**, pero
 * `store_daily_opening_statuses.current_responsible_employee_id` —pese al nombre— es FK a **users**
 * (lo documenta `StoreOpeningService:107-108`). Son espacios de ID DISTINTOS y en producción están
 * desalineados de verdad: en el tenant de pruebas el Gerente es `employee_id=4` / `user_id=5`.
 * El front comparaba un espacio contra el otro, así que NUNCA casaba: el reloj no sabía quién abre
 * ("Apertura por: Encargado") y el responsable real no mostraba su llave.
 *
 * Los llamadores tienen en la mano un **users.id** (`currentUser.id`, `current_responsible_employee_id`),
 * así que el casamiento se hace por `user_id`. Dos casos hay que distinguir con cuidado:
 *
 *  - **Falta la llave** (`undefined`): asignación MOCK del sandbox/simulador. Ahí no hay `user_id` y
 *    ambos espacios de ID coinciden, así que se cae a `employee_id` y todo sigue funcionando.
 *  - **La llave vale `null`**: empleado HUÉRFANO, un expediente sin usuario vinculado (`employees.user_id`
 *    es nullable con `onDelete('set null')`; ver R40). NO debe caer a `employee_id`: haría casar la fila
 *    contra un users.id ajeno que resulte tener ese número — justo el bug de espacios de ID que esta
 *    ronda vino a cerrar. Un huérfano no puede fichar ni abrir, así que lo correcto es que no case con
 *    nadie.
 *
 * Por eso NO se puede usar `a.user_id ?? a.employee_id`: `??` también cae con `null` y trataría al
 * huérfano como si su employee_id fuera un users.id.
 */

/** users.id al que pertenece la asignación, o null si no se puede determinar (huérfano). */
export const assignmentUserId = (a: any): number | null => {
  if (!a) return null;
  // 'user_id' in a distingue "no viene la llave" (mock) de "viene y es null" (huérfano).
  const raw = a.user_id !== undefined ? a.user_id : a.employee_id;
  if (raw === null || raw === undefined) return null;
  const n = Number(raw);
  return Number.isFinite(n) ? n : null;
};

/** ¿Esta asignación es de este usuario? `userId` es siempre un users.id. */
export const assignmentIsUser = (a: any, userId: any): boolean => {
  const assigned = assignmentUserId(a);
  const target = Number(userId);
  return assigned !== null && Number.isFinite(target) && assigned === target;
};

/**
 * Fila de apertura AUTORIZADA, en el mismo orden que usa el backend: sólo activos con
 * `can_open_store`, por `priority_order` ascendente. Espeja la query de `firstResponsible`
 * (`StoreOpeningService:82-88`) para que el front no discrepe del handoff (R46: se salta a quien el
 * admin no autorizó). `fila[0]` = responsable principal, `fila[1]` = suplente.
 */
export const filaDeApertura = (assignments: any): any[] => {
  if (!Array.isArray(assignments)) return [];
  return assignments
    .filter((a: any) => a && a.is_active && a.can_open_store)
    .sort((a: any, b: any) => Number(a.priority_order) - Number(b.priority_order));
};

/** Lee las asignaciones cacheadas en localStorage sin reventar por JSON corrupto. */
export const leerAssignmentsCacheadas = (): any[] => {
  try {
    const saved = localStorage.getItem('store_opening_assignments');
    const parsed = saved ? JSON.parse(saved) : [];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
};
