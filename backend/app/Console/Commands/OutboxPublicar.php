<?php

namespace App\Console\Commands;

use App\Services\Bitacora;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OutboxPublicar extends Command
{
    protected $signature = 'outbox:publicar {--una-vez : Procesa un lote y termina}';
    protected $description = 'Publica en DynamoDB los eventos pendientes del outbox';

    public function handle(Bitacora $bitacora): int
    {
        do {
            $publicados = $this->procesarLote($bitacora);

            if ($this->option('una-vez')) {
                break;
            }
            if ($publicados === 0) {
                sleep(5);
            }
        } while (true);

        return self::SUCCESS;
    }

    private function procesarLote(Bitacora $bitacora): int
    {
        return DB::transaction(function () use ($bitacora) {
            $eventos = DB::select('SELECT id, organizacion_id, event_id, tipo, payload FROM outbox WHERE publicado_en IS NULL ORDER BY id LIMIT 50 FOR UPDATE SKIP LOCKED');
            $publicados = 0;

            foreach ($eventos as $evento) {
                try {
                    // La organización sale de la columna del outbox, no del JSON.
                    $nuevo = $bitacora->registrar(
                        (int) $evento->organizacion_id,
                        $evento->event_id,
                        $evento->tipo,
                        json_decode($evento->payload, true),
                    );
                    DB::update('UPDATE outbox SET publicado_en = now() WHERE id = ?', [$evento->id]);
                    $publicados++;

                    if (! $nuevo) {
                        $this->line("Evento {$evento->event_id} ya estaba en DynamoDB; no se duplica.");
                    }
                } catch (\Throwable $e) {
                    DB::update('UPDATE outbox SET intentos = intentos + 1 WHERE id = ?', [$evento->id]);
                    $this->error("Evento {$evento->id} no publicado: ".$e->getMessage());
                    break;
                }
            }

            if ($publicados > 0) {
                $this->info("Publicados {$publicados} eventos.");
            }

            return $publicados;
        });
    }
}
