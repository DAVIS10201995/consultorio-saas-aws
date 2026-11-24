<?php

namespace App\Console\Commands;

use App\Services\Bitacora;
use Illuminate\Console\Command;

/** Solo para el entorno local (DynamoDB Local). En AWS la tabla se crea en la consola. */
class CrearTablaBitacora extends Command
{
    protected $signature = 'bitacora:crear-tabla';
    protected $description = 'Crea la tabla de la bitácora en DynamoDB Local';

    public function handle(Bitacora $bitacora): int
    {
        $bitacora->crearTablaLocal();
        $this->info('Tabla '.config('services.dynamodb.tabla').' creada en DynamoDB Local.');

        return self::SUCCESS;
    }
}
