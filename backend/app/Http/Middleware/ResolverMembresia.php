<?php

namespace App\Http\Middleware;

use App\Models\Membresia;
use Closure;
use Illuminate\Http\Request;

/**
 * Obtiene la organización y el rol a partir de la identidad autenticada.
 * Es la ÚNICA fuente de la organización: nunca se lee de la petición.
 */
class ResolverMembresia
{
    public function handle(Request $request, Closure $next)
    {
        $usuario = $request->user();

        $membresia = $usuario && $usuario->activo
            ? Membresia::with('organizacion')->where('usuario_id', $usuario->id)->first()
            : null;

        if (! $membresia) {
            return response()->json(['mensaje' => 'El usuario no pertenece a una organización activa'], 403);
        }

        $request->attributes->set('membresia', $membresia);

        return $next($request);
    }
}
