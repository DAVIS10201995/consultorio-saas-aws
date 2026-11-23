<?php

namespace App\Http\Controllers;

use App\Models\Membresia;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $usuario = Usuario::where('email', $datos['email'])->where('activo', true)->first();
        $membresia = $usuario ? Membresia::with('organizacion')->where('usuario_id', $usuario->id)->first() : null;

        // Mismo mensaje para cualquier fallo: no revela si el correo existe.
        if (! $usuario || ! $membresia || ! Hash::check($datos['password'], $usuario->password)) {
            return response()->json(['mensaje' => 'Credenciales inválidas'], 401);
        }

        $token = $usuario->createToken('api')->plainTextToken;

        return response()->json(['token' => $token] + $this->perfil($usuario, $membresia));
    }

    public function yo(Request $request)
    {
        return response()->json($this->perfil($request->user(), $this->membresia($request)));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function perfil(Usuario $usuario, Membresia $membresia): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'email' => $usuario->email,
            'rol' => $membresia->rol,
            'es_dentista' => $membresia->es_dentista,
            'organizacion' => $membresia->organizacion->only(['nombre', 'slug']),
        ];
    }
}
