<?php

namespace App\Http\Controllers;

use App\Models\Membresia;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Membresía resuelta por el middleware ResolverMembresia. */
    protected function membresia(Request $request): Membresia
    {
        return $request->attributes->get('membresia');
    }
}
