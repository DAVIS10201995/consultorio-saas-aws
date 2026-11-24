<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    use PerteneceAOrganizacion;

    protected $table = 'citas';
    public $timestamps = false;

    // organizacion_id no es asignable desde la petición: lo pone el servidor.
    protected $fillable = ['paciente_id', 'dentista_id', 'plan_id', 'inicio', 'fin', 'estado', 'motivo'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fin' => 'datetime'];
    }
}
