/**
 * Avatar de un colaborador, con su respaldo generado.
 *
 * PRIVACIDAD (2026-08-08): el respaldo se pedía como
 * `https://api.dicebear.com/7.x/avataaars/svg?seed={NOMBRE REAL}` desde ocho lugares
 * distintos. Como hoy nadie tiene foto subida, ESE es el camino que se ejecuta siempre: en
 * cada carga del monitor, del organigrama o de RRHH, el navegador le manda a un tercero
 * (dicebear) el **nombre completo de cada empleado** en la query string, junto con la IP y
 * el Referer de la empresa. Nadie aceptó eso.
 *
 * La semilla pasa a ser el id interno, que fuera de la base no dice nada de nadie. El dibujo
 * de cada quien cambia una vez y ya; el estilo es el mismo.
 *
 * Pendiente para el dueño: dejar de pedirle las caras a un tercero (un círculo con las
 * iniciales se dibuja aquí mismo, no gasta una petición externa por cara y además funciona
 * con el reloj en modo offline, donde hoy salen imágenes rotas).
 */
const ESTILO = 'https://api.dicebear.com/7.x/avataaars/svg?seed=';

export function avatarDe(persona: { avatar?: string | null; id?: number | string | null }): string {
  if (persona?.avatar) return persona.avatar;
  return ESTILO + encodeURIComponent(`t360-${persona?.id ?? 'anon'}`);
}
