<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §43: si la petición no trae el header `Authorization: Bearer`, pero sí la cookie
 * `httpOnly` `talent_auth_token`, se copia el token de la cookie al header antes de que
 * Sanctum evalúe la autenticación. Así el token puede vivir en una cookie que JavaScript
 * no puede leer (protección contra robo por XSS) sin cambiar nada del resto del stack de
 * autenticación por tokens, que sigue funcionando igual con el header Bearer.
 */
class AuthTokenFromCookie
{
    public const COOKIE_NAME = 'talent_auth_token';

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->bearerToken()) {
            $cookieToken = $request->cookie(self::COOKIE_NAME);
            if (is_string($cookieToken) && $cookieToken !== '') {
                $request->headers->set('Authorization', 'Bearer ' . $cookieToken);
            }
        }

        return $next($request);
    }
}
