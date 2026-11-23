<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class SembrarDatos extends Command
{
    protected $signature = 'datos:sembrar';
    protected $description = 'Siembra datos ficticios y reproducibles (usuarios, pacientes, planes, citas y registros clínicos)';

    private const NS = '6f1c2c1e-5d0a-4a7e-9f3b-0c0ffee0d0f1';
    private const PACIENTES = 120;
    private const CITAS = 150;
    private const DIAS_LABORABLES = 25;

    private const NOMBRES = ['Ana', 'Luis', 'María', 'Carlos', 'Sofía', 'Jorge', 'Lucía', 'Pedro', 'Elena', 'Miguel', 'Paula', 'Andrés', 'Camila', 'Diego', 'Valeria', 'Raúl', 'Isabel', 'Tomás', 'Renata', 'Hugo'];
    private const APELLIDOS = ['García', 'López', 'Martínez', 'Hernández', 'Ramírez', 'Torres', 'Flores', 'Rivera', 'Gómez', 'Díaz', 'Morales', 'Reyes'];
    private const PROCEDIMIENTOS = ['Limpieza dental', 'Resina', 'Endodoncia', 'Extracción', 'Revisión', 'Blanqueamiento', 'Ortodoncia: ajuste', 'Corona'];

    public function handle(): int
    {
        if (Usuario::where('email', 'admin@prueba.test')->exists()) {
            $this->info('La semilla ya fue aplicada; no se hace nada.');

            return self::SUCCESS;
        }

        $tz = config('app.timezone');

        DB::transaction(function () use ($tz) {
            // Usuarios de prueba (contraseñas aleatorias, se imprimen una sola vez)
            $usuarios = [
                ['Admin Prueba', 'admin@prueba.test', 'admin'],
                ['Recepción Prueba', 'recepcion@prueba.test', 'recepcion'],
                ['Dentista Uno', 'dentista1@prueba.test', 'dentista'],
                ['Dentista Dos', 'dentista2@prueba.test', 'dentista'],
                ['Dentista Tres', 'dentista3@prueba.test', 'dentista'],
            ];
            $ids = [];
            foreach ($usuarios as [$nombre, $email, $rol]) {
                $password = Str::random(16);
                $ids[$email] = Usuario::create(compact('nombre', 'email', 'rol') + ['password' => $password])->id;
                $this->line("{$email} => {$password}");
            }
            $recepcion = $ids['recepcion@prueba.test'];
            $dentistas = [$ids['dentista1@prueba.test'], $ids['dentista2@prueba.test'], $ids['dentista3@prueba.test']];

            // Pacientes ficticios (teléfonos del rango reservado 555-01xx)
            $pacientes = [];
            for ($p = 0; $p < self::PACIENTES; $p++) {
                $nombre = self::NOMBRES[$p % 20].' '.self::APELLIDOS[intdiv($p, 20) % 12].' '.self::APELLIDOS[($p * 5 + 3) % 12];
                $pacientes[$p] = DB::table('pacientes')->insertGetId([
                    'nombre' => $nombre,
                    'telefono' => sprintf('555-01%02d', $p % 100),
                    'email' => sprintf('paciente%03d@prueba.test', $p + 1),
                ]);
            }

            // Planes de tratamiento para los primeros 40 pacientes
            $planes = [];
            for ($p = 0; $p < 40; $p++) {
                $planes[$p] = DB::table('planes_tratamiento')->insertGetId([
                    'paciente_id' => $pacientes[$p],
                    'dentista_id' => $dentistas[$p % 3],
                    'descripcion' => self::PROCEDIMIENTOS[$p % 8].' (plan de prueba)',
                    'sesiones_total' => 2 + ($p % 4),
                    'estado' => 'activo',
                ]);
            }

            // Días laborables consecutivos desde un ancla fija
            $dias = [];
            $d = Carbon::create(2026, 10, 12, 0, 0, 0, $tz);
            while (count($dias) < self::DIAS_LABORABLES) {
                if ($d->isWeekday()) {
                    $dias[] = $d->copy();
                }
                $d->addDay();
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

            for ($i = 0; $i < self::CITAS; $i++) {
                $dent = $i % 3;
                $j = intdiv($i, 3);                    // 0..49 por dentista
                $dia = $dias[$j % self::DIAS_LABORABLES];
                $slot = ($j * 3) % 16;                 // único por (dentista, día)
                $inicio = $dia->copy()->setTime(9, 0)->addMinutes($slot * 30);
                $fin = $inicio->copy()->addMinutes(30);
                $estado = ($j % self::DIAS_LABORABLES) < 14
                    ? $pasado[$i % count($pasado)]
                    : $futuro[$i % count($futuro)];
                $p = $i % self::PACIENTES;

                $citaId = DB::table('citas')->insertGetId([
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
                    $enAtencion = in_array($nuevo, ['en_atencion', 'completada'], true);
                    DB::table('outbox')->insert([
                        'event_id' => Uuid::uuid5(self::NS, "cita:{$citaId}:{$n}")->toString(),
                        'tipo' => 'cita.estado_cambiado',
                        'payload' => json_encode([
                            'cita_id' => $citaId,
                            'paciente_id' => $pacientes[$p],
                            'dentista_id' => $dentistas[$dent],
                            'estado_anterior' => $n === 0 ? null : $camino[$n - 1],
                            'estado_nuevo' => $nuevo,
                            'usuario_id' => $enAtencion ? $dentistas[$dent] : $recepcion,
                            'fecha' => $inicio->copy()->subDays(max(0, 3 - $n))->toIso8601String(),
                        ]),
                    ]);
                }

                if ($estado === 'completada') {
                    DB::table('registros_clinicos')->insert([
                        'cita_id' => $citaId,
                        'dentista_id' => $dentistas[$dent],
                        'procedimiento' => self::PROCEDIMIENTOS[$i % 8],
                        'notas' => 'Nota clínica ficticia de prueba.',
                    ]);
                }
            }
        });

        $this->info('Semilla aplicada: 5 usuarios, '.self::PACIENTES.' pacientes, 40 planes, '.self::CITAS.' citas.');

        return self::SUCCESS;
    }
}
