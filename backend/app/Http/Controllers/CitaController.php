<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CitaController extends Controller
{
    private const TRANSICIONES = [
        'programada' => ['confirmada', 'cancelada'],
        'pendiente_confirmacion' => ['confirmada', 'cancelada'],
        'confirmada' => ['en_atencion', 'cancelada', 'no_asistio'],
        'en_atencion' => ['completada'],
    ];

    public function index(Request $request)
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $consulta = Cita::query()->orderBy('inicio', 'desc');

        $usuario = $request->user();
        if ($usuario->rol === 'dentista') {
            $consulta->where('dentista_id', $usuario->id);
        }

        foreach (['dentista_id', 'paciente_id', 'estado'] as $campo) {
            if ($campo === 'dentista_id' && $usuario->rol === 'dentista') {
                continue;
            }
            if ($valor = $request->query($campo)) {
                $consulta->where($campo, $valor);
            }
        }
        if ($desde = $request->query('desde')) {
            $consulta->where('inicio', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $consulta->where('inicio', '<', $hasta);
        }

        return $consulta->paginate(20);
    }

    public function show(Request $request, int $id)
    {
        $cita = Cita::findOrFail($id);
        $usuario = $request->user();

        if ($usuario->rol === 'dentista' && $cita->dentista_id !== $usuario->id) {
            abort(404);
        }

        return $cita;
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'paciente_id' => ['required', 'integer', 'exists:pacientes,id'],
            'dentista_id' => ['required', 'integer', Rule::exists('usuarios', 'id')->where('rol', 'dentista')->where('activo', true)],
            'inicio' => ['required', 'date'],
            'duracion_min' => ['required', 'integer', 'in:30,60,90'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $inicio = Carbon::parse($datos['inicio'])->setTimezone(config('app.timezone'));
        $fin = $inicio->copy()->addMinutes($datos['duracion_min']);

        if ($inicio->isPast()) {
            return response()->json(['mensaje' => 'La cita debe ser en el futuro'], 422);
        }
        if (! in_array($inicio->minute, [0, 30], true) || $inicio->second !== 0) {
            return response()->json(['mensaje' => 'Las citas empiezan en bloques de 30 minutos'], 422);
        }
        if ($inicio->hour < 9 || $fin->greaterThan($inicio->copy()->setTime(18, 0))) {
            return response()->json(['mensaje' => 'El horario de atención es de 9:00 a 18:00'], 422);
        }

        $faltas = DB::table('citas')
            ->where('paciente_id', $datos['paciente_id'])
            ->where('estado', 'no_asistio')
            ->where('inicio', '>=', now()->subMonths(6))
            ->count();
        $estado = $faltas >= 2 ? 'pendiente_confirmacion' : 'programada';

        try {
            $cita = DB::transaction(function () use ($datos, $inicio, $fin, $estado, $request) {
                $cita = Cita::create([
                    'paciente_id' => $datos['paciente_id'],
                    'dentista_id' => $datos['dentista_id'],
                    'inicio' => $inicio,
                    'fin' => $fin,
                    'estado' => $estado,
                    'motivo' => $datos['motivo'] ?? null,
                ]);
                $this->registrarEvento($cita, null, $estado, $request->user()->id);

                return $cita;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                return response()->json(['mensaje' => 'El dentista ya tiene una cita en ese horario'], 409);
            }
            throw $e;
        }

        return response()->json($cita->refresh(), 201);
    }

    public function cambiarEstado(Request $request, int $id)
    {
        $datos = $request->validate([
            'estado' => ['required', Rule::in(['confirmada', 'en_atencion', 'completada', 'cancelada', 'no_asistio'])],
        ]);

        $usuario = $request->user();
        $nuevo = $datos['estado'];

        return DB::transaction(function () use ($id, $nuevo, $usuario) {
            $cita = Cita::lockForUpdate()->find($id);
            if (! $cita) {
                return response()->json(['mensaje' => 'Cita no encontrada'], 404);
            }

            $actual = $cita->estado;
            if (! in_array($nuevo, self::TRANSICIONES[$actual] ?? [], true)) {
                return response()->json(['mensaje' => "No se puede pasar de {$actual} a {$nuevo}"], 409);
            }

            if (in_array($nuevo, ['en_atencion', 'completada'], true)) {
                if ($usuario->rol !== 'dentista' || $usuario->id !== $cita->dentista_id) {
                    return response()->json(['mensaje' => 'Solo el dentista asignado puede realizar este cambio'], 403);
                }
            } elseif (! in_array($usuario->rol, ['recepcion', 'admin'], true)) {
                return response()->json(['mensaje' => 'No autorizado para esta acción'], 403);
            }

            if ($nuevo === 'no_asistio' && now()->lessThan($cita->inicio)) {
                return response()->json(['mensaje' => 'La inasistencia solo se registra después de la hora de la cita'], 409);
            }

            if ($nuevo === 'cancelada' && now()->greaterThan($cita->inicio->copy()->subHours(2))) {
                $nuevo = 'no_asistio';
            }

            $cita->estado = $nuevo;
            $cita->save();
            $this->registrarEvento($cita, $actual, $nuevo, $usuario->id);

            return response()->json($cita);
        });
    }

    private function registrarEvento(Cita $cita, ?string $anterior, string $nuevo, int $usuarioId): void
    {
        DB::table('outbox')->insert([
            'event_id' => (string) Str::uuid(),
            'tipo' => 'cita.estado_cambiado',
            'payload' => json_encode([
                'cita_id' => $cita->id,
                'paciente_id' => $cita->paciente_id,
                'dentista_id' => $cita->dentista_id,
                'estado_anterior' => $anterior,
                'estado_nuevo' => $nuevo,
                'usuario_id' => $usuarioId,
                'fecha' => now()->toIso8601String(),
            ]),
        ]);
    }
}