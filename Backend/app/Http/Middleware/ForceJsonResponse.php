<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza `Accept: application/json` en todas las rutas api/*. Sin esto, una petición
 * no autenticada que no manda ese header (curl, healthchecks, scanners) no produce un
 * 401: el middleware Authenticate del framework intenta calcular route('login') —que
 * no existe en una API pura— y la petición muere en 500 (RouteNotFoundException) antes
 * de que el `shouldRenderJsonWhen` del manejador de excepciones pueda intervenir.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
