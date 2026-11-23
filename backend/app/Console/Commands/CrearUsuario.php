<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrearUsuario extends Command
{
    protected $signature = 'usuarios:crear {nombre} {email} {rol : recepcion, dentista o admin}';
    protected $description = 'Crea un usuario con una contraseña aleatoria que se muestra una sola vez';

    public function handle(): int
    {
        if (! in_array($this->argument('rol'), ['recepcion', 'dentista', 'admin'], true)) {
            $this->error('Rol inválido');

            return self::FAILURE;
        }

        $password = Str::random(16);

        Usuario::create([
            'nombre' => $this->argument('nombre'),
            'email' => $this->argument('email'),
            'password' => $password,
            'rol' => $this->argument('rol'),
        ]);

        $this->line("Contraseña: {$password}");

        return self::SUCCESS;
    }
}