<?php

use App\Flows\Atenciones\Http\EstanciasAtencionController;
use App\Flows\Autenticacion\Http\CambiarContrasenaController;
use App\Flows\Autenticacion\Http\IniciarSesionController;
use App\Flows\Emergencias\Http\EmergenciasController;
use App\Flows\Farmacia\Http\FarmaciaAlmacenController;
use App\Flows\Imagenologia\Http\Controllers\OrthancController;
use App\Flows\Pacientes\Http\Controllers\ActualizarResponsablePacienteController;
use App\Flows\Pacientes\Http\Controllers\BuscarPacientePorCiController;
use App\Flows\Pacientes\Http\Controllers\EstudiosController;
use App\Flows\Pacientes\Http\Controllers\RegistrarPacienteController;
use App\Flows\Usuarios\Http\AlmacenesCajasController;
use App\Flows\Usuarios\Http\RolesController;
use App\Flows\Usuarios\Http\UsuariosController;
use Illuminate\Support\Facades\Route;

Route::post('/login', IniciarSesionController::class);
Route::post('/cambiar-contrasena', CambiarContrasenaController::class);

Route::prefix('emergencias')->controller(EmergenciasController::class)->group(function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/catalogo', 'catalogo');
});

Route::prefix('orthanc')->controller(OrthancController::class)->group(function (): void {
    Route::get('/estado', 'estado');
    Route::get('/estudios', 'estudios');
    Route::post('/sincronizar', 'sincronizar');
    Route::get('/estudios/{id}/archivo', 'archivo');
    Route::put('/estudios/{id}', 'actualizar');
    Route::delete('/estudios/{id}', 'eliminar');
});

Route::prefix('v1')->group(function (): void {
    Route::get('/farmacia/productos', [FarmaciaAlmacenController::class, 'index']);
    Route::post('/farmacia/productos', [FarmaciaAlmacenController::class, 'store']);
    Route::get('/farmacia/productos/{producto}/kardex', [FarmaciaAlmacenController::class, 'kardex']);
    Route::post('/farmacia/productos/{producto}/movimientos', [FarmaciaAlmacenController::class, 'movimiento']);
    Route::get('/farmacia/almacen', [FarmaciaAlmacenController::class, 'almacen']);
    Route::post('/farmacia/almacen', [FarmaciaAlmacenController::class, 'storeAlmacen']);
    Route::get('/usuarios', [UsuariosController::class, 'index']);
    Route::post('/usuarios', [UsuariosController::class, 'store']);
    Route::get('/roles', [RolesController::class, 'index']);
    Route::post('/roles', [RolesController::class, 'store']);
    Route::put('/roles/{rol}', [RolesController::class, 'update']);
    Route::get('/operacion/almacenes-cajas', [AlmacenesCajasController::class, 'index']);
    Route::post('/operacion/almacenes', [AlmacenesCajasController::class, 'storeAlmacen']);
    Route::post('/operacion/cajas', [AlmacenesCajasController::class, 'storeCaja']);
    Route::patch('/operacion/{tipo}/{id}/estado', [AlmacenesCajasController::class, 'cambiarEstado']);
    Route::post('/pacientes', RegistrarPacienteController::class);
    Route::get('/pacientes/buscar', BuscarPacientePorCiController::class);
    Route::post('/pacientes/{paciente}/responsables', ActualizarResponsablePacienteController::class);
    Route::get('/pacientes/{paciente}/estudios', [EstudiosController::class, 'index']);
    Route::post('/pacientes/{paciente}/estudios', [EstudiosController::class, 'store']);
    Route::patch('/estudios/{estudio}', [EstudiosController::class, 'actualizar']);
    Route::get('/atenciones/{atencion}/estancias', [EstanciasAtencionController::class, 'index']);
    Route::post('/atenciones/{atencion}/estancias', [EstanciasAtencionController::class, 'ingresar']);
    Route::post('/atenciones/{atencion}/estancias/{estancia}/traslado', [EstanciasAtencionController::class, 'trasladar']);
});
