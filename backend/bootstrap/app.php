<?php

use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\RegistroPeticion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Al inicio de la cadena: Laravel reordena auth y throttle por prioridad, y estos dos
        // deben envolverlos para que también los 401, 403 y 429 lleven registro y cabeceras.
        $middleware->api(prepend: [RegistroPeticion::class, CabecerasSeguridad::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 404 uniforme: no revela nombres de modelos ni si el registro existe en otra organización.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['mensaje' => 'Recurso no encontrado'], 404);
            }
        });
    })->create();
