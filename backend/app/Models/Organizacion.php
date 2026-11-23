<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organizacion extends Model
{
    protected $table = 'organizaciones';
    public $timestamps = false;

    protected $fillable = ['nombre', 'slug'];
}
