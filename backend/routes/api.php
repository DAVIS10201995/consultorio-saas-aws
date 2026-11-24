<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\MiembroController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\SaludController;
use App\Http\Middleware\ResolverMembresia;
use App\Http\Middleware\RolRequerido;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Salud bajo /api/ para que pase por CloudFront (/up no). Sin sesión, sin detalles.
Route::get('/salud', SaludController::class)->middleware('throttle:api');

// Toda ruta protegida: identidad (Sanctum) + organización y rol desde la membresía.
Route::middleware(['auth:sanctum', 'throttle:api', ResolverMembresia::class])->group(function () {
    Route::get('/yo', [AuthController::class, 'yo']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Lectura: los tres roles.
    Route::get('/pacientes', [PacienteController::class, 'index']);
    Route::get('/pacientes/{id}', [PacienteController::class, 'show'])->whereNumber('id');
    Route::get('/citas', [CitaController::class, 'index']);
    Route::get('/citas/{id}', [CitaController::class, 'show'])->whereNumber('id');
    Route::get('/citas/{id}/historial', [CitaController::class, 'historial'])->whereNumber('id');

    // Escritura: administrador y capturista.
    Route::middleware(RolRequerido::class.':admin,capturista')->group(function () {
        Route::post('/pacientes', [PacienteController::class, 'store']);
        Route::put('/pacientes/{id}', [PacienteController::class, 'update'])->whereNumber('id');
        Route::post('/citas', [CitaController::class, 'store']);
        Route::post('/citas/{id}/estado', [CitaController::class, 'cambiarEstado'])->whereNumber('id');
    });

    // Solo administrador de la organización.
    Route::middleware(RolRequerido::class.':admin')->group(function () {
        Route::delete('/pacientes/{id}', [PacienteController::class, 'destroy'])->whereNumber('id');
        Route::get('/miembros', [MiembroController::class, 'index']);
        Route::post('/miembros', [MiembroController::class, 'store']);
        Route::put('/miembros/{id}', [MiembroController::class, 'update'])->whereNumber('id');
    });
});
