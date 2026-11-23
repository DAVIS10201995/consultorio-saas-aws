<?php

namespace App\Http\Controllers;

use App\Models\Membresia;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Gestión de miembros de la propia organización (solo administrador). */
class MiembroController extends Controller
{
    public function index(Request $request)
    {
        $m = $this->membresia($request);

        return Membresia::with('usuario:id,nombre,email,activo')
            ->where('organizacion_id', $m->organizacion_id)
            ->orderBy('id')
            ->get()
            ->map(fn (Membresia $x) => [
                'id' => $x->id,
                'usuario_id' => $x->usuario_id,
                'nombre' => $x->usuario->nombre,
                'email' => $x->usuario->email,
                'activo' => $x->usuario->activo,
                'rol' => $x->rol,
                'es_dentista' => $x->es_dentista,
            ]);
    }

    public function store(Request $request)
    {
        $m = $this->membresia($request);
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:usuarios,email'],
            'rol' => ['required', Rule::in(Membresia::ROLES)],
            'es_dentista' => ['sometimes', 'boolean'],
        ]);

        $password = Str::random(16);

        $miembro = DB::transaction(function () use ($datos, $password, $m) {
            $usuario = Usuario::create([
                'nombre' => $datos['nombre'],
                'email' => $datos['email'],
                'password' => $password,
            ]);

            return Membresia::create([
                'organizacion_id' => $m->organizacion_id,
                'usuario_id' => $usuario->id,
                'rol' => $datos['rol'],
                'es_dentista' => $datos['es_dentista'] ?? false,
            ]);
        });

        // La contraseña inicial se muestra una sola vez.
        return response()->json(['id' => $miembro->id, 'usuario_id' => $miembro->usuario_id, 'password_inicial' => $password], 201);
    }

    public function update(Request $request, int $id)
    {
        $m = $this->membresia($request);
        $miembro = Membresia::where('organizacion_id', $m->organizacion_id)->findOrFail($id);

        if ($miembro->usuario_id === $m->usuario_id) {
            return response()->json(['mensaje' => 'No puedes cambiar tu propia membresía'], 409);
        }

        $datos = $request->validate([
            'rol' => ['sometimes', Rule::in(Membresia::ROLES)],
            'es_dentista' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($miembro, $datos) {
            $miembro->update(array_intersect_key($datos, array_flip(['rol', 'es_dentista'])));

            if (array_key_exists('activo', $datos)) {
                $usuario = $miembro->usuario;
                $usuario->activo = $datos['activo'];
                $usuario->save();
                if (! $datos['activo']) {
                    $usuario->tokens()->delete(); // cierra sus sesiones
                }
            }
        });

        return response()->noContent();
    }
}
