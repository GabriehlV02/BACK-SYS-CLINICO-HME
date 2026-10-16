<?php

use App\Flows\Autenticacion\Http\CambiarContrasenaController;
use App\Flows\Autenticacion\Http\IniciarSesionController;
use App\Flows\Imagenologia\Http\Controllers\OrthancController;
use App\Flows\Pacientes\Http\Controllers\ActualizarResponsablePacienteController;
use App\Flows\Pacientes\Http\Controllers\BuscarPacientePorCiController;
use App\Flows\Pacientes\Http\Controllers\RegistrarPacienteController;
use App\Flows\Usuarios\Http\UsuariosController;
use App\Flows\Usuarios\Http\RolesController;
use Illuminate\Support\Facades\Route;

Route::post('/login', IniciarSesionController::class);
Route::post('/cambiar-contrasena', CambiarContrasenaController::class);

Route::prefix('orthanc')->controller(OrthancController::class)->group(function (): void {
    Route::get('/estado', 'estado');
    Route::get('/estudios', 'estudios');
    Route::post('/sincronizar', 'sincronizar');
    Route::get('/estudios/{id}/archivo', 'archivo');
    Route::put('/estudios/{id}', 'actualizar');
    Route::delete('/estudios/{id}', 'eliminar');
});

Route::prefix('v1')->group(function (): void {
    Route::get('/usuarios', [UsuariosController::class, 'index']);
    Route::post('/usuarios', [UsuariosController::class, 'store']);
    Route::get('/roles', [RolesController::class, 'index']);
    Route::post('/roles', [RolesController::class, 'store']);
    Route::put('/roles/{rol}', [RolesController::class, 'update']);
    Route::post('/pacientes', RegistrarPacienteController::class);
    Route::get('/pacientes/buscar', BuscarPacientePorCiController::class);
    Route::post('/pacientes/{paciente}/responsables', ActualizarResponsablePacienteController::class);
});
