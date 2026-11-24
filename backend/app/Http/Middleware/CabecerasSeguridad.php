<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Cabeceras de seguridad para todas las respuestas de la API. HSTS lo pone CloudFront. */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next)
    {
        $respuesta = $next($request);

        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set('X-Frame-Options', 'DENY');
        $respuesta->headers->set('Referrer-Policy', 'no-referrer');
        $respuesta->headers->set('Cache-Control', 'no-store'); // datos de pacientes: nunca en caché
        $respuesta->headers->remove('X-Powered-By');

        return $respuesta;
    }
}
