<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * §44: sanitizador HTML nativo (sin dependencias externas — este entorno no tiene red
 * para instalar HTMLPurifier/mews\purifier). Lista blanca de etiquetas y atributos,
 * consistente con el sanitizador de cliente `Frontend/src/lib/sanitizeHtml.ts` que
 * Cowork ya aplicó, para que ambas capas coincidan. Cierra el vector de XSS del vault
 * público (`WebPublicaOrganizacion.tsx`, renderiza `ObsidianDocument.content` sin sesión).
 */
class HtmlSanitizer
{
    /** Etiquetas permitidas (todo lo demás se "desenvuelve" conservando su texto). */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small', 'sub', 'sup',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div', 'span',
    ];

    /** Etiquetas peligrosas: se eliminan junto con TODO su contenido. */
    private const FORBIDDEN_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'link', 'meta', 'base', 'svg', 'math',
        'noscript', 'template', 'audio', 'video', 'source', 'track',
    ];

    /** Atributos permitidos por etiqueta (además de los globales de abajo). */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel', 'data-target-slug'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /** Atributos permitidos en cualquier etiqueta (no ejecutan JS). */
    private const GLOBAL_ATTRIBUTES = ['class', 'title', 'id'];

    /** Esquemas permitidos en href/src. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        // Cargar como fragmento UTF-8, sin doctype ni <html>/<body> implícitos.
        $wrapped = '<?xml encoding="UTF-8"><div id="__sanitizer_root__">' . $html . '</div>';

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__sanitizer_root__');
        if (!$root) {
            return '';
        }

        self::sanitizeNode($root);

        // Serializar solo el contenido interno del root.
        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private static function sanitizeNode(DOMNode $node): void
    {
        // Se itera sobre una copia porque vamos modificando el árbol.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!($child instanceof DOMElement)) {
                // Nodos de texto, comentarios, etc. Los comentarios se eliminan.
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $node->removeChild($child);
                }
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::FORBIDDEN_TAGS, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Etiqueta desconocida pero no peligrosa: se desenvuelve (se conservan
                // sus hijos ya sanitizados, se descarta la etiqueta en sí).
                self::sanitizeNode($child);
                self::unwrap($child);
                continue;
            }

            self::sanitizeAttributes($child, $tag);
            self::sanitizeNode($child);
        }
    }

    private static function sanitizeAttributes(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::ALLOWED_ATTRIBUTES[$tag] ?? []);

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);

            // Cualquier manejador de eventos (onclick, onerror, onload, ...) fuera.
            if (str_starts_with($name, 'on')) {
                $el->removeAttribute($attr->name);
                continue;
            }

            if (!in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->name);
                continue;
            }

            if ($name === 'href' || $name === 'src') {
                if (!self::isSafeUrl($attr->value)) {
                    $el->removeAttribute($attr->name);
                }
            }
        }

        // Endurecer enlaces que abren en nueva pestaña.
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        $trimmed = trim($url);

        // Enlaces internos de la app (wiki-links usan href="#") y rutas relativas.
        if ($trimmed === '' || $trimmed === '#' || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '/') || str_starts_with($trimmed, './')
            || str_starts_with($trimmed, '../')) {
            return true;
        }

        // Si trae esquema explícito, debe estar en la lista blanca.
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $trimmed, $m)) {
            return in_array(strtolower($m[1]), self::ALLOWED_SCHEMES, true);
        }

        // Sin esquema (ej. "example.com/x", "imagen.png") — relativo, se permite.
        return true;
    }

    private static function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (!$parent) {
            return;
        }
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
