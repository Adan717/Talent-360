<?php

namespace App\Support;

/**
 * H3 (prueba en vivo 2026-07-29): al dar de alta a "Adán Cuéllar" el correo autogenerado salió
 * `adáncuéllar@pruebaqa360.com`. Un correo con diacríticos en la parte local exige SMTPUTF8
 * (RFC 6531) para viajar y la mayoría de servidores/clientes lo rechazan: la invitación de
 * bienvenida y las notificaciones fallan en silencio. Además es incómodo de teclear en el
 * celular al iniciar sesión.
 *
 * Normaliza la PARTE LOCAL (antes de la @): quita diacríticos y pasa a minúsculas. El dominio
 * se deja como viene, salvo pasarlo a minúsculas (los dominios no distinguen mayúsculas).
 */
class EmailNormalizer
{
    /** Diacríticos frecuentes en español y su equivalente ASCII. */
    private const MAPA = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /**
     * Quita diacríticos de un texto y lo pasa a minúsculas. Útil también para armar la parte
     * local de un correo a partir de un nombre.
     */
    public static function sinAcentos(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');

        return strtr($texto, self::MAPA);
    }

    /**
     * Normaliza un correo completo. Si el valor no parece un correo (sin @), se devuelve tal
     * cual para que la validación de Laravel lo rechace con su mensaje habitual — este helper
     * no valida, sólo normaliza.
     */
    public static function normalizar(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }

        // Se parte por la ÚLTIMA arroba: la parte local puede contenerlas entre comillas.
        $posicion = strrpos($email, '@');
        $local = substr($email, 0, $posicion);
        $dominio = substr($email, $posicion + 1);

        return self::sinAcentos($local) . '@' . mb_strtolower($dominio, 'UTF-8');
    }
}
