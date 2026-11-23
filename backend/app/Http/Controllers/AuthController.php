<?php

namespace App\Http\Controllers;

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

        if (! $usuario || ! Hash::check($datos['password'], $usuario->password)) {
            return response()->json(['mensaje' => 'Credenciales inválidas'], 401);
        }

        $token = $usuario->createToken('api')->plainTextToken;

        return response()->json(['token' => $token, 'rol' => $usuario->rol]);
    }

    public function yo(Request $request)
    {
        return response()->json($request->user()->only(['id', 'nombre', 'email', 'rol']));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}