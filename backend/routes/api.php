<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\PacienteController;
use App\Http\Middleware\RolRequerido;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/yo', [AuthController::class, 'yo']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/pacientes', [PacienteController::class, 'index']);
    Route::get('/pacientes/{paciente}', [PacienteController::class, 'show']);

    Route::get('/citas', [CitaController::class, 'index']);
    Route::get('/citas/{id}', [CitaController::class, 'show']);
    Route::post('/citas/{id}/estado', [CitaController::class, 'cambiarEstado']);

    Route::middleware(RolRequerido::class.':recepcion,admin')->group(function () {
        Route::post('/pacientes', [PacienteController::class, 'store']);
        Route::put('/pacientes/{paciente}', [PacienteController::class, 'update']);
        Route::post('/citas', [CitaController::class, 'store']);
    });

    Route::delete('/pacientes/{paciente}', [PacienteController::class, 'destroy'])
        ->middleware(RolRequerido::class.':admin');
});