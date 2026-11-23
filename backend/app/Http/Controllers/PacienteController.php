<?php

namespace App\Http\Controllers;

use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PacienteController extends Controller
{
    public function index(Request $request)
    {
        $m = $this->membresia($request);
        $consulta = Paciente::deOrganizacion($m->organizacion_id)->orderBy('nombre');

        if ($m->vistaDeDentista()) {
            $consulta->whereExists(fn ($q) => $q->select(DB::raw(1))->from('citas')
                ->whereColumn('citas.organizacion_id', 'pacientes.organizacion_id')
                ->whereColumn('citas.paciente_id', 'pacientes.id')
                ->where('citas.dentista_id', $m->usuario_id));
        }

        if ($buscar = $request->query('buscar')) {
            $buscar = addcslashes($buscar, '%_\\');
            $consulta->where('nombre', 'ilike', '%'.$buscar.'%');
        }

        return $consulta->paginate(20);
    }

    public function store(Request $request)
    {
        $m = $this->membresia($request);
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        $paciente = new Paciente($datos);
        $paciente->organizacion_id = $m->organizacion_id;
        $paciente->save();

        return response()->json($paciente, 201);
    }

    public function show(Request $request, int $id)
    {
        $m = $this->membresia($request);
        $paciente = Paciente::deOrganizacion($m->organizacion_id)->findOrFail($id);

        if ($m->vistaDeDentista() && ! DB::table('citas')
            ->where('organizacion_id', $m->organizacion_id)
            ->where('paciente_id', $paciente->id)
            ->where('dentista_id', $m->usuario_id)->exists()) {
            abort(404);
        }

        return $paciente;
    }

    public function update(Request $request, int $id)
    {
        $m = $this->membresia($request);
        $paciente = Paciente::deOrganizacion($m->organizacion_id)->findOrFail($id);

        $datos = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        $paciente->update($datos);

        return $paciente;
    }

    public function destroy(Request $request, int $id)
    {
        $m = $this->membresia($request);
        $paciente = Paciente::deOrganizacion($m->organizacion_id)->findOrFail($id);

        if (DB::table('citas')->where('organizacion_id', $m->organizacion_id)->where('paciente_id', $paciente->id)->exists()) {
            return response()->json(['mensaje' => 'El paciente tiene citas y no puede eliminarse'], 409);
        }

        $paciente->delete();

        return response()->noContent();
    }
}
