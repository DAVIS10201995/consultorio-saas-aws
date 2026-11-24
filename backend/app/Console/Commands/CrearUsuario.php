<?php

namespace App\Console\Commands;

use App\Models\Membresia;
use App\Models\Organizacion;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrearUsuario extends Command
{
    protected $signature = 'usuarios:crear {nombre} {email} {organizacion : slug de la organización} {rol : admin, capturista o lector} {--dentista : marca al usuario como dentista}';
    protected $description = 'Crea un usuario con su membresía y una contraseña aleatoria que se muestra una sola vez';

    public function handle(): int
    {
        if (! in_array($this->argument('rol'), Membresia::ROLES, true)) {
            $this->error('Rol inválido: usa admin, capturista o lector');

            return self::FAILURE;
        }

        $organizacion = Organizacion::where('slug', $this->argument('organizacion'))->first();
        if (! $organizacion) {
            $this->error('No existe la organización '.$this->argument('organizacion'));

            return self::FAILURE;
        }

        $password = Str::random(16);

        DB::transaction(function () use ($organizacion, $password) {
            $usuario = Usuario::create([
                'nombre' => $this->argument('nombre'),
                'email' => $this->argument('email'),
                'password' => $password,
            ]);
            Membresia::create([
                'organizacion_id' => $organizacion->id,
                'usuario_id' => $usuario->id,
                'rol' => $this->argument('rol'),
                'es_dentista' => (bool) $this->option('dentista'),
            ]);
        });

        $this->line("Contraseña: {$password}");

        return self::SUCCESS;
    }
}
