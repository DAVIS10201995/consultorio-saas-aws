<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    protected $table = 'citas';
    public $timestamps = false;

    protected $fillable = ['paciente_id', 'dentista_id', 'plan_id', 'inicio', 'fin', 'estado', 'motivo'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fin' => 'datetime'];
    }
}