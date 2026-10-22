<?php

namespace App\Flows\Autenticacion\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class IniciarSesionController
{
    public function __invoke(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $datos = $request->validate(['usuario' => ['required', 'string', 'max:60'], 'password' => ['required', 'string', 'max:200']]);
        $usuario = $acceso->conexion()->table('usuarios_sistema')->where('usuario', $datos['usuario'])->first();
        if (! $usuario || ! $acceso->permitido($usuario) || ! Hash::check($datos['password'], $usuario->password)) {
            return response()->json(['message' => 'Usuario o contraseña incorrectos, o acceso clínico no habilitado.'], 401);
        }

        return response()->json($acceso->emitir($usuario))->header('Cache-Control', 'no-store');
    }
}
