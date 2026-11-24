<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAOrganizacion;
use Illuminate\Database\Eloquent\Model;

class Paciente extends Model
{
    use PerteneceAOrganizacion;

    protected $table = 'pacientes';
    public $timestamps = false;

    // organizacion_id no es asignable desde la petición: lo pone el servidor.
    protected $fillable = ['nombre', 'telefono', 'email'];
}
