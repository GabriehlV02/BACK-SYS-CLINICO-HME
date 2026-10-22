<?php

namespace App\Flows\Autenticacion\Http;

use App\Flows\Autenticacion\AccesoCompartido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CambiarContrasenaController
{
    public function __invoke(Request $request, AccesoCompartido $acceso): JsonResponse
    {
        $datos = $request->validate(['usuario' => ['required', 'string'], 'contrasena_actual' => ['required', 'string'],
            'contrasena_nueva' => ['required', 'string', 'min:8', 'max:200', 'same:confirmar_contrasena'], 'confirmar_contrasena' => ['required', 'string']]);
        $usuario = $request->user();
        abort_unless($usuario->usuario === $datos['usuario'] && Hash::check($datos['contrasena_actual'], $usuario->password), 422, 'La contraseña actual no es correcta.');
        $acceso->conexion()->table('usuarios_sistema')->where('id', $usuario->id)->update(['password' => Hash::make($datos['contrasena_nueva']), 'updated_at' => now()]);

        return response()->json(['message' => 'Contraseña actualizada en ambos sistemas. Inicia sesión nuevamente.']);
    }
}
