<?php

namespace App\Console\Commands;

use App\Models\Membresia;
use App\Models\Organizacion;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class SembrarDatos extends Command
{
    protected $signature = 'datos:sembrar';
    protected $description = 'Siembra dos consultorios ficticios con usuarios, pacientes, planes, citas y registros clínicos';

    private const NS = '6f1c2c1e-5d0a-4a7e-9f3b-0c0ffee0d0f1';
    private const PACIENTES = 60;   // por organización
    private const PLANES = 20;      // por organización
    private const CITAS = 76;       // por organización (38 por dentista)
    private const DIAS_LABORABLES = 25;

    private const ORGANIZACIONES = [
        'sonrisa' => 'Clínica Dental Sonrisa',
        'norte' => 'Consultorio Dental Norte',
    ];

    private const NOMBRES = ['Ana', 'Luis', 'María', 'Carlos', 'Sofía', 'Jorge', 'Lucía', 'Pedro', 'Elena', 'Miguel', 'Paula', 'Andrés', 'Camila', 'Diego', 'Valeria', 'Raúl', 'Isabel', 'Tomás', 'Renata', 'Hugo'];
    private const APELLIDOS = ['García', 'López', 'Martínez', 'Hernández', 'Ramírez', 'Torres', 'Flores', 'Rivera', 'Gómez', 'Díaz', 'Morales', 'Reyes'];
    private const PROCEDIMIENTOS = ['Limpieza dental', 'Resina', 'Endodoncia', 'Extracción', 'Revisión', 'Blanqueamiento', 'Ortodoncia: ajuste', 'Corona'];

    public function handle(): int
    {
        if (Organizacion::where('slug', 'sonrisa')->exists()) {
            $this->info('La semilla ya fue aplicada; no se hace nada.');

            return self::SUCCESS;
        }

        $tz = config('app.timezone');

        // 25 días laborables: unos 14 en el pasado y el resto en el futuro, relativos a hoy.
        $dias = [];
        $d = Carbon::now($tz)->startOfWeek()->subWeeks(3)->startOfDay();
        while (count($dias) < self::DIAS_LABORABLES) {
            if ($d->isWeekday()) {
                $dias[] = $d->copy();
            }
            $d->addDay();
        }

        DB::transaction(function () use ($dias) {
            $desfase = 0;
            foreach (self::ORGANIZACIONES as $slug => $nombre) {
                $org = Organizacion::create(compact('slug', 'nombre'));
                $this->line("== {$nombre} ({$slug})");
                $this->sembrarOrganizacion($org, $dias, $desfase);
                $desfase += 7;
            }
        });

        $this->info('Semilla aplicada: 2 organizaciones, 5 usuarios, '.self::PACIENTES.' pacientes y '.self::CITAS.' citas en cada una.');

        return self::SUCCESS;
    }

    private function sembrarOrganizacion(Organizacion $org, array $dias, int $desfase): void
    {
        $slug = $org->slug;

        // Usuarios: contraseñas aleatorias, se imprimen una sola vez.
        $usuarios = [
            ['Admin '.ucfirst($slug), "admin@{$slug}.test", 'admin', false],
            ['Capturista '.ucfirst($slug), "capturista@{$slug}.test", 'capturista', false],
            ['Dentista Uno '.ucfirst($slug), "dentista1@{$slug}.test", 'capturista', true],
            ['Dentista Dos '.ucfirst($slug), "dentista2@{$slug}.test", 'capturista', true],
            ['Lector '.ucfirst($slug), "lector@{$slug}.test", 'lector', false],
        ];
        $ids = [];
        foreach ($usuarios as [$nombre, $email, $rol, $esDentista]) {
            $password = Str::random(16);
            $usuario = Usuario::create(compact('nombre', 'email', 'password'));
            Membresia::create([
                'organizacion_id' => $org->id,
                'usuario_id' => $usuario->id,
                'rol' => $rol,
                'es_dentista' => $esDentista,
            ]);
            $ids[$email] = $usuario->id;
            $this->line("   {$email} ({$rol}".($esDentista ? ', dentista' : '').") => {$password}");
        }
        $capturista = $ids["capturista@{$slug}.test"];
        $dentistas = [$ids["dentista1@{$slug}.test"], $ids["dentista2@{$slug}.test"]];

        // Pacientes ficticios (teléfonos del rango reservado 555-01xx).
        $pacientes = [];
        for ($p = 0; $p < self::PACIENTES; $p++) {
            $q = $p + $desfase;
            $pacientes[$p] = DB::table('pacientes')->insertGetId([
                'organizacion_id' => $org->id,
                'nombre' => self::NOMBRES[$q % 20].' '.self::APELLIDOS[intdiv($q, 20) % 12].' '.self::APELLIDOS[($q * 5 + 3) % 12],
                'telefono' => sprintf('555-01%02d', $p % 100),
                'email' => sprintf('paciente%03d@%s.test', $p + 1, $slug),
            ]);
        }

        // Planes de tratamiento para los primeros pacientes.
        $planes = [];
        for ($p = 0; $p < self::PLANES; $p++) {
            $planes[$p] = DB::table('planes_tratamiento')->insertGetId([
                'organizacion_id' => $org->id,
                'paciente_id' => $pacientes[$p],
                'dentista_id' => $dentistas[$p % 2],
                'descripcion' => self::PROCEDIMIENTOS[$p % 8].' (plan de prueba)',
                'sesiones_total' => 2 + ($p % 4),
                'estado' => 'activo',
            ]);
        }

        $pasado = ['completada', 'completada', 'completada', 'completada', 'completada', 'no_asistio', 'cancelada'];
        $futuro = ['programada', 'confirmada', 'confirmada', 'pendiente_confirmacion'];
        $caminos = [
            'completada' => ['programada', 'confirmada', 'en_atencion', 'completada'],
            'no_asistio' => ['programada', 'confirmada', 'no_asistio'],
            'cancelada' => ['programada', 'cancelada'],
            'confirmada' => ['programada', 'confirmada'],
            'programada' => ['programada'],
            'pendiente_confirmacion' => ['pendiente_confirmacion'],
        ];
        $hoy = Carbon::now(config('app.timezone'))->startOfDay();

        for ($i = 0; $i < self::CITAS; $i++) {
            $dent = $i % 2;
            $j = intdiv($i, 2);                     // 0..37 por dentista
            $dia = $dias[$j % self::DIAS_LABORABLES];
            $slot = ($j * 3) % 16;                  // único por (dentista, día)
            $inicio = $dia->copy()->setTime(9, 0)->addMinutes($slot * 30);
            $fin = $inicio->copy()->addMinutes(30);
            $estado = $dia->lessThan($hoy) ? $pasado[$i % count($pasado)] : $futuro[$i % count($futuro)];
            $p = $i % self::PACIENTES;

            $citaId = DB::table('citas')->insertGetId([
                'organizacion_id' => $org->id,
                'paciente_id' => $pacientes[$p],
                'dentista_id' => $dentistas[$dent],
                'plan_id' => $planes[$p] ?? null,
                'inicio' => $inicio,
                'fin' => $fin,
                'estado' => $estado,
                'motivo' => self::PROCEDIMIENTOS[$i % 8],
            ]);

            $camino = $caminos[$estado];
            foreach ($camino as $n => $nuevo) {
                $clinico = in_array($nuevo, ['en_atencion', 'completada'], true);
                DB::table('outbox')->insert([
                    'organizacion_id' => $org->id,
                    'event_id' => Uuid::uuid5(self::NS, "cita:{$citaId}:{$n}")->toString(),
                    'tipo' => 'cita.estado_cambiado',
                    'payload' => json_encode([
                        'organizacion_id' => $org->id,
                        'cita_id' => $citaId,
                        'paciente_id' => $pacientes[$p],
                        'dentista_id' => $dentistas[$dent],
                        'estado_anterior' => $n === 0 ? null : $camino[$n - 1],
                        'estado_nuevo' => $nuevo,
                        'usuario_id' => $clinico ? $dentistas[$dent] : $capturista,
                        'fecha' => $inicio->copy()->subDays(max(0, 3 - $n))->toIso8601String(),
                    ]),
                ]);
            }

            if ($estado === 'completada') {
                DB::table('registros_clinicos')->insert([
                    'organizacion_id' => $org->id,
                    'cita_id' => $citaId,
                    'dentista_id' => $dentistas[$dent],
                    'procedimiento' => self::PROCEDIMIENTOS[$i % 8],
                    'notas' => 'Nota clínica ficticia de prueba.',
                ]);
            }
        }
    }
}
