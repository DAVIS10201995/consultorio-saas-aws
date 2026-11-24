<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/** Indicador de disponibilidad: 200 si la API y PostgreSQL responden; 503 si no. Nunca revela detalles. */
class SaludController extends Controller
{
    public function __invoke()
    {
        try {
            DB::select('SELECT 1');

            return response()->json(['estado' => 'ok']);
        } catch (\Throwable) {
            return response()->json(['estado' => 'degradado'], 503);
        }
    }
}
