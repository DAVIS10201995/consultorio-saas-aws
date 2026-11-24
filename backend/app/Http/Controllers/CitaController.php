<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Services\Bitacora;
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

        $m = $this->membresia($request);
        $consulta = Cita::deOrganizacion($m->organizacion_id)->orderBy('inicio', 'desc');

        if ($m->vistaDeDentista()) {
            $consulta->where('dentista_id', $m->usuario_id);
        }

        foreach (['dentista_id', 'paciente_id', 'estado'] as $campo) {
            if ($campo === 'dentista_id' && $m->vistaDeDentista()) {
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
        $m = $this->membresia($request);
        $cita = Cita::deOrganizacion($m->organizacion_id)->findOrFail($id);

        if ($m->vistaDeDentista() && $cita->dentista_id !== $m->usuario_id) {
            abort(404);
        }

        return $cita;
    }

    /** Historial desde DynamoDB. La PK se arma con la organización autorizada, nunca con datos del cliente. */
    public function historial(Request $request, int $id, Bitacora $bitacora)
    {
        $m = $this->membresia($request);
        $cita = Cita::deOrganizacion($m->organizacion_id)->findOrFail($id);

        if ($m->vistaDeDentista() && $cita->dentista_id !== $m->usuario_id) {
            abort(404);
        }

        return response()->json([
            'cita_id' => $cita->id,
            'eventos' => $bitacora->historial($m->organizacion_id, $cita->id),
        ]);
    }

    public function store(Request $request)
    {
        $m = $this->membresia($request);
        $org = $m->organizacion_id;

        $datos = $request->validate([
            'paciente_id' => ['required', 'integer', Rule::exists('pacientes', 'id')->where('organizacion_id', $org)],
            'dentista_id' => [
                'required', 'integer',
                Rule::exists('membresias', 'usuario_id')->where('organizacion_id', $org)->where('es_dentista', true),
                Rule::exists('usuarios', 'id')->where('activo', true),
            ],
            'inicio' => ['required', 'date'],
            'duracion_min' => ['required', 'integer', 'in:30,60,90'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $inicio = Carbon::parse($datos['inicio'])->setTimezone(config('app.zona_consultorio'));
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
            ->where('organizacion_id', $org)
            ->where('paciente_id', $datos['paciente_id'])
            ->where('estado', 'no_asistio')
            ->where('inicio', '>=', now()->subMonths(6))
            ->count();
        $estado = $faltas >= 2 ? 'pendiente_confirmacion' : 'programada';

        try {
            $cita = DB::transaction(function () use ($datos, $inicio, $fin, $estado, $m) {
                $cita = new Cita([
                    'paciente_id' => $datos['paciente_id'],
                    'dentista_id' => $datos['dentista_id'],
                    'inicio' => $inicio->copy()->utc(),
                    'fin' => $fin->copy()->utc(),
                    'estado' => $estado,
                    'motivo' => $datos['motivo'] ?? null,
                ]);
                $cita->organizacion_id = $m->organizacion_id;
                $cita->save();
                $this->registrarEvento($cita, null, $estado, $m->usuario_id);

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

        $m = $this->membresia($request);
        $nuevo = $datos['estado'];

        return DB::transaction(function () use ($id, $nuevo, $m) {
            $cita = Cita::deOrganizacion($m->organizacion_id)->lockForUpdate()->find($id);
            if (! $cita) {
                return response()->json(['mensaje' => 'Recurso no encontrado'], 404);
            }

            $actual = $cita->estado;
            if (! in_array($nuevo, self::TRANSICIONES[$actual] ?? [], true)) {
                return response()->json(['mensaje' => "No se puede pasar de {$actual} a {$nuevo}"], 409);
            }

            // Pasos clínicos: solo el dentista asignado. El resto: admin o capturista (ya filtrado en la ruta).
            if (in_array($nuevo, ['en_atencion', 'completada'], true)
                && (! $m->es_dentista || $cita->dentista_id !== $m->usuario_id)) {
                return response()->json(['mensaje' => 'Solo el dentista asignado puede realizar este cambio'], 403);
            }

            if ($nuevo === 'no_asistio' && now()->lessThan($cita->inicio)) {
                return response()->json(['mensaje' => 'La inasistencia solo se registra después de la hora de la cita'], 409);
            }

            if ($nuevo === 'cancelada' && now()->greaterThan($cita->inicio->copy()->subHours(2))) {
                $nuevo = 'no_asistio';
            }

            $cita->estado = $nuevo;
            $cita->save();
            $this->registrarEvento($cita, $actual, $nuevo, $m->usuario_id);

            return response()->json($cita);
        });
    }

    private function registrarEvento(Cita $cita, ?string $anterior, string $nuevo, int $usuarioId): void
    {
        DB::table('outbox')->insert([
            'organizacion_id' => $cita->organizacion_id,
            'event_id' => (string) Str::uuid(),
            'tipo' => 'cita.estado_cambiado',
            'payload' => json_encode([
                'organizacion_id' => $cita->organizacion_id,
                'cita_id' => $cita->id,
                'paciente_id' => $cita->paciente_id,
                'dentista_id' => $cita->dentista_id,
                'estado_anterior' => $anterior,
                'estado_nuevo' => $nuevo,
                'usuario_id' => $usuarioId,
                'fecha' => now()->format('Y-m-d\TH:i:s.uP'), // con microsegundos: ordena eventos del mismo segundo
            ]),
        ]);
    }
}
