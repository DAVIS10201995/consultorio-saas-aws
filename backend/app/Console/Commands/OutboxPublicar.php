<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;

class OutboxPublicar extends Command
{
    protected $signature = 'outbox:publicar {--una-vez : Procesa un lote y termina}';
    protected $description = 'Publica en MongoDB los eventos pendientes del outbox';

    public function handle(): int
    {
        $coleccion = (new Client(env('MONGODB_URI')))->selectCollection('consultorio', 'bitacora_citas');

        do {
            $publicados = $this->procesarLote($coleccion);

            if ($this->option('una-vez')) {
                break;
            }
            if ($publicados === 0) {
                sleep(5);
            }
        } while (true);

        return self::SUCCESS;
    }

    private function procesarLote(Collection $coleccion): int
    {
        return DB::transaction(function () use ($coleccion) {
            $eventos = DB::select('SELECT id, event_id, tipo, payload FROM outbox WHERE publicado_en IS NULL ORDER BY id LIMIT 50 FOR UPDATE SKIP LOCKED');
            $publicados = 0;

            foreach ($eventos as $evento) {
                try {
                    $documento = json_decode($evento->payload, true);
                    $documento['fecha'] = new UTCDateTime(Carbon::parse($documento['fecha'])->getTimestamp() * 1000);
                    $documento['tipo'] = $evento->tipo;

                    $coleccion->updateOne(
                        ['event_id' => $evento->event_id],
                        ['$setOnInsert' => $documento],
                        ['upsert' => true]
                    );
                    DB::update('UPDATE outbox SET publicado_en = now() WHERE id = ?', [$evento->id]);
                    $publicados++;
                } catch (\Throwable $e) {
                    DB::update('UPDATE outbox SET intentos = intentos + 1 WHERE id = ?', [$evento->id]);
                    $this->error("Evento {$evento->id} no publicado: ".$e->getMessage());
                    break;
                }
            }

            return $publicados;
        });
    }
}