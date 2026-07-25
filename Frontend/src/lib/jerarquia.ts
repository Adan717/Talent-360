/**
 * Jerarquía de puestos derivada del ORGANIGRAMA (`job_roles.reports_to_role_ids`).
 *
 * POR QUÉ EXISTE (Ronda 43): el Reloj decidía el mando comparando `role` contra nombres de
 * puesto hardcodeados ('Encargado Titular', 'Segundo Encargado', 'Administrador / Gerente'…).
 * Eso venía de los datos MOCK del simulador. En producción:
 *   - `currentUser.role` sólo vale 'admin' | 'empleado' | 'supervisor' (es el eje de ACCESO).
 *   - Los puestos reales los define cada empresa y cambian por giro (multigiro) — un tenant
 *     tiene 'Gerente de Sucursal'/'Cajero(a)', otro tendrá 'Chef'/'Mesero'.
 * Resultado: esas comparaciones eran SIEMPRE falsas y varias features quedaban muertas
 * (el botón "Entrega de Turno" nunca se renderizaba; nadie veía el panel de aprobación de
 * descansos), pero funcionaban en el simulador — por eso el suite nunca lo vio.
 *
 * La fuente de verdad de la jerarquía es el ORGANIGRAMA, editable por empresa. Decisión de
 * producto (2026-07-15): acceso a la plataforma ⊥ puesto de trabajo, y la jerarquía vive en
 * el organigrama, no en un enum ni en nombres.
 */

/**
 * Ids de los puestos a los que ESTE puesto reporta.
 * `reports_to_role_ids` llega casteado a array desde el modelo JobRole (`$casts`); se tolera
 * el JSON crudo por si algún camino lo entrega sin castear, y se cae al legacy
 * `reports_to_role_id` (singular) que aún existe en la tabla.
 */
export const getReportsToIds = (role: any): number[] => {
  if (!role) return [];

  const many = role.reports_to_role_ids;
  if (Array.isArray(many)) {
    return many.map(Number).filter((n) => Number.isFinite(n));
  }
  if (typeof many === 'string' && many.trim() !== '') {
    try {
      const parsed = JSON.parse(many);
      if (Array.isArray(parsed)) {
        return parsed.map(Number).filter((n) => Number.isFinite(n));
      }
    } catch {
      /* formato inesperado → se intenta el legacy abajo */
    }
  }

  const one = Number(role?.reports_to_role_id);
  return Number.isFinite(one) && one > 0 ? [one] : [];
};

/**
 * Un puesto es de MANDO si AL MENOS OTRO puesto le reporta en el organigrama.
 * Se ignora el auto-reporte (un puesto que se apunte a sí mismo no se vuelve su propio jefe).
 */
export const puestoTieneSubordinados = (jobRoleId: any, roles: any[]): boolean => {
  const id = Number(jobRoleId);
  if (!Number.isFinite(id) || id <= 0) return false;

  return (roles || []).some(
    (r) => Number(r?.id) !== id && getReportsToIds(r).includes(id)
  );
};

/**
 * ¿Este usuario tiene autoridad para supervisar/aprobar?
 *
 * Dos ejes INDEPENDIENTES (decisión de producto del 2026-07-15):
 *  - ACCESO a la plataforma: admin/supervisor mandan por definición.
 *  - PUESTO operativo: manda quien tiene subordinados en el organigrama.
 * Así un 'Gerente de Sucursal' con `role='empleado'` (que es el caso real: el eje de acceso
 * no dice nada de su puesto) sí es reconocido como autoridad.
 */
export const tieneAutoridad = (user: any, roles: any[]): boolean => {
  const accessRole = String(user?.role ?? '').toLowerCase();
  if (accessRole === 'admin' || accessRole === 'supervisor' || accessRole === 'platform_admin') {
    return true;
  }
  return puestoTieneSubordinados(user?.job_role_id, roles);
};
