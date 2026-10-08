<?php

use App\Flows\Pacientes\Http\Controllers\RegistrarPacienteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/pacientes', RegistrarPacienteController::class);
});
