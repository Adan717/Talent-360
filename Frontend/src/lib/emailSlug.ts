/**
 * H3 (prueba en vivo 2026-07-29): al dar de alta a "Adán Cuéllar", el alta generaba el correo
 * `adáncuéllar@pruebaqa360.com` — con acentos. Un correo con diacríticos en la parte local
 * exige SMTPUTF8 (RFC 6531) para viajar y la mayoría de servidores lo rechazan: la invitación
 * de bienvenida y las notificaciones fallan en silencio. Además es incómodo de teclear en el
 * celular al iniciar sesión.
 *
 * El backend también normaliza al persistir (App\Support\EmailNormalizer), pero conviene
 * generarlo bien desde el origen para que el admin vea en pantalla el mismo correo que se
 * guardará.
 */

/**
 * Convierte un nombre en la parte local de un correo: sin acentos, sin espacios, minúsculas
 * y sólo con caracteres seguros.
 *
 *   "Adán Cuéllar"  -> "adancuellar"
 *   "Íñigo Muñoz"   -> "inigomunoz"
 *   "Ana  Ma. Sáez" -> "anama.saez"
 */
export function slugParaCorreo(nombre: string): string {
  return (nombre || '')
    .normalize('NFD')                  // separa la letra de su acento
    .replace(/[̀-ͯ]/g, '')   // quita los acentos sueltos
    .toLowerCase()
    .replace(/\s+/g, '')               // sin espacios
    .replace(/[^a-z0-9._+-]/g, '');    // sólo lo que un correo acepta sin comillas
}
