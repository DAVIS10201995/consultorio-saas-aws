<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $demasiadas = fn () => response()->json(['mensaje' => 'Demasiadas solicitudes, intenta más tarde'], 429);

        // Login: 5 intentos por minuto para cada combinación de correo + IP (frena fuerza bruta).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip())
            ->response($demasiadas));

        // API: 60 solicitudes por minuto por usuario; sin sesión, por IP.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip())
            ->response($demasiadas));
    }
}
