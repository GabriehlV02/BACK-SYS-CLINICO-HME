<?php

use App\Flows\Pacientes\Http\Controllers\RegistrarPacienteController;
use App\Flows\Autenticacion\Http\IniciarSesionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', IniciarSesionController::class);

Route::prefix('v1')->group(function (): void {
    Route::post('/pacientes', RegistrarPacienteController::class);
});
