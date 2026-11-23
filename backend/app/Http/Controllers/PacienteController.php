<?php

namespace App\Http\Controllers;

use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PacienteController extends Controller
{
    public function index(Request $request)
    {
        $consulta = Paciente::query()->orderBy('nombre');

        $usuario = $request->user();
        if ($usuario->rol === 'dentista') {
            $consulta->whereExists(fn ($q) => $q->select(DB::raw(1))->from('citas')
                ->whereColumn('citas.paciente_id', 'pacientes.id')
                ->where('citas.dentista_id', $usuario->id));
        }

        if ($buscar = $request->query('buscar')) {
            $buscar = addcslashes($buscar, '%_\\');
            $consulta->where('nombre', 'ilike', '%'.$buscar.'%');
        }

        return $consulta->paginate(20);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        return response()->json(Paciente::create($datos), 201);
    }

    public function show(Request $request, Paciente $paciente)
    {
        $usuario = $request->user();
        if ($usuario->rol === 'dentista' && ! DB::table('citas')->where('paciente_id', $paciente->id)->where('dentista_id', $usuario->id)->exists()) {
            abort(404);
        }

        return $paciente;
    }

    public function update(Request $request, Paciente $paciente)
    {
        $datos = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        $paciente->update($datos);

        return $paciente;
    }

    public function destroy(Paciente $paciente)
    {
        if (DB::table('citas')->where('paciente_id', $paciente->id)->exists()) {
            return response()->json(['mensaje' => 'El paciente tiene citas y no puede eliminarse'], 409);
        }

        $paciente->delete();

        return response()->noContent();
    }
}