/**
 * Auditoría general de plataforma (2026-07-24), Hallazgo 1 (grave) — XSS almacenado público.
 *
 * `WebPublicaOrganizacion.tsx` (rutas públicas SIN autenticación: /organizacion/:tenantSlug y
 * /organizacion/:tenantSlug/:docSlug) y `OrgVaultManager.tsx` (editor interno del vault)
 * renderizaban contenido de documentos (`ObsidianDocument.content`) y HTML generado por IA
 * (`scribeResultHtml`) directamente vía `dangerouslySetInnerHTML`, sin sanitizar en ningún punto
 * — ni al guardar en el backend, ni al mostrar en el frontend. Como la página de organigrama es
 * pública y no exige sesión, cualquier cuenta con permiso de edición del vault (o una cuenta
 * comprometida) podía inyectar HTML/JS que se ejecutaría en el navegador de cualquier visitante
 * anónimo de esa URL — robo de sesión, phishing superpuesto, redirecciones maliciosas, etc.
 *
 * Esta es una mitigación del lado del cliente (sanitiza justo antes de inyectar HTML). NO
 * reemplaza sanitizar también del lado del servidor al guardar (ver §44 en
 * docs/BACKEND_INTERFACES.md) — un cliente modificado o una llamada directa a la API podría
 * saltarse esta capa. Defensa en profundidad: las dos capas importan.
 *
 * No se usó una librería como DOMPurify porque el entorno de esta sesión no tiene acceso de
 * red al registro de npm para instalar dependencias nuevas (`npm install` da 403). Este
 * sanitizador usa `DOMParser` (nativo del navegador, sin dependencias) y una lista blanca de
 * etiquetas/atributos — cubre los vectores de XSS conocidos y comunes (<script>, <style>,
 * atributos on*, URLs javascript:/data:text/html, <iframe>/<object>/<embed>), pero una librería
 * madura y probada contra el corpus de bypasses conocidos (mutation XSS, encodings raros, etc.)
 * sigue siendo más robusta. Recomendación: en cuanto haya acceso de red, correr
 * `npm install dompurify` y reemplazar `sanitizeHtml()` por `DOMPurify.sanitize()` — la firma de
 * la función (string entra, string sale) ya es compatible, así que el cambio sería de una línea
 * en cada sitio que la usa.
 */

const ALLOWED_TAGS = new Set([
  'p', 'br', 'hr',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small', 'sub', 'sup',
  'ul', 'ol', 'li',
  'blockquote', 'pre', 'code',
  'a', 'img',
  'table', 'thead', 'tbody', 'tr', 'th', 'td',
  'div', 'span',
]);

// Atributos permitidos por etiqueta (además de 'class' y 'id', que se permiten en todas las de arriba).
const ALLOWED_ATTRS: Record<string, string[]> = {
  a: ['href', 'title', 'target', 'rel'],
  img: ['src', 'alt', 'title', 'width', 'height'],
  table: ['border'],
  td: ['colspan', 'rowspan'],
  th: ['colspan', 'rowspan'],
};

const SAFE_URL_PROTOCOLS = /^(https?:|mailto:|tel:|\/|#)/i;

function isSafeUrl(value: string): boolean {
  const trimmed = value.trim();
  if (trimmed === '') return true;
  return SAFE_URL_PROTOCOLS.test(trimmed);
}

function sanitizeNode(node: Element): void {
  // Recorrer hijos en reversa porque removeChild altera la lista en vivo.
  const children = Array.from(node.children);
  for (const child of children) {
    const tag = child.tagName.toLowerCase();

    if (!ALLOWED_TAGS.has(tag)) {
      // Etiqueta no permitida (script, style, iframe, object, embed, form, svg con <use>
      // malicioso, etc.) — se descarta el nodo completo, no solo la etiqueta, para no dejar
      // pasar su contenido/atributos sin control (ej. <style>@import url(javascript:...)</style>).
      child.remove();
      continue;
    }

    // Quitar todos los atributos no explícitamente permitidos (esto elimina de raíz cualquier
    // atributo on* — onclick, onerror, onload, etc. — porque ninguno está en la lista blanca).
    const allowedForTag = new Set(['class', 'id', ...(ALLOWED_ATTRS[tag] || [])]);
    for (const attr of Array.from(child.attributes)) {
      const name = attr.name.toLowerCase();
      if (!allowedForTag.has(name)) {
        child.removeAttribute(attr.name);
        continue;
      }
      if ((name === 'href' || name === 'src') && !isSafeUrl(attr.value)) {
        child.removeAttribute(attr.name);
      }
    }

    // target="_blank" sin rel="noopener noreferrer" permite que la pestaña abierta manipule
    // la página de origen (reverse tabnabbing) — se fuerza el rel seguro.
    if (tag === 'a' && child.getAttribute('target') === '_blank') {
      child.setAttribute('rel', 'noopener noreferrer');
    }

    sanitizeNode(child);
  }
}

/**
 * Sanitiza una cadena de HTML antes de usarla con `dangerouslySetInnerHTML`.
 * Ver comentario del encabezado del archivo para contexto y limitaciones.
 */
export function sanitizeHtml(html: string | null | undefined): string {
  if (!html) return '';
  try {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    sanitizeNode(doc.body);
    return doc.body.innerHTML;
  } catch (e) {
    console.warn('sanitizeHtml: no se pudo parsear/sanitizar el HTML, se descarta por seguridad.', e);
    return '';
  }
}
