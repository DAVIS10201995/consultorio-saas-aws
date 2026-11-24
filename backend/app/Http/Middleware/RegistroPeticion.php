<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Una línea de registro por petición, con un identificador que también se
 * devuelve en la cabecera X-Request-Id. NO registra tokens, contraseñas,
 * cuerpos ni la query string (puede traer nombres de pacientes).
 */
class RegistroPeticion
{
    public function handle(Request $request, Closure $next)
    {
        // Si la petición viene de un proxy de confianza (nginx) se reutiliza su id,
        // así nginx y Laravel registran el mismo. De cualquier otro origen se ignora.
        $entrante = (string) $request->header('X-Request-Id');
        $id = $request->isFromTrustedProxy() && preg_match('/^[A-Za-z0-9-]{8,64}$/', $entrante)
            ? $entrante
            : (string) Str::uuid();
        $inicio = hrtime(true);

        // Todo lo que se registre durante esta petición (también errores) lleva el id.
        Log::withContext(['request_id' => $id]);

        $respuesta = $next($request);

        $membresia = $request->attributes->get('membresia');

        Log::info('peticion', [
            'cf_id' => $request->header('X-Amz-Cf-Id'), // id de CloudFront, para cruzar con sus registros
            'ip' => $request->ip(),
            'metodo' => $request->method(),
            'ruta' => '/'.$request->path(),
            'estado' => $respuesta->getStatusCode(),
            'duracion_ms' => round((hrtime(true) - $inicio) / 1e6, 1),
            'usuario_id' => $membresia?->usuario_id,
            'organizacion_id' => $membresia?->organizacion_id,
            'rol' => $membresia?->rol,
        ]);

        $respuesta->headers->set('X-Request-Id', $id);

        return $respuesta;
    }
}
