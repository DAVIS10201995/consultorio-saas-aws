<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RolRequerido
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $membresia = $request->attributes->get('membresia');

        if (! $membresia || ! in_array($membresia->rol, $roles, true)) {
            return response()->json(['mensaje' => 'No autorizado para esta acción'], 403);
        }

        return $next($request);
    }
}
