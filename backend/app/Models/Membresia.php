<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membresia extends Model
{
    public const ROLES = ['admin', 'capturista', 'lector'];

    protected $table = 'membresias';
    public $timestamps = false;

    protected $fillable = ['organizacion_id', 'usuario_id', 'rol', 'es_dentista'];

    protected function casts(): array
    {
        return ['es_dentista' => 'boolean'];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    /** Un dentista que no es admin solo ve sus citas y los pacientes de esas citas. */
    public function vistaDeDentista(): bool
    {
        return $this->es_dentista && $this->rol !== 'admin';
    }
}
